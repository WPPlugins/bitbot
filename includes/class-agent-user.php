<?php
/**
 * The plugin's service account.
 *
 * Why an account exists at all: the assistant edits content while no person is
 * logged in (a scheduled task, or a change the owner asked for in the chat that is
 * applied by a server-to-server call). WordPress decides what a request may do from
 * the current user, so those edits need a user to run as. Running them as an
 * administrator would give them every capability. Instead they run as one account
 * with a narrow role (BitBot_Agent_Policy::role_caps(): content, media, menus; no
 * users, plugins, themes, settings or unfiltered HTML), so WordPress's own
 * current_user_can() is the ceiling on everything the assistant does.
 *
 * The account's password is random and never shown or stored by the plugin, and
 * its email is on the reserved .invalid domain, so a reset cannot be delivered.
 *
 * OWNERSHIP. The plugin only ever uses an account it created itself:
 *   - the account's id is remembered in an option, and
 *   - the account carries a user meta marker written when the plugin created it.
 * No existing account is ever adopted, whatever its login or email: when there is
 * no owned account a new one is created, under a login that is free. Any other
 * account holding the plugin's role (an account an earlier version created, or
 * one an earlier version promoted) has that role taken away, since the role is
 * meant for the owned account alone.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Agent_User {

    /** Option holding the id of the account the plugin created. Not autoloaded. */
    const ID_OPTION = 'bitbot_agent_user_id';

    /** User meta marker written at creation. */
    const OWNED_META = 'bitbot_agent_account';

    /** Login used when it is free. */
    const LOGIN = 'bitbot-agent';

    /**
     * The id the plugin may act as, from what is stored about an account.
     * Pure: decides from plain values so it can be tested without WordPress.
     *
     * @param int  $stored_id  Id from ID_OPTION (0 when none).
     * @param int  $user_id    Id of the account found for $stored_id (0 when it no longer exists).
     * @param bool $has_marker Whether that account carries OWNED_META.
     * @return int The account id, or 0 when there is no account the plugin owns.
     */
    public static function owned_id( $stored_id, $user_id, $has_marker ) {
        $stored_id = (int) $stored_id;
        return ( $stored_id > 0 && (int) $user_id === $stored_id && $has_marker ) ? $stored_id : 0;
    }

    /**
     * The login for a new account: the usual one when free, else a suffixed one.
     * Pure.
     *
     * @param bool   $usual_taken Whether LOGIN already belongs to some account.
     * @param string $suffix      Random suffix to use when it is taken.
     * @return string
     */
    public static function new_login( $usual_taken, $suffix ) {
        return $usual_taken ? self::LOGIN . '-' . strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $suffix ) ) : self::LOGIN;
    }

    /**
     * The plugin's own account, or null. Never creates or changes anything.
     *
     * @return WP_User|null
     */
    public static function get() {
        $stored = (int) get_option( self::ID_OPTION, 0 );
        if ( $stored <= 0 ) {
            return null;
        }
        $user = get_user_by( 'id', $stored );
        if ( ! $user ) {
            return null;
        }
        $marker = '1' === (string) get_user_meta( $stored, self::OWNED_META, true );
        return self::owned_id( $stored, (int) $user->ID, $marker ) ? $user : null;
    }

    /**
     * True when $user_id is the plugin's own account.
     *
     * @param int $user_id User id.
     * @return bool
     */
    public static function is_agent( $user_id ) {
        $user = self::get();
        return $user && (int) $user->ID === (int) $user_id;
    }

    /**
     * The plugin's own account, created when there is none yet.
     *
     * @param string $role_key Role the account holds.
     * @return int Account id, or 0 on failure.
     */
    public static function ensure( $role_key ) {
        $user = self::get();
        if ( $user ) {
            return (int) $user->ID;
        }
        $login = self::new_login( (bool) get_user_by( 'login', self::LOGIN ), wp_generate_password( 8, false ) );
        $uid   = wp_insert_user( array(
            'user_login'   => $login,
            'user_pass'    => wp_generate_password( 48, true, true ),
            // Reserved .invalid TLD (RFC 2606) with a random local part: unique,
            // passes is_email(), and can never receive a password reset.
            'user_email'   => 'agent-' . wp_generate_password( 12, false ) . '@bitbot.invalid',
            // Public bylines read as the site, not as the account's login.
            'display_name' => get_bloginfo( 'name' ),
            'description'  => BitBot_Agent_Policy::AGENT_DESCRIPTION,
            'role'         => $role_key,
        ) );
        if ( is_wp_error( $uid ) ) {
            // Two requests provisioning at once: the other one created and
            // remembered it. Use that; never fall back to a lookup by login.
            $again = self::get();
            return $again ? (int) $again->ID : 0;
        }
        update_user_meta( (int) $uid, self::OWNED_META, '1' );
        update_option( self::ID_OPTION, (int) $uid, false );
        return (int) $uid;
    }

    /**
     * Take the plugin's role away from every account that is not the owned one.
     * Accounts an earlier version created or promoted lose the role and keep
     * nothing else from the plugin; the accounts themselves are not touched.
     *
     * @param string $role_key The plugin's role.
     * @return bool True when no other account holds the role any more.
     */
    public static function demote_strangers( $role_key ) {
        $own    = self::get();
        $own_id = $own ? (int) $own->ID : 0;
        // Pages of 100 until none are left; the loop is bounded so a role that
        // cannot be removed (a user filter putting it back) cannot spin forever.
        for ( $round = 0; $round < 50; $round++ ) {
            $args = array( 'role' => $role_key, 'number' => 100, 'fields' => 'ID' );
            if ( $own_id ) {
                $args['exclude'] = array( $own_id );
            }
            $ids = get_users( $args );
            if ( empty( $ids ) ) {
                return true;
            }
            foreach ( $ids as $id ) {
                $stranger = get_user_by( 'id', (int) $id );
                if ( $stranger ) {
                    $stranger->remove_role( $role_key );
                }
            }
        }
        $args = array( 'role' => $role_key, 'number' => 1, 'fields' => 'ID' );
        if ( $own_id ) {
            $args['exclude'] = array( $own_id );
        }
        return empty( get_users( $args ) );
    }
}
