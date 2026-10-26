<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('app:create-user {email} {--admin : Grant the user administrator rights} {--name= : Display name (defaults to the part of the email before the @)}')]
#[Description('Create a user, prompting for a password, and print a personal API access token')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) ($this->option('name') ?: strstr($email, '@', true) ?: $email);

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->is_admin = (bool) $this->option('admin');
        $user->save();

        $token = $user->createToken('cli')->plainTextToken;

        $this->components->info(sprintf(
            'Created %s [%s]. Store this token now; it will not be shown again:',
            $user->is_admin ? 'admin' : 'user',
            $user->email,
        ));
        $this->line($token);

        return self::SUCCESS;
    }
}
