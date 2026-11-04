<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Services\SheetComments;
use App\Services\SheetStructureService;
use App\Services\WriteLock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Comments in the new editor. Every answer is the sheet's threads and marks as they now are. Comments are
 * not cells: they need the right to comment, not to edit, and they leave the structure version alone.
 */
class GridCommentController extends Controller
{
    use AuthorizesRequests;

    private const TEXT = 'required|string|max:1000';

    public function __construct(private SheetComments $comments) {}

    public function index(Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('view', $spreadsheet, $sheetTab);

        return $this->answer($sheetTab);
    }

    public function store(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab): JsonResponse
    {
        $this->authorizeTab('comment', $spreadsheet, $sheetTab);

        $input = $request->validate([
            'row' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_ROWS - 1),
            'col' => 'required|integer|min:0|max:'.(SheetStructureService::GRID_COLS - 1),
            'text' => self::TEXT,
        ]);

        return $this->saying($spreadsheet, $sheetTab, fn () => $this->comments->add($spreadsheet->id, $sheetTab->id, (int) Auth::id(), (int) $input['row'], (int) $input['col'], $input['text']));
    }

    public function reply(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, Comment $comment): JsonResponse
    {
        $this->authorizeTab('comment', $spreadsheet, $sheetTab);
        $this->ownThread($sheetTab, $comment);

        $input = $request->validate(['text' => self::TEXT]);

        return $this->saying($spreadsheet, $sheetTab, fn () => $this->comments->reply($comment, (int) Auth::id(), $input['text']));
    }

    public function resolve(Request $request, Spreadsheet $spreadsheet, SheetTab $sheetTab, Comment $comment): JsonResponse
    {
        $this->authorizeTab('comment', $spreadsheet, $sheetTab);
        $this->ownThread($sheetTab, $comment);

        $input = $request->validate(['resolved' => 'required|boolean']);

        return $this->writing($sheetTab, fn () => $comment->update(['resolved' => (bool) $input['resolved']]));
    }

    /** People delete only what they wrote. A thread takes its replies with it. */
    public function destroy(Spreadsheet $spreadsheet, SheetTab $sheetTab, Comment $comment): JsonResponse
    {
        $this->authorizeTab('comment', $spreadsheet, $sheetTab);
        abort_unless($comment->sheet_tab_id === $sheetTab->id, 404);
        abort_unless($comment->user_id === Auth::id(), 403);

        return $this->writing($sheetTab, fn () => $comment->delete());
    }

    private function saying(Spreadsheet $spreadsheet, SheetTab $sheetTab, callable $write): JsonResponse
    {
        $comment = null;
        $answer = $this->writing($sheetTab, function () use ($write, &$comment) {
            $comment = $write();
        });
        // After the write: a slow mail server must not hold the database's one write lock.
        if ($comment !== null) {
            $this->comments->notifyMentions($spreadsheet, $comment, Auth::user());
        }

        return $answer;
    }

    private function writing(SheetTab $sheetTab, callable $write): JsonResponse
    {
        $busy = false;
        WriteLock::guard($write, function () use (&$busy) {
            $busy = true;
        });

        return $busy ? response()->json(['message' => WriteLock::BUSY_MESSAGE], 503) : $this->answer($sheetTab);
    }

    private function answer(SheetTab $sheetTab): JsonResponse
    {
        return response()->json([...$this->comments->threads($sheetTab->id, (int) Auth::id()), 'marks' => $this->comments->marks($sheetTab->id)]);
    }

    private function ownThread(SheetTab $sheetTab, Comment $comment): void
    {
        abort_unless($comment->sheet_tab_id === $sheetTab->id && $comment->parent_id === null, 404);
    }

    private function authorizeTab(string $ability, Spreadsheet $spreadsheet, SheetTab $sheetTab): void
    {
        $this->authorize($ability, $spreadsheet);
        abort_unless($sheetTab->spreadsheet_id === $spreadsheet->id, 404);
    }
}
