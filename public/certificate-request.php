<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';

$basePath = $basePath ?? base_path();
$pageTitle = 'Request a Certificate';

$values = [
    'certificate_type' => '',
    'subject_name' => '',
    'spouse_name' => '',
    'marriage_date' => '',
    'birth_date' => '',
    'father_name' => '',
    'mother_maiden_name' => '',
    'event_date' => '',
    'requester_name' => '',
    'requester_relationship' => 'self',
    'requester_relationship_other' => '',
    'requester_email' => '',
    'requester_contact_number' => '',
    'purpose' => 'School',
    'purpose_other' => '',
];
$errors = [];
$submitted = isset($_GET['submitted']) && $_GET['submitted'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$submitted) {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        foreach (array_keys($values) as $key) {
            if (isset($_POST[$key]) && is_string($_POST[$key])) {
                $values[$key] = trim($_POST[$key]);
            }
        }
        $honeypot = (isset($_POST['website']) && is_string($_POST['website'])) ? trim($_POST['website']) : '';
        if ($honeypot !== '') {
            header('Location: ' . $basePath . '/public/certificate-request.php?submitted=1');
            exit;
        }

        $typeLabels = [
            'baptism' => 'Baptism',
            'first_communion' => 'First Communion',
            'confirmation' => 'Confirmation',
            'marriage' => 'Marriage',
        ];
        $purposeLabels = ['School', 'Employment', 'Passport', 'Marriage', 'Personal records', 'Other'];
        $relationships = ['self', 'parent', 'guardian', 'spouse', 'sibling', 'child', 'other'];

        if (!isset($typeLabels[$values['certificate_type']])) {
            $errors[] = 'Choose a certificate type.';
        }
        if ($values['subject_name'] === '') {
            $errors[] = 'Enter the full name on the certificate.';
        } elseif (strlen($values['subject_name']) > 150) {
            $errors[] = 'The name on the certificate must be 150 characters or fewer.';
        }
        if ($values['certificate_type'] === 'marriage' && $values['spouse_name'] === '') {
            $errors[] = 'Enter the bride\'s full name.';
        }
        foreach (['birth_date', 'event_date', 'marriage_date'] as $dateKey) {
            if ($values[$dateKey] !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $values[$dateKey]);
                if (!$d || $d->format('Y-m-d') !== $values[$dateKey]) {
                    $errors[] = 'Enter a valid date.';
                    break;
                }
            }
        }
        if ($values['requester_name'] === '') {
            $errors[] = 'Enter your full name.';
        } elseif (strlen($values['requester_name']) > 150) {
            $errors[] = 'Your name must be 150 characters or fewer.';
        }
        if (!in_array($values['requester_relationship'], $relationships, true)) {
            $errors[] = 'Choose your relationship to the person named.';
        }
        if ($values['requester_relationship'] === 'other' && $values['requester_relationship_other'] === '') {
            $errors[] = 'Describe your relationship.';
        }
        if ($values['requester_relationship_other'] !== '' && strlen($values['requester_relationship_other']) > 100) {
            $errors[] = 'The relationship description must be 100 characters or fewer.';
        }
        if ($values['requester_email'] === '' || !filter_var($values['requester_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($values['requester_contact_number'] !== '' && strlen($values['requester_contact_number']) > 30) {
            $errors[] = 'The contact number must be 30 characters or fewer.';
        }
        if (!in_array($values['purpose'], $purposeLabels, true)) {
            $errors[] = 'Choose a purpose.';
        }
        if ($values['purpose'] === 'Other' && $values['purpose_other'] === '') {
            $errors[] = 'Describe the purpose.';
        }
        if ($values['purpose_other'] !== '' && strlen($values['purpose_other']) > 150) {
            $errors[] = 'The purpose details must be 150 characters or fewer.';
        }
        if (empty($_POST['privacy_consent'])) {
            $errors[] = 'You must agree to the privacy notice.';
        }

        if (empty($errors)) {
            $email = $values['requester_email'];
            if (public_request_throttled($pdo, $email)) {
                $errors[] = 'We already received several requests from this email. Please wait a while, or visit the parish office.';
            } else {
                $details = [];
                if ($values['birth_date'] !== '') {
                    $details['birth_date'] = $values['birth_date'];
                }
                if ($values['father_name'] !== '') {
                    $details['father_name'] = $values['father_name'];
                }
                if ($values['mother_maiden_name'] !== '') {
                    $details['mother_maiden_name'] = $values['mother_maiden_name'];
                }
                if ($values['event_date'] !== '') {
                    $details['event_date'] = $values['event_date'];
                }
                if ($values['certificate_type'] === 'marriage') {
                    if ($values['spouse_name'] !== '') {
                        $details['spouse_name'] = $values['spouse_name'];
                    }
                    if ($values['marriage_date'] !== '') {
                        $details['marriage_date'] = $values['marriage_date'];
                    }
                }
                $subjectDetails = empty($details) ? null : json_encode($details, JSON_UNESCAPED_UNICODE);
                $requesterRelationshipOther = ($values['requester_relationship'] === 'other') ? $values['requester_relationship_other'] : null;
                $purposeValue = $values['purpose'];
                $purposeOther = ($values['purpose'] === 'Other') ? $values['purpose_other'] : null;
                $requesterContact = ($values['requester_contact_number'] !== '') ? $values['requester_contact_number'] : null;
                $readableType = $typeLabels[$values['certificate_type']];

                $inserted = false;
                try {
                    for ($attempt = 0; $attempt < 3; $attempt++) {
                        $requestNo = generate_request_no($pdo, 'certificate_requests');
                        try {
                            $stmt = $pdo->prepare("INSERT INTO certificate_requests (request_no, channel, sacrament_type, subject_name, subject_details, requester_name, requester_relationship, requester_relationship_other, requester_email, requester_contact_number, purpose, purpose_other, privacy_consent_at, status) VALUES (?, 'online', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'new')");
                            $stmt->execute([
                                $requestNo,
                                $values['certificate_type'],
                                $values['subject_name'],
                                $subjectDetails,
                                $values['requester_name'],
                                $values['requester_relationship'],
                                $requesterRelationshipOther,
                                $values['requester_email'],
                                $requesterContact,
                                $purposeValue,
                                $purposeOther,
                            ]);
                            $inserted = true;
                            break;
                        } catch (PDOException $e) {
                            if ($e->getCode() === '23000' && $attempt < 2) {
                                continue;
                            }
                            throw $e;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('SRS-SPPO certificate request insert failed: ' . $e->getMessage());
                    $errors[] = 'We could not save your request just now. Please try again in a moment.';
                }

                if ($inserted) {
                    try {
                        mail_certificate_request_received($values['requester_email'], $values['requester_name'], $readableType . ' certificate');
                    } catch (Throwable $e) {
                        error_log('SRS-SPPO certificate request email failed: ' . $e->getMessage());
                    }
                    header('Location: ' . $basePath . '/public/certificate-request.php?submitted=1');
                    exit;
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($submitted): ?>
<section class="card">
    <h1>Thank you</h1>
    <p>Thank you. We received your request. Please visit the parish office to pay at the counter before processing begins; your request is pre-filled there so nothing needs to be retyped. We will email you when it is signed and ready for pickup.</p>
</section>
<?php else: ?>
<section class="card">
    <h1>Request a certificate</h1>
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e($basePath . '/public/certificate-request.php') ?>" class="stacked-form">
        <?= csrf_field() ?>
        <label for="certificate_type">Certificate type *</label>
        <select id="certificate_type" name="certificate_type" required>
            <option value="">-- Select --</option>
            <option value="baptism" <?= $values['certificate_type'] === 'baptism' ? 'selected' : '' ?>>Baptism</option>
            <option value="first_communion" <?= $values['certificate_type'] === 'first_communion' ? 'selected' : '' ?>>First Communion</option>
            <option value="confirmation" <?= $values['certificate_type'] === 'confirmation' ? 'selected' : '' ?>>Confirmation</option>
            <option value="marriage" <?= $values['certificate_type'] === 'marriage' ? 'selected' : '' ?>>Marriage</option>
        </select>

        <label for="subject_name"><?= $values['certificate_type'] === 'marriage' ? 'Groom\'s full name *' : 'Full name on the certificate *' ?></label>
        <input type="text" id="subject_name" name="subject_name" required maxlength="150" value="<?= e($values['subject_name']) ?>">

        <div id="marriage-only" <?= $values['certificate_type'] === 'marriage' ? '' : 'hidden' ?>>
            <label for="spouse_name">Bride\'s full name *</label>
            <input type="text" id="spouse_name" name="spouse_name" maxlength="150" value="<?= e($values['spouse_name']) ?>">
            <label for="marriage_date">Date of marriage (optional)</label>
            <input type="date" id="marriage_date" name="marriage_date" value="<?= e($values['marriage_date']) ?>">
        </div>

        <label for="birth_date">Date of birth (optional)</label>
        <input type="date" id="birth_date" name="birth_date" value="<?= e($values['birth_date']) ?>">

        <label for="father_name">Father\'s full name (optional)</label>
        <input type="text" id="father_name" name="father_name" maxlength="150" value="<?= e($values['father_name']) ?>">

        <label for="mother_maiden_name">Mother\'s maiden name (optional)</label>
        <input type="text" id="mother_maiden_name" name="mother_maiden_name" maxlength="150" value="<?= e($values['mother_maiden_name']) ?>">

        <label for="event_date">Approximate date of the sacrament (optional)</label>
        <input type="date" id="event_date" name="event_date" value="<?= e($values['event_date']) ?>">

        <label for="requester_name">Your full name *</label>
        <input type="text" id="requester_name" name="requester_name" required maxlength="150" value="<?= e($values['requester_name']) ?>">

        <label for="requester_relationship">Your relationship to the person named *</label>
        <select id="requester_relationship" name="requester_relationship" required>
            <option value="self" <?= $values['requester_relationship'] === 'self' ? 'selected' : '' ?>>Self</option>
            <option value="parent" <?= $values['requester_relationship'] === 'parent' ? 'selected' : '' ?>>Parent</option>
            <option value="guardian" <?= $values['requester_relationship'] === 'guardian' ? 'selected' : '' ?>>Guardian</option>
            <option value="spouse" <?= $values['requester_relationship'] === 'spouse' ? 'selected' : '' ?>>Spouse</option>
            <option value="sibling" <?= $values['requester_relationship'] === 'sibling' ? 'selected' : '' ?>>Sibling</option>
            <option value="child" <?= $values['requester_relationship'] === 'child' ? 'selected' : '' ?>>Child</option>
            <option value="other" <?= $values['requester_relationship'] === 'other' ? 'selected' : '' ?>>Other</option>
        </select>
        <div id="relationship-other" <?= $values['requester_relationship'] === 'other' ? '' : 'hidden' ?>>
            <label for="requester_relationship_other">Describe your relationship *</label>
            <input type="text" id="requester_relationship_other" name="requester_relationship_other" maxlength="100" value="<?= e($values['requester_relationship_other']) ?>">
        </div>

        <label for="requester_email">Email *</label>
        <input type="email" id="requester_email" name="requester_email" required maxlength="150" value="<?= e($values['requester_email']) ?>">

        <label for="requester_contact_number">Contact number (optional)</label>
        <input type="text" id="requester_contact_number" name="requester_contact_number" maxlength="30" value="<?= e($values['requester_contact_number']) ?>">

        <label for="purpose">Purpose *</label>
        <select id="purpose" name="purpose" required>
            <option value="School" <?= $values['purpose'] === 'School' ? 'selected' : '' ?>>School</option>
            <option value="Employment" <?= $values['purpose'] === 'Employment' ? 'selected' : '' ?>>Employment</option>
            <option value="Passport" <?= $values['purpose'] === 'Passport' ? 'selected' : '' ?>>Passport</option>
            <option value="Marriage" <?= $values['purpose'] === 'Marriage' ? 'selected' : '' ?>>Marriage</option>
            <option value="Personal records" <?= $values['purpose'] === 'Personal records' ? 'selected' : '' ?>>Personal records</option>
            <option value="Other" <?= $values['purpose'] === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
        <div id="purpose-other" <?= $values['purpose'] === 'Other' ? '' : 'hidden' ?>>
            <label for="purpose_other">Purpose details *</label>
            <input type="text" id="purpose_other" name="purpose_other" maxlength="150" value="<?= e($values['purpose_other']) ?>">
        </div>

        <p><strong>Before you submit</strong></p>
        <ul>
            <li>Payment is recorded at the counter before processing begins.</li>
            <li>Your online submission becomes a pre-filled request at the counter, so you will not need to retype anything.</li>
            <li>If you pick it up yourself, bring your valid government ID.</li>
            <li>If someone else picks it up for you, they bring their own valid ID, a signed authorization letter from you, and a copy of the valid ID of the person named in the certificate.</li>
            <li>Nothing is uploaded online. You show your documents at the counter.</li>
        </ul>

        <label><input type="checkbox" name="privacy_consent" value="1" required> I agree to the privacy notice.</label>

        <div style="position:absolute;left:-9999px;" aria-hidden="true">
            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <button type="submit" class="btn">Submit request</button>
    </form>
    <script>
        (function () {
            var typeSelect = document.getElementById('certificate_type');
            var marriageOnly = document.getElementById('marriage-only');
            var subjectLabel = document.querySelector('label[for="subject_name"]');
            function updateType() {
                var isMarriage = typeSelect.value === 'marriage';
                marriageOnly.hidden = !isMarriage;
                subjectLabel.textContent = isMarriage ? 'Groom\'s full name *' : 'Full name on the certificate *';
            }
            typeSelect.addEventListener('change', updateType);
            updateType();
            document.getElementById('requester_relationship').addEventListener('change', function () {
                document.getElementById('relationship-other').hidden = this.value !== 'other';
            });
            document.getElementById('purpose').addEventListener('change', function () {
                document.getElementById('purpose-other').hidden = this.value !== 'Other';
            });
        })();
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
