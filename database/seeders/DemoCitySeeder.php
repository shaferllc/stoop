<?php

namespace Database\Seeders;

use App\Models\Appeal;
use App\Models\CityEvent;
use App\Models\Contest;
use App\Models\Game;
use App\Models\Penalty;
use App\Models\Place;
use App\Models\Report;
use App\Models\Sponsorship;
use App\Models\User;
use App\Notifications\StoopActivity;
use App\Support\DailyQuiz;
use App\Support\Magazine;
use App\Support\Messaging;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A month of sample activity so every feature has something to show: about 60 neighbors,
 * hundreds of dispatches and replies across the Metro map, places and passports, polls,
 * quizzes, rivalries, events, game picks, messages and a moderation queue.
 *
 * Adds to the database without wiping it: php artisan db:seed --class=DemoCitySeeder
 * Everyone and everything here is invented. Places carry "(sample)" list names.
 */
class DemoCitySeeder extends Seeder
{
    protected const FIRST = ['Maya', 'Jordan', 'Priya', 'Dre', 'Ana', 'Sam', 'Keisha', 'Marcus', 'Elena', 'Tomás', 'Grace', 'Malik', 'Hana', 'Owen', 'Bisrat', 'Lucía', 'Devon', 'Imani', 'Caleb', 'Nadia', 'Rafael', 'Zoe', 'Andre', 'Mei', 'Theo', 'Aisha', 'Ben', 'Sofia', 'Kofi', 'Leah', 'Jamal', 'Ruth', 'Diego', 'Tasha', 'Henry', 'Yonas', 'Clara', 'Darnell', 'Ivy', 'Mateo', 'Nia', 'Felix', 'Selam', 'Quinn', 'Rosa', 'Terrence', 'Ellie', 'Omar', 'June', 'Xavier', 'Bea', 'Kwame', 'Lily', 'Reggie', 'Amara', 'Gus', 'Tiana', 'Paul', 'Dahlia', 'Wes'];

    protected const LAST = ['R.', 'T.', 'K.', 'W.', 'L.', 'O.', 'M.', 'B.', 'S.', 'G.', 'H.', 'P.', 'C.', 'D.', 'F.', 'J.', 'N.', 'A.', 'V.', 'E.'];

    protected const DISHES = ['half-smoke', 'pupusas', 'jumbo slice', 'mumbo wings', 'injera platter', 'pho', 'banh mi', 'crab cake', 'bagel', 'tacos al pastor', 'fried chicken', 'dumplings', 'shawarma', 'ramen', 'fish fry'];

