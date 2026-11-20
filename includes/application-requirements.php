<?php

function sacrament_application_requirements(string $type): array
{
    $requirements = [
        'baptism' => [
            'before' => [
                'The child’s PSA birth certificate or birth certificate from the local civil registrar.',
            ],
            'after' => [
                'The Parish Office reviews the pre-application and confirms the baptism preparation seminar and available schedule.',
                'Parents and godparents attend the preparation seminar before the baptism, as scheduled by the Parish Office.',
                'The Parish Office confirms godparent eligibility and any additional documents before the baptism date.',
            ],
        ],
        'first_communion' => [
            'before' => [
                'The candidate’s PSA birth certificate or birth certificate from the local civil registrar.',
                'First Confession / First Reconciliation before Holy Communion.',
                'If no verified Baptism record is already on file, bring a Baptismal Certificate from the parish where the candidate was baptized.',
            ],
            'after' => [
                'The Parish Office reviews the pre-application and confirms the candidate’s preparation and schedule.',
                'Complete the Holy Communion formation or seminar arranged by the Parish Office; ask whether it needs any additional proof of formation.',
                'The Parish Office confirms the celebration schedule and any further instructions.',
            ],
        ],
        'confirmation' => [
            'before' => [
                'The candidate’s PSA birth certificate or birth certificate from the local civil registrar.',
                'A Baptismal Certificate from the parish where the candidate was baptized, marked “For Confirmation Purposes” and issued within the last 6 months.',
                'The proposed sponsor’s name and details, if available; the Parish Office confirms sponsor qualifications and any supporting documents.',
            ],
            'after' => [
                'The Parish Office reviews the pre-application and confirms the required preparation seminar.',
                'The Parish Office schedules candidates according to their group or individual preparation plan.',
                'Attend the confirmed preparation and Confirmation schedule.',
            ],
        ],
        'marriage' => [
            'before' => [
                'The latest Baptismal Certificate for each applicant, annotated “For Marriage Purposes.”',
                'A Confirmation Certificate for each applicant, annotated “For Marriage Purposes.”',
                'A list of wedding sponsors with their addresses; the Parish Office will confirm the applicable sponsor requirements.',
                'Civil-status documents depend on the statuses selected for both applicants and will be listed in the form and on the review screen.',
                'If the wedding is outside St. Patrick’s Parish, the Parish Office will confirm whether a transfer or permission letter is required. Missing sacramental records are reviewed separately.',
            ],
            'after' => [
                'The Parish Office reviews the documents and arranges an interview with the priest.',
                'Complete the Family Life or Pre-Cana seminar as scheduled by the Parish Office.',
                'Arrange confession with the Parish Office, either beforehand or as directed before the wedding.',
                'Confirm the wedding date, ceremony items, and any remaining instructions with the Parish Office. The reference checklist mentions wedding candles, offering candles, a Bible, two rosaries, a crucifix, host, and wine; ask the office for its exact instructions.',
                'The pre-application does not itself approve or reserve a wedding date.',
            ],
        ],
    ];

    if (!array_key_exists($type, $requirements)) {
        throw new InvalidArgumentException('A valid sacrament application type is required.');
    }

    return $requirements[$type];
}

function marriage_preapplication_required_documents(string $groomStatus, string $brideStatus, string $venue): array
{
    $documents = [
        'The latest Baptismal Certificate for each applicant, annotated “For Marriage Purposes.”',
        'A Confirmation Certificate for each applicant, annotated “For Marriage Purposes.”',
        'A list of wedding sponsors with their addresses; the Parish Office will confirm the applicable sponsor requirements.',
    ];
    $statuses = ['groom' => $groomStatus, 'bride' => $brideStatus];
    $partyLabels = ['groom' => 'Groom', 'bride' => 'Bride'];

    foreach ($statuses as $party => $status) {
        $label = $partyLabels[$party];
        if ($status === 'single') {
            $documents[] = $label . ': PSA Certificate of No Marriage Record (CENOMAR).';
        } elseif ($status === 'widowed') {
            $documents[] = $label . ': deceased spouse’s PSA Death Certificate.';
            $documents[] = $label . ': prior PSA Marriage Certificate.';
        } elseif ($status === 'annulled') {
            $documents[] = $label . ': Civil Annulment Decree / Certificate of Finality.';
            $documents[] = $label . ': annotated PSA Marriage Certificate.';
            $documents[] = $label . ': Church Declaration of Nullity.';
        }
    }

    if ($groomStatus === 'civilly_married' && $brideStatus === 'civilly_married') {
        $documents[] = 'Couple: PSA Marriage Certificate.';
        $documents[] = 'Enter the civil marriage date and place in the form.';
    } else {
        $documents[] = 'Marriage License from the Municipal Civil Registrar; submit it at the Parish Office.';
    }

    if ($venue === 'other') {
        $documents[] = 'For a wedding outside St. Patrick’s Parish, ask the Parish Office whether a transfer or permission letter is required.';
    }

    return $documents;
}

