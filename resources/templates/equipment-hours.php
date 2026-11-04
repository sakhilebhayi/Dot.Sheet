<?php

$byUnit = [
    'A1' => ['v' => 'Equipment', 'x' => ['bold' => true]],
    'B1' => ['v' => 'Scheduled', 'x' => ['bold' => true]],
    'C1' => ['v' => 'Operating', 'x' => ['bold' => true]],
    'D1' => ['v' => 'Breakdown', 'x' => ['bold' => true]],
    'E1' => ['v' => 'Availability', 'x' => ['bold' => true]],
    'F1' => ['v' => 'Utilisation', 'x' => ['bold' => true]],
];
foreach (range(2, 31) as $row) {
    foreach (['B' => 'E', 'C' => 'F', 'D' => 'G'] as $to => $from) {
        $byUnit["{$to}{$row}"] = "=IF(\$A{$row}=\"\",\"\",SUMIFS(Hours!\${$from}\$2:\${$from}\$501,Hours!\$B\$2:\$B\$501,\$A{$row}))";
    }
    $byUnit["E{$row}"] = ['v' => "=IF(OR(A{$row}=\"\",B{$row}=0),\"\",(B{$row}-D{$row})/B{$row})", 'x' => ['number_format' => 'percent']];
    $byUnit["F{$row}"] = ['v' => "=IF(OR(A{$row}=\"\",B{$row}-D{$row}<=0),\"\",C{$row}/(B{$row}-D{$row}))", 'x' => ['number_format' => 'percent']];
}

return [
    'key' => 'equipment-hours',
    'title' => 'Equipment operating-hours report',
    'group' => 'Mining',
    'summary' => 'Hours by machine and shift, with availability and utilisation worked out.',
    'words' => ['mining', 'mine', 'equipment', 'operating', 'hours', 'machine', 'machines', 'fleet', 'availability', 'utilisation', 'plant'],
    'sheets' => [
        [
            'name' => 'Hours',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 500, 'columns' => [
                ['heading' => 'Date', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Equipment', 'width' => 130],
                ['heading' => 'Type', 'width' => 130, 'allowed' => ['Truck', 'Excavator', 'Loader', 'Drill rig', 'Dozer', 'Grader', 'Other']],
                ['heading' => 'Shift', 'width' => 90, 'allowed' => ['Day', 'Night']],
                ['heading' => 'Scheduled', 'width' => 100, 'allowed' => ['min' => 0, 'max' => 24]],
                ['heading' => 'Operating', 'width' => 100, 'allowed' => ['min' => 0, 'max' => 24]],
                ['heading' => 'Breakdown', 'width' => 100, 'allowed' => ['min' => 0, 'max' => 24]],
                ['heading' => 'Standby', 'width' => 100, 'formula' => '=IF(E{r}="","",E{r}-F{r}-G{r})'],
            ]],
            'rules' => [['column' => 'H', 'operator' => '<', 'value' => '0', 'color' => '#F7E0DA']],
        ],
        [
            'name' => 'By equipment',
            'color' => 'green',
            'frozen_rows' => 1,
            'widths' => ['A' => 150, 'B' => 100, 'C' => 100, 'D' => 100, 'E' => 110, 'F' => 110],
            'cells' => $byUnit,
        ],
    ],
];