    protected const POSTS = [
        'red' => [
            'Finally tried the {dish} at {place}. Worth the wait, get there before noon.',
            'Hot take: {place} has the best {dish} in the city and it isn’t close.',
            '{place} changed their menu. The {dish} is still on it, thank goodness.',
            'Line at {place} is down the block again. Is it the new {dish}?',
            'Took my parents to {place}. They’re still talking about the {dish}.',
            'Cash only at {place}, learned that the hard way. The {dish} was worth the ATM trip.',
            'Brunch spot recs near {stop}? Somewhere that takes reservations for six.',
            'The {dish} at {place} tonight was perfect. Ask for extra sauce.',
        ],
        'orange' => [
            'Free go-go show in the park by {stop} tonight. Bring a chair.',
            'Trivia at the bar near {stop} is back on Tuesdays. Our team needs a sports person.',
            'Anyone going to the jazz night by {stop} Friday? Looking for a group.',
            'The rooftop near {stop} is open again and the view is ridiculous.',
            'Comedy open mic near {stop} was packed. Two of the sets were genuinely great.',
            'Late-night dumplings after the show by {stop} hit different.',
        ],
        'blue' => [
            'The new exhibit at the Smithsonian is worth a weekday visit before the crowds.',
            'Library branch near {stop} has a free film series this month.',
            'Spotted a mural going up by {stop}. Anyone know the artist?',
            'Bookstore by {stop} is doing a signing Saturday. Small room, get there early.',
            'Neighborhood history walk near {stop} on Sunday. Our guide knew everything.',
            'The little free library on our block by {stop} is overflowing. Come take some books.',
        ],
        'green' => [
            'Rowhouse on our street by {stop} sold in four days. Over asking. Wild.',
            'Anyone appealed their property assessment this year? Ours near {stop} jumped a lot.',
            'New building going up by {stop}. Hoping for a grocery store on the ground floor.',
            'Rent renewal came in near {stop}. Up again. Anyone negotiate successfully?',
            'Our block by {stop} is finally getting new sidewalks. Only took three years.',
            'Basement apartment tips? Moving near {stop} and it’s my first DC basement.',
        ],
        'yellow' => [
            'Best dentist near {stop} who takes walk-ins?',
            'Where can I get a bike tuned up near {stop} this week?',
            'Quiet coffee shop near {stop} with outlets and decent wifi?',
            'Good pediatrician near {stop}? New parents here.',
            'Is the pool near {stop} open in the fall at all?',
            'Who fixes old radiators? Ours near {stop} sounds like a drum line.',
            'Best place near {stop} to watch the game with a big group?',
            'Dog-friendly patio near {stop} that isn’t packed on weekends?',
        ],
        'silver' => [
            'Traffic on the Beltway this morning was something else. Took Metro from {stop} instead.',
            'Farmers market by {stop} has the good apples now.',
            'New trail connection near {stop} is open. Great for a Saturday ride.',
            'Parking at {stop} filled up by 7:45 today. Plan accordingly.',
            'Anyone know when the new library by {stop} opens?',
        ],
    ];

    protected const REPLIES = [
        'Agree completely.', 'Went last week, can confirm.', 'Adding this to my list.', 'Thanks for the tip.',
        'The one on the corner is better, fight me.', 'Saving this.', 'Is it still cash only?', 'This is the way.',
        'We should do a Stoop meetup there.', 'My neighbor swears by it.', 'Been going for years, still great.',
        'Can confirm, the line moves fast.', 'Try going on a weekday.', 'Same thing happened on our block.',
    ];

    protected const ANSWERS = [
        'The place on 14th takes walk-ins most mornings. Ask for the front desk manager.',
        'Try the shop by the park. Fast, fair prices, and they’ll tell you if it isn’t worth fixing.',
        'There’s a spot two blocks north with big tables and outlets everywhere.',
        'We love ours. Small practice, they call you back the same day.',
        'Call the city’s parks line, the fall schedule changes every year.',
        'The bar on the corner has six TVs and doesn’t mind big groups if you call ahead.',
    ];

    public function run(): void
    {
        // All or nothing, so a failure halfway never leaves half a city behind.
        DB::transaction(fn () => $this->seed());

        Cache::flush();
    }

