<?php

namespace App\Livewire\Admin;

use App\Support\Radar as StoryRadar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Story radar: what's growing on Stoop this week, for editors.
 */
#[Layout('layouts::stoop')]
#[Title('Radar · Admin')]
class Radar extends Component
{
    use StaffOnly;

    public const ROLE = 'editor';

    /** @var array<string, ?string> Story ideas by trend key. */
    public array $ideas = [];

    public function refresh(): void
    {
        Cache::forget('radar');
    }

    public function idea(string $key): void
    {
        if (! RateLimiter::attempt('radar-idea:'.$this->staff()->id, 20, fn () => true)) {
            $this->ideas[$key] = 'Slow down a little. Try again in a minute.';

            return;
        }

        $trend = collect(StoryRadar::get())->except('generated_at')->flatten(1)->firstWhere('key', $key);
        $this->ideas[$key] = $trend ? (StoryRadar::idea($trend) ?? 'Claude didn’t have an idea for this one.') : null;
    }

    public function render(): View
    {
        return view('livewire.admin.radar', ['radar' => StoryRadar::get(), 'aiOn' => StoryRadar::ideasEnabled()]);
    }
}
