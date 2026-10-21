<?php

namespace App\Livewire\Settings;

use App\Models\BusinessAccount;
use App\Models\Place;
use App\Models\Promotion;
use App\Support\Business as BusinessPlan;
use App\Support\Stripe;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings → Business: the plan, Upgrade (Stripe Checkout) and Manage billing (Stripe Billing
 * Portal), the places this account manages, and promotions with their delivery.
 */
#[Layout('layouts::stoop')]
#[Title('Business settings')]
class Business extends Component
{
    #[Url(except: '')]
    public string $checkout = '';

    #[Url(except: '')]
    public string $promoted = '';

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.settings.business', [
            'account' => BusinessAccount::where('user_id', $user->id)->first(),
            'isBusiness' => BusinessPlan::isBusiness($user),
            'plan' => BusinessPlan::plan($user),
            'canUpgrade' => BusinessPlan::canUpgrade(),
            'stripe' => Stripe::configured(),
            'places' => Place::where('owner_id', $user->id)->orderBy('name')->get(),
            'promotions' => Promotion::with('ad')->where('user_id', $user->id)->where('status', '!=', 'unpaid')->latest()->take(20)->get(),
        ]);
    }
}