    protected function seed(): void
    {
        mt_srand(2026);
        $now = now();

        $people = $this->people();
        $places = $this->places();
        $stations = array_keys(config('stoop.stations'));
        $popular = ['E04', 'E05', 'E03', 'E02', 'F05', 'F04', 'D06', 'D05', 'A03', 'A02', 'B35', 'B03', 'K02', 'K04', 'C13', 'C12', 'B05', 'B07', 'A04', 'A05', 'B08', 'A09', 'F06', 'F07', 'C04'];
        $pick = fn ($a) => collect($a)->values()[mt_rand(0, count($a) - 1)];
        $stop = fn () => mt_rand(1, 100) <= 80 ? $pick($popular) : $pick($stations);
        $at = fn (int $maxDays) => $now->copy()->subMinutes((int) (($maxDays * 1440) * (mt_rand(0, 1000) / 1000) ** 1.6));

        // Follows: everyone follows 6 to 20 people, plus the house accounts.
        $houses = User::whereIn('username', ['stoop', 'magazine'])->pluck('id')->all();
        foreach ($people as $person) {
            $person->following()->syncWithoutDetaching(array_merge($houses, collect($people)->where('id', '!=', $person->id)->random(mt_rand(6, 20))->pluck('id')->all()));
        }

        $posts = collect();
        $file = function (User $user, array $attrs, CarbonInterface $when) use (&$posts) {
            $d = $user->dispatches()->create($attrs + ['type' => 'dispatch', 'status' => 'approved']);
            $d->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
            $posts->push($d);

            return $d;
        };

        // About 450 dispatches over the last month, weighted toward recent days.
        foreach (range(1, 450) as $i) {
            $author = $pick($people);
            $line = $pick(['red', 'red', 'red', 'orange', 'orange', 'blue', 'green', 'green', 'yellow', 'yellow', 'yellow', 'silver']);
            $code = $line === 'silver' ? $pick(['B08', 'A09', 'C13', 'C12', 'K04', 'K02', 'N02', 'C08', 'B10', 'A14']) : $stop();
            $place = $line === 'red' ? $pick($places) : null;
            $body = strtr($pick(self::POSTS[$line]), ['{dish}' => $pick(self::DISHES), '{place}' => $place?->name ?? 'the place on the corner', '{stop}' => config("stoop.stations.$code")]);
            $type = match (true) {
                $line === 'yellow' => 'ask',
                $line === 'red' && mt_rand(1, 3) === 1 => 'been',
                $line === 'orange' && mt_rand(1, 3) === 1 => 'happening',
                default => 'dispatch',
            };

            $d = $file($author, [
                'type' => $type, 'line' => $line, 'stop' => $place?->stop ?? $code, 'body' => $body,
                'place_id' => $place?->id,
                'happening_at' => $type === 'happening' ? $pick(['Tonight · 8pm', 'Friday · 7pm', 'Saturday · 2pm', 'Sunday · 11am']) : null,
            ], $at(30));

            if ($type === 'been') {
                $author->marked()->syncWithoutDetaching([$d->id => ['kind' => 'been']]);
            }
        }

        // Crime and safety posts, written the way the form asks.
        foreach (range(1, 8) as $i) {
            $file($pick($people), [
                'type' => 'safety', 'line' => 'blue', 'stop' => $stop(),
                'body' => $pick(['Car window broken overnight on our block.', 'Package taken from a stoop this afternoon.', 'Bike stolen from the rack by the library.', 'Someone trying car door handles around 2am.']),
                'safety' => ['when' => $pick(['Last night', 'Today, about 3pm', 'This morning']), 'block' => $pick(['1400 block', 'Corner by the park', 'Near the library']), 'person' => $pick(['Gray hoodie, black backpack, on a red bike', 'Tall, green jacket, carrying a large duffel bag'])],
            ], $at(20));
        }

        // Polls with real-looking votes.
        $polls = [
            ['Settle it: best late-night food in DC?', ['Half-smoke', 'Jumbo slice', 'Mumbo wings', 'Pupusas']],
            ['Which line has the best stations?', ['Red', 'Orange', 'Green', 'Silver']],
            ['Is Arlington part of DC?', ['Obviously', 'Absolutely not', 'Spiritually']],
            ['Best season in the city?', ['Cherry blossom spring', 'Swampy summer', 'Perfect fall', 'Snow day winter']],
            ['Stand right, walk left: enforce it?', ['Yes, strictly', 'Only at rush hour', 'Live and let live']],
            ['Best neighborhood for a first apartment?', ['Columbia Heights', 'Petworth', 'Navy Yard', 'NoMa']],
            ['How long is too long to wait for brunch?', ['15 minutes', '30 minutes', 'An hour', 'I bring a book']],
            ['Go-go or jazz on a Friday night?', ['Go-go', 'Jazz', 'Both, in that order']],
        ];
        foreach ($polls as [$question, $options]) {
            $poll = $file($pick($people), ['type' => 'poll', 'line' => 'yellow', 'body' => $question], $at(20));
            $poll->forceFill(['closes_at' => mt_rand(0, 1) ? $poll->created_at->copy()->addDays(3) : $now->copy()->addDays(2)])->saveQuietly();
            $opts = $poll->options()->createMany(array_map(fn ($o) => ['label' => $o], $options));
            foreach (collect($people)->random(mt_rand(20, 50)) as $voter) {
                DB::table('poll_votes')->insertOrIgnore(['dispatch_id' => $poll->id, 'poll_option_id' => $opts->random()->id, 'user_id' => $voter->id, 'created_at' => $poll->created_at, 'updated_at' => $poll->created_at]);
            }
        }

        // Replies, saves, Been heres, Going, mumbo sauce and half-smokes.
        $marks = [];
        foreach ($posts as $post) {
            $repliers = collect($people)->where('id', '!=', $post->user_id)->random(mt_rand(0, 100) < 65 ? mt_rand(1, 9) : 0);
            $first = null;
            foreach ($repliers as $replier) {
                $when = $post->created_at->copy()->addMinutes(mt_rand(5, 2000));
                if ($when->isFuture()) {
                    continue;
                }
                $reply = $replier->dispatches()->create([
                    'parent_id' => $post->id, 'line' => $post->line, 'status' => 'approved', 'type' => 'dispatch',
                    'body' => $post->type === 'ask' ? $pick(self::ANSWERS) : $pick(self::REPLIES),
                ]);
                $reply->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
                $first ??= $reply;
            }
            if ($post->type === 'ask' && $first && mt_rand(1, 2) === 1) {
                $post->forceFill(['best_answer_id' => $first->id])->saveQuietly();
            }

            foreach (collect($people)->where('id', '!=', $post->user_id)->random(mt_rand(0, 14)) as $fan) {
                $kind = match ($post->type) {
                    'been' => $pick(['been', 'been', 'save', 'mumbo']),
                    'happening' => $pick(['going', 'going', 'save']),
                    default => $pick(['save', 'mumbo', 'mumbo']),
                };
                $marks[] = ['user_id' => $fan->id, 'dispatch_id' => $post->id, 'kind' => $kind, 'created_at' => $post->created_at, 'updated_at' => $post->created_at];
                if ($fan->half_smokes_on && mt_rand(1, 6) === 1) {
                    DB::table('half_smokes')->insert(['giver_id' => $fan->id, 'dispatch_id' => $post->id, 'created_at' => $post->created_at]);
                }
            }
        }
        foreach (array_chunk($marks, 500) as $chunk) {
            DB::table('marks')->insertOrIgnore($chunk);
        }

        // Passport progress: several people are close to a stamp, two have one.
        foreach (collect($people)->random(12) as $i => $person) {
            foreach ($places->where('list_name', 'Cheap Eats (sample)')->take($i < 2 ? 10 : mt_rand(3, 9)) as $place) {
                $visit = $file($person, ['type' => 'been', 'line' => 'red', 'stop' => $place->stop, 'place_id' => $place->id, 'body' => 'Checked off '.$place->name.'. '.$pick(['Worth it.', 'Get the special.', 'Go early.'])], $at(25));
                $person->marked()->syncWithoutDetaching([$visit->id => ['kind' => 'been']]);
            }
        }

        $this->dailyQuiz($people, $now);
        $this->moments($people, $now, $pick);
        $this->messages($people);
        $this->moderation($people, $pick, $at);
        $this->sponsorships($places, $now);
        $this->notificationsForDemo($people);

    }

