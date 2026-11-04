<?php

$categories = ['Rent', 'Salaries', 'Stock', 'Fuel and transport', 'Utilities', 'Insurance', 'Marketing', 'Professional fees', 'Other'];

$summary = [
    'A1' => ['v' => 'Expenses by category', 'x' => ['bold' => true, 'font_size' => 16]],
    'A2' => 'VAT rate, checked against SARS',
    'B2' => ['v' => '0.15', 'x' => ['number_format' => 'percent']],
    'A4' => ['v' => 'Category', 'x' => ['bold' => true]],
    'B4' => ['v' => 'Total', 'x' => ['bold' => true]],
    'C4' => ['v' => 'Share', 'x' => ['bold' => true]],
    'A14' => ['v' => 'Total', 'x' => ['bold' => true]],
    'B14' => ['v' => '=SUM(B5:B13)', 'x' => ['bold' => true, 'number_format' => 'currency']],
    'A16' => 'VAT in these expenses',
    'B16' => ['v' => '=SUM(Expenses!G2:G201)', 'x' => ['number_format' => 'currency']],
];
foreach ($categories as $i => $category) {
    $row = $i + 5;
    $summary["A{$row}"] = $category;
    $summary["B{$row}"] = ['v' => "=SUMIFS(Expenses!\$E\$2:\$E\$201,Expenses!\$C\$2:\$C\$201,A{$row})", 'x' => ['number_format' => 'currency']];
    $summary["C{$row}"] = ['v' => "=IF(\$B\$14=0,\"\",B{$row}/\$B\$14)", 'x' => ['number_format' => 'percent']];
}

return [
    'key' => 'expense-tracker',
    'title' => 'Monthly expense tracker',
    'group' => 'Business',
    'summary' => 'Every expense with its category and VAT, totalled by category.',
    'words' => ['expense', 'expenses', 'spending', 'spend', 'costs', 'petty', 'cash'],
    'sheets' => [
        [
            'name' => 'Expenses',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 200, 'columns' => [
                ['heading' => 'Date', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Description', 'width' => 240],
                ['heading' => 'Category', 'width' => 160, 'allowed' => $categories],
                ['heading' => 'Supplier', 'width' => 180],
                ['heading' => 'Amount', 'width' => 120, 'format' => ['number_format' => 'currency']],
                ['heading' => 'VAT included', 'width' => 110, 'allowed' => ['Yes', 'No']],
                ['heading' => 'VAT', 'width' => 110, 'formula' => '=IF(OR(E{r}="",F{r}<>"Yes"),"",E{r}*Summary!$B$2/(1+Summary!$B$2))', 'format' => ['number_format' => 'currency']],
            ]],
            'rules' => [['column' => 'E', 'operator' => '>', 'value' => '50000', 'color' => '#FBF0BF']],
        ],
        [
            'name' => 'Summary',
            'color' => 'green',
            'widths' => ['A' => 240, 'B' => 130, 'C' => 90],
            'cells' => $summary,
            'charts' => [['title' => 'Expenses by category', 'type' => 'pie', 'data' => 'B5:B13', 'labels' => 'A5:A13']],
        ],
    ],
];
