<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Largest sheet the Excel export will build
    |--------------------------------------------------------------------------
    |
    | PhpSpreadsheet keeps every cell in memory while it writes the file (about 1.2 KB per
    | cell), and a shared host allows 128 MB or less per request. Above this many cells the
    | export answers with a plain message pointing at the CSV export, which has no limit,
    | instead of running out of memory with a 500. 0 turns the limit off.
    |
    */
    'export_excel_max_cells' => (int) env('SHEET_EXPORT_EXCEL_MAX_CELLS', 50000),

    /*
    |--------------------------------------------------------------------------
    | User scripts
    |--------------------------------------------------------------------------
    |
    | Saved scripts are JavaScript typed by anyone who can edit a spreadsheet, stored in the
    | spreadsheet's settings and run in the browser of whoever clicks "Run" -- so an editor can
    | have code run as the owner. The web worker they run in has no DOM and no cookies, but it
    | can still call fetch() and WebSocket, so it is not a sandbox. Off unless
    | SHEET_SCRIPTS_ENABLED=true; while off, the Scripts panel is not shown, the server refuses
    | to save or run scripts and the browser never starts the worker. Macros (recorded
    | cell actions, no code) are not affected.
    |
    */
    'scripts_enabled' => filter_var(env('SHEET_SCRIPTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

];
