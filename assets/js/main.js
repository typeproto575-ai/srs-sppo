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
    // ---- 5) Admin sidebar dropdowns ----------------------------------------
    // No persistence: every page load is server-driven (see $groupIsOpen in
    // includes/admin-header.php). Refreshing therefore always resets the
    // menus — dashboard loads with all groups closed, inner pages load with
    // only their own group open. Any stale saved state from the old
    // memory feature is cleared once so it can never stick a group open.
    (function () {
        try { window.localStorage.removeItem('sppo-sidebar-open'); }
        catch (err) { /* storage unavailable — nothing to clear */ }
    })();

    // ---- 6) Password show/peek toggles --------------------------------------
    // A button.password-toggle inside .password-wrap flips its sibling
    // <input type="password"> between hidden and visible text. Both eye
    // icons live in the markup; the "off" one starts hidden and swaps with
    // the visible one on each press. Also mirrors the state to
    // aria-pressed so screen readers announce Show/Hide correctly.
    document.querySelectorAll('.password-toggle').forEach(function (btn) {
        var wrap = btn.closest('.password-wrap');
        var input = wrap ? wrap.querySelector('input[type="password"], input[type="text"]') : null;
        if (!input) return; // malformed markup — leave it alone

        btn.addEventListener('click', function () {
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            btn.classList.toggle('is-showing', !showing);
            var eyeOn = btn.querySelector('.eye-on');
            var eyeOff = btn.querySelector('.eye-off');
            if (eyeOn) eyeOn.hidden = !showing;
            if (eyeOff) eyeOff.hidden = showing;
            // Keep the caret where the user was typing.
            input.focus({ preventScroll: true });
        });
    });

    // ---- 7) Admin notification bell ----------------------------------------
    // Global notifications stream (see admin/api/notifications.php):
    //   - badge shows unread notifications + today/tomorrow events;
    //   - the panel lists events first, then the 10 newest notifications;
    //   - clicking an item marks it read and opens its link;
    //   - re-polls every 60s, but only while the tab is visible.
    (function () {
        var wrap = document.querySelector('[data-notif-wrap]');
        if (!wrap) return; // public pages have no bell

        var api    = wrap.getAttribute('data-notif-api');
        var csrf   = wrap.getAttribute('data-csrf') || '';
        var bell   = wrap.querySelector('[data-notif-bell]');
        var panel  = wrap.querySelector('[data-notif-panel]');
        var badge  = wrap.querySelector('[data-notif-badge]');
        var list   = wrap.querySelector('[data-notif-list]');
        var empty  = wrap.querySelector('[data-notif-empty]');
        var markAll = wrap.querySelector('[data-notif-mark-all]');
        if (!bell || !panel || !badge || !list || !api) return;

        // "3m ago"-style labels; falls back to the raw date for old items.
        function timeAgo(iso) {
            var then = new Date(iso).getTime();
            if (isNaN(then)) return '';
            var secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
            if (secs < 60) return 'just now';
            if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
            if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
            if (secs < 7 * 86400) return Math.floor(secs / 86400) + 'd ago';
            return new Date(iso).toLocaleDateString();
        }

        function render(data) {
            var count = data.unread_count || 0;
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count === 0;

            var items = data.items || [];
            list.innerHTML = '';
            items.forEach(function (item) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'notif-item notif-type-' + item.type + (item.is_read ? '' : ' is-unread');
                a.href = item.link;

                var title = document.createElement('span');
                title.className = 'notif-item-title';
                title.textContent = item.title;

                var msg = document.createElement('span');
                msg.className = 'notif-item-msg';
                msg.textContent = item.message;

                var time = document.createElement('span');
                time.className = 'notif-item-time';
                time.textContent = timeAgo(item.created_at);

                a.appendChild(title);
                a.appendChild(msg);
                a.appendChild(time);
                li.appendChild(a);
                list.appendChild(li);

                // Mark read on navigation (never blocks the link itself).
                if (item.kind === 'notif' && !item.is_read) {
                    a.addEventListener('click', function () {
                        markRead('mark_read', item.id);
                    });
                }
            });

            empty.hidden = items.length > 0;
        }

        function apply(data) {
            if (!data || data.error) return;
            render(data);
        }

        function fetchNotifications() {
            // Skip polling in hidden tabs — the badge refreshes the moment
            // the user returns anyway (visibilitychange below).
            if (document.hidden) return;
            fetch(api, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(apply)
                .catch(function () { /* offline or logged out — badge just stays stale */ });
        }

        function markRead(action, id) {
            var body = new URLSearchParams({ action: action, csrf_token: csrf });
            if (id) body.append('id', id);
            fetch(api, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(apply)
                .catch(function () { /* non-fatal */ });
        }

        bell.addEventListener('click', function () {
            var open = panel.hidden;
            panel.hidden = !open;
            bell.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) fetchNotifications();
        });

        // Click outside closes the panel (standard dropdown behaviour).
        document.addEventListener('click', function (ev) {
            if (!panel.hidden && !wrap.contains(ev.target)) {
                panel.hidden = true;
                bell.setAttribute('aria-expanded', 'false');
            }
        });

        if (markAll) {
            markAll.addEventListener('click', function (ev) {
                ev.stopPropagation();
                markRead('mark_all_read', null);
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !panel.hidden) fetchNotifications();
        });

        fetchNotifications();
        window.setInterval(fetchNotifications, 60000);
    })();

});
