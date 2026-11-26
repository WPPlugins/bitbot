<?php
/**
 * Gather v2, pass 2: paced, parallel, adaptive page renders from inside WordPress.
 *
 * OFF unless the backend returned gatherV2:true in a SyncContent response (the
 * backend owns the allowlist). The engine extends the existing bulk-sync cron
 * chain (hook bitbot_sync_chunk, args [seq, run_id]) once pass 1 is finished;
 * it adds no cron hook of its own.
 *
 * Safety model:
 *  - Nothing is written to uploads. HTML is gzip+base64 and sent to the backend
 *    straight away; a failed send is simply rendered again.
 *  - One tick at a time: an atomic lease (BitBot_Gather_Lease).
 *  - Run-specific state is committed by compare-and-set against the exact value
 *    the tick read, so a tick that was in flight when a new run started cannot
 *    restore the old run.
 *  - Events carry [seq, run_id]; stale ones are rejected (BitBot_Gather_Guard).
 *  - A failed schedule is recorded and recovered on the next admin load or
 *    backend nudge; a shutdown handler recovers from a fatal in a render tick.
 *  - Manual ticks obey the cooldown; only an explicit probe skips it.
 *  - Renders are anonymous (no cookies), the metrics token is bound to run +
 *    page and consumed on first use, the key never appears in a URL, and
 *    sslverify is only off for the 127.0.0.1 attempts.
 *
 * @package BitBot
 * @since   2.2.17
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Gather_V2 {

    const FLAG_OPTION  = 'bitbot_gv2_flag';
    const STATE_KEY    = 'bitbot_gv2_state';
    const LEASE_KEY    = 'bitbot_gv2_lease';
    const QUEUE_KEY    = 'bitbot_gv2_queue';
    const ROWS_KEY     = 'bitbot_gv2_rows';
    const DONE_KEY     = 'bitbot_gv2_done';
    const CURRENT_KEY  = 'bitbot_gv2_current';
    const CURRENT_CAP  = 50000;
    const TOKEN_PREFIX = 'bitbot_gv2_tok_';
    const HEADER       = 'HTTP_X_BITBOT_GATHER';
    const MARKER       = 'bitbot-gather-metrics';

    const TICK_BUDGET_SECONDS = 20;
    const LEASE_TTL           = 90;
    const REQUEST_TIMEOUT     = 15;
    const TOKEN_TTL           = 120;
    const MAX_HTML_BYTES      = 3000000;
    const IMAGE_MAX_BYTES     = 5242880;    // 5 MB
    const IMAGES_PER_CALL     = 5;
    const IMAGE_QUEUE_CAP     = 200;
    const IMAGE_TRIES         = 3;
    const BACKEND_FAIL_PAUSE  = 3;
    const FATAL_RETRY_DELAY   = 60;
    const DEAD_RETRY_DELAY    = 1;      // a killed worker is not a bad page: the retry goes out almost at once
    const STALLED_AFTER       = 300;
    const NUDGE_COOLDOWN      = 20;
    const FAILED_IDS_CAP      = 200;
    /** A tick stops starting waves once PHP has used this share of memory_limit. */
    const MEMORY_SHARE        = 0.7;

    /** @var BitBot_API_Client */
    private $api;
    /** @var BitBot_Gather_WP_Store */
    private $store;
    /** @var BitBot_Gather_Lease */
    private $lease;
    /** @var string Post id the render-side metrics token was bound to. */
    private $bound_post = 0;
    /** @var array|null The last wave's paired visitor probe (batch field visitorProbe), or null when none could be taken. */
    private $last_probe = null;
    /** @var string|null The exact route key (http, https, self) the last wave rendered on. */
    private $last_route = null;
    /** @var bool The last wave's search probe got a non-200, non-distress answer: search is no sensor on this site. */
    private $search_unusable = false;
    /** @var string Why the last read_image() returned null. */
    private $image_reason = '';
    /** @var array|null Context of the tick running in THIS process (for the shutdown handler). */
    private $tick_ctx = null;

    public function __construct( $api_client ) {
        $this->api   = $api_client;
        $this->store = new BitBot_Gather_WP_Store();
        $this->lease = new BitBot_Gather_Lease( $this->store, self::LEASE_KEY );
    }

    // ------------------------------------------------------------------ flag

    /** Is gather v2 switched on for this site (by the backend)? */
    public static function enabled() {
        return '1' === (string) get_option( self::FLAG_OPTION, '' );
    }

    /**
     * Read the backend's switch from a SyncContent response. Present+true turns
     * it on; absent or false turns it off again.
     *
     * @return bool The flag after reading.
     */
    public static function note_flag( $data ) {
        $on = is_array( $data ) && ! empty( $data['gatherV2'] ) && true === $data['gatherV2'];
        if ( $on ) {
            if ( ! self::enabled() ) {
                update_option( self::FLAG_OPTION, '1', true );
            }
        } elseif ( false !== get_option( self::FLAG_OPTION, false ) ) {
            delete_option( self::FLAG_OPTION );
        }
        return $on;
    }

    /**
     * Fold one pass-1 batch response into the run's pending decisions.
     * sampleRequests (as ids) and wantImages are de-duplicated (images capped
     * at 200); renderTypes keeps the latest NON-EMPTY list seen.
     *
     * @param array $acc  { types: string[], samples: int[], images: [{url, wpPostId}] }
     * @param array $data One SyncContent response.
     * @return array New accumulator.
     */
    public static function accumulate( $acc, $data ) {
        $acc = is_array( $acc ) ? $acc : array();
        $acc = array_merge( array( 'types' => array(), 'samples' => array(), 'images' => array(), 'cap' => 1, 'rounds' => array() ), $acc );
        $acc['rounds'] = BitBot_Gather_Rounds::merge( (array) $acc['rounds'], BitBot_Gather_Rounds::from_response( $data ) );
        $acc['cap'] = isset( $data['renderConcurrencyMax'] ) ? max( 1, (int) $data['renderConcurrencyMax'] ) : 1;
        if ( isset( $data['renderTypes'] ) && is_array( $data['renderTypes'] ) ) {
            $types = array();
            foreach ( $data['renderTypes'] as $t ) {
                if ( is_string( $t ) && '' !== $t ) {
                    $types[] = $t;
                }
            }
            if ( $types ) {
                $acc['types'] = array_values( array_unique( $types ) );
            }
        }
        if ( isset( $data['sampleRequests'] ) && is_array( $data['sampleRequests'] ) ) {
            foreach ( $data['sampleRequests'] as $req ) {
                if ( is_array( $req ) && isset( $req['wpPostIds'] ) ) {
                    foreach ( (array) $req['wpPostIds'] as $id ) {
                        if ( (int) $id > 0 ) {
                            $acc['samples'][] = (int) $id;
                        }
                    }
                }
            }
            $acc['samples'] = array_values( array_unique( $acc['samples'] ) );
        }
        if ( isset( $data['wantImages'] ) && is_array( $data['wantImages'] ) ) {
            $by = array();
            foreach ( $acc['images'] as $w ) {
                $by[ $w['url'] ] = $w;
            }
            foreach ( $data['wantImages'] as $w ) {
                // Pass 1 has uploaded nothing yet: the uploaded-set filter belongs to image_requests() in the render pass.
                if ( is_array( $w ) && ! empty( $w['url'] ) && is_string( $w['url'] ) && ! isset( $by[ $w['url'] ] ) ) {
                    $by[ $w['url'] ] = array( 'url' => $w['url'], 'wpPostId' => (int) ( isset( $w['wpPostId'] ) ? $w['wpPostId'] : 0 ) );
                }
            }
            $acc['images'] = array_slice( array_values( $by ), 0, self::IMAGE_QUEUE_CAP );
        }
        return $acc;
    }

    /**
     * Pass 1 reports the pages whose stored record is already a render of this very
     * post version (currentIds). Fold one response into the run's set: positive
     * ints only, a set, capped. Pure.
     *
     * @param int[] $existing Ids seen so far.
     * @param array $data     One SyncContent response.
     * @return int[]
     */
    public static function merge_current( array $existing, $data ) {
        $set = array();
        foreach ( $existing as $id ) {
            if ( (int) $id > 0 ) {
                $set[ (int) $id ] = true;
            }
        }
        if ( is_array( $data ) && isset( $data['currentIds'] ) && is_array( $data['currentIds'] ) ) {
            foreach ( $data['currentIds'] as $id ) {
                if ( is_numeric( $id ) && (int) $id > 0 && count( $set ) < self::CURRENT_CAP ) {
                    $set[ (int) $id ] = true;
                }
            }
        }
        return array_keys( $set );
    }

    /**
     * Keep the run's current ids in their own per-run key (not in the progress option).
     * A lost update between two overlapping ticks only means a page renders again.
     */
    public function note_current( $run_id, $data ) {
        if ( ! is_array( $data ) || empty( $data['currentIds'] ) ) {
            return;
        }
        $this->write_json( self::CURRENT_KEY, $run_id, 'ids', self::merge_current( $this->read_json( self::CURRENT_KEY, $run_id, 'ids' ), $data ) );
    }

    /** Flat sample id list from a response (public for tests and begin()). */
    public static function sample_ids_from( array $data ) {
        $ids = array();
        if ( isset( $data['sampleRequests'] ) && is_array( $data['sampleRequests'] ) ) {
            foreach ( $data['sampleRequests'] as $req ) {
                if ( is_array( $req ) && isset( $req['wpPostIds'] ) ) {
                    foreach ( (array) $req['wpPostIds'] as $id ) {
                        if ( (int) $id > 0 ) {
                            $ids[] = (int) $id;
                        }
                    }
                }
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /** Samples with a round id are grouped by round; those without keep one plain entry. */
    private static function requests_from_acc( $acc ) {
        $rounds = isset( $acc['rounds'] ) && is_array( $acc['rounds'] ) ? $acc['rounds'] : array();
        $out    = BitBot_Gather_Rounds::to_requests( $rounds );
        $plain  = array();
        foreach ( isset( $acc['samples'] ) ? $acc['samples'] : array() as $id ) {
            if ( ! isset( $rounds[ (int) $id ] ) ) {
                $plain[] = (int) $id;
            }
        }
        if ( $plain ) {
            $out[] = array( 'postType' => '', 'wpPostIds' => $plain );
        }
        return $out;
    }

    /** The accumulator back in response shape, for begin(). */
    public static function accumulated_to_data( $acc ) {
        $acc = is_array( $acc ) ? $acc : array();
        return array(
            'renderTypes'    => isset( $acc['types'] ) ? $acc['types'] : array(),
            'sampleRequests' => self::requests_from_acc( $acc ),
            'wantImages'     => isset( $acc['images'] ) ? $acc['images'] : array(),
            'renderConcurrencyMax' => isset( $acc['cap'] ) ? (int) $acc['cap'] : 1,
        );
    }

    // ----------------------------------------------------------------- hooks

    public function register_hooks() {
        // Render side: a request carrying our one-use token gets a metrics comment.
        if ( isset( $_SERVER[ self::HEADER ] ) ) {
            add_action( 'template_redirect', array( $this, 'maybe_capture_render_metrics' ), 0 );
        }
        // Failed-schedule / dead-runner recovery on an admin load (throttled).
        add_action( 'admin_init', array( $this, 'maybe_recover_on_admin' ) );
    }

    public function register_routes() {
        foreach ( array( 'gather/status' => 'rest_status', 'gather/continue' => 'rest_continue' ) as $path => $method ) {
            register_rest_route( 'bitbot/v1', '/' . $path, array(
                'methods'             => 'POST',
                'callback'            => array( $this, $method ),
                'permission_callback' => array( $this, 'rest_permission' ),
            ) );
        }
    }

    /** POST only; the key comes from the JSON body, never from the URL. */
    public function rest_permission( $request ) {
        $body   = $request->get_json_params();
        $key    = is_array( $body ) && isset( $body['apiKey'] ) ? (string) $body['apiKey'] : '';
        $stored = (string) get_option( 'bitbot_api_key', '' );
        return BitBot_Consent::is_connected() && '' !== $stored && '' !== $key && hash_equals( $stored, $key );
    }

    public function rest_status( $request ) {
        return new WP_REST_Response( array( 'ok' => true, 'gather' => $this->status() ), 200 );
    }

    /**
     * mode "nudge" (backend, rate limited): recover scheduling only, never work inline.
     * mode "continue" (explicit): recover, then run one tick inline. The cooldown
     * still applies unless probe=true.
     */
    public function rest_continue( $request ) {
        $body  = (array) $request->get_json_params();
        $mode  = isset( $body['mode'] ) && 'continue' === $body['mode'] ? 'continue' : 'nudge';
        $probe = ! empty( $body['probe'] );
        if ( 'nudge' === $mode ) {
            if ( false !== get_transient( 'bitbot_gv2_nudge' ) ) {
                return new WP_REST_Response( array( 'ok' => true, 'throttled' => true, 'gather' => $this->status() ), 200 );
            }
            set_transient( 'bitbot_gv2_nudge', 1, self::NUDGE_COOLDOWN );
        }
        if ( 'continue' === $mode ) {
            return new WP_REST_Response( BitBot::get_instance()->continue_gather( $probe ), 200 );
        }
        $recovered = $this->recover();
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
        return new WP_REST_Response( array( 'ok' => true, 'recovered' => $recovered, 'tick' => null, 'gather' => $this->status() ), 200 );
    }

    // ----------------------------------------------------------- state helpers

    private function read_state( &$raw = null ) {
        $raw = $this->store->get( self::STATE_KEY );
        if ( null === $raw ) {
            return null;
        }
        $s = json_decode( $raw, true );
        return is_array( $s ) ? $s : null;
    }

    private function read_json( $key, $run_id, $field ) {
        $raw = $this->store->get( BitBot_Gather_Guard::run_key( $key, $run_id ) );
        $d   = null === $raw ? null : json_decode( $raw, true );
        if ( ! is_array( $d ) || ! isset( $d['run'] ) || (string) $d['run'] !== (string) $run_id ) {
            return array();
        }
        return isset( $d[ $field ] ) && is_array( $d[ $field ] ) ? $d[ $field ] : array();
    }

    /** Unfenced write: only begin() uses it, for a run no other worker has yet. */
    private function write_json( $key, $run_id, $field, array $value ) {
        $this->store->put( BitBot_Gather_Guard::run_key( $key, $run_id ), wp_json_encode( array( 'run' => (string) $run_id, $field => $value ) ) );
    }

    /**
     * Run data written by a tick or by recovery: per-run key, and only while this
     * worker still owns the lease and the run is still the site's current run.
     */
    private function fenced_json( array $handle, $key, $run_id, $field, array $value ) {
        return BitBot_Gather_Guard::fenced_put(
            $this->store,
            $this->lease,
            $handle,
            (string) get_option( BitBot::SYNC_RUN_OPTION ),
            $run_id,
            BitBot_Gather_Guard::run_key( $key, $run_id ),
            wp_json_encode( array( 'run' => (string) $run_id, $field => $value ) ),
            time()
        );
    }

    /** Drop all engine data. Called when a new sync starts or the sync is stopped. */
    public function retire() {
        foreach ( array( self::STATE_KEY, self::LEASE_KEY ) as $k ) {
            $this->store->delete( $k );
        }
        foreach ( array( self::QUEUE_KEY, self::ROWS_KEY, self::DONE_KEY, self::CURRENT_KEY ) as $k ) {
            $this->store->delete_prefix( $k . '_' );      // every run's data, including orphans
        }
        $this->store->delete_prefix( self::TOKEN_PREFIX );
    }

    // ----------------------------------------------------------------- begin

    /**
     * Pass 1 finished with the flag on: start the render pass for this run.
     *
     * @param string $run_id SYNC_RUN_OPTION value of the finished pass-1 run.
     * @param int[]  $ids    Post ids pass 1 covered.
     * @param array  $data   The final pass-1 batch response data.
     */
    public function begin( $run_id, array $ids, $data ) {
        // Only ever under the site's CURRENT run: the final batch of a superseded
        // sync must not start a render pass nobody can tick.
        if ( (string) get_option( BitBot::SYNC_RUN_OPTION ) !== (string) $run_id ) {
            return false;
        }
        $current = self::merge_current( $this->read_json( self::CURRENT_KEY, $run_id, 'ids' ), array() );   // read before retire() clears it
        $this->retire();
        $rows = $this->candidate_rows( $ids );
        if ( $current ) {
            $this->write_json( self::CURRENT_KEY, $run_id, 'ids', $current );
        }
        $this->write_json( self::ROWS_KEY, $run_id, 'rows', $rows );
        $this->write_json( self::DONE_KEY, $run_id, 'ids', array() );

        $types   = $this->string_list( is_array( $data ) && isset( $data['renderTypes'] ) ? $data['renderTypes'] : array() );
        $samples = $this->sample_ids( is_array( $data ) ? $data : array() );
        $queue   = BitBot_Gather_Queue::build( $rows, $types, $samples, array(), array_fill_keys( $current, true ) );
        $this->write_json( self::QUEUE_KEY, $run_id, 'ids', $queue );

        $now   = time();
        $state = array(
            'run_id'       => (string) $run_id,
            'state'        => 'running',
            'seq'          => 0,
            'pos'          => 0,
            'total'        => count( $queue ),
            'done'         => 0,
            'skipped_current' => BitBot_Gather_Queue::skipped_count( $rows, $types, $current ),
            'failed'       => 0,
            'failed_ids'   => array(),
            'inflight'     => array(),
            'retried'      => array(),
            'render_types' => $types,
            'samples'      => $samples,
            'sample_rounds' => BitBot_Gather_Rounds::from_response( is_array( $data ) ? $data : array() ),
            'want_images'  => $this->image_requests( is_array( $data ) ? $data : array(), array() ),
            'images_sent'  => 0,
            'pacer'        => BitBot_Gather_Pacer::set_cap( BitBot_Gather_Pacer::initial(), is_array( $data ) && isset( $data['renderConcurrencyMax'] ) ? (int) $data['renderConcurrencyMax'] : 1 ),
            'pause_seconds' => 0,
            'via'          => '',
            'due_at'       => $now,
            'started_at'   => $now,
            'updated_at'   => $now,
            'last_tick_at' => 0,
            'pause_reason' => '',
            'backend_fail' => 0,
            'schedule_failed' => false,
            'ticks'        => 0,
        );
        if ( ! $queue && ! $state['want_images'] ) {
            $state['state'] = 'done';
        }
        if ( 'running' === $state['state'] ) {
            $this->schedule( $state, $now );
        }
        $this->store->put( self::STATE_KEY, wp_json_encode( $state ) );
        return true;
    }

    /** Run id of the stored render-pass state, or null when there is none. */
    public function state_run_id() {
        $s = $this->read_state();
        return $s && isset( $s['run_id'] ) ? (string) $s['run_id'] : null;
    }

    /** [ [id, type, modified_unix], ... ] for the ids pass 1 covered. */
    private function candidate_rows( array $ids ) {
        global $wpdb;
        $rows = array();
        foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
            $in  = implode( ',', array_map( 'absint', $chunk ) );
            $res = $wpdb->get_results( "SELECT ID, post_type, post_modified_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID IN ($in)", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is absint()-ed ids joined by commas, nothing else.
            foreach ( (array) $res as $r ) {
                $rows[] = array( (int) $r[0], (string) $r[1], (int) strtotime( $r[2] . ' UTC' ) );
            }
        }
        return $rows;
    }

    private function string_list( $v ) {
        $out = array();
        foreach ( (array) $v as $x ) {
            if ( is_string( $x ) && '' !== $x ) {
                $out[] = $x;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** sampleRequests: [{postType, wpPostIds}] -> flat id list. */
    private function sample_ids( array $data ) {
        $ids = array();
        if ( isset( $data['sampleRequests'] ) && is_array( $data['sampleRequests'] ) ) {
            foreach ( $data['sampleRequests'] as $req ) {
                if ( is_array( $req ) && isset( $req['wpPostIds'] ) ) {
                    foreach ( (array) $req['wpPostIds'] as $id ) {
                        if ( (int) $id > 0 ) {
                            $ids[] = (int) $id;
                        }
                    }
                }
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /** wantImages: [{url, wpPostId}] merged into the pending list, de-duplicated, capped. */
    private function image_requests( array $data, array $existing, array $uploaded = array() ) {
        $by = array();
        foreach ( $existing as $e ) {
            $by[ $e['url'] ] = $e;
        }
        if ( isset( $data['wantImages'] ) && is_array( $data['wantImages'] ) ) {
            foreach ( $data['wantImages'] as $w ) {
                if ( is_array( $w ) && ! empty( $w['url'] ) && is_string( $w['url'] ) && ! isset( $by[ $w['url'] ] ) ) {
                    $by[ $w['url'] ] = array( 'url' => $w['url'], 'wpPostId' => (int) ( isset( $w['wpPostId'] ) ? $w['wpPostId'] : 0 ), 'tries' => 0 );
                }
            }
        }
        return array_slice( array_values( $by ), 0, self::IMAGE_QUEUE_CAP );
    }

    // -------------------------------------------------------------- scheduling

    /**
     * Schedule the next tick for $state's seq at time $at. Records a failure.
     *
     * @return bool
     */
    private function schedule( array &$state, $at ) {
        $res = wp_schedule_single_event( max( time(), (int) $at ), BitBot::SYNC_CHUNK_CRON_HOOK, array( (int) $state['seq'], (string) $state['run_id'] ), true );
        $ok  = ( true === $res );
        if ( ! $ok ) {
            $state['schedule_failed'] = true;
        } else {
            $state['schedule_failed'] = false;
            if ( function_exists( 'spawn_cron' ) && $at <= time() + 1 ) {
                spawn_cron();
            }
        }
        return $ok;
    }

    /**
     * Schedule AFTER the state was already committed: if the schedule fails,
     * record that in the stored state so recovery finds it.
     */
    private function schedule_persisted( array $state, $at ) {
        if ( $this->schedule( $state, $at ) ) {
            return true;
        }
        $this->persist_schedule_failed( $state['run_id'] );
        return false;
    }

    /** Persist schedule_failed=true on the stored state of $run_id. */
    private function persist_schedule_failed( $run_id ) {
        for ( $i = 0; $i < 3; $i++ ) {
            $raw = $this->store->get( self::STATE_KEY );
            $s   = null === $raw ? null : json_decode( $raw, true );
            if ( ! is_array( $s ) || (string) $s['run_id'] !== (string) $run_id ) {
                return;
            }
            $s['schedule_failed'] = true;
            if ( $this->store->cas( self::STATE_KEY, $raw, BitBot_Gather_Guard::next_raw( $raw, $s ) ) ) {
                return;
            }
        }
    }

    private function has_event( array $state ) {
        return false !== wp_next_scheduled( BitBot::SYNC_CHUNK_CRON_HOOK, array( (int) $state['seq'], (string) $state['run_id'] ) );
    }

    /**
     * Put a dead or unscheduled run back on the clock. Safe to call any time.
     *
     * @return string What it did: '', 'rescheduled', 'settled_crash'.
     */
    public function recover() {
        $raw   = null;
        $state = $this->read_state( $raw );
        if ( ! $state || 'running' !== $state['state'] ) {
            return '';
        }
        $now = time();
        if ( (string) get_option( BitBot::SYNC_RUN_OPTION ) !== (string) $state['run_id'] ) {
            // A newer sync replaced this run: its render pass can never tick, so clear it
            // instead of leaving it "stalled" for ever.
            $this->retire();
            return 'retired';
        }
        $lease_raw = $this->store->get( self::LEASE_KEY );
        $lease     = null === $lease_raw ? null : json_decode( $lease_raw, true );
        $expires   = is_array( $lease ) && isset( $lease['expires'] ) ? (int) $lease['expires'] : null;
        if ( ! BitBot_Gather_Guard::run_needs_recovery( $state['state'], $expires, $now ) ) {
            return '';      // a tick is alive
        }
        $did = '';
        if ( ! empty( $state['inflight'] ) ) {
            // Settling writes run data, so it needs the lease like any tick.
            $rh = $this->lease->acquire( $state['run_id'], 30, $now, wp_generate_password( 20, false ) );
            if ( ! $rh ) {
                return '';
            }
            $settled = $this->settle_inflight( $state, 'tick died', $rh, self::DEAD_RETRY_DELAY );
            if ( null === $settled ) {
                $this->lease->release( $rh );
                return '';
            }
            $state = $settled;
            $did   = 'settled_crash';
            if ( ! $this->store->cas( self::STATE_KEY, $raw, BitBot_Gather_Guard::next_raw( $raw, array_merge( $state, array( 'seq' => $state['seq'] + 1, 'updated_at' => $now ) ) ) ) ) {
                $this->lease->release( $rh );
                return '';
            }
            $state['seq']++;
            $this->lease->release( $rh );
            $this->schedule_persisted( $state, max( $now, (int) $state['due_at'] ) );
            return 'rescheduled';
        }
        // A pass that has never ticked and has no event is recovered quickly; a running one gets a minute.
        $overdue = $now > (int) $state['due_at'] + ( 0 === (int) $state['ticks'] ? 10 : 60 );
        if ( ! empty( $state['schedule_failed'] ) || ( $overdue && ! $this->has_event( $state ) ) || 'settled_crash' === $did ) {
            $state['seq']++;
            $state['updated_at'] = $now;
            if ( ! $this->store->cas( self::STATE_KEY, $raw, BitBot_Gather_Guard::next_raw( $raw, $state ) ) ) {
                return '';
            }
            $this->schedule_persisted( $state, max( $now, (int) $state['due_at'] ) );
            return 'rescheduled';
        }
        return $did;
    }

    public function maybe_recover_on_admin() {
        if ( false !== get_transient( 'bitbot_gv2_admin_recover' ) || ! $this->store->get( self::STATE_KEY ) ) {
            return;
        }
        set_transient( 'bitbot_gv2_admin_recover', 1, 60 );
        $this->recover();
    }

    /**
     * Whatever was in flight when a tick died gets ONE more try (it stays at the
     * front of the queue); a second death of the same page gives it up, so one bad
     * page cannot loop. Returns null when a fenced write is refused.
     */
    private function settle_inflight( array $state, $reason, array $handle, $delay = null ) {
        $run   = $state['run_id'];
        $queue = $this->read_json( self::QUEUE_KEY, $run, 'ids' );
        $r     = BitBot_Gather_Queue::settle_dead( $state, $queue, $reason, self::FAILED_IDS_CAP );
        if ( $r['give_up'] ) {
            $done = $this->read_json( self::DONE_KEY, $run, 'ids' );
            $done = array_values( array_unique( array_merge( array_map( 'intval', $done ), $r['give_up'] ) ) );
            if ( ! $this->fenced_json( $handle, self::DONE_KEY, $run, 'ids', $done )
                || ! $this->fenced_json( $handle, self::QUEUE_KEY, $run, 'ids', $r['queue'] ) ) {
                return null;
            }
        }
        $state           = $r['state'];
        $state['due_at'] = time() + ( null === $delay ? self::FATAL_RETRY_DELAY : (int) $delay );
        return $state;
    }

    // -------------------------------------------------------------------- tick

    /**
     * Entry from the bitbot_sync_chunk cron event.
     *
     * @return bool True when the engine owns this run (pass 1 must not touch it).
     */
    public function handle_event( $seq, $run_id ) {
        $state = $this->read_state();
        if ( ! $state || (string) $state['run_id'] !== (string) $run_id ) {
            return false;
        }
        $this->tick( (int) $seq, false, false );
        return true;
    }

    /** Manual tick (admin continue button, backend continue). Respects the cooldown. */
    public function manual_tick( $probe = false ) {
        $this->resume();
        return $this->tick( null, true, (bool) $probe );
    }

    /** A run paused for a non-breaker reason (backend refused batches) goes back to running. */
    public function resume() {
        $raw   = null;
        $state = $this->read_state( $raw );
        if ( ! $state || 'paused' !== $state['state'] || (string) get_option( BitBot::SYNC_RUN_OPTION ) !== (string) $state['run_id'] ) {
            return false;
        }
        $state['state']        = 'running';
        $state['pause_reason'] = '';
        $state['backend_fail'] = 0;
        $state['seq']++;
        $state['updated_at']   = time();
        if ( ! $this->store->cas( self::STATE_KEY, $raw, BitBot_Gather_Guard::next_raw( $raw, $state ) ) ) {
            return false;
        }
        $this->schedule_persisted( $state, time() );
        return true;
    }

    /**
     * One tick: render waves for up to 20 s of wall time, then schedule the next.
     *
     * @param int|null $event_seq Seq the cron event carried; null for a manual tick.
     * @return array Summary.
     */
    private function tick( $event_seq, $manual, $probe ) {
        $raw   = null;
        $state = $this->read_state( $raw );
        $now   = time();
        if ( ! $state ) {
            return array( 'processed' => false, 'reason' => 'no_run' );
        }
        $seq    = null === $event_seq ? (int) $state['seq'] : (int) $event_seq;
        $reason = BitBot_Gather_Guard::check_event( $state, $state['run_id'], $seq, (string) get_option( BitBot::SYNC_RUN_OPTION ) );
        if ( '' !== $reason ) {
            if ( 'superseded' === $reason ) {
                $this->retire();        // not stuck "stalled": the next sync starts clean
            }
            return array( 'processed' => false, 'reason' => $reason );
        }
        // Someone else is mid-tick: they reschedule when they finish. Touch nothing, so a
        // doubled cron spawn cannot add a delay or a second event.
        if ( ! $manual && $this->lease->held( $now ) ) {
            return array( 'processed' => false, 'reason' => 'busy' );
        }
        $pacer = $state['pacer'];
        $may   = BitBot_Gather_Pacer::may_run( $pacer, $now, (int) $state['due_at'], $manual, $probe );
        if ( ! $may['ok'] ) {
            // An early event: put it back on the clock for when it may run.
            if ( ! $manual && ! $this->has_event( $state ) ) {
                $at = 'breaker_open' === $may['reason']
                    ? $pacer['breaker_open_at'] + BitBot_Gather_Pacer::BREAKER_WAIT
                    : max( $now + 1, (int) $state['due_at'] );
                $this->schedule_persisted( $state, $at );
            }
            return array( 'processed' => false, 'reason' => $may['reason'] );
        }

        $handle = $this->lease->acquire( $state['run_id'], self::LEASE_TTL, $now, wp_generate_password( 20, false ) );
        if ( ! $handle ) {
            return array( 'processed' => false, 'reason' => 'busy' );
        }
        $this->tick_ctx = array( 'run' => $state['run_id'], 'handle' => $handle, 'finished' => false );
        register_shutdown_function( array( $this, 'on_shutdown' ) );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $summary = array( 'processed' => true, 'rendered' => 0, 'failed' => 0, 'images' => 0 );
        $outcome = $this->run_waves( $state, $raw, $handle, $probe && $manual, $summary );
        $this->tick_ctx['finished'] = true;
        $this->lease->release( $handle );
        $summary['state']  = $outcome;
        $summary['status'] = $this->status();
        return $summary;
    }

    /**
     * The inner loop. Returns the resulting state name. Every commit is a
     * compare-and-set: if it fails, the run was retired or taken over and this
     * tick stops without touching anything else.
     */
    private function run_waves( array $state, $raw, array &$handle, $is_probe, array &$summary ) {
        $started = microtime( true );
        $run_id  = $state['run_id'];
        $queue   = $this->read_json( self::QUEUE_KEY, $run_id, 'ids' );
        $done    = array_fill_keys( array_map( 'intval', $this->read_json( self::DONE_KEY, $run_id, 'ids' ) ), true );
        $rows    = null;
        $stop    = false;

        $memory_budget = BitBot_Gather_V2::memory_budget_bytes( (string) ini_get( 'memory_limit' ) );
        while ( ! $stop && ( microtime( true ) - $started ) < self::TICK_BUDGET_SECONDS ) {
            // A tick that has used most of PHP's memory ends here; the next one starts clean.
            if ( $memory_budget > 0 && memory_get_usage( true ) > $memory_budget ) {
                break;
            }
            $now  = time();
            $size = BitBot_Gather_Pacer::wave_size( $state['pacer'], $now );
            if ( $is_probe ) {
                // An explicit probe is exactly one render, even through an open breaker.
                if ( 'open' === BitBot_Gather_Pacer::breaker_state( $state['pacer'], $now ) ) {
                    $state['pacer']['breaker_open_at'] = $now - BitBot_Gather_Pacer::BREAKER_WAIT;
                }
                $size = 1;
                $is_probe = false;      // only the first wave
            }
            if ( $size < 1 || $state['pos'] >= count( $queue ) ) {
                break;
            }
            $ids = array_slice( $queue, (int) $state['pos'], $size );
            $state['pacer']    = BitBot_Gather_Pacer::mark_probe( $state['pacer'], $now );
            $state['inflight'] = $ids;
            if ( ! $this->commit( $state, $raw, $handle ) ) {
                return 'lost';
            }

            // Visitor probes alternate the home page and an uncached search page (lib/renderGuard).
            // Search stops being a sensor for the run once it answers anything but 200 (switched off, hijacked, redirected).
            $state['probe_n'] = isset( $state['probe_n'] ) ? (int) $state['probe_n'] + 1 : 0;
            $kind  = ( empty( $state['probe_search_off'] ) && 1 === $state['probe_n'] % 2 ) ? 'search' : 'home';
            $wave  = $this->render_wave( $ids, $run_id, $state['via'], $kind, isset( $state['route_key'] ) ? (string) $state['route_key'] : '' );
            $probe = $this->last_probe;
            if ( null !== $this->last_route ) {
                $state['route_key'] = $this->last_route;
            }
            if ( $this->search_unusable ) {
                $state['probe_search_off'] = true;
            }
            $pages = array();       // renders that worked
            $items = array();       // everything sent: successes and failures
            $results = array();
            foreach ( $wave as $id => $r ) {
                $results[] = array( 'kind' => $r['kind'], 'ms' => $r['ms'] );
                $round = BitBot_Gather_Rounds::for_id( isset( $state['sample_rounds'] ) ? (array) $state['sample_rounds'] : array(), $id );
                if ( BitBot_Gather_Pacer::OK === $r['kind'] ) {
                    if ( null !== $round ) {
                        $r['page']['sampleRoundId'] = $round;       // only items asked for as samples carry it
                    }
                    $pages[] = $r['page'];
                    $items[] = $r['page'];
                    $state['via'] = $r['page']['rendered']['via'];
                } else {
                    $state['failed']++;
                    if ( count( $state['failed_ids'] ) < self::FAILED_IDS_CAP ) {
                        $state['failed_ids'][] = array( 'id' => (int) $id, 'why' => $r['error'] );
                    }
                    // The backend needs to know a sample failed, or its type never gets a decision.
                    $fp = get_post( (int) $id );
                    if ( $fp ) {
                        $fi = $this->page_item( $fp, null, isset( $r['via'] ) ? $r['via'] : '', $r['ms'], isset( $r['status'] ) ? $r['status'] : 0, $r['error'] );
                        if ( null !== $round ) {
                            $fi['sampleRoundId'] = $round;
                        }
                        $items[] = $fi;
                    }
                }
            }
            $before_backoffs = $state['pacer']['backoffs'];
            $state['pacer']  = BitBot_Gather_Pacer::record_all( $state['pacer'], $results, time() );
            if ( $state['pacer']['backoffs'] > $before_backoffs || 'closed' !== BitBot_Gather_Pacer::breaker_state( $state['pacer'], time() ) ) {
                $stop = true;       // the new gap applies from the next event, not the next wave
            }

            // Send immediately; nothing is stored locally.
            if ( $items ) {
                // The last batch of the run: nothing left in the queue after it, no images pending.
                $is_final = ( 'running' === $state['state'] ) && BitBot_Gather_Queue::is_final_batch( $state['pos'], count( $ids ), count( $queue ), $state['want_images'], (int) ( isset( $state['images_outstanding'] ) ? $state['images_outstanding'] : 0 ) );
                $res = $this->api->sync_render_batch( $items, (int) $state['ticks'], $is_final, $probe );
                if ( empty( $res['success'] ) ) {
                    $state['backend_fail']++;
                    $state['inflight'] = array();
                    if ( $state['backend_fail'] >= self::BACKEND_FAIL_PAUSE ) {
                        $state['state']        = 'paused';
                        $state['pause_reason'] = 'The Youami service did not accept render batches: ' . ( isset( $res['error'] ) ? (string) $res['error'] : 'unknown error' );
                    }
                    // pos is NOT advanced: those pages are rendered again later. Failed ones already counted.
                    $stop = true;
                    if ( ! $this->commit( $state, $raw, $handle ) ) {
                        return 'lost';
                    }
                    break;
                }
                $state['backend_fail'] = 0;
                if ( $is_final ) {
                    $state['final_sent'] = true;
                }
                $state = $this->absorb_response( $state, $res['data'], true );
                if ( ! empty( $state['pause_seconds'] ) ) {
                    $stop = true;       // the backend asked for a pause: end this tick
                }
            }

            foreach ( $ids as $id ) {
                $done[ (int) $id ] = true;
            }
            $state['pos']      += count( $ids );
            $state['done']     += count( $pages );
            $state['inflight']  = array();
            $summary['rendered'] += count( $pages );
            $summary['failed']   += count( $ids ) - count( $pages );

            if ( 'running' !== $state['state'] ) {
                $stop = true;
            }
            if ( ! $this->rebuild_if_needed( $state, $queue, $done, $rows, $handle )
                || ! $this->fenced_json( $handle, self::DONE_KEY, $run_id, 'ids', array_keys( $done ) ) ) {
                return 'lost';
            }
            if ( ! $this->commit( $state, $raw, $handle ) ) {
                return 'lost';
            }
            $renewed = $this->lease->renew( $handle, self::LEASE_TTL, time() );
            if ( ! $renewed ) {
                return 'lost';
            }
            $handle = $renewed;
            $this->tick_ctx['handle'] = $handle;
        }

        // Images the backend could not fetch itself: batches of 5 (the backend's per-call
        // limit) back to back until the tick budget is spent, renewing the lease between them.
        // The budget is the bound: a site with thousands of images takes many 20 s ticks.
        $progressed = true;
        while ( 'running' === $state['state'] && BitBot_Gather_Images::may_continue( microtime( true ) - $started, self::TICK_BUDGET_SECONDS, BitBot_Gather_Images::pending_units( count( $state['want_images'] ), isset( $state['images_outstanding'] ) ? $state['images_outstanding'] : 0 ), $progressed ) ) {
            $asking     = ! $state['want_images'];
            $before     = (int) $state['images_sent'] + (int) ( isset( $state['images_dropped'] ) ? $state['images_dropped'] : 0 );
            $state      = $this->upload_images( $state, $summary );
            $progressed = ( (int) $state['images_sent'] + (int) ( isset( $state['images_dropped'] ) ? $state['images_dropped'] : 0 ) ) > $before || ( $asking && ! empty( $state['want_images'] ) );
            $rebuilt    = ! empty( $state['_rebuild'] );
            if ( ! $this->rebuild_if_needed( $state, $queue, $done, $rows, $handle )
                || ( $rebuilt && ! $this->fenced_json( $handle, self::DONE_KEY, $run_id, 'ids', array_keys( $done ) ) ) ) {
                return 'lost';
            }
            if ( ! $this->commit( $state, $raw, $handle ) ) {
                return 'lost';
            }
            $renewed = $this->lease->renew( $handle, self::LEASE_TTL, time() );
            if ( ! $renewed ) {
                return 'lost';
            }
            $handle = $renewed;
            $this->tick_ctx['handle'] = $handle;
            if ( $state['pos'] < count( $queue ) ) {
                break;      // a new decision queued more renders: those go first, next tick
            }
        }

        // Finish: done, paused, or the next event.
        $now = time();
        $state['ticks']++;
        $state['last_tick_at'] = $now;
        $state['seq']++;
        if ( 'running' === $state['state'] && $state['pos'] >= count( $queue ) ) {
            // Drained: re-derive what is left for the current decision before calling it done.
            $state['_rebuild'] = true;
            if ( ! $this->rebuild_if_needed( $state, $queue, $done, $rows, $handle )
                || ! $this->fenced_json( $handle, self::DONE_KEY, $run_id, 'ids', array_keys( $done ) ) ) {
                return 'lost';
            }
        }
        if ( 'running' === $state['state'] && BitBot_Gather_Queue::is_finished( $state, $queue ) ) {
            // The run ends. If no batch was marked final (a breaker pause cut the
            // last one off), say so now with an empty final batch.
            if ( empty( $state['final_sent'] ) ) {
                $res = $this->api->sync_render_batch( array(), (int) $state['ticks'], true );
                if ( empty( $res['success'] ) ) {
                    $state['backend_fail']++;
                    if ( $state['backend_fail'] >= self::BACKEND_FAIL_PAUSE ) {
                        $state['state']        = 'paused';
                        $state['pause_reason'] = 'The Youami service did not accept the final render batch.';
                    }
                } else {
                    $state['final_sent'] = true;
                    $state['backend_fail'] = 0;
                }
            }
            if ( 'running' === $state['state'] && ! empty( $state['final_sent'] ) ) {
                $state['state'] = 'done';
            }
        }
        if ( 'running' === $state['state'] ) {
            $p = $state['pacer'];
            if ( 'closed' !== BitBot_Gather_Pacer::breaker_state( $p, $now ) ) {
                $state['due_at'] = $p['breaker_open_at'] + BitBot_Gather_Pacer::BREAKER_WAIT;
            } else {
                $pause           = isset( $state['pause_seconds'] ) ? $state['pause_seconds'] : 0;
                $state['due_at'] = BitBot_Gather_Images::next_due( $now, BitBot_Gather_Pacer::next_due( $p, $now, $pause ), $state['pos'] >= count( $queue ), BitBot_Gather_Images::pending_units( count( $state['want_images'] ), isset( $state['images_outstanding'] ) ? $state['images_outstanding'] : 0 ), $pause );
            }
            $this->schedule( $state, $state['due_at'] );
        }
        if ( ! $this->commit( $state, $raw, $handle ) ) {
            return 'lost';
        }
        $this->mirror_progress( $state );
        return $state['state'];
    }

    /** A new renderTypes / sample decision from the backend: rebuild what is left to render. */
    private function rebuild_if_needed( array &$state, array &$queue, array $done, &$rows, array $handle ) {
        if ( empty( $state['_rebuild'] ) ) {
            return true;
        }
        unset( $state['_rebuild'] );
        if ( null === $rows ) {
            $rows = $this->read_json( self::ROWS_KEY, $state['run_id'], 'rows' );
        }
        $current = array_fill_keys( self::merge_current( $this->read_json( self::CURRENT_KEY, $state['run_id'], 'ids' ), array() ), true );
        $r     = BitBot_Gather_Queue::rebuild( $state, $rows, $done, $current );
        $state = $r['state'];
        $queue = $r['queue'];
        if ( $queue ) {
            $state['final_sent'] = false;       // more to render: an earlier "final" batch no longer ends the run
        }
        return $this->fenced_json( $handle, self::QUEUE_KEY, $state['run_id'], 'ids', $queue );
    }

    /**
     * Lease check then compare-and-set. On success $raw becomes the new stored
     * value so the next commit in this tick compares against it.
     */
    private function commit( array &$state, &$raw, array $handle ) {
        $state['updated_at'] = time();
        $new = BitBot_Gather_Guard::next_raw( $raw, $state );
        if ( ! $this->lease->owns( $handle, time() ) || (string) get_option( BitBot::SYNC_RUN_OPTION ) !== (string) $state['run_id'] ) {
            return false;
        }
        if ( ! $this->store->cas( self::STATE_KEY, $raw, $new ) ) {
            return false;
        }
        $raw = $new;
        return true;
    }

    /** Read renderTypes / sampleRequests / wantImages / gatherV2 from a response. */
    private function absorb_response( array $state, $data, $check_gate = false ) {
        if ( ! is_array( $data ) ) {
            return $state;
        }
        // A SyncContent answer without gatherV2:true means the backend switched it
        // off (its off answer simply omits the field). Stop, and forget the flag.
        if ( $check_gate && ! BitBot_Gather_Guard::gate_is_on( $data ) ) {
            self::note_flag( $data );
            $state['state']        = 'paused';
            $state['pause_reason'] = 'gather v2 switched off';
            return $state;
        }
        if ( $check_gate ) {
            // renderConcurrencyMax: the latest SyncContent answer decides; absent = 1.
            $cap            = isset( $data['renderConcurrencyMax'] ) ? max( 1, (int) $data['renderConcurrencyMax'] ) : 1;
            $state['pacer'] = BitBot_Gather_Pacer::set_cap( $state['pacer'], $cap );
            // renderPauseSeconds: slow down now, cleared by the next normal answer.
            $pause = isset( $data['renderPauseSeconds'] ) ? (int) $data['renderPauseSeconds'] : 0;
            if ( $pause > 0 ) {
                $state['pause_seconds'] = $pause;
                $state['pacer']         = BitBot_Gather_Pacer::slow_down( $state['pacer'] );
                $state['pause_reason']  = 'backend asked to slow down';
            } else {
                if ( ! empty( $state['pause_seconds'] ) && 'backend asked to slow down' === $state['pause_reason'] ) {
                    $state['pause_reason'] = '';
                }
                $state['pause_seconds'] = 0;
            }
        }
        if ( isset( $data['renderTypes'] ) && is_array( $data['renderTypes'] ) ) {
            $types = $this->string_list( $data['renderTypes'] );
            if ( $types !== $state['render_types'] ) {
                $state['render_types'] = $types;
                $state['_rebuild']     = true;
            }
        }
        $rounds = BitBot_Gather_Rounds::from_response( $data );
        if ( $rounds ) {
            $state['sample_rounds'] = BitBot_Gather_Rounds::merge( isset( $state['sample_rounds'] ) ? (array) $state['sample_rounds'] : array(), $rounds );
        }
        $samples = $this->sample_ids( $data );
        if ( $samples && $samples !== $state['samples'] ) {
            $state['samples']  = $samples;
            $state['_rebuild'] = true;
        }
        $state['want_images'] = $this->image_requests( $data, $state['want_images'], isset( $state['images_uploaded'] ) ? (array) $state['images_uploaded'] : array() );
        return $state;
    }

    /** Keep the existing sync-progress option in step so the SPA's one endpoint shows it. */
    private function mirror_progress( array $state ) {
        $progress = get_option( BitBot::SYNC_PROGRESS_OPTION );
        if ( ! is_array( $progress ) ) {
            return;
        }
        $progress['gather'] = $this->status_from( $state );
        // Atomic and run-conditioned: a superseded run (false) is simply ignored.
        BitBot::get_instance()->write_progress_if_current( (string) $state['run_id'], $progress );
    }

    // ------------------------------------------------------------------ renders

    /**
     * Render a wave of pages in parallel.
     *
     * @return array id => { kind, ms, error, page? }
     */
    private function render_wave( array $ids, $run_id, $preferred_via, $probe_kind = 'home', $preferred_route = '' ) {
        $this->last_probe      = null;
        $this->last_route      = null;
        $this->search_unusable = false;
        $out     = array();
        $pending = array();
        $tokens  = array();
        $plan    = BitBot_Gather_Routes::plan( home_url( '/' ) );
        $host    = $plan['host_header'];       // carries the port when the site has one
        foreach ( $ids as $id ) {
            $post = get_post( (int) $id );
            if ( ! $post || 'publish' !== $post->post_status ) {
                $out[ $id ] = array( 'kind' => BitBot_Gather_Pacer::PAGE_FAIL, 'ms' => 0, 'error' => 'post missing or no longer published' );
                continue;
            }
            $path = $this->site_path( get_permalink( $post ) );
            if ( '' === $path ) {
                $out[ $id ] = array( 'kind' => BitBot_Gather_Pacer::PAGE_FAIL, 'ms' => 0, 'error' => 'permalink is not on this site' );
                continue;
            }
            $token = wp_generate_password( 24, false );
            $key   = self::TOKEN_PREFIX . sha1( $token );
            $this->store->add( $key, wp_json_encode( array( 'run' => (string) $run_id, 'post' => (int) $id, 'exp' => time() + self::TOKEN_TTL ) ) );
            $tokens[]       = $key;
            $pending[ $id ] = array( 'post' => $post, 'path' => $path, 'token' => $token, 'last' => array( 'kind' => BitBot_Gather_Pacer::PAGE_FAIL, 'ms' => 0, 'error' => 'no attempt', 'status' => 0, 'via' => '' ) );
        }

        $routes = array(
            'http'  => array( 'via' => 'loopback', 'base' => $plan['loopback_http'], 'verify' => false ),
            'https' => array( 'via' => 'loopback', 'base' => $plan['loopback_https'], 'verify' => false ),
            'self'  => array( 'via' => 'self', 'base' => '', 'verify' => true ),
        );
        $order = array_keys( $routes );
        if ( '' !== $preferred_route && isset( $routes[ $preferred_route ] ) ) {
            // The exact route that worked last (http and https are both "loopback").
            $order = array_values( array_unique( array_merge( array( $preferred_route ), $order ) ) );
        } elseif ( '' !== $preferred_via ) {
            usort( $order, function ( $a, $b ) use ( $routes, $preferred_via ) {
                return (int) ( $routes[ $b ]['via'] === $preferred_via ) - (int) ( $routes[ $a ]['via'] === $preferred_via );
            } );
        }

        // The paired visitor probe (lib/renderGuard): only on the EXACT route the last
        // wave rendered on (a blocked loopback must not read as a sick host), idle first
        // with nothing of ours in flight, then the same destination inside the wave's
        // own request. A redirect is resolved once, on the idle probe, so both measure
        // the page a visitor lands on, never the redirect response.
        $probe_rk  = ( '' !== $preferred_route && isset( $routes[ $preferred_route ] ) && $pending ) ? $preferred_route : null;
        $probe_rq  = null;
        $probe_out = null;
        if ( null !== $probe_rk ) {
            $resolved = $this->resolve_probe( $this->visitor_probe_request( $probe_kind, $routes[ $probe_rk ], $host ), $routes[ $probe_rk ], $plan );
            if ( null !== $resolved ) {
                $probe_rq  = $resolved['request'];
                $probe_out = array( 'url' => $probe_kind, 'idleMs' => $this->probe_ms( $resolved['timed'] ), 'idleStatus' => (int) $resolved['timed']['status'] );
            } elseif ( 'search' === $probe_kind ) {
                $this->search_unusable = true;
            }
        }
        $rendered_on = null;

        foreach ( $order as $rk ) {
            if ( ! $pending ) {
                break;
            }
            $route    = $routes[ $rk ];
            $requests = array();
            foreach ( $pending as $id => $p ) {
                $url = '' === $route['base'] ? home_url( $p['path'] ) : $route['base'] . $p['path'];
                $requests[ $id ] = array(
                    'url'     => $url,
                    'headers' => array( 'Host' => $host, 'X-BitBot-Gather' => $p['token'], 'Accept' => 'text/html' ),
                    'type'    => 'GET',
                    'options' => array(),
                );
            }
            $in_flight = count( $requests );
            if ( $rk === $probe_rk && null !== $probe_rq ) {
                $requests['probe'] = $probe_rq;
            }
            $timed = $this->multi_get( $requests, $route['verify'] );
            if ( isset( $timed['probe'] ) ) {
                $probe_out['waveMs']      = $this->probe_ms( $timed['probe'] );
                $probe_out['waveStatus']  = (int) $timed['probe']['status'];
                $probe_out['concurrency'] = $in_flight;
                unset( $timed['probe'], $requests['probe'] );
            }
            $timed = $this->follow_redirects_once( $timed, $requests, $route, $plan );
            foreach ( $timed as $id => $t ) {
                $cls = $this->classify_response( $t );
                if ( BitBot_Gather_Pacer::OK === $cls['kind'] ) {
                    $out[ $id ] = array(
                        'kind' => BitBot_Gather_Pacer::OK,
                        'ms'   => $t['ms'],
                        'page' => $this->page_item( $pending[ $id ]['post'], $cls['html'], $route['via'], $t['ms'], $t['status'] ),
                    );
                    unset( $pending[ $id ] );
                    if ( null === $rendered_on ) {
                        $rendered_on = $rk;
                    }
                } else {
                    $pending[ $id ]['last'] = array( 'kind' => $cls['kind'], 'ms' => $t['ms'], 'error' => $cls['error'], 'status' => $t['status'], 'via' => $route['via'] );
                    // A struggling host (5xx, 429) is not asked again through another route.
                    if ( BitBot_Gather_Pacer::HOST_FAIL === $cls['kind'] && $t['status'] > 0 ) {
                        $out[ $id ] = array( 'kind' => $cls['kind'], 'ms' => $t['ms'], 'error' => $cls['error'], 'status' => $t['status'], 'via' => $route['via'] );
                        unset( $pending[ $id ] );
                    }
                }
            }
        }
        foreach ( $pending as $id => $p ) {
            $l = $p['last'];
            $out[ $id ] = array( 'kind' => $l['kind'], 'ms' => $l['ms'], 'error' => 'all render routes failed: ' . $l['error'], 'status' => $l['status'], 'via' => $l['via'] );
        }
        foreach ( $tokens as $key ) {
            $this->store->delete( $key );   // unused tokens do not outlive the wave
        }
        // The probe counts only when the wave rendered on the route it was taken on:
        // otherwise its failure says "this route is blocked", not "the host is unwell".
        $this->last_route = $rendered_on;
        if ( null !== $probe_out && isset( $probe_out['waveStatus'] ) && $rendered_on === $probe_rk ) {
            if ( self::probe_status_usable( $probe_out['waveStatus'] ) ) {
                $this->last_probe = $probe_out;
            } elseif ( 'search' === $probe_kind ) {
                $this->search_unusable = true;
            }
        }
        return $out;
    }

    /**
     * The idle probe, with one same-host redirect resolved: returns the request
     * that reaches the destination and its timed idle answer, or null when the
     * probe is no visitor-latency sensor here (a redirect off the site, two
     * redirects, or a non-200 that is not a sign of distress).
     */
    private function resolve_probe( array $rq, array $route, array $plan ) {
        $t = $this->multi_get( array( 'probe' => $rq ), $route['verify'] );
        $t = $t['probe'];
        if ( in_array( (int) $t['status'], array( 301, 302, 307, 308 ), true ) ) {
            $f = empty( $t['location'] ) ? null : BitBot_Gather_Routes::follow( $t['location'], $plan['host'] );
            if ( ! $f ) {
                return null;
            }
            if ( null !== $f['host_header'] ) {
                $rq['headers']['Host'] = $f['host_header'];
            }
            $rq['url'] = '' === $route['base'] ? home_url( $f['path'] ) : $route['base'] . $f['path'];
            $t = $this->multi_get( array( 'probe' => $rq ), $route['verify'] );
            $t = $t['probe'];
        }
        return self::probe_status_usable( (int) $t['status'] ) ? array( 'request' => $rq, 'timed' => $t ) : null;
    }

    /**
     * A probe answer the guard can use: a 200 (a page a visitor gets), or a sign
     * of distress (no answer, 429, 5xx). A 3xx or another 4xx measures nothing a
     * visitor waits for, so that pair is dropped.
     */
    public static function probe_status_usable( $status ) {
        $status = (int) $status;
        return 200 === $status || 0 === $status || 429 === $status || $status >= 500;
    }

    /** A visitor's GET of the home page or an uncached search page: no gather token, so it renders as any visitor's would. */
    private function visitor_probe_request( $kind, array $route, $host ) {
        $path = $this->site_path( home_url( '/' ) );
        if ( '' === $path ) {
            $path = '/';
        }
        if ( 'search' === $kind ) {
            $path .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . 's=bbprobe' . wp_rand( 1000, 9999 );
        }
        return array(
            'url'     => '' === $route['base'] ? home_url( $path ) : $route['base'] . $path,
            'headers' => array( 'Host' => $host, 'Accept' => 'text/html', 'Cache-Control' => 'no-cache' ),
            'type'    => 'GET',
            'options' => array(),
        );
    }

    /** Time to first byte of one timed response, ms; null when there was no response at all. */
    private function probe_ms( array $t ) {
        if ( 0 === (int) $t['status'] ) {
            return null;
        }
        return (int) ( isset( $t['ttfb'] ) ? $t['ttfb'] : $t['ms'] );
    }

    /**
     * The memory a tick may use before it stops starting waves: MEMORY_SHARE of
     * memory_limit. 0 = no bound (memory_limit -1, or unreadable).
     *
     * @param string $limit memory_limit as PHP reports it ("256M", "1G", "-1", "134217728").
     */
    public static function memory_budget_bytes( $limit ) {
        $limit = trim( (string) $limit );
        if ( '' === $limit || '-1' === $limit ) {
            return 0;
        }
        $n    = (float) $limit;
        $unit = strtolower( substr( $limit, -1 ) );
        if ( 'g' === $unit ) {
            $n *= 1073741824;
        } elseif ( 'm' === $unit ) {
            $n *= 1048576;
        } elseif ( 'k' === $unit ) {
            $n *= 1024;
        }
        return $n > 0 ? (int) ( $n * self::MEMORY_SHARE ) : 0;
    }

    /** Requests::request_multiple with a per-request latency from curl's own timing. */
    private function multi_get( array $requests, $verify ) {
        $cls   = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : 'Requests';
        $hooks = class_exists( '\WpOrg\Requests\Hooks' ) ? '\WpOrg\Requests\Hooks' : 'Requests_Hooks';
        $ca    = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        $ms    = array();
        $ttfb  = array();
        foreach ( $requests as $id => &$rq ) {
            $h = new $hooks();
            $h->register( 'curl.after_request', function ( $headers, &$info ) use ( &$ms, &$ttfb, $id ) {
                if ( is_array( $info ) && isset( $info['total_time'] ) ) {
                    $ms[ $id ] = (int) round( $info['total_time'] * 1000 );
                }
                if ( is_array( $info ) && isset( $info['starttransfer_time'] ) && $info['starttransfer_time'] > 0 ) {
                    $ttfb[ $id ] = (int) round( $info['starttransfer_time'] * 1000 );
                }
            } );
            $rq['options'] = array(
                'timeout'          => self::REQUEST_TIMEOUT,
                'connect_timeout'  => 5,
                'redirects'        => 0,
                'follow_redirects' => false,
                'verify'           => $verify ? ( file_exists( $ca ) ? $ca : true ) : false,
                'useragent'        => 'BitBot-Gather/2.0',
                'hooks'            => $h,
            );
        }
        unset( $rq );

        $t0   = microtime( true );
        $resp = array();
        try {
            $resp = call_user_func( array( $cls, 'request_multiple' ), $requests );
        } catch ( \Throwable $e ) {
            foreach ( $requests as $id => $_ ) {
                $resp[ $id ] = $e;
            }
        }
        $wall = (int) round( ( microtime( true ) - $t0 ) * 1000 );
        $out  = array();
        foreach ( $requests as $id => $_ ) {
            $r = isset( $resp[ $id ] ) ? $resp[ $id ] : null;
            $t = array( 'ms' => isset( $ms[ $id ] ) ? $ms[ $id ] : $wall, 'status' => 0, 'body' => '', 'error' => '', 'location' => '' );
            if ( isset( $ttfb[ $id ] ) ) {
                $t['ttfb'] = $ttfb[ $id ];
            }
            if ( $r instanceof \Exception ) {
                $t['error'] = $r->getMessage();
            } elseif ( is_object( $r ) && isset( $r->status_code ) ) {
                $t['status'] = (int) $r->status_code;
                $t['body']   = (string) $r->body;
                $loc         = isset( $r->headers['location'] ) ? $r->headers['location'] : '';
                $t['location'] = is_array( $loc ) ? (string) reset( $loc ) : (string) $loc;
            } else {
                $t['error'] = 'no response';
            }
            $out[ $id ] = $t;
        }
        return $out;
    }

    /**
     * A 301/302/307/308 to the SAME host (www or not, with or without a port) is
     * followed once, on the same route with the Host the redirect names.
     */
    private function follow_redirects_once( array $timed, array $requests, array $route, array $plan ) {
        $again = array();
        foreach ( $timed as $id => $t ) {
            if ( ! in_array( $t['status'], array( 301, 302, 307, 308 ), true ) || empty( $t['location'] ) ) {
                continue;
            }
            $f = BitBot_Gather_Routes::follow( $t['location'], $plan['host'] );
            if ( ! $f ) {
                continue;
            }
            $rq = $requests[ $id ];
            if ( null !== $f['host_header'] ) {
                $rq['headers']['Host'] = $f['host_header'];
            }
            $rq['url'] = '' === $route['base'] ? home_url( $f['path'] ) : $route['base'] . $f['path'];
            if ( '' === $route['base'] && null !== $f['host_header'] ) {
                $rq['url'] = ( 0 === strpos( $t['location'], 'http' ) ) ? $t['location'] : $rq['url'];
            }
            $again[ $id ] = $rq;
        }
        if ( ! $again ) {
            return $timed;
        }
        foreach ( $this->multi_get( $again, $route['verify'] ) as $id => $t2 ) {
            $t2['ms'] += $timed[ $id ]['ms'];
            $t2['location'] = '';       // one hop only
            $timed[ $id ] = $t2;
        }
        return $timed;
    }

    private function classify_response( array $t ) {
        if ( '' !== $t['error'] ) {
            $timed_out = (bool) preg_match( '/timed out|timeout|cURL error 28/i', $t['error'] );
            return array( 'kind' => BitBot_Gather_Pacer::classify( 0, true, false, $timed_out ), 'error' => $t['error'], 'html' => '' );
        }
        $is_html = '' !== $t['body'] && false !== stripos( $t['body'], '<html' );
        $kind    = BitBot_Gather_Pacer::classify( $t['status'], false, $is_html );
        $err     = '';
        if ( BitBot_Gather_Pacer::OK !== $kind ) {
            $err = ( $t['status'] >= 200 && $t['status'] < 300 ) ? 'response was not an HTML document' : 'HTTP ' . $t['status'];
        }
        return array( 'kind' => $kind, 'error' => $err, 'html' => $t['body'] );
    }

    /** One render-batch item. $html null = the render failed ($error says why). */
    private function page_item( $post, $html, $via, $ms, $status, $error = '' ) {
        if ( null !== $html ) {
            $html = $this->strip_metrics( $html );
            if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
                $html = substr( $html, 0, self::MAX_HTML_BYTES );
            }
        }
        // The pass-1 facts ride along so the backend keeps price, stock, taxonomies,
        // alt text and PDF links in the rendered record.
        $content    = '';
        $structured = null;
        try {
            $GLOBALS['post'] = $post;
            setup_postdata( $post );
            $content = (string) apply_filters( 'the_content', get_the_content( null, false, $post ) );
            wp_reset_postdata();
            $f          = BitBot_Gather_Fields::for_post( $post, $content, $this->api->detect_built_with( $post->ID, $post ) );
            $structured = $f['structured'];
        } catch ( \Throwable $e ) {
            $structured = null;
        }
        return array(
            'url'           => (string) get_permalink( $post ),
            'title'         => (string) get_the_title( $post ),
            'content'       => $content,
            'structured'    => $structured,
            'wpPostId'      => (int) $post->ID,
            'gatherVersion' => 2,
            'postType'      => (string) $post->post_type,
            'modifiedGmt'   => (string) $post->post_modified_gmt,
            'rendered'      => null === $html
                ? array( 'status' => (int) $status, 'error' => substr( (string) $error, 0, 160 ), 'htmlGzB64' => null, 'via' => (string) $via, 'ms' => (int) $ms )
                : array( 'htmlGzB64' => base64_encode( gzencode( $html, 6 ) ), 'via' => (string) $via, 'ms' => (int) $ms, 'status' => (int) $status ),
        );
    }

    private function site_path( $url ) {
        $p    = wp_parse_url( $url );
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        if ( empty( $p['host'] ) || 0 !== strcasecmp( $p['host'], $host ) ) {
            return '';
        }
        $path = isset( $p['path'] ) ? $p['path'] : '/';
        if ( isset( $p['query'] ) && '' !== $p['query'] ) {
            $path .= '?' . $p['query'];
        }
        if ( '' === $path || '/' !== $path[0] || ( isset( $path[1] ) && '/' === $path[1] ) ) {
            return '';
        }
        return $path;
    }

    // ---------------------------------------------------------- render-side token

    /**
     * Runs inside the RENDERING request. The token must exist, be unexpired and
     * be consumed by this request (delete-if-equal, so a replay finds nothing);
     * it is bound to a run and one page, and only emits for that page.
     */
    public function maybe_capture_render_metrics() {
        $sent = isset( $_SERVER[ self::HEADER ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ self::HEADER ] ) ) : '';
        if ( '' === $sent || strlen( $sent ) > 64 ) {
            return;
        }
        $key = self::TOKEN_PREFIX . sha1( $sent );
        $raw = $this->store->get( $key );
        if ( null === $raw || ! $this->store->delete_if( $key, $raw ) ) {
            return;     // unknown, or someone else consumed it first
        }
        $tok = json_decode( $raw, true );
        if ( ! is_array( $tok ) || (int) $tok['exp'] < time() || (int) $tok['post'] !== (int) get_queried_object_id() ) {
            return;
        }
        $state = $this->read_state();
        if ( ! $state || (string) $state['run_id'] !== (string) $tok['run'] ) {
            return;
        }
        // WordPress 6.9 and later: the core template enhancement buffer opens
        // and closes the buffer for us.
        if ( function_exists( 'wp_start_template_enhancement_output_buffer' ) ) {
            add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'append_metrics_marker' ) );
            return;
        }
        // Older WordPress has no such filter: no output buffer is opened, so
        // the marker is simply not added.
    }

    /**
     * Append the metrics comment the backend reads back (see strip_metrics).
     *
     * @param string $buf The page HTML.
     * @return string
     */
    public function append_metrics_marker( $buf ) {
        $t0 = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
        return $buf . "\n<!--" . self::MARKER . ' mem=' . memory_get_peak_usage( true )
            . ' ms=' . (int) round( ( microtime( true ) - $t0 ) * 1000 ) . ' q=' . (int) get_num_queries() . '-->';
    }

    private function strip_metrics( $html ) {
        return preg_replace( '/\n?<!--' . self::MARKER . ' mem=\d+ ms=\d+ q=\d+-->\s*$/', '', $html );
    }

    // ------------------------------------------------------------------- images

    /** One UploadPageImages call of at most 5 images, each at most 5 MB. */
    private function upload_images( array $state, array &$summary ) {
        $batch   = array();
        $keep    = array();
        $payload = array();
        foreach ( $state['want_images'] as $w ) {
            if ( count( $batch ) >= self::IMAGES_PER_CALL ) {
                $keep[] = $w;
                continue;
            }
            $this->image_reason = '';
            $img = $this->read_image( $w['url'] );
            if ( $img ) {
                $batch[]   = $w;
                $payload[] = array( 'url' => $w['url'], 'wpPostId' => (int) $w['wpPostId'], 'mime' => $img['mime'], 'b64' => base64_encode( $img['bytes'] ) );
            } else {
                // Unreadable or too big images are dropped (the backend cannot read them either), but visibly.
                $state = $this->note_image( $state, $w['url'], '' !== $this->image_reason ? $this->image_reason : 'download_failed', '', true );
            }
        }
        $ask_again = ! $state['want_images'] && (int) ( isset( $state['images_outstanding'] ) ? $state['images_outstanding'] : 0 ) > 0;
        if ( ! $payload && ! $ask_again ) {
            $state['want_images'] = $keep;
            return $state;
        }
        $res = $this->api->upload_page_images( $payload );
        if ( ! empty( $res['success'] ) ) {
            $data = is_array( $res['data'] ) ? $res['data'] : array();
            $list = isset( $data['results'] ) ? $data['results'] : ( isset( $data['images'] ) ? $data['images'] : null );
            // Per image: read/cached = done, stale/rejected = drop, skipped = retry later (3 tries).
            $settled = BitBot_Gather_Images::settle( $batch, $list );
            // The backend's count of wants still outstanding (a missing field, an older backend, means none).
            // An empty "any more?" call that brings nothing new ends the phase after a few tries.
            $state['images_outstanding'] = isset( $data['wantsOutstanding'] ) ? max( 0, (int) $data['wantsOutstanding'] ) : 0;
            if ( $ask_again ) {
                $got = isset( $data['wantImages'] ) && is_array( $data['wantImages'] ) && $data['wantImages'];
                $state['images_dry'] = $got ? 0 : (int) ( isset( $state['images_dry'] ) ? $state['images_dry'] : 0 ) + 1;
                if ( $state['images_dry'] >= BitBot_Gather_Images::DRY_ASKS ) {
                    $state['images_outstanding'] = 0;
                }
            }
            $kept_urls = array_column( $settled['keep'], 'url' );
            $sent_urls = array();
            foreach ( $batch as $w ) {
                if ( ! in_array( $w['url'], $kept_urls, true ) ) {
                    $sent_urls[] = $w['url'];
                }
            }
            $state['images_uploaded'] = BitBot_Gather_Images::remember( isset( $state['images_uploaded'] ) ? (array) $state['images_uploaded'] : array(), $sent_urls );
            foreach ( $settled['statuses'] as $u => $st ) {
                $state = $this->note_image( $state, $u, $st, '', false );
            }
            $state['images_dropped'] = (int) ( isset( $state['images_dropped'] ) ? $state['images_dropped'] : 0 ) + $settled['dropped'];
            $state['images_sent']  += $settled['done'];
            $summary['images']     += $settled['done'];
            $state['want_images']   = array_merge( $keep, $settled['keep'] );
            return $this->absorb_response( $state, $data, false );
        }
        if ( $ask_again ) {
            $state['images_dry'] = (int) ( isset( $state['images_dry'] ) ? $state['images_dry'] : 0 ) + 1;
            if ( $state['images_dry'] >= BitBot_Gather_Images::DRY_ASKS ) {
                $state['images_outstanding'] = 0;
            }
        }
        foreach ( $batch as $w ) {
            $w['tries'] = (int) ( isset( $w['tries'] ) ? $w['tries'] : 0 ) + 1;
            $state = $this->note_image( $state, $w['url'], 'rpc_failed', isset( $res['error'] ) ? (string) $res['error'] : '', false );
            if ( $w['tries'] < self::IMAGE_TRIES ) {
                $keep[] = $w;
            } else {
                $state['images_dropped'] = (int) ( isset( $state['images_dropped'] ) ? $state['images_dropped'] : 0 ) + 1;
            }
        }
        $state['want_images'] = $keep;
        return $state;
    }

    /** Record one image result in the bounded log, and in the plugin's debug log. */
    private function note_image( array $state, $url, $status, $reason, $dropped ) {
        $state['image_log'] = BitBot_Gather_Images::log_push( isset( $state['image_log'] ) ? (array) $state['image_log'] : array(), $url, $status, $reason, time() );
        if ( $dropped ) {
            $state['images_dropped'] = (int) ( isset( $state['images_dropped'] ) ? $state['images_dropped'] : 0 ) + 1;
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[BitBot] gather image ' . $status . ( '' !== (string) $reason ? ' (' . $reason . ')' : '' ) . ' ' . substr( (string) $url, 0, 200 ) );
        }
        return $state;
    }

    /**
     * Bytes of a site image, or null. The URL came from the backend, so it is
     * checked: http(s) only, and either under this site's uploads or on this
     * site's own host. Local files are read from disk inside uploads only.
     */
    private function read_image( $url ) {
        if ( ! wp_http_validate_url( $url ) ) {
            $this->image_reason = 'not_site_image';
            return null;
        }
        $up   = wp_upload_dir( null, false );
        $base = trailingslashit( $up['baseurl'] );
        if ( 0 === strpos( $url, $base ) ) {
            $rel  = urldecode( substr( strtok( $url, '?' ), strlen( $base ) ) );
            $file = realpath( trailingslashit( $up['basedir'] ) . $rel );
            $root = realpath( $up['basedir'] );
            if ( $file && $root && 0 === strpos( $file, $root . DIRECTORY_SEPARATOR ) && is_file( $file ) ) {
                if ( filesize( $file ) > self::IMAGE_MAX_BYTES ) {
                    $this->image_reason = 'too_large';
                    return null;
                }
                $mime = wp_check_filetype( $file )['type'];
                $bytes = file_get_contents( $file );
                if ( $mime && 0 === strpos( $mime, 'image/' ) && false !== $bytes ) {
                    return array( 'mime' => $mime, 'bytes' => $bytes );
                }
                $this->image_reason = false === $bytes ? 'download_failed' : 'not_an_image';
                return null;
            }
        }
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        if ( 0 !== strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), $host ) ) {
            $this->image_reason = 'not_site_image';
            return null;
        }
        $res = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => self::IMAGE_MAX_BYTES + 1, 'cookies' => array() ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            $this->image_reason = 'download_failed';
            return null;
        }
        $bytes = (string) wp_remote_retrieve_body( $res );
        $mime  = (string) wp_remote_retrieve_header( $res, 'content-type' );
        $mime  = strtolower( trim( strtok( $mime, ';' ) ) );
        if ( strlen( $bytes ) > self::IMAGE_MAX_BYTES ) {
            $this->image_reason = 'too_large';
            return null;
        }
        if ( '' === $bytes || 0 !== strpos( $mime, 'image/' ) ) {
            $this->image_reason = '' === $bytes ? 'download_failed' : 'not_an_image';
            return null;
        }
        return array( 'mime' => $mime, 'bytes' => $bytes );
    }

    // ------------------------------------------------------------------ shutdown

    /** A fatal inside a render tick: give up on what was in flight and reschedule. */
    public function on_shutdown() {
        $ctx = $this->tick_ctx;
        if ( ! $ctx || ! empty( $ctx['finished'] ) ) {
            return;
        }
        $err = error_get_last();
        if ( ! $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
            return;
        }
        $raw   = null;
        $state = $this->read_state( $raw );
        if ( ! $state || (string) $state['run_id'] !== (string) $ctx['run'] ) {
            return;
        }
        if ( $this->lease->owns( $ctx['handle'], time() ) ) {
            $settled = $this->settle_inflight( $state, 'fatal: ' . substr( (string) $err['message'], 0, 120 ), $ctx['handle'] );
            if ( $settled ) {
                $state = $settled;
                $state['seq']++;
                $state['updated_at'] = time();
                if ( $this->store->cas( self::STATE_KEY, $raw, BitBot_Gather_Guard::next_raw( $raw, $state ) ) ) {
                    $this->schedule_persisted( $state, $state['due_at'] );
                }
            }
            $this->lease->release( $ctx['handle'] );
        }
    }

    // -------------------------------------------------------------------- status

    public function status() {
        $raw = null;
        $s   = $this->read_state( $raw );
        if ( $s ) {
            return $this->status_from( $s );
        }
        // No render pass yet: show pass 1 while it runs (batch progress).
        $prog = get_option( BitBot::SYNC_PROGRESS_OPTION );
        if ( self::enabled() && is_array( $prog ) && isset( $prog['state'] ) && 'running' === $prog['state'] ) {
            return array(
                'phase'        => 'pass1',
                'state'        => 'running',
                'enabled'      => true,
                'batchIndex'   => isset( $prog['cursor'] ) ? (int) $prog['cursor'] : 0,
                'totalBatches' => isset( $prog['total_batches'] ) ? (int) $prog['total_batches'] : 0,
                'done'         => isset( $prog['done'] ) ? (int) $prog['done'] : 0,
                'total'        => isset( $prog['total'] ) ? (int) $prog['total'] : 0,
                'updatedAt'    => isset( $prog['updated_at'] ) ? (int) $prog['updated_at'] : 0,
            );
        }
        return array( 'state' => 'off', 'enabled' => self::enabled() );
    }

    private function status_from( array $s ) {
        $now   = time();
        $p     = $s['pacer'];
        $label = $s['state'];
        if ( 'running' === $label ) {
            $br = BitBot_Gather_Pacer::breaker_state( $p, $now );
            if ( 'closed' !== $br ) {
                $label = 'paused';
            } elseif ( $now > (int) $s['due_at'] + self::STALLED_AFTER ) {
                $label = 'stalled';
            }
        }
        $skipped = isset( $s['skipped_current'] ) ? (int) $s['skipped_current'] : 0;
        $reason = $s['pause_reason'];
        if ( 'running' === $s['state'] && 'paused' === $label ) {
            $reason = 'Slowing down: the host struggled with ' . BitBot_Gather_Pacer::BREAKER_FAILURES . ' renders in a row. It will try one page again in ' . ceil( BitBot_Gather_Pacer::breaker_wait_left( $p, $now ) / 60 ) . ' minutes.';
        }
        return array(
            'phase'          => 'render',
            'state'          => $label,
            'pauseReason'    => $reason,
            'enabled'        => self::enabled(),
            'runId'          => $s['run_id'],
            // Pages already current count as done, so a repeat sync reads N of N.
            'total'          => (int) $s['total'] + $skipped,
            'done'           => (int) $s['done'] + $skipped,
            'skippedCurrent' => $skipped,
            'failed'         => (int) $s['failed'],
            'imagesSent'     => (int) $s['images_sent'],
            'imagesPending'  => count( $s['want_images'] ),
            'images'         => array(
                'sent'    => (int) $s['images_sent'],
                'pending' => count( $s['want_images'] ),
                'dropped' => (int) ( isset( $s['images_dropped'] ) ? $s['images_dropped'] : 0 ),
                'tries'   => self::IMAGE_TRIES,
            ),
            'imageLog'       => isset( $s['image_log'] ) ? array_slice( (array) $s['image_log'], -BitBot_Gather_Images::LOG_CAP ) : array(),
            'renderTypes'    => $s['render_types'],
            'concurrency'    => (int) $p['concurrency'],
            'concurrencyCap' => isset( $p['cap'] ) ? (int) $p['cap'] : 1,
            'gapSeconds'     => (int) $p['gap'],
            'baselineMs'     => null === $p['baseline'] ? null : (int) round( $p['baseline'] ),
            'breaker'        => BitBot_Gather_Pacer::breaker_state( $p, $now ),
            'via'            => $s['via'],
            'scheduleFailed' => ! empty( $s['schedule_failed'] ),
            'nextDueAt'      => (int) $s['due_at'],
            'lastTickAt'     => (int) $s['last_tick_at'],
            'updatedAt'      => (int) $s['updated_at'],
            'canContinue'    => in_array( $label, array( 'stalled', 'paused' ), true ),
        );
    }
}
