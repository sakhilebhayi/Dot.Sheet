<?php

return [
    'key' => 'project-budget',
    'title' => 'Project budget',
    'group' => 'Projects',
    'summary' => 'Budget, committed and actual by line, with what is left and any overrun.',
    'words' => ['project', 'budget', 'cost', 'overrun', 'variance', 'construction'],
    'sheets' => [
        [
            'name' => 'Budget',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 60, 'columns' => [
                ['heading' => 'Line', 'width' => 240],
                ['heading' => 'Category', 'width' => 150, 'allowed' => ['Labour', 'Materials', 'Equipment', 'Subcontractors', 'Travel', 'Contingency', 'Other']],
                ['heading' => 'Budget', 'width' => 120, 'format' => ['number_format' => 'currency']],
                ['heading' => 'Committed', 'width' => 120, 'format' => ['number_format' => 'currency']],
                ['heading' => 'Actual', 'width' => 120, 'format' => ['number_format' => 'currency']],
                ['heading' => 'Left', 'width' => 120, 'formula' => '=IF(C{r}="","",C{r}-D{r}-E{r})', 'format' => ['number_format' => 'currency']],
                ['heading' => 'Used', 'width' => 90, 'formula' => '=IF(OR(C{r}="",C{r}=0),"",(D{r}+E{r})/C{r})', 'format' => ['number_format' => 'percent']],
            ]],
            'rules' => [['column' => 'F', 'operator' => '<', 'value' => '0', 'color' => '#F7E0DA']],
            'cells' => [
                'H1' => ['v' => 'Totals', 'x' => ['bold' => true]],
                'H2' => 'Budget',
                'I2' => ['v' => '=SUM(C2:C61)', 'x' => ['number_format' => 'currency']],
                'H3' => 'Committed',
                'I3' => ['v' => '=SUM(D2:D61)', 'x' => ['number_format' => 'currency']],
                'H4' => 'Actual',
                'I4' => ['v' => '=SUM(E2:E61)', 'x' => ['number_format' => 'currency']],
                'H5' => 'Left',
                'I5' => ['v' => '=I2-I3-I4', 'x' => ['bold' => true, 'number_format' => 'currency']],
            ],
            'widths' => ['H' => 110, 'I' => 130],
        ],
    ],
];