    /**
     * @return array<int, User>
     */
    protected function people(): array
    {
        $password = Hash::make('password');
        $people = [];

        foreach (self::FIRST as $i => $first) {
            $username = strtolower(preg_replace('/[^a-z]/i', '', $first)).'_'.strtolower(self::LAST[$i % count(self::LAST)][0]).($i + 10);
            $user = User::where('username', $username)->first();
            if (! $user) {
                $user = new User;
                $user->forceFill([
                    'name' => $first.' '.self::LAST[$i % count(self::LAST)],
                    'username' => $username,
                    'email' => $username.'@example.com',
                    'password' => $password,
                    'email_verified_at' => now(),
                    'accepted_rules_at' => now(),
                    'is_native' => $i % 3 === 0,
                    'dc_since' => $i % 3 === 0 ? null : 2005 + ($i % 20),
                    'stops' => [array_rand(config('stoop.stations'))],
                    'half_smokes_on' => $i % 2 === 0,
                    'newsletter' => $i % 4 === 0,
                    'created_at' => now()->subDays(40 + $i * 2),
                ])->save();
            }
            $people[] = $user;
        }

        return $people;
    }

    protected function places(): Collection
    {
        $lists = [
            'Cheap Eats (sample)' => [
                ['Mount Pleasant Pupusa Window', 'Restaurant', 'Mount Pleasant', 'E04'], ['Georgia Ave Half-Smoke Stand', 'Restaurant', 'Petworth', 'E05'],
                ['U Street Jumbo Slice', 'Restaurant', 'U Street', 'E03'], ['Shaw Banh Mi Counter', 'Restaurant', 'Shaw', 'E02'],
                ['Navy Yard Taco Truck', 'Restaurant', 'Navy Yard', 'F05'], ['Eastern Market Bagel Co.', 'Bakery', 'Capitol Hill', 'D06'],
                ['Brookland Fish Fry', 'Restaurant', 'Brookland', 'B05'], ['Takoma Dumpling House', 'Restaurant', 'Takoma', 'B07'],
                ['Clarendon Shawarma', 'Restaurant', 'Clarendon', 'K02'], ['Anacostia Wing Spot', 'Restaurant', 'Anacostia', 'F06'],
                ['Silver Spring Injera Kitchen', 'Restaurant', 'Silver Spring', 'B08'], ['NoMa Ramen Bar', 'Restaurant', 'NoMa', 'B35'],
                ['Old Town Crab Shack', 'Restaurant', 'Old Town', 'C13'], ['Dupont Pho Corner', 'Restaurant', 'Dupont Circle', 'A03'],
            ],
            'Best Restaurants (sample)' => [
                ['The Judiciary Table', 'Restaurant', 'Penn Quarter', 'B02'], ['Petworth Supper Club', 'Restaurant', 'Petworth', 'E05'],
                ['Wharf Oyster House', 'Restaurant', 'The Wharf', 'F04'], ['Logan Circle Bistro', 'Restaurant', 'Logan Circle', 'E02'],
                ['Capitol Hill Tasting Room', 'Restaurant', 'Capitol Hill', 'D05'], ['Woodley Park Trattoria', 'Restaurant', 'Woodley Park', 'A04'],
                ['Bethesda Kitchen & Bar', 'Restaurant', 'Bethesda', 'A09'], ['Foggy Bottom Brasserie', 'Restaurant', 'Foggy Bottom', 'C04'],
            ],
            'Best Bars (sample)' => [
                ['Navy Yard Taproom', 'Bar', 'Navy Yard', 'F05'], ['Columbia Heights Rooftop', 'Bar', 'Columbia Heights', 'E04'],
                ['H Street Jazz Bar', 'Bar', 'H Street', 'B35'], ['Ballston Beer Garden', 'Bar', 'Ballston', 'K04'],
            ],
        ];

        $all = collect();
        foreach ($lists as $list => $places) {
            foreach ($places as $rank => [$name, $category, $hood, $stop]) {
                $all->push(Place::firstOrCreate(['name' => $name], [
                    'category' => $category, 'neighborhood' => $hood, 'stop' => $stop, 'list_name' => $list, 'list_rank' => $rank + 1,
                    'blurb' => 'Sample entry for local development.',
                ]));
            }
        }

        return $all;
    }

