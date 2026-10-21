<?php

namespace App\Bots;

use App\Models\Dispatch;
use App\Models\Place;
use App\Support\ClaudeText;
use App\Support\Metro;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @eats answers "@eats <craving> near <station or neighborhood>" from the magazine's place lists.
 */
class Eats extends Bot
{
    // Stations within about a 15-minute walk count as nearby.
    protected const NEARBY_METERS = 1200;

    // Words that say nothing about what kind of place someone wants.
    protected const FILLER = ['the', 'some', 'good', 'great', 'best', 'cheap', 'nice', 'spot', 'place', 'food', 'for', 'want', 'craving', 'any', 'something', 'really', 'late', 'night', 'open', 'with', 'and', 'eat', 'get', 'grab', 'where', 'find', 'recommend', 'recommendation', 'please', 'thanks', 'near'];

    public function username(): string
    {
        return 'eats';
    }

    public function name(): string
    {
        return 'Eats';
    }

    public function description(): string
    {
        return 'Ask “@eats Ethiopian near U Street” for picks from the magazine’s lists.';
    }

    public function reply(Dispatch $dispatch): ?string
    {
        $ask = trim(preg_replace('/(?<![\w.@])@eats\b/i', '', $dispatch->body));
        // "near" wins ("pizza by the slice near Shaw"), then the first "around", "by" or "in".
        $split = preg_match('/^(.*)\s+near\s+(.+)$/is', $ask, $m) || preg_match('/^(.*?)\s+(?:around|by|in)\s+(.+)$/is', $ask, $m);
        [$craving, $where] = $split ? [$m[1], trim($m[2], " \t\n.?!")] : [$ask, ''];

        $station = $where !== '' ? self::station($where) : null;
        $stops = $station ? self::nearbyStops($station) : [];
        $places = Place::query()
            ->when($where !== '', fn ($q) => $q->where(fn ($q) => $q->whereIn('stop', $stops)->orWhere('neighborhood', 'like', '%'.Str::limit($where, 60, '').'%')))
            ->get();

        if ($where !== '' && $places->isEmpty()) {
            return 'I couldn’t find anything on the magazine’s lists around there. Try a Metro station, like “near U Street”, or browse them all: '.route('places');
        }

        $words = self::keywords($craving);
        if (! $words && $where === '') {
            return 'Tell me what you’re craving and where, like “@eats Ethiopian near U Street”. Or browse the lists: '.route('places');
        }

        $matches = self::rank($places, $words, $station);
        $picks = self::claudePicks($ask, $matches->isNotEmpty() ? $matches : self::rank($places, [], $station));
        if ($picks === null || ($picks->isEmpty() && $matches->isNotEmpty())) {
            $picks = $matches->take(3);
        }

        if ($picks->isEmpty()) {
            return 'Nothing on the magazine’s lists matches that'.($where !== '' ? ' around there' : '').'. Browse them all: '.route('places');
        }

        $body = 'From the magazine’s lists:';
        foreach ($picks->values() as $i => $place) {
            $line = "\n".($i + 1).'. '.$place->name.($place->listLabel() ? ' ('.$place->listLabel().')' : '').' '.route('places.show', $place);
            $review = $place->article_url ? "\n   Review: ".$place->article_url : '';
            // Replies have to fit the 500-character limit, so drop what doesn't.
            foreach ([$line.$review, $line] as $candidate) {
                if (mb_strlen($body.$candidate) <= config('stoop.max_length')) {
                    $body .= $candidate;
                    break;
                }
            }
        }

        return $body;
    }

    /**
     * The station named, e.g. "U St" → U Street.
     */
    public static function station(string $where): ?string
    {
        $normal = fn (string $s) => trim(preg_replace(
            ['/\bstreet\b/', '/\bsquare\b/', '/\bmount\b/', '/\bstation\b/'], ['st', 'sq', 'mt', ''],
            preg_replace('/[^a-z0-9]+/', ' ', str_replace(['’', "'"], '', strtolower($s))),
        ));
        $wanted = $normal($where);
        if (strlen($wanted) < 2) {
            return null;
        }

        $names = collect(config('stoop.stations'))->map($normal);

        return $names->search($wanted)
            ?: $names->filter(fn ($n) => str_starts_with($n, $wanted.' '))->keys()->first()
            ?: $names->filter(fn ($n) => strlen($wanted) > 3 && str_contains($n, $wanted))->keys()->first();
    }

    /**
     * @return array<int, string>
     */
    public static function nearbyStops(string $code): array
    {
        $at = config("metro_coords.$code");
        if (! $at) {
            return [$code];
        }

        return collect(config('metro_coords'))
            ->filter(fn ($c) => Metro::meters($at[0], $at[1], $c[0], $c[1]) <= self::NEARBY_METERS)
            ->keys()->push($code)->unique()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function keywords(string $craving): array
    {
        return collect(preg_split('/[^a-z0-9]+/', strtolower($craving)))
            ->filter(fn ($w) => strlen($w) > 2 && ! in_array($w, self::FILLER, true))
            ->map(fn ($w) => Str::singular($w))->unique()->values()->all();
    }

    /**
     * Places sharing the most words with the craving, then by list rank, then nearest.
     *
     * @param  array<int, string>  $words
     * @return Collection<int, Place>
     */
    protected static function rank(Collection $places, array $words, ?string $station): Collection
    {
        $at = $station ? config("metro_coords.$station") : null;
        $distance = fn (Place $p) => $at && ($c = config("metro_coords.{$p->stop}")) ? Metro::meters($at[0], $at[1], $c[0], $c[1]) : PHP_INT_MAX;
        $score = fn (Place $p) => collect($words)->filter(fn ($w) => str_contains(strtolower($p->category.' '.$p->name.' '.$p->list_name.' '.$p->blurb), $w))->count();

        return $places
            ->filter(fn (Place $p) => ! $words || $score($p) > 0)
            ->sortBy([
                fn ($a, $b) => $score($b) <=> $score($a),
                fn ($a, $b) => ($a->list_rank ?? PHP_INT_MAX) <=> ($b->list_rank ?? PHP_INT_MAX),
                fn ($a, $b) => $distance($a) <=> $distance($b),
            ])->values();
    }

    /**
     * With an Anthropic key, Claude picks and orders up to three of the candidates. Null
     * means "use the keyword order"; an empty collection means none of them fit.
     *
     * @return Collection<int, Place>|null
     */
    protected static function claudePicks(string $ask, Collection $candidates): ?Collection
    {
        if (! ClaudeText::enabled() || $candidates->isEmpty()) {
            return null;
        }

        $candidates = $candidates->take(25)->keyBy('id');
        $list = $candidates->map(fn (Place $p) => implode(' | ', array_filter([$p->id, $p->name, $p->category, $p->listLabel(), $p->neighborhood, Str::limit((string) $p->blurb, 160)])))->join("\n");
        $answer = ClaudeText::ask(
            'You pick restaurants and bars for @eats on Stoop, a DC social network, from a DC magazine’s lists. Choose up to three places from the candidates that best fit the request, best first. Reply with only their ids, comma-separated, or the word none if nothing fits.',
            "<request>\n$ask\n</request>\n<candidates>\n$list\n</candidates>",
            256,
        );

        if ($answer === null) {
            return null;
        }

        preg_match_all('/\d+/', $answer, $ids);

        return collect($ids[0])->map(fn ($id) => $candidates->get((int) $id))->filter()->unique('id')->take(3)->values();
    }
}
