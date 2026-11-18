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
require_once __DIR__ . '/../../includes/admin-header.php';
?>

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

<form method="post" class="stacked-form" data-walk-in-intake data-walk-in-certificate autocomplete="off">
    <?= csrf_field() ?>

    <label for="walk-in-certificate-type">Certificate type <span class="required-marker" aria-hidden="true">*</span></label>
    <select id="walk-in-certificate-type" name="sacrament_type" data-walk-in-type required autofocus>
        <option value="">Choose a sacrament</option>
        <?php foreach ($types as $type => $label): ?>
            <option value="<?= e($type) ?>"<?= $selectedType === $type ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <section class="card">
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

    <fieldset class="card" data-marriage-only<?= $selectedType === 'marriage' ? '' : ' hidden disabled' ?>>
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

    <fieldset class="card">
        <legend>Requester</legend>
        <label for="requester-relationship">Relationship to the subject <span class="required-marker" aria-hidden="true">*</span></label>
        <select id="requester-relationship" name="requester_relationship" required>
            <option value="self"<?= $selectedRelationship === 'self' ? ' selected' : '' ?>>Self</option>
            <option value="parent"<?= $selectedRelationship === 'parent' ? ' selected' : '' ?>>Parent</option>
            <option value="sibling"<?= $selectedRelationship === 'sibling' ? ' selected' : '' ?>>Sibling</option>
            <option value="child"<?= $selectedRelationship === 'child' ? ' selected' : '' ?>>Child</option>
            <option value="authorized_representative"<?= $selectedRelationship === 'authorized_representative' ? ' selected' : '' ?>>Authorized Representative</option>
        </select>
        <label for="requester-name">Requester name <span class="required-marker" aria-hidden="true">*</span></label>
        <input id="requester-name" type="text" name="requester_name" data-requester-name maxlength="150"
               value="<?= e($values['requester_name']) ?>"
               <?= $selectedRelationship === 'self' ? 'readonly' : 'required' ?>>
        <p class="field-hint">Self is copied from the selected profile. Enter the actual requester email; this page does not substitute staff email.</p>
        <p data-self-id-note<?= $selectedRelationship === 'self' ? '' : ' hidden' ?>>A valid ID matching the subject profile is required at pickup.</p>
        <label for="requester-email">Requester email <span class="required-marker" aria-hidden="true">*</span></label>
        <input id="requester-email" type="email" name="requester_email" maxlength="150" value="<?= e($values['requester_email']) ?>" required>
        <label for="requester-contact">Contact number <span class="field-hint">(optional)</span></label>
        <input id="requester-contact" type="tel" name="requester_contact" maxlength="30" value="<?= e($values['requester_contact']) ?>">
    </fieldset>

    <p class="field-hint">Documents and payment are handled in person. The request will open in the existing certificate queue detail.</p>
    <button type="submit" class="btn">Save and open request</button>
</form>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>
