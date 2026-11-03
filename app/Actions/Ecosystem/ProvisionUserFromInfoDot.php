<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns an InfoDot identity (already proven by the redeem endpoint) into a local user.
 *
 * Matches by verified email. An existing account whose email was never verified
 * here (pre-registered, or changed to this address without proof) is taken over
 * safely: its password is replaced and its memberships of other teams removed, so
 * whoever claimed the address cannot keep using it or see this person's work. A person InfoDot vouches for but who has no
 * account here gets one, with a random password and a personal team.
 */
class ProvisionUserFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity
     */
    public function __invoke(array $identity): ?User
    {
        $email = Str::lower(trim((string) ($identity['user']['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($identity['user']['email_verified'] ?? false) !== true) {
            return null;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user) {
            if ($user->email_verified_at === null) {
                DB::transaction(function () use ($user) {
                    $user->forceFill(['password' => Hash::make(Str::random(48)), 'email_verified_at' => now()])->save();

                    // Whoever held the address before may have joined teams so that this person's work
                    // lands where they can read it. The person InfoDot vouches for starts outside them.
                    $user->teams()->detach();
                    $personal = $user->ownedTeams()->where('personal_team', true)->first();
                    $personal ? $user->switchTeam($personal) : $user->forceFill(['current_team_id' => null])->save();
                });
                Log::notice('Unverified local account adopted by InfoDot sign-in; password replaced and team memberships removed.', ['user_id' => $user->id]);
            }

            return $user;
        }

        if (! config('ecosystem.infodot.provision', true)) {
            return null;
        }

        return DB::transaction(function () use ($identity, $email) {
            $name = trim((string) ($identity['user']['name'] ?? '')) ?: Str::before($email, '@');

            $user = new User(['name' => Str::limit($name, 255, ''), 'email' => $email, 'password' => Hash::make(Str::random(48))]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]);
            $user->ownedTeams()->save($team);
            $user->switchTeam($team);

            return $user;
        });
    }
}
