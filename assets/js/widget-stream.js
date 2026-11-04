/**
 * BitBot widget - answer stream logic.
 *
 * Pure helpers behind the streamed reply, kept out of widget.js so they can be
 * unit tested in Node (tests/widget-stream.test.js). widget.js supplies the DOM
 * and the fetch; this file never touches either.
 *
 * Wire format: application/x-ndjson, one JSON object per line.
 *   {"text": "<delta>"}            text to append to the bubble
 *   {"reset": true}                the text so far is not the answer, clear it
 *   {"done": true, "data": {...}}  the settled answer (same object as REST /chat)
 *   {"error": "<message>"}         the turn failed after text had started
 *
 * @package BitBot
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.BitBotStream = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** True when a Content-Type header names the NDJSON stream. */
    function isNdjson(contentType) {
        return typeof contentType === 'string' && contentType.toLowerCase().indexOf('application/x-ndjson') !== -1;
    }

    /**
     * Splits arriving text into complete JSON records. A line can arrive in any
     * number of pieces; only a finished line is parsed. Blank and unparseable
     * lines are skipped (and counted in `skipped`).
     */
    function createLineParser() {
        var rest = '';
        var skipped = 0;

        function parseLine(line) {
            var trimmed = line.trim();
            if (!trimmed) return null;
            try {
                var value = JSON.parse(trimmed);
                if (value && typeof value === 'object' && !Array.isArray(value)) return value;
            } catch (e) { /* fall through */ }
            skipped += 1;
            return null;
        }

        return {
            /** @returns {Object[]} the records completed by this piece of text */
            push: function (chunk) {
                rest += String(chunk);
                var lines = rest.split('\n');
                rest = lines.pop();
                var records = [];
                for (var i = 0; i < lines.length; i++) {
                    var record = parseLine(lines[i]);
                    if (record) records.push(record);
                }
                return records;
            },
            /** @returns {Object[]} a final line that had no newline after it */
            flush: function () {
                var record = parseLine(rest);
                rest = '';
                return record ? [record] : [];
            },
            get skipped() { return skipped; }
        };
    }

    /** Appends a delta to the text so far. Anything that is not a string adds nothing. */
    function mergeDelta(current, delta) {
        var base = typeof current === 'string' ? current : '';
        return typeof delta === 'string' ? base + delta : base;
    }

    /**
     * Folds records into the state of one streamed answer.
     * apply() returns what the caller should do: 'text', 'reset', 'done',
     * 'error' or 'ignore'.
     */
    function createAssembler() {
        var state = { text: '', started: false, done: false, data: null, error: null };
        return {
            state: state,
            apply: function (record) {
                if (!record || typeof record !== 'object' || state.done || state.error !== null) return 'ignore';
                if (typeof record.text === 'string') {
                    if (record.text === '') return 'ignore';
                    state.text = mergeDelta(state.text, record.text);
                    state.started = true;
                    return 'text';
                }
                if (record.reset === true) {
                    state.text = '';
                    state.started = false;
                    return 'reset';
                }
                if (record.done === true) {
                    if (!record.data || typeof record.data !== 'object') return 'ignore';
                    state.done = true;
                    state.data = record.data;
                    return 'done';
                }
                if (typeof record.error === 'string') {
                    state.error = record.error;
                    return 'error';
                }
                return 'ignore';
            }
        };
    }

    /**
     * Reads a response body to its end, calling onRecord for each record as soon
     * as its line is complete.
     *
     * @param {{read: function(): Promise<{done: boolean, value?: Uint8Array}>}} reader
     * @param {{decode: function(Uint8Array, Object=): string}} decoder
     * @param {function(Object): void} onRecord
     */
    async function consume(reader, decoder, onRecord) {
        var parser = createLineParser();
        for (;;) {
            var step = await reader.read();
            if (step.done) break;
            var records = parser.push(decoder.decode(step.value, { stream: true }));
            for (var i = 0; i < records.length; i++) onRecord(records[i]);
        }
        var tail = parser.push(decoder.decode());
        var last = parser.flush();
        var all = tail.concat(last);
        for (var j = 0; j < all.length; j++) onRecord(all[j]);
    }

    return {
        isNdjson: isNdjson,
        createLineParser: createLineParser,
        createAssembler: createAssembler,
        mergeDelta: mergeDelta,
        consume: consume
    };
}));
