/* =====================================================================
   SPPO-IS — SITE-WIDE FRONT-END HELPERS (vanilla JS, no frameworks)
   =====================================================================
   Purpose: Small progressive enhancements loaded last on every page
   (via includes/footer.php / admin-footer.php):
     1. Auto-dismiss flash messages marked with [data-auto-dismiss].
     2. Confirmation prompts for destructive submits marked with
        [data-confirm="message"] on the <form>.
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
});
