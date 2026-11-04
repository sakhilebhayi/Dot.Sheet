<?php

$lines = ['Rent or bond', 'Groceries', 'Transport', 'School fees', 'Medical', 'Insurance', 'Electricity and water', 'Phone and internet', 'Savings', 'Other'];
$cells = [
    'A1' => ['v' => 'Household budget', 'x' => ['bold' => true, 'font_size' => 16]],
    'A3' => ['v' => 'Money in', 'x' => ['bold' => true]],
    'B3' => ['v' => 'Planned', 'x' => ['bold' => true]],
    'C3' => ['v' => 'Actual', 'x' => ['bold' => true]],
    'D3' => ['v' => 'Difference', 'x' => ['bold' => true]],
    'A4' => 'Salary',
    'A5' => 'Other income',
    'A6' => ['v' => 'Total in', 'x' => ['bold' => true]],
    'B6' => ['v' => '=SUM(B4:B5)', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'C6' => ['v' => '=SUM(C4:C5)', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'D6' => ['v' => '=C6-B6', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'A8' => ['v' => 'Money out', 'x' => ['bold' => true]],
    'B8' => ['v' => 'Planned', 'x' => ['bold' => true]],
    'C8' => ['v' => 'Actual', 'x' => ['bold' => true]],
    'D8' => ['v' => 'Difference', 'x' => ['bold' => true]],
    'A19' => ['v' => 'Total out', 'x' => ['bold' => true]],
    'B19' => ['v' => '=SUM(B9:B18)', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'C19' => ['v' => '=SUM(C9:C18)', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'D19' => ['v' => '=B19-C19', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'A21' => ['v' => 'Left at month end', 'x' => ['bold' => true]],
    'B21' => ['v' => '=B6-B19', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'C21' => ['v' => '=C6-C19', 'x' => ['bold' => true, 'number_format' => 'currency']],
];
foreach ([4, 5] as $row) {
    $cells["B{$row}"] = ['v' => '', 'x' => ['number_format' => 'currency']];
    $cells["C{$row}"] = ['v' => '', 'x' => ['number_format' => 'currency']];
    $cells["D{$row}"] = ['v' => "=IF(C{$row}=\"\",\"\",C{$row}-B{$row})", 'x' => ['number_format' => 'currency']];
}
foreach ($lines as $i => $line) {
    $row = $i + 9;
    $cells["A{$row}"] = $line;
    $cells["B{$row}"] = ['v' => '', 'x' => ['number_format' => 'currency']];
    $cells["C{$row}"] = ['v' => '', 'x' => ['number_format' => 'currency']];
    $cells["D{$row}"] = ['v' => "=IF(C{$row}=\"\",\"\",B{$row}-C{$row})", 'x' => ['number_format' => 'currency']];
}

return [
    'key' => 'household-budget',
    'title' => 'Household budget',
    'group' => 'Personal',
    'summary' => 'A month of money in and out, planned against actual.',
    'words' => ['household', 'personal', 'family', 'home', 'budget', 'monthly', 'savings'],
    'sheets' => [
        [
            'name' => 'Budget',
            'color' => 'teal',
            'widths' => ['A' => 220, 'B' => 120, 'C' => 120, 'D' => 120],
            'cells' => $cells,
        ],
    ],
];
