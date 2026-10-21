<?php

namespace App\Support;

use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lost & found posts for pets and things. Contact goes through replies and messages only,
 * so posts can't carry a phone number or email address.
 */
class LostAndFound
{
    public const KINDS = ['pet' => 'Pet', 'item' => 'Item'];

    public const STATUSES = ['lost' => 'Lost', 'found' => 'Found'];

    public static function hasContactInfo(string $text): bool
    {
        return (bool) preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|(\+?1[\s.-]?)?\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/i', $text);
    }

    /**
     * The poster closes their own post once the pet or item is home.
     */
    public static function reunite(User $user, Dispatch $dispatch): void
    {
        abort_unless($dispatch->type === 'lost' && $dispatch->user_id === $user->id, 403);

        if (! ($dispatch->details['reunited_at'] ?? null)) {
            $dispatch->forceFill(['details' => [...($dispatch->details ?? []), 'reunited_at' => now()->toIso8601String()]])->save();
        }
    }

    /**
     * The board: open posts, or reunited ones, narrowed by stop, line and pet or item.
     *
     * @return Builder<Dispatch>
     */
    public static function board(?string $stop, ?string $line, ?string $kind, bool $reunited, ?User $viewer): Builder
    {
        return Dispatch::topLevel()->visibleTo($viewer)->where('type', 'lost')
            ->when($stop, fn ($q) => $q->where('stop', $stop))
            ->when($line, fn ($q) => $q->where('line', $line))
            ->when($kind, fn ($q) => $q->where('details->lost_kind', $kind))
            ->when($reunited, fn ($q) => $q->whereNotNull('details->reunited_at'), fn ($q) => $q->whereNull('details->reunited_at'));
    }

    /**
     * "Lost pet", "Found item", or "Reunited".
     */
    public static function label(array $details): string
    {
        if ($details['reunited_at'] ?? null) {
            return 'Reunited';
        }

        return (self::STATUSES[$details['lost_status'] ?? ''] ?? 'Lost').' '.strtolower(self::KINDS[$details['lost_kind'] ?? ''] ?? 'item');
    }
}
