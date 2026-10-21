<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The magazine's back catalog from its WordPress API: images, tags, Metro line and
     * writers for archive stories, and the writers' bios.
     */
    public function up(): void
    {
        Schema::table('archive_stories', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable();
            $table->string('line', 10)->nullable();
            $table->string('tags', 300)->nullable();
        });

        Schema::create('archive_story_author', function (Blueprint $table) {
            $table->foreignId('archive_story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->primary(['archive_story_id', 'author_id']);
        });

        Schema::table('authors', function (Blueprint $table) {
            $table->string('bio', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_story_author');
        Schema::table('archive_stories', fn (Blueprint $table) => $table->dropColumn(['image_url', 'line', 'tags']));
        Schema::table('authors', fn (Blueprint $table) => $table->dropColumn('bio'));
    }
};
