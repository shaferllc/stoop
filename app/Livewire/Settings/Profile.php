<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use App\Support\Translator;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public bool $is_native = false;

    public bool $half_smokes_on = false;

    public string $translation_language = 'en';

    public ?int $dc_since = null;

    /** @var array<int, string> */
    public array $stops = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->username = (string) Auth::user()->username;
        $this->email = Auth::user()->email;
        $this->is_native = (bool) Auth::user()->is_native;
        $this->half_smokes_on = (bool) Auth::user()->half_smokes_on;
        $this->translation_language = Auth::user()->translation_language ?? 'en';
        $this->dc_since = Auth::user()->dc_since;
        $this->stops = Auth::user()->stops ?? [];
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'is_native' => ['boolean'],
            'half_smokes_on' => ['boolean'],
            'translation_language' => [Rule::in(array_keys(Translator::LANGUAGES))],
            'dc_since' => ['nullable', 'integer', 'min:1940', 'max:'.now()->year],
            'stops' => ['array', 'max:3'],
            'stops.*' => [Rule::in(array_keys(config('stoop.stations')))],
        ]);

        if ($validated['is_native']) {
            $validated['dc_since'] = null;
        }

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail();
    }
}
