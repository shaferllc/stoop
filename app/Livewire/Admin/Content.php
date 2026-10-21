<?php

namespace App\Livewire\Admin;

use App\Mail\WeeklyDigest;
use App\Models\Dispatch;
use App\Models\User;
use App\Support\Audit;
use App\Support\Magazine;
use App\Support\Newsletter;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Content · Admin')]
class Content extends Component
{
    use StaffOnly;

    public function importNow(): void
    {
        $posted = Magazine::import();
        Audit::log('articles.imported', null, ['posted' => $posted]);
        Flux::toast(config('stoop.magazine_feed') ? "Posted $posted new ".str('article')->plural($posted).'.' : 'Set STOOP_MAGAZINE_FEED first.');
    }

    public function sendTest(): void
    {
        Mail::to($this->staff())->send(new WeeklyDigest(Newsletter::digest(), null));
        Flux::toast('Sent a test to '.$this->staff()->email.'.');
    }

    public function render(): View
    {
        return view('livewire.admin.content', [
            'scheduled' => Dispatch::with('user')->where('status', 'scheduled')->orderBy('publish_at')->take(50)->get(),
            'feed' => config('stoop.magazine_feed'),
            'articles' => DB::table('articles')->latest('created_at')->take(10)->get(),
            'newsletterReaders' => User::where('newsletter', true)->count(),
            'callouts' => Dispatch::with('user')->where('type', 'callout')->where('status', 'approved')->withCount('replies')->latest()->take(20)->get(),
        ]);
    }
}