    /**
     * Two weeks of The Daily for most people, with a few long streaks.
     *
     * @param  array<int, User>  $people
     */
    protected function dailyQuiz(array $people, CarbonInterface $now): void
    {
        if (! DB::table('quiz_questions')->exists()) {
            $this->call(QuizQuestionSeeder::class);
        }

        foreach ($people as $i => $person) {
            $days = $i < 8 ? 21 : mt_rand(0, 14);
            foreach (range(1, $days) as $back) {
                if ($i >= 8 && mt_rand(1, 4) === 1) {
                    continue;
                }
                $day = $now->copy()->timezone(config('stoop.timezone'))->subDays($back)->startOfDay();
                $ids = DailyQuiz::questionIdsFor($day->toImmutable());
                $answers = array_map(fn () => ['choice' => 0, 'correct' => mt_rand(1, 100) <= 70], $ids);
                DB::table('quiz_attempts')->insertOrIgnore([
                    'user_id' => $person->id, 'day' => $day->toDateString(), 'question_ids' => json_encode($ids), 'answers' => json_encode($answers),
                    'score' => collect($answers)->where('correct', true)->count(), 'completed_at' => $day->copy()->addHours(8), 'created_at' => $day, 'updated_at' => $day,
                ]);
            }
        }
    }

    /**
     * @param  array<int, User>  $people
     */
    protected function moments(array $people, CarbonInterface $now, \Closure $pick): void
    {
        $halloween = CityEvent::firstOrCreate(['slug' => 'halloween-sample'], [
            'name' => 'Halloween Week', 'kind' => 'event', 'rating_label' => 'Candy rating',
            'description' => 'Rate the trick-or-treating on your block. Full-size bars get five stars.',
            'starts_at' => $now->copy()->subDays(3), 'ends_at' => $now->copy()->addDays(11),
        ]);
        foreach (collect($people)->random(18) as $person) {
            $d = $person->dispatches()->create([
                'type' => 'dispatch', 'line' => 'orange', 'status' => 'approved', 'stop' => $pick(['D06', 'E05', 'A04', 'B05', 'C13', 'E04']), 'event_id' => $halloween->id,
                'details' => ['rating' => mt_rand(2, 5)],
                'body' => $pick(['Whole block is decorated, the skeleton band is back.', 'Full-size bars at the yellow house on the corner!', 'Haunted stoop with a fog machine, kids loved it.', 'Mostly raisins on our street. Raisins.']),
            ]);
            $d->forceFill(['created_at' => $now->copy()->subHours(mt_rand(1, 70))])->saveQuietly();
        }

        CityEvent::firstOrCreate(['slug' => 'marathon-cheer-sample'], [
            'name' => 'Marine Corps Marathon cheer map', 'kind' => 'event',
            'description' => 'Post where you’re cheering from and how the runners look.',
            'starts_at' => $now->copy()->addDays(5)->setTime(6, 0), 'ends_at' => $now->copy()->addDays(5)->setTime(16, 0),
        ]);

        $sits = CityEvent::firstOrCreate(['slug' => 'stoop-sits-petworth-sample'], [
            'name' => 'Stoop Sits: Petworth', 'kind' => 'meetup', 'stop' => 'E05', 'checkin_code' => 'HALFSMK',
            'description' => 'Coffee and porch chairs on Upshur Street. Say hi to your neighbors.',
            'starts_at' => $now->copy()->subDays(6)->setTime(14, 0), 'ends_at' => $now->copy()->subDays(6)->setTime(16, 0),
        ]);
        $sits->attendees()->syncWithoutDetaching(collect($people)->random(14)->pluck('id')->all());

        CityEvent::firstOrCreate(['slug' => 'stoop-sits-capitol-hill-sample'], [
            'name' => 'Stoop Sits: Capitol Hill', 'kind' => 'meetup', 'stop' => 'D06', 'checkin_code' => 'MARKET',
            'description' => 'Meet by the market on Saturday morning.',
            'starts_at' => $now->copy()->addDays(4)->setTime(10, 0), 'ends_at' => $now->copy()->addDays(4)->setTime(12, 0),
        ]);

        $contest = Contest::firstOrCreate(['slug' => 'peak-bloom-sample'], [
            'title' => 'Peak Bloom 2027', 'question' => 'When will the cherry blossoms reach peak bloom?',
            'prize' => 'Dinner for two (sample prize)', 'closes_at' => Carbon::create(2027, 3, 1, 12), 'winners' => 3,
        ]);
        foreach (collect($people)->random(30) as $person) {
            $contest->entries()->firstOrCreate(['user_id' => $person->id], ['guess' => Carbon::create(2027, 3, mt_rand(18, 31))->toDateString()]);
        }

        $magazine = Magazine::account();
        $games = [
            ['Capitals', 'Rangers', true, $now->copy()->addDay()->setTime(19, 0), null],
            ['Wizards', 'Celtics', false, $now->copy()->subDays(2)->setTime(19, 30), 'opponent'],
            ['Commanders', 'Eagles', true, $now->copy()->subDays(5)->setTime(13, 0), 'team'],
            ['Spirit', 'Thorns', true, $now->copy()->addDays(3)->setTime(19, 0), null],
        ];
        foreach ($games as [$team, $opponent, $home, $start, $winner]) {
            if (Game::where(['team' => $team, 'opponent' => $opponent])->exists()) {
                continue;
            }
            $post = $magazine->dispatches()->create([
                'type' => 'poll', 'line' => 'orange', 'status' => 'approved', 'closes_at' => $winner ? $start : $start,
                'body' => "Game day: $team ".($home ? 'vs.' : 'at')." $opponent, ".$start->format('g:ia').'. Who wins? Right picks are worth 10 XP.',
            ]);
            // Posted the day before the game, but never in the future.
            $post->forceFill(['created_at' => $start->copy()->subDay()->min(now()->subHours(2))])->saveQuietly();
            [$a, $b] = $post->options()->createMany([['label' => $team], ['label' => $opponent]]);
            foreach (collect($people)->random(mt_rand(15, 35)) as $voter) {
                DB::table('poll_votes')->insertOrIgnore(['dispatch_id' => $post->id, 'poll_option_id' => mt_rand(1, 100) <= 65 ? $a->id : $b->id, 'user_id' => $voter->id, 'created_at' => $post->created_at, 'updated_at' => $post->created_at]);
            }
            Game::create(['team' => $team, 'opponent' => $opponent, 'home' => $home, 'starts_at' => $start, 'dispatch_id' => $post->id, 'team_option_id' => $a->id, 'opponent_option_id' => $b->id, 'winner' => $winner]);
        }
    }

