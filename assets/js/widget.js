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
    let widget, toggle, chat, messages, form, input;
    let chatHistory = [];
    let isOpen = false;
    let isLoading = false;
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
        toggle = document.getElementById('bitbot-toggle');
        chat = document.getElementById('bitbot-chat');
        messages = document.getElementById('bitbot-messages');
        form = document.getElementById('bitbot-form');
        input = document.getElementById('bitbot-input');

        if (!widget || !toggle || !chat) {
            return;
        }

        // Auto-reposition if a 3rd-party widget (UserWay, Tawk, etc.) is
        // squatting on the configured corner. Run once now + once after a
        // delay because a lot of accessibility widgets inject themselves
        // post-load.
        autoPositionWidget();
        setTimeout(autoPositionWidget, 1500);

        // Event listeners
        toggle.addEventListener('click', toggleChat);

        const minimize = document.getElementById('bitbot-minimize');
        if (minimize) {
            minimize.addEventListener('click', toggleChat);
        }

        if (form) {
            form.addEventListener('submit', handleSubmit);
        }

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
        window.addEventListener('beforeunload', sendLeaveBeacon);
        window.addEventListener('pagehide', sendLeaveBeacon);
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
     * Toggle chat window
     */
    function toggleChat() {
        isOpen = !isOpen;

        const iconChat = toggle.querySelector('.bitbot-icon-chat');
        const iconClose = toggle.querySelector('.bitbot-icon-close');

        if (isOpen) {
            chat.style.display = 'flex';
            if (iconChat) iconChat.style.display = 'none';
            if (iconClose) iconClose.style.display = 'block';
            if (input) input.focus();
        } else {
            chat.style.display = 'none';
            if (iconChat) iconChat.style.display = 'block';
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
            const response = await fetch(config.restUrl + '/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message: message,
                    history: chatHistory.slice(-CHAT_SEND_MAX),
                    sessionId: getSessionId(),
                    visitorId: getVisitorId(),
                    // 1.7.0: visitor's IANA timezone so appointment slots +
                    // confirmations render in their local time rather than the
                    // site owner's.
                    visitorTimezone: getVisitorTimezone(),
                    siteLanguage: config.languageCode || 'en'  // Pass site language
                })
            });

            const data = await response.json();

            hideTypingIndicator();

            if (data.success) {
                // Only render an agent-side message if the backend actually
                // sent one. In live mode the backend returns { message: '' }
                // because the agent stepped aside.
                if (data.data.message && data.data.message.trim() !== '') {
                    addMessage(data.data.message, 'bot', data.data.citations, data.data.products);
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
                addMessage(errMsg, 'bot');
            }
        } catch (error) {
            hideTypingIndicator();
            addMessage('Sorry, I couldn\'t connect to the server. Please try again later.', 'bot');
        }

        isLoading = false;
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
        if (citations && citations.length > 0) {
            html += '<div class="bitbot-citations">';
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

            html += '</div>';
        }

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
     * Show typing indicator
     */
    function showTypingIndicator() {
        const typingDiv = document.createElement('div');
        typingDiv.id = 'bitbot-typing-indicator';
        typingDiv.className = 'bitbot-message bitbot-bot';
        typingDiv.innerHTML = '<div class="bitbot-typing"><span></span><span></span><span></span></div>';
        messages.appendChild(typingDiv);
        scrollToBottom();
    }

    /**
     * Hide typing indicator
     */
    function hideTypingIndicator() {
        const typing = document.getElementById('bitbot-typing-indicator');
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

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
