<?php
/**
 * Inside-WordPress gathering ("gather v2"): the pure decision logic.
 *
 * Nothing in this file touches WordPress. Time is always passed in, state is
 * always passed in and a NEW state array is returned, so every rule here can be
 * tested without a WordPress install (tests/gather-v2/harness.php).
 *
 *  - BitBot_Gather_Pacer : adaptive concurrency and gap, baseline, breaker.
 *  - BitBot_Gather_Lease : one-run-at-a-time lease over an atomic key store.
 *  - BitBot_Gather_Guard : stale run / cursor event rejection.
 *
 * @package BitBot
 * @since   2.2.17
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Minimal atomic key store the lease and the state commit need. The WordPress
 * implementation (class-gather-v2.php) maps these onto single SQL statements
 * against the options table. Values are always strings.
 */
interface BitBot_Gather_Store {
    /** Insert only when the key does not exist. True when this call created it. */
    public function add( $key, $value );
    /** Raw stored value, or null when absent. Never served from a cache. */
    public function get( $key );
    /** Replace $old with $new only when the stored value still equals $old. */
    public function cas( $key, $old, $new );
    /** Delete only when the stored value still equals $value. */
    public function delete_if( $key, $value );
    /** Delete unconditionally. */
    public function delete( $key );
    /** Unconditional write (data keys fenced by the caller, see BitBot_Gather_Guard::fenced_put). */
    public function put( $key, $value );
}

/**
 * Adaptive pacing. The signal is the render latency against THIS host's own
 * baseline (median of its first 5 renders); load averages and core counts are
 * not trusted (a shared host reported no core count while its load hit 15.5).
 */
class BitBot_Gather_Pacer {

    const CONCURRENCY_MIN   = 1;
    const CONCURRENCY_MAX   = 4;      // hard ceiling; the backend's renderConcurrencyMax is the working cap
    const CONCURRENCY_START = 1;      // always start at one render at a time
    const GAP_MIN           = 1;      // a nonzero gap between waves, always
    const GAP_MAX           = 60;
    const GAP_START         = 2;
    const BASELINE_COUNT    = 5;
    const WINDOW_COUNT      = 5;
    const BACKOFF_RATIO     = 2.0;
    const SPEEDUP_RATIO     = 1.2;
    const SPEEDUP_STREAK    = 10;
    const BREAKER_FAILURES  = 5;
    const BREAKER_WAIT      = 900;   // 15 minutes
    const PROBE_STALE       = 180;   // a probe that never reported back is forgotten after this
    const MIN_BASELINE_MS   = 50;    // a floor so a 5 ms noise spike cannot read as "slow"

    /** Result kinds recorded by record(). */
    const OK        = 'ok';
    const HOST_FAIL = 'host_fail';   // 5xx, 429, timeout: the host is struggling
    const PAGE_FAIL = 'page_fail';   // 4xx, not HTML, TLS/DNS/refused: this page failed, the host is fine

    public static function initial() {
        return array(
            'concurrency'      => self::CONCURRENCY_START,
            'cap'              => self::CONCURRENCY_START,   // renderConcurrencyMax from the backend (default 1)
            'gap'              => self::GAP_START,
            'baseline_samples' => array(),
            'baseline'         => null,
            'window'           => array(),
            'ok_streak'        => 0,
            'fail_streak'      => 0,
            'breaker_open_at'  => 0,    // 0 = closed
            'probe_at'         => 0,    // when a half-open probe was handed out
            'backoffs'         => 0,
            'speedups'         => 0,
            'breaker_trips'    => 0,
        );
    }

    /** Median of a list of numbers; null for an empty list. */
    public static function median( array $values ) {
        $n = count( $values );
        if ( 0 === $n ) {
            return null;
        }
        $v = array_values( $values );
        sort( $v );
        $mid = intdiv( $n, 2 );
        return ( 1 === $n % 2 ) ? $v[ $mid ] : ( $v[ $mid - 1 ] + $v[ $mid ] ) / 2;
    }

    /** 'closed' | 'open' | 'half_open' at time $now. */
    public static function breaker_state( array $p, $now ) {
        if ( empty( $p['breaker_open_at'] ) ) {
            return 'closed';
        }
        return ( $now >= $p['breaker_open_at'] + self::BREAKER_WAIT ) ? 'half_open' : 'open';
    }

    /** Seconds until the breaker lets a probe through (0 when not open). */
    public static function breaker_wait_left( array $p, $now ) {
        if ( empty( $p['breaker_open_at'] ) ) {
            return 0;
        }
        return max( 0, $p['breaker_open_at'] + self::BREAKER_WAIT - $now );
    }

