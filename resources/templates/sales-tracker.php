<?php

$byRep = [
    'A1' => ['v' => 'Sales by rep', 'x' => ['bold' => true, 'font_size' => 16]],
    'A2' => 'Won deals only. Put each rep\'s name in column A, spelt as on Sales.',
    'A4' => ['v' => 'Rep', 'x' => ['bold' => true]],
    'B4' => ['v' => 'Won value', 'x' => ['bold' => true]],
    'C4' => ['v' => 'Deals won', 'x' => ['bold' => true]],
    'D4' => ['v' => 'Target', 'x' => ['bold' => true]],
    'E4' => ['v' => 'Of target', 'x' => ['bold' => true]],
    'A15' => ['v' => 'Total', 'x' => ['bold' => true]],
    'B15' => ['v' => '=SUM(B5:B14)', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'C15' => ['v' => '=SUM(C5:C14)', 'x' => ['bold' => true]],
    'D15' => ['v' => '=SUM(D5:D14)', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'E15' => ['v' => '=IF(D15=0,"",B15/D15)', 'x' => ['bold' => true, 'number_format' => 'percent']],
];
foreach (range(5, 14) as $row) {
    $byRep["B{$row}"] = ['v' => "=IF(A{$row}=\"\",\"\",SUMIFS(Sales!\$G\$2:\$G\$301,Sales!\$B\$2:\$B\$301,A{$row},Sales!\$H\$2:\$H\$301,\"Won\"))", 'x' => ['number_format' => 'currency']];
    $byRep["C{$row}"] = "=IF(A{$row}=\"\",\"\",COUNTIFS(Sales!\$B\$2:\$B\$301,A{$row},Sales!\$H\$2:\$H\$301,\"Won\"))";
    $byRep["D{$row}"] = ['v' => '', 'x' => ['number_format' => 'currency']];
    $byRep["E{$row}"] = ['v' => "=IF(OR(A{$row}=\"\",D{$row}=\"\",D{$row}=0),\"\",B{$row}/D{$row})", 'x' => ['number_format' => 'percent']];
}

return [
    'key' => 'sales-tracker',
    'title' => 'Sales performance tracker',
    'group' => 'Business',
    'summary' => 'Deals by rep and stage, with won value against each rep\'s target.',
    'words' => ['sales', 'sale', 'revenue', 'deals', 'pipeline', 'reps', 'performance', 'targets'],
    'sheets' => [
        [
            'name' => 'Sales',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 300, 'columns' => [
                ['heading' => 'Date', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Rep', 'width' => 150],
                ['heading' => 'Customer', 'width' => 200],
                ['heading' => 'Product', 'width' => 180],
                ['heading' => 'Quantity', 'width' => 90, 'allowed' => ['min' => 0]],
                ['heading' => 'Unit price', 'width' => 110, 'format' => ['number_format' => 'currency']],
                ['heading' => 'Value', 'width' => 120, 'formula' => '=IF(OR(E{r}="",F{r}=""),"",E{r}*F{r})', 'format' => ['number_format' => 'currency']],
                ['heading' => 'Stage', 'width' => 110, 'allowed' => ['Quoted', 'Won', 'Lost']],
            ]],
            'rules' => [['column' => 'H', 'operator' => '==', 'value' => 'Won', 'color' => '#DCEBD9']],
        ],
        [
            'name' => 'By rep',
            'color' => 'green',
            'widths' => ['A' => 200, 'B' => 130, 'C' => 100, 'D' => 130, 'E' => 100],
            'cells' => $byRep,
            'charts' => [['title' => 'Won value by rep', 'type' => 'bar', 'data' => 'B5:B14', 'labels' => 'A5:A14']],
        ],
    ],
];
