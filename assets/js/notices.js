/* Floating notices and "Not applicable" name toggles: adds a close button to error/success/warning notices
   and hides them after a time limit. Presentation only. */
(function () {
    'use strict';
    var SELECTOR = '.public-page .alert-error, .public-page .alert-success, .public-page .alert-warning, '
        + '.admin-content .alert-success, .admin-content .alert-error';

    function enhance(notice) {
        if (!notice || notice.getAttribute('data-toast') === '1' || notice.getAttribute('role') === 'note') return;
        notice.setAttribute('data-toast', '1');
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss message');
        close.textContent = '×';
        close.addEventListener('click', function () { notice.classList.add('is-dismissed'); });
        notice.appendChild(close);
        // Time limit: success 6 s, errors and warnings 15 s. Hovering pauses the timer.
        var limit = notice.classList.contains('alert-success') ? 6000 : 15000;
        var timer = null;
        function start() { timer = window.setTimeout(function () { notice.classList.add('is-dismissed'); }, limit); }
        notice.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
        notice.addEventListener('mouseleave', start);
        start();
    }

    document.querySelectorAll(SELECTOR).forEach(enhance);

    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.matches && node.matches(SELECTOR)) enhance(node);
                    if (node.querySelectorAll) node.querySelectorAll(SELECTOR).forEach(enhance);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();

/* "Not available / unknown (N/A)" checkboxes.
   <input type="checkbox" data-na-target="field_a,field_b"> fills the listed fields
   with "N/A" and locks them; unticking restores what was typed before.
   The server only sees the field values, so "N/A" satisfies a required field. */
(function () {
    'use strict';
    function fieldsFor(box) {
        var form = box.closest('form');
        if (!form) return [];
        return (box.getAttribute('data-na-target') || '').split(',').map(function (name) {
            return form.elements[name.trim()] || null;
        }).filter(Boolean);
    }
    document.querySelectorAll('input[data-na-target]').forEach(function (box) {
        var fields = fieldsFor(box);
        if (!fields.length) return;
        var saved = {};
        function isNa(field) { return field.value.trim().toUpperCase() === 'N/A'; }
        function lock(on) {
            fields.forEach(function (field) {
                if (on) {
                    if (!isNa(field)) saved[field.name] = field.value;
                    field.value = 'N/A';
                    field.readOnly = true;
                    field.setAttribute('data-na-locked', '1');
                } else {
                    field.readOnly = false;
                    field.removeAttribute('data-na-locked');
                    field.value = saved[field.name] || '';
                }
            });
        }
        box.addEventListener('change', function () {
            // Never overwrite a field that a matched parish profile has locked.
            var profileLocked = fields.some(function (f) { return f.readOnly && !f.hasAttribute('data-na-locked'); });
            if (profileLocked) { box.checked = false; return; }
            lock(box.checked);
        });
        if (fields.every(isNa)) { box.checked = true; lock(true); }
    });
})();

/* "Not applicable" tick box for a parent's name: disables that name's inputs. */
(function () {
    'use strict';
    function apply(box) {
        var group = box.closest('.structured-name-fields');
        if (!group) return;
        group.querySelectorAll('input[type="text"]').forEach(function (input) {
            input.disabled = box.checked;
        });
    }
    document.querySelectorAll('.na-toggle input[type="checkbox"]').forEach(apply);
    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('.na-toggle input[type="checkbox"]')) apply(event.target);
    });
})();

/* Name fields capitalise themselves when you leave them ("juan dela cruz" becomes
   "Juan Dela Cruz"). Mixed-case words such as "McDonald" are left as typed.
   The server applies the same rule, so this only makes the form show it early. */
(function () {
    'use strict';
    var ROMAN = /^(i|ii|iii|iv|v|vi|vii|viii|ix|x)$/i;

    function formatName(value, isSuffix) {
        value = value.replace(/\s+/g, ' ').trim();
        if (value === '' || value.toUpperCase() === 'N/A') return value;
        return value.split(' ').map(function (word) {
            if (!/\p{L}/u.test(word)) return word;
            if (isSuffix && ROMAN.test(word)) return word.toUpperCase();
            if (word !== word.toLowerCase() && word !== word.toUpperCase()) return word;
            return word.toLowerCase().replace(/(^|[-'’.])(\p{L})/gu, function (match, lead, letter) {
                return lead + letter.toUpperCase();
            });
        }).join(' ');
    }

    document.addEventListener('focusout', function (event) {
        var input = event.target;
        if (!input || !input.matches || !input.matches('.structured-name-fields input[type="text"]')) return;
        if (input.readOnly || input.disabled) return;
        var part = (input.name || '').replace(/\[\]$/, '').split('_').pop();
        var formatted = formatName(input.value, part === 'suffix');
        if (formatted !== input.value) input.value = formatted;
    });
})();