    /**
     * How many renders to launch in the next wave.
     * 0 = do not run; 1 while probing; otherwise the current concurrency.
     */
    public static function wave_size( array $p, $now ) {
        $b = self::breaker_state( $p, $now );
        if ( 'open' === $b ) {
            return 0;
        }
        if ( 'half_open' === $b ) {
            return self::probe_in_flight( $p, $now ) ? 0 : 1;
        }
        return (int) $p['concurrency'];
    }

    public static function probe_in_flight( array $p, $now ) {
        return ! empty( $p['probe_at'] ) && ( $now - $p['probe_at'] ) < self::PROBE_STALE;
    }

    /** Mark that a half-open probe has been handed out. */
    public static function mark_probe( array $p, $now ) {
        if ( 'half_open' === self::breaker_state( $p, $now ) ) {
            $p['probe_at'] = $now;
        }
        return $p;
    }

    /**
     * May a tick do work now?
     *
     * @param array $p      Pacer state.
     * @param int   $now    Unix time.
     * @param int   $due_at The earliest time the next tick may run (cooldown end).
     * @param bool  $manual The tick came from a manual route, not a scheduled event.
     * @param bool  $probe  A manual tick explicitly asking to be a probe.
     * @return array { ok: bool, reason: string }
     */
    public static function may_run( array $p, $now, $due_at, $manual = false, $probe = false ) {
        $b = self::breaker_state( $p, $now );
        if ( 'open' === $b && ! ( $manual && $probe ) ) {
            return array( 'ok' => false, 'reason' => 'breaker_open' );
        }
        if ( 'half_open' === $b && self::probe_in_flight( $p, $now ) && ! ( $manual && $probe ) ) {
            return array( 'ok' => false, 'reason' => 'probe_in_flight' );
        }
        // The cooldown binds scheduled AND manual ticks. Only an explicit probe skips it.
        if ( $now < $due_at && ! ( $manual && $probe ) ) {
            return array( 'ok' => false, 'reason' => 'cooldown' );
        }
        return array( 'ok' => true, 'reason' => '' );
    }

    /** Halve concurrency, double the gap. */
    public static function back_off( array $p ) {
        $p['concurrency'] = max( self::CONCURRENCY_MIN, intdiv( (int) $p['concurrency'], 2 ) );
        $p['gap']         = min( self::GAP_MAX, max( 1, (int) $p['gap'] ) * 2 );
        $p['ok_streak']   = 0;
        $p['window']      = array();
        $p['backoffs']++;
        return $p;
    }

    /** The backend's cap on concurrency (renderConcurrencyMax). Absent means 1. */
    public static function set_cap( array $p, $cap ) {
        $cap          = max( self::CONCURRENCY_MIN, min( self::CONCURRENCY_MAX, (int) $cap ) );
        $p['cap']     = $cap;
        $p['concurrency'] = min( (int) $p['concurrency'], $cap );
        return $p;
    }

    /** The backend asked us to slow down: one render at a time. */
    public static function slow_down( array $p ) {
        $p['concurrency'] = self::CONCURRENCY_MIN;
        $p['ok_streak']   = 0;
        return $p;
    }

    /**
     * When the next event may run: after the gap, and no sooner than the
     * backend's renderPauseSeconds when it asked for one.
     */
    public static function next_due( array $p, $now, $pause_seconds = 0 ) {
        return $now + max( self::GAP_MIN, (int) $p['gap'], (int) $pause_seconds );
    }

    /** Concurrency + 1 (never above the backend's cap), gap halved (never below the minimum). */
    public static function speed_up( array $p ) {
        $cap              = isset( $p['cap'] ) ? (int) $p['cap'] : self::CONCURRENCY_MIN;
        $p['concurrency'] = min( $cap, self::CONCURRENCY_MAX, (int) $p['concurrency'] + 1 );
        $p['gap']         = max( self::GAP_MIN, intdiv( (int) $p['gap'], 2 ) );
        $p['ok_streak']   = 0;
        $p['speedups']++;
        return $p;
    }

