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
 *          email, or photograph before navigating away.
 *
 * Contract (set by the calling page before including):
 *   $trackingNumber  - (required) the generated tracking number
 *   $successHeading  - (optional) e.g. 'Your request has been submitted'
 *                      defaults to a generic submitted message
 *   $basePath        - falls back to base_path()
 *
 * Scope note: the panel points at Track My Request only - never a
 * download/print-your-certificate flow (certificates are picked up and
 * printed at the counter per project rules). The action buttons here are
 * visitor-side conveniences only: "Send to Email" opens the visitor's
 * own mail app (with a Gmail-on-the-web fallback link) with this
 * confirmation pre-filled - the parish never handles the recipient
 * address; "Copy" duplicates the number on the spot.
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
                <button type="button" class="btn btn-secondary btn-small" id="copy-tracking-btn">Copy Number</button>
            </div>

            <div class="submission-next-steps">
                <h3>What happens next</h3>
                <ol>
                    <li>The parish office reviews the information you submitted and checks the details against the parish register.</li>
                    <li>Keep the tracking number and the same email address. Check your status any time on the
                        <a href="<?= $basePath ?>/public/track-request.php">Track My Request</a>
                        page using this number <em>and</em> the email you provided.</li>
                    <li>If requirements are needed, follow the parish office's email instructions or visit the office. When the status shows <strong>Ready for Pickup</strong>, bring valid ID and claim the printed certificate in person.</li>
                </ol>
            </div>

            <p class="field-hint">We will use the email address you provided for request updates when email delivery is available. Online submission is only a request for parish-office review; it does not guarantee approval, accept payment, or release a certificate online. Write this tracking number down before leaving this page.</p>
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
        })();
        </script>
