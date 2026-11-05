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
    | Largest sheet or workbook a duplicate can hold
    |--------------------------------------------------------------------------
    |
    | A duplicate is one write transaction, so it holds the single SQLite write lock for its
    | whole length, and everyone else waits. 100,000 cells measured 0.2 seconds on a laptop, which
    | leaves a slower shared host well under busy_timeout 5000.
    | 0 turns the limit off.
    |
    */
    'duplicate_max_cells' => (int) env('SHEET_DUPLICATE_MAX_CELLS', 100000),

    /*
    |--------------------------------------------------------------------------
    | Largest workbook read whole, with its formulas and formatting
    |--------------------------------------------------------------------------
    |
    | Reading a file with its formatting holds every cell in memory, at about 1.2 KB each, and a
    | shared host allows 128 MB or less per request. This counts the cells its sheets span. A
    | larger file comes in as values, one sheet, and is told so. 0 turns the limit off.
    |
    */
    'import_whole_max_cells' => (int) env('SHEET_IMPORT_WHOLE_MAX_CELLS', 60000),

    /*
    |--------------------------------------------------------------------------
    | Largest sheet laid out for paper
    |--------------------------------------------------------------------------
    |
    | Print and PDF draw every cell as a table cell. A PDF is built in the request and the time
    | it takes grows quickly with the size of the table. 0 turns the limit off.
    |
    */
    'page_max_cells' => (int) env('SHEET_PAGE_MAX_CELLS', 5000),

    /*
    |--------------------------------------------------------------------------
    | How often an open page asks what has changed
    |--------------------------------------------------------------------------
    |
    | Without a broadcast server, others' changes arrive this many seconds late at most. Only a
    | page in view asks. Lower it and every open workbook costs the host more requests.
    |
    */
    'pulse_seconds' => (int) env('SHEET_PULSE_SECONDS', 20),

    /*
    |--------------------------------------------------------------------------
    | Largest import that runs inside the request
    |--------------------------------------------------------------------------
    |
    | An import below this many non-empty CELLS is written in one all-or-nothing transaction,
    | and that transaction holds the single SQLite write lock for its whole length (measured
    | at 0.2 to 0.35 ms per cell, so 6000 cells is 1 to 2 seconds, well under busy_timeout 5000).
    | The lock time depends on cells, not rows: 1,499 rows of 20 columns held it for 10 seconds.
    | At or above this many cells the file is split into queued chunk jobs instead (one short
    | write transaction each, so sessions, the queue worker and other people are never kept
    | waiting) and the person is told it is importing in the background. 0 queues every import.
    |
    */
    'import_inline_max_cells' => (int) env('SHEET_IMPORT_INLINE_MAX_CELLS', 6000),

    /*
    |--------------------------------------------------------------------------
    | Cells written per queued import chunk
    |--------------------------------------------------------------------------
    |
    | One chunk job is one write transaction, so it is sized in cells too: the rows per chunk are
    | this many cells divided by the widest row of the file (at least 1 row, at most 500 rows).
    | 5000 cells is 0.9 to 1.75 seconds of write lock.
    |
    */
    'import_chunk_cells' => (int) env('SHEET_IMPORT_CHUNK_CELLS', 5000),

    /*
    |--------------------------------------------------------------------------
    | Pause after each queued import chunk
    |--------------------------------------------------------------------------
    |
    | Milliseconds the worker waits after a chunk has committed, before it takes the next job.
    | SQLite hands the write lock to whoever asks first, not to whoever has waited longest, so
    | chunks that follow each other without a gap can starve a session or cache write until it
    | gives up with "database is locked". 0 turns the pause off.
    |
    */
    'import_chunk_pause_ms' => (int) env('SHEET_IMPORT_CHUNK_PAUSE_MS', 250),

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

    /*
    |--------------------------------------------------------------------------
    | Insert and delete rows and columns
    |--------------------------------------------------------------------------
    |
    | Off unless SHEET_STRUCTURE_OPS_ENABLED=true. Moving rows and columns under people who are
    | editing the same sheet is the riskiest thing the editor does (stale grids, queued imports,
    | formulas, the single SQLite write lock): the code is in place and tested, but the switch stays
    | off until the multi-process race test (tests/Feature/ProductionSettingsTest.php) has been run
    | against the target host. While off the context-menu items and toolbar buttons are hidden, every
    | server handler refuses ("Inserting and deleting rows and columns is not available yet.") and
    | nothing in SheetStructureService runs. Editing, import, export, tab delete and version
    | restore are not affected.
    |
    */
    'structure_ops_enabled' => filter_var(env('SHEET_STRUCTURE_OPS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Days the record of who did what to a workbook is kept.
    */
    'audit_keep_days' => (int) env('SHEET_AUDIT_KEEP_DAYS', 730),

    /*
    | Seconds a person's request may spend working formulas out. What is left finishes in the background and the
    | sheet says so, so a huge sheet never stops a workbook from opening. Keep it below the host's PHP time limit.
    */
    'recalculate_seconds' => (int) env('SHEET_RECALCULATE_SECONDS', 20),
];