    /**
     * Fold ONE render result into the pacer.
     *
     * @param array $p   Pacer state.
     * @param array $r   { kind: ok|host_fail|page_fail, ms: int }
     * @param int   $now Unix time.
     * @return array New pacer state.
     */
    public static function record( array $p, array $r, $now ) {
        $kind = isset( $r['kind'] ) ? $r['kind'] : self::OK;
        $ms   = isset( $r['ms'] ) ? max( 0, (int) $r['ms'] ) : 0;

        if ( self::OK !== $kind ) {
            $p['fail_streak']++;
            $p['ok_streak'] = 0;
            if ( self::HOST_FAIL === $kind ) {
                $p = self::back_off( $p );
            }
            if ( $p['fail_streak'] >= self::BREAKER_FAILURES || 'half_open' === self::breaker_state( $p, $now ) ) {
                // A failed probe re-opens for a full wait; five in a row trips it.
                if ( empty( $p['breaker_open_at'] ) || 'half_open' === self::breaker_state( $p, $now ) ) {
                    $p['breaker_trips']++;
                }
                $p['breaker_open_at'] = $now;
                $p['probe_at']        = 0;
            }
            return $p;
        }

        // A success. If this was the probe, the breaker closes cautiously.
        $was_half_open = 'half_open' === self::breaker_state( $p, $now );
        $p['fail_streak'] = 0;
        if ( $was_half_open ) {
            $p['breaker_open_at'] = 0;
            $p['probe_at']        = 0;
            $p['concurrency']     = self::CONCURRENCY_MIN;
            $p['gap']             = max( (int) $p['gap'], self::GAP_START * 2 );
            $p['window']          = array();
            $p['ok_streak']       = 0;
        }

        if ( null === $p['baseline'] ) {
            // Only renders made at concurrency 1 are an unloaded baseline.
            if ( (int) $p['concurrency'] > self::CONCURRENCY_MIN ) {
                return $p;
            }
            $p['baseline_samples'][] = $ms;
            if ( count( $p['baseline_samples'] ) >= self::BASELINE_COUNT ) {
                $p['baseline'] = max( self::MIN_BASELINE_MS, (float) self::median( $p['baseline_samples'] ) );
            }
            return $p;
        }

        $p['window'][] = $ms;
        if ( count( $p['window'] ) > self::WINDOW_COUNT ) {
            $p['window'] = array_slice( $p['window'], -self::WINDOW_COUNT );
        }
        if ( count( $p['window'] ) >= self::WINDOW_COUNT && self::median( $p['window'] ) > self::BACKOFF_RATIO * $p['baseline'] ) {
            return self::back_off( $p );
        }
        if ( $ms <= self::SPEEDUP_RATIO * $p['baseline'] ) {
            $p['ok_streak']++;
            if ( $p['ok_streak'] >= self::SPEEDUP_STREAK ) {
                $p = self::speed_up( $p );
            }
        } else {
            $p['ok_streak'] = 0;
        }
        return $p;
    }

    /** Fold a list of results in order. */
    public static function record_all( array $p, array $results, $now ) {
        foreach ( $results as $r ) {
            $p = self::record( $p, $r, $now );
        }
        return $p;
    }

    /** Classify one HTTP outcome. */
    public static function classify( $status, $transport_error, $is_html, $timed_out = false ) {
        if ( $transport_error ) {
            // Only a timeout says the host is struggling. A TLS, DNS or refused
            // connection on one page (a blocked /checkout/) is that page's failure.
            return $timed_out ? self::HOST_FAIL : self::PAGE_FAIL;
        }
        $status = (int) $status;
        if ( $status >= 500 || 429 === $status ) {
            return self::HOST_FAIL;
        }
        if ( $status >= 200 && $status < 300 && $is_html ) {
            return self::OK;
        }
        return self::PAGE_FAIL;
    }
}

/**
 * One run at a time. The lease is a JSON value {run, token, expires} in one key:
 * created with an atomic insert, stolen only when expired (compare-and-set on the
 * exact expired value), released only by its owner (delete-if-equal).
 */
class BitBot_Gather_Lease {

    /** @var BitBot_Gather_Store */
    private $store;
    private $key;

    public function __construct( BitBot_Gather_Store $store, $key ) {
        $this->store = $store;
        $this->key   = $key;
    }

    /**
     * @return array|null The lease handle { raw, token, run, expires }, or null when someone else holds a live lease.
     */
    public function acquire( $run_id, $ttl, $now, $token ) {
        $lease = array( 'run' => (string) $run_id, 'token' => (string) $token, 'expires' => $now + (int) $ttl, 'rev' => 0 );
        $raw   = json_encode( $lease );
        if ( $this->store->add( $this->key, $raw ) ) {
            return $lease + array( 'raw' => $raw );
        }
        $cur_raw = $this->store->get( $this->key );
        if ( null === $cur_raw ) {
            // Released between our add and get: one more try.
            return $this->store->add( $this->key, $raw ) ? $lease + array( 'raw' => $raw ) : null;
        }
        $cur = json_decode( $cur_raw, true );
        if ( is_array( $cur ) && isset( $cur['expires'] ) && (int) $cur['expires'] > $now ) {
            return null;     // live lease held by someone else
        }
        // Expired (or unreadable): steal it, but only if nobody else did first.
        return $this->store->cas( $this->key, $cur_raw, $raw ) ? $lease + array( 'raw' => $raw ) : null;
    }

