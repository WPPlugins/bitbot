/**
 * BitBot Chat Widget
 *
 * Frontend chat widget for visitor interactions.
 *
 * @package BitBot
 * @since   1.0.0
 * @author  youami.ai
 * @license GPL-2.0-or-later
 */
(function() {
    'use strict';

    // Configuration from WordPress
    const config = window.bitbotConfig || {};

    // DOM Elements
    // `widget` is the light-DOM host (#bitbot-widget) — holds the position
    // class, CSS variables, and data-* attributes read by auto-flip.
    // `widgetRoot` is the shadow root attached to the host on init — every
    // bitbot-internal element lives inside it, so all queries for them
    // scope through widgetRoot.getElementById(...) instead of document.
    let widget, widgetRoot, toggle, chat, messages, form, input;
    let chatHistory = [];
    // Suggested-question chips: the owner's list and the bar in the DOM.
    // Module level because the language control re-renders them from
    // attachLanguage, outside init.
    let suggestedQuestions = [];
    let chipBar = null;
    // Set by attachChatGate while the pre-chat gate is armed: takes a first message
    // (typed or a suggestion chip) and returns true when it was held.
    let chatGateHold = null;
    // Set by attachChatGate: reopens or retries the gate after a reload.
    let chatGateRestore = null;
    let isOpen = false;
    let isLoading = false;
    // The bubble of the answer being streamed, and the text it shows so far.
    let streamBubble = null;
    let streamContent = null;
    let sessionId = null;
    let leadCapturedEmail = null; // Track if a lead was already captured this session
    // Live-handover mode (1.7.0): when the agent escalates to a human, the
    // widget stops talking to the agent and polls for admin replies instead.
    let liveMode = false;
    let liveLastMessageId = null;
    let livePollTimer = null;

    // Visitor presence (1.7.0): while the page is visible we send a
    // lightweight heartbeat every 30s so the admin's Live Chats dashboard
    // can render "present / idle / left" for this session. On tab close
    // we fire a sendBeacon so the admin sees the visitor leave immediately.
    let heartbeatTimer = null;
    const HEARTBEAT_INTERVAL_MS = 30000;

    // Per-tab chat persistence (1.5.3): sessionStorage so the conversation
    // survives navigation within the same tab, wipes on tab close.
    const CHAT_STORAGE_KEY = 'bitbot_chat_state';

    // Local persistence cap — stops sessionStorage growth on marathon chats.
    // Unrelated to the per-request send cap below.
    const CHAT_LOCAL_MAX = 80;
    // How many prior messages we send to the backend on each chat request.
    // The Pro+ agent also reads ground-truth tool results from its own
    // server-side trace, so we don't need the full transcript for facts —
    // just enough recent turns for conversational flow. Bumped from 10 to
    // 20 so a 4–5-turn OTP-add-to-cart doesn't lose its earlier context.
    const CHAT_SEND_MAX = 20;

    function saveChatState() {
        try {
            // Cap the local transcript so marathon sessions don't blow
            // sessionStorage. The agent gets its long-horizon memory from
            // the server-side tool_trace, not this client cache.
            if (chatHistory.length > CHAT_LOCAL_MAX) {
                chatHistory = chatHistory.slice(-CHAT_LOCAL_MAX);
            }
            sessionStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify({
                sessionId: sessionId,
                history: chatHistory,
                leadCapturedEmail: leadCapturedEmail,
                isOpen: isOpen,
                liveMode: liveMode,
                liveLastMessageId: liveLastMessageId,
            }));
        } catch (e) {
            // Storage quota or privacy mode — not fatal.
        }
    }

    function loadChatState() {
        try {
            const raw = sessionStorage.getItem(CHAT_STORAGE_KEY);
            if (!raw) return null;
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    /**
     * Visitor's IANA timezone (e.g. "Europe/London") from the browser. The
     * Pro+ agent uses this to format appointment labels in local time.
     * Returns empty string if unavailable; backend falls back to site tz.
     *
     * @since 1.7.0
     */
    function getVisitorTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {
            return '';
        }
    }

    /**
     * Persistent per-browser UUID so the Pro+ agent can recognise returning
     * visitors across sessions. Unlike sessionId (per-tab-life), this lives
     * in localStorage and survives tab close / navigation. Fully opaque —
     * no PII, no server access beyond our own API.
     *
     * @since 1.7.0
     */
    function getVisitorId() {
        var key = 'bitbot_visitor_id';
        try {
            var existing = localStorage.getItem(key);
            if (existing && existing.length > 10) return existing;
        } catch (e) { /* storage disabled */ }

        var uuid;
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            uuid = crypto.randomUUID();
        } else {
            // RFC4122-ish fallback for older browsers.
            uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0;
                var v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }
        try { localStorage.setItem(key, uuid); } catch (e) { /* swallow */ }
        return uuid;
    }

    /**
     * Generate or retrieve session ID
     */
    function getSessionId() {
        if (sessionId) return sessionId;

        // Try to get from localStorage
        const storageKey = 'bitbot_session_id';
        try {
            sessionId = localStorage.getItem(storageKey);

            if (!sessionId) {
                // Generate new UUID
                sessionId = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    const r = Math.random() * 16 | 0;
                    const v = c === 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
                localStorage.setItem(storageKey, sessionId);
            }
        } catch (e) {
            // localStorage not available, generate temporary ID
            sessionId = 'temp-' + Date.now();
        }

        return sessionId;
    }

    /**
     * Detect a fixed-position widget (accessibility tools, other chat
     * widgets, "back to top" buttons) overlapping our planned corner.
     *
     * Strategy:
     *   1. Try known signatures first — UserWay, AccessiBe, EqualWeb,
     *      Tawk.to, Crisp, Intercom, Drift, HubSpot, Drift, Olark, Kommo,
     *      ChatBot, etc. Cheap and reliable.
     *   2. Fall back to a geometric scan: any fixed-position element with
     *      z-index >= 100 whose bounding box intersects our 80×80 corner
     *      box, ignoring elements smaller than 30px (purely decorative).
     *
     * Runs twice — once on init, once after a 1500ms delay — because a
     * lot of these widgets inject themselves async after page load.
     *
     * @param {string} corner 'right' or 'left' — the corner we're checking.
     * @returns {boolean} true if the corner is occupied.
     */
    function isCornerOccupied(corner) {
        var KNOWN_SELECTORS = [
            // UserWay accessibility widget
            '#userwayAccessibilityIcon', '.userway_buttons_wrapper', '#userwayDebug',
            // AccessiBe / accessiBe Web Accessibility
            '#acsbBtn', '.acsb-trigger', '#acsb-trigger', '[data-acsb]',
            // EqualWeb
            '#INDmenu-btn', '.INDmenu-btn',
            // Tawk.to
            '.tawk-min-container', '#tawkchat-container', '#tawkchat-minified-wrapper',
            // Crisp
            '.crisp-client',
            // Intercom
            '.intercom-launcher', '.intercom-lightweight-app-launcher',
            // Drift
            '#drift-frame-controller', '.drift-frame-controller',
            // HubSpot chat
            '#hubspot-messages-iframe-container',
            // Olark
            '#olark-box-front',
            // Kommo / amoCRM chat
            '#amo-chat-button',
            // ChatBot.com / LiveChat
            '#chat-widget-container', '#chat-widget',
            // Generic "scroll-to-top" buttons / back-to-top plugins
            '#scrollUp', '.scroll-to-top', '.back-to-top'
        ];
        for (var i = 0; i < KNOWN_SELECTORS.length; i++) {
            var el = document.querySelector(KNOWN_SELECTORS[i]);
            if (!el) continue;
            var r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;
            var cs = getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden') continue;
            // Only count it if it's actually pinned to the bottom of the viewport.
            if (r.bottom < window.innerHeight - 200) continue;
            // Match the side
            if (corner === 'right' && r.right > window.innerWidth - 120) return true;
            if (corner === 'left' && r.left < 120) return true;
        }
        // Geometric fallback — scan ALL fixed elements for ones in the corner.
        var all = document.querySelectorAll('body *');
        for (var j = 0; j < all.length; j++) {
            var node = all[j];
            if (node.id && node.id.indexOf('bitbot') === 0) continue; // ourselves
            var c = getComputedStyle(node);
            if (c.position !== 'fixed') continue;
            if (c.display === 'none' || c.visibility === 'hidden') continue;
            // Invisible-but-mounted overlays (e.g. WooCommerce mini-cart's
            // screen-overlay sits at opacity:0, pointer-events:none until
            // the cart is opened) shouldn't count as a real occupant —
            // we'd otherwise flag both corners as busy and stick with
            // the user's preference, defeating the whole point of the
            // collision check. Same logic for pointer-events:none alone:
            // if you can't click it, it's decorative.
            if (parseFloat(c.opacity) === 0) continue;
            if (c.pointerEvents === 'none') continue;
            var z = parseInt(c.zIndex, 10);
            if (!isNaN(z) && z < 100) continue;
            var b = node.getBoundingClientRect();
            if (b.width < 30 || b.height < 30) continue;
            // Anything wider than 60% of the viewport is a sheet / modal /
            // toast container, not a corner widget. It "occupies" both
            // corners by accident; counting it as an occupant breaks the
            // flip logic. Same height check catches full-screen overlays.
            if (b.width > window.innerWidth * 0.6) continue;
            if (b.height > window.innerHeight * 0.6) continue;
            if (b.bottom < window.innerHeight - 200) continue;
            if (corner === 'right' && b.right > window.innerWidth - 120) return true;
            if (corner === 'left' && b.left < 120) return true;
        }
        return false;
    }

    /**
     * Set widget position class. Strips any prior position class first.
     */
    function setWidgetPosition(corner) {
        if (!widget) return;
        widget.classList.remove('bitbot-bottom-right', 'bitbot-bottom-left');
        widget.classList.add(corner === 'left' ? 'bitbot-bottom-left' : 'bitbot-bottom-right');
    }

    /**
     * Pick the best corner. Respects the site owner's configured preference
     * unless that corner is occupied — then flips to the other corner if
     * THAT one is free. If both are occupied, sticks with the configured
     * preference (we'd rather overlap a third-party widget than be hidden).
     */
    /**
     * Find the topmost (smallest .top) fixed widget already in our
     * target corner so we can stack on top of it. Skips the same
     * candidates that isCornerOccupied filters out (invisible
     * overlays, full-viewport sheets) because those wouldn't be a
     * "real" collision to stack against either.
     *
     * Returns the bounding rect of the matching element, or null if
     * nothing real is in the corner.
     */
    function findCornerOccupant(corner) {
        var candidates = document.querySelectorAll('body *');
        var topmost = null;
        for (var i = 0; i < candidates.length; i++) {
            var node = candidates[i];
            if (node.id && node.id.indexOf('bitbot') === 0) continue;
            var c = getComputedStyle(node);
            if (c.position !== 'fixed') continue;
            if (c.display === 'none' || c.visibility === 'hidden') continue;
            if (parseFloat(c.opacity) === 0) continue;
            if (c.pointerEvents === 'none') continue;
            var z = parseInt(c.zIndex, 10);
            if (!isNaN(z) && z < 100) continue;
            var b = node.getBoundingClientRect();
            if (b.width < 30 || b.height < 30) continue;
            if (b.width > window.innerWidth * 0.6) continue;
            if (b.height > window.innerHeight * 0.6) continue;
            if (b.bottom < window.innerHeight - 200) continue;
            if (corner === 'right' && b.right <= window.innerWidth - 120) continue;
            if (corner === 'left' && b.left >= 120) continue;
            // Prefer the one closest to the bottom of the viewport
            // (largest .bottom) so we stack above THAT one's height.
            if (!topmost || b.bottom > topmost.bottom) {
                topmost = b;
            }
        }
        return topmost;
    }

    /**
     * Override the bottom offset on the widget so it stacks on top of
     * the corner occupant: occupant.height + 16px gap. Stored in the
     * runtime --bitbot-offset-y custom property; doesn't touch the
     * saved option.
     */
    function applyStackedOffset(occupantRect) {
        if (!widget || !occupantRect) return;
        // distance from viewport bottom to top of occupant + 16px gap
        var clearance = (window.innerHeight - occupantRect.top) + 16;
        widget.style.setProperty('--bitbot-offset-y', clearance + 'px');
    }

    /**
     * Restore the owner's configured vertical offset (set on
     * data-base-offset-y by PHP). Called when we successfully move to
     * a free corner so we don't carry a stacked offset across flips.
     */
    function restoreBaseOffset() {
        if (!widget) return;
        var base = parseInt(widget.getAttribute('data-base-offset-y') || '20', 10);
        widget.style.setProperty('--bitbot-offset-y', base + 'px');
    }

    function autoPositionWidget() {
        if (!widget) return;
        // Read the OWNER'S configured preference from the data attribute,
        // not the current class. The class can flip across passes (init
        // + 1.5s) and inherits from this same function -- if we read the
        // class as "preferred", a first-pass flip locks in the new
        // corner as the new "preferred" and we can never restore the
        // owner's actual choice when the original corner becomes free
        // again. data-preferred is set once by PHP from settings.
        var preferred = widget.getAttribute('data-preferred') === 'left' ? 'left' : 'right';
        var preferredOccupied = isCornerOccupied(preferred);
        if (!preferredOccupied) {
            // Configured corner is clear → restore it + clear any stacked offset.
            setWidgetPosition(preferred);
            restoreBaseOffset();
            return;
        }
        var alt = preferred === 'right' ? 'left' : 'right';
        if (!isCornerOccupied(alt)) {
            setWidgetPosition(alt);
            restoreBaseOffset();
            return;
        }
        // Both occupied → instead of overlapping, stack on top of
        // whatever's in the owner's preferred corner. Bumps the
        // vertical offset by occupant.height + 16px gap so the two
        // widgets sit one above the other.
        setWidgetPosition(preferred);
        var occupant = findCornerOccupant(preferred);
        if (occupant) {
            applyStackedOffset(occupant);
        } else {
            restoreBaseOffset();
        }
    }

    /**
     * Initialize the widget
     */
    function init() {
        widget = document.getElementById('bitbot-widget');
        if (!widget) {
            return;
        }

        // If a prior chat in this tab session already discovered the
        // bot can't reply right now (trial expired OR Free chat cap
        // hit), don't render the bubble on subsequent pageloads.
        // Keeps visitors from seeing flicker-then-disappear behaviour.
        // 2.0.119: also covers Free monthly chat cap (was previously
        // deflecting to "leave us your email" which we couldn't honour
        // on Free since admin can't see lead rows).
        // Server-authoritative pause. The plugin computes whether the bot can
        // reply right now (expired sub or Free monthly chat cap) from a cached,
        // throttle-refreshed plan snapshot and sends it as config.accessPaused
        // (fetched fresh by the bootstrap before init runs). Honour it so we
        // never render a FAB that clicks into a dead chat — and the widget
        // reappears on its own once the cap resets / the site upgrades, with
        // no client-side flag or timer.
        if (config.accessPaused) {
            widget.style.display = 'none';
            return;
        }
        // Clear any pre-2.1.9 sticky flag so sessions stuck under the old
        // client-flag behaviour recover (the server value is authoritative now).
        try {
            sessionStorage.removeItem('bitbot-access-paused');
            sessionStorage.removeItem('bitbot-trial-expired');
        } catch (e) { /* sessionStorage unavailable */ }

        // 2.0.12 widget-seen heartbeat. Single fire-and-forget POST per
        // browser session so the WP backend can stamp the wp_option the
        // admin Overview banner reads. Lets us detect themes that skip
        // wp_footer() — the enqueue ran, but the visitor's browser
        // never executed widget.js, so we never ping back. Throttled
        // via sessionStorage so even chatty single-page-app navigation
        // only pings once.
        try {
            if (sessionStorage.getItem('bitbot-seen-pinged') !== '1') {
                var origin = (window.bitbotConfig && window.bitbotConfig.restUrl)
                    ? new URL(window.bitbotConfig.restUrl).origin
                    : window.location.origin;
                var ajaxUrl = origin + '/wp-admin/admin-ajax.php';
                var body = new URLSearchParams();
                body.append('action', 'bitbot_widget_seen');
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    keepalive: true
                }).catch(function () { /* swallow — best-effort */ });
                sessionStorage.setItem('bitbot-seen-pinged', '1');
            }
        } catch (e) { /* sessionStorage unavailable, skip ping */ }

        // Attach a shadow root on the host and mount the template into it.
        // Theme CSS rules on bare `button`, `svg`, `input`, etc. cannot
        // cross the shadow boundary, so the widget renders exactly as
        // designed regardless of which theme the site uses.
        if (widget.shadowRoot) {
            widgetRoot = widget.shadowRoot;
        } else if (typeof widget.attachShadow === 'function') {
            widgetRoot = widget.attachShadow({ mode: 'open' });

            // First child: the stylesheet. Browsers block style application
            // on its descendants until this link resolves, which prevents a
            // flash of unstyled widget on first paint.
            if (config.cssUrl) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = config.cssUrl;
                widgetRoot.appendChild(link);
            }

            // Clone the template content into the shadow root.
            const tpl = document.getElementById('bitbot-widget-template');
            if (tpl && 'content' in tpl) {
                widgetRoot.appendChild(tpl.content.cloneNode(true));
            }
        } else {
            // Shadow DOM unsupported (very old browser): fall back to
            // mounting the template directly in the host. The widget will
            // be vulnerable to theme CSS bleed but at least functional.
            const tpl = document.getElementById('bitbot-widget-template');
            if (tpl && 'content' in tpl) {
                widget.appendChild(tpl.content.cloneNode(true));
            }
            widgetRoot = widget;
        }

        toggle = widgetRoot.getElementById('bitbot-toggle');
        chat = widgetRoot.getElementById('bitbot-chat');
        messages = widgetRoot.getElementById('bitbot-messages');
        form = widgetRoot.getElementById('bitbot-form');
        input = widgetRoot.getElementById('bitbot-input');

        if (!toggle || !chat) {
            return;
        }

        // Citation-click beacon. Delegation so we catch any future
        // .bitbot-citation-link nodes inserted into `messages` without
        // re-binding. The beacon is best-effort — we never block the
        // visitor's actual navigation; the click still opens the link
        // because the listener doesn't preventDefault.
        messages.addEventListener('click', function (ev) {
            var target = ev.target;
            // Walk up the tree in case the click landed on a child node
            // (browsers can hit a text node, especially with text-only
            // links).
            while (target && target !== messages && !(target.classList && target.classList.contains('bitbot-citation-link'))) {
                target = target.parentNode;
            }
            if (!target || target === messages) return;
            var url = target.getAttribute('href') || '';
            var title = (target.textContent || '').trim();
            if (!url) return;
            try {
                var body = JSON.stringify({
                    sessionId: getSessionId(),
                    url: url,
                    title: title,
                });
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(
                        config.restUrl + '/citation-click',
                        new Blob([body], { type: 'application/json' })
                    );
                } else {
                    // Fallback: keepalive fetch survives tab close.
                    fetch(config.restUrl + '/citation-click', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: body,
                        keepalive: true,
                    }).catch(function () { /* swallow */ });
                }
            } catch (e) { /* swallow — never block the click */ }
        });

        // 1.9.0: mobile-behaviour override (8k). Stamp a data attribute on
        // the host so the widget CSS @media block can flip position or
        // hide entirely below the mobile breakpoint. CSS does the heavy
        // lifting; this just communicates intent.
        const mobileBehaviour = (config.mobileBehaviour || 'inherit').toLowerCase();
        const allowedMobileBehaviour = new Set(['inherit', 'bottom-right', 'bottom-left', 'hide']);
        const host = document.getElementById('bitbot-widget');
        if (host && allowedMobileBehaviour.has(mobileBehaviour) && mobileBehaviour !== 'inherit') {
            host.setAttribute('data-mobile-behaviour', mobileBehaviour);
        }

        // 1.9.0: custom avatar (8d). Replace the default FAB chat icon
        // with the admin's image when configured. The chat-header slot
        // also receives a small circular crop of the same image.
        //
        // Round launcher and header circle use the saved crop through a
        // background-image; the Pill launcher shows the image as a small
        // circle (object-fit cover, sized in CSS) with no crop maths.
        const avatarUrl = (config.avatarUrl || '').trim();
        const avatarTx  = config.avatarTransform || null;
        const widgetHost = document.getElementById('bitbot-widget');
        const isPill = !!widgetHost && widgetHost.getAttribute('data-shape') === 'pill';

        // Build a CSS background spec from a saved transform for a square
        // target. Returns null when the transform is missing or malformed.
        function bgFromTransform(tx, containerW, containerH) {
            if (!tx || !tx.cropW || !tx.cropH || !tx.sourceW || !tx.sourceH) return null;
            if (tx.shape && tx.shape !== 'rounded') return null;
            var scaleX = containerW / tx.cropW;
            var scaleY = containerH / tx.cropH;
            return {
                size: (tx.sourceW * scaleX) + 'px ' + (tx.sourceH * scaleY) + 'px',
                position: (-tx.cropX * scaleX) + 'px ' + (-tx.cropY * scaleY) + 'px'
            };
        }

        if (avatarUrl) {
            // ── FAB launcher ──
            // Round launcher is 56x56; the pill takes no crop.
            var fabBg = isPill ? null : bgFromTransform(avatarTx, 56, 56);
            var chatIcon = widgetRoot.querySelector('.bitbot-icon-chat');
            if (isPill && toggle) {
                // Pill: the avatar is its own circle beside the text pill, not
                // inside it. It is not .bitbot-icon-chat, so opening the chat
                // leaves it visible.
                var pillAvatar = document.createElement('img');
                pillAvatar.src = avatarUrl;
                pillAvatar.alt = '';
                pillAvatar.className = 'bitbot-pill-avatar';
                toggle.appendChild(pillAvatar);
                toggle.classList.add('bitbot-has-avatar');
            } else if (chatIcon && chatIcon.parentNode) {
                var fabAvatar;
                if (fabBg) {
                    // bg-image path
                    fabAvatar = document.createElement('span');
                    fabAvatar.className = 'bitbot-icon-chat bitbot-avatar-img';
                    fabAvatar.style.display = 'block';
                    fabAvatar.style.width = '100%';
                    fabAvatar.style.height = '100%';
                    fabAvatar.style.borderRadius = 'inherit';
                    fabAvatar.style.backgroundImage = 'url(' + avatarUrl + ')';
                    fabAvatar.style.backgroundSize = fabBg.size;
                    fabAvatar.style.backgroundPosition = fabBg.position;
                    fabAvatar.style.backgroundRepeat = 'no-repeat';
                } else {
                    // Fallback to plain <img> with object-fit cover.
                    fabAvatar = document.createElement('img');
                    fabAvatar.src = avatarUrl;
                    fabAvatar.alt = '';
                    fabAvatar.className = 'bitbot-icon-chat bitbot-avatar-img';
                    fabAvatar.style.width = '100%';
                    fabAvatar.style.height = '100%';
                    fabAvatar.style.objectFit = 'cover';
                    fabAvatar.style.borderRadius = 'inherit';
                }
                chatIcon.replaceWith(fabAvatar);
            }

            // ── Chat-header avatar slot ──
            // Header avatar is a 36x36 circle in widget.css.
            var headerBg = bgFromTransform(avatarTx, 36, 36);
            var headerSlot = widgetRoot.getElementById('bitbot-header-avatar');
            if (headerSlot) {
                var hAvatar;
                if (headerBg) {
                    hAvatar = document.createElement('span');
                    hAvatar.className = 'bitbot-header-avatar-img';
                    hAvatar.style.display = 'block';
                    hAvatar.style.width = '100%';
                    hAvatar.style.height = '100%';
                    hAvatar.style.backgroundImage = 'url(' + avatarUrl + ')';
                    hAvatar.style.backgroundSize = headerBg.size;
                    hAvatar.style.backgroundPosition = headerBg.position;
                    hAvatar.style.backgroundRepeat = 'no-repeat';
                } else {
                    hAvatar = document.createElement('img');
                    hAvatar.src = avatarUrl;
                    hAvatar.alt = '';
                    hAvatar.className = 'bitbot-header-avatar-img';
                }
                headerSlot.appendChild(hAvatar);
            }
        }

        // 1.9.0: proactive nudge (8f). Pro feature. Three modes:
        //   - simple: one static line
        //   - page-aware: URL-pattern → line lookup
        //   - rag: ask backend for a contextual line (Pro+; widget falls
        //     back to first page-aware rule or simpleText if backend errors)
        // One-shot per session; sessionStorage 'bitbot-nudge-shown'='1'.
        //
        // 2.2.4: the bubble shows N seconds after the visitor ARRIVES (the
        // admin's "Show after"), whatever they are doing meanwhile. The 2.0.3
        // "inactivity" reading reset the countdown on every mouse move and
        // scroll, so a visitor reading the page never saw it (owner, 2026-11-05).
        // It still never interrupts an open chat: cancelled the moment the
        // chat opens, skipped if it is open at fire time. Once per session.
        (function attachNudge() {
            const nudge = config.nudge;
            if (!nudge || !nudge.enabled) return;
            try {
                if (sessionStorage.getItem('bitbot-nudge-shown') === '1') return;
            } catch (e) {}

            const delayMs = (nudge.delaySeconds || 8) * 1000;
            // How long an ignored bubble stays before it fades out.
            const NUDGE_LINGER_MS = 12000;
            let timer = null;
            let cancelled = false;
            let currentBubble = null;
            let chatObserver = null;

            function pickText() {
                if (nudge.mode === 'page-aware' && nudge.pageRules) {
                    const path = window.location.pathname || '/';
                    const rules = nudge.pageRules.split(/\r?\n/);
                    for (let i = 0; i < rules.length; i++) {
                        const parts = rules[i].split('|');
                        if (parts.length < 2) continue;
                        const pat = parts[0].trim();
                        const txt = parts.slice(1).join('|').trim();
                        if (!pat || !txt) continue;
                        const regex = new RegExp('^' + pat.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$', 'i');
                        if (regex.test(path) || path.indexOf(pat) !== -1) return txt;
                    }
                }
                // simple OR rag-fallback OR page-aware-no-match
                // An enabled bubble with no message uses the default line the admin shows as its placeholder.
                return nudge.simpleText || "Need a hand? I'm here to help.";
            }

            // The launcher buzzes as the bubble slides in beside it (class
            // drives the keyframes in widget.css; removed when they end).
            function buzzLauncher() {
                if (!toggle) return;
                toggle.classList.remove('bitbot-toggle-buzz');
                void toggle.offsetWidth;
                toggle.classList.add('bitbot-toggle-buzz');
                toggle.addEventListener('animationend', function () { toggle.classList.remove('bitbot-toggle-buzz'); }, { once: true });
            }

            function showBubble(text) {
                if (!text || !toggle) return;
                try { sessionStorage.setItem('bitbot-nudge-shown', '1'); } catch (e) {}
                buzzLauncher();
                const bubble = document.createElement('div');
                bubble.className = 'bitbot-nudge-bubble';
                bubble.setAttribute('role', 'status');
                // A pill holding just the line (no icon; owner, 2026-11-05).
                const line = document.createElement('span');
                line.className = 'bitbot-nudge-text';
                line.textContent = text;
                bubble.appendChild(line);
                // No close button (owner, 2026-11-05): a click opens the chat,
                // and an ignored bubble fades out by itself.
                bubble.addEventListener('click', function() {
                    bubble.remove();
                    if (chat && chat.style.display === 'none') toggleChat();
                });
                setTimeout(function () {
                    if (!bubble.isConnected) return;
                    bubble.classList.add('bitbot-nudge-leaving');
                    bubble.addEventListener('animationend', function () { bubble.remove(); }, { once: true });
                }, NUDGE_LINGER_MS);
                // 2.0.2: insert INSIDE the shadow root, not the light DOM.
                // The previous "light DOM so the theme doesn't see inside
                // the shadow root" comment had the encapsulation direction
                // backwards — shadow DOM hides its *contents* from the
                // page, not the other way around. widget.css lives only
                // inside the shadow root (loaded via cssUrl by widget.js)
                // so a bubble in the light DOM gets none of the
                // .bitbot-nudge-bubble styles and renders as a 0×0 element.
                // Matches how the pinned banner already does it.
                if (widgetRoot) widgetRoot.appendChild(bubble);
                currentBubble = bubble;
            }

            function fire() {
                if (cancelled) return;
                // Chat already open? Treat as engaged — don't interrupt.
                if (chat && chat.style.display !== 'none') {
                    cancel(true);
                    return;
                }
                const text = pickText();
                if (text) showBubble(text);
                // Don't tear down listeners yet — keep watching the chat
                // so the bubble disappears if the visitor opens the chat
                // (via toggle, auto-open, or any programmatic path).
            }

            function cancel(removeBubble) {
                cancelled = true;
                if (timer) { clearTimeout(timer); timer = null; }
                if (chatObserver) { chatObserver.disconnect(); chatObserver = null; }
                if (removeBubble && currentBubble) {
                    currentBubble.remove();
                    currentBubble = null;
                }
            }

            // Watch chat-panel display so we cancel/tear-down on ANY way
            // the chat opens — toggle click, auto-open delay/scroll/exit,
            // restored from session storage, programmatic toggleChat from
            // pre-chat form, etc. Pure click-listener wouldn't catch the
            // programmatic openings.
            if (chat && typeof MutationObserver === 'function') {
                chatObserver = new MutationObserver(function() {
                    if (chat.style.display !== 'none') cancel(true);
                });
                chatObserver.observe(chat, { attributes: true, attributeFilter: ['style'] });
            }

            timer = setTimeout(fire, delayMs);
        })();

        // 1.9.0: auto-open trigger (8g). Pro feature; opens the chat
        // panel automatically once per session based on admin-configured
        // triggers. Storage: sessionStorage 'bitbot-auto-opened' = '1'
        // so reload-spam doesn't re-trigger.
        (function attachAutoOpen() {
            const ao = config.autoOpen;
            if (!ao) return;
            let alreadyTriggered = false;
            try {
                if (sessionStorage.getItem('bitbot-auto-opened') === '1') return;
            } catch (e) { /* sessionStorage unavailable */ }

            function trigger(reason) {
                if (alreadyTriggered) return;
                alreadyTriggered = true;
                try { sessionStorage.setItem('bitbot-auto-opened', '1'); } catch (e) {}
                // Open the chat panel via the existing toggle handler.
                if (chat && chat.style.display === 'none') {
                    toggleChat();
                }
            }

            // URL pattern match.
            if (ao.urlPatterns) {
                const path = window.location.pathname || '/';
                const url  = window.location.href;
                const patterns = ao.urlPatterns.split(/\r?\n/).map(function(p){ return p.trim(); }).filter(Boolean);
                for (let i = 0; i < patterns.length; i++) {
                    const pat = patterns[i];
                    const regex = new RegExp('^' + pat.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$', 'i');
                    if (regex.test(path) || regex.test(url) || path.indexOf(pat) !== -1) {
                        trigger('url');
                        return;
                    }
                }
            }

            // Time-on-page delay.
            if (ao.delaySeconds > 0) {
                setTimeout(function(){ trigger('delay'); }, ao.delaySeconds * 1000);
            }

            // Scroll-percent trigger.
            if (ao.scrollPercent > 0) {
                const threshold = ao.scrollPercent;
                const onScroll = function() {
                    const doc = document.documentElement;
                    const scrolled = (doc.scrollTop || document.body.scrollTop);
                    const max = (doc.scrollHeight - doc.clientHeight) || 1;
                    if ((scrolled / max) * 100 >= threshold) {
                        trigger('scroll');
                        window.removeEventListener('scroll', onScroll);
                    }
                };
                window.addEventListener('scroll', onScroll, { passive: true });
            }

            // Exit-intent (cursor moving toward browser chrome — top y < 0).
            if (ao.exitIntent) {
                const onMouseOut = function(e) {
                    if (!e.toElement && !e.relatedTarget && e.clientY < 10) {
                        trigger('exit');
                        document.removeEventListener('mouseout', onMouseOut);
                    }
                };
                document.addEventListener('mouseout', onMouseOut);
            }
        })();

        // 2.0.3: pinned "Get started" banner removed — the admin UI no
        // longer exposes the field. Stored values are ignored client-side.
        // PHP no longer sends pinnedMessage in the config object either.

        // Chat-gate. When the admin has flipped on the chat-gate toggle
        // for the default pre-chat form, the FIRST visitor message is held
        // client-side: not sent to the bot. It stays visible as a pending
        // bubble above the form and is released only after a successful
        // submission. States:
        //   armed      the interceptor catches the next composer send (or chip click).
        //   open       form shown; composer disabled; Back and Submit available.
        //   submitting Submit and Back disabled; the request is in flight.
        //   Back       drops the form and pending bubble, puts the text back in the
        //              composer, returns to armed (nothing is lost).
        //   success    flag set, interceptor removed, held message sent as normal.
        // The interceptor never detaches before a successful submission, so
        // closing and reopening the chat cannot bypass the gate.
        (function attachChatGate() {
            const gateFormId = (config.chatGateFormId || '').trim();
            const formSchema = config.chatGateForm || null;
            if (!gateFormId || !formSchema) return;
            try {
                if (sessionStorage.getItem('bitbot-chat-gate-done') === '1') return;
            } catch (e) {}
            if (!form || !input) return;
            const F = window.BitBotForm;
            if (!F) return;

            // All visitor-facing gate copy lives here. The per-language copy
            // table is owner text run through the translation pipeline, so these
            // fixed strings are not in it yet (English only for now).
            const GATE_COPY = {
                heading: 'A few details first',
                pendingLabel: 'Your message, sent once you share your details',
                submit: 'Continue',
                submitting: 'Sending...',
                back: 'Back',
                networkError: 'Network error. Please try again.',
                genericError: 'Something went wrong. Please try again.'
            };
            const P = 'bitbot-chat-gate';
            const GATE_STATE_KEY = 'bitbot_chat_gate_state';
            const fields = F.normalizeFields(formSchema.fields);
            let gateOpen = false;      // a form is showing (open or submitting)
            let gateDone = false;      // successful submission happened

            // Gate state survives a reload or navigation (sessionStorage, like the
            // chat): { held, key, submitted, body }. submitted + body mean a
            // submission was sent and its response may have been lost; on load it
            // is retried with the same key (the API stores one submission per key).
            function readGateState() {
                try {
                    const raw = sessionStorage.getItem(GATE_STATE_KEY);
                    const s = raw ? JSON.parse(raw) : null;
                    return s && typeof s.held === 'string' && typeof s.key === 'string' ? s : null;
                } catch (e) { return null; }
            }
            function writeGateState(state) {
                try { sessionStorage.setItem(GATE_STATE_KEY, JSON.stringify(state)); } catch (e) {}
            }
            function clearGateState() {
                try { sessionStorage.removeItem(GATE_STATE_KEY); } catch (e) {}
            }

            function openGate(heldMessage, existingKey, retryBody) {
                gateOpen = true;
                setComposerLocked(true);
                // One key per held message; reused for every retry of it.
                const submissionKey = existingKey || F.newSubmissionKey();
                writeGateState({ held: heldMessage, key: submissionKey, submitted: false, body: null });

                const pending = document.createElement('div');
                pending.className = 'bitbot-message bitbot-user bitbot-pending';
                const pendingText = document.createElement('div');
                pendingText.className = 'bitbot-message-content';
                pendingText.textContent = heldMessage;
                const pendingNote = document.createElement('div');
                pendingNote.className = 'bitbot-pending-note';
                pendingNote.textContent = GATE_COPY.pendingLabel;
                pending.appendChild(pendingText);
                pending.appendChild(pendingNote);
                messages.appendChild(pending);

                const wrap = document.createElement('div');
                wrap.className = P;
                wrap.setAttribute('role', 'group');
                const headId = 'bitbot-gate-head';
                wrap.setAttribute('aria-labelledby', headId);
                wrap.innerHTML = ''
                    + '<div class="' + P + '-head" id="' + headId + '"></div>'
                    + '<form class="' + P + '-form" novalidate>'
                    +   F.renderFields(fields, { prefix: P, idPrefix: 'bitbot-gate' })
                    +   '<div class="' + P + '-actions">'
                    +     '<button type="submit" class="' + P + '-submit"></button>'
                    +     '<button type="button" class="' + P + '-back"></button>'
                    +   '</div>'
                    + '</form>';
                // The form's name is the owner's label for it in the admin; the visitor gets one neutral line.
                wrap.querySelector('.' + P + '-head').textContent = GATE_COPY.heading;
                const submitBtn = wrap.querySelector('.' + P + '-submit');
                const backBtn = wrap.querySelector('.' + P + '-back');
                submitBtn.textContent = GATE_COPY.submit;
                backBtn.textContent = GATE_COPY.back;
                messages.appendChild(wrap);

                const gateForm = wrap.querySelector('.' + P + '-form');
                // The sticky action row must not hide the last field: the scroll
                // area keeps room for it (padding and scroll-padding, see CSS).
                const first = wrap.querySelector('[data-field]');
                // The gate scrolls inside the panel; put the first field in view
                // and focused (preventScroll, then an explicit scroll, so the
                // mobile keyboard does not fight the browser's own jump).
                if (first) {
                    try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
                }
                // The card is a normal block in the conversation: the panel scrolls, the card never
                // does. Show the whole card (fields and buttons); the held message sits just above it.
                messages.scrollTop = messages.scrollHeight;

                function clearAll() {
                    fields.forEach(function(f) { F.clearError(wrap, f.name, P); });
                }
                wrap.addEventListener('input', function(ev) {
                    const name = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-field');
                    if (name) F.clearError(wrap, name, P);
                });
                wrap.addEventListener('change', function(ev) {
                    const name = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-field');
                    if (name) F.clearError(wrap, name, P);
                });

                function setBusy(busy) {
                    submitBtn.disabled = busy;
                    backBtn.disabled = busy;
                    submitBtn.textContent = busy ? GATE_COPY.submitting : GATE_COPY.submit;
                    gateForm.setAttribute('aria-busy', busy ? 'true' : 'false');
                }
                function focusFirstError(errors) {
                    const f = fields.filter(function(x) { return errors[x.name]; })[0];
                    const el = f && wrap.querySelector('[data-field="' + f.name + '"]');
                    if (el) el.focus();
                }
                function fail(fieldName, message) {
                    setBusy(false);
                    if (!F.showError(wrap, fieldName, message, P)) F.showError(wrap, 'email', message, P);
                }

                backBtn.addEventListener('click', function() {
                    if (backBtn.disabled) return;
                    wrap.remove();
                    pending.remove();
                    gateOpen = false;
                    clearGateState();   // a resend gets a fresh key
                    setComposerLocked(false);
                    input.value = heldMessage;
                    input.focus();
                });

                // POST the body and, on success, release the held message.
                async function send(body) {
                    setBusy(true);
                    // Marked before the request: if the page dies mid-flight the
                    // next load retries this exact body with the same key.
                    writeGateState({ held: heldMessage, key: submissionKey, submitted: true, body: body });
                    try {
                        const res = await fetch(config.restUrl + '/form-submit', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(body),
                        });
                        const resBody = await res.json();
                        if (!res.ok || (resBody && resBody.error)) {
                            // A real answer: nothing is in flight any more.
                            writeGateState({ held: heldMessage, key: submissionKey, submitted: false, body: null });
                            fail((resBody && resBody.field) ? resBody.field : 'email',
                                (resBody && resBody.error) ? resBody.error : GATE_COPY.genericError);
                            return;
                        }
                    } catch (e) {
                        // No answer: the marker stays so a reload retries with the same key.
                        fail('email', GATE_COPY.networkError);
                        return;
                    }

                    gateDone = true;
                    chatGateHold = null;
                    form.removeEventListener('submit', gateHandler, true);
                    wrap.remove();
                    pending.remove();
                    gateOpen = false;
                    setComposerLocked(false);
                    // Release the held message: render it and send it through
                    // the normal chat path so the bot replies. It is saved into
                    // the chat transcript first; only then is the gate state
                    // cleared, so a reload at any earlier point still has it.
                    addMessage(heldMessage, 'user');
                    chatHistory.push({ role: 'user', content: heldMessage });
                    saveChatState();
                    try { sessionStorage.setItem('bitbot-chat-gate-done', '1'); } catch (e) {}
                    clearGateState();
                    sendMessage(heldMessage);
                }

                gateForm.addEventListener('submit', function(ev) {
                    ev.preventDefault();
                    if (submitBtn.disabled) return;
                    clearAll();
                    const values = F.readValues(wrap, fields);
                    const check = F.validate(fields, values);
                    if (!check.valid) {
                        Object.keys(check.errors).forEach(function(n) { F.showError(wrap, n, check.errors[n], P); });
                        focusFirstError(check.errors);
                        return;
                    }
                    send(F.buildSubmitBody({
                        formId: gateFormId,
                        payload: F.toPayload(fields, values),
                        heldMessage: heldMessage,
                        // getSessionId(), not the bare var: it is lazily
                        // populated, null until the first send or this call.
                        sessionId: getSessionId(),
                        submissionKey: submissionKey
                    }));
                });

                // Reload retry: the response to this body was lost, send it again.
                if (retryBody) send(retryBody);
            }

            // Composer lock while the form is open: no second message can be
            // typed or sent behind the gate.
            function setComposerLocked(locked) {
                input.disabled = locked;
                const sendBtn = form.querySelector('button[type="submit"]');
                if (sendBtn) sendBtn.disabled = locked;
            }

            // Hold a message (typed or a chip). Returns true when the gate took it.
            function hold(text) {
                if (gateDone) return false;
                if (gateOpen) return true;  // swallow: a form is already waiting
                openGate(text);
                return true;
            }
            chatGateHold = hold;

            // After a reload: reopen the gate with the same held message and
            // key, or retry a submission whose response was lost. Called once
            // the chat transcript has been rehydrated (that wipes the panel).
            chatGateRestore = function() {
                const state = readGateState();
                if (!state || gateDone || gateOpen) return;
                if (!isOpen) toggleChat();
                openGate(state.held, state.key, state.submitted && state.body ? state.body : null);
            };

            // Capture phase, so this runs before the normal send handler.
            function gateHandler(ev) {
                if (gateDone) return;
                const value = (input.value || '').trim();
                if (gateOpen) {
                    ev.preventDefault();
                    ev.stopImmediatePropagation();
                    return;
                }
                if (!value) return;  // empty submit: let it fall through
                ev.preventDefault();
                ev.stopImmediatePropagation();
                input.value = '';
                hold(value);
            }
            form.addEventListener('submit', gateHandler, true);
        })();

        // 1.9.0: suggested-question chips (8e). Render below welcome
        // message, hide on first visitor turn (whether they typed or
        // clicked a chip). Each click submits the chip's message as if
        // typed — same path as a normal send.
        suggestedQuestions = Array.isArray(config.suggestedQuestions) ? config.suggestedQuestions : [];
        renderChips(suggestedQuestions);
        // Hide on first typed-send (cross-cutting state kept here, not in the
        // form handler below).
        if (form && chipBar) {
            form.addEventListener('submit', function() {
                if (chipBar) chipBar.style.display = 'none';
            }, { once: true });
        }

        // 1.9.0: apply custom placeholder if admin has set one in Settings.
        // Empty string keeps the template's localized "Type your message…"
        // default — no surprise behaviour change for existing installs.
        if (input && typeof config.placeholderText === 'string' && config.placeholderText.trim()) {
            input.setAttribute('placeholder', config.placeholderText.trim());
        }

        // Auto-reposition if a 3rd-party widget (UserWay, Tawk, etc.) is
        // squatting on the configured corner. Run once now + once after a
        // delay because a lot of accessibility widgets inject themselves
        // post-load.
        autoPositionWidget();
        setTimeout(autoPositionWidget, 1500);

        // Event listeners
        toggle.addEventListener('click', toggleChat);

        const minimize = widgetRoot.getElementById('bitbot-minimize');
        if (minimize) {
            minimize.addEventListener('click', toggleChat);
        }

        if (form) {
            form.addEventListener('submit', handleSubmit);
        }

        attachLanguage();
        attachVoiceCall();
        attachWhatsApp();

        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isOpen) {
                toggleChat();
            }
        });

        // Rehydrate chat state from sessionStorage (persists across page navigation
        // within the same tab).
        const saved = loadChatState();
        if (saved && Array.isArray(saved.history) && saved.history.length > 0) {
            if (saved.sessionId) sessionId = saved.sessionId;
            chatHistory = saved.history;
            leadCapturedEmail = saved.leadCapturedEmail || null;
            liveMode = !!saved.liveMode;
            liveLastMessageId = saved.liveLastMessageId || null;
            if (liveMode) {
                // Restarting poll after page navigation — enterLiveMode is
                // idempotent, so we flip the flag back off first to let it
                // rebuild the banner + timer cleanly.
                liveMode = false;
                enterLiveMode();
            }

            // Remove the static welcome message — replay the real conversation.
            messages.innerHTML = '';
            chatHistory.forEach(function(msg) {
                addMessage(
                    msg.content,
                    msg.role === 'user' ? 'user' : 'bot',
                    msg.citations || null,
                    msg.products || null
                );
            });

            // Re-open the chat if the visitor had it open when they navigated.
            if (saved.isOpen) {
                toggleChat();
            }
        }

        // A reload mid-form: bring the gate back (after the transcript replay
        // above, which rebuilds the panel).
        if (chatGateRestore) chatGateRestore();

        // Presence: heartbeat while visible + leave beacon on unload.
        // This only matters once there's a conversation (so we gate
        // every tick on having a sessionId + backend-acknowledged).
        if (document.visibilityState === 'visible') {
            startHeartbeat();
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                startHeartbeat();
            } else {
                stopHeartbeat();
            }
        });
        // pagehide covers mobile Safari + back/forward cache cases where
        // beforeunload is flaky. Using both is safe — idempotent on the server.
        //
        // The pagehide listener gates on `!event.persisted`: pagehide fires
        // on every internal navigation too (the previous page is being
        // unloaded for the new one), but `persisted=true` means the
        // browser may restore this page from bfcache, so the visitor
        // hasn't truly left — they could come back via Back. Only fire
        // the leave beacon on real unloads (tab close, off-site nav,
        // hard reload). Without this guard, every same-origin click
        // marked the conversation as "Ended" prematurely.
        window.addEventListener('beforeunload', sendLeaveBeacon);
        window.addEventListener('pagehide', function (ev) {
            if (ev && ev.persisted) return;
            sendLeaveBeacon();
        });
    }

    function startHeartbeat() {
        if (heartbeatTimer) return;
        heartbeatTimer = setInterval(function () {
            if (!sessionId || document.visibilityState !== 'visible') return;
            fetch(config.restUrl + '/visitor-heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify({ sessionId: sessionId }),
                keepalive: true,
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (json) {
                    // Heartbeat response carries the conversation's current
                    // live_mode flag. If an admin took the thread over on the
                    // dashboard, we pick it up here within one interval and
                    // flip the widget into live mode without waiting for the
                    // visitor's next message.
                    if (json && json.success && json.data && json.data.liveMode && !liveMode) {
                        enterLiveMode();
                    }
                })
                .catch(function () { /* silent */ });
        }, HEARTBEAT_INTERVAL_MS);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    function sendLeaveBeacon() {
        if (!sessionId) return;
        try {
            const body = JSON.stringify({ sessionId: sessionId });
            // sendBeacon survives the tab closing without us waiting for the
            // ack. Falls back to fetch+keepalive where sendBeacon isn't
            // available (very old browsers).
            if (navigator.sendBeacon) {
                navigator.sendBeacon(
                    config.restUrl + '/visitor-left',
                    new Blob([body], { type: 'application/json' })
                );
            } else {
                fetch(config.restUrl + '/visitor-left', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                    body: body,
                    keepalive: true,
                }).catch(function () { /* silent */ });
            }
        } catch (e) {
            // Unload path — never throw.
        }
    }


    /**
     * Language picker. The globe shows only when the owner offers more than one
     * language (config.languages). The pick is kept per visitor and sent with
     * every chat message (the default reply language) and with a call start
     * (binding). Logic lives in widget-language.js (unit tested).
     */
    var languageControl = null;
    // The live voice call, so closing the chat can hang up (set in attachVoiceCall).
    var voiceCall = null;

    // Renders (or re-renders) the chip bar from a list of { label, message }.
    // First paint uses the owner's list; a language pick calls this again
    // with the picked language's questions.
    function renderChips(list) {
        if (chipBar && chipBar.parentNode) chipBar.parentNode.removeChild(chipBar);
        chipBar = null;
        if (!messages || !Array.isArray(list) || list.length === 0) return;
        const bar = document.createElement('div');
        bar.className = 'bitbot-chips';
        bar.setAttribute('role', 'group');
        bar.setAttribute('aria-label', 'Suggested questions');
        list.forEach(function(q) {
            if (!q || (!q.label && !q.message)) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bitbot-chip';
            btn.textContent = q.label || q.message;
            btn.addEventListener('click', function() {
                const payload = (q.message || q.label || '').trim();
                // A chip is a first message too: the pre-chat gate takes it.
                if (payload && chatGateHold && chatGateHold(payload)) return;
                // Hide entire chip bar on the first click.
                bar.style.display = 'none';
                if (payload) {
                    addMessage(payload, 'user');
                    chatHistory.push({ role: 'user', content: payload });
                    sendMessage(payload);
                }
            });
            bar.appendChild(btn);
        });
        messages.appendChild(bar);
        chipBar = bar;
    }

    function attachLanguage() {
        const L = window.BitBotLanguage;
        const wrap = widgetRoot.getElementById('bitbot-lang');
        const btn = widgetRoot.getElementById('bitbot-lang-btn');
        const menu = widgetRoot.getElementById('bitbot-lang-menu');
        if (!L || !wrap || !btn || !menu) return;
        let storage = null;
        try { storage = window.localStorage; } catch (e) { storage = null; }
        const control = L.createLanguageControl({
            languages: config.languages,
            siteCode: config.languageCode,
            storage: storage
        });
        if (!control.isEnabled()) return;
        languageControl = control;

        // Title and welcome message follow the pick. The base text is what the
        // page rendered; the welcome bubble only changes while nothing has
        // been sent, so a running conversation is never rewritten.
        const titleEl = widgetRoot.querySelector('.bitbot-title');
        const pillLabelEl = widgetRoot.querySelector('.bitbot-pill-label');
        const welcomeEl = widgetRoot.querySelector('.bitbot-welcome .bitbot-message-content');
        const base = {
            title: titleEl ? titleEl.textContent : '',
            welcome: welcomeEl ? welcomeEl.textContent.trim() : '',
            questions: suggestedQuestions.map(function (q) { return q && (q.label || q.message) || ''; }),
            placeholder: input ? (input.getAttribute('placeholder') || '') : ''
        };
        function applyCopy() {
            const c = L.copyFor(control.current(), config.copy, base);
            if (titleEl && c.title) titleEl.textContent = c.title;
            if (pillLabelEl && c.title) pillLabelEl.textContent = c.title;
            if (input && c.placeholder) input.setAttribute('placeholder', c.placeholder);
            if (welcomeEl && c.welcome && chatHistory.length === 0 && welcomeEl.isConnected) {
                welcomeEl.textContent = c.welcome;
            }
            if (chatHistory.length === 0 && c.questions.length > 0) {
                // Same renderer as first paint. A question equal to a base chip
                // label keeps that chip's message; others send their own text.
                renderChips(c.questions.map(function (text) {
                    const hit = suggestedQuestions.filter(function (q) { return q && q.label === text; })[0];
                    return hit || { label: text, message: text };
                }));
            }
        }
        applyCopy();

        function setOpen(open) {
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        const codeEl = widgetRoot.getElementById('bitbot-lang-code');

        function render() {
            menu.textContent = '';
            const active = control.current();
            // The pill shows the current pick as its code (EN, HI); the menu
            // carries the native names. A flag would be wrong: six of the
            // languages on offer share one flag.
            if (codeEl) codeEl.textContent = active.toUpperCase();
            control.options().forEach(function (lang) {
                const li = document.createElement('li');
                li.setAttribute('role', 'presentation');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'bitbot-lang-item';
                item.setAttribute('dir', 'auto');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', lang.code === active ? 'true' : 'false');
                item.lang = lang.code;
                item.textContent = lang.native || lang.name || lang.code;
                item.addEventListener('click', function () {
                    control.pick(lang.code);
                    applyCopy();
                    render();
                    btn.title = control.label(control.current());
                    setOpen(false);
                    btn.focus();
                });
                li.appendChild(item);
                menu.appendChild(li);
            });
        }

        render();
        btn.title = control.label(control.current());
        wrap.hidden = false;
        btn.addEventListener('click', function () { setOpen(menu.hidden); });
        // Click elsewhere in the chat, or Escape, closes the menu.
        chat.addEventListener('click', function (e) {
            if (!menu.hidden && !wrap.contains(e.target)) setOpen(false);
        });
        wrap.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !menu.hidden) {
                e.stopPropagation();
                setOpen(false);
                btn.focus();
            }
        });
        // The pick binds a call when it starts, so it cannot change mid-call.
        languageLockHook = function (locked) {
            btn.disabled = locked;
            if (locked) setOpen(false);
        };
    }

    var languageLockHook = null;

    function currentLanguagePick() {
        return languageControl ? languageControl.current() : '';
    }

    /**
     * WhatsApp click-to-chat. The header button shows only when the owner set a
     * number and turned the switch on (config.whatsappNumber, digits). The
     * pre-filled text names the site. Logic lives in widget-whatsapp.js
     * (unit tested).
     */
    function attachWhatsApp() {
        const wa = widgetRoot.getElementById('bitbot-whatsapp');
        const W = window.BitBotWhatsApp;
        if (!wa || !W || !W.digitsOnly(config.whatsappNumber)) return;
        wa.hidden = false;
        wa.href = W.headerLink(config.whatsappNumber, config.siteName);
    }

    /** Add the inline WhatsApp button to the bot reply just rendered, when it applies. */
    function maybeAddWhatsAppInline(visitorMessage, reply) {
        const W = window.BitBotWhatsApp;
        if (!W || !W.shouldOfferInline({
            number: config.whatsappNumber,
            suggestContact: !!reply.suggest_contact,
            liveMode: !!reply.live_mode,
            visitorMessage: visitorMessage,
        })) return;
        const last = messages.lastElementChild;
        if (!last || !last.classList.contains('bitbot-bot')) return;
        const a = document.createElement('a');
        a.className = 'bitbot-whatsapp-inline';
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.href = W.questionLink(config.whatsappNumber, config.siteName, visitorMessage);
        a.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm4.52 11.99c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.87.85-.87 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.29z"/></svg><span>Chat with us on WhatsApp</span>';
        last.appendChild(a);
        scrollToBottom();
    }

    /**
     * Voice calls (Habitat). Shown only when the site owner turned the
     * "Voice calls" switch on (config.voiceCallsEnabled). The call logic lives
     * in widget-voice.js (unit tested); this wires it to the widget's DOM and
     * reuses addMessage() so call lines look like every other message.
     */
    function attachVoiceCall() {
        const callBtn = widgetRoot.getElementById('bitbot-call');
        const bar = widgetRoot.getElementById('bitbot-call-bar');
        const statusEl = widgetRoot.getElementById('bitbot-call-status');
        const hangupBtn = widgetRoot.getElementById('bitbot-hangup');
        if (!config.voiceCallsEnabled || !window.BitBotVoice || !callBtn || !bar || !hangupBtn) return;

        // 'thinking' is ours (widget-voice.js): the visitor has finished and the
        // answer is on its way, so the caller can tell the turn has ended.
        const STATE_WORDS = { connecting: 'Connecting...', listening: 'Listening', thinking: 'Thinking...', speaking: 'Talking' };
        const lineEls = {};
        const lineHistoryIdx = {};
        const lineCitations = {};

        function loadScript(url) {
            return new Promise(function (resolve, reject) {
                const el = document.createElement('script');
                el.src = url;
                el.async = true;
                el.onload = function () { resolve(); };
                el.onerror = function () { reject(new Error('voice script failed to load')); };
                document.head.appendChild(el);
            });
        }

        const call = window.BitBotVoice.createVoiceCall({
            restUrl: config.restUrl,
            getVisitorId: getVisitorId,
            getLanguage: currentLanguagePick,
            fetch: function (url, init) { return window.fetch(url, init); },
            loadScript: loadScript,
            getHabitatVoice: function () { return window.HabitatVoice; },
            // Links of the answer just spoken, from the site's REST route. The
            // caller treats any failure as "nothing to show".
            fetchLinks: function (sessionId, afterTurnId) {
                const url = config.restUrl.replace(/\/+$/, '') + '/voice-call/links?session=' + encodeURIComponent(sessionId) +
                    (afterTurnId ? '&after=' + encodeURIComponent(afterTurnId) : '') +
                    // A page cache must never replay an earlier (empty) reply for the same URL.
                    '&_=' + Date.now();
                return window.fetch(url, { cache: 'no-store' }).then(function (res) { return res.json(); }).then(function (json) {
                    return json && json.success && json.data ? json.data : null;
                });
            },
            // The welcome line for the visitor's language: the call opens by
            // speaking it, and the bubble is already on screen.
            getGreeting: function () {
                const L = window.BitBotLanguage;
                const welcomeEl = widgetRoot.querySelector('.bitbot-welcome .bitbot-message-content');
                const shown = welcomeEl ? welcomeEl.textContent.trim() : '';
                if (L && languageControl && config.copy) {
                    const c = L.copyFor(languageControl.current(), config.copy, { welcome: shown });
                    if (c && c.welcome) return c.welcome;
                }
                return shown;
            },
            ui: {
                setState: function (state) { statusEl.textContent = STATE_WORDS[state] || ''; },
                setInCall: function (inCall) {
                    bar.hidden = !inCall;
                    callBtn.disabled = inCall;
                    if (languageLockHook) languageLockHook(inCall);
                    if (!inCall) statusEl.textContent = '';
                },
                showError: function (text) { addMessage(text, 'bot'); },
                upsertLine: function (key, role, text, final) {
                    const sender = role === 'bot' ? 'bot' : 'user';
                    let el = lineEls[key];
                    if (!el) {
                        addMessage(text, sender);
                        el = messages.lastElementChild;
                        lineEls[key] = el;
                    } else {
                        const content = el.querySelector('.bitbot-message-content');
                        if (content) content.textContent = text;
                        scrollToBottom();
                    }
                    if (el) el.classList.toggle('bitbot-interim', !final);
                    if (final) {
                        // A streamed answer finalises one sentence at a time under
                        // the same key: the bubble and its history entry grow in
                        // place rather than repeating.
                        const entry = { role: sender === 'bot' ? 'assistant' : 'user', content: text };
                        if (lineCitations[key]) entry.citations = lineCitations[key];
                        if (lineHistoryIdx[key] !== undefined && chatHistory[lineHistoryIdx[key]]) {
                            chatHistory[lineHistoryIdx[key]] = entry;
                        } else {
                            lineHistoryIdx[key] = chatHistory.push(entry) - 1;
                        }
                        saveChatState();
                    }
                },
                attachCitations: function (key, citations) {
                    const el = lineEls[key];
                    if (!el || !citations || citations.length === 0) return;
                    const old = el.querySelector('.bitbot-citations');
                    if (old) old.remove();
                    el.insertAdjacentHTML('beforeend', citationsHtml(citations));
                    lineCitations[key] = citations;
                    const idx = lineHistoryIdx[key];
                    if (idx !== undefined && chatHistory[idx]) {
                        chatHistory[idx] = Object.assign({}, chatHistory[idx], { citations: citations });
                        saveChatState();
                    }
                    scrollToBottom();
                }
            }
        });

        voiceCall = call;
        callBtn.hidden = false;
        callBtn.addEventListener('click', function () { call.start(); });
        hangupBtn.addEventListener('click', function () { call.hangUp(); });
        // Leaving the page ends the call; do not leave a live mic behind.
        window.addEventListener('pagehide', function () { if (call.isActive()) call.hangUp(); });
    }

    /**
     * Toggle chat window
     */
    function toggleChat() {
        isOpen = !isOpen;

        const iconChat = toggle.querySelector('.bitbot-icon-chat');
        const iconClose = toggle.querySelector('.bitbot-icon-close');
        var stateRoot = widgetRoot.querySelector('.bitbot-widget');
        if (stateRoot) stateRoot.classList.toggle('bitbot-open', isOpen);

        if (isOpen) {
            chat.style.display = 'flex';
            if (iconChat) iconChat.style.setProperty('display', 'none', 'important');
            if (iconClose) iconClose.style.display = 'block';
            if (input) input.focus();
        } else {
            // Closing the chat ends a live call: a hidden widget must never keep a microphone open.
            if (voiceCall && voiceCall.isActive()) voiceCall.hangUp();
            chat.style.display = 'none';
            if (iconChat) iconChat.style.removeProperty('display');
            if (iconClose) iconClose.style.display = 'none';
        }

        saveChatState();
    }

    /**
     * Handle form submission
     */
    function handleSubmit(e) {
        e.preventDefault();

        const message = input.value.trim();
        if (!message || isLoading) return;

        // Add user message
        addMessage(message, 'user');
        chatHistory.push({ role: 'user', content: message });
        saveChatState();

        // Clear input
        input.value = '';

        // Send to API
        sendMessage(message);
    }

    /**
     * Send message to API
     */
    async function sendMessage(message) {
        isLoading = true;
        showTypingIndicator();

        try {
            const history = chatHistory.slice(-CHAT_SEND_MAX);
            const common = {
                message: message,
                history: history,
                sessionId: getSessionId(),
                visitorId: getVisitorId(),
                // 1.7.0: visitor's IANA timezone so appointment slots +
                // confirmations render in their local time rather than the
                // site owner's.
                visitorTimezone: getVisitorTimezone(),
                siteLanguage: config.languageCode || 'en',  // Pass site language
                // The visitor's pick (only when the owner offers a choice):
                // the default reply language. '' means no pick.
                language: currentLanguagePick()
            };

            // Streamed answer straight from the backend. Anything short of a
            // settled answer (or a failure after text was shown) falls through
            // to the REST call below, silently.
            let data = null;
            if (canStream()) {
                const streamed = await streamChat(common);
                if (streamed.kind === 'done') {
                    data = { success: true, data: streamed.data };
                } else if (streamed.kind === 'error') {
                    data = { success: false, error: streamed.error };
                }
            }

            if (!data) {
                const response = await fetch(config.restUrl + '/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(Object.assign({}, common, {
                        // 1.9.0 Feature 8i custom copy bundle (server-side
                        // consumers). Empty strings tell the backend to use
                        // built-in agent defaults. Backend ignores unknown
                        // fields, so pre-1.9 deploys are forward-compatible.
                        fallbackMessage: (config.fallbackMessage || '').trim(),
                        leadCapturePrompt: (config.leadCapturePrompt || '').trim()
                    }))
                });

                data = await response.json();
            }

            hideTypingIndicator();
            removeStreamBubble();

            if (data.success) {
                // Only render an agent-side message if the backend actually
                // sent one. In live mode the backend returns { message: '' }
                // because the agent stepped aside.
                if (data.data.message && data.data.message.trim() !== '') {
                    addMessage(data.data.message, 'bot', data.data.citations, data.data.products);
                    // WhatsApp: when the bot could not answer, the chat was handed
                    // to a person, or the visitor asked for one, the reply carries
                    // the same button, pre-filled with the visitor's own question.
                    maybeAddWhatsAppInline(message, data.data);
                    // Persist citations + product cards alongside the
                    // text so back-nav rehydration restores the full
                    // bubble, not just the prose. Both fields are
                    // optional + small (<= a few KB each); the
                    // CHAT_LOCAL_MAX cap still bounds total state size.
                    chatHistory.push({
                        role: 'assistant',
                        content: data.data.message,
                        citations: data.data.citations || null,
                        products: data.data.products || null,
                    });
                }
                saveChatState();

                // Show lead captured confirmation
                if (data.data.lead_captured && !leadCapturedEmail) {
                    var emailMatch = message.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/);
                    leadCapturedEmail = emailMatch ? emailMatch[0] : true;
                    saveChatState();
                    var confirmDiv = document.createElement('div');
                    confirmDiv.className = 'bitbot-lead-confirmed';
                    confirmDiv.innerHTML = '<span class="bitbot-lead-check">&#10003;</span> We\'ll follow up' +
                        (typeof leadCapturedEmail === 'string' ? ' at ' + escapeHtml(leadCapturedEmail) : '') + '.';
                    messages.appendChild(confirmDiv);
                    scrollToBottom();
                }

                // Live-handover detection: enter live mode as soon as backend
                // flips to it (either because this turn triggered it via the
                // agent, OR because a prior turn did and we're already there).
                if (data.data.live_mode && !liveMode) {
                    enterLiveMode();
                }
            } else {
                var errMsg = (data && typeof data.error === 'string' && data.error.trim())
                    ? data.error
                    : 'Sorry, I encountered an error. Please try again.';
                // Backend says the bot can't reply right now. Always
                // hide the widget for the rest of this session rather
                // than pretend with degraded copy.
                //
                //   ACCESS_PAUSED:  — generic "bot is paused" (new in
                //                    2.0.119; covers expired sub and
                //                    Free chat-cap)
                //   CHAT_CAP:       — legacy Free monthly cap (pre-2.0.119)
                //   TRIAL_EXPIRED:  — legacy 30-day trial (pre-freemium)
                //
                // We promise the visitor an answer or we hide; we never
                // ask for an email we can't act on. Resets next session.
                if (
                    errMsg.indexOf('ACCESS_PAUSED:') === 0 ||
                    errMsg.indexOf('CHAT_CAP:') === 0 ||
                    errMsg.indexOf('TRIAL_EXPIRED:') === 0
                ) {
                    hideWidgetForSession();
                    return;
                }
                addMessage(errMsg, 'bot');
            }
        } catch (error) {
            hideTypingIndicator();
            removeStreamBubble();
            addMessage('Sorry, I couldn\'t connect to the server. Please try again later.', 'bot');
        }

        isLoading = false;
    }

    // ---- Streamed answers (logic in widget-stream.js) ----------------------

    /** True when this browser and this config can take the streamed answer. */
    function canStream() {
        return !!(window.BitBotStream && config.streamUrl && config.streamTicket &&
            typeof window.ReadableStream !== 'undefined' && typeof window.TextDecoder !== 'undefined');
    }

    /** Asks the uncacheable config endpoint for a fresh ticket. Resolves true when it got one. */
    function refreshStreamTicket() {
        var base = (config.restUrl || '').replace(/\/+$/, '');
        if (!base) return Promise.resolve(false);
        return fetch(base + '/widget-config?_=' + Date.now(), { cache: 'no-store', credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (fresh) {
                if (!fresh || !fresh.streamTicket) return false;
                config.streamTicket = fresh.streamTicket;
                if (fresh.streamUrl) config.streamUrl = fresh.streamUrl;
                return true;
            })
            .catch(function () { return false; });
    }

    /**
     * Streams one answer. Resolves { kind: 'done', data } with the settled
     * answer, { kind: 'error', error } when the turn failed after text was
     * shown, or { kind: 'fallback' } when nothing was shown and the REST call
     * should answer instead. An expired ticket is refreshed once first.
     */
    async function streamChat(common) {
        var outcome = await streamOnce(common);
        if (outcome.kind === 'expired') {
            outcome = (await refreshStreamTicket()) ? await streamOnce(common) : outcome;
        }
        return outcome.kind === 'expired' ? { kind: 'fallback' } : outcome;
    }

    async function streamOnce(common) {
        var Stream = window.BitBotStream;
        var response;
        try {
            response = await fetch(config.streamUrl, {
                method: 'POST',
                mode: 'cors',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/x-ndjson' },
                body: JSON.stringify({
                    ticket: config.streamTicket,
                    message: common.message,
                    history: common.history,
                    sessionId: common.sessionId,
                    visitorId: common.visitorId,
                    visitorTimezone: common.visitorTimezone,
                    language: common.language,
                    siteLanguage: common.siteLanguage
                })
            });
        } catch (e) {
            return { kind: 'fallback' };
        }
        if (response.status === 401) return { kind: 'expired' };
        if (!response.ok || !response.body || typeof response.body.getReader !== 'function' ||
            !Stream.isNdjson(response.headers.get('Content-Type'))) {
            return { kind: 'fallback' };
        }

        var assembler = Stream.createAssembler();
        try {
            await Stream.consume(response.body.getReader(), new TextDecoder(), function (record) {
                var action = assembler.apply(record);
                if (action === 'text') {
                    showStreamText(assembler.state.text);
                } else if (action === 'reset') {
                    resetStreamBubble();
                }
            });
        } catch (e) {
            if (streamBubble) throw e;
            return { kind: 'fallback' };
        }

        if (assembler.state.done) return { kind: 'done', data: assembler.state.data };
        if (assembler.state.error !== null && streamBubble) return { kind: 'error', error: assembler.state.error };
        if (streamBubble) throw new Error('stream ended early');
        return { kind: 'fallback' };
    }

    /** Shows the text so far in the streaming bubble, creating it on the first text. */
    function showStreamText(text) {
        if (!streamBubble) {
            hideTypingIndicator();
            streamBubble = document.createElement('div');
            streamBubble.className = 'bitbot-message bitbot-bot';
            streamContent = document.createElement('div');
            streamContent.className = 'bitbot-message-content';
            // Plain text while it streams; the settled answer is rendered with markdown.
            streamContent.style.whiteSpace = 'pre-wrap';
            streamBubble.appendChild(streamContent);
            messages.appendChild(streamBubble);
        }
        streamContent.textContent = text;
        scrollToBottom();
    }

    /** The text so far is not the answer (the agent moved on to a tool): back to the typing dots. */
    function resetStreamBubble() {
        removeStreamBubble();
        if (!widgetRoot.getElementById('bitbot-typing-indicator')) showTypingIndicator();
    }

    function removeStreamBubble() {
        if (streamBubble && streamBubble.parentNode) streamBubble.parentNode.removeChild(streamBubble);
        streamBubble = null;
        streamContent = null;
    }

    /**
     * Hide the widget for the rest of THIS page view when a chat reply comes
     * back paused (expired sub or Free chat cap) — avoids leaving a dead bot
     * on screen after the visitor already hit the wall. No client flag: on the
     * next page load the server-driven config.accessPaused (refreshed within
     * ~5 min) decides whether the FAB renders, so it reappears on its own once
     * the cap lifts.
     */
    function hideWidgetForSession() {
        var host = document.getElementById('bitbot-widget');
        if (host) host.style.display = 'none';
    }

    /**
     * Enter live-handover mode. Starts polling for admin replies and shows
     * an indicator banner so the visitor knows they're now talking to a
     * person. Idempotent.
     *
     * @since 1.7.0
     */
    function enterLiveMode() {
        if (liveMode) return;
        liveMode = true;
        saveChatState();

        var banner = document.createElement('div');
        banner.className = 'bitbot-live-banner';
        banner.style.cssText = 'padding: 8px 12px; margin: 8px 0; background: #e7f5ea; border-left: 3px solid #46b450; border-radius: 4px; font-size: 12px; color: #1a5d2f;';
        banner.innerHTML = '<strong>Live:</strong> You\'re now chatting with someone from the team.';
        messages.appendChild(banner);
        scrollToBottom();

        if (livePollTimer) clearInterval(livePollTimer);
        livePollTimer = setInterval(pollLiveUpdates, 5000);
    }

    /**
     * Exit live mode — admin closed the thread, agent resumes.
     *
     * @since 1.7.0
     */
    function exitLiveMode(reason) {
        if (!liveMode) return;
        liveMode = false;
        if (livePollTimer) { clearInterval(livePollTimer); livePollTimer = null; }
        saveChatState();

        var banner = document.createElement('div');
        banner.className = 'bitbot-live-banner';
        banner.style.cssText = 'padding: 8px 12px; margin: 8px 0; background: #f4f4f5; border-left: 3px solid #888; border-radius: 4px; font-size: 12px; color: #555;';
        banner.innerHTML = reason || 'The team has handed things back to the assistant.';
        messages.appendChild(banner);
        scrollToBottom();
    }

    /**
     * Poll the plugin's REST bridge for new admin messages + live_mode state.
     *
     * @since 1.7.0
     */
    async function pollLiveUpdates() {
        if (!liveMode) return;
        try {
            const res = await fetch(config.restUrl + '/live-updates', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    sessionId: getSessionId(),
                    sinceMessageId: liveLastMessageId || '',
                }),
            });
            const data = await res.json();
            if (!data || !data.success || !data.data) return;

            var payload = data.data;
            var newOnes = payload.newMessages || [];
            for (var i = 0; i < newOnes.length; i++) {
                addMessage(newOnes[i].content, 'admin');
                chatHistory.push({ role: 'assistant', content: newOnes[i].content });
                liveLastMessageId = newOnes[i].id;
            }
            if (newOnes.length > 0) saveChatState();

            if (!payload.liveMode) {
                exitLiveMode('The team has handed things back to the assistant. Ask anything!');
            }
        } catch (e) {
            // swallow — next tick retries
        }
    }

    /**
     * Simple markdown to HTML conversion
     */
    function parseMarkdown(text) {
        return text
            // Escape HTML first
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            // Markdown links [text](url) — do this BEFORE auto-linking so we
            // don't double-wrap the URL portion of a markdown link.
            .replace(
                /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>'
            )
            // Bare URLs not already inside an href="..." — auto-link them so
            // `Visit https://example.com` renders as a clickable link.
            .replace(
                /(^|[^"'>=])(https?:\/\/[^\s<)]+)/g,
                '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>'
            )
            // Bold **text**
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic *text*
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            // Line breaks
            .replace(/\n/g, '<br>')
            // Lists - lines starting with -
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
            // Clean up multiple <ul> tags
            .replace(/<\/ul>\s*<ul>/g, '');
    }

    /**
     * "Related pages" chips under a bot bubble. Shared by typed answers
     * (addMessage) and spoken answers (voice attachCitations).
     */
    function citationsHtml(citations) {
        if (!citations || citations.length === 0) return '';
        let html = '<div class="bitbot-citations">';
        html += '<div class="bitbot-citations-title">Related pages:</div>';

        citations.forEach(function(citation) {
            let url = citation.url;
            if (citation.anchorId) {
                url += '#' + citation.anchorId;
            }
            html += '<a href="' + escapeHtml(url) + '" class="bitbot-citation-link" target="_blank" rel="noopener noreferrer">';
            html += escapeHtml(citation.title || citation.url);
            html += '</a>';
        });

        return html + '</div>';
    }

    /**
     * Add message to chat
     */
    function addMessage(content, sender, citations, products) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'bitbot-message bitbot-' + sender;

        // Admin messages (live-handover): render with a distinct label so the
        // visitor clearly knows a person wrote this, not the agent.
        if (sender === 'admin') {
            const label = document.createElement('div');
            label.className = 'bitbot-message-label';
            label.style.cssText = 'font-size: 10px; font-weight: 600; color: #46b450; text-transform: uppercase; margin-bottom: 2px;';
            label.textContent = 'Team';
            messageDiv.appendChild(label);
        }

        // Use markdown parsing for bot + admin messages (both may format), escape for user
        const formattedContent = (sender === 'bot' || sender === 'admin') ? parseMarkdown(content) : escapeHtml(content);
        let html = '<div class="bitbot-message-content">' + formattedContent + '</div>';

        // Add citations if present
        html += citationsHtml(citations);

        // Product cards from the Pro+ agent's `searchProducts` tool. Rendered
        // as a horizontal card strip so the visitor sees image + price + CTA
        // the same way they would on a catalogue page, instead of reading the
        // agent's inline markdown links.
        if (products && products.length > 0) {
            html += '<div class="bitbot-product-cards">';
            products.forEach(function (p) {
                const name = escapeHtml(p.name || '');
                const url = escapeHtml(p.url || '#');
                const image = p.imageUrl ? escapeHtml(p.imageUrl) : '';
                const blurb = escapeHtml((p.shortDescription || '').slice(0, 140));
                const rawPrice = p.onSale && p.salePrice ? p.salePrice : p.price;
                const hasStrike = p.onSale && p.salePrice && p.price;
                const price = rawPrice ? escapeHtml(rawPrice) : '';
                const oldPrice = hasStrike ? escapeHtml(p.price) : '';
                const outOfStock = p.inStock === false;

                html += '<a class="bitbot-product-card" href="' + url + '" target="_blank" rel="noopener noreferrer">';
                if (image) {
                    html += '<div class="bitbot-product-image" style="background-image: url(\'' + image + '\')"></div>';
                } else {
                    html += '<div class="bitbot-product-image bitbot-product-image-empty"></div>';
                }
                html += '<div class="bitbot-product-body">';
                html += '<div class="bitbot-product-name">' + name + '</div>';
                if (blurb) {
                    html += '<div class="bitbot-product-blurb">' + blurb + '</div>';
                }
                html += '<div class="bitbot-product-foot">';
                if (price) {
                    html += '<span class="bitbot-product-price">';
                    if (oldPrice) {
                        html += '<span class="bitbot-product-old-price">$' + oldPrice + '</span> ';
                    }
                    html += '$' + price + '</span>';
                }
                if (outOfStock) {
                    html += '<span class="bitbot-product-oos">Out of stock</span>';
                } else {
                    html += '<span class="bitbot-product-cta">View →</span>';
                }
                html += '</div></div></a>';
            });
            html += '</div>';
        }

        messageDiv.innerHTML = html;
        messages.appendChild(messageDiv);
        scrollToBottom();
    }

    /**
     * Show typing indicator. If the admin has set a custom typing string
     * in Settings (1.9.0 custom copy bundle), render that text in place
     * of the animated dots. Empty string → fall back to dots.
     */
    function showTypingIndicator() {
        const typingDiv = document.createElement('div');
        typingDiv.id = 'bitbot-typing-indicator';
        typingDiv.className = 'bitbot-message bitbot-bot';
        const customTypingText = (config.typingIndicatorText || '').trim();
        if (customTypingText) {
            const escaped = customTypingText
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            typingDiv.innerHTML = '<div class="bitbot-typing bitbot-typing-text">' + escaped + '</div>';
        } else {
            typingDiv.innerHTML = '<div class="bitbot-typing"><span></span><span></span><span></span></div>';
        }
        messages.appendChild(typingDiv);
        scrollToBottom();
    }

    /**
     * Hide typing indicator
     */
    function hideTypingIndicator() {
        const typing = widgetRoot.getElementById('bitbot-typing-indicator');
        if (typing) {
            typing.remove();
        }
    }

    /**
     * Scroll messages to bottom
     */
    function scrollToBottom() {
        if (messages) {
            messages.scrollTop = messages.scrollHeight;
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Refresh config past page caches, then initialize.
    // bitbotConfig is inlined into the page HTML via wp_localize_script for
    // first paint, but full-page caches (LiteSpeed/WP Rocket/CDN) freeze that
    // HTML — so a settings change (avatar, colour, copy) can be stale. We
    // fetch the live config from the uncacheable /widget-config endpoint with
    // a cache-buster and override the inlined values BEFORE rendering, so the
    // widget always reflects current settings regardless of any cache layer.
    // A 2.5s timeout guarantees the widget still renders (from the inlined
    // config) if the fetch is slow/blocked.
    function bootstrap() {
        var base = (config.restUrl || '').replace(/\/+$/, '');
        if (!base) { init(); return; }
        var started = false;
        var go = function () { if (!started) { started = true; init(); } };
        var timer = setTimeout(go, 2500);
        fetch(base + '/widget-config?_=' + Date.now(), { cache: 'no-store', credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (fresh) {
                if (fresh && typeof fresh === 'object') {
                    for (var k in fresh) {
                        if (!Object.prototype.hasOwnProperty.call(fresh, k)) continue;
                        // The page's own REST nonce stays: the runtime fetch is
                        // anonymous (credentials omitted), so its nonce is the
                        // logged-out one and would 403 an owner viewing their site.
                        if (k === 'nonce' && config.nonce) continue;
                        config[k] = fresh[k];
                    }
                }
            })
            .catch(function () { /* keep inlined config on any failure */ })
            .then(function () { clearTimeout(timer); go(); });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
