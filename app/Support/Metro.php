<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live rail incidents from WMATA, cached for two minutes. Needs a free WMATA API key.
 */
class Metro
{
    protected const LINE_KEYS = ['RD' => 'red', 'OR' => 'orange', 'BL' => 'blue', 'GR' => 'green', 'YL' => 'yellow', 'SV' => 'silver'];

    /**
     * @return array<int, array{line: string, message: string}>|null Null when no key is set or WMATA is unreachable.
     */
    public static function incidents(): ?array
    {
        if (! $key = config('services.wmata.key')) {
            return null;
        }

        return Cache::remember('wmata:incidents', now()->addMinutes(2), function () use ($key) {
            try {
                $incidents = Http::withHeaders(['api_key' => $key])->timeout(4)
                    ->get('https://api.wmata.com/Incidents.svc/json/Incidents')->throw()->json('Incidents', []);
            } catch (Throwable) {
                return null;
            }

            return collect($incidents)->flatMap(fn ($i) => collect(preg_split('/[;\s]+/', (string) $i['LinesAffected'], -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($code) => ['line' => self::LINE_KEYS[$code] ?? null, 'message' => (string) $i['Description']]))
                ->filter(fn ($i) => $i['line'])->values()->all();
        });
    }
}
