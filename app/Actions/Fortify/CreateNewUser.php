<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Bots;
use App\Support\Magazine;
use App\Support\SignupRisk;
use App\Support\Turnstile;
use Illuminate\Support\Facades\DB;
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
        $turnstile = null;
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'cf-turnstile-response' => [config('services.turnstile.secret') ? 'required' : 'nullable', 'string', function ($attribute, $value, $fail) use (&$turnstile) {
                // Tokens work once, so keep the result for the risk score.
                if (config('services.turnstile.secret') && ! ($turnstile = Turnstile::passes($value))) {
                    $fail('We couldn’t confirm you’re a person. Try again.');
                }
            }],
        ], ['cf-turnstile-response.required' => 'Complete the check that you’re a person.'])->validate();

        $user = User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // The magazine may already have told us this email is a subscriber.
        $subscription = DB::table('subscribers')->where('email_hash', User::hashSubject($user->email))->value('subscribed_until');
        if ($subscription) {
            $user->forceFill(['subscribed_until' => $subscription])->save();
        }

        // For now every new account is verified on sign-up (STOOP_AUTO_VERIFY=false turns this off).
        if (config('stoop.auto_verify')) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        // Staff see how risky the sign-up looked; risky accounts' first posts wait for a moderator.
        SignupRisk::assess($user, request()->ip(), $turnstile);

        // Everyone's first friend: new accounts follow @stoop, and @stoop follows them back.
        $stoop = Bots::account('stoop');
        $user->following()->attach($stoop->id);
        $stoop->following()->attach($user->id);

        // New accounts also follow the magazine, so its articles show up in Following.
        if (config('stoop.magazine_feed')) {
            $user->following()->attach(Magazine::account()->id);
        }

        return $user;
    }
}