    /** True when someone holds a live (unexpired) lease. Read-only: used to leave a busy tick's schedule alone. */
    public function held( $now ) {
        $raw = $this->store->get( $this->key );
        if ( null === $raw ) {
            return false;
        }
        $cur = json_decode( $raw, true );
        return is_array( $cur ) && isset( $cur['expires'] ) && (int) $cur['expires'] > $now;
    }

    /** True while the handle's exact lease is still stored and unexpired. */
    public function owns( array $lease, $now ) {
        if ( empty( $lease['raw'] ) || (int) $lease['expires'] <= $now ) {
            return false;
        }
        return $this->store->get( $this->key ) === $lease['raw'];
    }

    /** Push the expiry out. Returns the new handle, or null when the lease was lost. */
    public function renew( array $lease, $ttl, $now ) {
        if ( ! $this->owns( $lease, $now ) ) {
            return null;
        }
        // The revision makes every renewal a DIFFERENT value: MySQL reports an
        // UPDATE that changes nothing as 0 rows, which would read as a lost lease.
        $next = array( 'run' => $lease['run'], 'token' => $lease['token'], 'expires' => $now + (int) $ttl, 'rev' => (int) ( isset( $lease['rev'] ) ? $lease['rev'] : 0 ) + 1 );
        $raw  = json_encode( $next );
        return $this->store->cas( $this->key, $lease['raw'], $raw ) ? $next + array( 'raw' => $raw ) : null;
    }

    /** Release, compare-and-delete: an old holder cannot free a newer holder's lease. */
    public function release( array $lease ) {
        return ! empty( $lease['raw'] ) && $this->store->delete_if( $this->key, $lease['raw'] );
    }
}

/**
 * Stale event and stale writer rejection.
 */
class BitBot_Gather_Guard {

    /**
     * Should a scheduled event be processed?
     *
     * @param array|null $state         Decoded engine state, or null when none exists.
     * @param string     $event_run     Run id the event was scheduled with.
     * @param int        $event_seq     Tick sequence the event was scheduled with (-1 = watchdog).
     * @param string     $current_run   The site's current run id (SYNC_RUN_OPTION).
     * @return string '' when the event is current, else the reason it is stale.
     */
    public static function check_event( $state, $event_run, $event_seq, $current_run ) {
        if ( ! is_array( $state ) || empty( $state['run_id'] ) ) {
            return 'no_run';
        }
        if ( (string) $state['run_id'] !== (string) $event_run ) {
            return 'stale_run';
        }
        if ( (string) $current_run !== (string) $event_run ) {
            return 'superseded';
        }
        if ( 'running' !== $state['state'] ) {
            return 'not_running';
        }
        if ( -1 !== (int) $event_seq && (int) $event_seq !== (int) $state['seq'] ) {
            return 'stale_cursor';
        }
        return '';
    }

    /**
     * Commit a new state only if the stored state is still exactly the one this
     * worker read, the lease is still its own, and the run is still current.
     * A retired run (state deleted or replaced) therefore cannot be restored by
     * a tick that was in flight when the new run started.
     *
     * @return bool True when the new state was stored.
     */
    public static function commit( BitBot_Gather_Store $store, BitBot_Gather_Lease $lease, array $handle, $key, $old_raw, array $new_state, $now ) {
        if ( ! $lease->owns( $handle, $now ) ) {
            return false;
        }
        return $store->cas( $key, $old_raw, self::next_raw( $old_raw, $new_state ) );
    }

    /**
     * The JSON to store for $new_state: its revision is one past the stored one,
     * so a commit always CHANGES the value (see the lease note on MySQL).
     */
    public static function next_raw( $old_raw, array $new_state ) {
        $old = json_decode( (string) $old_raw, true );
        $new_state['rev'] = (int) ( is_array( $old ) && isset( $old['rev'] ) ? $old['rev'] : 0 ) + 1;
        return json_encode( $new_state );
    }

    /**
     * Write run data (queue, done list, candidates) only while this worker still
     * owns the lease and its run is still the site's current run. Keys are also
     * per run, so even a fence miss can never touch another run's data.
     *
     * @return bool True when written.
     */
    public static function fenced_put( BitBot_Gather_Store $store, BitBot_Gather_Lease $lease, array $handle, $current_run, $run_id, $key, $value, $now ) {
        if ( (string) $current_run !== (string) $run_id || ! $lease->owns( $handle, $now ) ) {
            return false;
        }
        $store->put( $key, $value );
        return true;
    }

