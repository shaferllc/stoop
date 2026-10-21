<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Calendar and ticket details for a Happening dispatch. Tickets are an outside link:
 * Stoop takes no payments, it only counts the click-throughs.
 */
#[Fillable(['dispatch_id', 'starts_at', 'price_cents', 'ticket_url', 'featured'])]
class Happening extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'featured' => 'boolean'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function isTicketed(): bool
    {
        return (bool) $this->ticket_url;
    }

    public function priceLabel(): ?string
    {
        if ($this->price_cents === null) {
            return null;
        }

        return $this->price_cents === 0 ? 'Free' : '$'.rtrim(rtrim(number_format($this->price_cents / 100, 2), '0'), '.');
    }
}
