<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Dispatch;
use App\Models\Place;
use App\Support\QuoteRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public JSON behind the boxes /embed.js draws on the magazine's article pages. Read-only,
 * cached briefly, no cookies and no view counting. Browsers may only call it from the
 * magazine's own sites (config stoop.magazine_origins).
 */
class EmbedController extends Controller
{
    /**
     * "What neighbors at Shaw are saying": the latest approved dispatches at a stop.
     */
    public function neighborhood(Request $request, string $code): JsonResponse
    {
        $code = strtoupper($code);
        abort_unless($name = config("stoop.stations.$code"), 404);

        return $this->json($request, "embed:stop:$code", 120, fn () => [
            'code' => $code,
            'name' => $name,
            'url' => route('home', ['stop' => $code]),
            'dispatches' => Dispatch::topLevel()->visibleTo(null)->with('user')->where('stop', $code)
                ->whereNotIn('type', ['safety', 'callout'])->latest()->take(5)->get()->map($this->item(...))->all(),
        ]);
    }

    /**
     * A place's Been here count and its latest check-ins (Been here posts).
     */
    public function place(Request $request, string $place): JsonResponse
    {
        $place = Place::where('slug', $place)->orWhere('id', ctype_digit($place) ? (int) $place : 0)->firstOrFail();

        return $this->json($request, "embed:place:{$place->id}", 120, function () use ($place) {
            $ids = Dispatch::visibleTo(null)->where('place_id', $place->id)->pluck('id');

            return [
                'name' => $place->name,
                'url' => route('places.show', $place),
                'neighborhood' => $place->neighborhood,
                'stop' => $place->stop ? ['code' => $place->stop, 'name' => $place->stopName()] : null,
                'list' => $place->listLabel(),
                'been_count' => DB::table('marks')->whereIn('dispatch_id', $ids)->where('kind', 'been')->distinct()->count('user_id'),
                'checkins' => Dispatch::topLevel()->visibleTo(null)->with('user')->where('place_id', $place->id)
                    ->where('type', 'been')->latest()->take(5)->get()->map($this->item(...))->all(),
            ];
        });
    }

    /**
     * A reader quote the author agreed to, shaped like an oEmbed "rich" response.
     */
    public function quote(Request $request, int $id): JsonResponse
    {
        abort_unless($card = QuoteRequests::card($id), 404);
        $d = $card->dispatch;

        // Short cache: a declined or deleted quote should disappear quickly.
        return $this->json($request, "embed:quote:$id", 30, fn () => [
            'type' => 'rich',
            'version' => '1.0',
            'provider_name' => 'Stoop',
            'provider_url' => url('/'),
            'author_name' => '@'.$d->user->username,
            'author_url' => route('users.show', $d->user),
            'text' => $d->body,
            'url' => route('dispatches.show', $d),
            'stop' => $d->stopName(),
            'posted_at' => $d->created_at->toIso8601String(),
            'width' => 500,
            'height' => null,
            'html' => '<blockquote class="stoop-quote" cite="'.e(route('dispatches.show', $d)).'"><p>'.e($d->body).'</p>&mdash; <a href="'.e(route('users.show', $d->user)).'">@'.e($d->user->username).'</a> on Stoop</blockquote>',
        ]);
    }

    /**
     * Live results for a poll. Voting happens on Stoop.
     */
    public function poll(Request $request, int $id): JsonResponse
    {
        $poll = Dispatch::topLevel()->visibleTo(null)->where('type', 'poll')->findOrFail($id);

        return $this->json($request, "embed:poll:$id", 15, function () use ($poll) {
            $options = $poll->options()->withCount('voters')->get();
            $total = $options->sum('voters_count');

            return [
                'id' => $poll->id,
                'question' => $poll->body,
                'options' => $options->map(fn ($o) => ['label' => $o->label, 'votes' => $o->voters_count, 'percent' => $total ? (int) round($o->voters_count / $total * 100) : 0])->all(),
                'total' => $total,
                'closes_at' => $poll->closes_at?->toIso8601String(),
                'closed' => $poll->isClosed(),
                'article_url' => $poll->details['article_url'] ?? null,
                'url' => route('dispatches.show', $poll),
            ];
        });
    }

    /**
     * A writer's byline box: name, title, photo, and where to follow them.
     */
    public function author(Request $request, string $slug): JsonResponse
    {
        $author = Author::with('user')->where('slug', $slug)->firstOrFail();

        return $this->json($request, "embed:author:{$author->id}", 300, fn () => [
            'name' => $author->name,
            'title' => $author->title,
            'photo_url' => $author->photo_url,
            'url' => $author->url(),
            'followers' => $author->followers()->count(),
            'articles' => $author->articles()->count(),
            'stoop' => $author->user ? ['username' => $author->user->username, 'url' => route('users.show', $author->user)] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function item(Dispatch $d): array
    {
        return ['author' => $d->user?->username, 'body' => str($d->body)->limit(280)->toString(), 'url' => route('dispatches.show', $d), 'at' => $d->created_at->toIso8601String()];
    }

    protected function json(Request $request, string $key, int $seconds, callable $data): JsonResponse
    {
        $response = response()->json(Cache::remember($key, $seconds, $data))
            ->header('Cache-Control', "public, max-age=$seconds")
            ->header('Vary', 'Origin');

        $origin = $request->headers->get('Origin');
        if ($origin && in_array(rtrim($origin, '/'), config('stoop.magazine_origins'), true)) {
            $response->header('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }
}
