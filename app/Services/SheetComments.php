<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\Team;
use App\Models\User;
use App\Notifications\MentionedInComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Comments on a sheet, in both editors. A comment is held by its cell's place, so everything that moves
 * cells moves comments with it: a row or column going in or out, a sort, a cut. A comment whose cell was
 * deleted keeps its words and is detached, with no place on the sheet, until a copy from before puts it back.
 */
class SheetComments
{
    /** Threads sent to the panel at once. Marks on the sheet are not limited. */
    public const MAX_THREADS = 500;

    /**
     * Where the open threads are, for the marks on the sheet.
     *
     * @return list<array{0: int, 1: int, 2: int}> row, column, threads
     */
    public function marks(int $tabId): array
    {
        return DB::table('comments')
            ->where('sheet_tab_id', $tabId)->whereNull('parent_id')->where('resolved', false)->where('detached', false)
            ->groupBy('row_index', 'col_index')->orderBy('row_index')->orderBy('col_index')
            ->selectRaw('row_index, col_index, count(*) as threads')->get()
            ->map(fn ($mark) => [(int) $mark->row_index, (int) $mark->col_index, (int) $mark->threads])->all();
    }

    /**
     * A sheet's threads for the panel: in reading order, those that are open first.
     *
     * @return array{threads: list<array<string, mixed>>, more: int}
     */
    public function threads(int $tabId, int $viewerId): array
    {
        $threads = $this->threadsOn($tabId)->with(['user:id,name', 'replies' => fn ($replies) => $replies->orderBy('created_at')->orderBy('id'), 'replies.user:id,name'])
            ->orderBy('resolved')->orderBy('detached')->orderBy('row_index')->orderBy('col_index')->orderBy('created_at')->orderBy('id')
            ->limit(self::MAX_THREADS)->get();

        return [
            'threads' => $threads->map(fn (Comment $thread) => [
                ...$this->words($thread, $viewerId),
                'row' => $thread->row_index,
                'col' => $thread->col_index,
                'resolved' => $thread->resolved,
                'detached' => (bool) $thread->detached,
                'replies' => $thread->replies->map(fn (Comment $reply) => $this->words($reply, $viewerId))->all(),
            ])->all(),
            'more' => max(0, $this->threadsOn($tabId)->count() - self::MAX_THREADS),
        ];
    }

    public function add(int $spreadsheetId, int $tabId, int $userId, int $row, int $col, string $text): Comment
    {
        return Comment::create([
            'spreadsheet_id' => $spreadsheetId,
            'sheet_tab_id' => $tabId,
            'user_id' => $userId,
            'parent_id' => null,
            'row_index' => $row,
            'col_index' => $col,
            'content' => $text,
            'resolved' => false,
        ]);
    }

    public function reply(Comment $thread, int $userId, string $text): Comment
    {
        return Comment::create([
            'spreadsheet_id' => $thread->spreadsheet_id,
            'sheet_tab_id' => $thread->sheet_tab_id,
            'user_id' => $userId,
            'parent_id' => $thread->id,
            'row_index' => $thread->row_index,
            'col_index' => $thread->col_index,
            'content' => $text,
            'resolved' => false,
            'detached' => (bool) $thread->detached,
        ]);
    }

