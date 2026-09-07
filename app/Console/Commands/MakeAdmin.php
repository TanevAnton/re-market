<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or revokes moderator rights.
 *
 * Deliberately not a web UI. The first admin has to come from somewhere, and
 * a screen that can create admins is the single most valuable page on the site
 * to compromise. Shell access to the server is a fair price for the power.
 */
class MakeAdmin extends Command
{
    protected $signature = 'remarket:make-admin
                            {user : Email or username}
                            {--revoke : Take the rights away instead}';

    protected $description = 'Grant or revoke moderator rights';

    public function handle(): int
    {
        $needle = (string) $this->argument('user');

        $user = User::where('email', $needle)
            ->orWhereRaw('lower(username) = lower(?)', [$needle])
            ->first();

        if (! $user) {
            $this->error("No user matches '{$needle}'.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ($user->is_admin === $grant) {
            $this->line($grant
                ? "{$user->username} is already a moderator."
                : "{$user->username} is not a moderator.");

            return self::SUCCESS;
        }

        // is_admin is not mass-assignable, and that is the point: it must never
        // be settable by anything that takes user input.
        $user->forceFill(['is_admin' => $grant])->save();

        $this->info($grant
            ? "{$user->username} ({$user->email}) is now a moderator."
            : "{$user->username} ({$user->email}) is no longer a moderator.");

        return self::SUCCESS;
    }
}
