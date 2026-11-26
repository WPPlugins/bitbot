/**
 * BitBot widget: where a link in the chat opens.
 *
 * A link to another page of THIS site opens in the same tab: the chat is kept
 * per tab, so the visitor arrives on the page with the conversation still
 * beside them. Opening it in a new tab showed them an empty chat. Everything
 * else keeps opening in a new tab: another site, a file download, a link
 * clicked with a modifier key or the middle button (the visitor asked for a
 * new tab), and any link clicked during a voice call (leaving the page would
 * end the call).
 *
 * Pure logic, kept out of widget.js so it can be unit tested in Node
 * (tests/widget-links.test.js).
 *
 * @package BitBot
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.BitBotLinks = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Files a browser downloads or shows on their own: leaving the page for one is never what the visitor wants.
    var FILE = /\.(?:pdf|zip|rar|7z|gz|docx?|xlsx?|pptx?|csv|txt|rtf|mp3|mp4|mov|avi|wav|png|jpe?g|gif|webp|svg|ics|apk|dmg|exe)$/i;

    function parse(url, base) {
        try { return new URL(String(url), base); } catch (e) { return null; }
    }

    /**
     * Whether a clicked chat link should replace the current page.
     *
     * @param {string} href    The link's address.
     * @param {string} pageUrl The address of the page the widget is on.
     * @param {Object} [click] { inCall, modifier (ctrl/meta/shift/alt held), button (0 = primary), download (the link has a download attribute) }
     * @returns {boolean}
     */
    function staysInTab(href, pageUrl, click) {
        var c = click || {};
        if (c.inCall || c.modifier || c.download || (c.button !== undefined && c.button !== 0)) return false;
        var page = parse(pageUrl);
        var link = parse(href, pageUrl);
        if (!page || !link) return false;
        if (link.protocol !== 'http:' && link.protocol !== 'https:') return false;
        if (link.origin !== page.origin) return false;
        if (FILE.test(link.pathname)) return false;
        // The same page with only another #fragment is a jump within the page: the browser handles it in place either way.
        return true;
    }

    // ---- A reply's text as HTML -------------------------------------------

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Characters that must never appear in a link's visible text because they hide or reorder what it says:
    // whitespace, control characters and the Unicode direction controls.
    var UNSAFE_IN_LABEL = /[\s\u0000-\u001f\u007f-\u009f\u00ad\u061c\u180e\u200b-\u200f\u202a-\u202e\u2060-\u206f\ufeff\ufff9-\ufffb]/g;

    /**
     * An address as a person reads it: percent-encoded letters decoded, so
     * https://example.gr/%ce%b5%cf%80%ce%b9/ shows as https://example.gr/επι/.
     * Only the visible text changes; the link itself keeps the address as given.
     * An address that does not decode cleanly is shown as it was, and an invisible
     * or direction-changing character is always shown percent-encoded, whether it
     * arrived encoded or as itself. The same rule lives in the plugin admin
     * (admin-react/src/lib/renderMarkdown.ts) and the operator dashboard
     * (packages/api/src/lib/linkedText.ts); tests/fixtures/link-display.json
     * holds all three to the same answers.
     */
    function hasUnsafe(s) { UNSAFE_IN_LABEL.lastIndex = 0; return UNSAFE_IN_LABEL.test(s); }
    function readableUrl(url) {
        var given = String(url);
        var decoded;
        try { decoded = decodeURI(given); } catch (e) { decoded = given; }
        return hasUnsafe(decoded) ? given.replace(UNSAFE_IN_LABEL, encodeURIComponent) : decoded;
    }

    function anchor(url, labelHtml) {
        return '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + labelHtml + '</a>';
    }

    function inline(escaped) {
        return escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\*(.+?)\*/g, '<em>$1</em>');
    }

    // [text](https://...) or a bare https://... address. A bare address stops at whitespace, a quote, a bracket,
    // an angle bracket or a Markdown mark (* and [), so it never takes the bold or the link that follows it;
    // sentence punctuation at its end is not part of it.
    var LINKS = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<>"'`()\[\]*]+)/g;
    var SENTENCE_END = /[.,;:!?…»”’]+$/;

    /**
     * The small Markdown a reply may use, as HTML: links, bare addresses, bold,
     * italic, line breaks and "- " lists. Every piece of the reply is escaped,
     * quotes included, BEFORE it is placed: an address is the only thing that
     * goes inside an attribute, and it cannot end that attribute.
     *
     * @param {string} text
     * @returns {string}
     */
    function markdownToHtml(text) {
        var src = String(text == null ? '' : text);
        var out = '';
        var last = 0;
        var m;
        LINKS.lastIndex = 0;
        while ((m = LINKS.exec(src)) !== null) {
            out += inline(esc(src.slice(last, m.index)));
            if (m[2]) {
                out += anchor(m[2], inline(esc(m[1])));
            } else {
                var url = m[3];
                var end = (SENTENCE_END.exec(url) || [''])[0];
                url = url.slice(0, url.length - end.length);
                out += /^https?:\/\/[^/?#]/.test(url) ? anchor(url, esc(readableUrl(url))) + esc(end) : esc(m[3]);
            }
            last = m.index + m[0].length;
        }
        out += inline(esc(src.slice(last)));
        return out
            .replace(/\n/g, '<br>')
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
            .replace(/<\/ul>\s*<ul>/g, '');
    }

    return { staysInTab: staysInTab, markdownToHtml: markdownToHtml, readableUrl: readableUrl };
}));
