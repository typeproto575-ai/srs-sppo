<?php
/**
 * =====================================================================
 * SRS-SPPO SHARED SUBMISSION-SUCCESS PANEL (public)
 * =====================================================================
 * Purpose: The "your request was received" confirmation shown after any
 *          public form (certificate request or sacrament application)
 *          saves successfully. Deliberately more prominent than an
 *          inline alert: the tracking number is the parishioner's only
 *          key to their request, so the panel makes it easy to copy,
 *          print, or photograph before navigating away.
 *
 * Contract (set by the calling page before including):
 *   $trackingNumber  - (required) the generated tracking number
 *   $successHeading  - (optional) e.g. 'Your request has been submitted'
 *                      defaults to a generic submitted message
 *   $basePath        - falls back to base_path()
 *
 * Scope note: the panel points at Track My Request only - never a
 * download/print-your-certificate flow (certificates are picked up and
 * printed at the counter per project rules). "Print" here prints this
 * confirmation page itself, for note-keeping.
 * =====================================================================
 */

// Guard: only renders when a tracking number actually exists.
if (empty($trackingNumber)) {
    return;
}

$successHeading = $successHeading ?? 'Your request has been submitted';
$basePath       = $basePath ?? base_path();
?>
        <div class="submission-success" id="submission-success">
            <p class="submission-eyebrow"><?= e($successHeading) ?></p>

            <div class="submission-number-row">
                <p class="submission-tracking" id="submission-tracking-number"><?= e($trackingNumber) ?></p>
                <button type="button" class="btn btn-small" id="copy-tracking-btn">Copy Number</button>
                <button type="button" class="btn btn-secondary btn-small" id="print-confirmation-btn">Print This Page</button>
            </div>

            <div class="submission-next-steps">
                <h3>What happens next</h3>
                <ol>
                    <li>The secretary matches your submission against the parish register.</li>
                    <li>Check your status any time on the
                        <a href="<?= $basePath ?>/public/track-request.php">Track My Request</a>
                        page using this number <em>and</em> the email you provided.</li>
                    <li>When it shows <strong>Ready for Pickup</strong>, visit the parish office
                        with a valid ID to claim and complete your request in person.</li>
                </ol>
            </div>

            <p class="field-hint">Write this number down before leaving this page - the parish office cannot resend it without verifying your identity in person.</p>
        </div>
        <script>
        (function () {
            // Copy: modern clipboard API first, textarea fallback for older
            // mobile browsers inside the parish (no external library).
            var btn = document.getElementById('copy-tracking-btn');
            if (btn) {
                btn.addEventListener('click', function () {
                    var text = document.getElementById('submission-tracking-number').textContent.trim();
                    function done(ok) {
                        btn.textContent = ok ? 'Copied!' : 'Copy failed';
                        setTimeout(function () { btn.textContent = 'Copy Number'; }, 2000);
                    }
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(fallback(text)); });
                    } else {
                        done(fallback(text));
                    }
                    function fallback(value) {
                        var ta = document.createElement('textarea');
                        ta.value = value;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.select();
                        var ok = false;
                        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                        document.body.removeChild(ta);
                        return ok;
                    }
                });
            }
            // Print: prints the confirmation page only (nav/footer hidden via CSS).
            var pbtn = document.getElementById('print-confirmation-btn');
            if (pbtn) {
                pbtn.addEventListener('click', function () { window.print(); });
            }
        })();
        </script>