function verified_parish_record_requirement(PDO $pdo, int $personId, string $sacramentType): ?array
{
    if ($personId < 1) {
        return null;
    }

    $recordStmt = $pdo->prepare(
        'SELECT book_no, page_no, line_no
         FROM sacrament_records
         WHERE person_id = ? AND sacrament_type = ? AND source = \'this_parish\'
           AND book_verified_at IS NOT NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $recordStmt->execute([$personId, $sacramentType]);
    $record = $recordStmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        return null;
    }

    $reference = [];
    foreach (['book_no' => 'Book', 'page_no' => 'Page', 'line_no' => 'Line'] as $column => $label) {
        $value = trim((string) ($record[$column] ?? ''));
        $reference[] = $label . ' ' . ($value !== '' ? $value : 'not recorded');
    }

    return [
        'status' => 'verified',
        'note' => 'Verified from the parish ' . str_replace('_', ' ', $sacramentType)
            . ' record on file (' . implode(', ', $reference) . ').',
    ];
}

function marriage_application_requirement_states(
    string $groomStatus,
    string $brideStatus,
    string $venue,
    ?PDO $pdo = null,
    ?int $groomPersonId = null,
    ?int $bridePersonId = null,
    string $groomAddress = '',
    string $brideAddress = ''
): array
{
    $requirementState = static fn(bool $required): array => [
        'status' => $required ? 'not_received' : 'not_applicable',
        'note' => $required ? '' : 'Not required for the selected marital status.',
    ];
    $isCivillyMarriedPair = $groomStatus === 'civilly_married' && $brideStatus === 'civilly_married';
    $requiresMarriageLicense = !$isCivillyMarriedPair;
    $transferReasons = [];
    if ($venue !== 'church') {
        $transferReasons[] = 'the wedding venue is outside St. Patrick\'s Parish or has not been confirmed';
    }
    if (stripos($groomAddress, 'Dingalan') === false) {
        $transferReasons[] = "the groom's current address is outside Dingalan or not specified";
    }
    if (stripos($brideAddress, 'Dingalan') === false) {
        $transferReasons[] = "the bride's current address is outside Dingalan or not specified";
    }
    $transferLetterRequirement = $transferReasons === []
        ? ['status' => 'not_applicable', 'note' => 'The wedding is at St. Patrick\'s Parish and both applicants\' current addresses include Dingalan.']
        : ['status' => 'not_received', 'note' => 'Transfer or permission letter required for review because ' . implode('; ', $transferReasons) . '.'];

    $requirements = [
        'psa_birth_certificate_groom' => ['status' => 'not_received', 'note' => ''],
        'psa_birth_certificate_bride' => ['status' => 'not_received', 'note' => ''],
        'psa_cenomar_groom' => $requirementState($groomStatus === 'single'),
        'psa_cenomar_bride' => $requirementState($brideStatus === 'single'),
        'psa_marriage_certificate_couple' => $requirementState($isCivillyMarriedPair),
        'psa_death_certificate_groom' => $requirementState($groomStatus === 'widowed'),
        'psa_death_certificate_bride' => $requirementState($brideStatus === 'widowed'),
        'psa_prior_marriage_certificate_groom' => $requirementState($groomStatus === 'widowed'),
        'psa_prior_marriage_certificate_bride' => $requirementState($brideStatus === 'widowed'),
        'civil_annulment_finality_groom' => $requirementState($groomStatus === 'annulled'),
        'civil_annulment_finality_bride' => $requirementState($brideStatus === 'annulled'),
        'annotated_psa_marriage_certificate_groom' => $requirementState($groomStatus === 'annulled'),
        'annotated_psa_marriage_certificate_bride' => $requirementState($brideStatus === 'annulled'),
        'church_declaration_nullity_groom' => $requirementState($groomStatus === 'annulled'),
        'church_declaration_nullity_bride' => $requirementState($brideStatus === 'annulled'),
        'origin_baptismal_certificate_groom' => ['status' => 'not_received', 'note' => ''],
        'origin_baptismal_certificate_bride' => ['status' => 'not_received', 'note' => ''],
        'transfer_letter' => $transferLetterRequirement,
        'canonical_interview' => ['status' => 'not_received', 'note' => ''],
        'marriage_banns' => ['status' => 'not_received', 'note' => ''],
        'pre_cana_seminar' => ['status' => 'not_received', 'note' => ''],
        'marriage_license_application' => $requirementState($requiresMarriageLicense),
        'origin_confirmation_certificate_groom' => ['status' => 'not_received', 'note' => ''],
        'origin_confirmation_certificate_bride' => ['status' => 'not_received', 'note' => ''],
    ];

    if ($pdo !== null) {
        foreach ([
            'groom' => $groomPersonId,
            'bride' => $bridePersonId,
        ] as $party => $personId) {
            if ($personId === null || $personId < 1) {
                continue;
            }

            foreach ([
                'baptism' => 'origin_baptismal_certificate',
                'confirmation' => 'origin_confirmation_certificate',
            ] as $recordType => $requirementPrefix) {
                $recordRequirement = verified_parish_record_requirement($pdo, $personId, $recordType);
                if ($recordRequirement !== null) {
                    $requirements[$requirementPrefix . '_' . $party] = $recordRequirement;
                }
            }
        }
    }

    return $requirements;
}
