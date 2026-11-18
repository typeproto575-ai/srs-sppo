<?php
/**
 * Renders the preparation list for one public sacrament application.
 *
 * Expects $applicationType to be one of the public sacrament-application
 * types. The shared checklist data is also used in application emails, while
 * remaining separate from certificate-request requirements.
 */
$applicationType = isset($applicationType) && is_string($applicationType) ? $applicationType : '';
$applicationRequirements = sacrament_application_requirements($applicationType);
$requirementsNoticePart = isset($requirementsNoticePart) && $requirementsNoticePart === 'after'
    ? 'after'
    : 'before';
?>
<section class="online-intake-notice requirements-notice" aria-label="<?= $requirementsNoticePart === 'after' ? 'What happens after submission' : 'Requirements to prepare' ?>">
    <?php if ($requirementsNoticePart === 'before'): ?>
    <strong>Documents and details to prepare</strong>
    <p>The Parish Office will confirm which requirements apply, accepted documents, copies, and schedules. Do not upload or send documents with this online form.</p>
    <ul>
        <?php foreach ($applicationRequirements['before'] as $requirement): ?>
            <li><?= e($requirement) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <strong>After submission: parish review and preparation</strong>
    <ol>
        <?php foreach ($applicationRequirements['after'] as $step): ?>
            <li><?= e($step) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</section>
