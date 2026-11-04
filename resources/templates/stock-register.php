<?php

return [
    'key' => 'stock-register',
    'title' => 'Stock inventory register',
    'group' => 'Operations',
    'summary' => 'What you hold, what it is worth, and what to reorder.',
    'words' => ['stock', 'inventory', 'warehouse', 'reorder', 'items', 'parts', 'stores'],
    'sheets' => [
        [
            'name' => 'Stock',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 300, 'columns' => [
                ['heading' => 'Code', 'width' => 100],
                ['heading' => 'Item', 'width' => 240],
                ['heading' => 'Supplier', 'width' => 180],
                ['heading' => 'Unit cost', 'width' => 110, 'format' => ['number_format' => 'currency']],
                ['heading' => 'On hand', 'width' => 90, 'allowed' => ['min' => 0]],
                ['heading' => 'Reorder at', 'width' => 100, 'allowed' => ['min' => 0]],
                ['heading' => 'Value', 'width' => 120, 'formula' => '=IF(OR(D{r}="",E{r}=""),"",D{r}*E{r})', 'format' => ['number_format' => 'currency']],
                ['heading' => 'Status', 'width' => 100, 'formula' => '=IF(OR(E{r}="",F{r}=""),"",IF(E{r}<=F{r},"Reorder","OK"))'],
            ]],
            'rules' => [['column' => 'H', 'operator' => '==', 'value' => 'Reorder', 'color' => '#F7E0DA']],
        ],
        [
            'name' => 'Summary',
            'color' => 'green',
            'widths' => ['A' => 220, 'B' => 140],
            'cells' => [
                'A1' => ['v' => 'Stock at a glance', 'x' => ['bold' => true, 'font_size' => 16]],
                'A3' => 'Lines on the register',
                'B3' => '=COUNTA(Stock!B2:B301)',
                'A4' => 'Value of stock on hand',
                'B4' => ['v' => '=SUM(Stock!G2:G301)', 'x' => ['number_format' => 'currency']],
                'A5' => 'Lines to reorder',
                'B5' => '=COUNTIF(Stock!H2:H301,"Reorder")',
            ],
        ],
    ],
];
