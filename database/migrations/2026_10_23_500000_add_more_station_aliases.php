<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Neighborhood names the magazine uses for its list places, mapped to the nearest Metro station.
// Staff can change them in Admin → Archive.
return new class extends Migration
{
    private const ALIASES = [
        'downtown DC' => 'A01', 'Downtown DC' => 'A01', 'Wharf' => 'F04', 'The Wharf' => 'F04', 'Southwest DC' => 'F04',
        '14th Street corridor' => 'E03', 'Chevy Chase DC' => 'A08', 'Cathedral Heights' => 'A07',
        'Falls Church' => 'K05', 'Fairfax' => 'K08', 'Bloomingdale' => 'B35', 'Kalorama' => 'A03', 'Glover Park' => 'A07',
    ];

    public function up(): void
    {
        foreach (self::ALIASES as $alias => $stop) {
            DB::table('station_aliases')->insertOrIgnore(['alias' => $alias, 'stop' => $stop, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('station_aliases')->whereIn('alias', array_keys(self::ALIASES))->delete();
    }
};
