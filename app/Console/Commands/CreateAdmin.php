<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * php artisan hr:create-admin you@company.com --name="Your Name"
 * Creates (or resets) an admin login. Use it once after deployment; then add other users in Settings > Users.
 */
class CreateAdmin extends Command
{
    protected $signature = 'hr:create-admin {email} {--name=Administrator} {--password= : Leave empty to be asked}';

    protected $description = 'Create an admin login (or reset an existing user to admin with a new password)';

    public function handle(): int
    {
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');
        $v = Validator::make(['email' => $this->argument('email'), 'password' => $password], [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => strtolower($this->argument('email'))], [
            'name' => $this->option('name'),
            'password' => $password,
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->info("Admin login ready: {$user->email}");

        return self::SUCCESS;
    }
}
