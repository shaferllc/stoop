<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named neighborhood areas as groups of Metro stations, for the monthly area reports sales
     * sends to business improvement districts and developers. Seeded with DC's BIDs, mapped to
     * their nearest stations by hand: marked "verify" until someone checks each boundary.
     */
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->json('stations');
            $table->boolean('verify')->default(false);
            $table->timestamps();
        });

        $now = now();
        DB::table('areas')->insert(collect([
            'Downtown DC BID' => ['A01', 'B01', 'B02', 'C02', 'D01', 'F02'],
            'Golden Triangle BID' => ['A02', 'C03', 'A03'],
            'NoMa BID' => ['B35', 'B03'],
            'Capitol Riverfront BID' => ['F05', 'D05'],
            'Mount Vernon Triangle CID' => ['E01', 'B02'],
            'Georgetown BID' => ['C04', 'C05'],
            'Adams Morgan Partnership BID' => ['A04', 'E04', 'A03'],
            'Dupont Circle Main Street' => ['A03'],
            'Anacostia BID' => ['F06'],
        ])->map(fn ($stations, $name) => [
            'name' => $name, 'slug' => str($name)->slug(), 'stations' => json_encode($stations), 'verify' => true, 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
