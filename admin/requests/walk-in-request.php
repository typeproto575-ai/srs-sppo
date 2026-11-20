<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/walk-in-intake.php';
require_login();
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Walk-in Certificate Request';
$types = walk_in_sacrament_types();
$errors = [];
$values = [
    'sacrament_type' => '',
    'person_id' => '',
    'person_search_name' => '',
    'person_first_name' => '',
    'person_middle_name' => '',
    'person_last_name' => '',
    'person_suffix' => '',
    'person_birth_date' => '',
    'spouse_person_id' => '',
    'spouse_search_name' => '',
    'spouse_first_name' => '',
    'spouse_middle_name' => '',
    'spouse_last_name' => '',
    'spouse_suffix' => '',
    'spouse_birth_date' => '',
    'requester_relationship' => 'self',
    'requester_name' => '',
    'requester_email' => '',
    'requester_contact' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $key) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $errors = walk_in_certificate_input_errors($_POST);
        if ($errors === []) {
            try {
                $request = walk_in_save_certificate_request($pdo, $_POST, current_user() ?? []);
                redirect_to('/admin/requests/certificate-detail.php?id=' . (int) $request['id']);
            } catch (InvalidArgumentException $e) {
                $errors = array_filter(explode("\n", $e->getMessage()), static fn(string $message): bool => $message !== '');
            } catch (PDOException $e) {
                error_log('Walk-in certificate request save failed (' . $e->getCode() . ') at ' . basename($e->getFile()) . ':' . $e->getLine());
                $errors[] = 'The certificate request could not be saved. Please try again.';
            } catch (JsonException|RuntimeException $e) {
                error_log('Walk-in certificate request save failed (' . get_class($e) . ') at ' . basename($e->getFile()) . ':' . $e->getLine());
                $errors[] = 'The certificate request could not be saved. Please try again.';
            }
        }
    }
}

$selectedType = isset($types[$values['sacrament_type']]) ? $values['sacrament_type'] : '';
$selectedRelationship = in_array($values['requester_relationship'], ['self', 'parent', 'sibling', 'child', 'authorized_representative'], true)
    ? $values['requester_relationship']
    : 'self';
$walkInFormId = 'walk-in-certificate-form';
require_once __DIR__ . '/../../includes/admin-header.php';
?>

<div class="walk-in-page">
<section class="page-header">
    <div>
        <h1>Walk-in Certificate Request</h1>
        <p>Record a counter request quickly. Match it to a sacramental register record from the request detail page.</p>
    </div>
