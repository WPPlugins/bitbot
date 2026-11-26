<?php
/**
 * Text edits inside Elementor pages, through Elementor's own save lifecycle.
 *
 * Until 2.1.272 a page-builder body was refused outright. That refusal was
 * honest and it is still the answer for everything this file does not cover —
 * but Elementor is on the order of ten million installs, and "I cannot touch
 * your page" means the agent can write posts and SEO and nothing else on a
 * large share of real sites.
 *
 * Scope is deliberately small: literal text in tested core widgets, addressed
 * by find-and-replace, on a document the caller named. NO structure, ids,
 * layout, styles, dynamic tags, global widgets, templates, repeaters or
 * third-party widgets. Everything outside that is refused BY NAME rather than
 * silently skipped, because "not found in a field I can edit" and "not on the
 * page" are different answers and only one of them is about the page.
 *
 * THIS BOUNDARY IS THE FEATURE, NOT A STAGE TO GROW OUT OF.
 *
 * Owner directive, 2026-09-28: "for anything that is not our theme we want to
 * keep it minimal capability." On our own theme there are declarations, slots
 * and a revision model, so rich editing is checkable. Here we are guessing at
 * someone else's internals, where a wrong write corrupts a whole page rather
 * than a paragraph. Depth buys a little reach and a great deal of risk.
 *
 * So: no undo of our own (WordPress revisions and Elementor's own History
 * already give the owner a way back — a save through `$document->save()` leaves
 * a normal WP revision, verified on the sandbox), no control-shape
 * introspection, no widening the widget list to whatever happens to hold a
 * string. A request for more depth here gets this paragraph, not a patch.
 *
 * MINIMAL CAPABILITY IS NOT MINIMAL HONESTY. Everything below still has to be
 * true: a save we cannot confirm is `unverified`, never `done`, and text we
 * cannot reach is reported as present-and-unreachable rather than absent.
 *
 * TWO RULES THAT ARE NOT NEGOTIABLE HERE.
 *
 * 1. Never write `_elementor_data` directly. Elementor owns the save: it
 *    regenerates the per-post CSS file the front end actually loads, and a
 *    postmeta write that skips it leaves the page LOOKING unchanged while the
 *    data says otherwise — a silent success, which is the failure mode this
 *    whole codebase is built against.
 * 2. A save that returns cleanly is not evidence. The caller verifies the
 *    persisted value AND the rendered page, and anything short of both is
 *    `unverified`, never `done`.
 *
 * @package BitBot
 * @since 2.1.273
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Elementor_Adapter {

    /**
     * Widget types and the literal-text fields we will edit inside them.
     *
     * An allowlist, not a denylist: an unknown widget is refused, so a new or
     * third-party widget cannot quietly become editable because its settings
     * happen to contain a string. Each entry is a widget we have actually
     * driven end to end.
     */
    /**
     * The Elementor versions these two widget pairs were actually driven
     * against, end to end, on a real site. Not a guess at compatibility: the
     * lower bound is where it was proven and the upper bound is the next major
     * whose data shape nobody here has looked at.
     */
    const TESTED_MIN   = '3.0.0';
    const TESTED_BELOW = '5.0.0';

    const SUPPORTED = array(
        'heading'     => array( 'title' ),
        'text-editor' => array( 'editor' ),
    );

    /**
     * Is Elementor here, and new enough to expose the document API we use?
     *
     * Checked per call rather than assumed: Elementor can be deactivated
     * between the read that found the page and the write that changes it, and
     * its internals do shift between majors.
     *
     * @return true|WP_Error
     */
    public static function available() {
        if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
            return new WP_Error( 'bitbot_elementor_absent', 'Elementor is not active on this site, so its pages cannot be edited through it.' );
        }
        $plugin = \Elementor\Plugin::$instance;
        if ( ! $plugin || ! isset( $plugin->documents ) || ! is_object( $plugin->documents ) ) {
            return new WP_Error( 'bitbot_elementor_api_missing', 'This Elementor build does not expose the documents API this edit needs. Nothing was changed.' );
        }
        // VERSION, NOT JUST SHAPE. Codex: "runtime availability currently checks
        // the documents object, not version compatibility. Do not imply any
        // Elementor version exposing that object is verified."
        //
        // The object has existed across majors whose internals we have not
        // tested, and "it responds to the call" is not "it behaves the way the
        // two supported widgets were exercised". Outside the tested range this
        // fails CLOSED and says so, rather than guessing at a tree it has never
        // seen — the whole point of a bounded compatibility surface is that its
        // bounds are checked.
        $version = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
        if ( '' === $version ) {
            return new WP_Error( 'bitbot_elementor_version_unknown', 'This Elementor build does not report its version, so it cannot be matched against the versions these edits were tested on. Nothing was changed.' );
        }
        if ( version_compare( $version, self::TESTED_MIN, '<' ) || version_compare( $version, self::TESTED_BELOW, '>=' ) ) {
            return new WP_Error(
                'bitbot_elementor_version_untested',
                sprintf(
                    'Elementor %s is outside the range these text edits were tested against (%s up to but not including %s). Nothing was changed — the widget data shape is not guaranteed to match, and a wrong write here corrupts a whole page rather than a paragraph. Tell the owner to make the change in Elementor.',
                    $version,
                    self::TESTED_MIN,
                    self::TESTED_BELOW
                )
            );
        }
        return true;
    }

    /**
     * A revision for the document's content, so a write can prove it is acting
     * on what it read. Elementor has no revision id of its own that survives a
     * round trip, so this hashes the data itself.
     *
     * @param array $elements
     * @return string
     */
    public static function revision( $elements ) {
        return hash( 'sha256', wp_json_encode( $elements ) );
    }

    /**
     * Replace literal text inside one Elementor document.
     *
     * @param int    $post_id
     * @param string $find
     * @param string $replace
     * @param bool   $replace_all
     * @return array|WP_Error { replaced:int, revisionBefore:string, revisionAfter:string, widgets:array }
     */
    public static function replace_text( $post_id, $find, $replace, $replace_all = false ) {
        $ready = self::available();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        if ( '' === (string) $find ) {
            return new WP_Error( 'bitbot_bad_text_edit', 'replace_text needs a non-empty find.' );
        }

        $document = \Elementor\Plugin::$instance->documents->get( (int) $post_id );
        if ( ! $document ) {
            return new WP_Error( 'bitbot_elementor_no_document', 'Post ' . (int) $post_id . ' has no Elementor document. Nothing was changed.' );
        }
        if ( ! $document->is_editable_by_current_user() ) {
            // The caller is responsible for establishing WHO is editing before
            // reaching this; an empty user context here is a wiring bug on our
            // side, and the refusal says so rather than implying the owner has
            // locked something. The first live attempt reported exactly that
            // invented cause back to them.
            return new WP_Error(
                'bitbot_elementor_forbidden',
                'Elementor refused this document to the current WordPress user (id ' . get_current_user_id() . '). This is a permissions problem on our side, not a setting on the page. Nothing was changed.'
            );
        }

        $elements = $document->get_elements_data();
        if ( ! is_array( $elements ) || ! $elements ) {
            return new WP_Error( 'bitbot_elementor_empty', 'This Elementor document has no element data to edit. Nothing was changed.' );
        }
        $revision_before = self::revision( $elements );

        // WHERE THE TEXT ACTUALLY IS, before changing anything. Counting first
        // means an ambiguous or unreachable match is refused with the page
        // untouched, rather than discovered halfway through a tree rewrite.
        $scan = self::scan( $elements, (string) $find );
        if ( 0 === $scan['supported'] ) {
            // NOT THE SAME AS ABSENT. If the text is on the page but sits in a
            // widget we do not edit, saying "0 matches" would send the agent off
            // to look somewhere else for something that is right here.
            if ( $scan['unsupported'] > 0 ) {
                return new WP_Error(
                    'bitbot_elementor_unsupported_widget',
                    sprintf(
                        'The text IS on post %d, %d time(s), but not in a field this can edit safely (%s) — an unsupported widget, a style value, or a field whose content comes from a dynamic tag rather than the literal you can see. Nothing was changed. Supported for text edits: %s. Tell the owner exactly which text to change and where, and let them edit it in Elementor.',
                        (int) $post_id,
                        (int) $scan['unsupported'],
                        implode( ', ', array_unique( $scan['unsupportedTypes'] ) ),
                        implode( ', ', array_keys( self::SUPPORTED ) )
                    )
                );
            }
            return new WP_Error( 'bitbot_text_not_found', self::not_found_message( $post_id, $elements ) );
        }
        if ( $scan['supported'] > 1 && ! $replace_all ) {
            return new WP_Error(
                'bitbot_text_ambiguous',
                sprintf(
                    'The find text appears %d times in post %d\'s Elementor content — nothing was changed. Add surrounding context so it matches exactly one place, or pass replaceAll:true to change all %d deliberately.',
                    (int) $scan['supported'],
                    (int) $post_id,
                    (int) $scan['supported']
                )
            );
        }

        $touched = array();
        $new     = self::apply( $elements, (string) $find, (string) $replace, (bool) $replace_all, $touched );

        // RE-READ IMMEDIATELY BEFORE SAVING. Between the read above and here the
        // owner may have saved in the Elementor editor; writing our tree over
        // theirs would discard their work with no trace. Refuse instead.
        $current = \Elementor\Plugin::$instance->documents->get( (int) $post_id, false );
        $fresh   = $current ? $current->get_elements_data() : null;
        if ( ! is_array( $fresh ) || self::revision( $fresh ) !== $revision_before ) {
            return new WP_Error(
                'bitbot_elementor_stale',
                'This Elementor document changed while the edit was being prepared (someone saved it in the editor). Nothing was changed. Read it again and retry.'
            );
        }

        // THROUGH ELEMENTOR, so its CSS regeneration and cache invalidation run.
        // A direct update_post_meta here would persist and leave the front end
        // rendering the old text from a stale generated stylesheet.
        $saved = $document->save( array( 'elements' => $new ) );
        if ( ! $saved ) {
            return new WP_Error( 'bitbot_elementor_save_failed', 'Elementor refused to save the edited document. Nothing is confirmed changed; read it back before reporting anything.' );
        }

        // VERIFY THE PERSISTED VALUE. `save()` returning true is Elementor
        // saying it accepted the call, not that the bytes are on disk.
        $after = \Elementor\Plugin::$instance->documents->get( (int) $post_id, false );
        $after_elements = $after ? $after->get_elements_data() : null;
        if ( ! is_array( $after_elements ) ) {
            return new WP_Error( 'bitbot_elementor_unverified', 'The document was saved but could not be read back, so the change is NOT confirmed.' );
        }
        // COMPARE THE FIELD TO WHAT IT SHOULD NOW SAY.
        //
        // This counted how many times the OLD text still appeared, which is
        // wrong in both directions and Codex caught both:
        //
        //   - a replacement that CONTAINS its find text is ordinary — expanding
        //     a phrase rather than swapping it — and left the old string in
        //     place, so a correct edit was reported unverified. "Six places" to
        //     "Six places per session" is the obvious case.
        //   - a save that MANGLED the field also removes the old text, and the
        //     count read that as success.
        //
        // The only thing that proves the intended change landed is the field
        // reading what we meant it to read, so that is what is checked.
        $verified = array();
        foreach ( $touched as $t ) {
            $actual = self::field_value( $after_elements, $t['id'], $t['field'] );
            if ( null === $actual ) {
                return new WP_Error(
                    'bitbot_elementor_unverified',
                    sprintf( 'The document was saved but widget %s no longer carries a %s field, so the change is NOT confirmed.', (string) $t['id'], (string) $t['field'] )
                );
            }
            if ( $actual !== $t['expected'] ) {
                return new WP_Error(
                    'bitbot_elementor_unverified',
                    sprintf(
                        'The document was saved but %s.%s does not read what was written (expected %d chars, found %d), so the change is NOT confirmed. Elementor may have altered it on save.',
                        (string) $t['id'],
                        (string) $t['field'],
                        strlen( (string) $t['expected'] ),
                        strlen( (string) $actual )
                    )
                );
            }
            $verified[] = $t['id'] . '.' . $t['field'];
        }

        // IS THERE A WAY BACK? We build no undo of our own — WordPress revisions
        // and Elementor's History are the recovery path, and a save through
        // $document->save() writes one (verified end to end on 2026-09-28:
        // restoring the revision reverted both the stored tree and the live
        // page). But revisions can be switched off, and then there is NO way
        // back from this edit at all. The owner needs that BEFORE they rely on
        // it, not after, so it rides on the result rather than being assumed.
        $restorable = function_exists( 'wp_revisions_enabled' )
            ? (bool) wp_revisions_enabled( get_post( (int) $post_id ) )
            : true;

        return array(
            'replaced'       => count( $touched ),
            'restorable'     => $restorable,
            'restoreNote'    => $restorable
                ? 'Recoverable: this save wrote a WordPress revision, so the owner can roll it back from Revisions or from Elementor History.'
                : 'NOT RECOVERABLE: post revisions are disabled on this site, so this change cannot be rolled back. Say so when you report it.',
            'widgets'        => $touched,
            // Which fields were read back and matched, so "verified" is a list
            // of things checked rather than an adjective.
            'verifiedFields' => $verified,
            'revisionBefore' => $revision_before,
            'revisionAfter'  => self::revision( $after_elements ),
        );
    }

    /**
     * Is this field's value actually coming from somewhere else?
     *
     * Elementor stores dynamic tags in a `__dynamic__` map beside the settings,
     * keyed by field name. The literal in `settings` is then a stale preview of
     * whatever the tag resolves to, and editing it either writes over a binding
     * or is silently discarded at render — a change that looks applied and is
     * not, which is the one outcome this codebase refuses to produce.
     *
     * The widget and field still look supported, which is exactly why this is
     * checked per field rather than per widget type.
     */
    private static function is_bound( $node, $field ) {
        return isset( $node['settings']['__dynamic__'][ $field ] )
            && '' !== trim( (string) $node['settings']['__dynamic__'][ $field ] );
    }

    /**
     * Count where the text sits, split by whether we can edit it there.
     *
     * @param array  $elements
     * @param string $find
     * @return array{supported:int, unsupported:int, unsupportedTypes:array}
     */
    private static function scan( $elements, $find ) {
        $out = array( 'supported' => 0, 'unsupported' => 0, 'unsupportedTypes' => array() );
        self::walk(
            $elements,
            function ( $node ) use ( $find, &$out ) {
                $type     = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';
                $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
                $fields   = isset( self::SUPPORTED[ $type ] ) ? self::SUPPORTED[ $type ] : null;
                foreach ( $settings as $key => $value ) {
                    if ( ! is_string( $value ) || '' === $value ) {
                        continue;
                    }
                    $hits = substr_count( $value, $find );
                    if ( 0 === $hits ) {
                        continue;
                    }
                    if ( $fields && in_array( $key, $fields, true ) && ! self::is_bound( $node, $key ) ) {
                        $out['supported'] += $hits;
                    } else {
                        $out['unsupported']       += $hits;
                        $out['unsupportedTypes'][] = '' === $type ? ( isset( $node['elType'] ) ? (string) $node['elType'] : 'unknown' ) : $type;
                    }
                }
            }
        );
        return $out;
    }

    /**
     * Rewrite the tree, touching ONLY allowlisted fields on allowlisted widgets.
     *
     * Everything else is copied through unchanged, including settings we do not
     * understand: the tree carries styling, responsive variants and per-widget
     * state we have no business normalising, and "preserve all unrelated data"
     * means byte-for-byte, not "re-serialise and hope".
     *
     * @param array  $elements
     * @param string $find
     * @param string $replace
     * @param bool   $all
     * @param array  $touched  Out: widget ids changed.
     * @return array
     */
    private static function apply( $elements, $find, $replace, $all, &$touched ) {
        $done = false;
        $rewrite = function ( $node ) use ( &$rewrite, $find, $replace, $all, &$touched, &$done ) {
            if ( ! is_array( $node ) ) {
                return $node;
            }
            $type   = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';
            $fields = isset( self::SUPPORTED[ $type ] ) ? self::SUPPORTED[ $type ] : null;
            if ( $fields && isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
                foreach ( $fields as $key ) {
                    if ( ! isset( $node['settings'][ $key ] ) || ! is_string( $node['settings'][ $key ] ) ) {
                        continue;
                    }
                    // A bound field is not ours to write, however supported the
                    // widget and field look. Same test as the scan, so what gets
                    // counted and what gets written can never disagree.
                    if ( self::is_bound( $node, $key ) ) {
                        continue;
                    }
                    $value = $node['settings'][ $key ];
                    if ( ! $all && $done ) {
                        continue;
                    }
                    if ( 0 === substr_count( $value, $find ) ) {
                        continue;
                    }
                    $node['settings'][ $key ] = $all
                        ? str_replace( $find, $replace, $value )
                        : self::replace_first( $value, $find, $replace );
                    $touched[] = array(
                        'id'     => isset( $node['id'] ) ? (string) $node['id'] : '',
                        'widget' => $type,
                        'field'  => $key,
                        // THE WHOLE FIELD AS IT SHOULD NOW READ. Verification
                        // compares against this, not against the absence of the
                        // find text — see the note on the post-save check.
                        'expected' => $node['settings'][ $key ],
                    );
                    $done = true;
                }
            }
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $node['elements'] = array_map( $rewrite, $node['elements'] );
            }
            return $node;
        };
        return array_map( $rewrite, $elements );
    }

    /** First occurrence only, without regex. */
    private static function replace_first( $haystack, $find, $replace ) {
        $at = strpos( $haystack, $find );
        if ( false === $at ) {
            return $haystack;
        }
        return substr_replace( $haystack, $replace, $at, strlen( $find ) );
    }

    /**
     * One widget's field value out of a saved tree, by widget id.
     *
     * Addressed by ID rather than by position: the point of reading back is to
     * find what Elementor actually persisted, and walking to "the same place"
     * would find whatever now sits there.
     *
     * @return string|null Null when the widget or field is gone.
     */
    private static function field_value( $elements, $id, $field ) {
        $found = null;
        self::walk(
            $elements,
            function ( $node ) use ( $id, $field, &$found ) {
                if ( null !== $found ) {
                    return;
                }
                if ( ! isset( $node['id'] ) || (string) $node['id'] !== (string) $id ) {
                    return;
                }
                if ( isset( $node['settings'][ $field ] ) && is_string( $node['settings'][ $field ] ) ) {
                    $found = $node['settings'][ $field ];
                }
            }
        );
        return $found;
    }

    /**
     * The text this adapter can change, by widget, as a visitor reads it.
     *
     * Round 4 E1 (2026-10-02): asked what could change on the Elementor home
     * page, the agent named the theme's page title and the button label,
     * neither of which is a heading or text-editor widget. The read said only
     * "heading and text widgets"; it now lists them by their current text, so
     * the answer can name exactly what is editable. Supported fields, not
     * bound to a dynamic tag, tags stripped, in tree order.
     *
     * @param array $elements The decoded _elementor_data tree.
     * @param int   $max      At most this many.
     * @return array<int, array{widget:string, text:string}>
     */
    public static function editable_texts( $elements, $max = 12 ) {
        $out = array();
        self::walk(
            $elements,
            function ( $node ) use ( &$out, $max ) {
                if ( count( $out ) >= $max ) {
                    return;
                }
                $type   = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';
                $fields = isset( self::SUPPORTED[ $type ] ) ? self::SUPPORTED[ $type ] : null;
                if ( ! $fields || ! isset( $node['settings'] ) || ! is_array( $node['settings'] ) ) {
                    return;
                }
                foreach ( $fields as $key ) {
                    if ( ! isset( $node['settings'][ $key ] ) || ! is_string( $node['settings'][ $key ] ) || self::is_bound( $node, $key ) ) {
                        continue;
                    }
                    $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $node['settings'][ $key ] ) ) );
                    if ( '' === $text ) {
                        continue;
                    }
                    if ( strlen( $text ) > 160 ) {
                        $text = rtrim( substr( $text, 0, 157 ) ) . '...';
                    }
                    $out[] = array( 'widget' => $type, 'text' => $text );
                }
            }
        );
        return $out;
    }

    /**
     * The refusal for a find that is in none of the editable widgets.
     *
     * Round 6 E3 (compilechicken page 8): four finds lifted from the stored
     * `_elementor_data` JSON and the page body's anchor each came back "appears
     * 0 times ... pass it byte-exact", which invited a fifth try at different
     * bytes. On a builder page the find is text, not stored data or markup, so
     * the refusal says what is editable instead.
     *
     * @param int   $post_id
     * @param array $elements The decoded _elementor_data tree.
     * @return string
     */
    public static function not_found_message( $post_id, $elements ) {
        $texts = array_map(
            function ( $t ) {
                return '"' . $t['text'] . '"';
            },
            self::editable_texts( $elements, 6 )
        );
        $editable = $texts
            ? ' The editable texts are: ' . implode( ', ', $texts ) . '.'
            : ' It has no editable heading or text widget.';
        return sprintf(
            'The find text is not in the text of post %d\'s Elementor heading and text widgets, so nothing was changed. Links, buttons and layout are not edited here; only the text of heading and text widgets is.%s',
            (int) $post_id,
            $editable
        );
    }

    /** Depth-first over every node in the tree. */
    private static function walk( $elements, $visit ) {
        foreach ( (array) $elements as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $visit( $node );
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                self::walk( $node['elements'], $visit );
            }
        }
    }
}
