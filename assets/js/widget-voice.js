/**
 * BitBot widget — voice call logic.
 *
 * Pure logic behind the Call button, kept out of widget.js so it can be unit
 * tested in Node (tests/widget-voice.test.js). widget.js supplies the DOM
 * pieces through `deps.ui`; this file never touches the DOM.
 *
 * Flow: ask THIS SITE's REST route for a session (the Habitat key never reaches
 * the browser) -> load Habitat's voice-session.js once -> HabitatVoice.join().
 *
 * @package BitBot
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.BitBotVoice = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // After the last final bot sentence, wait this long before asking for links.
    var LINKS_SETTLE_MS = 1500;

    // A call with no sound either way: a counting-down caption from 30 s of
    // silence, hang up at 60 s (owner, 2026-11-05). Speech from either side
    // clears the caption and restarts the clock.
    var IDLE_PROMPT_MS = 30000;
    var IDLE_HANGUP_MS = 60000;
    var IDLE_TICK_MS = 1000;
    var IDLE_INTERIM_MIN_CHARS = 12;

    var MESSAGES = {
        stillThere: 'Still there? The call ends in {n} {unit} if it stays quiet.',
        idleEnded: 'The call ended because it went quiet.',
        busy: 'Call lines are busy, try again shortly.',
        unavailable: 'Voice calls are not available right now.',
        notOnSite: 'Voice calls are not available on this site.',
        couldNotStart: 'The call could not start. Please try again.',
        interrupted: 'The call was interrupted. Please try again.',
        micDenied: 'Microphone access is needed for a call. Allow it in your browser and try again.'
    };

    // The caption with the seconds left, re-drawn every second while quiet.
    function stillThereText(secondsLeft) {
        return MESSAGES.stillThere.replace('{n}', String(secondsLeft)).replace('{unit}', secondsLeft === 1 ? 'second' : 'seconds');
    }

    function errorTextFor(code) {
        switch (code) {
            case 'busy': return MESSAGES.busy;
            case 'payment':
            case 'credits':
            case 'auth': return MESSAGES.unavailable;
            case 'disabled':
            case 'not_configured': return MESSAGES.notOnSite;
            default: return MESSAGES.couldNotStart;
        }
    }

    function normaliseRole(role) {
        return (role === 'bot' || role === 'assistant') ? 'bot' : 'visitor';
    }

    // Letters and digits only, so a spoken line and its written source compare
    // despite punctuation, case and spacing.
    function normaliseText(text) {
        return String(text || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
    }

    /**
     * @param {Object} deps
     * @param {string} deps.restUrl
     * @param {function(): string} deps.getVisitorId
     * @param {function(): string} [deps.getLanguage]  the visitor's picked language, '' for none
     * @param {function(): string} [deps.getGreeting]  the welcome line already on screen; the call opens by speaking it, so its transcript is not drawn again
     * @param {function(string, Object): Promise} deps.fetch
     * @param {function(string): Promise} deps.loadScript   loads a script URL once per call to this
     * @param {function(): Object} deps.getHabitatVoice     returns window.HabitatVoice
     * @param {function(string, string): Promise} [deps.fetchLinks]  (sessionId, lastTurnId) -> {turnId, citations} or null; the citations of the answer just spoken
     * @param {Object} deps.ui   setState, upsertLine, attachCitations, showError, showNote, setInCall
     */
    function createVoiceCall(deps) {
        // Each Start creates a call record `c`. `cur` is the live one (or null).
        // End/hang up clears `cur` synchronously, so everything that was started
        // under an older call (awaits, HabitatVoice callbacks) sees `cur !== c`
        // and does nothing. UI state is only ever written for the live call.
        var cur = null;
        var seq = 0;
        var scriptPromises = {};
        // Resolves once the previous call's join (if any) has settled and been
        // cleaned up, so a new call never races an old join on the shared
        // HabitatVoice singleton.
        var settled = Promise.resolve();

        function loadOnce(url) {
            if (!scriptPromises[url]) {
                scriptPromises[url] = Promise.resolve(deps.loadScript(url)).catch(function (e) {
                    delete scriptPromises[url]; // allow a retry after a failed load
                    throw e;
                });
            }
            return scriptPromises[url];
        }

        function leaveQuietly() {
            var habitat = deps.getHabitatVoice();
            try {
                if (habitat && typeof habitat.leave === 'function') {
                    return Promise.resolve(habitat.leave()).catch(function () { /* best effort */ });
                }
            } catch (e) { /* best effort */ }
            return Promise.resolve();
        }

        // Ask for the links of the answer the bot just finished speaking and hang
        // them under its bubble. Never blocks the transcript; any failure is silent.
        function flushLinks(c) {
            if (c.linkTimer) { clearTimeout(c.linkTimer); c.linkTimer = null; }
            var turn = c.botTurn;
            if (!turn || turn.asked || !c.sessionId) return;
            if (typeof deps.fetchLinks !== 'function' || typeof deps.ui.attachCitations !== 'function') return;
            turn.asked = true;
            var key = turn.key;
            var after = c.lastTurnId || '';
            var asked;
            try { asked = Promise.resolve(deps.fetchLinks(c.sessionId, after)); } catch (e) { return; }
            asked.then(function (found) {
                if (!found || !found.turnId || found.turnId === c.lastTurnId) return;
                c.lastTurnId = found.turnId;
                if (Array.isArray(found.citations) && found.citations.length) {
                    deps.ui.attachCitations(key, found.citations);
                }
            }).catch(function () { /* silent */ });
        }

        function setTimer(fn, ms) {
            var t = deps.timers && deps.timers.setTimeout ? deps.timers.setTimeout : setTimeout;
            return t(fn, ms);
        }
        function clearTimer(id) {
            var t = deps.timers && deps.timers.clearTimeout ? deps.timers.clearTimeout : clearTimeout;
            t(id);
        }
        function nowMs() { return typeof deps.now === 'function' ? deps.now() : Date.now(); }

        function markActivity(c) {
            c.lastActivityAt = nowMs();
            if (c.idlePrompted && typeof deps.ui.removeLine === 'function') deps.ui.removeLine('idle-' + c.id);
            c.idlePrompted = false;
        }

        // One re-armed 1 s tick per call, only while listening has begun.
        function idleTick(c) {
            c.idleTimer = null;
            if (cur !== c) return;
            if (c.rawState === 'speaking') {
                markActivity(c);
            } else {
                var quiet = nowMs() - c.lastActivityAt;
                if (quiet >= IDLE_HANGUP_MS) {
                    hangUp();
                    // Not an error and not part of the conversation: a quiet note that fades away.
                    deps.ui.showNote(MESSAGES.idleEnded);
                    return;
                }
                if (quiet >= IDLE_PROMPT_MS && c.rawState === 'listening' && !c.awaiting) {
                    // Not final: the caption is a live countdown, never part of the transcript.
                    c.idlePrompted = true;
                    deps.ui.upsertLine('idle-' + c.id, 'bot', stillThereText(Math.max(1, Math.ceil((IDLE_HANGUP_MS - quiet) / 1000))), false);
                }
            }
            c.idleTimer = setTimer(function () { idleTick(c); }, IDLE_TICK_MS);
        }

        function startIdleWatch(c) {
            if (c.idleTimer || c.idleStarted) return;
            c.idleStarted = true;
            markActivity(c);
            c.idleTimer = setTimer(function () { idleTick(c); }, IDLE_TICK_MS);
        }

        function endCall(c) {
            if (cur !== c) return false;
            if (c.idleTimer) { clearTimer(c.idleTimer); c.idleTimer = null; }
            // The countdown is a live caption; it leaves with the call.
            if (c.idlePrompted && typeof deps.ui.removeLine === 'function') deps.ui.removeLine('idle-' + c.id);
            c.idlePrompted = false;
            flushLinks(c);
            cur = null;
            deps.ui.setInCall(false);
            return true;
        }

        function failCall(c, text) {
            if (endCall(c)) deps.ui.showError(text);
        }

        // The visitor's language pick binds the call; no pick sends nothing.
        function startBody() {
            var body = { visitorId: deps.getVisitorId() };
            var language = typeof deps.getLanguage === 'function' ? deps.getLanguage() : '';
            if (language) body.language = language;
            return body;
        }

        // The session request. Direct to the API with the page's ticket when the
        // config carries one (deps.startUrl + deps.getTicket): no hop through the
        // site's WordPress, which cost 3 to 4 s on cheap hosts. Anything that is
        // not a JSON answer from that route, or an invalid ticket, falls back to
        // the site's REST route; an expired ticket is refreshed once first.
        function viaRest(body) {
            return deps.fetch(deps.restUrl.replace(/\/+$/, '') + '/voice-call/start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (res) { return res.json(); });
        }
        function viaDirect(body, retried) {
            var payload = { ticket: deps.getTicket() };
            for (var k in body) if (Object.prototype.hasOwnProperty.call(body, k)) payload[k] = body[k];
            // The REST route builds the greeting on the server; here the widget sends
            // the welcome line already on screen and the language it is in.
            var greeting = typeof deps.getGreeting === 'function' ? deps.getGreeting() : '';
            if (greeting) payload.greeting = greeting;
            var greetingLanguage = body.language || (typeof deps.getSiteLanguage === 'function' ? deps.getSiteLanguage() : '');
            if (greetingLanguage) payload.greetingLanguage = greetingLanguage;
            var request;
            try {
                request = deps.fetch(deps.startUrl, {
                    method: 'POST',
                    mode: 'cors',
                    credentials: 'omit',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } catch (e) {
                return viaRest(body);
            }
            return Promise.resolve(request)
                .then(function (res) {
                    return Promise.resolve(res.json()).then(function (json) { return { status: res.status, json: json }; });
                })
                .then(function (r) {
                    if (r.status === 401) {
                        var expired = r.json && r.json.code === 'ticket_expired';
                        if (expired && !retried && typeof deps.refreshTicket === 'function') {
                            return Promise.resolve(deps.refreshTicket()).then(function (ok) { return ok ? viaDirect(body, true) : viaRest(body); });
                        }
                        return viaRest(body);
                    }
                    return r.json;
                }, function () { return viaRest(body); });
        }
        // Tells the API the browser is in the call (mode=voice-joined), so a
        // caller who has joined but not spoken yet is never swept as abandoned.
        // Fire-and-forget: nothing in the call waits on it.
        function reportJoined(c) {
            if (!c.sessionId || typeof deps.joinedUrl !== 'string' || !deps.joinedUrl || typeof deps.getTicket !== 'function') return;
            var ticket = deps.getTicket();
            if (!ticket) return;
            try {
                Promise.resolve(deps.fetch(deps.joinedUrl, {
                    method: 'POST',
                    mode: 'cors',
                    credentials: 'omit',
                    keepalive: true,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket: ticket, sessionId: c.sessionId })
                })).catch(function () { /* best effort */ });
            } catch (e) { /* best effort */ }
        }
        function requestSession() {
            var body = startBody();
            var direct = typeof deps.startUrl === 'string' && deps.startUrl && typeof deps.getTicket === 'function' && deps.getTicket();
            return direct ? viaDirect(body, false) : viaRest(body);
        }

        function start() {
            if (cur) return Promise.resolve();
            seq += 1;
            // `awaiting`: the visitor's utterance is final and the bot has not
            // replied yet. Habitat's states only know listening/speaking (from
            // who is audible), so between the two the bar would say "Listening"
            // while the answer is being worked out; the UI gets 'thinking' instead.
            var c = { id: seq, open: {}, lineSeq: 0, joining: false, joined: false, awaiting: false, botTurn: null, greetingLeft: '', sessionId: '', lastTurnId: '', linkTimer: null, rawState: '', lastActivityAt: 0, idlePrompted: false, idleTimer: null, idleStarted: false };
            cur = c;
            // The call opens by speaking the welcome line the visitor already
            // read. Its transcript is swallowed, sentence by sentence, until
            // the whole line has been heard; anything else is drawn as usual.
            if (typeof deps.getGreeting === 'function') {
                c.greetingLeft = normaliseText(deps.getGreeting());
            }
            deps.ui.setInCall(true);
            deps.ui.setState('connecting');

            function onTranscript(t) {
                if (cur !== c) return;
                if (!t || typeof t.text !== 'string' || !t.text.trim()) return;
                // A live microphone keeps producing short interim fragments
                // from room noise; those are not the visitor talking. Only a
                // final line, or an interim one long enough to be speech, keeps
                // the call alive.
                if (c.idleStarted && (t.final || t.text.trim().length >= IDLE_INTERIM_MIN_CHARS)) markActivity(c);
                var role = normaliseRole(t.role);
                var text = t.text;
                if (role === 'bot' && c.greetingLeft) {
                    var heard = normaliseText(text);
                    if (heard && c.greetingLeft.indexOf(heard) === 0) {
                        if (t.final) c.greetingLeft = c.greetingLeft.slice(heard.length);
                        c.awaiting = false;
                        return;
                    }
                    c.greetingLeft = '';
                }
                var key = c.open[role];
                if (!key && role === 'bot' && c.botTurn) {
                    // A streamed answer arrives one sentence at a time, each
                    // final. They are one answer, so they stay one bubble until
                    // the visitor speaks again.
                    key = c.botTurn.key;
                    text = c.botTurn.text + ' ' + text;
                    c.open[role] = key;
                }
                if (!key) {
                    c.lineSeq += 1;
                    key = 'voice-' + c.id + '-' + role + '-' + c.lineSeq;
                    c.open[role] = key;
                }
                deps.ui.upsertLine(key, role, text, !!t.final);
                if (t.final) delete c.open[role];
                if (role === 'bot') {
                    c.awaiting = false;
                    if (t.final) {
                        c.botTurn = { key: key, text: text, asked: false };
                        if (c.linkTimer) clearTimeout(c.linkTimer);
                        c.linkTimer = setTimeout(function () { flushLinks(c); }, LINKS_SETTLE_MS);
                    }
                } else {
                    flushLinks(c);
                    c.botTurn = null;
                    if (t.final) {
                        c.awaiting = true;
                        deps.ui.setState('thinking');
                    }
                }
            }

            function onState(state) {
                if (cur !== c) return;
                if (state === 'failed') { failCall(c, MESSAGES.interrupted); return; }
                c.rawState = state;
                if (state === 'speaking' && c.idleStarted) markActivity(c);
                if (state === 'listening') startIdleWatch(c);
                if (state !== 'listening') c.awaiting = false;
                deps.ui.setState(state === 'listening' && c.awaiting ? 'thinking' : state);
                if (state === 'ended') endCall(c);
            }

            var run = settled
                .then(function () {
                    if (cur !== c) return null;
                    return requestSession();
                })
                .then(function (json) {
                    if (cur !== c) return null;
                    if (!json || !json.success || !json.data) {
                        var e = new Error('start refused');
                        e.code = json && json.code;
                        throw e;
                    }
                    var session = json.data;
                    c.sessionId = session.session_id || '';
                    return loadOnce(session.script_url).then(function () { return session; });
                })
                .then(function (session) {
                    if (!session) return;
                    if (cur !== c) { return; }
                    var habitat = deps.getHabitatVoice();
                    if (!habitat || typeof habitat.join !== 'function') {
                        var e = new Error('voice script missing');
                        e.code = 'failed';
                        throw e;
                    }
                    c.joining = true;
                    return Promise.resolve(habitat.join({
                        livekit_url: session.livekit_url,
                        token: session.token,
                        onState: onState,
                        onTranscript: onTranscript,
                        onEnd: function () { endCall(c); }
                    })).then(
                        function () {
                            c.joining = false;
                            c.joined = true;
                            // End arrived while the join was underway: release the mic now.
                            if (cur !== c) return leaveQuietly();
                            reportJoined(c);
                        },
                        function (err) {
                            c.joining = false;
                            // A join that fails after End has nothing to clean up.
                            if (cur !== c) return;
                            throw err;
                        }
                    );
                })
                .catch(function (err) {
                    if (cur !== c) return;
                    if (err && err.name === 'NotAllowedError') { failCall(c, MESSAGES.micDenied); return; }
                    failCall(c, errorTextFor(err && err.code));
                });
            settled = run;
            return run;
        }

        function hangUp() {
            var c = cur;
            if (!c) return Promise.resolve();
            endCall(c); // synchronous: invalidates every pending step and callback of c
            // Mid-join: the join's own completion calls leave() (already inside `settled`).
            if (c.joining || !c.joined) return Promise.resolve();
            // Otherwise leave now, and make the next Start wait for the leave to finish.
            var leaving = leaveQuietly();
            settled = Promise.all([settled, leaving]);
            return leaving;
        }

        // Tell the API a visitor has opened the chat, once per page, so the instance that will start a call has
        // finished loading before they press Call (an idle instance took about six seconds longer). Nothing is
        // started and no answer is read. No Content-Type and no body: a plain request, with no CORS preflight.
        var warmed = false;
        function warm() {
            if (warmed || typeof deps.warmUrl !== 'string' || !deps.warmUrl) return;
            warmed = true;
            try {
                Promise.resolve(deps.fetch(deps.warmUrl, { method: 'POST', mode: 'cors', credentials: 'omit', keepalive: true })).catch(function () { /* best effort */ });
            } catch (e) { /* best effort */ }
        }

        return { start: start, hangUp: hangUp, warm: warm, isActive: function () { return cur !== null; } };
    }

    return { createVoiceCall: createVoiceCall, errorTextFor: errorTextFor, stillThereText: stillThereText, MESSAGES: MESSAGES, LINKS_SETTLE_MS: LINKS_SETTLE_MS, IDLE_PROMPT_MS: IDLE_PROMPT_MS, IDLE_HANGUP_MS: IDLE_HANGUP_MS };
}));
