/**
 * BitBot widget - form renderer and validator.
 *
 * One schema in (the API's FormFieldSchema: name, label, type, required,
 * options, placeholder), one markup out. The pre-chat gate in widget.js uses
 * this; the [bitbot_form] shortcode in bitbot.php prints the equivalent markup
 * from PHP (same element per type, same ids and error wiring).
 *
 * Pure functions (normalizeFields, renderFields, validate, toPayload) never
 * touch the DOM and are unit tested in tests/widget-form.test.js. The two
 * error helpers at the bottom take an element root and only use querySelector.
 *
 * Answer shape: every answer is a string. A checkbox group submits the chosen
 * options joined with ", " (the SubmitForm answers map is Record<string,string>).
 *
 * @package BitBot
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.BitBotForm = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var JOIN = ', ';

    var COPY = {
        selectPlaceholder: 'Select...',
        requiredMark: ' *',
        errName: 'Please enter your name.',
        errEmail: 'Please enter a valid email address.',
        errRequired: 'This field is required.',
        errChoose: 'Please choose an option.',
        errChooseAny: 'Please choose at least one option.'
    };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function copyOf(copy) {
        var out = {};
        Object.keys(COPY).forEach(function (k) {
            out[k] = copy && typeof copy[k] === 'string' ? copy[k] : COPY[k];
        });
        return out;
    }

    function idPart(name) {
        return String(name).replace(/[^A-Za-z0-9_-]/g, '_');
    }

    function fieldId(idPrefix, name) {
        return idPrefix + '-' + idPart(name);
    }

    function isChoice(type) {
        return type === 'select' || type === 'radio' || type === 'checkbox';
    }

    function optionsOf(f) {
        return Array.isArray(f.options)
            ? f.options.map(function (o) { return String(o); }).filter(function (o) { return o !== ''; })
            : [];
    }

    /**
     * The fields the visitor actually sees. Name and email are always present
     * and always required (the submit endpoint rejects a lead without them).
     * Anything else keeps the owner's schema untouched.
     */
    function normalizeFields(fields) {
        var list = Array.isArray(fields) ? fields.filter(function (f) { return f && f.name; }) : [];
        var out = list.map(function (f) {
            var copy = Object.assign({}, f);
            if (f.name === 'name') { copy.required = true; copy.type = 'text'; }
            if (f.name === 'email') { copy.required = true; copy.type = 'email'; }
            return copy;
        });
        var hasName = out.some(function (f) { return f.name === 'name'; });
        var hasEmail = out.some(function (f) { return f.name === 'email'; });
        var head = [];
        if (!hasName) head.push({ name: 'name', label: 'Full name', type: 'text', required: true, placeholder: 'Your name' });
        if (!hasEmail) head.push({ name: 'email', label: 'Email', type: 'email', required: true, placeholder: 'you@example.com' });
        return head.concat(out);
    }

    function cls(prefix, part) {
        return prefix + '-' + part;
    }

    function labelText(f, c) {
        return esc(f.label || f.name) + (f.required ? esc(c.requiredMark) : '');
    }

    function inputAttrs(f, id, errId) {
        return ' id="' + esc(id) + '" data-field="' + esc(f.name) + '" name="' + esc(f.name) + '"'
            + (f.required ? ' aria-required="true"' : '')
            + (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '')
            + ' data-error-id="' + esc(errId) + '"';
    }

    function renderField(f, opts, c) {
        var p = opts.prefix;
        var id = fieldId(opts.idPrefix, f.name);
        var errId = id + '-error';
        var type = f.type;
        var err = '<p class="' + cls(p, 'msg') + '" id="' + esc(errId) + '" hidden></p>';
        var rowOpen = '<div class="' + cls(p, 'row') + '" data-field-row="' + esc(f.name) + '">';
        var body;

        if (type === 'radio' || type === 'checkbox') {
            var kind = type;
            var opts2 = optionsOf(f);
            var items = opts2.map(function (o, i) {
                var oid = id + '-' + i;
                return '<label class="' + cls(p, 'choice') + '" for="' + esc(oid) + '">'
                    + '<input type="' + kind + '" id="' + esc(oid) + '" data-field="' + esc(f.name) + '"'
                    + ' name="' + esc(f.name) + '" value="' + esc(o) + '" data-error-id="' + esc(errId) + '">'
                    + '<span>' + esc(o) + '</span></label>';
            }).join('');
            return '<fieldset class="' + cls(p, 'row') + ' ' + cls(p, 'group') + '" data-field-row="' + esc(f.name) + '"'
                + ' data-field-type="' + kind + '"' + (f.required ? ' aria-required="true"' : '') + ' id="' + esc(id) + '">'
                + '<legend class="' + cls(p, 'legend') + '">' + labelText(f, c) + '</legend>'
                + items + err + '</fieldset>';
        }

        var label = '<label class="' + cls(p, 'label') + '" for="' + esc(id) + '">' + labelText(f, c) + '</label>';
        if (type === 'textarea') {
            body = '<textarea class="' + cls(p, 'input') + '" rows="4"' + inputAttrs(f, id, errId) + '></textarea>';
        } else if (type === 'select') {
            body = '<select class="' + cls(p, 'input') + '"' + inputAttrs(f, id, errId) + '>'
                + '<option value="">' + esc(f.placeholder || c.selectPlaceholder) + '</option>'
                + optionsOf(f).map(function (o) { return '<option value="' + esc(o) + '">' + esc(o) + '</option>'; }).join('')
                + '</select>';
        } else {
            var htmlType = type === 'email' ? 'email' : type === 'phone' ? 'tel' : 'text';
            body = '<input class="' + cls(p, 'input') + '" type="' + htmlType + '"' + inputAttrs(f, id, errId) + '>';
        }
        return rowOpen + label + body + err + '</div>';
    }

    /**
     * Markup for every field.
     * opts: { prefix: css class prefix, idPrefix: unique id prefix, copy: optional strings }
     * Pass the output of normalizeFields.
     */
    function renderFields(fields, opts) {
        var o = opts || {};
        var settings = { prefix: o.prefix || 'bitbot-form', idPrefix: o.idPrefix || 'bitbot-field' };
        var c = copyOf(o.copy);
        return fields.map(function (f) { return renderField(f, settings, c); }).join('');
    }

    function hasValue(v) {
        return Array.isArray(v) ? v.length > 0 : String(v == null ? '' : v).trim() !== '';
    }

    /**
     * values: { fieldName: string | string[] }. Returns { valid, errors: { fieldName: message } }.
     */
    function validate(fields, values, copy) {
        var c = copyOf(copy);
        var errors = {};
        fields.forEach(function (f) {
            var v = values[f.name];
            if (f.name === 'name' && !hasValue(v)) { errors[f.name] = c.errName; return; }
            if (f.name === 'email' || f.type === 'email') {
                var s = String(v == null ? '' : v).trim();
                if (s === '' ? f.required : !EMAIL_RE.test(s)) { errors[f.name] = c.errEmail; }
                return;
            }
            if (f.required && !hasValue(v)) {
                errors[f.name] = f.type === 'checkbox' ? c.errChooseAny
                    : (f.type === 'radio' || f.type === 'select') ? c.errChoose
                    : c.errRequired;
            }
        });
        return { valid: Object.keys(errors).length === 0, errors: errors };
    }

    /**
     * The form's own answers. name, email and phone also go top level (the API
     * has dedicated columns for them), but EVERY non-empty field, including one
     * named "message", is ALSO kept under answers keyed by its field name. A
     * field named "message" therefore never collides with the gate's held chat
     * message, which owns the top-level message key (see buildSubmitBody).
     * Checkbox arrays are joined with ", ".
     */
    function toPayload(fields, values) {
        var out = { name: '', email: '', phone: '', answers: {} };
        fields.forEach(function (f) {
            var v = values[f.name];
            var s = Array.isArray(v) ? v.join(JOIN) : String(v == null ? '' : v).trim();
            if (f.name === 'name' || f.name === 'email' || f.name === 'phone') { out[f.name] = s; }
            if (s !== '') out.answers[f.name] = s;
        });
        return out;
    }

    /**
     * A random id for one submission, made once per held message. The API stores
     * a submission once per key, so a retry after a lost response is safe.
     */
    function newSubmissionKey() {
        var bytes = new Uint8Array(16);
        var c = typeof crypto !== 'undefined' ? crypto : (typeof globalThis !== 'undefined' ? globalThis.crypto : null);
        if (c && c.getRandomValues) {
            c.getRandomValues(bytes);
        } else {
            // Old browsers only. The key is an idempotency id, not a secret.
            for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        return Array.prototype.map.call(bytes, function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
    }

    /**
     * The gate's form-submit body. message is the held chat question; the form's
     * own fields, message included, are all in answers.
     */
    function buildSubmitBody(opts) {
        var p = opts.payload;
        return {
            formId: opts.formId,
            name: p.name,
            email: p.email,
            phone: p.phone,
            message: opts.heldMessage,
            sessionId: opts.sessionId,
            answers: p.answers,
            submissionKey: opts.submissionKey
        };
    }

    /** Reads the current values from rendered markup. */
    function readValues(rootEl, fields) {
        var values = {};
        fields.forEach(function (f) {
            var els = rootEl.querySelectorAll('[data-field="' + f.name + '"]');
            var list = Array.prototype.slice.call(els);
            if (f.type === 'checkbox') {
                values[f.name] = list.filter(function (e) { return e.checked; }).map(function (e) { return e.value; });
            } else if (f.type === 'radio') {
                var on = list.filter(function (e) { return e.checked; })[0];
                values[f.name] = on ? on.value : '';
            } else {
                values[f.name] = list[0] ? list[0].value : '';
            }
        });
        return values;
    }

    function rowFor(rootEl, name) {
        return rootEl.querySelector('[data-field-row="' + name + '"]');
    }

    /** Shows a message on a field: visible text, aria-invalid and aria-describedby. */
    function showError(rootEl, name, message, prefix) {
        var row = rowFor(rootEl, name);
        if (!row) return false;
        var p = prefix || 'bitbot-form';
        row.classList.add(cls(p, 'row-error'));
        var msg = row.querySelector('.' + cls(p, 'msg'));
        if (msg) { msg.textContent = message; msg.hidden = false; }
        var errId = msg ? msg.id : '';
        var controls = row.querySelectorAll('[data-field]');
        for (var i = 0; i < controls.length; i++) {
            controls[i].setAttribute('aria-invalid', 'true');
            if (errId) controls[i].setAttribute('aria-describedby', errId);
        }
        if (row.getAttribute('data-field-type') && errId) row.setAttribute('aria-describedby', errId);
        return true;
    }

    function clearError(rootEl, name, prefix) {
        var row = rowFor(rootEl, name);
        if (!row) return;
        var p = prefix || 'bitbot-form';
        row.classList.remove(cls(p, 'row-error'));
        var msg = row.querySelector('.' + cls(p, 'msg'));
        if (msg) { msg.textContent = ''; msg.hidden = true; }
        var controls = row.querySelectorAll('[data-field]');
        for (var i = 0; i < controls.length; i++) {
            controls[i].removeAttribute('aria-invalid');
            controls[i].removeAttribute('aria-describedby');
        }
        row.removeAttribute('aria-describedby');
    }

    return {
        COPY: COPY,
        EMAIL_RE: EMAIL_RE,
        normalizeFields: normalizeFields,
        renderFields: renderFields,
        validate: validate,
        toPayload: toPayload,
        newSubmissionKey: newSubmissionKey,
        buildSubmitBody: buildSubmitBody,
        readValues: readValues,
        showError: showError,
        clearError: clearError
    };
}));
