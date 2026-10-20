<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('name');
            $table->boolean('is_native')->default(false);
            $table->unsignedSmallInteger('dc_since')->nullable();
            $table->json('stops')->nullable();
            $table->boolean('is_staff')->default(false);
            $table->timestamp('muted_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'is_native', 'dc_since', 'stops', 'is_staff', 'muted_until']);
        });
    }
};
