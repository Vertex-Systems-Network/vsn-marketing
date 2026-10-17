<?php

namespace App\Modules\Identity\Presentation\Console;

use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Super Admin is never granted over HTTP; only an operator with server access can run this (SRS §2.2). */
final class GrantPlatformAdmin extends Command
{
    protected $signature = 'vsn:platform-admin {email} {--revoke}';

    protected $description = 'Grant or revoke the platform Super Admin role';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if (! $user instanceof User) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            DB::table('platform_admins')->where('user_id', $user->getKey())->delete();
            $this->info('Super Admin revoked.');

            return self::SUCCESS;
        }

        DB::table('platform_admins')->updateOrInsert(
            ['user_id' => $user->getKey()],
            ['granted_via' => 'artisan', 'created_at' => now(), 'updated_at' => now()],
        );
        $this->info('Super Admin granted.');

        return self::SUCCESS;
    }
}
