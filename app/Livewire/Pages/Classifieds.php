<?php

namespace App\Livewire\Pages;

use App\Support\Classifieds as Board;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Classifieds: jobs, apartments and rooms, roommates and for sale, filtered by stop or line
 * and price, with the form for posting one.
 */
#[Layout('layouts::stoop')]
#[Title('Classifieds')]
class Classifieds extends Component
{
    #[Url]
    public ?string $category = null;

    #[Url]
    public ?string $stop = null;

    #[Url]
    public ?string $line = null;

    #[Url]
    public ?string $min = null;

    #[Url]
    public ?string $max = null;

    public int $limit = 30;

    public bool $posting = false;

    /** @var array<string, mixed> */
    public array $form = ['category' => 'forsale', 'title' => '', 'body' => '', 'stop' => '', 'price' => '', 'pay_min' => '', 'pay_max' => '', 'pay_period' => 'hour'];

    public function startPosting(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }
        $this->posting = true;
    }

    public function post(): void
    {
        abort_unless(Auth::check(), 403);
        $input = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        if ($input['category'] !== 'jobs') {
            $input = [...$input, 'pay_min' => null, 'pay_max' => null, 'pay_period' => null];
        } else {
            $input['price'] = null;
        }

        $this->resetErrorBag();
        try {
            [$listing, $checkout] = Board::create(Auth::user(), $input);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("form.$field", $messages[0]);
            }

            return;
        }

        if ($checkout) {
            $this->redirect($checkout);

            return;
        }

        $this->redirectRoute('classifieds.show', $listing, navigate: true);
    }

    public function loadMore(): void
    {
        $this->limit += 30;
    }

    public function render(): View
    {
        $valid = validator(['category' => $this->category, 'stop' => $this->stop, 'line' => $this->line, 'min' => $this->min, 'max' => $this->max], [
            'category' => ['nullable', Rule::in(array_keys(config('classifieds.categories')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'min' => ['nullable', 'integer', 'min:0'],
            'max' => ['nullable', 'integer', 'min:0'],
        ])->passes();
        if (! $valid) {
            $this->reset('category', 'stop', 'line', 'min', 'max');
        }

        $listings = Board::board($this->category, $this->stop, $this->line, $this->min === null || $this->min === '' ? null : (int) $this->min, $this->max === null || $this->max === '' ? null : (int) $this->max, Auth::user())
            ->take($this->limit)->get();

        return view('livewire.pages.classifieds', ['listings' => $listings]);
    }
}
