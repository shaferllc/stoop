<?php

namespace App\Http\Resources;

use App\Models\Dispatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dispatch
 */
class DispatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => route('dispatches.show', $this->id),
            'type' => $this->type,
            'body' => $this->body,
            'line' => ['key' => $this->line, 'name' => $this->lineName()],
            'stop' => $this->stop ? ['code' => $this->stop, 'name' => $this->stopName()] : null,
            'author' => ['username' => $this->user->username, 'name' => $this->user->name, 'badge' => $this->user->badge()],
            'parent_id' => $this->parent_id,
            'quote_id' => $this->quote_id,
            'happening_at' => $this->happening_at,
            'poll' => $this->whenLoaded('options', fn () => $this->options->isEmpty() ? null : [
                'closes_at' => $this->closes_at?->toIso8601String(),
                'options' => $this->options->map(fn ($o) => ['label' => $o->label, 'votes' => $o->voters_count ?? null]),
            ]),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => ['url' => $i->url(), 'alt' => $i->alt])),
            'replies_count' => $this->replies_count ?? null,
            'status' => $this->when($request->user()?->id === $this->user_id, $this->status),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
