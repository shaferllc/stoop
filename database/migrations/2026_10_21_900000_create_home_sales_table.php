<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recent DC home sales near Metro stations, from DC's public property records.
     * Only the block is kept, never the house number or anyone's name.
     */
    public function up(): void
    {
        Schema::create('home_sales', function (Blueprint $table) {
            $table->id();
            $table->string('ssl', 40);
            $table->string('block', 120);
            $table->unsignedInteger('price');
            $table->date('sold_on');
            $table->string('stop', 4)->index();
            $table->unsignedSmallInteger('meters');
            $table->timestamps();

            $table->unique(['ssl', 'sold_on']);
            $table->index(['stop', 'sold_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_sales');
    }
};
