<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Support\Classifieds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Classifieds for the app: the board, one listing, posting (paid categories return a Stripe
 * Checkout link to open in the browser), renewing, marking filled, messaging and reporting.
 */
class ClassifiedsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', Rule::in(array_keys(config('classifieds.categories')))],
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'line' => ['nullable', Rule::in(array_keys(config('stoop.lines')))],
            'min' => ['nullable', 'integer', 'min:0'],
            'max' => ['nullable', 'integer', 'min:0'],
        ]);

        $listings = Classifieds::board($data['category'] ?? null, $data['stop'] ?? null, $data['line'] ?? null, isset($data['min']) ? (int) $data['min'] : null, isset($data['max']) ? (int) $data['max'] : null, $request->user())
            ->take(50)->get();

        return response()->json([
            'data' => $listings->map(fn (Listing $l) => $this->present($l)),
            'categories' => collect(config('classifieds.categories'))->map(fn ($c, $key) => ['key' => $key, 'name' => $c['name'], 'price' => Classifieds::priceLabel($key), 'open' => Classifieds::canPost($key)])->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->visible($request, $id))]);
    }

    public function store(Request $request): JsonResponse
    {
        [$listing, $checkout] = Classifieds::create($request->user(), $request->only(['category', 'title', 'body', 'stop', 'price', 'pay_min', 'pay_max', 'pay_period']));

        return response()->json(['data' => $this->present($listing), 'checkout_url' => $checkout], 201);
    }

    public function renew(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $checkout = Classifieds::renew($request->user(), $listing);

        return response()->json(['data' => $this->present($listing->fresh()), 'checkout_url' => $checkout]);
    }

    public function filled(Request $request, int $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        Classifieds::markFilled($request->user(), $listing);

        return response()->json(['data' => $this->present($listing->fresh())]);
    }

    public function contact(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:1000']]);
        $conversation = Classifieds::contact($request->user(), $this->visible($request, $id), $data['body']);

        return response()->json(['conversation_id' => $conversation->id], 201);
    }

    public function report(Request $request, int $id): JsonResponse
    {
        Classifieds::report($request->user(), $this->visible($request, $id), (string) $request->input('reason'), $request->input('note'));

        return response()->json(['ok' => true]);
    }

    protected function visible(Request $request, int $id): Listing
    {
        $listing = Listing::with('user')->findOrFail($id);
        abort_unless($listing->isLive() || $listing->user_id === $request->user()->id, 404);

        return $listing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Listing $l): array
    {
        return [
            'id' => $l->id,
            'category' => $l->category,
            'category_name' => $l->categoryName(),
            'title' => $l->title,
            'body' => $l->body,
            'price' => $l->price,
            'pay' => $l->pay_min ? ['min' => $l->pay_min, 'max' => $l->pay_max, 'period' => $l->pay_period] : null,
            'price_label' => $l->priceLabel(),
            'stop' => $l->stop ? Present::stop($l->stop) : null,
            'status' => $l->isLive() ? 'live' : $l->statusLabel(),
            'expires_at' => $l->expires_at?->toIso8601String(),
            'created_at' => $l->created_at->toIso8601String(),
            'user' => Present::user($l->user),
            'url' => route('classifieds.show', $l),
        ];
    }
}
