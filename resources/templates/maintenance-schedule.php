<?php

return [
    'key' => 'maintenance-schedule',
    'title' => 'Maintenance schedule',
    'group' => 'Operations',
    'summary' => 'Recurring jobs by asset. The next date and status work themselves out.',
    'words' => ['maintenance', 'service', 'servicing', 'asset', 'assets', 'schedule', 'inspection', 'equipment'],
    'sheets' => [
        [
            'name' => 'Schedule',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 300, 'columns' => [
                ['heading' => 'Asset', 'width' => 200],
                ['heading' => 'Job', 'width' => 260],
                ['heading' => 'Every, days', 'width' => 100, 'allowed' => ['min' => 1]],
                ['heading' => 'Last done', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Next due', 'width' => 110, 'formula' => '=IF(OR(C{r}="",D{r}=""),"",D{r}+C{r})', 'format' => ['number_format' => 'date']],
                ['heading' => 'Days left', 'width' => 90, 'formula' => '=IF(E{r}="","",E{r}-TODAY())'],
                ['heading' => 'Status', 'width' => 100, 'formula' => '=IF(F{r}="","",IF(F{r}<0,"Overdue",IF(F{r}<=7,"Due soon","OK")))'],
                ['heading' => 'Owner', 'width' => 150],
            ]],
            'rules' => [
                ['column' => 'G', 'operator' => '==', 'value' => 'Overdue', 'color' => '#F7E0DA'],
                ['column' => 'G', 'operator' => '==', 'value' => 'Due soon', 'color' => '#FBF0BF'],
            ],
        ],
    ],
];