    /**
     * Has the pass-1 cron chain lost its next event? WordPress's cron array is one
     * option that a long-running cron process rewrites from a stale in-memory copy,
     * so an event scheduled by another request can silently vanish. The chain is
     * alive only if either its next event or its watchdog is still scheduled.
     *
     * @param array|null $progress          The sync-progress option.
     * @param string     $current_run       SYNC_RUN_OPTION.
     * @param int        $now               Unix time.
     * @param bool       $has_cursor_event  An event for the stored cursor is scheduled.
     * @param bool       $has_watchdog      The watchdog (-1) event is scheduled.
     * @param int        $stale_after       Seconds without progress before we look.
     */
    public static function chain_needs_recovery( $progress, $current_run, $now, $has_cursor_event, $has_watchdog, $stale_after = 60 ) {
        if ( ! is_array( $progress ) || ! isset( $progress['state'], $progress['run_id'] ) || 'running' !== $progress['state'] ) {
            return false;
        }
        if ( (string) $progress['run_id'] !== (string) $current_run ) {
            return false;
        }
        if ( $now - (int) ( isset( $progress['updated_at'] ) ? $progress['updated_at'] : 0 ) <= $stale_after ) {
            return false;
        }
        return ! $has_cursor_event && ! $has_watchdog;
    }

    /**
     * Is a running render pass's tick dead? True when the run is running and its
     * lease is missing or has expired (the lease is renewed after every wave).
     *
     * @param string   $state_name   State of the stored render pass.
     * @param int|null $lease_expires Expiry of the stored lease, null when there is none.
     * @param int      $now          Unix time.
     */
    public static function run_needs_recovery( $state_name, $lease_expires, $now ) {
        if ( 'running' !== $state_name ) {
            return false;
        }
        return null === $lease_expires || (int) $lease_expires <= (int) $now;
    }

    /**
     * What "continue" should do for the render pass.
     *
     * @param string|null $engine_run  Run id of the stored render-pass state (null = none).
     * @param string      $current_run SYNC_RUN_OPTION.
     * @param string|null $progress_run  Run id in the sync-progress option.
     * @param string|null $progress_state Its state.
     * @return string clear_and_restart | clear_and_wait | clear | restart | wait | none | continue
     */
    public static function continue_action( $engine_run, $current_run, $progress_run, $progress_state ) {
        $stale = ( null !== $engine_run && (string) $engine_run !== (string) $current_run );
        if ( null !== $engine_run && ! $stale ) {
            return 'continue';
        }
        $p_ok = ( null !== $progress_run && (string) $progress_run === (string) $current_run );
        if ( $p_ok && 'done' === $progress_state ) {
            return $stale ? 'clear_and_restart' : 'restart';
        }
        if ( $p_ok && 'running' === $progress_state ) {
            return $stale ? 'clear_and_wait' : 'wait';
        }
        return $stale ? 'clear' : 'none';
    }

    /** The backend's switch: only an explicit true is "on"; absent means off. */
    public static function gate_is_on( $data ) {
        return is_array( $data ) && array_key_exists( 'gatherV2', $data ) && true === $data['gatherV2'];
    }

    /** Option key for run data: one key per run and kind. */
    public static function run_key( $base, $run_id ) {
        return $base . '_' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $run_id );
    }
}

/**
 * Which pages to render, in what order. Pure.
 */
class BitBot_Gather_Queue {

    /** Products first, then pages, then everything else. */
    public static function rank( $post_type ) {
        if ( 'product' === $post_type ) {
            return 0;
        }
        return 'page' === $post_type ? 1 : 2;
    }