    /**
     * Tell the people a comment names with @. The comment stands whether or not the message gets out.
     */
    public function notifyMentions(Spreadsheet $spreadsheet, Comment $comment, ?User $actor): void
    {
        preg_match_all('/@([A-Za-z0-9_.-]{2,50})/', $comment->content, $matches);
        $tokens = array_unique($matches[1]);
        if (empty($tokens)) {
            return;
        }

        $recipientIds = collect([$spreadsheet->owner_id]);
        $recipientIds = $recipientIds->merge($spreadsheet->sharedUsers()->pluck('users.id'));
        if ($spreadsheet->team instanceof Team) {
            $recipientIds = $recipientIds->merge($spreadsheet->team->users()->pluck('users.id'));
        }

        $recipientIds = $recipientIds->unique()->reject(fn ($id) => (int) $id === (int) $actor?->id);
        if ($recipientIds->isEmpty()) {
            return;
        }

        foreach ($tokens as $token) {
            $users = User::whereIn('id', $recipientIds)
                ->where(function ($q) use ($token) {
                    $q->where('name', 'like', $token.'%')
                        ->orWhere('email', 'like', $token.'%');
                })
                ->get();

            foreach ($users as $user) {
                try {
                    $user->notify(new MentionedInComment(
                        actorName: $actor->name ?? 'Someone',
                        spreadsheetId: $spreadsheet->id,
                        spreadsheetName: $spreadsheet->name,
                        row: $comment->row_index + 1,
                        col: $comment->col_index + 1,
                        excerpt: mb_strimwidth($comment->content, 0, 140, '...'),
                        sheetTabId: $comment->sheet_tab_id,
                    ));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * A row or column went in (+1) or out (-1) at $index. Comments on a deleted line lose their cell, and
     * so does one an insert pushes off the end of the sheet. Call inside the change's transaction.
     */
    public function shift(int $tabId, string $axis, int $index, int $delta): void
    {
        $placed = fn () => DB::table('comments')->where('sheet_tab_id', $tabId)->where('detached', false);

        if ($delta > 0) {
            $placed()->where($axis, '>=', $index)->increment($axis);
            $limit = $axis === 'row_index' ? SheetStructureService::GRID_ROWS : SheetStructureService::GRID_COLS;
            $placed()->where($axis, '>=', $limit)->update(['detached' => true]);

            return;
        }

        $placed()->where($axis, $index)->update(['detached' => true]);
        $placed()->where($axis, '>', $index)->decrement($axis);
    }

    /**
     * Rows that changed places take their comments, inside the columns that moved.
     *
     * @param  array<int, int>  $moved  row it was on => row it is on now
     * @return list<array{id: int, tab: int, row: int, col: int}> how to put them back
     */
    public function moveRows(int $tabId, array $moved, int $c1, int $c2): array
    {
        $back = [];
        foreach (array_chunk(array_keys($moved), 200) as $rows) {
            $this->placed($tabId)->whereIn('row_index', $rows)->whereBetween('col_index', [$c1, $c2])->get()
                ->each(function (object $comment) use ($tabId, $moved, &$back) {
                    $back[] = $this->was($comment, $tabId);
                    $this->put($comment->id, $tabId, $moved[$comment->row_index], (int) $comment->col_index);
                });
        }

        return $back;
    }

    /**
     * Cut cells take their comments, to another sheet of the workbook if that is where they landed.
     *
     * @param  array{r1: int, r2: int, c1: int, c2: int}  $area
     * @return list<array{id: int, tab: int, row: int, col: int}> how to put them back
     */
    public function moveArea(int $fromTab, array $area, int $toTab, int $down, int $across): array
    {
        $back = [];
        $this->placed($fromTab)->whereBetween('row_index', [$area['r1'], $area['r2']])->whereBetween('col_index', [$area['c1'], $area['c2']])->get()
            ->each(function (object $comment) use ($fromTab, $toTab, $down, $across, &$back) {
                $back[] = $this->was($comment, $fromTab);
                $this->put($comment->id, $toTab, (int) $comment->row_index + $down, (int) $comment->col_index + $across);
            });

        return $back;
    }

    /**
     * Put comments back where undo says they were, and answer how to redo it. Only sheets of this
     * workbook, and a comment that has lost its cell stays as it is.
     *
     * @param  list<array{id: int, tab: int, row: int, col: int}>  $places
     * @return list<array{id: int, tab: int, row: int, col: int}>
     */
    public function place(int $spreadsheetId, array $places): array
    {
        $tabs = SheetTab::where('spreadsheet_id', $spreadsheetId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $at = DB::table('comments')->where('spreadsheet_id', $spreadsheetId)->where('detached', false)
            ->whereIn('id', array_column($places, 'id'))->get()->keyBy('id');

        $back = [];
        foreach ($places as $place) {
            $comment = $at[$place['id']] ?? null;
            if ($comment === null || ! in_array((int) $place['tab'], $tabs, true)) {
                continue;
            }
            $back[] = $this->was($comment, (int) $comment->sheet_tab_id);
            $this->put($comment->id, (int) $place['tab'], (int) $place['row'], (int) $place['col']);
        }

        return $back;
    }

    /**
     * Where a sheet's comments are, for a copy of the sheet.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: bool}> id, row, column, detached
     */
    public function placesOf(int $tabId): array
    {
        return DB::table('comments')->where('sheet_tab_id', $tabId)->orderBy('id')->get(['id', 'row_index', 'col_index', 'detached'])
            ->map(fn ($comment) => [(int) $comment->id, (int) $comment->row_index, (int) $comment->col_index, (bool) $comment->detached])->all();
    }

    /**
     * Put a copy's comments back. Comments made since the copy stay where they are, and comments deleted
     * since then stay deleted.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: bool}>  $places
     */
    public function restorePlaces(int $tabId, array $places): void
    {
        $now = collect($this->placesOf($tabId))->keyBy(fn (array $place) => $place[0]);
        foreach ($places as $place) {
            if (($now[$place[0]] ?? null) !== null && $now[$place[0]] !== $place) {
                DB::table('comments')->where('id', $place[0])->update(['row_index' => $place[1], 'col_index' => $place[2], 'detached' => $place[3]]);
            }
        }
    }

    /**
     * @return Builder<Comment>
     */
    private function threadsOn(int $tabId): Builder
    {
        return Comment::where('sheet_tab_id', $tabId)->whereNull('parent_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function words(Comment $comment, int $viewerId): array
    {
        return [
            'id' => $comment->id,
            'who' => $comment->user->name ?? 'Unknown',
            'mine' => $comment->user_id === $viewerId,
            'at' => $comment->created_at?->toIso8601String(),
            'text' => $comment->content,
        ];
    }

    private function placed(int $tabId)
    {
        return DB::table('comments')->where('sheet_tab_id', $tabId)->where('detached', false);
    }

    private function put(int $id, int $tabId, int $row, int $col): void
    {
        DB::table('comments')->where('id', $id)->update(['sheet_tab_id' => $tabId, 'row_index' => $row, 'col_index' => $col]);
    }

    /**
     * @return array{id: int, tab: int, row: int, col: int}
     */
    private function was(object $comment, int $tabId): array
    {
        return ['id' => (int) $comment->id, 'tab' => $tabId, 'row' => (int) $comment->row_index, 'col' => (int) $comment->col_index];
    }
}
