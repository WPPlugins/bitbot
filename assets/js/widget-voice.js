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

    // A call with no sound either way: caption at 45 s, hang up at 75 s.
    var IDLE_PROMPT_MS = 45000;
    var IDLE_HANGUP_MS = 75000;
    var IDLE_TICK_MS = 1000;
    var IDLE_INTERIM_MIN_CHARS = 12;

    var MESSAGES = {
        stillThere: 'Still there? The call ends in 30 seconds if it stays quiet.',
        idleEnded: 'The call ended because it went quiet.',
        busy: 'Call lines are busy, try again shortly.',
        unavailable: 'Voice calls are not available right now.',
        notOnSite: 'Voice calls are not available on this site.',
        couldNotStart: 'The call could not start. Please try again.',
        interrupted: 'The call was interrupted. Please try again.',
        micDenied: 'Microphone access is needed for a call. Allow it in your browser and try again.'
    };

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
     * @param {Object} deps.ui   setState, upsertLine, attachCitations, showError, setInCall
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
                    deps.ui.showError(MESSAGES.idleEnded);
                    return;
                }
                if (quiet >= IDLE_PROMPT_MS && !c.idlePrompted && c.rawState === 'listening' && !c.awaiting) {
                    c.idlePrompted = true;
                    deps.ui.upsertLine('idle-' + c.id, 'bot', MESSAGES.stillThere, true);
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
                    return deps.fetch(deps.restUrl.replace(/\/+$/, '') + '/voice-call/start', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(startBody())
                    }).then(function (res) { return res.json(); });
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

        return { start: start, hangUp: hangUp, isActive: function () { return cur !== null; } };
    }

    return { createVoiceCall: createVoiceCall, errorTextFor: errorTextFor, MESSAGES: MESSAGES, LINKS_SETTLE_MS: LINKS_SETTLE_MS, IDLE_PROMPT_MS: IDLE_PROMPT_MS, IDLE_HANGUP_MS: IDLE_HANGUP_MS };
}));
