<?php

namespace App\Support;

use App\Models\Dispatch;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Open house posts on the Green (Real Estate) line: the block, a date and time window,
 * price, a listing link and the listing agent, labeled as such. Staff can mark one sponsored.
 */
class OpenHouses
{
    /**
     * Validate the open house form and return what goes in the dispatch's details.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function details(array $form, ?string $stop): array
    {
        $today = now(config('stoop.timezone'))->toDateString();

        $data = Validator::make(['openHouse' => $form, 'stop' => $stop], [
            'stop' => ['required'],
            'openHouse.block' => ['required', 'string', 'max:120'],
            'openHouse.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.now(config('stoop.timezone'))->addDays(60)->toDateString()],
            'openHouse.from' => ['required', 'date_format:H:i'],
            'openHouse.to' => ['required', 'date_format:H:i', 'after:openHouse.from'],
            'openHouse.price' => ['nullable', 'integer', 'between:1000,100000000'],
            'openHouse.url' => ['nullable', 'url:https', 'max:300'],
            'openHouse.agent' => ['nullable', 'string', 'max:120'],
        ], ['stop.required' => 'Pick the nearest stop so neighbors can find it.'], [
            'openHouse.block' => 'address', 'openHouse.date' => 'date', 'openHouse.from' => 'start time',
            'openHouse.to' => 'end time', 'openHouse.price' => 'price', 'openHouse.url' => 'link', 'openHouse.agent' => 'listing agent',
        ])->validate()['openHouse'];

        if (Profanity::contains((string) $data['block'].' '.($data['agent'] ?? ''))) {
            throw ValidationException::withMessages(['openHouse.block' => 'Stoop keeps it clean: no swearing. Edit it and post again.']);
        }

        return array_filter([
            'block' => HomeSales::block($data['block']),
            'date' => $data['date'],
            'from' => $data['from'],
            'to' => $data['to'],
            'price' => isset($data['price']) && $data['price'] !== '' ? (int) $data['price'] : null,
            'url' => $data['url'] ?? null,
            'agent' => isset($data['agent']) ? trim($data['agent']) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Staff mark an open house sponsored, or take the label off.
     */
    public static function setSponsored(Dispatch $dispatch, bool $sponsored): void
    {
        abort_unless($dispatch->type === 'openhouse', 404);

        $dispatch->forceFill(['details' => [...($dispatch->details ?? []), 'sponsored' => $sponsored]])->save();
        Audit::log($sponsored ? 'openhouse.sponsored' : 'openhouse.unsponsored', $dispatch);
    }
}
