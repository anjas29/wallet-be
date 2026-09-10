<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
                            {email? : Email address to sign in with}
                            {--name= : Display name}
                            {--password= : Password (prompted for when omitted)}';

    protected $description = 'Create a super_admin user, or promote an existing user, for the admin panel.';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email');
        $email = mb_strtolower(trim((string) $email));

        // Promote in place: re-running with an existing email should grant access, not fail.
        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $this->error("{$email} belongs to a deleted account. Restore it first.");

                return self::FAILURE;
            }

            if ($existing->isAdmin()) {
                $this->info("{$email} is already an admin.");

                return self::SUCCESS;
            }

            $existing->role = 'super_admin';
            $existing->save();

            $this->info("Promoted {$email} to super_admin.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: $this->ask('Name', 'Admin');
        $password = $this->option('password') ?: $this->secret('Password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->fill(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        // `role` is intentionally not mass-assignable (the registration endpoint fills the
        // same model), so it is set directly here.
        $user->role = 'super_admin';
        $user->save();

        $this->info("Created admin {$email} ({$user->id}).");

        return self::SUCCESS;
    }
}
