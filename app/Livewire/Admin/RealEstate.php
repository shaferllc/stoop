<?php

namespace App\Livewire\Admin;

use App\Models\Dispatch;
use App\Support\Audit;
use App\Support\HomeSales;
use App\Support\OpenHouses;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Home sales import status and open houses, with the staff-only "sponsored" label.
 */
#[Layout('layouts::stoop')]
#[Title('Real estate · Admin')]
class RealEstate extends Component
{
    use StaffOnly;

    public const ROLE = 'sales';

    public function import(): void
    {
        if (! RateLimiter::attempt('home-sales-import', 2, fn () => true, 600)) {
            Flux::toast('The import ran a moment ago. Try again in a few minutes.');

            return;
        }

        $stored = HomeSales::import();
        Audit::log('home_sales.imported', null, ['stored' => $stored]);
        Flux::toast("Stored $stored sales near stations.");
    }

    public function sponsor(int $id, bool $sponsored): void
    {
        OpenHouses::setSponsored(Dispatch::findOrFail($id), $sponsored);
    }

    public function render(): View
    {
        return view('livewire.admin.real-estate', [
            'sales' => DB::table('home_sales')->selectRaw('count(*) as total, max(sold_on) as newest, max(updated_at) as imported')->first(),
            'openHouses' => Dispatch::with('user')->where('type', 'openhouse')->whereNull('parent_id')->latest()->take(50)->get(),
        ]);
    }
}
