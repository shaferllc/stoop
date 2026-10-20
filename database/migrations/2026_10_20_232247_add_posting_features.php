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
        Schema::create('link_previews', function (Blueprint $table) {
            $table->id();
            $table->string('url_hash', 64)->unique();
            $table->text('url');
            $table->string('title', 200)->nullable();
            $table->string('description', 300)->nullable();
            $table->text('image')->nullable();
            $table->timestamps();
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->timestamp('publish_at')->nullable()->index();
            $table->timestamp('closes_at')->nullable();
            $table->foreignId('link_preview_id')->nullable()->constrained()->nullOnDelete();
            $table->softDeletes();
        });

        Schema::create('dispatch_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt', 1000)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('pinned_dispatch_id')->nullable()->constrained('dispatches')->nullOnDelete();
            $table->timestamp('accepted_rules_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_dispatch_id');
            $table->dropColumn('accepted_rules_at');
        });
        Schema::dropIfExists('dispatch_images');
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('link_preview_id');
            $table->dropColumn(['publish_at', 'closes_at', 'deleted_at']);
        });
        Schema::dropIfExists('link_previews');
    }
};
