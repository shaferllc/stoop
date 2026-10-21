<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An owner's Business plan, kept in step with their Stripe subscription by webhooks.
 */
#[Fillable(['user_id', 'stripe_customer_id', 'stripe_subscription_id', 'status', 'current_period_end', 'cancel_at_period_end', 'failed_payments', 'payment_failed_at'])]
class BusinessAccount extends Model
{
    protected function casts(): array
    {
        return ['current_period_end' => 'datetime', 'payment_failed_at' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', config('business.active_statuses'));
    }

    public function isActive(): bool
    {
        return in_array($this->status, config('business.active_statuses'), true);
    }
}
