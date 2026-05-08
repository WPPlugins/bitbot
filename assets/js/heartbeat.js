/* BitBot admin heartbeat — lets the backend know the site owner is
 * currently active so the Pro+ agent can offer live-handover to
 * visitors. Fires on page load and every 120s thereafter while the
 * tab is visible. No UI.
 */
( function ( cfg ) {
	'use strict';

	if ( ! cfg || ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	var INTERVAL_MS = 120 * 1000; // 2 min

	function ping() {
		if ( typeof document !== 'undefined' && document.hidden ) {
			// Tab is backgrounded — don't claim presence.
			return;
		}
		var data = new FormData();
		data.append( 'action', 'bitbot_heartbeat' );
		data.append( 'nonce', cfg.nonce );
		// Fire-and-forget.
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			keepalive: true,
		} ).catch( function () { /* swallow */ } );
	}

	// Fire immediately on load so the first visitor chat turn after
	// the admin opens WP sees them as online.
	ping();
	setInterval( ping, INTERVAL_MS );

	// Re-ping when the tab comes back to foreground.
	if ( typeof document !== 'undefined' ) {
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) ping();
		} );
	}
} )( window.bitbotHeartbeat );
