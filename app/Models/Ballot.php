<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A Best of Washington reader ballot: categories, each with nominee places. Readers vote
 * once per category while it's open; results show once it closes.
 */
#[Fillable(['title', 'opens_at', 'closes_at'])]
class Ballot extends Model
{
    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return now()->between($this->opens_at, $this->closes_at);
    }

    public function isClosed(): bool
    {
        return $this->closes_at->isPast();
    }

    /**
     * @param  array<string, array<int, int>>  $categories  Category name => nominee place ids.
     */
    public static function start(string $title, \DateTimeInterface $opensAt, \DateTimeInterface $closesAt, array $categories): self
    {
        return DB::transaction(function () use ($title, $opensAt, $closesAt, $categories) {
            $ballot = self::create(['title' => $title, 'opens_at' => $opensAt, 'closes_at' => $closesAt]);
            $position = 0;
            foreach ($categories as $name => $placeIds) {
                $id = DB::table('ballot_categories')->insertGetId(['ballot_id' => $ballot->id, 'name' => $name, 'position' => $position++]);
                DB::table('ballot_nominees')->insert(array_map(fn ($placeId) => ['ballot_category_id' => $id, 'place_id' => $placeId], array_values(array_unique($placeIds))));
            }

            return $ballot;
        });
    }

    /**
     * Categories with their nominees, vote counts and the viewer's pick. Counts are only
     * filled in once the ballot closes.
     *
     * @return Collection<int, object>
     */
    public function categories(?User $viewer = null): Collection
    {
        $categories = DB::table('ballot_categories')->where('ballot_id', $this->id)->orderBy('position')->get();
        $nominees = DB::table('ballot_nominees')->join('places', 'places.id', '=', 'ballot_nominees.place_id')
            ->whereIn('ballot_category_id', $categories->pluck('id'))
            ->select('ballot_nominees.id', 'ballot_nominees.ballot_category_id', 'places.id as place_id', 'places.name', 'places.slug', 'places.neighborhood')
            ->selectSub(DB::table('ballot_votes')->selectRaw('count(*)')->whereColumn('ballot_nominee_id', 'ballot_nominees.id'), 'votes')
            ->orderBy('places.name')->get()->groupBy('ballot_category_id');
        $picks = $viewer ? DB::table('ballot_votes')->where('user_id', $viewer->id)->whereIn('ballot_category_id', $categories->pluck('id'))
            ->pluck('ballot_nominee_id', 'ballot_category_id') : collect();
        $closed = $this->isClosed();

        return $categories->each(function ($c) use ($nominees, $picks, $closed) {
            $c->nominees = ($nominees[$c->id] ?? collect())->each(fn ($n) => $n->votes = $closed ? (int) $n->votes : null)
                ->when($closed, fn ($n) => $n->sortByDesc('votes'))->values();
            $c->pick = $picks[$c->id] ?? null;
            $c->winner = $closed && $c->nominees->first()?->votes ? $c->nominees->first() : null;
        });
    }

    /**
     * One vote per person per category, and only while the ballot is open. Returns an error
     * message, or null when the vote counted.
     */
    public function vote(User $user, int $nomineeId): ?string
    {
        if (! $this->isOpen()) {
            return 'Voting is closed.';
        }

        $nominee = DB::table('ballot_nominees')->join('ballot_categories', 'ballot_categories.id', '=', 'ballot_nominees.ballot_category_id')
            ->where('ballot_categories.ballot_id', $this->id)->where('ballot_nominees.id', $nomineeId)
            ->select('ballot_nominees.id', 'ballot_nominees.ballot_category_id')->first();
        if (! $nominee) {
            return 'That isn’t on this ballot.';
        }

        try {
            DB::table('ballot_votes')->insert(['ballot_category_id' => $nominee->ballot_category_id, 'ballot_nominee_id' => $nominee->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        } catch (QueryException) {
            return 'You already voted in this category.';
        }

        return null;
    }

    /**
     * Results as CSV for the magazine: every nominee with its votes, winners first.
     */
    public function csv(): string
    {
        // Spreadsheet apps run cells that start with these as formulas.
        $cell = fn ($v) => '"'.str_replace('"', '""', preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v).'"';
        $rows = [['Category', 'Rank', 'Place', 'Neighborhood', 'Votes']];

        foreach ($this->categories() as $c) {
            foreach ($c->nominees as $i => $n) {
                $rows[] = [$c->name, $i + 1, $n->name, $n->neighborhood, $n->votes ?? 0];
            }
        }

        return collect($rows)->map(fn ($row) => implode(',', array_map($cell, $row)))->join("\n")."\n";
    }
}
