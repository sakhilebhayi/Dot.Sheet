<?php

return [
    'key' => 'invoice-register',
    'title' => 'Invoice register',
    'group' => 'Business',
    'summary' => 'Who owes what, when it falls due, and what is overdue.',
    'words' => ['invoice', 'invoices', 'debtors', 'receivables', 'billing', 'customers', 'overdue'],
    'sheets' => [
        [
            'name' => 'Invoices',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 300, 'columns' => [
                ['heading' => 'Number', 'width' => 110],
                ['heading' => 'Customer', 'width' => 220],
                ['heading' => 'Issued', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Terms, days', 'width' => 100, 'allowed' => ['min' => 0]],
                ['heading' => 'Due', 'width' => 110, 'formula' => '=IF(OR(C{r}="",D{r}=""),"",C{r}+D{r})', 'format' => ['number_format' => 'date']],
                ['heading' => 'Amount', 'width' => 120, 'format' => ['number_format' => 'currency']],
                ['heading' => 'Paid', 'width' => 120, 'format' => ['number_format' => 'currency']],
                ['heading' => 'Balance', 'width' => 120, 'formula' => '=IF(F{r}="","",F{r}-G{r})', 'format' => ['number_format' => 'currency']],
                ['heading' => 'Status', 'width' => 100, 'formula' => '=IF(F{r}="","",IF(H{r}<=0,"Paid",IF(AND(E{r}<>"",E{r}<TODAY()),"Overdue","Open")))'],
            ]],
            'rules' => [['column' => 'I', 'operator' => '==', 'value' => 'Overdue', 'color' => '#F7E0DA']],
        ],
        [
            'name' => 'Owed',
            'color' => 'green',
            'widths' => ['A' => 200, 'B' => 140],
            'cells' => [
                'A1' => ['v' => 'What is owed', 'x' => ['bold' => true, 'font_size' => 16]],
                'A3' => 'Open',
                'B3' => ['v' => '=SUMIFS(Invoices!H2:H301,Invoices!I2:I301,"Open")', 'x' => ['number_format' => 'currency']],
                'A4' => 'Overdue',
                'B4' => ['v' => '=SUMIFS(Invoices!H2:H301,Invoices!I2:I301,"Overdue")', 'x' => ['number_format' => 'currency']],
                'A5' => ['v' => 'Total owed', 'x' => ['bold' => true]],
                'B5' => ['v' => '=B3+B4', 'x' => ['bold' => true, 'number_format' => 'currency']],
            ],
        ],
    ],
];
