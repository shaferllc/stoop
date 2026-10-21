<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Support\Metro;
use App\Support\Stations;

/**
 * @bikeshare answers "@bikeshare <station or neighborhood>" with Capital Bikeshare docks nearby.
 */
class BikeshareBot extends Bot
{
    // Neighborhoods without a Metro stop of their own, by the nearest one.
    protected const NEIGHBORHOODS = [
        'adams morgan' => 'A04', 'logan circle' => 'E02', 'bloomingdale' => 'E02', 'mount pleasant' => 'E04',
        'kalorama' => 'A03', 'georgetown' => 'C04', 'west end' => 'C04', 'penn quarter' => 'F02', 'chinatown' => 'B01',
        'downtown' => 'A01', 'capitol hill' => 'D05', 'barracks row' => 'D06', 'h street' => 'B03', 'eckington' => 'B35',
        'wharf' => 'F04', 'southwest' => 'F04', 'ballpark' => 'F05', 'national mall' => 'D02', 'the mall' => 'D02', 'old town' => 'C13',
    ];

    public function username(): string
    {
        return 'bikeshare';
    }

    public function name(): string
    {
        return 'Bikeshare';
    }

    public function description(): string
    {
        return 'Live Capital Bikeshare bikes, e-bikes and open docks. Reply “@bikeshare Navy Yard” or a neighborhood.';
    }

    public function reply(Dispatch $dispatch): ?string
    {
        $text = mb_strtolower(trim(preg_replace('/(?<![\w.@])@bikeshare\b/i', '', $dispatch->body)));
        $code = $text === '' ? null : self::NEIGHBORHOODS[$text] ?? Stations::find($text)
            ?? collect(self::NEIGHBORHOODS)->first(fn ($code, $hood) => str_contains($text, $hood));

        if (! $code) {
            return 'Tell me a Metro stop or neighborhood, like “@bikeshare Dupont Circle” or “@bikeshare Adams Morgan”, and I’ll list live bikes, e-bikes and open docks within 500 m.';
        }

        $name = Stations::name($code);
        $docks = Metro::bikeshare($code);

        if ($docks === null) {
            return 'Capital Bikeshare’s live feed isn’t answering right now. Try again in a minute.';
        }

        if (! $docks) {
            return "No Capital Bikeshare docks within 500 m of $name.";
        }

        return "Capital Bikeshare near $name: ".collect($docks)->map(fn ($d) => "{$d['name']} ({$d['meters']} m): "
            .$d['bikes'].' '.str('bike')->plural($d['bikes']).($d['ebikes'] ? " ({$d['ebikes']} e)" : '').', '
            .$d['docks'].' open '.str('dock')->plural($d['docks']))->join('; ').'.';
    }
}
