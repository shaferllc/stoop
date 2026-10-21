<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Keeping the site running: integration health and a fixed set of safe chores.
 * Nothing here can wipe, reseed or roll back the database.
 */
class Maintenance
{
    public const TASKS = [
        'import' => 'Import magazine articles',
        'schedule' => 'Run scheduled tasks',
        'search' => 'Rebuild the search index',
        'clear' => 'Clear caches',
        'backup' => 'Back up the database',
    ];

    public static function run(string $task): string
    {
        abort_unless(array_key_exists($task, self::TASKS), 404);

        $result = match ($task) {
            'import' => Artisan::call('stoop:import-articles') === 0 ? trim(Artisan::output()) : 'The import failed. Check the log.',
            'schedule' => Artisan::call('schedule:run') === 0 ? 'Scheduled tasks ran.' : 'Some scheduled tasks failed. Check the log.',
            'search' => tap('Search index rebuilt.', fn () => SearchIndex::install()),
            'clear' => Artisan::call('optimize:clear') === 0 ? 'Caches cleared.' : 'Clearing caches failed.',
            'backup' => 'Saved '.basename(self::backup()).'.',
        };
        Audit::log("maintenance.$task", null, ['result' => $result]);

        return $result;
    }

    /**
     * Copies the live SQLite database to storage/app/backups without locking writers out.
     */
    public static function backup(): string
    {
        File::ensureDirectoryExists($dir = storage_path('app/backups'));
        $path = $dir.'/stoop-'.now()->format('Y-m-d-His').'.sqlite';
        DB::statement('VACUUM INTO ?', [$path]);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public static function health(): array
    {
        $migrator = app('migrator');
        $pending = array_values(array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan()));

        $reverb = config('broadcasting.connections.reverb.options');
        $reverbUp = config('broadcasting.default') === 'reverb' && ! empty($reverb['host'])
            && (bool) @fsockopen($reverb['host'], (int) $reverb['port'], $errno, $errstr, 1);

        $ran = Cache::get('stoop:schedule-ran');
        $db = config('database.connections.sqlite.database');

        return [
            'checks' => [
                ['name' => 'OpenAI moderation', 'ok' => (bool) config('services.openai.key'), 'fix' => 'Set OPENAI_API_KEY'],
                ['name' => 'Claude (moderation, @stoop, translation)', 'ok' => (bool) config('services.anthropic.key'), 'fix' => 'Set ANTHROPIC_API_KEY'],
                ['name' => 'WMATA live trains', 'ok' => (bool) config('services.wmata.key'), 'fix' => 'Set WMATA_API_KEY'],
                ['name' => 'Push notifications', 'ok' => (bool) config('services.webpush.private_key'), 'fix' => 'Set VAPID keys'],
                ['name' => 'Turnstile on sign-up', 'ok' => (bool) config('services.turnstile.secret'), 'fix' => 'Set TURNSTILE_SECRET'],
                ['name' => 'Magazine feed', 'ok' => (bool) config('stoop.magazine_feed'), 'fix' => 'Set STOOP_MAGAZINE_FEED'],
                ['name' => 'Reverb real-time server', 'ok' => $reverbUp, 'fix' => 'Run php artisan reverb:start'],
                ['name' => 'Queue worker (post checks, bot replies)', 'ok' => config('queue.default') === 'sync' || ! DB::table('jobs')->where('available_at', '<', now()->subMinutes(2)->getTimestamp())->exists(), 'fix' => 'Run npm run workers (or dpl dev on stoop --script workers)'],
                ['name' => 'Scheduler', 'ok' => $ran && now()->diffInMinutes($ran) < 5, 'fix' => 'Run npm run workers (or cron in production)'],
                ['name' => 'Migrations', 'ok' => ! $pending, 'fix' => 'Run php artisan migrate'],
            ],
            'pending_migrations' => $pending,
            'schedule_ran_at' => $ran,
            'jobs_waiting' => DB::table('jobs')->count(),
            'jobs_failed' => DB::table('failed_jobs')->count(),
            'database_bytes' => $db && File::exists($db) ? File::size($db) : null,
            'backups' => collect(File::glob(storage_path('app/backups/*.sqlite')))->sortDesc()->take(10)
                ->map(fn ($f) => ['name' => basename($f), 'bytes' => File::size($f)])->values(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => app()->environment(),
            'tasks' => self::TASKS,
        ];
    }

    /**
     * The end of a log file, read without loading the whole thing.
     */
    public static function tail(string $path, int $bytes = 30000): string
    {
        if (! is_file($path)) {
            return '';
        }
        $size = filesize($path);
        $f = fopen($path, 'r');
        fseek($f, max(0, $size - $bytes));
        $text = stream_get_contents($f);
        fclose($f);

        return $size > $bytes ? (string) str($text)->after("\n") : $text;
    }
}
