<?php
/**
 * Shared first step for public certificate requests.
 * The selected mode is carried through the existing form POST and mapped to
 * certificate_requests.requester_relationship; no schema change is needed.
 */
function certificate_requester_mode(): string
{
    $mode = $_POST['requester_mode'] ?? $_GET['requester_mode'] ?? '';
    return in_array($mode, ['self', 'other'], true) ? $mode : '';
}

function certificate_requester_is_relative(string $relationship): bool
{
    return in_array($relationship, ['parent', 'spouse', 'sibling', 'child'], true);
}

function render_certificate_requester_choice(string $certificateLabel): void
{
    ?>
    <section class="requester-mode-choice" aria-labelledby="requester-mode-heading">
        <h2 id="requester-mode-heading">Who are you requesting this certificate for?</h2>
        <p class="field-hint">Choose one option before entering the certificate details.</p>
        <div class="hub-cards">
            <a class="hub-card" href="?requester_mode=self">
                <h3>For myself</h3>
                <p>I am the person named on this <?= e($certificateLabel) ?> request.</p>
                <strong>Continue as self &rarr;</strong>
            </a>
            <a class="hub-card" href="?requester_mode=other">
                <h3>For someone else</h3>
                <p>I am requesting this <?= e($certificateLabel) ?> on another person's behalf.</p>
                <strong>Continue for someone else &rarr;</strong>
            </a>
        </div>
    </section>
    <?php
}
