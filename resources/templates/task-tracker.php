<?php

return [
    'key' => 'task-tracker',
    'title' => 'Project task tracker',
    'group' => 'Projects',
    'summary' => 'Tasks with owners and due dates. Late ones flag themselves.',
    'words' => ['task', 'tasks', 'project', 'plan', 'tracker', 'deadlines', 'todo', 'milestones'],
    'sheets' => [
        [
            'name' => 'Tasks',
            'color' => 'teal',
            'frozen_rows' => 1,
            'table' => ['rows' => 200, 'columns' => [
                ['heading' => 'Task', 'width' => 300],
                ['heading' => 'Owner', 'width' => 150],
                ['heading' => 'Start', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Due', 'width' => 110, 'format' => ['number_format' => 'date']],
                ['heading' => 'Status', 'width' => 120, 'allowed' => ['Not started', 'In progress', 'Blocked', 'Done']],
                ['heading' => 'Flag', 'width' => 100, 'formula' => '=IF(OR(D{r}="",E{r}="Done"),"",IF(D{r}<TODAY(),"Overdue",""))'],
            ]],
            'rules' => [['column' => 'F', 'operator' => '==', 'value' => 'Overdue', 'color' => '#F7E0DA']],
        ],
        [
            'name' => 'Summary',
            'color' => 'green',
            'widths' => ['A' => 200, 'B' => 100],
            'cells' => [
                'A1' => ['v' => 'Where the work stands', 'x' => ['bold' => true, 'font_size' => 16]],
                'A3' => 'Not started',
                'B3' => '=COUNTIF(Tasks!E2:E201,A3)',
                'A4' => 'In progress',
                'B4' => '=COUNTIF(Tasks!E2:E201,A4)',
                'A5' => 'Blocked',
                'B5' => '=COUNTIF(Tasks!E2:E201,A5)',
                'A6' => 'Done',
                'B6' => '=COUNTIF(Tasks!E2:E201,A6)',
                'A8' => 'Overdue',
                'B8' => '=COUNTIF(Tasks!F2:F201,"Overdue")',
            ],
            'charts' => [['title' => 'Tasks by status', 'type' => 'bar', 'data' => 'B3:B6', 'labels' => 'A3:A6']],
        ],
    ],
];
