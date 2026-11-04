<?php

$sections = [];
foreach (range(4, 13) as $row) {
    $sections["B{$row}"] = "=IF(A{$row}=\"\",\"\",SUMIFS(Shifts!\$D\$2:\$D\$501,Shifts!\$C\$2:\$C\$501,A{$row}))";
    $sections["C{$row}"] = "=IF(A{$row}=\"\",\"\",SUMIFS(Shifts!\$E\$2:\$E\$501,Shifts!\$C\$2:\$C\$501,A{$row}))";
}

return [
    'key' => 'production-log',
    'title' => 'Shift production log',
    'group' => 'Mining',
    'summary' => 'Tonnes mined and hoisted by shift and section, with tonnes per person.',
    'words' => ['mining', 'mine', 'production', 'tonnes', 'shift', 'shifts', 'hoisted', 'output', 'section'],
    'sheets' => [
        [
            'name' => 'Shifts',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 500, 'columns' => [
                ['heading' => 'Date', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Shift', 'width' => 90, 'allowed' => ['Morning', 'Afternoon', 'Night']],
                ['heading' => 'Section', 'width' => 130],
                ['heading' => 'Tonnes mined', 'width' => 120, 'allowed' => ['min' => 0], 'format' => ['number_format' => 'number']],
                ['heading' => 'Tonnes hoisted', 'width' => 120, 'allowed' => ['min' => 0], 'format' => ['number_format' => 'number']],
                ['heading' => 'Crew', 'width' => 80, 'allowed' => ['min' => 0]],
                ['heading' => 'Tonnes each', 'width' => 110, 'formula' => '=IF(OR(D{r}="",F{r}="",F{r}=0),"",D{r}/F{r})', 'format' => ['number_format' => 'number']],
                ['heading' => 'Notes', 'width' => 260],
            ]],
        ],
        [
            'name' => 'By section',
            'color' => 'green',
            'widths' => ['A' => 170, 'B' => 130, 'C' => 130],
            'cells' => [
                'A1' => ['v' => 'Tonnes by section', 'x' => ['bold' => true, 'font_size' => 16]],
                'A3' => ['v' => 'Section', 'x' => ['bold' => true]],
                'B3' => ['v' => 'Mined', 'x' => ['bold' => true]],
                'C3' => ['v' => 'Hoisted', 'x' => ['bold' => true]],
                'A14' => ['v' => 'Total', 'x' => ['bold' => true]],
                'B14' => ['v' => '=SUM(B4:B13)', 'x' => ['bold' => true]],
                'C14' => ['v' => '=SUM(C4:C13)', 'x' => ['bold' => true]],
                ...$sections,
            ],
            'charts' => [['title' => 'Tonnes mined by section', 'type' => 'bar', 'data' => 'B4:B13', 'labels' => 'A4:A13']],
        ],
    ],
];
