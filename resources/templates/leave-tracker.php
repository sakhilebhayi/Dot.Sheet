<?php

$balances = [];
foreach (range(2, 41) as $row) {
    $balances["C{$row}"] = "=IF(A{$row}=\"\",\"\",SUMIFS(Leave!\$E\$2:\$E\$301,Leave!\$A\$2:\$A\$301,A{$row},Leave!\$B\$2:\$B\$301,\"Annual\",Leave!\$F\$2:\$F\$301,\"Approved\"))";
    $balances["D{$row}"] = "=IF(OR(A{$row}=\"\",B{$row}=\"\"),\"\",B{$row}-C{$row})";
}

return [
    'key' => 'leave-tracker',
    'title' => 'Leave tracker',
    'group' => 'People',
    'summary' => 'Leave requests with working days counted, and each person\'s annual balance.',
    'words' => ['leave', 'holiday', 'vacation', 'annual', 'sick', 'days', 'off'],
    'sheets' => [
        [
            'name' => 'Leave',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 300, 'columns' => [
                ['heading' => 'Employee', 'width' => 200],
                ['heading' => 'Type', 'width' => 170, 'allowed' => ['Annual', 'Sick', 'Family responsibility', 'Study', 'Unpaid']],
                ['heading' => 'First day', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Last day', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Working days', 'width' => 110, 'formula' => '=IF(OR(C{r}="",D{r}=""),"",NETWORKDAYS(C{r},D{r}))'],
                ['heading' => 'Status', 'width' => 110, 'allowed' => ['Requested', 'Approved', 'Declined']],
            ]],
        ],
        [
            'name' => 'Balances',
            'color' => 'green',
            'frozen_rows' => 1,
            'widths' => ['A' => 200, 'B' => 110, 'C' => 110, 'D' => 110],
            'cells' => [
                'A1' => ['v' => 'Employee', 'x' => ['bold' => true]],
                'B1' => ['v' => 'Entitled', 'x' => ['bold' => true]],
                'C1' => ['v' => 'Annual taken', 'x' => ['bold' => true]],
                'D1' => ['v' => 'Left', 'x' => ['bold' => true]],
                ...$balances,
            ],
        ],
    ],
];
