<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Bots;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // For now every new account is verified on sign-up (STOOP_AUTO_VERIFY=false turns this off).
        if (config('stoop.auto_verify')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Everyone's first friend: new accounts follow @stoop, and @stoop follows them back.
        $stoop = Bots::account('stoop');
        $user->following()->attach($stoop->id);
        $stoop->following()->attach($user->id);

        return $user;
    }
}
