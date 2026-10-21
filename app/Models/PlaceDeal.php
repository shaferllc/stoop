<?php

namespace App\Models;

use App\Support\Business;
use App\Support\Profanity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A deal a verified owner posts for their place, shown on the place page and, labeled,
 * at the top of the place's stop feed. One deal at a time per place, three on the Business plan.
 */
#[Fillable(['place_id', 'user_id', 'title', 'details', 'starts_at', 'ends_at'])]
class PlaceDeal extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Running now.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    /**
     * Post a deal as the place's verified owner. Times are DC times. A deal that hasn't
     * ended yet (running or scheduled) blocks a new one.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function post(User $user, Place $place, array $input): self
    {
        abort_unless($place->owner_id === $user->id, 403);

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'min:3', 'max:80'],
            'details' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['required', 'date'],
        ], [], ['ends_at' => 'end'])->validate();

        $tz = config('stoop.timezone');
        $starts = ($data['starts_at'] ?? null) ? CarbonImmutable::parse($data['starts_at'], $tz)->utc() : now();
        $ends = CarbonImmutable::parse($data['ends_at'], $tz)->utc();

        if ($ends->lte(max($starts, now())) || $ends->gt($starts->addDays(90))) {
            throw ValidationException::withMessages(['ends_at' => 'End it after it starts, and within 90 days.']);
        }
        if (Profanity::contains($data['title'].' '.($data['details'] ?? ''))) {
            throw ValidationException::withMessages(['title' => 'Stoop keeps it clean: no swearing.']);
        }

        return DB::transaction(function () use ($user, $place, $data, $starts, $ends) {
            $limit = Business::dealLimit($place);
            if (self::where('place_id', $place->id)->where('ends_at', '>', now())->count() >= $limit) {
                throw ValidationException::withMessages(['title' => $limit === 1 ? 'One deal at a time. End the current one first.' : "Up to $limit deals at a time. End one first."]);
            }

            return self::create(['place_id' => $place->id, 'user_id' => $user->id, 'title' => $data['title'], 'details' => $data['details'] ?? null, 'starts_at' => $starts, 'ends_at' => $ends]);
        });
    }
}