    /**
     * @param  array<int, User>  $people
     */
    protected function messages(array $people): void
    {
        $demo = User::where('username', 'you')->first();
        $lines = ['Want to check out the new taco truck Saturday?', 'Yes! Noon?', 'Noon works. Meet by the Metro?', 'See you there.', 'Did you see the rivalry standings? We’re winning.', 'Columbia Heights forever.'];

        foreach (array_slice($people, 0, 6) as $i => $friend) {
            $a = $demo ?? $people[10];
            $friend->following()->syncWithoutDetaching([$a->id]);
            $a->following()->syncWithoutDetaching([$friend->id]);
            $conversation = Messaging::start($a, [$friend]);
            foreach (array_slice($lines, 0, 2 + $i % 4) as $j => $text) {
                $from = $j % 2 === 0 ? $friend : $a;
                $conversation->messages()->create(['user_id' => $from->id, 'body' => $text]);
            }
            $conversation->update(['last_message_at' => now()->subHours($i)]);
        }

        foreach ([1, 2, 3] as $i) {
            $people[$i]->following()->syncWithoutDetaching([$people[0]->id]);
        }
        $group = Messaging::start($people[0], [$people[1], $people[2], $people[3]], 'Brunch crew');
        foreach (['Brunch Sunday?', 'In.', 'In, but not before 11.', 'Booking for four at 11:30.'] as $j => $text) {
            $group->messages()->create(['user_id' => $people[$j]->id, 'body' => $text]);
        }
        $group->update(['last_message_at' => now()->subHours(2)]);
    }