</section>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p>Please correct the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form id="walk-in-certificate-form" method="post" class="walk-in-form walk-in-dedicated stacked-form" data-walk-in-dedicated="request" data-walk-in-intake data-walk-in-certificate autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="walk_in_current_step" value="1" data-dedicated-step>

    <section class="card walk-in-form-section" data-dedicated-panel="type">
        <h2>Request details</h2>
        <label for="walk-in-certificate-type">Certificate type <span class="required-marker" aria-hidden="true">*</span></label>
        <select id="walk-in-certificate-type" name="sacrament_type" data-walk-in-type required autofocus>
            <option value="">Choose a sacrament</option>
            <?php foreach ($types as $type => $label): ?>
                <option value="<?= e($type) ?>"<?= $selectedType === $type ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </section>

    <section class="card walk-in-form-section" data-dedicated-panel="subject">
        <h2>Certificate subject</h2>
        <p class="field-hint">Search the master person index first. A new profile needs only a name and date of birth.</p>
        <?php
        $matchWidgetMode = 'intake';
        $matchRole = 'applicant';
        $matchLabel = 'Primary subject';
        $matchFieldPrefix = 'person';
        $matchPersonId = $values['person_id'];
        $matchSearchName = $values['person_search_name'];
        $matchFirstName = $values['person_first_name'];
        $matchMiddleName = $values['person_middle_name'];
        $matchLastName = $values['person_last_name'];
        $matchSuffix = $values['person_suffix'];
        $matchBirthDate = $values['person_birth_date'];
        require __DIR__ . '/../../includes/person-match-widget.php';
        ?>
    </section>

    <fieldset class="card walk-in-form-section" data-dedicated-spouse data-marriage-only<?= $selectedType === 'marriage' ? '' : ' hidden disabled' ?>>
        <legend>Spouse</legend>
        <p class="field-hint">Search the master person index first, or create a profile with only a name and date of birth.</p>
        <?php
        $matchRole = 'spouse';
        $matchLabel = 'Bride / spouse';
        $matchFieldPrefix = 'spouse';
        $matchPersonId = $values['spouse_person_id'];
        $matchSearchName = $values['spouse_search_name'];
        $matchFirstName = $values['spouse_first_name'];
        $matchMiddleName = $values['spouse_middle_name'];
        $matchLastName = $values['spouse_last_name'];
        $matchSuffix = $values['spouse_suffix'];
        $matchBirthDate = $values['spouse_birth_date'];
        require __DIR__ . '/../../includes/person-match-widget.php';
        ?>
    </fieldset>

    <section class="card walk-in-form-section" data-dedicated-panel="requester">
        <h2>Requester</h2>
        <div class="walk-in-requester-grid">
            <div class="walk-in-field">
                <label for="requester-relationship">Relationship to the subject <span class="required-marker" aria-hidden="true">*</span></label>
                <select id="requester-relationship" form="walk-in-certificate-form" name="requester_relationship" required>
                    <option value="self"<?= $selectedRelationship === 'self' ? ' selected' : '' ?>>Self</option>
                    <option value="parent"<?= $selectedRelationship === 'parent' ? ' selected' : '' ?>>Parent</option>
                    <option value="sibling"<?= $selectedRelationship === 'sibling' ? ' selected' : '' ?>>Sibling</option>
                    <option value="child"<?= $selectedRelationship === 'child' ? ' selected' : '' ?>>Child</option>
                    <option value="authorized_representative"<?= $selectedRelationship === 'authorized_representative' ? ' selected' : '' ?>>Authorized Representative</option>
                </select>
            </div>
            <div class="walk-in-field">
                <label for="requester-name">Requester name <span class="required-marker" aria-hidden="true">*</span></label>
                <input id="requester-name" form="walk-in-certificate-form" type="text" name="requester_name" data-requester-name maxlength="150"
                       value="<?= e($values['requester_name']) ?>"
                       <?= $selectedRelationship === 'self' ? 'readonly' : 'required' ?>>
            </div>
            <div class="walk-in-field">
                <label for="requester-email">Requester email <span class="required-marker" aria-hidden="true">*</span></label>
                <input id="requester-email" form="walk-in-certificate-form" type="email" name="requester_email" maxlength="150" value="<?= e($values['requester_email']) ?>" required>
            </div>
            <div class="walk-in-field">
                <label for="requester-contact">Contact number <span class="field-hint">(optional)</span></label>
                <input id="requester-contact" form="walk-in-certificate-form" type="tel" name="requester_contact" maxlength="30" value="<?= e($values['requester_contact']) ?>">
            </div>
            <p class="field-hint walk-in-grid-full">Self is copied from the selected profile. Enter the actual requester email; this page does not substitute staff email.</p>
            <p class="walk-in-grid-full" data-self-id-note<?= $selectedRelationship === 'self' ? '' : ' hidden' ?>>A valid ID matching the subject profile is required at pickup.</p>
        </div>
    </section>

    <p class="field-hint">Documents and payment are handled in person. The request will open in the existing certificate queue detail.</p>
    <section class="card walk-in-form-section" data-dedicated-panel="review">
        <h2>Review</h2>
        <dl class="walk-in-dedicated-review" data-dedicated-review></dl>
    </section>
    <button type="submit" form="walk-in-certificate-form" class="btn" data-dedicated-submit hidden>Save and open request</button>
</form>
</div>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>
