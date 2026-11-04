/**
 * BitBot widget — language pick logic.
 *
 * Pure logic behind the globe button, kept out of widget.js so it can be unit
 * tested in Node (tests/widget-language.test.js). widget.js supplies the DOM;
 * this file never touches it.
 *
 * The owner's languages come from the site config (code, name, native name).
 * The visitor's pick is stored per browser. No pick means the site language.
 * With one language there is nothing to choose, so the control is off and no
 * language is sent anywhere.
 *
 * @package BitBot
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.BitBotLanguage = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var STORAGE_KEY = 'bitbot_language';

    function readStored(storage, key) {
        try {
            return storage ? storage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function writeStored(storage, key, value) {
        try {
            if (storage) storage.setItem(key, value);
        } catch (e) { /* private mode: the pick lasts for this page only */ }
    }

    /**
     * @param {Object} deps
     * @param {Array<{code:string,name:string,native:string}>} deps.languages  the owner's list
     * @param {string} deps.siteCode  the site language, the default
     * @param {Object} [deps.storage] localStorage-like (getItem/setItem)
     * @param {string} [deps.key]     storage key
     */
    function createLanguageControl(deps) {
        var languages = Array.isArray(deps.languages) ? deps.languages.filter(function (l) {
            return l && typeof l.code === 'string' && l.code;
        }) : [];
        var key = deps.key || STORAGE_KEY;
        var memory = null; // the pick when storage is unavailable

        function has(code) {
            return languages.some(function (l) { return l.code === code; });
        }

        function defaultCode() {
            if (has(deps.siteCode)) return deps.siteCode;
            return languages.length ? languages[0].code : '';
        }

        function enabled() {
            return languages.length > 1;
        }

        function current() {
            if (!enabled()) return '';
            var saved = memory || readStored(deps.storage, key);
            return has(saved) ? saved : defaultCode();
        }

        function pick(code) {
            if (!enabled() || !has(code)) return false;
            memory = code;
            writeStored(deps.storage, key, code);
            return true;
        }

        function label(code) {
            for (var i = 0; i < languages.length; i++) {
                if (languages[i].code === code) return languages[i].native || languages[i].name || code;
            }
            return code;
        }

        return {
            isEnabled: enabled,
            current: current,
            pick: pick,
            label: label,
            options: function () { return languages.slice(); }
        };
    }

    /**
     * Title and welcome message for a language: its row in config.copy when
     * the row has text, else the base text. Never returns undefined.
     *
     * @param {string} code
     * @param {Object} copy   config.copy: { code: { title, welcome, questions } }
     * @param {{title:string,welcome:string,questions?:string[]}} base
     */
    function questionList(v) {
        return Array.isArray(v) ? v.filter(function (q) { return typeof q === 'string' && q.trim() !== ''; }) : [];
    }

    function copyFor(code, copy, base) {
        var b = base || {};
        var row = copy && typeof copy === 'object' && code && copy[code] && typeof copy[code] === 'object' ? copy[code] : {};
        function text(v) { return typeof v === 'string' && v.trim() !== '' ? v : null; }
        return {
            title: text(row.title) !== null ? row.title : (typeof b.title === 'string' ? b.title : ''),
            welcome: text(row.welcome) !== null ? row.welcome : (typeof b.welcome === 'string' ? b.welcome : ''),
            questions: questionList(row.questions).length ? questionList(row.questions) : questionList(b.questions),
            placeholder: text(row.placeholder) !== null ? row.placeholder : (typeof b.placeholder === 'string' ? b.placeholder : '')
        };
    }

    return { createLanguageControl: createLanguageControl, copyFor: copyFor, STORAGE_KEY: STORAGE_KEY };
}));
