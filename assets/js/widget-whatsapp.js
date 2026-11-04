/**
 * BitBot widget — WhatsApp click-to-chat.
 *
 * Pure logic for the WhatsApp button, kept out of widget.js so it can be unit
 * tested in Node (tests/widget-whatsapp.test.js). Click-to-chat only: the link
 * is https://wa.me/<number>?text=<prefilled>. No WhatsApp API, account or key.
 *
 * @package BitBot
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.BitBotWhatsApp = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var QUESTION_MAX = 200;

    // The visitor asking for a person, in plain words.
    var WANTS_PERSON = /\b(?:(?:talk|speak|chat|connect|transfer)(?:ing)?\s+(?:me\s+)?(?:to|with)\s+(?:a|an|the|some|someone|somebody|your)?\s*(?:real\s+|live\s+)?(?:person|people|human|someone|somebody|agent|representative|rep|staff|team|manager|owner|operator)|(?:real|live)\s+(?:person|human|agent)|(?:a|an)\s+(?:human|person|agent|representative)|human\s+(?:please|help|support)|customer\s+(?:service|support)|contact\s+(?:a|the)\s+(?:person|team|human|someone))\b/i;

    /** Digits only (what wa.me wants), or '' when the value is not a plausible number. */
    function digitsOnly(number) {
        var d = String(number == null ? '' : number).replace(/\D/g, '');
        return /^[1-9][0-9]{7,14}$/.test(d) ? d : '';
    }

    function clean(text) {
        return String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
    }

    /** The opening line names the business: "Hello, I was on <site name>". */
    function greeting(siteName) {
        return 'Hello, I was on ' + (clean(siteName) || 'your website');
    }

    /** The visitor's own words, trimmed to 200 characters. Never any bot text. */
    function trimQuestion(message) {
        var q = clean(message);
        if (q.length <= QUESTION_MAX) return q;
        return q.slice(0, QUESTION_MAX - 1).replace(/\s+\S*$/, '').replace(/[\s.,;:!?-]+$/, '') + '…';
    }

    function link(number, text) {
        var d = digitsOnly(number);
        if (!d) return '';
        return 'https://wa.me/' + d + '?text=' + encodeURIComponent(text);
    }

    /** The header button's link: the greeting alone. */
    function headerLink(number, siteName) {
        return link(number, greeting(siteName));
    }

    /** The inline button's link: the greeting plus the visitor's last message. */
    function questionLink(number, siteName, visitorMessage) {
        var q = trimQuestion(visitorMessage);
        var text = greeting(siteName) + (q ? '. My question: ' + q : '');
        return link(number, text);
    }

    function wantsPerson(message) {
        return WANTS_PERSON.test(String(message == null ? '' : message));
    }

    /**
     * Should the bot's reply carry the WhatsApp button? Only when the owner's
     * number is set and the bot could not answer (the backend's suggest_contact),
     * the chat was handed to a person (live_mode), or the visitor asked for one.
     */
    function shouldOfferInline(opts) {
        if (!digitsOnly(opts && opts.number)) return false;
        return !!(opts.suggestContact || opts.liveMode || wantsPerson(opts.visitorMessage));
    }

    return {
        digitsOnly: digitsOnly,
        greeting: greeting,
        trimQuestion: trimQuestion,
        headerLink: headerLink,
        questionLink: questionLink,
        wantsPerson: wantsPerson,
        shouldOfferInline: shouldOfferInline,
        QUESTION_MAX: QUESTION_MAX
    };
}));
