<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SheetProtection extends Model
{
    protected $fillable = [
        'spreadsheet_id',
        'sheet_tab_id',
        'created_by',
        'label',
        'start_row',
        'start_col',
        'end_row',
        'end_col',
    ];

    public function isWholeSheet(): bool
    {
        return $this->start_row === null;
    }
}
