<?php
/**
 * =====================================================================
 * SRS-SPPO SHARED SUBMISSION-SUCCESS PANEL (public)
 * =====================================================================
 * Purpose: The "your request was received" confirmation shown after any
 *          public form (certificate request or sacrament pre-application)
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
$successRequiredDocuments = isset($successRequiredDocuments) && is_array($successRequiredDocuments)
    ? $successRequiredDocuments
    : [];
$successPersonReferences = isset($successPersonReferences) && is_array($successPersonReferences)
    ? $successPersonReferences
    : [['name' => '', 'reference' => $trackingNumber]];
$basePath       = $basePath ?? base_path();
?>
        <div class="submission-success" id="submission-success">
            <p class="submission-eyebrow"><?= e($successHeading) ?></p>

            <div class="submission-number-row">
                <div>
                    <p class="field-hint">Your person reference</p>
                    <?php foreach ($successPersonReferences as $personReference): ?>
                        <?php if (!is_array($personReference) || !is_string($personReference['reference'] ?? null)) continue; ?>
                        <p class="submission-tracking">
                            <?php if (is_string($personReference['name'] ?? null) && $personReference['name'] !== ''): ?>
                                <?= e($personReference['name']) ?>:
                            <?php endif; ?>
                            <?= e($personReference['reference']) ?>
                        </p>
                        <button type="button" class="btn btn-secondary btn-small" data-copy-person-reference="<?= e($personReference['reference']) ?>">Copy<?= is_string($personReference['name'] ?? null) && $personReference['name'] !== '' ? ' ' . e($personReference['name']) . ' Reference' : ' Reference' ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="submission-next-steps">
                <h3>After submission: what happens next</h3>
                <ol>
                    <?php if ($successIsApplication): ?>
                    <li>The Parish Office reviews your pre-application and may email you about requirements, payment, preparation, and scheduling. Use your person reference and the email address you used to view requests linked to that profile. You may also contact the office or ask a question through the <a href="https://www.facebook.com/profile.php?id=61575322479373" target="_blank" rel="noopener noreferrer">parish Facebook/Messenger page</a>. Do not send IDs or documents through Messenger.</li>
                    <?php else: ?>
                    <li>The parish office reviews the information you submitted and checks the details against the parish register.</li>
                    <?php endif; ?>
                    <?php if ($successIsApplication): ?>
                    <li>Bring requested documents to the Parish Office in person. The secretary confirms approval and any sacrament schedule separately.</li>
                    <?php else: ?>
                    <li>If asked to proceed, handle payment at the Parish Office. Keep any claim stub or payment slip the secretary gives you.</li>
                    <li>The Parish Office may email you when there is an update. Use your person reference and the email address you used to view linked requests, or contact the office through the <a href="https://www.facebook.com/profile.php?id=61575322479373" target="_blank" rel="noopener noreferrer">parish Facebook/Messenger page</a>. Do not send documents through Messenger. When the certificate is ready, collect it in person; the secretary verifies the request and recorded payment. An authorized representative must bring a signed authorization letter.</li>
                    <?php endif; ?>
                </ol>
            </div>

            <?php if ($successRequiredDocuments !== []): ?>
                <section class="submission-documents" aria-label="Documents to prepare">
                    <h3><?= $successIsApplication ? 'Pre-Application requirements to prepare' : 'Certificate pickup guidance' ?></h3>
                    <ul>
                        <?php foreach ($successRequiredDocuments as $document): ?>
                            <li><?= e($document) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <p class="field-hint">The Parish Office may email you at the address you provided when email updates are available. Online submission is only a request for parish-office review; it does not guarantee approval, accept payment, or release a certificate online. <a href="<?= e($basePath . '/public/track-request.php') ?>">View requests linked to your person reference</a>.</p>
        </div>
        <script>
        (function () {
            // Copy: modern clipboard API first, textarea fallback for older
            // mobile browsers inside the parish (no external library).
            document.querySelectorAll('[data-copy-person-reference]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var text = btn.dataset.copyPersonReference;
                    function done(ok) {
                        var originalLabel = btn.dataset.originalLabel;
                        btn.textContent = ok ? 'Copied!' : 'Copy failed';
                        setTimeout(function () { btn.textContent = originalLabel; }, 2000);
                    }
                    btn.dataset.originalLabel = btn.textContent;
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
            });
        })();
        </script>
