<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coded and local phrases staff watch for. New posts with an active phrase are held for a
     * moderator, with the meaning shown. The seeded rows are examples for staff to review.
     */
    public function up(): void
    {
        Schema::create('watch_phrases', function (Blueprint $table) {
            $table->id();
            $table->string('phrase', 120)->unique();
            $table->string('meaning', 500);
            $table->string('severity', 10)->default('medium');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Broad phrases start off: they show up in plenty of innocent posts too.
        $examples = [
            ['east of the river types', 'Example: uses Wards 7 and 8 as a stand-in for Black and low-income neighbors.', 'medium', true],
            ['Section 8 crowd', 'Example: housing-voucher shorthand used to look down on low-income neighbors.', 'medium', true],
            ['the wrong element', 'Example: often means “people of a certain race or class” moving in or showing up.', 'medium', true],
            ['those people', 'Example: vague othering, often coded for race or class when talking about a neighborhood. Off by default.', 'low', false],
            ['urban youth', 'Example: frequently a stand-in for Black teenagers in crime and safety posts. Off by default.', 'low', false],
        ];

        DB::table('watch_phrases')->insert(array_map(fn ($e) => [
            'phrase' => $e[0], 'meaning' => $e[1], 'severity' => $e[2], 'active' => $e[3], 'created_at' => now(), 'updated_at' => now(),
        ], $examples));
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_phrases');
    }
};