    /**
     * @param array $rows        [ [ id, post_type, modified_unix ], ... ]
     * @param array $types       Post types the backend wants rendered.
     * @param array $sample_ids  Specific post ids the backend asked for as samples (first).
     * @param array $done        id => true for pages already rendered or given up on.
     * @param array $current     id => true for pages whose stored record already is a render of this post version (never queued, not even as samples).
     * @return int[] Post ids in render order.
     */
    public static function build( array $rows, array $types, array $sample_ids, array $done, array $current = array() ) {
        $done = $done + $current;
        $out  = array();
        $seen = array();
        foreach ( $sample_ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && empty( $done[ $id ] ) && empty( $seen[ $id ] ) ) {
                $out[]       = $id;
                $seen[ $id ] = true;
            }
        }
        $want = array_flip( array_map( 'strval', $types ) );
        $rest = array();
        foreach ( $rows as $r ) {
            $id = (int) $r[0];
            if ( isset( $want[ (string) $r[1] ] ) && empty( $done[ $id ] ) && empty( $seen[ $id ] ) ) {
                $rest[] = $r;
            }
        }
        usort( $rest, function ( $a, $b ) {
            $ra = self::rank( $a[1] );
            $rb = self::rank( $b[1] );
            if ( $ra !== $rb ) {
                return $ra - $rb;
            }
            return (int) $b[2] - (int) $a[2];     // recently modified first
        } );
        foreach ( $rest as $r ) {
            $out[] = (int) $r[0];
        }
        return $out;
    }

    /**
     * A new decision (renderTypes / samples) or a drained queue: work out what is
     * left to render. Pure.
     *
     * @param array $state { render_types, samples, done, failed, pos, total }
     * @param array $rows  Site inventory rows.
     * @param array $done  id => true for pages already handled.
     * @return array { state, queue }
     */
    public static function rebuild( array $state, array $rows, array $done, array $current = array() ) {
        $queue                    = self::build( $rows, $state['render_types'], $state['samples'], $done, $current );
        $state['skipped_current'] = self::skipped_count( $rows, $state['render_types'], array_keys( $current ) );
        $state['pos']   = 0;
        $state['total'] = $state['done'] + $state['failed'] + count( $queue );
        return array( 'state' => $state, 'queue' => $queue );
    }

    /**
     * A tick died with pages in flight. A dead worker is not necessarily a bad
     * page: each id gets ONE more go (it stays at the front, pos unchanged, and is
     * marked in state['retried']). An id whose tick died a second time is given up
     * ("tick died twice"): removed from the queue, counted failed. Pure.
     *
     * @param array  $state  Needs inflight, pos, failed, failed_ids; optional retried.
     * @param int[]  $queue  Queue (ids from pos on are still to do).
     * @param string $reason Why recorded on a first-time give up (no room to retry).
     * @param int    $cap    Bound for both failed_ids and retried.
     * @return array { state, queue, give_up (ids), retry (ids) }
     */
    public static function settle_dead( array $state, array $queue, $reason, $cap ) {
        $retried = isset( $state['retried'] ) && is_array( $state['retried'] ) ? $state['retried'] : array();
        $give    = array();
        $retry   = array();
        foreach ( $state['inflight'] as $id ) {
            $id = (int) $id;
            if ( isset( $retried[ $id ] ) ) {
                $why = 'tick died twice';
            } elseif ( count( $retried ) < $cap ) {
                $retried[ $id ] = 1;
                $retry[]        = $id;
                continue;
            } else {
                $why = $reason;
            }
            $give[] = $id;
            if ( count( $state['failed_ids'] ) < $cap ) {
                $state['failed_ids'][] = array( 'id' => $id, 'why' => $why );
            }
        }
        if ( $give ) {
            $drop  = array_flip( $give );
            $pos   = (int) $state['pos'];
            $keep  = array_slice( $queue, 0, $pos );
            foreach ( array_slice( $queue, $pos ) as $qid ) {
                if ( ! isset( $drop[ (int) $qid ] ) ) {
                    $keep[] = $qid;
                }
            }
            $queue = $keep;
        }
        $state['failed']  += count( $give );
        $state['retried']  = $retried;
        $state['inflight'] = array();
        return array( 'state' => $state, 'queue' => $queue, 'give_up' => $give, 'retry' => $retry );
    }

    /** How many inventory rows of the wanted types are already current (and so not queued). */
    public static function skipped_count( array $rows, array $types, array $current ) {
        $want = array_flip( array_map( 'strval', $types ) );
        $cur  = array_flip( array_map( 'intval', $current ) );
        $n    = 0;
        foreach ( $rows as $r ) {
            if ( isset( $want[ (string) $r[1] ] ) && isset( $cur[ (int) $r[0] ] ) ) {
                $n++;
            }
        }
        return $n;
    }

    /** Is the batch of $n pages starting at $pos the last one of the run? */
    public static function is_final_batch( $pos, $n, $queue_count, array $want_images, $outstanding = 0 ) {
        return ( (int) $pos + (int) $n >= (int) $queue_count ) && empty( $want_images ) && (int) $outstanding <= 0;
    }

    /** The run is finished only when nothing is left to render and no image is pending. */
    public static function is_finished( array $state, array $queue ) {
        return (int) $state['pos'] >= count( $queue ) && empty( $state['want_images'] ) && (int) ( isset( $state['images_outstanding'] ) ? $state['images_outstanding'] : 0 ) <= 0;
    }
}

/**
 * UploadPageImages answers per image. read / cached = done; stale / rejected =
 * drop; skipped (spend stop, reads off) = keep and retry on a later tick.
 */
class BitBot_Gather_Images {

    const MAX_TRIES = 3;
    const LOG_CAP   = 20;
    const UPLOADED_CAP = 500;   // per-run memory of uploaded urls (8-hex hashes)
    const DRY_ASKS     = 3;     // empty "any more wants?" calls that bring nothing before the phase ends
    const GAP       = 1;      // seconds between image ticks: cheap local reads, not host-protected renders

