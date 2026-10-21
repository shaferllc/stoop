<?php

namespace App\Concerns;

use App\Models\User;
use App\Support\Profanity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'username' => [
                'required', 'string', 'min:2', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', $this->clean(),
                $userId === null ? Rule::unique(User::class) : Rule::unique(User::class)->ignore($userId),
            ],
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255', $this->clean()];
    }

    /**
     * Names and usernames are public, so they follow the no-swearing rule too.
     */
    protected function clean(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (Profanity::contains(str_replace('_', ' ', (string) $value))) {
                $fail('Pick a name without swearing.');
            }
        };
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
