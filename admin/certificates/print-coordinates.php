<?php
/**
 * Baptismal certificate layout for the parish's pre-printed blank stock.
 *
 * Everything here was measured from a photo of the real stock (straightened
 * with a perspective transform), so it is a close first pass, not gospel.
 * Do a physical test print and tune the `calibration` offsets below.
 *
 * Units (so the layout survives a change of paper size):
 *   - x, width, size ....... % of PAGE WIDTH
 *   - y, base(line) ........ % of PAGE HEIGHT
 *   - 'size' / 'size_pct' .. font em-size as % of page width
 *                            (2.667 is about 11.5pt on a 6in-wide page)
 *
 * 'stroke'   = optional faux-semibold outline (viewBox units) for the heading text.
 *
 * 'fields'   = values the system prints (baseline_pct = text baseline).
 * 'template' = replica of the pre-printed artwork, used for the on-screen
 *              preview and the optional "full certificate" print. It is
 *              NOT printed when you print onto the pre-printed stock.
 */

function baptismal_blank_stock_layout(): array
{
    return [
        'page' => [
            // ASSUMED 6 x 9 in from the photo's proportions (about 2:3).
            // Measure the real stock with a ruler and change these two numbers.
            'width_in'  => 6.0,
            'height_in' => 9.0,
        ],
        // Nudge the printed values after a test print (inches; +x right, +y down).
        'calibration' => [
            'offset_x_in' => 0.0,
            'offset_y_in' => 0.0,
        ],
        'fonts' => [
            'serif' => "'Book Antiqua','Palatino Linotype',Palatino,'TeX Gyre Pagella','URW Palladio L',P052,Georgia,serif",
            'geo'   => "'Century Gothic','Avenir Next',Avenir,'TeX Gyre Adventor','URW Gothic',Montserrat,'Segoe UI',Arial,sans-serif",
            'cal'   => "Calibri,Carlito,'Segoe UI','Open Sans',Arial,sans-serif",
        ],
        'colors' => [
            'ink'       => '#222222',
            'title'     => '#4e5051',
            'green'     => '#6b8268',
            'muted'     => '#565d56',
            'watermark' => '#f3f7f2',
            'value'     => '#000000',
        ],
        'fields' => [
        'subject_full_name' => ['label' => 'Subject full name (\'That\')', 'confidence' => 'measured', 'x_pct' => 13.333, 'baseline_pct' => 24.556, 'width_pct' => 78.792, 'align' => 'start', 'size_pct' => 2.667],
        'father_name' => ['label' => 'Father\'s name (\'child of\')', 'confidence' => 'measured', 'x_pct' => 15.917, 'baseline_pct' => 27.889, 'width_pct' => 76.25, 'align' => 'start', 'size_pct' => 2.667],
        'mother_name' => ['label' => 'Mother\'s name (\'and\')', 'confidence' => 'measured', 'x_pct' => 12, 'baseline_pct' => 31.167, 'width_pct' => 80.167, 'align' => 'start', 'size_pct' => 2.667],
        'place_of_birth' => ['label' => 'Place of birth (\'born in\')', 'confidence' => 'measured', 'x_pct' => 15.75, 'baseline_pct' => 34.556, 'width_pct' => 76.375, 'align' => 'start', 'size_pct' => 2.667],
        'birth_date_day' => ['label' => 'Birth date: day', 'confidence' => 'measured', 'x_pct' => 20, 'baseline_pct' => 37.917, 'width_pct' => 10.033, 'align' => 'middle', 'size_pct' => 2.667],
        'birth_date_month' => ['label' => 'Birth date: month', 'confidence' => 'measured', 'x_pct' => 43.749, 'baseline_pct' => 37.917, 'width_pct' => 20.033, 'align' => 'middle', 'size_pct' => 2.667],
        'birth_date_year' => ['label' => 'Birth date: year', 'confidence' => 'measured', 'x_pct' => 86.438, 'baseline_pct' => 37.917, 'width_pct' => 11.575, 'align' => 'middle', 'size_pct' => 2.667],
        'baptism_date_day' => ['label' => 'Baptism date: day', 'confidence' => 'measured', 'x_pct' => 21.167, 'baseline_pct' => 52.4, 'width_pct' => 8.2, 'align' => 'middle', 'size_pct' => 2.667],
        'baptism_date_month' => ['label' => 'Baptism date: month', 'confidence' => 'measured', 'x_pct' => 42.916, 'baseline_pct' => 52.4, 'width_pct' => 18.033, 'align' => 'middle', 'size_pct' => 2.667],
        'baptism_date_year' => ['label' => 'Baptism date: year', 'confidence' => 'measured', 'x_pct' => 84.624, 'baseline_pct' => 52.4, 'width_pct' => 10.283, 'align' => 'middle', 'size_pct' => 2.667],
        'officiating_priest' => ['label' => 'Officiating priest (by the Rev.)', 'confidence' => 'measured', 'x_pct' => 30.833, 'baseline_pct' => 55.544, 'width_pct' => 45.333, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_1' => ['label' => 'Sponsor 1 (left column, row 1)', 'confidence' => 'measured', 'x_pct' => 7.625, 'baseline_pct' => 63.586, 'width_pct' => 40.25, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_2' => ['label' => 'Sponsor 2 (right column, row 1)', 'confidence' => 'measured', 'x_pct' => 52.667, 'baseline_pct' => 63.586, 'width_pct' => 39.917, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_3' => ['label' => 'Sponsor 3 (left column, row 2)', 'confidence' => 'measured', 'x_pct' => 7.625, 'baseline_pct' => 66.594, 'width_pct' => 40.25, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_4' => ['label' => 'Sponsor 4 (right column, row 2)', 'confidence' => 'measured', 'x_pct' => 52.667, 'baseline_pct' => 66.594, 'width_pct' => 39.917, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_5' => ['label' => 'Sponsor 5 (left column, row 3)', 'confidence' => 'measured', 'x_pct' => 7.625, 'baseline_pct' => 70.038, 'width_pct' => 40.25, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_6' => ['label' => 'Sponsor 6 (right column, row 3)', 'confidence' => 'measured', 'x_pct' => 52.667, 'baseline_pct' => 70.038, 'width_pct' => 39.917, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_7' => ['label' => 'Sponsor 7 (left column, row 4)', 'confidence' => 'measured', 'x_pct' => 7.625, 'baseline_pct' => 73.655, 'width_pct' => 40.25, 'align' => 'start', 'size_pct' => 2.667],
        'sponsor_8' => ['label' => 'Sponsor 8 (right column, row 4)', 'confidence' => 'measured', 'x_pct' => 52.667, 'baseline_pct' => 73.655, 'width_pct' => 39.917, 'align' => 'start', 'size_pct' => 2.667],
        'register_book' => ['label' => 'Register: Book No.', 'confidence' => 'measured', 'x_pct' => 39.791, 'baseline_pct' => 80.606, 'width_pct' => 10.117, 'align' => 'middle', 'size_pct' => 2.667],
        'register_page' => ['label' => 'Register: Page', 'confidence' => 'measured', 'x_pct' => 56.458, 'baseline_pct' => 80.606, 'width_pct' => 8.95, 'align' => 'middle', 'size_pct' => 2.667],
        'register_line' => ['label' => 'Register: Line', 'confidence' => 'measured', 'x_pct' => 72.166, 'baseline_pct' => 80.606, 'width_pct' => 9.033, 'align' => 'middle', 'size_pct' => 2.667],
        'purpose' => ['label' => 'Purpose', 'confidence' => 'measured', 'x_pct' => 19, 'baseline_pct' => 84.689, 'width_pct' => 44.583, 'align' => 'start', 'size_pct' => 2.667],
        'date_issued' => ['label' => 'Date issued (the Date: blank)', 'confidence' => 'measured', 'x_pct' => 71.083, 'baseline_pct' => 84.689, 'width_pct' => 21.75, 'align' => 'start', 'size_pct' => 2.667],
        'remarks' => ['label' => 'Remarks', 'confidence' => 'measured', 'x_pct' => 18.917, 'baseline_pct' => 87.889, 'width_pct' => 73.917, 'align' => 'start', 'size_pct' => 2.667],

        ],
        'template' => [
            'logo'      => ['cx' => 20.8, 'cy' => 10.954, 'd' => 20.8],
            'watermark' => ['cx' => 85.667, 'cy' => 69.778, 'd' => 131.31],
            'header_rules' => [
            ['x' => 34.667, 'w' => 10, 'y' => 8.383, 'color' => 'green', 'th' => 0.217],
            ['x' => 76.833, 'w' => 10, 'y' => 8.383, 'color' => 'green', 'th' => 0.217],
            ['x' => 34.583, 'w' => 5.5, 'y' => 13.306, 'color' => 'green', 'th' => 0.217],
            ['x' => 81.417, 'w' => 5.5, 'y' => 13.306, 'color' => 'green', 'th' => 0.217],

            ],
            'rules' => [
            'that' => ['x' => 12.833, 'w' => 79.792, 'y' => 24.933, 'color' => 'ink', 'th' => 0.183],
            'childof' => ['x' => 15.417, 'w' => 77.25, 'y' => 28.05, 'color' => 'ink', 'th' => 0.183],
            'and' => ['x' => 11.5, 'w' => 81.167, 'y' => 31.433, 'color' => 'ink', 'th' => 0.183],
            'bornin' => ['x' => 15.25, 'w' => 77.375, 'y' => 34.828, 'color' => 'ink', 'th' => 0.183],
            'birth_day' => ['x' => 14.583, 'w' => 10.833, 'y' => 38.117, 'color' => 'ink', 'th' => 0.183],
            'birth_month' => ['x' => 33.333, 'w' => 20.833, 'y' => 38.117, 'color' => 'ink', 'th' => 0.183],
            'birth_year' => ['x' => 80.25, 'w' => 12.375, 'y' => 38.117, 'color' => 'ink', 'th' => 0.183],
            'bapt_day' => ['x' => 16.667, 'w' => 9, 'y' => 52.428, 'color' => 'ink', 'th' => 0.183],
            'bapt_month' => ['x' => 33.5, 'w' => 18.833, 'y' => 52.428, 'color' => 'ink', 'th' => 0.183],
            'bapt_year' => ['x' => 79.083, 'w' => 11.083, 'y' => 52.428, 'color' => 'ink', 'th' => 0.183],
            'priest' => ['x' => 30.333, 'w' => 46.333, 'y' => 55.778, 'color' => 'ink', 'th' => 0.183],
            'book' => ['x' => 34.333, 'w' => 10.917, 'y' => 80.6, 'color' => 'ink', 'th' => 0.183],
            'page' => ['x' => 51.583, 'w' => 9.75, 'y' => 80.6, 'color' => 'ink', 'th' => 0.183],
            'line' => ['x' => 67.25, 'w' => 9.833, 'y' => 80.6, 'color' => 'ink', 'th' => 0.183],
            'purpose' => ['x' => 18.5, 'w' => 45.583, 'y' => 84.828, 'color' => 'ink', 'th' => 0.183],
            'date' => ['x' => 70.583, 'w' => 22.75, 'y' => 84.828, 'color' => 'ink', 'th' => 0.183],
            'remarks' => ['x' => 18.417, 'w' => 74.917, 'y' => 88.039, 'color' => 'ink', 'th' => 0.183],
            'signature' => ['x' => 57.667, 'w' => 35.667, 'y' => 94.956, 'color' => 'ink', 'th' => 0.183],
            'sponsor_1' => ['x' => 7.125, 'w' => 41.25, 'y' => 63.892, 'color' => 'ink', 'th' => 0.183],
            'sponsor_2' => ['x' => 52.167, 'w' => 40.917, 'y' => 63.892, 'color' => 'ink', 'th' => 0.183],
            'sponsor_3' => ['x' => 7.125, 'w' => 41.25, 'y' => 66.9, 'color' => 'ink', 'th' => 0.183],
            'sponsor_4' => ['x' => 52.167, 'w' => 40.917, 'y' => 66.9, 'color' => 'ink', 'th' => 0.183],
            'sponsor_5' => ['x' => 7.125, 'w' => 41.25, 'y' => 70.344, 'color' => 'ink', 'th' => 0.183],
            'sponsor_6' => ['x' => 52.167, 'w' => 40.917, 'y' => 70.344, 'color' => 'ink', 'th' => 0.183],
            'sponsor_7' => ['x' => 7.125, 'w' => 41.25, 'y' => 73.961, 'color' => 'ink', 'th' => 0.183],
            'sponsor_8' => ['x' => 52.167, 'w' => 40.917, 'y' => 73.961, 'color' => 'ink', 'th' => 0.183],

            ],
            'texts' => [
            ['id' => 'title', 'family' => 'serif', 'weight' => 400, 'color' => 'title', 'size' => 4.942, 'base' => 5.889, 'stroke' => 0.16, 'words' => [
                [
    33.5,
    26.5,
    [['B', 1], ['APTISMAL', 0.85]],
],
                [
    59.0,
    30.0,
    [['C', 1], ['ERTIFICATE', 0.85]],
],
            ]],
            ['id' => 'prelature', 'family' => 'geo', 'weight' => 400, 'color' => 'green', 'size' => 2.083, 'base' => 9, 'stroke' => 0.12, 'words' => [
                [45.25, 14.25, 'PRELATURE'],
                [60.75, 3.25, 'OF'],
                [65.25, 10.667, 'INFANTA'],
            ]],
            ['id' => 'stpatrick', 'family' => 'geo', 'weight' => 400, 'color' => 'green', 'size' => 4.6, 'base' => 11.778, 'stroke' => 0.3, 'words' => [
                [34.5, 7.333, 'ST.'],
                [44.25, 21.917, 'PATRICK'],
                [68.583, 18.75, 'PARISH'],
            ]],
            ['id' => 'dingalan', 'family' => 'cal', 'weight' => 400, 'color' => 'muted', 'size' => 2.354, 'base' => 13.807, 'words' => [
                [40.583, 8.75, 'Dingalan,'],
                [49.917, 4.5, '3207'],
                [55, 6.25, 'Aurora'],
                [61.833, 8.583, 'Province,'],
                [71, 10.167, 'Philippines'],
            ]],
            ['id' => 'certify', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 3.316, 'base' => 18.716, 'words' => [
                [39.5, 6, 'This'],
                [46.417, 2, 'is'],
                [49.333, 2.583, 'to'],
                [53, 8.167, 'certify'],
            ]],
            ['id' => 'that', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 24.667, 'words' => [
                [6.583, 5.25, 'That'],
            ]],
            ['id' => 'childof', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 28, 'words' => [
                [6.75, 5.417, 'child'],
                [12.833, 2.333, 'of'],
            ]],
            ['id' => 'and', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 31.278, 'words' => [
                [6.75, 4.167, 'and'],
            ]],
            ['id' => 'bornin', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 34.667, 'words' => [
                [6.917, 5, 'born'],
                [12.667, 2.167, 'in'],
            ]],
            ['id' => 'onthe1', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 38.028, 'words' => [
                [6.833, 2.75, 'on'],
                [10.333, 3.417, 'the'],
            ]],
            ['id' => 'dayof1', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 38.028, 'words' => [
                [25.917, 4, 'day'],
                [30.667, 2.333, 'of'],
            ]],
            ['id' => 'year1', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 38.028, 'words' => [
                [54.417, 2.083, 'in'],
                [57.25, 3.333, 'the'],
                [61.333, 4.667, 'year'],
                [66.667, 2.25, 'of'],
                [69.417, 3.833, 'our'],
                [73.75, 5.5, 'Lord'],
            ]],
            ['id' => 'received', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 43.056, 'words' => [
                [44.333, 10.417, 'Received'],
            ]],
            ['id' => 'holy', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 3.941, 'base' => 46.222, 'stroke' => 0.16, 'words' => [
                [
    18.917,
    7.583,
    [['T', 1], ['HE', 0.84]],
],
                [
    27.583,
    9.917,
    [['H', 1], ['OLY', 0.84]],
],
                [
    38.417,
    21,
    [['S', 1], ['ACRAMENT', 0.84]],
],
                [
    60.333,
    4.583,
    [['OF', 0.84]],
],
                [
    66.083,
    14.833,
    [['B', 1], ['APTISM', 0.84]],
],
            ]],
            ['id' => 'according', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 48.933, 'words' => [
                [20.083, 11.417, 'according'],
                [32.167, 2.25, 'to'],
                [35.083, 3.583, 'the'],
                [39.417, 3.667, 'rite'],
                [43.917, 2.417, 'of'],
                [46.833, 3.5, 'the'],
                [51, 8.333, 'Roman'],
                [60.167, 9.417, 'Catholic'],
                [70.333, 8.417, 'Church'],
            ]],
            ['id' => 'onthe2', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 52.511, 'words' => [
                [9.167, 2.917, 'on'],
                [12.917, 3.583, 'the'],
            ]],
            ['id' => 'dayof2', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 52.511, 'words' => [
                [26.167, 4.083, 'day'],
                [31.083, 2.417, 'of'],
            ]],
            ['id' => 'year2', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 52.511, 'words' => [
                [52.75, 2.25, 'in'],
                [55.75, 3.5, 'the'],
                [60, 4.833, 'year'],
                [65.583, 2.417, 'of'],
                [68.583, 3.917, 'our'],
                [73.083, 5.667, 'Lord'],
            ]],
            ['id' => 'bytherev', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 55.656, 'words' => [
                [17.167, 2.833, 'by'],
                [20.75, 3.583, 'the'],
                [25, 5.417, 'Rev.'],
            ]],
            ['id' => 'sponsors', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 60.767, 'words' => [
                [6.917, 4.833, 'The'],
                [12.5, 10, 'sponsors'],
                [23.417, 6.25, 'being'],
            ]],
            ['id' => 'appears', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 77.489, 'words' => [
                [18.583, 3.167, 'As'],
                [22.5, 8.917, 'appears'],
                [32.25, 5.5, 'from'],
                [38.583, 3.5, 'the'],
                [42.833, 11.25, 'Baptismal'],
                [54.833, 9.417, 'Register'],
                [65, 2.417, 'of'],
                [67.917, 4, 'this'],
                [72.75, 9.25, 'Church,'],
            ]],
            ['id' => 'bookno', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 80.717, 'words' => [
                [23.083, 6.083, 'Book'],
                [30, 4.25, 'No.'],
            ]],
            ['id' => 'page', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 80.717, 'words' => [
                [45.417, 5.583, 'Page'],
            ]],
            ['id' => 'line', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 80.717, 'words' => [
                [62, 5.167, 'Line'],
            ]],
            ['id' => 'purpose', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 84.8, 'words' => [
                [7.167, 10.083, 'Purpose:'],
            ]],
            ['id' => 'date', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 84.8, 'words' => [
                [64.25, 6.167, 'Date:'],
            ]],
            ['id' => 'remarks', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 88, 'words' => [
                [7.25, 10.917, 'Remarks:'],
            ]],
            ['id' => 'priest', 'family' => 'serif', 'weight' => 400, 'color' => 'ink', 'size' => 2.667, 'base' => 96.778, 'words' => [
                [68.667, 7, 'Parish'],
                [76.5, 6.333, 'Priest'],
            ]],

            ],
        ],
        'notes' => 'Measured from a photo of the blank stock; physically calibrate before production use.',
    ];
}
