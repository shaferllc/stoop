<?php

namespace App\Livewire\Admin;

use App\Models\BusinessAccount;
use App\Models\Place;
use App\Models\Promotion;
use App\Support\Business as BusinessPlan;
use App\Support\Stripe;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sales: Business subscribers, their Stripe status, MRR, failed payments and promotion revenue.
 */
#[Layout('layouts::stoop')]
#[Title('Business · Admin')]
class Business extends Component
{
    use StaffOnly;

    protected const ROLE = 'sales';

    public function render(): View
    {
        return view('livewire.admin.business', [
            'stripe' => Stripe::configured(),
            'accounts' => BusinessAccount::with('user:id,name,username')->latest()->get(),
            'places' => Place::whereNotNull('owner_id')->pluck('owner_id')->countBy(),
            'mrr' => BusinessPlan::mrrCents(),
            'active' => BusinessAccount::active()->count(),
            'failing' => BusinessAccount::where('payment_failed_at', '>=', now()->subDays(30))->count(),
            'promotionCents' => Promotion::whereIn('status', ['review', 'approved'])->where('created_at', '>=', now()->subDays(30))->sum('amount_cents'),
        ]);
    }
}
