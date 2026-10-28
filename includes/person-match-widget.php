<?php
$matchRole = ($matchRole ?? 'applicant') === 'spouse' ? 'spouse' : 'applicant';
$matchLabel = $matchLabel ?? ($matchRole === 'spouse' ? 'Bride' : 'Applicant');
$matchWidgetMode = $matchWidgetMode ?? 'ajax';
?>
<?php if ($matchWidgetMode !== 'link-only'): ?>
<form method="post" class="person-match-form" data-person-match-widget novalidate
      data-person-link-endpoint="<?= e($basePath . '/admin/api/application-person-link.php') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="application_id" value="<?= (int) $matchApplicationId ?>">
    <input type="hidden" name="person_role" value="<?= e($matchRole) ?>">
    <input type="hidden" name="<?= e($matchRole === 'spouse' ? 'spouse_person_id' : 'person_id') ?>"
           data-person-id value="<?= (int) ($matchPersonId ?? 0) ?: '' ?>">
    <div class="person-finder" data-person-finder data-match-widget="true"
         data-endpoint="<?= e($basePath . '/admin/api/person-search.php') ?>">
        <label><?= e($matchLabel) ?> name
            <input autocomplete="off" type="search" data-person-search value="<?= e($matchName ?? '') ?>"
                   placeholder="Search master persons">
        </label>
        <label>Date of birth
            <input autocomplete="off" type="date" name="search_birth_date" data-person-birth-date
                   value="<?= e($matchBirthDate ?? '') ?>">
        </label>
        <div class="person-finder-selection" data-person-selection aria-live="polite"></div>
        <div class="person-finder-results" data-person-results aria-live="polite"></div>
        <button type="button" class="btn btn-secondary btn-small" data-person-find>Search people</button>
        <button type="button" class="btn btn-secondary btn-small" data-person-create-toggle>
            Can't find them? Create a person profile
        </button>
        <fieldset class="person-match-create" data-person-create-panel hidden>
            <legend>Create and link a master person profile</legend>
            <label>First name
                <input type="text" name="new_first_name" maxlength="150" autocomplete="given-name" required>
            </label>
            <label>Middle name
                <input type="text" name="new_middle_name" maxlength="100" autocomplete="additional-name">
            </label>
            <label>Last name
                <input type="text" name="new_last_name" maxlength="100" autocomplete="family-name" required>
            </label>
            <label>Date of birth
                <input type="date" name="new_birth_date">
            </label>
            <label>Father's name
                <input type="text" name="new_father_name" maxlength="150">
            </label>
            <label>Mother's maiden name
                <input type="text" name="new_mother_maiden_name" maxlength="150">
            </label>
            <label>Address
                <input type="text" name="new_address" maxlength="255" autocomplete="street-address">
            </label>
            <button type="button" class="btn btn-small" data-person-create>Create and link profile</button>
            <p class="field-hint">Only create a profile after checking the name and birth date for possible duplicates.</p>
        </fieldset>
        <p class="field-hint">Select a matching result to link it immediately. Confirm the name and birth date first.</p>
    </div>
</form>
<?php else: ?>
<div class="person-match-form">
    <?php if (!empty($matchLinkedPerson)): ?>
        <section class="person-match-linked" aria-label="Linked person profile">
            <strong>Linked profile: <?= e(implode(' ', array_filter([
                $matchLinkedPerson['first_name'],
                $matchLinkedPerson['middle_name'],
                $matchLinkedPerson['last_name'],
                $matchLinkedPerson['suffix'],
            ]))) ?></strong>
            <dl class="application-details-list">
                <div><dt>Date of birth</dt><dd><?= e($matchLinkedPerson['birth_date'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Father</dt><dd><?= e($matchLinkedPerson['father_name'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Mother's maiden name</dt><dd><?= e($matchLinkedPerson['mother_maiden_name'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Address</dt><dd><?= e($matchLinkedPerson['address'] ?? 'Not recorded') ?></dd></div>
            </dl>
        </section>
    <?php else: ?>
        <p>No master person profile linked yet.</p>
    <?php endif; ?>

    <form method="get" action="<?= e($basePath . '/admin/requests/applications-queue.php') ?>" class="person-match-search">
        <?php foreach ($matchReturnParams as $key => $value): ?>
            <?php if (is_scalar($value) && (string) $value !== ''): ?>
                <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <input type="hidden" name="match_search_application_id" value="<?= (int) $matchApplicationId ?>">
        <input type="hidden" name="match_search_role" value="<?= e($matchRole) ?>">
        <label><?= e($matchLabel) ?> surname
            <input autocomplete="off" type="search" name="match_surname"
                   value="<?= e($matchSearchApplicationId === (int) $matchApplicationId && $matchSearchRole === $matchRole ? $matchSurname : '') ?>"
                   placeholder="Enter surname">
        </label>
        <label>Date of birth
            <input autocomplete="off" type="date" name="match_birth_date"
                   value="<?= e($matchSearchApplicationId === (int) $matchApplicationId && $matchSearchRole === $matchRole ? $matchBirthDate : '') ?>">
        </label>
        <button type="submit" class="btn btn-secondary btn-small">Find people</button>
    </form>

    <?php if ($matchSearchApplicationId === (int) $matchApplicationId && $matchSearchRole === $matchRole): ?>
        <div class="person-finder-results" aria-live="polite">
            <?php if ($matchSearchError !== ''): ?>
                <p class="alert alert-error"><?= e($matchSearchError) ?></p>
            <?php elseif ($matchResults === []): ?>
                <p>No matching people found. Refine the surname or date of birth and search again.</p>
            <?php else: ?>
                <?php foreach ($matchResults as $person): ?>
                    <article class="person-finder-result">
                        <strong><?= e(implode(' ', array_filter([
                            $person['first_name'],
                            $person['middle_name'],
                            $person['last_name'],
                            $person['suffix'],
                        ]))) ?></strong>
                        <span>Date of birth: <?= e($person['birth_date'] ?? 'Not recorded') ?></span>
                        <span>Father: <?= e($person['father_name'] ?? 'Not recorded') ?></span>
                        <span>Mother's maiden name: <?= e($person['mother_maiden_name'] ?? 'Not recorded') ?></span>
                        <span>Address: <?= e($person['address'] ?? 'Not recorded') ?></span>
                        <form method="post" action="<?= e($basePath . '/admin/requests/applications-queue.php') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="link_person">
                            <input type="hidden" name="application_id" value="<?= (int) $matchApplicationId ?>">
                            <input type="hidden" name="person_role" value="<?= e($matchRole) ?>">
                            <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
                            <input type="hidden" name="return_query" value="<?= e($matchReturnQuery) ?>">
                            <button type="submit" class="btn btn-small">Link this person</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
