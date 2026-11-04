<?php

$types = ['Near miss', 'First aid', 'Medical treatment', 'Lost time', 'Property damage', 'Environmental'];
$summary = ['A1' => ['v' => 'Incidents by type', 'x' => ['bold' => true, 'font_size' => 16]]];
foreach ($types as $i => $type) {
    $row = $i + 3;
    $summary["A{$row}"] = $type;
    $summary["B{$row}"] = "=COUNTIF(Incidents!\$C\$2:\$C\$301,A{$row})";
}
$summary['A10'] = 'Open actions';
$summary['B10'] = '=COUNTIF(Incidents!J2:J301,"Open")';
$summary['A11'] = 'Overdue actions';
$summary['B11'] = '=COUNTIF(Incidents!J2:J301,"Overdue")';

return [
    'key' => 'incident-register',
    'title' => 'Safety incident register',
    'group' => 'Operations',
    'summary' => 'Incidents, their actions and owners, with overdue actions flagged.',
    'words' => ['safety', 'incident', 'incidents', 'accident', 'near', 'miss', 'hazard', 'health', 'sheq', 'compliance'],
    'sheets' => [
        [
            'name' => 'Incidents',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 300, 'columns' => [
                ['heading' => 'Date', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Area', 'width' => 140],
                ['heading' => 'Type', 'width' => 160, 'allowed' => $types],
                ['heading' => 'Severity', 'width' => 100, 'allowed' => ['Low', 'Medium', 'High']],
                ['heading' => 'What happened', 'width' => 280],
                ['heading' => 'Action', 'width' => 260],
                ['heading' => 'Owner', 'width' => 140],
                ['heading' => 'Action due', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Closed', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Status', 'width' => 100, 'formula' => '=IF(B{r}="","",IF(I{r}<>"","Closed",IF(AND(H{r}<>"",H{r}<TODAY()),"Overdue","Open")))'],
            ]],
            'rules' => [
                ['column' => 'J', 'operator' => '==', 'value' => 'Overdue', 'color' => '#F7E0DA'],
                ['column' => 'D', 'operator' => '==', 'value' => 'High', 'color' => '#FBF0BF'],
            ],
        ],
        [
            'name' => 'Summary',
            'color' => 'green',
            'widths' => ['A' => 200, 'B' => 90],
            'cells' => $summary,
            'charts' => [['title' => 'Incidents by type', 'type' => 'bar', 'data' => 'B3:B8', 'labels' => 'A3:A8']],
        ],
    ],
];