    /** Short stable key for a url in the uploaded set. */
    public static function key( $url ) {
        return substr( md5( (string) $url ), 0, 8 );
    }

    /** Add urls to the bounded uploaded list (newest last). */
    public static function remember( array $uploaded, array $urls ) {
        foreach ( $urls as $u ) {
            $k = self::key( $u );
            if ( ! in_array( $k, $uploaded, true ) ) {
                $uploaded[] = $k;
            }
        }
        return count( $uploaded ) > self::UPLOADED_CAP ? array_slice( $uploaded, -self::UPLOADED_CAP ) : $uploaded;
    }

    /** Work remaining for the image phase: pending images, or one "ask again" while the backend says more are outstanding. */
    public static function pending_units( $pending, $outstanding ) {
        return (int) $pending > 0 ? (int) $pending : ( (int) $outstanding > 0 ? 1 : 0 );
    }

    /**
     * Keep sending image batches inside this tick? Only while time is left in the
     * tick budget, images are pending, and the last batch made progress.
     */
    public static function may_continue( $elapsed, $budget, $pending, $progressed ) {
        return $pending > 0 && $progressed && $elapsed < $budget;
    }

    /**
     * When the next tick is due. Once every page is rendered and only images remain,
     * the render gap (host protection) no longer applies: come back in GAP seconds.
     * A backend pause request still wins.
     */
    public static function next_due( $now, $pacer_due, $queue_drained, $pending, $pause_seconds ) {
        if ( $queue_drained && $pending > 0 && (int) $pause_seconds <= 0 ) {
            return $now + self::GAP;
        }
        return $pacer_due;
    }

    /**
     * Append one entry to the bounded per-image log (newest last, at most LOG_CAP).
     *
     * @param array  $log    Existing log.
     * @param string $url    Image url (cut to 200 chars).
     * @param string $status Backend status (read/cached/skipped/stale/rejected) or a local reason
     *                       (not_an_image, too_large, download_failed, not_site_image, rpc_failed).
     * @param string $reason Optional detail (cut to 120 chars).
     * @param int    $at     Unix time.
     * @return array New log.
     */
    public static function log_push( array $log, $url, $status, $reason, $at ) {
        $e = array( 'url' => substr( (string) $url, 0, 200 ), 'status' => (string) $status, 'at' => (int) $at );
        if ( '' !== (string) $reason ) {
            $e['reason'] = substr( (string) $reason, 0, 120 );
        }
        $log[] = $e;
        return count( $log ) > self::LOG_CAP ? array_slice( $log, -self::LOG_CAP ) : $log;
    }

    /**
     * @param array $sent    [ { url, wpPostId, tries } ] images in the call.
     * @param mixed $results The response's per-image results: [ { url, status } ], or null when absent.
     * @return array { done: int, keep: array }
     */
    public static function settle( array $sent, $results ) {
        if ( ! is_array( $results ) ) {
            $st = array();
            foreach ( $sent as $w ) {
                $st[ $w['url'] ] = 'read';
            }
            return array( 'done' => count( $sent ), 'keep' => array(), 'dropped' => 0, 'statuses' => $st );     // an older backend that does not report per image
        }
        $by = array();
        foreach ( $results as $i => $r ) {
            if ( is_array( $r ) && isset( $r['url'], $r['status'] ) ) {
                $by[ (string) $r['url'] ] = (string) $r['status'];
            }
        }
        $done = 0;
        $keep = array();
        $dropped = 0;
        $statuses = array();
        foreach ( $sent as $i => $w ) {
            $st = isset( $by[ $w['url'] ] ) ? $by[ $w['url'] ] : ( isset( $results[ $i ]['status'] ) ? (string) $results[ $i ]['status'] : 'skipped' );
            $statuses[ $w['url'] ] = $st;
            if ( 'read' === $st || 'cached' === $st ) {
                $done++;
            } elseif ( 'stale' === $st || 'rejected' === $st ) {
                $dropped++;
            } else {
                $w['tries'] = (int) ( isset( $w['tries'] ) ? $w['tries'] : 0 ) + 1;
                if ( $w['tries'] < self::MAX_TRIES ) {
                    $keep[] = $w;
                } else {
                    $dropped++;
                }
            }
        }
        return array( 'done' => $done, 'keep' => $keep, 'dropped' => $dropped, 'statuses' => $statuses );
    }
}

/**
 * Site fingerprint: `<theme slug>-<theme version>|<sha1 of the sorted active plugin slugs>`.
 * The backend resets its render-type decisions when it changes.
 */
class BitBot_Gather_Fingerprint {

