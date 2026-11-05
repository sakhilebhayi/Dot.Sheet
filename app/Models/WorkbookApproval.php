<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkbookApproval extends Model
{
    public const WAITING = 'waiting';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const REOPENED = 'reopened';

    public const WITHDRAWN = 'withdrawn';

    protected $fillable = ['spreadsheet_id', 'requested_by', 'decided_by', 'state', 'note'];
}
