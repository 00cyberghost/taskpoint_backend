<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteUserToAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:promote-user-to-admin {email : The email address of the user to promote}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Promote an existing user account to admin access';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user was found for that email address.');

            return self::FAILURE;
        }

        $user->update([
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $this->info("{$user->email} has been promoted to admin.");

        return self::SUCCESS;
    }
}
