<?php
/**
 * =====================================================================
 * SRS-SPPO SHARED SUBMISSION-SUCCESS PANEL (public)
 * =====================================================================
 * Purpose: The "your request was received" confirmation shown after any
 *          public form (certificate request or sacrament application)
 *          saves successfully. Deliberately more prominent than an
 *          inline alert: the reference number is the parishioner's only
 *          key to their request, so the panel makes it easy to copy,
 *          email, or photograph before navigating away.
 *
 * Contract (set by the calling page before including):
 *   $trackingNumber  - (required) the generated public reference number
 *   $successHeading  - (optional) e.g. 'Your request has been submitted'
 *                      defaults to a generic submitted message
 *   $successIsApplication - (optional) show sacrament-application next steps
 *   $basePath        - falls back to base_path()
 *
 * Certificates are picked up and printed at the counter per project rules.
 * The action buttons here are visitor-side conveniences only: "Send to
 * Email" opens the visitor's own mail app (with a Gmail-on-the-web fallback
 * link) with this confirmation pre-filled - the parish never handles the
 * recipient address; "Copy" duplicates the reference number on the spot.
 * =====================================================================
 */

// Guard: only renders when a reference number actually exists.
if (empty($trackingNumber)) {
    return;
}

$successHeading = $successHeading ?? 'Your request has been submitted';
$successIsApplication = $successIsApplication ?? false;
$successRequiredDocuments = is_array($successRequiredDocuments ?? null) ? $successRequiredDocuments : [];
$basePath       = $basePath ?? base_path();
?>
        <div class="submission-success" id="submission-success">
            <p class="submission-eyebrow"><?= e($successHeading) ?></p>

            <div class="submission-number-row">
                <p class="submission-tracking" id="submission-tracking-number"><?= e($trackingNumber) ?></p>
                <button type="button" class="btn btn-secondary btn-small" id="copy-tracking-btn">Copy Reference Number</button>
            </div>

            <div class="submission-next-steps">
                <h3>What happens next</h3>
                <ol>
                    <?php if ($successIsApplication): ?>
                    <li>The Parish Office reviews your application and may email you about requirements, payment, preparation, and scheduling. You may also contact the office with this reference number and the email address you used.</li>
                    <?php else: ?>
                    <li>The parish office reviews the information you submitted and checks the details against the parish register.</li>
                    <?php endif; ?>
                    <?php if ($successIsApplication): ?>
                    <li>Bring requested documents to the Parish Office in person. The secretary confirms approval and any sacrament schedule separately.</li>
                    <?php else: ?>
                    <li>The Parish Office may email you when there is an update. You may also contact the office with this reference number and the email address you used. Bring any requested documents and valid ID to collect the printed certificate in person.</li>
                    <?php endif; ?>
                </ol>
            </div>

            <?php if ($successRequiredDocuments !== []): ?>
                <section class="submission-documents" aria-label="Documents to prepare">
                    <h3>Documents to prepare</h3>
                    <ul>
                        <?php foreach ($successRequiredDocuments as $document): ?>
                            <li><?= e($document) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <p class="field-hint">The Parish Office may email you at the address you provided when email updates are available. Online submission is only a request for parish-office review; it does not guarantee approval, accept payment, or release a certificate online. Keep this reference number for your records.</p>
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
                        setTimeout(function () { btn.textContent = 'Copy Reference Number'; }, 2000);
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
