<?php

namespace App\Livewire\Admin;

use App\Models\Dispatch;
use App\Support\Audit;
use App\Support\Callouts as Quotes;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Callouts tied to stories, and the replies the newsroom may quote.
 */
#[Layout('layouts::stoop')]
#[Title('Call-outs · Admin')]
class Callouts extends Component
{
    use StaffOnly;

    public const ROLE = 'editor';

    #[Url(as: 'callout')]
    public ?int $selected = null;

    public string $storyUrl = '';

    public string $storyTitle = '';

    public function mount(): void
    {
        if ($this->selected) {
            $this->select($this->selected);
        }
    }

    public function select(int $id): void
    {
        $story = $this->callout($id)->details['story'] ?? [];
        $this->selected = $id;
        $this->storyUrl = $story['url'] ?? '';
        $this->storyTitle = $story['title'] ?? '';
    }

    public function saveStory(): void
    {
        $data = $this->validate([
            'storyUrl' => ['nullable', 'url:http,https', 'max:500'],
            'storyTitle' => ['nullable', 'string', 'max:160'],
        ]);
        $callout = $this->callout($this->selected);
        $story = array_filter(['url' => $data['storyUrl'] ?: null, 'title' => $data['storyTitle'] ?: null]);
        $details = array_filter([...($callout->details ?? []), 'story' => $story ?: null]);

        $callout->forceFill(['details' => $details ?: null])->save();
        Audit::log('callout.story', $callout, $story);
        Flux::toast('Saved.');
    }

    public function export(): StreamedResponse
    {
        $callout = $this->callout($this->selected);
        Audit::log('callout.quotes_exported', $callout);
        $csv = Quotes::csv($callout);

        return response()->streamDownload(fn () => print ($csv), 'callout-'.$callout->id.'-quotes.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function callout(?int $id): Dispatch
    {
        return Dispatch::where('type', 'callout')->whereNull('parent_id')->findOrFail($id);
    }

    public function render(): View
    {
        $callouts = Dispatch::where('type', 'callout')->whereNull('parent_id')->where('status', 'approved')
            ->withCount(['replies' => fn ($q) => $q->where('status', 'approved'), 'replies as quotable_count' => fn ($q) => $q->where('status', 'approved')->where('details->quote_ok', true)])
            ->latest()->take(30)->get();
        $callout = $this->selected ? $callouts->firstWhere('id', $this->selected) ?? $this->callout($this->selected) : null;

        return view('livewire.admin.callouts', [
            'callouts' => $callouts,
            'callout' => $callout,
            'quotes' => $callout ? Quotes::quotes($callout) : collect(),
        ]);
    }
}
