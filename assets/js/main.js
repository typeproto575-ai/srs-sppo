/* =====================================================================
   SRS-SPPO — SITE-WIDE FRONT-END HELPERS (vanilla JS, no frameworks)
   =====================================================================
   Purpose: Small progressive enhancements loaded last on every page
   (via includes/footer.php / admin-footer.php):
     1. Auto-dismiss flash messages marked with [data-auto-dismiss].
     2. Confirmation prompts for destructive submits marked with
        [data-confirm="message"] on the <form>.
      3. Modal encode dialogs ([data-open-dialog], [data-autoshow]).
      4. Record-list filter panel sync + click-outside close.
      5. Admin sidebar dropdown open/closed memory (localStorage).
   Every enhancement is opt-in via HTML attributes, so pages without
   the attributes are unaffected.
   ===================================================================== */

document.addEventListener('DOMContentLoaded', function () {
    // ---- 1) Flash messages fade away on their own -----------------------
    // Add data-auto-dismiss to an .alert element to enable; the value
    // (if any) is the delay in milliseconds.
    document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (el) {
        var delay = parseInt(el.getAttribute('data-auto-dismiss'), 10);
        setTimeout(function () { el.remove(); }, isNaN(delay) ? 6000 : delay);
    });

    // ---- 2) Confirm-before-submit for risky forms ------------------------
    // e.g. <form method="post" data-confirm="Delete this record?">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                ev.preventDefault(); // user cancelled — stop the submission
            }
        });
    });

    // ---- 3) Modal dialogs for the records encode forms -------------------
    // Buttons marked data-open-dialog="#id" pop the dialog up. A dialog
    // rendered with data-autoshow opens itself on load (edit mode or a
    // validation-error re-render). [data-close-dialog] buttons, the Esc
    // key, and clicks on the backdrop all dismiss it.
    document.querySelectorAll('[data-open-dialog]').forEach(function (btn) {
        var dlg = document.querySelector(btn.getAttribute('data-open-dialog'));
        if (!dlg || typeof dlg.showModal !== 'function') return;
        btn.addEventListener('click', function () { dlg.showModal(); });
    });
    document.querySelectorAll('dialog[data-autoshow]').forEach(function (dlg) {
        if (typeof dlg.showModal === 'function') dlg.showModal();
    });
    document.querySelectorAll('dialog [data-close-dialog]').forEach(function (btn) {
        btn.addEventListener('click', function () { btn.closest('dialog').close(); });
    });
    document.querySelectorAll('dialog.encode-dialog').forEach(function (dlg) {
        dlg.addEventListener('click', function (ev) {
            if (ev.target === dlg) dlg.close(); // click landed on the backdrop
        });
    });

    // ---- 4) Record list filter panel (records pages) ---------------------
    // The From/To year inputs only work while "Custom range" is selected;
    // they are disabled and greyed out for every other choice.
    document.querySelectorAll('.filter-panel').forEach(function (panel) {
        var radios = panel.querySelectorAll('input[name="range"]');
        var customBox = panel.querySelector('.filter-custom-dates');
        if (!radios.length || !customBox) return;
        var custom = customBox.querySelectorAll('input');
        var sync = function () {
            var checked = panel.querySelector('input[name="range"]:checked');
            var on = checked !== null && checked.value === 'custom';
            customBox.classList.toggle('is-off', !on);
            custom.forEach(function (el) { el.disabled = !on; });
        };
        radios.forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    });
    // Clicking anywhere outside an open filter panel closes it.
    document.querySelectorAll('details.filter-popup').forEach(function (popup) {
        document.addEventListener('click', function (ev) {
            if (popup.open && !popup.contains(ev.target)) popup.open = false;
        });
    });
    // ---- 5) Admin sidebar dropdown memory ----------------------------------
    // Each sidebar nav group is a <details data-sidebar-group="...">.
    // The server pre-opens the group holding the current page (see
    // includes/admin-header.php $groupIsOpen), and that always wins on page
    // load so the open menu matches the page you are on (nothing stays
    // stuck open after you go back to the dashboard).
    // A saved manual choice is only reused when the current page does not
    // belong to any group (dashboard) — then your last-opened group is
    // restored instead of leaving everything closed.
    (function () {
        var KEY = 'sppo-sidebar-open';
        var groups = Array.prototype.slice.call(
            document.querySelectorAll('details.sidebar-group[data-sidebar-group]')
        );
        if (!groups.length) return; // not an admin page — nothing to remember
        var read = function () {
            try { return JSON.parse(window.localStorage.getItem(KEY)) || {}; }
            catch (err) { return {}; }
        };
        var write = function (state) {
            try { window.localStorage.setItem(KEY, JSON.stringify(state)); }
            catch (err) { /* storage unavailable (private mode) — stay server-driven */ }
        };
        // Server-driven pages: force exactly the matching group open (or all
        // closed on the dashboard) and sync storage so stale values cannot
        // leak in from an earlier page.
        var serverOpen = groups.filter(function (g) { return g.open; });
        if (serverOpen.length > 0) {
            var state = {};
            groups.forEach(function (g) {
                var shouldOpen = serverOpen.indexOf(g) !== -1;
                g.open = shouldOpen;
                state[g.getAttribute('data-sidebar-group')] = shouldOpen;
            });
            write(state);
        } else {
            // Dashboard (no group matches): restore the last-opened group.
            var saved = read();
            groups.forEach(function (g) {
                if (saved[g.getAttribute('data-sidebar-group')] === true) { g.open = true; }
            });
        }
        groups.forEach(function (g) {
            g.addEventListener('toggle', function () {
                var state = read();
                state[g.getAttribute('data-sidebar-group')] = g.open;
                write(state);
            });
        });
    })();

});
