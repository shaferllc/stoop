<?php

namespace App\Livewire\Pages;

use App\Models\Place;
use App\Support\Passports;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::stoop')]
#[Title('Passports')]
class PassportsPage extends Component
{
    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.pages.passports', [
            'passports' => $user ? Passports::for($user) : collect(),
            'lists' => Place::whereNotNull('list_name')->orderByRaw('list_rank is null, list_rank')->orderBy('name')->get()->groupBy('list_name'),
            'beenIds' => $user ? DB::table('marks')->join('dispatches', 'dispatches.id', '=', 'marks.dispatch_id')
                ->where('marks.user_id', $user->id)->where('marks.kind', 'been')->whereNotNull('dispatches.place_id')->pluck('dispatches.place_id')->unique()->all() : [],
        ]);
    }
}
