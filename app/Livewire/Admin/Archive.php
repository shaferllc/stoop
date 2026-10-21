<?php

namespace App\Livewire\Admin;

use App\Models\Place;
use App\Support\Archive as StoryArchive;
use App\Support\Audit;
use App\Support\MagazineBackCatalog;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * The magazine's archive for station pages: CSV import, and the alias list the tagger uses.
 */
#[Layout('layouts::stoop')]
#[Title('Archive · Admin')]
class Archive extends Component
{
    use StaffOnly, WithFileUploads, WithPagination;

    protected const ROLE = 'editor';

    public $csv;

    #[Url(except: '')]
    public string $q = '';

    public string $alias = '';

    public string $aliasStop = '';

    public function import(): void
    {
        $this->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $result = StoryArchive::import($this->csv->getRealPath());

        Audit::log('archive.imported', null, $result);
        $this->reset('csv');
        Flux::toast("Imported {$result['saved']} ".str('story')->plural($result['saved']).($result['skipped'] ? ", skipped {$result['skipped']} without a title or URL" : '').'.');
    }

    public function addAlias(): void
    {
        $data = $this->validate([
            'alias' => ['required', 'string', 'min:3', 'max:80', 'unique:station_aliases,alias'],
            'aliasStop' => ['required', Rule::in(array_keys(config('stoop.stations')))],
        ]);
        DB::table('station_aliases')->insert(['alias' => trim($data['alias']), 'stop' => $data['aliasStop'], 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('archive.alias_added', null, $data);
        $this->reset('alias', 'aliasStop');
    }

    public function removeAlias(int $id): void
    {
        $alias = DB::table('station_aliases')->find($id);
        DB::table('station_aliases')->delete($id);
        Audit::log('archive.alias_removed', null, ['alias' => $alias?->alias]);
    }

    /**
     * One page (100 posts) of the back-catalog import from the saved cursor.
     */
    public function runBatch(): void
    {
        $r = MagazineBackCatalog::run(100);
        Audit::log('archive.import_batch', null, $r);
        Flux::toast($r['error'] ?? "Archived {$r['imported']} ".str('story')->plural($r['imported']).($r['backfilled'] ? ", filled in {$r['backfilled']} posted articles" : '').($r['done'] ? '. All caught up.' : '.'));
    }

    public function retag(): void
    {
        $count = StoryArchive::retag();
        Audit::log('archive.retagged', null, ['stories' => $count]);
        Flux::toast("Tagged $count ".str('story')->plural($count).' again.');
    }

    public function render(): View
    {
        $q = trim($this->q);
        $stories = DB::table('archive_stories')
            ->when($q !== '', fn ($query) => $query->where('title', 'like', '%'.$q.'%')->orWhereIn('id', DB::table('archive_story_stations')->where('stop', strtoupper($q))->select('archive_story_id')))
            ->latest('published_on')->paginate(25);

        return view('livewire.admin.archive', [
            'stories' => $stories,
            'stations' => DB::table('archive_story_stations')->whereIn('archive_story_id', $stories->pluck('id'))->get()->groupBy('archive_story_id')->map(fn ($rows) => $rows->pluck('stop')->all()),
            'aliases' => DB::table('station_aliases')->orderBy('alias')->get(),
            'total' => DB::table('archive_stories')->count(),
            'import' => MagazineBackCatalog::status(),
            'imported' => DB::table('archive_stories')->whereNotNull('line')->count(),
            'needsStation' => Place::whereNotNull('list_name')->whereNull('stop')->orderBy('list_name')->orderBy('name')->take(50)->get(),
            'needsStationCount' => Place::whereNotNull('list_name')->whereNull('stop')->count(),
        ]);
    }
}
