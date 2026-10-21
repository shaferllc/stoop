<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'password', 'is_native', 'dc_since', 'stops', 'half_smokes_on', 'translation_language', 'newsletter'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'risk_score', 'risk_reasons', 'signup_ip_hash', 'roles'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Defaults for new accounts, matching the database, so they hold before the first reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'replies_from' => 'everyone',
        'dms_on' => true,
        'message_requests' => false,
        'read_receipts' => true,
        'half_smokes_on' => false,
        'newsletter' => false,
        'is_bot' => false,
        'is_staff' => false,
        'is_native' => false,
        'moderation_emails' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_native' => 'boolean',
            'is_staff' => 'boolean',
            'stops' => 'array',
            'subscribed_until' => 'datetime',
            'half_smokes_on' => 'boolean',
            'newsletter' => 'boolean',
            'is_bot' => 'boolean',
            'dms_on' => 'boolean',
            'message_requests' => 'boolean',
            'read_receipts' => 'boolean',
            'live_until' => 'datetime',
            'accepted_rules_at' => 'datetime',
            'roles' => 'array',
            'risk_reasons' => 'array',
            'moderation_emails' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Deleting an account erases everything about the person. Dispatches other people
     * replied to stay as empty, authorless tombstones so those replies survive.
     * Penalties are kept by email hash (see hashSubject).
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            Dispatch::withTrashed()->where('user_id', $user->id)->with('images')->each(function (Dispatch $dispatch) use ($user) {
                $dispatch->images->each(fn ($image) => Storage::disk('public')->delete($image->path));
                $dispatch->images()->delete();

                $othersReplied = Dispatch::withTrashed()->where('parent_id', $dispatch->id)->where('user_id', '!=', $user->id)->exists();

                if ($othersReplied) {
                    $dispatch->forceFill(['body' => '', 'safety' => null, 'happening_at' => null, 'deleted_at' => $dispatch->deleted_at ?? now()])->saveQuietly();
                } else {
                    $dispatch->forceDelete();
                }
            });

            Message::where('user_id', $user->id)->with('images')->each(function (Message $message) {
                $message->images->each(fn ($image) => Storage::disk('local')->delete($image->path));
                $message->delete();
            });

            Tip::where('user_id', $user->id)->each(fn (Tip $tip) => Storage::disk('local')->delete($tip->photos ?? []));

            $user->notifications()->delete();
            $user->tokens()->delete();
            DB::table('notifications')->where('data->actor_id', $user->id)->delete();
        });
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(Dispatch::class);
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followee_id')->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'followee_id', 'follower_id')->withTimestamps();
    }

    public function silenced(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'silences', 'user_id', 'target_id')->withPivot('type')->withTimestamps();
    }

    public function pinned(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class, 'pinned_dispatch_id');
    }

    public function marked(): BelongsToMany
    {
        return $this->belongsToMany(Dispatch::class, 'marks')->withPivot('kind')->withTimestamps();
    }

    /**
     * People whose posts this user should never see: muted or blocked by them, or blocking them.
     *
     * @return array<int, int>
     */
    public function hiddenUserIds(): array
    {
        return DB::table('silences')->where('user_id', $this->id)->pluck('target_id')
            ->merge(DB::table('silences')->where('target_id', $this->id)->where('type', 'block')->pluck('user_id'))
            ->unique()->values()->all();
    }

    /**
     * Penalties follow the email address, so they outlive a deleted account.
     */
    public static function hashSubject(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }

    public function subjectHash(): string
    {
        return self::hashSubject($this->email);
    }

    /**
     * @return Builder<Penalty>
     */
    public function penalties(): Builder
    {
        return Penalty::where('subject_hash', $this->subjectHash());
    }

    public function activeBan(): ?Penalty
    {
        return $this->penalties()->active()->where('type', 'ban')->latest()->first();
    }

    public function activeTimeout(): ?Penalty
    {
        return $this->penalties()->active()->where('type', 'timeout')->orderByDesc('ends_at')->first();
    }

    /**
     * Why this person can't post right now, or null when they can.
     */
    public function postingBlock(): ?string
    {
        if ($this->activeBan()) {
            return 'Your account is banned from posting.';
        }

        if ($timeout = $this->activeTimeout()) {
            return 'You’re in a timeout until '.$timeout->ends_at->format('M j, g:ia').': '.$timeout->reason;
        }

        if (! $this->hasVerifiedEmail()) {
            return 'Verify your email address to post.';
        }

        if (config('stoop.require_subscription') && ! $this->is_staff && ! $this->subscribed_until?->isFuture()) {
            return 'Posting is for subscribers. Reading is free.';
        }

        return null;
    }

    /**
     * Stoop's own accounts (@stoop, @dc, and the magazine's article feed). Their posts skip
     * the automatic check; everyone else's, staff included, goes through it.
     */
    public function isHouseAccount(): bool
    {
        return $this->is_bot || $this->username === config('stoop.magazine_account');
    }

    /**
     * Staff roles: moderator, editor, sales and admin (admin can do everything). With no
     * role given, any staff role will do. Staff from before roles existed count as admins.
     */
    public function hasRole(?string $role = null): bool
    {
        $roles = $this->staffRoles();

        return $roles && ($role === null || in_array('admin', $roles, true) || in_array($role, $roles, true));
    }

    /**
     * @return array<int, string>
     */
    public function staffRoles(): array
    {
        return $this->is_staff ? ($this->roles ?: ['admin']) : [];
    }

    public function isLive(): bool
    {
        return $this->live_url && $this->live_until?->isFuture();
    }

    /**
     * Half-smokes left to give today. The allowance resets at midnight DC time.
     */
    public function halfSmokesLeft(): int
    {
        $given = HalfSmoke::where('giver_id', $this->id)
            ->where('created_at', '>=', now(config('stoop.timezone'))->startOfDay()->utc())
            ->count();

        return max(0, config('stoop.half_smokes_per_day') - $given);
    }

    /**
     * The label shown next to a name: STAFF, NATIVE, TRANSPLANT ’19, or SUBSCRIBER.
     */
    public function badge(): string
    {
        return match (true) {
            $this->is_bot => 'HOUSE ACCOUNT',
            $this->is_staff => 'STAFF',
            $this->is_native => 'NATIVE',
            $this->dc_since !== null => 'TRANSPLANT ’'.substr((string) $this->dc_since, -2),
            default => 'SUBSCRIBER',
        };
    }

    /**
     * A stable avatar color picked from the line colors.
     */
    public function color(): string
    {
        $colors = array_column(config('stoop.lines'), 'color');

        return $this->is_staff ? '#16181a' : $colors[$this->id % count($colors)];
    }
}
