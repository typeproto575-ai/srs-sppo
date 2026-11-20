/* Floating notices: adds a close button to error/success/warning notices
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
