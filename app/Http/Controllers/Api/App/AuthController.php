<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Messaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Signing in from the app: email and password, plus a 2FA code (or a recovery code) when
 * the account has two-factor turned on. Issues a Sanctum token that can only use /api/app.
 */
class AuthController extends Controller
{
    public function login(Request $request, TwoFactorAuthenticationProvider $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if (! empty($data['recovery_code'])) {
                $code = collect($user->recoveryCodes())->first(fn ($c) => hash_equals($c, $data['recovery_code']));
                if (! $code) {
                    throw ValidationException::withMessages(['recovery_code' => __('The provided two factor recovery code was invalid.')]);
                }
                $user->replaceRecoveryCode($code);
            } elseif (empty($data['code'])) {
                return response()->json([
                    'message' => 'Enter the code from your authenticator app.',
                    'two_factor_required' => true,
                    'errors' => ['code' => ['Enter the code from your authenticator app.']],
                ], 422);
            } elseif (! $twoFactor->verify(decrypt($user->two_factor_secret), $data['code'])) {
                throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
            }
        }

        $token = $user->createToken($data['device_name'] ?? 'Stoop for iOS', ['app'])->plainTextToken;

        return response()->json(['token' => $token, 'user' => self::account($user)], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => self::account($request->user())]);
    }

    public function acceptRules(Request $request): JsonResponse
    {
        $request->user()->forceFill(['accepted_rules_at' => now()])->save();

        return response()->json(['data' => self::account($request->user())]);
    }

    /**
     * Your own account. Still no email: the app never needs it after signing in.
     *
     * @return array<string, mixed>
     */
    public static function account(User $user): array
    {
        $ban = $user->activeBan();

        return [
            ...Present::user($user),
            'stops' => $user->stops ?: [],
            'is_staff' => (bool) $user->is_staff,
            'accepted_rules' => (bool) $user->accepted_rules_at,
            'half_smokes_on' => (bool) $user->half_smokes_on,
            'half_smokes_left' => $user->half_smokes_on ? $user->halfSmokesLeft() : 0,
            'posting_block' => $user->postingBlock(),
            'banned' => $ban ? ['reason' => $ban->reason] : null,
            'unread_notifications' => $user->unreadNotifications()->count(),
            'unread_messages' => Messaging::unreadCount($user),
        ];
    }
}
