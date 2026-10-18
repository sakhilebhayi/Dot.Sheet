<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Largest sheet the Excel export will build
    |--------------------------------------------------------------------------
    |
    | PhpSpreadsheet keeps every cell in memory while it writes the file (about 1.5 KB per
    | cell), and a shared host allows 128 MB or less per request. Above this many cells the
    | export answers with a plain message pointing at the CSV export, which streams and has
    | no limit, instead of running out of memory with a 500. 0 turns the limit off.
    |
    */
    'export_excel_max_cells' => (int) env('SHEET_EXPORT_EXCEL_MAX_CELLS', 50000),

];
