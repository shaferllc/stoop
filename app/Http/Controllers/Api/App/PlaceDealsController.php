<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\PlaceDeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Owners' deals: the running ones near a stop or at a place, and posting and ending them
 * for a place you've claimed.
 */
class PlaceDealsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stop' => ['nullable', Rule::in(array_keys(config('stoop.stations')))],
            'place_id' => ['nullable', 'integer'],
        ]);

        $deals = PlaceDeal::active()->with('place')
            ->when($data['stop'] ?? null, fn ($q, $stop) => $q->whereHas('place', fn ($q) => $q->where('stop', $stop)))
            ->when($data['place_id'] ?? null, fn ($q, $id) => $q->where('place_id', $id))
            ->latest()->take(20)->get();

        return response()->json(['data' => $deals->map(fn (PlaceDeal $d) => $this->present($d))]);
    }

    public function store(Request $request, int $placeId): JsonResponse
    {
        $deal = PlaceDeal::post($request->user(), Place::findOrFail($placeId), $request->only(['title', 'details', 'starts_at', 'ends_at']));

        return response()->json(['data' => $this->present($deal->load('place'))], 201);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $deal = PlaceDeal::with('place')->findOrFail($id);
        abort_unless($deal->place->owner_id === $request->user()->id, 403);
        $deal->update(['ends_at' => now()]);

        return response()->json(['data' => $this->present($deal)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(PlaceDeal $deal): array
    {
        return [
            'id' => $deal->id,
            'title' => $deal->title,
            'details' => $deal->details,
            'starts_at' => $deal->starts_at->toIso8601String(),
            'ends_at' => $deal->ends_at->toIso8601String(),
            'place' => ['id' => $deal->place->id, 'name' => $deal->place->name, 'stop' => $deal->place->stop ? Present::stop($deal->place->stop) : null],
        ];
    }
}
