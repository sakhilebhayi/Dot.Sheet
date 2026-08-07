<?php

use App\Models\Spreadsheet;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('spreadsheet.{spreadsheetId}', function ($user, $spreadsheetId) {
    $spreadsheet = Spreadsheet::find($spreadsheetId);
    if (! $spreadsheet) {
        return false;
    }

    if (! $user->can('view', $spreadsheet)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ];
});
