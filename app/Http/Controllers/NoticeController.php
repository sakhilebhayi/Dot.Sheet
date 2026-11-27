<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notification centre behind the header bell (EA-2, E-02): opening a notice marks it read and goes where it
 * points; "Mark all read" clears the count. Only the signed-in person's own notices are ever touched.
 */
class NoticeController extends Controller
{
    public function open(Request $request, string $notice): RedirectResponse
    {
        $row = $request->user()->notifications()->whereKey($notice)->firstOrFail();
        $row->markAsRead();

        return redirect()->to(self::safeUrl($row->data['url'] ?? null));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back(fallback: route('dashboard'));
    }

    /** Only somewhere in this app: a stored link to another host (never written by this app) goes to the dashboard. */
    public static function safeUrl(mixed $url): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return is_string($url) && ($url === $base || str_starts_with($url, $base.'/') || (str_starts_with($url, '/') && ! str_starts_with($url, '//')))
            ? $url
            : route('dashboard');
    }
}
