<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Where a workbook is filed in Dot.Files, and the version this app last saw. */
class WorkbookFiling extends Model
{
    protected $fillable = ['spreadsheet_id', 'team_id', 'file_uuid', 'version', 'open_url', 'filed_by'];
}
