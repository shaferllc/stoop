<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff roles, sign-up risk, the moderation email setting, spam clusters and an editable
     * knowledge base for @stoop.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Any of moderator, editor, sales, admin. is_staff stays true for anyone with a role.
            $table->json('roles')->nullable();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('risk_reasons')->nullable();
            // A salted hash of the sign-up IP; the address itself is never stored.
            $table->string('signup_ip_hash', 64)->nullable()->index();
            $table->boolean('moderation_emails')->default(true);
        });

        // Everyone who was staff before roles existed keeps full access.
        DB::table('users')->where('is_staff', true)->update(['roles' => json_encode(['admin'])]);

        Schema::create('spam_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('signature', 120);
            $table->string('summary');
            $table->json('members');
            $table->json('evidence');
            $table->string('status', 10)->default('open')->index();
            $table->foreignId('actioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'signature']);
        });

        Schema::create('bot_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('section', 80);
            $table->text('body');
            $table->timestamps();
        });

        // Seed from the file @stoop used to read: one row per bullet, plus the intro.
        $section = 'About Stoop';
        foreach (explode("\n", (string) @file_get_contents(resource_path('knowledge/stoop.md'))) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '# ')) {
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $section = substr($line, 3);

                continue;
            }
            DB::table('bot_knowledge')->insert(['section' => $section, 'body' => preg_replace('/^- /', '', $line), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_knowledge');
        Schema::dropIfExists('spam_clusters');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['signup_ip_hash']);
            $table->dropColumn(['roles', 'risk_score', 'risk_reasons', 'signup_ip_hash', 'moderation_emails']);
        });
    }
};
