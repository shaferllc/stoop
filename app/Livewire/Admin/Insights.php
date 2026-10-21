<?php

namespace App\Livewire\Admin;

use App\Support\Audit;
use App\Support\ReaderInsights;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reader insights for advertisers: aggregate, k-anonymous numbers only (see ReaderInsights).
 */
#[Layout('layouts::stoop')]
#[Title('Reader insights · Admin')]
class Insights extends Component
{
    use StaffOnly;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public ?string $summary = null;

    public function mount(): void
    {
        $today = now(config('stoop.timezone'));
        $this->to = $this->to ?: $today->toDateString();
        $this->from = $this->from ?: $today->copy()->subDays(29)->toDateString();
    }

    public function updated(): void
    {
        $this->summary = null;
    }

    /**
     * The period as UTC timestamps, from the start of the first DC day to the end of the last.
     * Bad or backwards dates, or more than a year, fall back to the last 30 days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function period(string $from, string $to): array
    {
        $tz = config('stoop.timezone');
        $start = rescue(fn () => Carbon::parse($from ?: 'now', $tz), now($tz), false)->startOfDay();
        $end = rescue(fn () => Carbon::parse($to ?: 'now', $tz), now($tz), false)->endOfDay();

        if ($from === '' || $end->lt($start) || $start->diffInDays($end) > 366) {
            $start = $end->copy()->subDays(29)->startOfDay();
        }

        return [$start->utc(), $end->utc()];
    }

    public function summarize(): void
    {
        $this->summary = ReaderInsights::summary(ReaderInsights::report(...self::period($this->from, $this->to)))
            ?? 'Claude couldn’t write a summary right now.';
        Audit::log('insights.summarized', null, ['from' => $this->from, 'to' => $this->to]);
    }

    public function render(): View
    {
        return view('livewire.admin.insights', [
            'report' => ReaderInsights::report(...self::period($this->from, $this->to)),
            'claude' => ReaderInsights::summaryEnabled(),
        ]);
    }
}
