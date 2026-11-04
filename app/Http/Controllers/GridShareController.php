<?php

namespace App\Http\Controllers;

use App\Models\Spreadsheet;
use App\Models\SpreadsheetInvitation;
use App\Models\User;
use App\Services\ShareRefused;
use App\Services\SheetSharing;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

/**
 * Sharing in the new editor. Every answer is the whole panel as it now stands, and every request is
 * checked here: rights to share can be taken away while the panel is open.
 */
class GridShareController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private SheetSharing $sharing) {}

    public function index(Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        return $this->answer($spreadsheet);
    }

    public function share(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        $input = $request->validate([
            'email' => 'required|email|max:255',
            'permission' => $this->permissionRule(),
            'days' => 'required|integer|min:1|max:90',
        ], [
            'email.required' => 'Enter their email address.',
            'email.email' => 'That does not look like an email address.',
            'days.*' => 'An invitation can last from 1 to 90 days.',
        ]);

        return $this->changing($spreadsheet, function () use ($spreadsheet, $input) {
            $shared = $this->sharing->share($spreadsheet, $this->person(), $input['email'], $input['permission'], (int) $input['days']);

            return $shared ? "Shared with {$input['email']}." : "{$input['email']} has no account here yet. Copy their link and send it to them.";
        });
    }

    public function change(Request $request, Spreadsheet $spreadsheet, User $user): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);
        abort_unless($spreadsheet->sharedUsers()->whereKey($user->id)->exists(), 404);

        $input = $request->validate(['permission' => $this->permissionRule()]);

        return $this->changing($spreadsheet, function () use ($spreadsheet, $user, $input) {
            $this->sharing->setPermission($spreadsheet, $user->id, $input['permission'], (int) Auth::id());

            return "Changed what {$user->name} can do.";
        });
    }

    public function remove(Spreadsheet $spreadsheet, User $user): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);
        abort_unless($spreadsheet->sharedUsers()->whereKey($user->id)->exists(), 404);

        return $this->changing($spreadsheet, function () use ($spreadsheet, $user) {
            $this->sharing->remove($spreadsheet, $user->id, (int) Auth::id());

            return "Removed {$user->name}.";
        });
    }

    public function cancel(Spreadsheet $spreadsheet, SpreadsheetInvitation $invitation): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);
        abort_unless($invitation->spreadsheet_id === $spreadsheet->id && $invitation->accepted_at === null, 404);

        return $this->changing($spreadsheet, function () use ($invitation) {
            $invitation->delete();

            return "Cancelled the invitation for {$invitation->email}. Its link no longer works.";
        });
    }

    public function link(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        $input = $request->validate(
            ['enabled' => 'required|boolean', 'expires_at' => 'present|nullable|bail|date|after:now'],
            ['expires_at.date' => 'That is not a date.', 'expires_at.after' => 'Choose a time that has not passed.'],
        );

        return $this->changing($spreadsheet, function () use ($spreadsheet, $input) {
            $enabled = (bool) $input['enabled'];
            $this->sharing->setLink($spreadsheet, $enabled, $input['expires_at'] === null ? null : Date::parse($input['expires_at']));

            return $enabled ? 'The link is on.' : 'The link is off. Anyone who has it can no longer open this.';
        });
    }

    /** Whose it is: the owner's alone, or a team's. */
    public function place(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        $team = $request->validate(['team_id' => 'present|nullable|integer'])['team_id'];

        return $this->changing($spreadsheet, function () use ($spreadsheet, $request, $team) {
            $this->sharing->setPlace($spreadsheet, $request->user(), $team === null ? null : (int) $team);

            return $team === null ? 'This is private now.' : 'This belongs to the team now.';
        });
    }

    public function template(Request $request, Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        $on = (bool) $request->validate(['on' => 'required|boolean'])['on'];

        return $this->changing($spreadsheet, function () use ($spreadsheet, $on) {
            $this->sharing->setTemplate($spreadsheet, $on);

            return $on ? 'Your team can start from this now.' : 'This is no longer offered as a template.';
        });
    }

    public function newLink(Spreadsheet $spreadsheet): JsonResponse
    {
        $this->authorize('manageSharing', $spreadsheet);

        return $this->changing($spreadsheet, function () use ($spreadsheet) {
            $this->sharing->newLink($spreadsheet);

            return 'This is a new link. The old one no longer works.';
        });
    }

    /**
     * @param  callable(): string  $change  makes the change and says what happened
     */
    private function changing(Spreadsheet $spreadsheet, callable $change): JsonResponse
    {
        $told = '';
        try {
            $busy = false;
            WriteLock::guard(function () use ($change, &$told) {
                $told = $change();
            }, function () use (&$busy) {
                $busy = true;
            });
        } catch (ShareRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 422);
        }

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($spreadsheet->refresh(), $told);
    }

    private function answer(Spreadsheet $spreadsheet, string $told = ''): JsonResponse
    {
        return response()->json([...$this->sharing->state($spreadsheet, (int) Auth::id()), 'told' => $told]);
    }

    private function permissionRule(): string
    {
        return 'required|in:'.implode(',', SheetSharing::PERMISSIONS);
    }

    private function person(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
