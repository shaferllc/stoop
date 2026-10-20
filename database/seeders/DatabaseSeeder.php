<?php

namespace Database\Seeders;

use App\Models\Dispatch;
use App\Models\User;
use App\Support\Bots;
use Illuminate\Database\Seeder;

/**
 * Sample neighbors and dispatches for local development. Not real people or posts.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $people = collect([
            'you' => ['name' => 'Demo User', 'email' => 'demo@example.com', 'dc_since' => 2019, 'stops' => ['D06', 'E05', 'C13']],
            'mayaeats' => ['name' => 'Maya R.', 'is_native' => true],
            'jt_hill' => ['name' => 'Jordan T.', 'dc_since' => 2017],
            'priyak' => ['name' => 'Priya K.'],
            'food' => ['name' => 'Food Desk', 'is_staff' => true, 'email' => 'food@example.com'],
            'samgoesout' => ['name' => 'Sam O.', 'is_native' => true],
            'oldtownana' => ['name' => 'Ana L.'],
            'dre_ward6' => ['name' => 'Dre W.', 'is_native' => true],
        ])->map(fn ($attrs, $username) => User::factory()->create([
            'username' => $username,
            'email' => $attrs['email'] ?? $username.'@example.com',
            ...collect($attrs)->except('email')->all(),
        ]));

        $post = function (string $who, array $attrs, int $minutesAgo) use ($people): Dispatch {
            $d = $people[$who]->dispatches()->create($attrs + ['type' => 'dispatch', 'status' => 'approved']);
            $d->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

            return $d;
        };
        $reply = fn (Dispatch $to, string $who, string $body, int $minutesAgo) => $post($who, ['parent_id' => $to->id, 'line' => $to->line, 'body' => $body], $minutesAgo);

        $p1 = $post('mayaeats', ['type' => 'been', 'line' => 'red', 'stop' => 'B02', 'body' => 'Went back to the #4 spot on the list last night. The list is right. The dessert course is not, fight me.'], 12);
        $reply($p1, 'dre_ward6', 'The dessert is the best part and I will not be taking questions.', 8);
        $reply($p1, 'priyak', 'Is it still impossible to get a table before 9?', 5);

        $p2 = $post('jt_hill', ['type' => 'poll', 'line' => 'yellow', 'body' => 'Settle it once and for all.'], 40);
        $p2->options()->createMany([['label' => 'Half-smoke'], ['label' => 'Mumbo wings'], ['label' => 'Jumbo slice (I’m sorry)']]);
        $reply($p2, 'samgoesout', 'Mumbo sauce on a half-smoke. Everyone wins.', 30);

        $p3 = $post('priyak', ['line' => 'green', 'stop' => 'E04', 'body' => 'Rowhouse on our block just listed at $1.4M with "original 1920s charm," which means the boiler. Anyone else’s assessment jump this year?'], 60);
        $reply($p3, 'dre_ward6', 'Ours went up a lot too. Appeal it, they settle more often than you’d think.', 50);

        $p4 = $post('food', ['type' => 'callout', 'line' => 'red', 'body' => 'We’re updating our cheap eats guide. Reply with the place we’re missing. The best replies get a call from a reporter.'], 120);
        $reply($p4, 'mayaeats', 'The pupusa window on Mt Pleasant St. Cash only, worth it.', 90);

        $post('samgoesout', ['type' => 'happening', 'line' => 'orange', 'stop' => 'F05', 'happening_at' => 'Tonight · 7:05pm', 'body' => 'Nats watch party near the park. Rain plan is the bar across the street.'], 180);
        $post('dre_ward6', ['line' => 'blue', 'stop' => 'D06', 'body' => 'Eastern Market Sunday flea is back to full size. The map vendor finally has the old ward maps.'], 300);
        $p7 = $post('oldtownana', ['line' => 'silver', 'stop' => 'C13', 'body' => 'King St at the waterfront is flooded again. Walk up Prince if you’re heading to the water taxi.'], 420);
        $reply($p7, 'jt_hill', 'Third time this month.', 360);
        $p8 = $post('jt_hill', ['type' => 'ask', 'line' => 'yellow', 'stop' => 'C02', 'body' => 'Quick lunch near McPherson that isn’t a salad chain?'], 1560);
        $reply($p8, 'mayaeats', 'The banh mi spot on 15th. In and out in 10 minutes.', 1500);

        // Some engagement so Rush Hour has something to rank.
        $people->except('you')->each(function (User $u) use ($p1, $p2) {
            $u->marked()->attach($p1->id, ['kind' => 'been']);
            $u->getConnection()->table('poll_votes')->insert([
                'dispatch_id' => $p2->id, 'poll_option_id' => $p2->options->random()->id, 'user_id' => $u->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $people['you']->following()->attach([$people['food']->id, $people['mayaeats']->id]);
        $people['you']->forceFill(['half_smokes_on' => true])->save();

        $stoop = Bots::account('stoop');
        Bots::account('dc');
        $people->each(fn (User $u) => $u->following()->syncWithoutDetaching([$stoop->id]));
    }
}