    /**
     * @param string   $theme_slug    Active stylesheet slug.
     * @param string   $theme_version Active theme version.
     * @param string[] $plugin_files  Active plugin files, e.g. "woocommerce/woocommerce.php", "hello.php".
     */
    public static function build( $theme_slug, $theme_version, array $plugin_files ) {
        $slugs = array();
        foreach ( $plugin_files as $f ) {
            $f = (string) $f;
            if ( '' === $f ) {
                continue;
            }
            $dir     = dirname( $f );
            $slugs[] = ( '.' === $dir || '' === $dir ) ? basename( $f, '.php' ) : $dir;
        }
        $slugs = array_values( array_unique( $slugs ) );
        sort( $slugs, SORT_STRING );
        return $theme_slug . '-' . $theme_version . '|' . sha1( implode( ',', $slugs ) );
    }
}

/**
 * Sample rounds. The backend tags each sampleRequests entry with a roundId; a
 * render only counts as a sample when it echoes that id as sampleRoundId.
 */
class BitBot_Gather_Rounds {

    /** id => roundId from one response's sampleRequests (entries without a roundId are skipped). */
    public static function from_response( $data ) {
        $map = array();
        if ( is_array( $data ) && isset( $data['sampleRequests'] ) && is_array( $data['sampleRequests'] ) ) {
            foreach ( $data['sampleRequests'] as $req ) {
                if ( is_array( $req ) && ! empty( $req['roundId'] ) && is_string( $req['roundId'] ) && isset( $req['wpPostIds'] ) ) {
                    foreach ( (array) $req['wpPostIds'] as $id ) {
                        if ( (int) $id > 0 ) {
                            $map[ (int) $id ] = $req['roundId'];
                        }
                    }
                }
            }
        }
        return $map;
    }

    /** Merge; the newest round for an id wins. */
    public static function merge( array $old, array $new ) {
        foreach ( $new as $id => $round ) {
            $old[ (int) $id ] = $round;
        }
        return $old;
    }

    /** The round to echo on a render item for $id, or null when it was not a sample. */
    public static function for_id( array $map, $id ) {
        return isset( $map[ (int) $id ] ) ? $map[ (int) $id ] : null;
    }

    /** Back into sampleRequests shape, grouped by round. */
    public static function to_requests( array $map ) {
        $by = array();
        foreach ( $map as $id => $round ) {
            $by[ $round ][] = (int) $id;
        }
        $out = array();
        foreach ( $by as $round => $ids ) {
            $out[] = array( 'postType' => '', 'roundId' => (string) $round, 'wpPostIds' => $ids );
        }
        return $out;
    }
}

/**
 * Where and how to render a page of THIS site. Pure.
 */
class BitBot_Gather_Routes {

    /**
     * @param string $home_url home_url('/').
     * @return array { host_header, host, loopback_http, loopback_https, port }
     *   host_header carries the port when the site has one (Host: localhost:8080), and
     *   the 127.0.0.1 attempts use that same port.
     */
    public static function plan( $home_url ) {
        $p    = wp_parse_url( $home_url );
        $host = isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
        $port = isset( $p['port'] ) ? (int) $p['port'] : 0;
        $suffix = $port ? ':' . $port : '';
        return array(
            'host'           => $host,
            'port'           => $port,
            'host_header'    => $host . $suffix,
            'loopback_http'  => 'http://127.0.0.1' . $suffix,
            'loopback_https' => 'https://127.0.0.1' . $suffix,
        );
    }

    /** Host without a leading www., lowercased, no port. */
    public static function bare_host( $host ) {
        $h = strtolower( (string) $host );
        return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
    }

    /**
     * Should a 301/302/307/308 be followed (once)? Only to the same host, with or
     * without www. and with or without a port.
     *
     * @param string $location  The Location header (absolute or relative).
     * @param string $site_host The site's host (no port).
     * @return array|null { path, host_header } to request next, or null.
     */
    public static function follow( $location, $site_host ) {
        $location = trim( (string) $location );
        if ( '' === $location ) {
            return null;
        }
        $p = wp_parse_url( $location );
        if ( false === $p ) {
            return null;
        }
        if ( isset( $p['scheme'] ) && ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
            return null;
        }
        if ( isset( $p['host'] ) ) {
            if ( self::bare_host( $p['host'] ) !== self::bare_host( $site_host ) ) {
                return null;
            }
            $host_header = strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
        } elseif ( isset( $location[0] ) && '/' === $location[0] && ( ! isset( $location[1] ) || '/' !== $location[1] ) ) {
            $host_header = null;     // relative: keep the Host we used
        } else {
            return null;
        }
        $path = isset( $p['path'] ) ? $p['path'] : '/';
        if ( isset( $p['query'] ) && '' !== $p['query'] ) {
            $path .= '?' . $p['query'];
        }
        return array( 'path' => $path, 'host_header' => $host_header );
    }
}
