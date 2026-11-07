<?php

function sacrament_application_requirements(string $type): array
{
    $requirements = [
        'baptism' => [
            'before' => [
                'The child’s PSA birth certificate or birth certificate from the local civil registrar.',
                'A valid ID for the parent or guardian, as currently requested by this form; ask the Parish Office which IDs it accepts.',
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
                'A Baptismal Certificate from the parish where the candidate was baptized.',
            ],
            'after' => [
                'The Parish Office reviews the pre-application and confirms the candidate’s preparation and schedule.',
                'Complete the First Communion formation or seminar arranged by the Parish Office; ask whether it needs any additional proof of formation.',
                'The Parish Office confirms the celebration schedule and any further instructions.',
            ],
        ],
        'confirmation' => [
            'before' => [
                'The candidate’s PSA birth certificate or birth certificate from the local civil registrar.',
                'A Baptismal Certificate from the parish where the candidate was baptized.',
                'The proposed sponsor’s name and details, if available; the Parish Office confirms sponsor qualifications and any supporting documents.',
            ],
            'after' => [
                'The Parish Office reviews the pre-application and confirms the required preparation seminar.',
                'The Parish Office schedules candidates according to their group, such as school candidates, adults, or people preparing for marriage.',
                'Attend the confirmed preparation and Confirmation schedule.',
            ],
        ],
        'marriage' => [
            'before' => [
                'The latest Baptismal Certificate for each applicant, annotated “For Marriage Purposes.”',
                'A Confirmation Certificate for each applicant, annotated “For Marriage Purposes.”',
                'Marriage-license documents, if applicable; the Parish Office will confirm the required documents and copy count.',
                'A PSA Certificate of No Marriage Record (CENOMAR) for each applicant; confirm the required copy count with the Parish Office.',
                'A list of wedding sponsors with their addresses; the Parish Office will confirm the applicable sponsor requirements.',
                'If the couple is already civilly married, the civil marriage contract.',
                'If either applicant’s sacramental records are from another parish, ask whether a transfer or permission letter is required.',
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