    /**
     * A moderation queue to practice on: held posts, reports, a pile-on, a timeout and an appeal.
     *
     * @param  array<int, User>  $people
     */
    protected function moderation(array $people, \Closure $pick, \Closure $at): void
    {
        $troll = $people[count($people) - 1];

        $held = $troll->dispatches()->create(['type' => 'dispatch', 'line' => 'green', 'stop' => 'E05', 'status' => 'held', 'body' => 'People like them are ruining this neighborhood and should leave.', 'screening' => ['openai' => ['harassment' => 0.71, 'hate' => 0.38]]]);
        $held->forceFill(['created_at' => $at(2)])->saveQuietly();

        $reported = $troll->dispatches()->create(['type' => 'dispatch', 'line' => 'yellow', 'stop' => 'E04', 'status' => 'approved', 'body' => 'Anyone who parks like that on my street is going to regret it.']);
        foreach (collect($people)->random(2) as $reporter) {
            Report::firstOrCreate(['user_id' => $reporter->id, 'dispatch_id' => $reported->id], ['reason' => 'hate', 'note' => 'Sounds like a threat.']);
        }

        $spam = $people[count($people) - 2]->dispatches()->create(['type' => 'dispatch', 'line' => 'red', 'status' => 'hidden', 'body' => 'DM me for a can’t-miss real estate investing opportunity!!']);
        foreach (collect($people)->random(3) as $reporter) {
            Report::firstOrCreate(['user_id' => $reporter->id, 'dispatch_id' => $spam->id], ['reason' => 'spam']);
        }

        $removed = $troll->dispatches()->create(['type' => 'dispatch', 'line' => 'blue', 'status' => 'removed', 'moderation_reason' => 'Attacking people for who they are', 'screening' => ['openai' => ['hate' => 0.62], 'auto_removed' => 'hate'], 'body' => '(removed sample post)']);
        $penalty = Penalty::create(['user_id' => $troll->id, 'subject_hash' => $troll->subjectHash(), 'type' => 'timeout', 'reason' => 'Attacking people for who they are', 'dispatch_id' => $removed->id, 'ends_at' => now()->addDays(6)]);
        Appeal::firstOrCreate(['penalty_id' => $penalty->id], ['user_id' => $troll->id, 'dispatch_id' => $removed->id, 'body' => 'I was quoting a flyer someone left on my car so neighbors would know about it.']);
    }

