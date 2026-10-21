<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @history's "On this day in DC" entries, and editors' picks for @weekend's round-up.
     */
    public function up(): void
    {
        Schema::create('history_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->string('text', 400);
            $table->string('link', 300)->nullable();
            $table->string('line', 10)->nullable();
            $table->string('stop', 4)->nullable();
            // Entries waiting on an editor's fact check; @history skips them.
            $table->boolean('verify')->default(false);
            $table->timestamps();

            $table->index(['month', 'day']);
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->timestamp('weekend_picked_at')->nullable();
        });

        // Well-known dates to start from. Each is marked for an editor to check before it posts.
        $seeds = [
            [3, 3, '1913: Thousands march down Pennsylvania Avenue in the Woman Suffrage Procession, the day before Woodrow Wilson’s inauguration.', 'blue', 'D01'],
            [3, 27, '1912: First Lady Helen Taft and Viscountess Chinda plant the first of Tokyo’s gift of cherry trees along the Tidal Basin.', 'blue', 'D02'],
            [3, 27, '1976: Metrorail opens, with Red Line trains running between Farragut North and Rhode Island Ave.', 'red', 'A02'],
            [3, 29, '1961: The 23rd Amendment is ratified, giving DC residents a vote for president.', 'blue', null],
            [4, 16, '1862: President Lincoln signs the DC Compensated Emancipation Act, freeing enslaved people in the District. It’s DC Emancipation Day.', 'blue', null],
            [7, 16, '1790: The Residence Act is signed, putting the new national capital on the Potomac.', 'blue', null],
            [8, 22, '1934: Chuck Brown, the Godfather of Go-Go, is born. Put on “Bustin’ Loose.”', 'blue', null],
            [8, 24, '1814: British troops burn the White House, the Capitol and much of federal Washington.', 'blue', null],
            [8, 28, '1963: The March on Washington fills the Mall, and Martin Luther King Jr. gives his “I Have a Dream” speech at the Lincoln Memorial.', 'blue', 'C04'],
            [10, 9, '1888: The Washington Monument opens to the public.', 'blue', 'D02'],
            [10, 30, '2019: The Nationals win Game 7 in Houston and bring DC its first World Series title since 1924.', 'orange', 'F05'],
            [12, 24, '1973: President Nixon signs the Home Rule Act, letting DC elect its own mayor and council.', 'blue', null],
        ];

        DB::table('history_entries')->insert(array_map(fn ($s) => [
            'month' => $s[0], 'day' => $s[1], 'text' => $s[2], 'line' => $s[3], 'stop' => $s[4],
            'verify' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $seeds));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn('weekend_picked_at');
        });
        Schema::dropIfExists('history_entries');
    }
};
