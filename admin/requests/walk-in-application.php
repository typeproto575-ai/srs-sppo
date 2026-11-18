<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/walk-in-intake.php';
require_login();
require_role('secretary');

$basePath = $basePath ?? base_path();
$pageTitle = 'Walk-in Pre-Application';
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
    'applicant_name' => '',
    'applicant_email' => '',
    'applicant_contact' => '',
    'baptism_venue' => '',
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
        $errors = walk_in_application_input_errors($_POST);
        if ($errors === []) {
            try {
                $application = walk_in_save_application($pdo, $_POST, current_user() ?? []);
                redirect_to('/admin/requests/applications-queue.php?open_application_id=' . (int) $application['id']);
            } catch (InvalidArgumentException $e) {
                $errors = array_filter(explode("\n", $e->getMessage()), static fn(string $message): bool => $message !== '');
            } catch (PDOException $e) {
                error_log('Walk-in application save failed (' . $e->getCode() . ') at ' . basename($e->getFile()) . ':' . $e->getLine());
                $errors[] = 'The pre-application could not be saved. Please try again.';
            } catch (JsonException|RuntimeException $e) {
                error_log('Walk-in application save failed (' . get_class($e) . ') at ' . basename($e->getFile()) . ':' . $e->getLine());
                $errors[] = 'The pre-application could not be saved. Please try again.';
            }
        }
    }
}

$selectedType = isset($types[$values['sacrament_type']]) ? $values['sacrament_type'] : '';
require_once __DIR__ . '/../../includes/admin-header.php';
?>

<section class="page-header">
    <div>
        <h1>Walk-in Pre-Application</h1>
        <p>Record the essentials at the counter. Verify documents and complete details in the application queue.</p>
    </div>
</section>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <p>Please correct the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="stacked-form" data-walk-in-intake autocomplete="off">
    <?= csrf_field() ?>

    <label for="walk-in-sacrament-type">Sacrament type <span class="required-marker" aria-hidden="true">*</span></label>
    <select id="walk-in-sacrament-type" name="sacrament_type" data-walk-in-type required autofocus>
        <option value="">Choose a sacrament</option>
        <?php foreach ($types as $type => $label): ?>
            <option value="<?= e($type) ?>"<?= $selectedType === $type ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <section class="card">
        <h2>Person</h2>
        <p class="field-hint">Search the master person index first. A new profile needs only a name and date of birth.</p>
        <?php
        $matchWidgetMode = 'intake';
        $matchRole = 'applicant';
        $matchLabel = 'Primary person';
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
        <legend>Bride</legend>
        <p class="field-hint">Search the master person index first, or create a profile with only a name and date of birth.</p>
        <?php
        $matchRole = 'spouse';
        $matchLabel = 'Bride';
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
        <legend>Applicant contact</legend>
        <label for="applicant-name">Applicant / contact name <span class="required-marker" aria-hidden="true">*</span></label>
        <input id="applicant-name" type="text" name="applicant_name" maxlength="150" value="<?= e($values['applicant_name']) ?>" required>
        <label for="applicant-email">Applicant email <span class="required-marker" aria-hidden="true">*</span></label>
        <input id="applicant-email" type="email" name="applicant_email" maxlength="150" value="<?= e($values['applicant_email']) ?>" required>
        <label for="applicant-contact">Contact number <span class="field-hint">(optional)</span></label>
        <input id="applicant-contact" type="tel" name="applicant_contact" maxlength="30" value="<?= e($values['applicant_contact']) ?>">
    </fieldset>

    <fieldset class="card" data-baptism-only<?= $selectedType === 'baptism' ? '' : ' hidden' ?>>
        <legend>Baptism preference</legend>
        <label for="baptism-venue">Preferred place / venue of baptism <span class="field-hint">(optional; parish confirmation required)</span></label>
        <input id="baptism-venue" type="text" name="baptism_venue" maxlength="150" value="<?= e($values['baptism_venue']) ?>">
    </fieldset>

    <p class="field-hint">Requirements are checked in person from the application queue. No documents or payment are collected on this screen.</p>
    <button type="submit" class="btn">Save and open application</button>
</form>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>
