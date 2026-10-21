<?php

namespace App\Livewire\Admin;

use App\Models\Promotion;
use App\Support\Promotions;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Paid and free promotions waiting for sales or a moderator, on the Ads and Moderation pages.
 */
class PromotionQueue extends Component
{
    /** @var array<int, string> */
    public array $reasons = [];

    public function boot(): void
    {
        abort_unless(Auth::user()?->hasRole('sales') || Auth::user()?->hasRole('moderator'), 403);
    }

    public function approve(int $id): void
    {
        try {
            Promotions::approve(Promotion::findOrFail($id), Auth::user());
            Flux::toast('Approved. It runs from its start date.');
        } catch (HttpException $e) {
            Flux::toast($e->getMessage() ?: 'Already decided.', variant: 'danger');
        }
    }

    public function reject(int $id): void
    {
        try {
            Promotions::reject(Promotion::findOrFail($id), Auth::user(), trim($this->reasons[$id] ?? '') ?: null);
            Flux::toast('Rejected.');
        } catch (ApiErrorException $e) {
            // The refund didn't go through, so it stays in the queue.
            Flux::toast('Stripe couldn’t refund it: '.$e->getMessage(), variant: 'danger');
        } catch (HttpException) {
            Flux::toast('Already decided.', variant: 'danger');
        }
    }

    public function render(): View
    {
        return view('livewire.admin.promotion-queue', [
            'queue' => Promotion::with('user')->where('status', 'review')->oldest()->get(),
        ]);
    }
}
