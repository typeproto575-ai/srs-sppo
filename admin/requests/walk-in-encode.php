<?php
/**
 * =====================================================================
 * SRS-SPPO WALK-IN CERTIFICATE REQUEST ENCODING (admin)
 * =====================================================================
 * Purpose: Lets the secretary encode a certificate request for a
 *          parishioner who walks into the office (phone request,
 *          no internet, etc.) - same data and flow as the public form,
 *          entered by staff on their behalf.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/record-lookup.php';
require_role('secretary');

// Subfolder-safe URL prefix for links on this page (base_path from functions.php).
$basePath = $basePath ?? base_path();

$pageTitle = 'Encode Walk-in Request';
$errors    = [];
$trackingNumber = null;

// CSRF guard: reject any POST whose token is missing or stale (see
// csrf_field() inside the form) before any validation or DB writes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Security token expired or invalid - please reload the page and submit again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    require_role('secretary');

    // --- Baptismal register search (staff-staged, no session) -----------------
    // bap_step=find: search the encoded register; bap_use_record: pick one.
    // Both re-render this same page. Neither writes to the database.
    $bapMatches = [];
    $bapState = ''; // '' | 'results' | 'resolved'
    $bapResolved = null; // loaded register row when a numeric id was picked
    $bapSearch = [
        'first_name'  => trim($_POST['bap_first_name'] ?? ''),
        'last_name'   => trim($_POST['bap_last_name'] ?? ''),
        'birthdate'   => trim($_POST['bap_birthdate'] ?? ''),
        'father_name' => trim($_POST['bap_father_name'] ?? ''),
        'mother_name' => trim($_POST['bap_mother_name'] ?? ''),
    ];
    if (($_POST['bap_step'] ?? '') === 'find') {
        if ($bapSearch['first_name'] === '' || $bapSearch['last_name'] === '') {
            $errors[] = 'First name and last name are required to search the register.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bapSearch['birthdate'])) {
            $errors[] = 'Please enter a valid birthdate to search the register.';
        } else {
            // Staff-only page: no record_search_allowed() / record_search_log().
            $bapMatches = find_baptismal_record_matches($pdo, $bapSearch);
            $bapState = 'results';
        }
    }
    if (array_key_exists('bap_use_record', $_POST)) {
        $choice = $_POST['bap_use_record'];
        if ($choice === 'manual') {
            $bapState = 'resolved'; // $bapResolved stays null -> manual inputs below
        } elseif (ctype_digit((string) $choice) && (int) $choice > 0) {
            $bapResolved = load_baptismal_record_for_request($pdo, (int) $choice);
            if ($bapResolved) {
                $bapState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
        } else {
            $errors[] = 'Please choose a register entry or continue manually.';
        }
    }
    // Re-derive the resolved state after a failed Encode: the Encode button
    // carries bap_matched_id but no bap_step/bap_use_record, so without this
    // the page would fall back to the bare search form and lose the pick.
    if ($bapState === '' && !array_key_exists('bap_use_record', $_POST)
        && ($_POST['sacrament_type'] ?? '') === 'baptismal'
        && array_key_exists('bap_matched_id', $_POST)) {
        $picked = (string) $_POST['bap_matched_id'];
        if ($picked === '') {
            $bapState = 'resolved'; // $bapResolved stays null -> sticky manual inputs below
        } elseif (ctype_digit($picked) && (int) $picked > 0) {
            $bapResolved = load_baptismal_record_for_request($pdo, (int) $picked);
            if ($bapResolved) {
                $bapState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
            // If the record vanished, fall back to the search state.
        }
    }

    // --- Confirmation register search (staff-staged, no session) ------------
    // conf_step=find: search the encoded register; conf_use_record: pick one.
    // Both re-render this same page. Neither writes to the database.
    $confMatches = [];
    $confState = ''; // '' | 'results' | 'resolved'
    $confResolved = null; // loaded register row when a numeric id was picked
    $confBapDate = null; // linked baptism_date for display only
    $confSearch = [
        'first_name'  => trim($_POST['conf_first_name'] ?? ''),
        'last_name'   => trim($_POST['conf_last_name'] ?? ''),
        'birthdate'   => trim($_POST['conf_birthdate'] ?? ''),
        'father_name' => trim($_POST['conf_father_name'] ?? ''),
        'mother_name' => trim($_POST['conf_mother_name'] ?? ''),
    ];
    if (($_POST['conf_step'] ?? '') === 'find') {
        if ($confSearch['first_name'] === '' || $confSearch['last_name'] === '') {
            $errors[] = 'First name and last name are required to search the register.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $confSearch['birthdate'])) {
            $errors[] = 'Please enter a valid birthdate to search the register.';
        } else {
            // Staff-only page: no record_search_allowed() / record_search_log().
            $confMatches = find_confirmation_record_matches($pdo, $confSearch);
            $confState = 'results';
        }
    }
    if (array_key_exists('conf_use_record', $_POST)) {
        $choice = $_POST['conf_use_record'];
        if ($choice === 'manual') {
            $confState = 'resolved'; // $confResolved stays null -> manual inputs below
        } elseif (ctype_digit((string) $choice) && (int) $choice > 0) {
            $confResolved = load_confirmation_record_for_request($pdo, (int) $choice);
            if ($confResolved) {
                $confState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
        } else {
            $errors[] = 'Please choose a register entry or continue manually.';
        }
    }
    // Re-derive the resolved state after a failed Encode: the Encode button
    // carries conf_matched_id but no conf_step/conf_use_record. Built in from
    // the start (not a follow-up), mirroring the Baptismal fix.
    if ($confState === '' && !array_key_exists('conf_use_record', $_POST)
        && ($_POST['sacrament_type'] ?? '') === 'confirmation'
        && array_key_exists('conf_matched_id', $_POST)) {
        $picked = (string) $_POST['conf_matched_id'];
        if ($picked === '') {
            $confState = 'resolved'; // $confResolved stays null -> sticky manual inputs below
        } elseif (ctype_digit($picked) && (int) $picked > 0) {
            $confResolved = load_confirmation_record_for_request($pdo, (int) $picked);
            if ($confResolved) {
                $confState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
            // If the record vanished, fall back to the search state.
        }
    }
    // Secondary display lookup (public confirmation.php lines 181-184, mirrored
    // exactly): baptism_date only, shown on the matched display and carried into
    // date_of_baptism on a matched Encode. null on the manual path.
    if ($confResolved && !empty($confResolved['baptismal_record_id'])) {
        $confBap = load_baptismal_record_for_request($pdo, (int) $confResolved['baptismal_record_id']);
        $confBapDate = $confBap['baptism_date'] ?? null;
    }

    // --- First Communion register search (staff-staged, no session) ---------
    // fc_step=find: search the encoded register; fc_use_record: pick one.
    // Both re-render this same page. Neither writes to the database.
    // NOTE: birth matching goes through the linked baptismal record (see
    // find_first_communion_record_matches()); nothing baptismal is stored here.
    $fcMatches = [];
    $fcState = ''; // '' | 'results' | 'resolved'
    $fcResolved = null; // loaded register row when a numeric id was picked
    $fcSearch = [
        'first_name'  => trim($_POST['fc_first_name'] ?? ''),
        'last_name'   => trim($_POST['fc_last_name'] ?? ''),
        'birthdate'   => trim($_POST['fc_birthdate'] ?? ''),
        'father_name' => trim($_POST['fc_father_name'] ?? ''),
        'mother_name' => trim($_POST['fc_mother_name'] ?? ''),
    ];
    if (($_POST['fc_step'] ?? '') === 'find') {
        if ($fcSearch['first_name'] === '' || $fcSearch['last_name'] === '') {
            $errors[] = 'First name and last name are required to search the register.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fcSearch['birthdate'])) {
            $errors[] = 'Please enter a valid birthdate to search the register.';
        } else {
            // Staff-only page: no record_search_allowed() / record_search_log().
            $fcMatches = find_first_communion_record_matches($pdo, $fcSearch);
            $fcState = 'results';
        }
    }
    if (array_key_exists('fc_use_record', $_POST)) {
        $choice = $_POST['fc_use_record'];
        if ($choice === 'manual') {
            $fcState = 'resolved'; // $fcResolved stays null -> manual inputs below
        } elseif (ctype_digit((string) $choice) && (int) $choice > 0) {
            $fcResolved = load_first_communion_record_for_request($pdo, (int) $choice);
            if ($fcResolved) {
                $fcState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
        } else {
            $errors[] = 'Please choose a register entry or continue manually.';
        }
    }
    // Re-derive the resolved state after a failed Encode: the Encode button
    // carries fc_matched_id but no fc_step/fc_use_record. Built in from
    // the start (not a follow-up), mirroring the Baptismal/Confirmation fix.
    if ($fcState === '' && !array_key_exists('fc_use_record', $_POST)
        && ($_POST['sacrament_type'] ?? '') === 'first_communion'
        && array_key_exists('fc_matched_id', $_POST)) {
        $picked = (string) $_POST['fc_matched_id'];
        if ($picked === '') {
            $fcState = 'resolved'; // $fcResolved stays null -> sticky manual inputs below
        } elseif (ctype_digit($picked) && (int) $picked > 0) {
            $fcResolved = load_first_communion_record_for_request($pdo, (int) $picked);
            if ($fcResolved) {
                $fcState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
            // If the record vanished, fall back to the search state.
        }
    }

    // --- Marriage register search (staff-staged, no session) ---------------
    // mar_step=find: search the encoded register; mar_use_record: pick one.
    // Both re-render this same page. Neither writes to the database.
    // NOTE: a couple is the identity here (names + date); no parent fields are
    // searched or carried, mirroring find_marriage_record_matches().
    $marMatches = [];
    $marState = ''; // '' | 'results' | 'resolved'
    $marResolved = null; // loaded register row when a numeric id was picked
    $marSearch = [
        'husband_first_name' => trim($_POST['husband_first_name'] ?? ''),
        'husband_last_name'  => trim($_POST['husband_last_name'] ?? ''),
        'wife_first_name'    => trim($_POST['wife_first_name'] ?? ''),
        'wife_last_name'     => trim($_POST['wife_last_name'] ?? ''),
        'marriage_date'      => trim($_POST['mar_marriage_date'] ?? ''),
    ];
    if (($_POST['mar_step'] ?? '') === 'find') {
        if ($marSearch['husband_first_name'] === '' || $marSearch['husband_last_name'] === '') {
            $errors[] = "Groom's first name and last name are required to search the register.";
        } elseif ($marSearch['wife_first_name'] === '' || $marSearch['wife_last_name'] === '') {
            $errors[] = "Bride's first name and maiden last name are required to search the register.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $marSearch['marriage_date'])) {
            $errors[] = 'Please enter a valid marriage date to search the register.';
        } else {
            // Staff-only page: no record_search_allowed() / record_search_log().
            $marMatches = find_marriage_record_matches($pdo, $marSearch);
            $marState = 'results';
        }
    }
    if (array_key_exists('mar_use_record', $_POST)) {
        $choice = $_POST['mar_use_record'];
        if ($choice === 'manual') {
            $marState = 'resolved'; // $marResolved stays null -> manual inputs below
        } elseif (ctype_digit((string) $choice) && (int) $choice > 0) {
            $marResolved = load_marriage_record_for_request($pdo, (int) $choice);
            if ($marResolved) {
                $marState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
        } else {
            $errors[] = 'Please choose a register entry or continue manually.';
        }
    }
    // Re-derive the resolved state after a failed Encode: the Encode button
    // carries mar_matched_id but no mar_step/mar_use_record. Built in from
    // the start (not a follow-up), mirroring the three shipped stages.
    if ($marState === '' && !array_key_exists('mar_use_record', $_POST)
        && ($_POST['sacrament_type'] ?? '') === 'marriage'
        && array_key_exists('mar_matched_id', $_POST)) {
        $picked = (string) $_POST['mar_matched_id'];
        if ($picked === '') {
            $marState = 'resolved'; // $marResolved stays null -> sticky manual inputs below
        } elseif (ctype_digit($picked) && (int) $picked > 0) {
            $marResolved = load_marriage_record_for_request($pdo, (int) $picked);
            if ($marResolved) {
                $marState = 'resolved';
            } else {
                $errors[] = 'That register entry is no longer available. Please search again.';
            }
            // If the record vanished, fall back to the search state.
        }
    }

    $sacramentType = $_POST['sacrament_type'] ?? '';
    // Find/Use-record clicks are navigation steps: they re-render the page and
    // must neither run sacrament-field validation nor reach the insert below.
    $isSearchStep = array_key_exists('bap_step', $_POST) || array_key_exists('conf_step', $_POST)
        || array_key_exists('fc_step', $_POST) || array_key_exists('mar_step', $_POST)
        || array_key_exists('bap_use_record', $_POST) || array_key_exists('conf_use_record', $_POST)
        || array_key_exists('fc_use_record', $_POST) || array_key_exists('mar_use_record', $_POST);
    $validTypes = ['baptismal', 'confirmation', 'first_communion', 'marriage'];

    $requesterName          = trim($_POST['requester_name'] ?? '');
    $requesterRelationship  = $_POST['requester_relationship'] ?? '';
    $requesterRelOther      = trim($_POST['requester_relationship_other'] ?? '');
    $requesterEmail         = trim($_POST['requester_email'] ?? '');
    $requesterMailingAddress = trim($_POST['requester_mailing_address'] ?? '');
    $requesterContact       = null; // Walk-in requests do not collect a phone number.
    $purpose      = $_POST['purpose'] ?? '';
    $purposeOther = trim($_POST['purpose_other'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    // Walk-in counter flow: request -> pay -> process. If the fee was already
    // paid at the counter, record that fact now (paid_at + paid_by). No amount
    // is stored; the system never takes money. Defaults to not-paid.
    $markPaid = ($_POST['payment_received'] ?? '') === '1';

    // Find/Use-record clicks skip these checks; only a real Encode needs them.
    if (!$isSearchStep) {
        if (!in_array($sacramentType, $validTypes, true)) $errors[] = 'Select a sacrament type.';
        if ($requesterName === '')         $errors[] = 'Requester name is required.';
        if ($requesterRelationship === '') $errors[] = 'Relationship is required.';
        if ($requesterEmail === '')        $errors[] = 'Email address is required.';
        if (strlen($requesterMailingAddress) > 500) $errors[] = 'Mailing address must be 500 characters or fewer.';
        if ($purpose === '')               $errors[] = 'Purpose is required.';
    }

    // Sacrament-specific fields: each type stores its details in a
    // companion table keyed by request_id (same as the public forms).
    $extTable = null;
    $extCols  = [];
    $extVals  = [];

    if ($sacramentType === 'baptismal') {
        $extTable = 'baptismal_certificate_requests';
        $extCols  = ['child_full_name','father_full_name','mother_full_name','birthplace','birthdate','date_of_baptism','officiating_minister','sponsors_text','book_no','page_no','line_no','matched_record_id'];
        $matchedId = (($_POST['bap_matched_id'] ?? '') !== '' && ctype_digit((string) $_POST['bap_matched_id']))
            ? (int) $_POST['bap_matched_id'] : null;
        if (!$isSearchStep && ($sacramentType === 'baptismal') && ($bapState === '') && !array_key_exists('bap_matched_id', $_POST)) {
            $errors[] = "Search the register or choose 'No match — continue manually' before encoding.";
        } elseif (!$isSearchStep) {
            $childName = trim($_POST['bap_child_full_name'] ?? '');
            $fatherName = trim($_POST['bap_father_full_name'] ?? '');
            $motherName = trim($_POST['bap_mother_full_name'] ?? '');
            if ($childName === '') $errors[] = "Child's full name is required.";
            if ($fatherName === '') $errors[] = "Father's full name is required.";
            if ($motherName === '') $errors[] = "Mother's full name is required.";
            $birthdateVal = $_POST['bap_birthdate'] ?? '';
            if ($birthdateVal === '') $errors[] = 'Birthdate is required.'; // column is NOT NULL; register rows always have one
            $extVals = [$childName, $fatherName, $motherName, trim($_POST['bap_birthplace'] ?? ''), $birthdateVal,
                        ($_POST['bap_date_of_baptism'] ?? '') ?: null, trim($_POST['bap_officiating_minister'] ?? ''),
                        '', '', '', '', $matchedId];
        }
    } elseif ($sacramentType === 'confirmation') {
        $extTable = 'confirmation_certificate_requests';
        $extCols  = ['confirmand_full_name','father_full_name','mother_full_name','date_of_confirmation','date_of_baptism','place_of_baptism','officiating_minister','sponsor_name','book_no','page_no','line_no','matched_record_id'];
        $confMatchedId = (($_POST['conf_matched_id'] ?? '') !== '' && ctype_digit((string) $_POST['conf_matched_id']))
            ? (int) $_POST['conf_matched_id'] : null;
        if (!$isSearchStep && ($sacramentType === 'confirmation') && ($confState === '') && !array_key_exists('conf_matched_id', $_POST)) {
            $errors[] = "Search the register or choose 'No match — continue manually' before encoding.";
        } elseif (!$isSearchStep) {
            $confirmandName = trim($_POST['conf_confirmand_full_name'] ?? '');
            $confFatherName = trim($_POST['conf_father_full_name'] ?? '');
            // Matched state carries conf_mother_full_name; the manual inputs post
            // conf_mother_maiden_name - read whichever was posted.
            $confMotherName = trim($_POST['conf_mother_full_name'] ?? $_POST['conf_mother_maiden_name'] ?? '');
            if ($confirmandName === '') $errors[] = "Confirmand's full name is required.";
            if ($confFatherName === '') $errors[] = "Father's full name is required.";
            if ($confMotherName === '') $errors[] = "Mother's full name is required.";
            $extVals = [$confirmandName, $confFatherName, $confMotherName,
                        ($_POST['conf_date_of_confirmation'] ?? '') ?: null, $confBapDate, '',
                        trim($_POST['conf_officiating_minister'] ?? ''), trim($_POST['conf_sponsor_name'] ?? ''),
                        '', '', '', $confMatchedId];
        }
    } elseif ($sacramentType === 'first_communion') {
        $extTable = 'first_communion_certificate_requests';
        $extCols  = ['communicant_full_name','father_full_name','mother_full_name','date_of_first_communion','officiating_minister','book_no','page_no','line_no','matched_record_id'];
        $fcMatchedId = (($_POST['fc_matched_id'] ?? '') !== '' && ctype_digit((string) $_POST['fc_matched_id']))
            ? (int) $_POST['fc_matched_id'] : null;
        if (!$isSearchStep && ($sacramentType === 'first_communion') && ($fcState === '') && !array_key_exists('fc_matched_id', $_POST)) {
            $errors[] = "Search the register or choose 'No match — continue manually' before encoding.";
        } elseif (!$isSearchStep) {
            $communicantName = trim($_POST['fc_communicant_full_name'] ?? '');
            $fcFatherName = trim($_POST['fc_father_full_name'] ?? '');
            // Manual posts fc_mother_maiden_name; matched carries
            // fc_mother_full_name - read whichever was posted.
            $fcMotherName = trim($_POST['fc_mother_full_name'] ?? $_POST['fc_mother_maiden_name'] ?? '');
            if ($communicantName === '') $errors[] = "Communicant's full name is required.";
            if ($fcFatherName === '') $errors[] = "Father's full name is required.";
            if ($fcMotherName === '') $errors[] = "Mother's full name is required.";
            $extVals = [$communicantName, $fcFatherName, $fcMotherName,
                        ($_POST['fc_date_of_first_communion'] ?? '') ?: null, trim($_POST['fc_officiating_minister'] ?? ''),
                        '', '', '', $fcMatchedId];
        }
    } elseif ($sacramentType === 'marriage') {
        $extTable = 'marriage_certificate_requests';
        $extCols  = ['party_a_full_name','party_a_father_name','party_a_mother_name','party_a_baptized_in_church_of','party_a_date_of_baptism','party_a_book_no','party_a_page_no','party_a_line_no','party_b_full_name','party_b_father_name','party_b_mother_name','party_b_baptized_in_church_of','party_b_date_of_baptism','party_b_book_no','party_b_page_no','party_b_line_no','marriage_date','officiating_minister','matched_record_id'];
        $marMatchedId = (($_POST['mar_matched_id'] ?? '') !== '' && ctype_digit((string) $_POST['mar_matched_id']))
            ? (int) $_POST['mar_matched_id'] : null;
        if (!$isSearchStep && ($sacramentType === 'marriage') && ($marState === '') && !array_key_exists('mar_matched_id', $_POST)) {
            $errors[] = "Search the register or choose 'No match — continue manually' before encoding.";
        } elseif (!$isSearchStep) {
            $husbandName = trim($_POST['party_a_full_name'] ?? '');
            $wifeName    = trim($_POST['party_b_full_name'] ?? '');
            if ($husbandName === '') $errors[] = "Party A's full name is required.";
            if ($wifeName === '')    $errors[] = "Party B's full name is required.";
            $extVals = [$husbandName, trim($_POST['party_a_father_name'] ?? ''), trim($_POST['party_a_mother_name'] ?? ''),
                        '', null,
                        '', '', '',
                        $wifeName, trim($_POST['party_b_father_name'] ?? ''), trim($_POST['party_b_mother_name'] ?? ''),
                        '', null,
                        '', '', '',
                        trim($_POST['mar_marriage_date'] ?? '') !== '' ? $_POST['mar_marriage_date'] : null, trim($_POST['mar_officiating_minister'] ?? ''),
                        $marMatchedId];
        }
    }

    if (empty($errors) && !$isSearchStep) {
        try {
            $trackingNumber = retry_on_duplicate($pdo, function () use ($pdo, $sacramentType, $requesterName, $requesterRelationship, $requesterRelOther, $requesterEmail, $requesterMailingAddress, $requesterContact, $purpose, $purposeOther, $notes, $markPaid, $extTable, $extCols, $extVals): string {
            $trackingNumber = generate_tracking_number($pdo, 'certificate_requests');

            $paidAt = $markPaid ? date('Y-m-d H:i:s') : null;
            $paidBy = $markPaid ? current_user()['id'] : null;

            $stmt = $pdo->prepare(
                "INSERT INTO certificate_requests
                    (tracking_number, submission_channel, sacrament_type, requester_name, requester_relationship,
                     requester_relationship_other, requester_email, requester_contact_number,
                     requester_mailing_address, purpose, purpose_other, notes, status, paid_at, paid_by)
                 VALUES (?, 'onsite', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
            );
            $stmt->execute([
                $trackingNumber, $sacramentType, $requesterName, $requesterRelationship, $requesterRelOther,
                $requesterEmail, $requesterContact, $requesterMailingAddress !== '' ? $requesterMailingAddress : null,
                $purpose, $purposeOther, $notes, $paidAt, $paidBy,
            ]);
            $requestId = (int) $pdo->lastInsertId();

            $placeholders = implode(', ', array_fill(0, count($extCols) + 1, '?'));
            $colsSql = implode(', ', array_merge(['request_id'], $extCols));
            $stmt = $pdo->prepare("INSERT INTO {$extTable} ({$colsSql}) VALUES ({$placeholders})");
            $stmt->execute(array_merge([$requestId], $extVals));

            log_activity($pdo, current_user()['id'], 'walk_in_request_encoded', 'certificate_requests', $requestId,
                "Walk-in {$sacramentType} certificate request encoded for {$requesterName}" . ($markPaid ? ' (payment recorded)' : ''));

            notify($pdo, 'request_new', 'New walk-in certificate request',
                "Walk-in {$sacramentType} certificate request for {$requesterName} ({$trackingNumber})",
                '/admin/requests/queue.php?status=pending');

            return $trackingNumber;
        });
        } catch (Exception $e) {
            error_log('SRS-SPPO walk-in certificate request insert failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving this request. Please try again.';
        }
    }
}
// Shared staff shell: top bar + sidebar + <head> (admin-footer.php closes the layout).
require_once __DIR__ . '/../../includes/admin-header.php';
?>
        <div class="walk-in-page walk-in-request-page">
        <header class="admin-queue-heading">
            <div>
                <p class="admin-queue-kicker">In-person service</p>
                <h1>Encode a certificate request</h1>
                <p>Use this form when a parishioner requests a certificate at the office.</p>
            </div>
        </header>

        <?php if ($trackingNumber): ?>
            <div class="alert alert-success">
                <p>Request encoded. Tracking number:</p>
                <p class="tracking-number"><strong><?= e($trackingNumber) ?></strong></p>
                <p>Print a tracking slip to hand to the requester or mail to the address below.</p>
                <?php
                $doneLabel = ['baptismal' => 'Baptismal', 'confirmation' => 'Confirmation', 'first_communion' => 'First Communion', 'marriage' => 'Marriage'][$sacramentType] ?? $sacramentType;
                $doneName = $sacramentType === 'baptismal' ? ($childName ?? '') : ($sacramentType === 'confirmation' ? ($confirmandName ?? '') : ($sacramentType === 'first_communion' ? ($communicantName ?? '') : ''));
                $doneId = $sacramentType === 'baptismal' ? $matchedId : ($sacramentType === 'confirmation' ? $confMatchedId : ($sacramentType === 'first_communion' ? $fcMatchedId : ($sacramentType === 'marriage' ? $marMatchedId : null)));
                ?>
                <dl class="review-list">
                    <dt>Sacrament</dt><dd><?= e($doneLabel) ?></dd>
                    <?php if ($sacramentType === 'marriage'): ?>
                        <dt>Groom</dt><dd><?= e($husbandName ?? '') ?></dd>
                        <dt>Bride</dt><dd><?= e($wifeName ?? '') ?></dd>
                    <?php else: ?>
                        <dt>Name</dt><dd><?= e($doneName) ?></dd>
                    <?php endif; ?>
                    <dt>Source</dt><dd><?= $doneId !== null ? 'Matched to register entry' : 'Encoded manually' ?></dd>
                    <dt>Payment</dt><dd><?= (($_POST['payment_received'] ?? '') === '1') ? 'Received at the counter and recorded' : 'Not yet recorded' ?></dd>
                    <dt>Requester</dt><dd><?= e($requesterName) ?></dd>
                    <dt>Relationship</dt><dd><?= e($requesterRelationship) ?></dd>
                    <dt>Purpose</dt><dd><?= e($purpose) ?></dd>
                </dl>
            </div>
            <section class="walk-in-tracking-slip" aria-labelledby="walk-in-slip-title">
                <p class="walk-in-slip-parish">St. Patrick's Parish Office</p>
                <h2 id="walk-in-slip-title">Certificate Request Tracking Slip</h2>
                <p class="walk-in-slip-number"><?= e($trackingNumber) ?></p>
                <p>Keep this number to check the request status with the email address provided.</p>
                <dl>
                    <dt>Requester</dt><dd><?= e($requesterName) ?></dd>
                    <dt>Certificate</dt><dd><?= e($doneLabel) ?></dd>
                    <?php if ($requesterMailingAddress !== ''): ?>
                        <dt>Mail to</dt><dd><?= nl2br(e($requesterMailingAddress)) ?></dd>
                    <?php endif; ?>
                    <dt>Date</dt><dd><?= e(date('F j, Y')) ?></dd>
                </dl>
                <p class="walk-in-slip-note">This slip is a tracking reference only. It is not a certificate or proof of approval.</p>
                <button type="button" class="btn btn-secondary walk-in-slip-print" id="print-walk-in-slip">Print Tracking Slip</button>
            </section>
            <script>
                (function () {
                    var button = document.getElementById('print-walk-in-slip');
                    if (!button) return;
                    button.addEventListener('click', function () {
                        document.body.classList.add('walk-in-print-mode');
                        window.print();
                    });
                    window.addEventListener('afterprint', function () {
                        document.body.classList.remove('walk-in-print-mode');
                    });
                })();
            </script>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form autocomplete="off" method="post" action="" class="walk-in-form walk-in-wizard" data-walk-in-kind="request">
            <?= csrf_field() ?><!-- CSRF token: pairs with the guard above -->
            <input type="hidden" name="walk_in_phase" value="<?= e((string) ($_POST['walk_in_phase'] ?? '1')) ?>" data-walk-in-current-step>
            <fieldset data-walk-in-phase="type">
                <legend>Sacrament Type</legend>
                <label>Type *
                    <select name="sacrament_type" id="sacrament_type" required onchange="showSection(this.value)">
                        <option value="">-- Select --</option>
                        <option value="baptismal" <?= (($_POST['sacrament_type'] ?? '') === 'baptismal') ? 'selected' : '' ?>>Baptismal</option>
                        <option value="confirmation" <?= (($_POST['sacrament_type'] ?? '') === 'confirmation') ? 'selected' : '' ?>>Confirmation</option>
                        <option value="first_communion" <?= (($_POST['sacrament_type'] ?? '') === 'first_communion') ? 'selected' : '' ?>>First Communion</option>
                        <option value="marriage" <?= (($_POST['sacrament_type'] ?? '') === 'marriage') ? 'selected' : '' ?>>Marriage</option>
                    </select>
                </label>
            </fieldset>

            <fieldset data-walk-in-phase="contact">
                <legend>Requester Information</legend>
                <label>Requester's Full Name * <input autocomplete="off" type="text" name="requester_name" value="<?= e($_POST['requester_name'] ?? '') ?>" required></label>
                <label>Relationship *
                    <select name="requester_relationship" required>
                        <option value="">-- Select --</option>
                        <option value="self">Self</option>
                        <option value="parent">Parent</option>
                        <option value="guardian">Guardian</option>
                        <option value="spouse">Spouse</option>
                        <option value="sibling">Sibling</option>
                        <option value="child">Child</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other <input autocomplete="off" type="text" name="requester_relationship_other" value="<?= e($_POST['requester_relationship_other'] ?? '') ?>"></label>
                <label>Email Address * <input autocomplete="off" type="email" name="requester_email" value="<?= e($_POST['requester_email'] ?? '') ?>" required></label>
                <label>Postal Mailing Address <textarea name="requester_mailing_address" rows="3" maxlength="500" autocomplete="street-address"><?= e($_POST['requester_mailing_address'] ?? '') ?></textarea>
                    <span class="field-hint">Optional. Enter this if the requester would like the tracking slip mailed to them. The parish must print and mail it manually; entering an address does not send anything automatically.</span>
                </label>
            </fieldset>

            <div id="section-baptismal" class="sacrament-section" data-walk-in-phase="record" style="display:none;">
                <?php
                $bapState = $bapState ?? '';
                $bapResolved = $bapResolved ?? null;
                $bapInteraction = $bapState !== '' || ($_POST['bap_step'] ?? '') === 'find'
                    || array_key_exists('bap_use_record', $_POST) || array_key_exists('bap_matched_id', $_POST);
                ?>
                <?php if ($bapInteraction): ?>
                <script>
                    // Re-open this section after a find/use/encode POST: the type
                    // select resets on re-render and nothing else calls showSection.
                    window.addEventListener('load', function () {
                        var sel = document.getElementById('sacrament_type');
                        if (sel) sel.value = 'baptismal';
                        showSection('baptismal');
                    });
                </script>
                <?php endif; ?>
                <?php if ($bapState === 'resolved'): ?>
                    <?php if ($bapResolved): ?>
                        <fieldset>
                            <legend>Matched Register Entry</legend>
                            <dl class="review-list">
                                <dt>Name</dt><dd><?= e($bapResolved['child_full_name']) ?></dd>
                                <dt>Birthdate</dt><dd><?= e($bapResolved['birth_date']) ?></dd>
                                <dt>Baptism date</dt><dd><?= e($bapResolved['baptism_date'] ?: 'date not recorded') ?></dd>
                                <dt>Father</dt><dd><?= e($bapResolved['father_name'] ?? '') ?></dd>
                                <dt>Mother</dt><dd><?= e($bapResolved['mother_name'] ?: ($bapResolved['mother_maiden_name'] ?? '')) ?></dd>
                                <dt>Minister</dt><dd><?= e($bapResolved['minister_name'] ?? '') ?></dd>
                            </dl>
                            <input type="hidden" name="bap_matched_id" value="<?= (int) $bapResolved['id'] ?>">
                            <input type="hidden" name="bap_child_full_name" value="<?= e($bapResolved['child_full_name']) ?>">
                            <input type="hidden" name="bap_father_full_name" value="<?= e($bapResolved['father_name'] ?? '') ?>">
                            <input type="hidden" name="bap_mother_full_name" value="<?= e($bapResolved['mother_name'] ?: ($bapResolved['mother_maiden_name'] ?? '')) ?>">
                            <input type="hidden" name="bap_birthdate" value="<?= e($bapResolved['birth_date']) ?>">
                            <input type="hidden" name="bap_date_of_baptism" value="<?= e($bapResolved['baptism_date'] ?? '') ?>">
                            <input type="hidden" name="bap_officiating_minister" value="<?= e($bapResolved['minister_name'] ?? '') ?>">
                        </fieldset>
                    <?php else: ?>
                        <fieldset>
                            <legend>Baptismal Details (manual)</legend>
                            <label>Child's Full Name * <input autocomplete="off" type="text" name="bap_child_full_name" value="<?= e($_POST['bap_child_full_name'] ?? '') ?>"></label>
                            <label>Father's Full Name * <input autocomplete="off" type="text" name="bap_father_full_name" value="<?= e($_POST['bap_father_full_name'] ?? '') ?>"></label>
                            <label>Mother's Full Name * <input autocomplete="off" type="text" name="bap_mother_full_name" value="<?= e($_POST['bap_mother_full_name'] ?? '') ?>"></label>
                            <label>Birthdate * <input autocomplete="off" type="date" name="bap_birthdate" value="<?= e($_POST['bap_birthdate'] ?? '') ?>"></label>
                            <label>Birthplace <input autocomplete="off" type="text" name="bap_birthplace" value="<?= e($_POST['bap_birthplace'] ?? '') ?>"></label>
                            <label>Date of Baptism <input autocomplete="off" type="date" name="bap_date_of_baptism" value="<?= e($_POST['bap_date_of_baptism'] ?? '') ?>"></label>
                            <label>Officiating Minister <input autocomplete="off" type="text" name="bap_officiating_minister" value="<?= e($_POST['bap_officiating_minister'] ?? '') ?>"></label>
                            <input type="hidden" name="bap_matched_id" value="">
                        </fieldset>
                    <?php endif; ?>
                <?php else: ?>
                    <fieldset>
                        <legend>Baptismal Record Details</legend>
                        <p class="field-hint">Search the encoded register first. Use the child's name and birth details as they appear in the record.</p>
                        <div class="inline-fields">
                            <label>First name <input autocomplete="off" type="text" name="bap_first_name" value="<?= e($_POST['bap_first_name'] ?? '') ?>"></label>
                            <label>Last name <input autocomplete="off" type="text" name="bap_last_name" value="<?= e($_POST['bap_last_name'] ?? '') ?>"></label>
                        </div>
                        <label>Birthdate <input autocomplete="off" type="date" name="bap_birthdate" value="<?= e($_POST['bap_birthdate'] ?? '') ?>"></label>
                        <label>Father's full name <input autocomplete="off" type="text" name="bap_father_name" value="<?= e($_POST['bap_father_name'] ?? '') ?>"></label>
                        <label>Mother's maiden name <input autocomplete="off" type="text" name="bap_mother_name" value="<?= e($_POST['bap_mother_name'] ?? '') ?>"></label>
                        <button type="submit" name="bap_step" value="find" formnovalidate class="btn btn-secondary">Find in Register</button>
                    </fieldset>
                    <?php if ($bapState === 'results'): ?>
                        <fieldset>
                            <legend>Register Matches</legend>
                            <?php if ($bapMatches): ?>
                                <p class="field-hint">Pick the exact register entry, or continue manually.</p>
                                <?php foreach ($bapMatches as $m): ?>
                                    <div class="record-card">
                                        <strong><?= e($m['child_full_name']) ?></strong>
                                        <span>Baptized <?= e($m['baptism_date'] ?: 'date not recorded') ?></span>
                                        <button type="submit" name="bap_use_record" value="<?= (int) $m['id'] ?>" formnovalidate class="btn btn-small">Use this record</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No matching register entry found. Older entries may not be encoded yet.</p>
                            <?php endif; ?>
                            <button type="submit" name="bap_use_record" value="manual" formnovalidate class="btn btn-secondary btn-small">No match — continue manually</button>
                        </fieldset>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div id="section-confirmation" class="sacrament-section" data-walk-in-phase="record" style="display:none;">
                <?php
                $confState = $confState ?? '';
                $confResolved = $confResolved ?? null;
                $confInteraction = $confState !== '' || ($_POST['conf_step'] ?? '') === 'find'
                    || array_key_exists('conf_use_record', $_POST) || array_key_exists('conf_matched_id', $_POST);
                ?>
                <?php if ($confInteraction): ?>
                <script>
                    // Re-open this section after a find/use/encode POST: the type
                    // select resets on re-render and nothing else calls showSection.
                    window.addEventListener('load', function () {
                        var sel = document.getElementById('sacrament_type');
                        if (sel) sel.value = 'confirmation';
                        showSection('confirmation');
                    });
                </script>
                <?php endif; ?>
                <?php if ($confState === 'resolved'): ?>
                    <?php if ($confResolved): ?>
                        <fieldset>
                            <legend>Matched Register Entry</legend>
                            <dl class="review-list">
                                <dt>Name</dt><dd><?= e($confResolved['confirmand_full_name']) ?></dd>
                                <dt>Birthdate</dt><dd><?= e($confResolved['birthdate']) ?></dd>
                                <dt>Confirmation date</dt><dd><?= e($confResolved['confirmation_date'] ?: 'date not recorded') ?></dd>
                                <dt>Father</dt><dd><?= e($confResolved['father_name'] ?? '') ?></dd>
                                <dt>Mother</dt><dd><?= e($confResolved['mother_maiden_name'] ?? '') ?></dd>
                                <dt>Sponsor</dt><dd><?= e($confResolved['sponsor_name'] ?? '') ?></dd>
                                <dt>Minister</dt><dd><?= e($confResolved['minister_name'] ?? '') ?></dd>
                                <dt>Baptism date</dt><dd><?= e($confBapDate ?? 'date not recorded') ?></dd>
                            </dl>
                            <input type="hidden" name="conf_matched_id" value="<?= (int) $confResolved['id'] ?>">
                            <input type="hidden" name="conf_confirmand_full_name" value="<?= e($confResolved['confirmand_full_name']) ?>">
                            <input type="hidden" name="conf_father_full_name" value="<?= e($confResolved['father_name'] ?? '') ?>">
                            <input type="hidden" name="conf_mother_full_name" value="<?= e($confResolved['mother_maiden_name'] ?? '') ?>">
                            <input type="hidden" name="conf_date_of_confirmation" value="<?= e($confResolved['confirmation_date'] ?? '') ?>">
                            <input type="hidden" name="conf_officiating_minister" value="<?= e($confResolved['minister_name'] ?? '') ?>">
                            <input type="hidden" name="conf_sponsor_name" value="<?= e($confResolved['sponsor_name'] ?? '') ?>">
                        </fieldset>
                    <?php else: ?>
                        <fieldset>
                            <legend>Confirmation Details (manual)</legend>
                            <label>Confirmand's Full Name * <input autocomplete="off" type="text" name="conf_confirmand_full_name" value="<?= e($_POST['conf_confirmand_full_name'] ?? '') ?>"></label>
                            <label>Father's Full Name * <input autocomplete="off" type="text" name="conf_father_full_name" value="<?= e($_POST['conf_father_full_name'] ?? '') ?>"></label>
                            <label>Mother's Maiden Name * <input autocomplete="off" type="text" name="conf_mother_maiden_name" value="<?= e($_POST['conf_mother_maiden_name'] ?? '') ?>"></label>
                            <label>Date of Confirmation <input autocomplete="off" type="date" name="conf_date_of_confirmation" value="<?= e($_POST['conf_date_of_confirmation'] ?? '') ?>"></label>
                            <label>Officiating Minister <input autocomplete="off" type="text" name="conf_officiating_minister" value="<?= e($_POST['conf_officiating_minister'] ?? '') ?>"></label>
                            <label>Sponsor <input autocomplete="off" type="text" name="conf_sponsor_name" value="<?= e($_POST['conf_sponsor_name'] ?? '') ?>"></label>
                            <input type="hidden" name="conf_matched_id" value="">
                        </fieldset>
                    <?php endif; ?>
                <?php else: ?>
                    <fieldset>
                        <legend>Confirmation Record Details</legend>
                        <p class="field-hint">Search the encoded register first. Use the confirmand's name and birth details as they appear in the record.</p>
                        <div class="inline-fields">
                            <label>First name <input autocomplete="off" type="text" name="conf_first_name" value="<?= e($_POST['conf_first_name'] ?? '') ?>"></label>
                            <label>Last name <input autocomplete="off" type="text" name="conf_last_name" value="<?= e($_POST['conf_last_name'] ?? '') ?>"></label>
                        </div>
                        <label>Birthdate <input autocomplete="off" type="date" name="conf_birthdate" value="<?= e($_POST['conf_birthdate'] ?? '') ?>"></label>
                        <label>Father's full name <input autocomplete="off" type="text" name="conf_father_name" value="<?= e($_POST['conf_father_name'] ?? '') ?>"></label>
                        <label>Mother's maiden name <input autocomplete="off" type="text" name="conf_mother_name" value="<?= e($_POST['conf_mother_name'] ?? '') ?>"></label>
                        <button type="submit" name="conf_step" value="find" formnovalidate class="btn btn-secondary">Find in Register</button>
                    </fieldset>
                    <?php if ($confState === 'results'): ?>
                        <fieldset>
                            <legend>Register Matches</legend>
                            <?php if ($confMatches): ?>
                                <p class="field-hint">Pick the exact register entry, or continue manually.</p>
                                <?php foreach ($confMatches as $m): ?>
                                    <div class="record-card">
                                        <strong><?= e($m['confirmand_full_name']) ?></strong>
                                        <span>Confirmed <?= e($m['confirmation_date'] ?: 'date not recorded') ?></span>
                                        <button type="submit" name="conf_use_record" value="<?= (int) $m['id'] ?>" formnovalidate class="btn btn-small">Use this record</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No matching register entry found. Older entries may not be encoded yet.</p>
                            <?php endif; ?>
                            <button type="submit" name="conf_use_record" value="manual" formnovalidate class="btn btn-secondary btn-small">No match — continue manually</button>
                        </fieldset>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div id="section-first_communion" class="sacrament-section" data-walk-in-phase="record" style="display:none;">
                <?php
                $fcState = $fcState ?? '';
                $fcResolved = $fcResolved ?? null;
                $fcInteraction = $fcState !== '' || ($_POST['fc_step'] ?? '') === 'find'
                    || array_key_exists('fc_use_record', $_POST) || array_key_exists('fc_matched_id', $_POST);
                ?>
                <?php if ($fcInteraction): ?>
                <script>
                    // Re-open this section after a find/use/encode POST: the type
                    // select resets on re-render and nothing else calls showSection.
                    window.addEventListener('load', function () {
                        var sel = document.getElementById('sacrament_type');
                        if (sel) sel.value = 'first_communion';
                        showSection('first_communion');
                    });
                </script>
                <?php endif; ?>
                <?php if ($fcState === 'resolved'): ?>
                    <?php if ($fcResolved): ?>
                        <fieldset>
                            <legend>Matched Register Entry</legend>
                            <dl class="review-list">
                                <dt>Name</dt><dd><?= e($fcResolved['communicant_full_name']) ?></dd>
                                <dt>Birthdate</dt><dd><?= e($fcResolved['birth_date'] ?? 'date not recorded') ?></dd>
                                <dt>First Communion date</dt><dd><?= e($fcResolved['communion_date'] ?: 'date not recorded') ?></dd>
                                <dt>Father</dt><dd><?= e($fcResolved['father_name'] ?? '') ?></dd>
                                <dt>Mother</dt><dd><?= e($fcResolved['mother_maiden_name'] ?? '') ?></dd>
                                <dt>Minister</dt><dd><?= e($fcResolved['minister_name'] ?? '') ?></dd>
                            </dl>
                            <input type="hidden" name="fc_matched_id" value="<?= (int) $fcResolved['id'] ?>">
                            <input type="hidden" name="fc_communicant_full_name" value="<?= e($fcResolved['communicant_full_name']) ?>">
                            <input type="hidden" name="fc_father_full_name" value="<?= e($fcResolved['father_name'] ?? '') ?>">
                            <input type="hidden" name="fc_mother_full_name" value="<?= e($fcResolved['mother_maiden_name'] ?? '') ?>">
                            <input type="hidden" name="fc_date_of_first_communion" value="<?= e($fcResolved['communion_date'] ?? '') ?>">
                            <input type="hidden" name="fc_officiating_minister" value="<?= e($fcResolved['minister_name'] ?? '') ?>">
                        </fieldset>
                    <?php else: ?>
                        <fieldset>
                            <legend>First Communion Details (manual)</legend>
                            <label>Communicant's Full Name * <input autocomplete="off" type="text" name="fc_communicant_full_name" value="<?= e($_POST['fc_communicant_full_name'] ?? '') ?>"></label>
                            <label>Father's Full Name * <input autocomplete="off" type="text" name="fc_father_full_name" value="<?= e($_POST['fc_father_full_name'] ?? '') ?>"></label>
                            <label>Mother's Maiden Name * <input autocomplete="off" type="text" name="fc_mother_maiden_name" value="<?= e($_POST['fc_mother_maiden_name'] ?? '') ?>"></label>
                            <label>Date of First Communion <input autocomplete="off" type="date" name="fc_date_of_first_communion" value="<?= e($_POST['fc_date_of_first_communion'] ?? '') ?>"></label>
                            <label>Officiating Minister <input autocomplete="off" type="text" name="fc_officiating_minister" value="<?= e($_POST['fc_officiating_minister'] ?? '') ?>"></label>
                            <input type="hidden" name="fc_matched_id" value="">
                        </fieldset>
                    <?php endif; ?>
                <?php else: ?>
                    <fieldset>
                        <legend>First Communion Record Details</legend>
                        <p class="field-hint">Search the encoded register first. Use the communicant's name and birth details as they appear in the record.</p>
                        <div class="inline-fields">
                            <label>First name <input autocomplete="off" type="text" name="fc_first_name" value="<?= e($_POST['fc_first_name'] ?? '') ?>"></label>
                            <label>Last name <input autocomplete="off" type="text" name="fc_last_name" value="<?= e($_POST['fc_last_name'] ?? '') ?>"></label>
                        </div>
                        <label>Birthdate <input autocomplete="off" type="date" name="fc_birthdate" value="<?= e($_POST['fc_birthdate'] ?? '') ?>"></label>
                        <label>Father's full name <input autocomplete="off" type="text" name="fc_father_name" value="<?= e($_POST['fc_father_name'] ?? '') ?>"></label>
                        <label>Mother's maiden name <input autocomplete="off" type="text" name="fc_mother_name" value="<?= e($_POST['fc_mother_name'] ?? '') ?>"></label>
                        <button type="submit" name="fc_step" value="find" formnovalidate class="btn btn-secondary">Find in Register</button>
                    </fieldset>
                    <?php if ($fcState === 'results'): ?>
                        <fieldset>
                            <legend>Register Matches</legend>
                            <?php if ($fcMatches): ?>
                                <p class="field-hint">Pick the exact register entry, or continue manually.</p>
                                <?php foreach ($fcMatches as $m): ?>
                                    <div class="record-card">
                                        <strong><?= e($m['communicant_full_name']) ?></strong>
                                        <span>First Communion <?= e($m['communion_date'] ?: 'date not recorded') ?></span>
                                        <button type="submit" name="fc_use_record" value="<?= (int) $m['id'] ?>" formnovalidate class="btn btn-small">Use this record</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No matching register entry found. Older entries may not be encoded yet.</p>
                            <?php endif; ?>
                            <button type="submit" name="fc_use_record" value="manual" formnovalidate class="btn btn-secondary btn-small">No match — continue manually</button>
                        </fieldset>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div id="section-marriage" class="sacrament-section" data-walk-in-phase="record" style="display:none;">
                <?php
                $marState = $marState ?? '';
                $marResolved = $marResolved ?? null;
                $marInteraction = $marState !== '' || ($_POST['mar_step'] ?? '') === 'find'
                    || array_key_exists('mar_use_record', $_POST) || array_key_exists('mar_matched_id', $_POST);
                ?>
                <?php if ($marInteraction): ?>
                <script>
                    // Re-open this section after a find/use/encode POST: the type
                    // select resets on re-render and nothing else calls showSection.
                    window.addEventListener('load', function () {
                        var sel = document.getElementById('sacrament_type');
                        if (sel) sel.value = 'marriage';
                        showSection('marriage');
                    });
                </script>
                <?php endif; ?>
                <?php if ($marState === 'resolved'): ?>
                    <?php if ($marResolved): ?>
                        <fieldset>
                            <legend>Matched Register Entry</legend>
                            <dl class="review-list">
                                <dt>Groom (Party A)</dt><dd><?= e($marResolved['husband_full_name']) ?></dd>
                                <dt>Bride (Party B)</dt><dd><?= e($marResolved['wife_maiden_name']) ?></dd>
                                <dt>Marriage date</dt><dd><?= e($marResolved['marriage_date'] ?: 'date not recorded') ?></dd>
                                <dt>Minister</dt><dd><?= e($marResolved['officiating_minister'] ?? '') ?></dd>
                            </dl>
                            <input type="hidden" name="mar_matched_id" value="<?= (int) $marResolved['id'] ?>">
                            <input type="hidden" name="party_a_full_name" value="<?= e($marResolved['husband_full_name']) ?>">
                            <input type="hidden" name="party_b_full_name" value="<?= e($marResolved['wife_maiden_name']) ?>">
                            <input type="hidden" name="mar_marriage_date" value="<?= e($marResolved['marriage_date'] ?? '') ?>">
                            <input type="hidden" name="mar_officiating_minister" value="<?= e($marResolved['officiating_minister'] ?? '') ?>">
                        </fieldset>
                    <?php else: ?>
                        <fieldset>
                            <legend>Party A (e.g., Groom)</legend>
                            <label>Full Name * <input autocomplete="off" type="text" name="party_a_full_name" value="<?= e($_POST['party_a_full_name'] ?? '') ?>"></label>
                            <div class="inline-fields">
                                <label>Father's Name <input autocomplete="off" type="text" name="party_a_father_name" value="<?= e($_POST['party_a_father_name'] ?? '') ?>"></label>
                                <label>Mother's Name <input autocomplete="off" type="text" name="party_a_mother_name" value="<?= e($_POST['party_a_mother_name'] ?? '') ?>"></label>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend>Party B (e.g., Bride)</legend>
                            <label>Full Name * <input autocomplete="off" type="text" name="party_b_full_name" value="<?= e($_POST['party_b_full_name'] ?? '') ?>"></label>
                            <div class="inline-fields">
                                <label>Father's Name <input autocomplete="off" type="text" name="party_b_father_name" value="<?= e($_POST['party_b_father_name'] ?? '') ?>"></label>
                                <label>Mother's Name <input autocomplete="off" type="text" name="party_b_mother_name" value="<?= e($_POST['party_b_mother_name'] ?? '') ?>"></label>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend>Contracted Marriage Detail</legend>
                            <label>Date of Marriage <input autocomplete="off" type="date" name="mar_marriage_date" value="<?= e($_POST['mar_marriage_date'] ?? '') ?>"></label>
                            <label>Officiating Minister <input autocomplete="off" type="text" name="mar_officiating_minister" value="<?= e($_POST['mar_officiating_minister'] ?? '') ?>"></label>
                            <input type="hidden" name="mar_matched_id" value="">
                        </fieldset>
                    <?php endif; ?>
                <?php else: ?>
                    <fieldset>
                        <legend>Marriage Record Details</legend>
                        <p class="field-hint">Search the encoded register first. Use the couple's names and marriage date as they appear in the record.</p>
                        <div class="inline-fields">
                            <label>Groom's first name <input autocomplete="off" type="text" name="husband_first_name" value="<?= e($_POST['husband_first_name'] ?? '') ?>"></label>
                            <label>Groom's last name <input autocomplete="off" type="text" name="husband_last_name" value="<?= e($_POST['husband_last_name'] ?? '') ?>"></label>
                        </div>
                        <div class="inline-fields">
                            <label>Bride's first name <input autocomplete="off" type="text" name="wife_first_name" value="<?= e($_POST['wife_first_name'] ?? '') ?>"></label>
                            <label>Bride's maiden last name <input autocomplete="off" type="text" name="wife_last_name" value="<?= e($_POST['wife_last_name'] ?? '') ?>"></label>
                        </div>
                        <label>Marriage date <input autocomplete="off" type="date" name="mar_marriage_date" value="<?= e($_POST['mar_marriage_date'] ?? '') ?>"></label>
                        <button type="submit" name="mar_step" value="find" formnovalidate class="btn btn-secondary">Find in Register</button>
                    </fieldset>
                    <?php if ($marState === 'results'): ?>
                        <fieldset>
                            <legend>Register Matches</legend>
                            <?php if ($marMatches): ?>
                                <p class="field-hint">Pick the exact register entry, or continue manually.</p>
                                <?php foreach ($marMatches as $m): ?>
                                    <div class="record-card">
                                        <strong><?= e($m['husband_full_name']) ?> &amp; <?= e($m['wife_maiden_name']) ?></strong>
                                        <span>Married <?= e($m['marriage_date'] ?: 'date not recorded') ?></span>
                                        <button type="submit" name="mar_use_record" value="<?= (int) $m['id'] ?>" formnovalidate class="btn btn-small">Use this record</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No matching register entry found. Older entries may not be encoded yet.</p>
                            <?php endif; ?>
                            <button type="submit" name="mar_use_record" value="manual" formnovalidate class="btn btn-secondary btn-small">No match — continue manually</button>
                        </fieldset>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <fieldset data-walk-in-phase="wrap-up">
                <legend>Request Details</legend>
                <label>Purpose *
                    <select name="purpose" required>
                        <option value="">-- Select --</option>
                        <option value="marriage">Marriage requirement</option>
                        <option value="school">School or education</option>
                        <option value="employment">Employment</option>
                        <option value="government_civil">Government ID, passport, or visa</option>
                        <option value="legal_tribunal">Legal or court matter</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>If Other, please specify <input autocomplete="off" type="text" name="purpose_other" value="<?= e($_POST['purpose_other'] ?? '') ?>"></label>
                <label>Additional Notes / Observanda
                    <textarea autocomplete="off" name="notes"><?= e($_POST['notes'] ?? '') ?></textarea>
                </label>
            </fieldset>

            <fieldset data-walk-in-phase="wrap-up">
                <legend>Payment (in person)</legend>
                <label class="checkbox-label"><input type="checkbox" name="payment_received" value="1" <?= (($_POST['payment_received'] ?? '') === '1') ? 'checked' : '' ?>> Payment received at the counter &mdash; mark as paid</label>
                <p class="field-hint">Optional. Payment is collected in person at the office; the system records no amount, only that it was received and by whom.</p>
            </fieldset>

            <button type="submit" class="btn" data-walk-in-submit>Encode Request</button>
        </form>
    </div>

    <script>
        // Show only the selected sacrament's detail section (all hidden by default).
        function showSection(type) {
            document.querySelectorAll('.sacrament-section').forEach(function (section) {
                section.style.display = (section.id === 'section-' + type) ? '' : 'none';
            });
        }
    </script>
<?php require_once __DIR__ . '/../../includes/admin-footer.php';
