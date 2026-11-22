<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:make-admin {email} {--revoke : Remove admin access instead}')]
#[Description('Grant (or revoke) access to the admin report for an existing user')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email. They need to sign up first.');

            return self::FAILURE;
        }

        $user->is_admin = ! $this->option('revoke');
        $user->save();

        $this->info($user->is_admin ? "{$user->email} is now an admin." : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
