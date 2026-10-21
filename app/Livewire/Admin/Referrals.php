<?php

namespace App\Livewire\Admin;

use App\Support\Referrals as ReferralLinks;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sales report: clicks on reservation and ticket links, by provider and DC day.
 */
#[Layout('layouts::stoop')]
#[Title('Referrals · Admin')]
class Referrals extends Component
{
    use StaffOnly;

    public const ROLE = 'sales';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /**
     * The period as DC dates; bad or backwards dates fall back to the last 30 days.
     *
     * @return array{0: string, 1: string}
     */
    public static function period(string $from, string $to): array
    {
        [$start, $end] = Insights::period($from, $to);
        $tz = config('stoop.timezone');

        return [$start->timezone($tz)->toDateString(), $end->timezone($tz)->toDateString()];
    }

    public function render(): View
    {
        [$from, $to] = self::period($this->from, $this->to);
        $days = ReferralLinks::byDay($from, $to);

        return view('livewire.admin.referrals', [
            'period' => [$from, $to],
            'days' => $days,
            'providers' => $days->groupBy('provider')->map(fn ($rows) => $rows->sum('clicks'))->sortDesc(),
            'top' => ReferralLinks::top($from, $to),
            'earning' => collect(config('referrals.providers'))->filter(fn ($p) => filled($p['param']) && filled($p['value']))->keys(),
        ]);
    }
}