    protected function sponsorships(Collection $places, CarbonInterface $now): void
    {
        Sponsorship::firstOrCreate(['headline' => 'Free checking for Columbia Heights (sample)'], [
            'sponsor' => 'Sample Credit Union', 'stop' => 'E04', 'body' => 'Open an account at our 14th Street branch this month.',
            'url' => 'https://example.com/credit-union', 'starts_on' => $now->copy()->subDays(5), 'ends_on' => $now->copy()->addDays(25),
            'impressions' => 1840, 'clicks' => 37,
        ]);
        Sponsorship::firstOrCreate(['headline' => 'Taco Tuesdays on the Red line (sample)'], [
            'sponsor' => 'Sample Taqueria', 'line' => 'red', 'place_id' => $places->first()?->id, 'body' => 'Two-dollar tacos every Tuesday.',
            'url' => 'https://example.com/tacos', 'starts_on' => $now->copy()->subDays(10), 'ends_on' => $now->copy()->addDays(20),
            'impressions' => 5210, 'clicks' => 122,
        ]);
    }

    /**
     * Give the demo account replies and followers to look at.
     *
     * @param  array<int, User>  $people
     */
    protected function notificationsForDemo(array $people): void
    {
        $demo = User::where('username', 'you')->first();
        if (! $demo) {
            return;
        }

        $post = $demo->dispatches()->create(['type' => 'ask', 'line' => 'yellow', 'stop' => 'D06', 'status' => 'approved', 'body' => 'Best place near Eastern Market for a birthday dinner for eight?']);
        foreach (array_slice($people, 0, 5) as $i => $person) {
            $reply = $person->dispatches()->create(['parent_id' => $post->id, 'type' => 'dispatch', 'line' => 'yellow', 'status' => 'approved', 'body' => self::ANSWERS[$i % count(self::ANSWERS)]]);
            $demo->notify(new StoopActivity('reply', $person, $reply));
        }
        foreach (array_slice($people, 5, 6) as $person) {
            $person->following()->syncWithoutDetaching([$demo->id]);
            $demo->notify(new StoopActivity('follow', $person));
        }
    }
}
