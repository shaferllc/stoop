<?php

use App\Support\SearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Newsroom tools: private tips, archive stories tagged to stations, article updates
     * that reach the discussion, and magazine issues.
     */
    public function up(): void
    {
        // Tips from readers. Private: only staff ever see them.
        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('link', 500)->nullable();
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('contact_ok')->default(false);
            $table->json('photos')->nullable();
            $table->json('screening')->nullable();
            $table->string('status', 12)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Older magazine stories, shown on station pages as "From the archive".
        Schema::create('archive_stories', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500)->unique();
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->date('published_on')->nullable();
            $table->string('section', 80)->nullable();
            $table->string('neighborhoods', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('archive_story_stations', function (Blueprint $table) {
            $table->foreignId('archive_story_id')->constrained()->cascadeOnDelete();
            $table->string('stop', 4)->index();

            $table->primary(['archive_story_id', 'stop']);
        });

        // Names that point at a station: neighborhoods, landmarks, spellings. Staff edit these.
        Schema::create('station_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 80)->unique();
            $table->string('stop', 4);
            $table->timestamps();
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable();
            $table->string('stations', 60)->nullable();
        });

        // Corrections and updates posted to an article's thread, once each.
        Schema::create('article_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('note', 400);
            $table->string('key', 64);
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('notified')->default(0);
            $table->timestamps();

            $table->unique(['article_id', 'key']);
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('cover_url', 500)->nullable();
            $table->json('stories');
            $table->foreignId('dispatch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // A starting alias list for the tagger: full spellings of abbreviated station names,
        // and neighborhoods named for a nearby station.
        $aliases = [
            'Columbia Heights' => 'E04', 'Mount Vernon Square' => 'E01', 'Mount Vernon Triangle' => 'E01', 'Judiciary Square' => 'B02',
            'McPherson Square' => 'C02', 'Rhode Island Avenue' => 'B04', 'Potomac Avenue' => 'D07', 'King Street' => 'C13',
            'Old Town Alexandria' => 'C13', 'Braddock Road' => 'C12', 'Del Ray' => 'C12', 'Benning Road' => 'G01', 'Van Ness' => 'A06',
            'Gallery Place' => 'B01', 'Chinatown' => 'B01', 'Penn Quarter' => 'B01', 'Adams Morgan' => 'A04', 'Logan Circle' => 'E02',
            'Georgetown' => 'C04', 'Capitol Hill' => 'D05', 'H Street' => 'B03', 'Atlas District' => 'B03', 'Union Market' => 'B35',
            'Eckington' => 'B35', 'Bloomingdale' => 'E02', 'Mount Pleasant' => 'E04', 'Kalorama' => 'A03', 'the Wharf' => 'F04',
            'Southwest Waterfront' => 'F04', 'Barracks Row' => 'D06', 'Hill East' => 'D08', 'National Landing' => 'C09',
            'Downtown Silver Spring' => 'B08', 'Takoma Park' => 'B07', 'Reston' => 'N07', 'Tysons Corner' => 'N02',
        ];
        DB::table('station_aliases')->insert(collect($aliases)->map(fn ($stop, $alias) => ['alias' => $alias, 'stop' => $stop, 'created_at' => now(), 'updated_at' => now()])->values()->all());

        SearchIndex::install();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'stations']);
        });
        foreach (['issues', 'article_updates', 'station_aliases', 'archive_story_stations', 'archive_stories', 'tips'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
