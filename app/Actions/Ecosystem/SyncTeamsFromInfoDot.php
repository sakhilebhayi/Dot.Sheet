<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies the companies InfoDot says a person belongs to, on every sign-in.
 *
 * A company here is a team linked to an InfoDot team by uuid. Rules:
 * - Only people InfoDot lists as owner or admin of a company create its team here;
 *   an ordinary member is attached once it exists, so a member can never become
 *   the local owner of someone else's company.
 * - Membership and role follow InfoDot, on every sign-in and for existing members too (owner and
 *   admin become admin, editor stays editor, viewer stays viewer: a read-only role). Any other
 *   or missing InfoDot role gets viewer, the least privilege.
 * - Companies InfoDot no longer lists for the person are detached, so removing
 *   someone in InfoDot removes their access here at their next sign-in.
 * - The company InfoDot says is active becomes the person's current team.
 * - Personal teams are never linked or removed.
 * - R-50: InfoDot marks each company with `entitled`. A company marked anything but exactly true is left alone here:
 *   not created, not renamed, no membership or role change, and it cannot be the person's current company. Their
 *   existing membership and the company's workbooks stay as they are. A response without the mark (an InfoDot from
 *   before R-50) is treated as before: absence is not a refusal.
 */
class SyncTeamsFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public function __invoke(User $user, array $identity): void
    {
        $companies = collect($identity['teams'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['uuid'] ?? null) && ! ($t['personal'] ?? false))
            ->keyBy('uuid');

        $listed = $companies->filter(fn ($t) => self::admits($t));
        $held = $companies->keys()->diff($listed->keys())->map(fn ($uuid) => (string) $uuid)->values()->all();

        DB::transaction(function () use ($user, $listed, $held, $identity) {
            foreach ($listed as $uuid => $info) {
                $this->apply($user, (string) $uuid, $info);
                $this->forgetCachedTeams($user);
            }

            // A company InfoDot still lists but that does not hold this platform keeps its membership: only its use stops.
            $this->revoke($user, [...$listed->keys()->map(fn ($uuid) => (string) $uuid)->all(), ...$held]);
            $this->forgetCachedTeams($user);

            $active = $identity['current_team_uuid'] ?? null;
            $team = $active && $listed->has($active) ? Team::where('infodot_team_uuid', $active)->first() : null;

            if ($team && $user->belongsToTeam($team)) {
                $user->switchTeam($team);

                return;
            }

            $this->leaveHeldCompany($user, $listed->keys()->map(fn ($uuid) => (string) $uuid)->all(), $held);
        });
    }

    /**
     * Whether InfoDot lets this company use the platform: `entitled` exactly true, or no mark at all (before R-50).
     *
     * @param  array<string, mixed>  $team
     */
    public static function admits(array $team): bool
    {
        return ! array_key_exists('entitled', $team) || $team['entitled'] === true;
    }

    /**
     * True when InfoDot listed workspaces for the person and not one of them may use this platform.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function nothingEntitled(array $identity): bool
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t));

        return $teams->isNotEmpty() && ! $teams->contains(fn ($t) => self::admits($t));
    }

    /**
     * The reason InfoDot gave for the company the person was in (or the first one it listed), for the wording.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function refusalReason(array $identity): ?string
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t));
        $team = $teams->firstWhere('uuid', $identity['current_team_uuid'] ?? null) ?? $teams->first(fn ($t) => ! ($t['personal'] ?? false)) ?? $teams->first();

        return is_array($team) && is_string($team['access'] ?? null) ? $team['access'] : null;
    }

    /**
     * A person whose current company here is one InfoDot just said does not hold the platform is moved to one that
     * does, or to their personal workspace.
     *
     * @param  list<string>  $admitted
     * @param  list<string>  $held
     */
    private function leaveHeldCompany(User $user, array $admitted, array $held): void
    {
        $current = $user->current_team_id ? Team::find($user->current_team_id) : null;

        if (! $current || ! in_array($current->infodot_team_uuid, $held, true)) {
            return;
        }

        $next = $user->allTeams()->first(fn ($team) => in_array($team->infodot_team_uuid, $admitted, true))
            ?? $user->ownedTeams()->where('personal_team', true)->first();

        $next ? $user->switchTeam($next) : $user->forceFill(['current_team_id' => null])->save();
        $this->forgetCachedTeams($user);
    }

    /** Membership checks read relations cached on the model; reload after changing them. */
    private function forgetCachedTeams(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function apply(User $user, string $uuid, array $info): void
    {
        $role = (string) ($info['role'] ?? '');
        $team = Team::where('infodot_team_uuid', $uuid)->first();

        if (! $team) {
            if (! in_array($role, ['owner', 'admin'], true)) {
                return;
            }

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => (string) ($info['name'] ?? 'Company'),
                'personal_team' => false,
                'infodot_team_uuid' => $uuid,
            ]);
        } elseif (filled($info['name'] ?? null) && $team->name !== $info['name']) {
            $team->forceFill(['name' => $info['name']])->save();
        }

        if ($team->user_id === $user->id) {
            return; // the local owner needs no membership row
        }

        $localRole = self::localRole($role);

        if ($user->belongsToTeam($team)) {
            $team->users()->updateExistingPivot($user->id, ['role' => $localRole]);
        } else {
            $team->users()->attach($user->id, ['role' => $localRole]);
        }
    }

    /**
     * @param  list<string>  $keep
     */
    private function revoke(User $user, array $keep): void
    {
        $stale = $user->allTeams()->filter(fn ($team) => filled($team->infodot_team_uuid) && ! in_array($team->infodot_team_uuid, $keep, true));

        foreach ($stale as $team) {
            if ($team->user_id === $user->id) {
                // Cannot detach a team's owner; surface it instead of leaving it silent.
                Log::warning('InfoDot no longer lists a company this person owns locally.', ['team_id' => $team->id, 'user_id' => $user->id]);

                continue;
            }

            $team->users()->detach($user->id);

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }
    }

    /**
     * The role this app stores for an InfoDot role: owner and admin become admin, editor stays editor,
     * viewer and anything unknown or empty become viewer. Never falls back to a role that can write.
     */
    public static function localRole(string $infodotRole): string
    {
        return match (strtolower(trim($infodotRole))) {
            'owner', 'admin' => 'admin',
            'editor' => 'editor',
            default => 'viewer',
        };
    }
}
