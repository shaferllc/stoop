<?php

namespace Database\Seeders;

use App\Bots\Bot;
use App\Bots\Calm;
use App\Bots\History;
use App\Bots\WeatherBot;
use App\Bots\Weekend;
use App\Models\Appeal;
use App\Models\Author;
use App\Models\Ballot;
use App\Models\Contest;
use App\Models\Conversation;
use App\Models\Dispatch;
use App\Models\EditorThread;
use App\Models\Hunt;
use App\Models\Issue;
use App\Models\Penalty;
use App\Models\Place;
use App\Models\PlaceDeal;
use App\Models\QuoteRequest;
use App\Models\Report;
use App\Models\SpamCluster;
use App\Models\Tip;
use App\Models\User;
use App\Notifications\MagazineActivity;
use App\Notifications\StoopActivity;
use App\Support\Archive;
use App\Support\Bingo;
use App\Support\DailyQuiz;
use App\Support\HomeSales;
use App\Support\Magazine;
use App\Support\Rivalries;
use App\Support\SpamRings;
use App\Support\Stations;
use App\Support\WatchPhrases;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A busy week on Stoop with every feature in use, for demos: about 40 more neighbors (two
 * editors, a moderator and a sales staffer), a week of posts, replies, polls and Ask DC
 * answers on every line, the house bots, happenings, lost & found, places and open houses,
 * the games, the newsroom tools, a moderation queue (held posts, a spam ring, a risky
 * sign-up, an appeal) and fresh notifications for @tshafer.
 *
 * Adds to the database without wiping it, and only once (a site setting marks the run):
 * php artisan db:seed --class=ShowcaseSeeder
 * Everyone here is invented and every sample person logs in with the password "password".
 * Sample links point at example.com; nothing calls an outside service.
 */
class ShowcaseSeeder extends Seeder
{
    public const MARKER = 'showcase_seeded_at';

    /** [name, username, home stop, roles] */
    protected const PEOPLE = [
        ['Margot Ellison', 'margot_ellison', 'A04', ['editor']], ['Desmond Pryor', 'desmond_pryor', 'B35', ['editor']],
        ['Renata Okafor', 'renata_okafor', 'E05', ['moderator']], ['Calvin Brooks', 'calvin_brooks', 'F05', ['sales']],
        ['Harriet Vu', 'hattie_vu', 'E04', null], ['Luis Carmona', 'luis_carmona', 'E04', null], ['Adaeze Nwosu', 'adaeze_n', 'B05', null],
        ['Pete Sandoval', 'pete_sandoval', 'D06', null], ['Wren Halloway', 'wren_h', 'A03', null], ['Marisol Ortega', 'marisol_o', 'E04', null],
        ['Jasper Cole', 'jasper_cole', 'F05', null], ['Delia Haskins', 'delia_haskins', 'F06', null], ['Kenji Mori', 'kenji_mori', 'K02', null],
        ['Bernadette Ruiz', 'bernie_ruiz', 'E02', null], ['Solomon Tesfaye', 'sol_tesfaye', 'E03', null], ['Frankie Liu', 'frankie_liu', 'B03', null],
        ['Gwen Abernathy', 'gwen_abernathy', 'A05', null], ['Rashad Greene', 'rashad_greene', 'F07', null], ['Paloma Reyes', 'paloma_reyes', 'B08', null],
        ['Tobias Grant', 'tobias_grant', 'D05', null], ['Yetunde Bello', 'yetunde_bello', 'E05', null], ['Ezra Wolfe', 'ezra_wolfe', 'C13', null],
        ['Meg Dunn', 'meg_dunn', 'C12', null], ['Ike Ferreira', 'ike_ferreira', 'A09', null], ['Noor Haddad', 'noor_haddad', 'K04', null],
        ['Vince Albright', 'vince_albright', 'F04', null], ['Celeste Moreau', 'celeste_moreau', 'C04', null], ['Dominic Ibarra', 'dom_ibarra', 'E05', null],
        ['Lorraine Tate', 'lorraine_tate', 'F06', null], ['Abebe Girma', 'abebe_girma', 'E03', null], ['Hollis Park', 'hollis_park', 'B35', null],
        ['Imogen Shaw', 'imogen_shaw', 'E02', null], ['Curtis Bland', 'curtis_bland', 'D06', null], ['Sunny Okoye', 'sunny_okoye', 'F05', null],
        ['Pilar Vega', 'pilar_vega', 'E04', null], ['Graham Whitfield', 'graham_w', 'A04', null], ['Tamsin Rowe', 'tamsin_rowe', 'B05', null],
        ['Odell Jenkins', 'odell_jenkins', 'F07', null], ['Astrid Lindqvist', 'astrid_l', 'K02', null], ['Quincy Adeyemi', 'quincy_adeyemi', 'B03', null],
    ];

    /** Fresh top-level posts: [line, stop, body]. Every body is different, so none look like a spam ring. */
    protected const POSTS = [
        ['red', 'E04', 'The pupusa window on Mount Pleasant St had a line of twelve at 8am and nobody minded. Loroco and cheese, every time.'],
        ['red', 'E05', 'Upshur Street on a Tuesday night is the most underrated dinner block in the city. Change my mind.'],
        ['red', 'D06', 'Eastern Market blueberry pancakes before the crowds hit. Got there at 8:15 and walked right up.'],
        ['red', 'F05', 'Pre-game half-smoke outside Nats Park, extra onions. October baseball energy even without October baseball.'],
        ['red', 'E03', 'Late-night chili half-smoke on U Street after a show. Some traditions don’t need improving.'],
        ['red', 'B35', 'Union Market’s new dumpling stall does a chili crisp that made me sit down on the curb.'],
        ['red', 'E02', 'Shaw bakery sold out of morning buns by 9:40. Set your alarms, neighbors.'],
        ['red', 'A03', 'Rainy-night pho in Dupont. The broth fixes everything, including the Red Line.'],
        ['red', 'F06', 'Fish fry on MLK Ave was perfect: whiting, hot sauce, white bread. A proper Friday.'],
        ['red', 'K02', 'Clarendon shawarma spot is now open till 2am on weekends. Dangerous information.'],
        ['red', 'C13', 'Crab cakes on King Street at an outside table with a breeze off the river. October in Old Town is unbeatable.'],
        ['red', 'B07', 'Takoma farmers market had the first good apples of the season. Stayman Winesap, if you know, you know.'],
        ['red', 'A04', 'The Woodley Park trattoria put cacio e pepe back on the menu. Ran there straight from the Metro.'],
        ['red', 'B05', 'Brookland has a new coffee window by the arts walk. Great cortado, and the barista knew every dog by name.'],
        ['red', 'E04', 'Mumbo sauce ranking update: the carryout on 14th still wins. Sweet, tangy, a little heat.'],
        ['red', 'D05', 'Capitol Hill neighbors: the tasting room’s happy hour oysters are a dollar off until six.'],
        ['orange', 'E03', 'Go-go Wednesdays on U Street are back and the pocket was deep tonight. Bring your dancing shoes.'],
        ['orange', 'F04', 'Sunset at the Wharf with the free concert on the pier. Half the crowd danced, the other half ate ice cream.'],
        ['orange', 'B35', 'Trivia at the NoMa taproom: our team “Stand Right Walk Left” took second. The sports round got us again.'],
        ['orange', 'A04', 'Jazz in the courtyard by Woodley Park, chairs on the lawn, a full moon. DC can be so pretty.'],
        ['orange', 'C04', 'The Kennedy Center’s free 6pm show tonight was a brass band and it ruled.'],
        ['orange', 'F05', 'Navy Yard riverwalk was packed tonight. Bring a blanket and grab a spot on the steps early.'],
        ['orange', 'E05', 'Porch concert on Upshur this Sunday. Neighbors bring snacks, the band brings a tuba.'],
        ['orange', 'K04', 'Ballston beer garden has a Thursday bluegrass jam. Showed up with a banjo and got pulled on stage.'],
        ['orange', 'E02', 'The Howard Theatre marquee lit up tonight made me stop and take a picture. Still gives me chills.'],
        ['orange', 'D06', 'Barracks Row bar crawl for a neighbor’s birthday. Everyone was in bed by 11. We are old now.'],
        ['blue', 'D02', 'The Hirshhorn sculpture garden at 10am on a weekday is basically your own private museum.'],
        ['blue', 'E04', 'A mural is going up on the side of the laundromat on Irving St. The artist said it’ll be done by Friday.'],
        ['blue', 'A05', 'Cleveland Park library had a used book sale and I came home with nine paperbacks. No regrets.'],
        ['blue', 'F06', 'The Anacostia Community Museum’s exhibit on neighborhood gardens is lovely. Free, and the staff are wonderful.'],
        ['blue', 'B05', 'Monroe Street arts walk studios are open Saturday. Picked up a print of the Brookland bridge.'],
        ['blue', 'D02', 'Took my nephew to the Air and Space Museum. He stood under the Wright Flyer for twenty minutes.'],
        ['blue', 'B03', 'Union Station’s main hall at night, almost empty, is one of the most beautiful rooms in America.'],
        ['blue', 'E02', 'Our history walk around Shaw ended at the Carter G. Woodson home. The guide grew up two blocks away.'],
        ['blue', 'C04', 'Free Shakespeare reading in the park by Foggy Bottom. Half the audience were students mouthing along.'],
        ['blue', 'F04', 'The Arena Stage preview tonight was sold out but they had rush seats. Ask at the box office.'],
        ['green', 'E05', 'Rowhouse two doors down sold in five days, over asking. Petworth porches are in demand.'],
        ['green', 'F05', 'Navy Yard rents went up again at renewal. Anyone had luck negotiating with one of the big buildings?'],
        ['green', 'B04', 'The new building by Rhode Island Ave is finally getting a grocery store on the ground floor. Opening in spring.'],
        ['green', 'D07', 'Our block by Potomac Ave got new street trees this week. The DDOT crew was fast and friendly.'],
        ['green', 'E04', 'First-time buyer here. Any DC first-time buyer program tips? The paperwork has me spinning.'],
        ['green', 'F07', 'Congress Heights neighbors, the community land trust info session is Thursday at the library.'],
        ['green', 'A07', 'Our Tenleytown condo board finally approved solar panels on the roof. Took two years and a lot of cookies.'],
        ['green', 'E01', 'Basement apartment hunting near Mt Vernon Square. Ceiling height matters, I am six foot three.'],
        ['silver', 'B08', 'Silver Spring’s Fenton Street market was packed. The Ethiopian coffee ceremony stall was the highlight.'],
        ['silver', 'C05', 'Arlington neighbors: the trail detour by Rosslyn is finally gone. Smooth sailing to the bridge.'],
        ['silver', 'C13', 'Old Town waterfront at sunrise, the river like glass. Worth the early alarm.'],
        ['silver', 'A09', 'Bethesda Row has a pop-up bookstore through November. The kids section is great.'],
        ['silver', 'N02', 'Silver Line to Tysons on a Saturday is shockingly pleasant. Empty car, good podcast.'],
        ['silver', 'B10', 'Wheaton’s night market had the best Peruvian chicken I’ve had outside Lima.'],
        ['silver', 'E09', 'The College Park trolley trail is perfect for a fall ride. The leaves are just starting to turn.'],
        ['silver', 'C10', 'National Airport by Metro for a 7am flight: left home at 5:50, at the gate by 6:30. Bless.'],
    ];

    /** Short replies (under six words, too generic for the spam detector to compare). */
    protected const CHEERS = ['Saving this.', 'Adding to my list.', 'Same on our block.', 'Thanks, neighbor!', 'Can confirm.', 'This is the way.',
        'Love this city.', 'Going this weekend.', 'Ooh, good call.', 'Seconding this.', 'Noted, thank you!', 'Best news all week.', 'Wait, really?', 'Ha, so true.'];

    /** Ask DC: [stop, question, [answers…], best answer index or null] */
    protected const ASKS = [
        ['E04', 'Where can I get a bike tuned up near Columbia Heights this week? My gears keep skipping.', ['The bike co-op on 14th has open shop hours Wednesday nights. They’ll walk you through fixing it yourself.', 'The shop by the Tivoli turned mine around in two days.', 'Check the derailleur hanger first. Mine was just bent.'], 0],
        ['D06', 'Good pediatric dentist near Capitol Hill who’s patient with nervous kids?', ['Ours on Pennsylvania Ave SE gives out stickers and lets kids hold the mirror. Total game changer.', 'Second the one on Penn. They also do Saturday mornings.'], 0],
        ['E05', 'Who fixes old radiators in Petworth? Ours bangs like a drum line at 5am.', ['Bleed them first! A radiator key costs two dollars at the hardware store on Georgia.', 'We used a family plumbing outfit from Brightwood. Fair price, showed up on time.', 'Same problem every October. Bleeding fixed it for us.'], 1],
        ['A03', 'Quiet coffee shop near Dupont with outlets where nobody minds a laptop all afternoon?', ['The one inside the bookstore on Connecticut has a back room with plugs at every table.', 'Try the library on R Street, free wifi and real silence.'], 0],
        ['F05', 'Where do Navy Yard dog owners go when the dog park is closed for maintenance?', ['Yards Park lawn early mornings, everyone’s friendly as long as you clean up.', 'Garfield Park has a fenced run, about fifteen minutes on foot.'], 1],
        ['B05', 'Is there anywhere in Brookland to swim laps in the fall?', ['The rec center indoor pool on Taylor St is open year round. Check the lap swim schedule online.', 'Bring a lock, the lockers don’t come with one.'], 0],
        ['E02', 'Reliable tailor near Shaw who can hem a suit by Friday?', ['The tailor on 7th did my pants in a day. Cash discount too.', 'Call ahead, they get slammed before wedding season.'], 0],
        ['K04', 'Anyone know a Ballston spot to watch the Caps with a group of twelve?', ['The beer garden takes reservations for big groups on game nights.', 'Go early, the projector side fills up by puck drop.'], null],
        ['D02', 'Least crowded Smithsonian on a Saturday with a toddler?', ['The Renwick! Small, calm and the installations blow little minds.', 'National Museum of the American Indian, and the café is excellent.', 'Hirshhorn plaza when the fountain is on. Toddler heaven.'], 0],
        ['F06', 'Where can I get rid of an old mattress east of the river without paying a fortune?', ['DC bulk trash pickup is free, you just have to book it through 311.', 'Book early, slots fill a week out.'], 0],
        ['B07', 'Best place near Takoma for an adult beginner to learn to swim?', [], null],
    ];

    /** [question, options] */
    protected const POLLS = [
        ['Half-smoke: chili or no chili?', ['Chili, always', 'Just mustard and onions', 'One of each']],
        ['Best fall walk in the city?', ['Rock Creek Park', 'C&O Canal towpath', 'The Arboretum', 'Anacostia Riverwalk']],
        ['Which Metro escalator ride is the most dramatic?', ['Wheaton (the longest)', 'Rosslyn', 'Dupont north exit', 'Woodley Park']],
        ['Mumbo sauce goes on…', ['Wings', 'Fries', 'Everything']],
        ['Should the DC Circulator come back?', ['Yes, all routes', 'Just the Mall route', 'Buses are fine as is']],
        ['Most DC sandwich?', ['Banh mi', 'Italian sub', 'Fried fish on white bread', 'A half-smoke counts']],
    ];

    /** Threads with replies to replies: [line, stop, root, [[depth, body], …]]. Depth 1 replies to the root, 2 to the last depth-1 reply… */
    protected const CHAINS = [
        ['blue', 'E05', 'Petworth library is getting a reading garden out back. Who wants to help plant on Saturday?', [
            [1, 'I’m in! Do we need to bring tools, or will the library have them?'], [2, 'They have shovels and gloves. Bring water and a hat.'],
            [3, 'Perfect. I’m bringing my kid, she loves dirt more than anything.'], [4, 'Kids get first pick of the sunflower seedlings, promise.'],
            [1, 'Can someone who doesn’t garden still help? I can carry mulch.'], [2, 'Mulch carriers are the true heroes of every garden day.'],
            [3, 'Putting “mulch hero” on my résumé.'],
        ]],
        ['orange', 'F06', 'The roller rink in Anacostia Park is open late on Fridays this month. Who wants to start a Stoop skate night?', [
            [1, 'Haven’t skated since middle school. Is there a beginners’ corner?'], [2, 'There’s a wall to hold onto and everyone is very encouraging.'],
            [3, 'Okay, I’m coming. If I fall, film it for the group chat.'], [4, 'Deal. I’ll bring knee pads for both of us.'],
            [1, 'Rentals were five bucks last time and the DJ played go-go all night.'], [2, 'Go-go on skates is the most DC sentence ever written.'],
        ]],
        ['orange', 'B05', 'Brookland neighbors: should we do a stoop sale on the whole block next Saturday?', [
            [1, 'Yes! I have a bread machine that has never made bread.'], [2, 'I will trade you a fondue set that has never made fondue.'],
            [3, 'This is how we end up hosting a bread and fondue party.'], [4, 'Honestly? I’d RSVP Going.'],
            [1, 'Can we ask the church on the corner to let us use their lawn if it rains?'], [2, 'I know the pastor, I’ll ask after Sunday service.'],
        ]],
        ['red', 'A04', 'Settle a debate: is a jumbo slice a food or an experience?', [
            [1, 'An experience, and the experience is folding it in half on the sidewalk at 2am.'], [2, 'Folding is mandatory. Plates are for tourists.'],
            [3, 'I have used a plate. I am a tourist in my own city.'], [4, 'You can be redeemed. Fold the next one.'],
            [1, 'Food. Very large food. A meal for one or a snack for three.'],
        ]],
        ['green', 'D07', 'We started a tool library in the alley shed behind our rowhouse. Ladder, drill, tile saw, a very serious leaf blower.', [
            [1, 'This is the most neighborly thing I’ve read all week. Can I donate a pressure washer?'], [2, 'Yes please! We’ll put your name on it with a label maker.'],
            [3, 'The label maker is also available for borrowing, I assume?'], [4, 'Of course. It’s the most popular item by far.'],
        ]],
    ];

    /** The pile-on: the root and sixteen strangers piling in. */
    protected const PILE_ON = ['E02', 'Unpopular opinion: the new protected bike lane on 9th Street is great and I’m glad they built it.', [
        'Tell that to everyone who used to park there on Sundays.', 'Protected lanes save lives. This isn’t complicated.', 'Nobody asked the businesses on the block.',
        'I bike it every day now and finally feel safe doing it.', 'The delivery trucks just double park anyway, so what changed?', 'Bike lanes are for people who don’t have kids to drive around.',
        'My kids bike to school on it. So.', 'Every city that builds these ends up loving them. Give it a year.', 'Did anyone measure whether traffic got worse?',
        'Yes, DDOT published the counts last month. Look it up.', 'Ah yes, the famously reliable DDOT counts.', 'We had this exact fight about 15th Street a decade ago.',
        'And now nobody remembers it wasn’t always there.', 'Can we all agree the potholes are the real enemy?', 'Hard agree on the potholes, at least.',
        'I come to Stoop for pupusa recs, not this.',
    ]];

    protected CarbonInterface $now;

    protected CarbonImmutable $week;

    protected Collection $crowd;

    /** @var array<string, User> */
    protected array $cast = [];

    /** @var array<int, array<string, mixed>> */
    protected array $marks = [];

    /** @var array<string, int> */
    public array $counts = [];

    public function run(): void
    {
        if (DB::table('site_settings')->where('key', self::MARKER)->exists()) {
            $this->command?->info('The showcase is already seeded ('.DB::table('site_settings')->where('key', self::MARKER)->value('value').'). Nothing to do.');

            return;
        }

        $started = microtime(true);

        // All or nothing, so a failure halfway never leaves half a showcase behind.
        DB::transaction(fn () => $this->seed());
        Cache::flush();

        $this->command?->info(sprintf('Showcase seeded in %.1fs: %s', microtime(true) - $started, collect($this->counts)->map(fn ($n, $k) => "$n $k")->join(', ')));
    }

    protected function seed(): void
    {
        mt_srand(1789);
        $this->now = now();
        $this->week = Rivalries::weekStart();

        $this->people();
        $places = $this->places();

        $posts = $this->posts();
        $this->chains();
        $asks = $this->asks();
        $this->polls();
        $happenings = $this->happenings($places);
        $this->lostAndFound();
        $this->checkIns($places);
        $this->openHouses();
        $this->bots($posts, $asks, $happenings);
        $this->games($places);
        $this->editorial($places);
        $this->safety();
        $this->messages();
        $this->owner();

        foreach (array_chunk($this->marks, 400) as $chunk) {
            DB::table('marks')->insertOrIgnore($chunk);
        }
        $this->counts['marks'] = count($this->marks);

        DB::table('site_settings')->insert(['key' => self::MARKER, 'value' => $this->now->toIso8601String(), 'created_at' => $this->now, 'updated_at' => $this->now]);
    }

    // People, places and the everyday feed

    protected function people(): void
    {
        $password = Hash::make('password');

        foreach (self::PEOPLE as $i => [$name, $username, $stop, $roles]) {
            $this->cast[$username] = $this->person($name, $username, $stop, $password, [
                'roles' => $roles, 'is_staff' => (bool) $roles,
                'is_native' => $i % 3 === 0, 'dc_since' => $i % 3 === 0 ? null : 1998 + ($i % 27),
                'half_smokes_on' => $i % 2 === 0, 'newsletter' => $i % 3 === 1,
                // Months on Stoop, so the spam detector never mistakes them for new accounts.
                'created_at' => $this->now->copy()->subDays(30 + $i * 6),
            ]);
        }

        // Earlier sample neighbors (DemoCitySeeder) join in when they're here.
        $earlier = User::where('is_staff', false)->where('is_bot', false)->where('email', 'like', '%@example.com')
            ->whereNotIn('username', ['you', 'tshafer', ...array_keys($this->cast)])->where('created_at', '<', $this->now->copy()->subWeeks(2))
            ->orderBy('id')->take(60)->get();

        $this->crowd = collect($this->cast)->reject(fn (User $u) => $u->is_staff)->values()->concat($earlier);
        $this->counts['people'] = count($this->cast);

        // Everyone follows a handful of neighbors and the house accounts.
        $houses = User::whereIn('username', ['stoop', 'magazine'])->pluck('id')->all();
        $rows = [];
        foreach ($this->crowd->concat(collect($this->cast)->where('is_staff', true)) as $person) {
            foreach ([...$houses, ...$this->crowd->where('id', '!=', $person->id)->random(mt_rand(6, 16))->pluck('id')->all()] as $id) {
                $rows[] = ['follower_id' => $person->id, 'followee_id' => $id, 'created_at' => $this->recent(), 'updated_at' => $this->now];
            }
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('follows')->insertOrIgnore($chunk);
        }
        $this->counts['follows'] = count($rows);
    }

    protected function person(string $name, string $username, ?string $stop, string $password, array $extra = []): User
    {
        $username = User::where('username', $username)->exists() ? $username.'_dc' : $username;

        $user = new User;
        $user->forceFill([
            'name' => $name, 'username' => $username, 'email' => $username.'@example.com', 'password' => $password,
            'email_verified_at' => $this->now, 'accepted_rules_at' => $this->now, 'stops' => $stop ? [$stop] : null,
            ...$extra,
        ])->save();

        return $user;
    }

    /**
     * The places to use: the magazine's lists if they're here, plus a few sample ones when the list is short.
     */
    protected function places(): Collection
    {
        $samples = [['Rowhouse Pupuseria (sample)', 'Restaurant', 'Columbia Heights', 'E04', 'Cheap Eats (sample)'], ['Upshur Half-Smoke Stand (sample)', 'Restaurant', 'Petworth', 'E05', 'Cheap Eats (sample)'],
            ['Navy Yard Taproom (sample)', 'Bar', 'Navy Yard', 'F05', 'Best Bars (sample)'], ['H Street Jazz Room (sample)', 'Bar', 'H Street', 'B35', 'Best Bars (sample)'],
            ['Irving Street Rooftop (sample)', 'Bar', 'Columbia Heights', 'E04', 'Best Bars (sample)'], ['Wharf Oyster Counter (sample)', 'Restaurant', 'The Wharf', 'F04', 'Best Restaurants (sample)'],
            ['Barracks Row Bistro (sample)', 'Restaurant', 'Capitol Hill', 'D06', 'Best Restaurants (sample)'], ['Shaw Banh Mi Window (sample)', 'Restaurant', 'Shaw', 'E02', 'Cheap Eats (sample)'],
            ['Brookland Fish Fry (sample)', 'Restaurant', 'Brookland', 'B05', 'Cheap Eats (sample)'], ['Dupont Pho House (sample)', 'Restaurant', 'Dupont Circle', 'A03', 'Cheap Eats (sample)'],
            ['Woodley Trattoria (sample)', 'Restaurant', 'Woodley Park', 'A04', 'Best Restaurants (sample)'], ['Ballston Beer Garden (sample)', 'Bar', 'Ballston', 'K04', 'Best Bars (sample)']];

        if (Place::whereNotNull('stop')->count() < 12) {
            foreach ($samples as $rank => [$name, $category, $hood, $stop, $list]) {
                Place::firstOrCreate(['name' => $name], ['category' => $category, 'neighborhood' => $hood, 'stop' => $stop, 'list_name' => $list, 'list_rank' => $rank + 1, 'blurb' => 'Sample entry for demos.']);
            }
        }

        return Place::whereNotNull('stop')->orderBy('id')->get();
    }

    protected function posts(): Collection
    {
        $posts = collect();
        foreach (self::POSTS as $i => [$line, $stop, $body]) {
            $author = $this->crowd->first(fn (User $u) => ($u->stops[0] ?? null) === $stop && mt_rand(0, 1)) ?? $this->crowd->random();
            $posts->push($this->post($author, ['line' => $line, 'stop' => $stop, 'body' => $body], $this->recent()));
        }

        // A few quotes, one of them of a magazine story.
        $article = Dispatch::whereIn('id', DB::table('articles')->whereNotNull('dispatch_id')->select('dispatch_id'))->where('status', 'approved')->latest()->first();
        $quotes = [
            [$posts[0], 'This is the correct order. Loroco forever.'], [$posts[3], 'Extra onions is a lifestyle.'],
            [$posts[16], 'Go-go Wednesdays are the best night of the week, no contest.'], [$posts[36], 'Our street is next. Porch prices are wild.'],
            [$article, 'Reading this on the Green Line and nodding the whole way.'],
        ];
        foreach ($quotes as [$quoted, $body]) {
            if ($quoted) {
                $posts->push($this->post($this->crowd->where('id', '!=', $quoted->user_id)->random(), ['line' => $quoted->line, 'stop' => $quoted->stop, 'quote_id' => $quoted->id, 'body' => $body], $this->after($quoted, 20, 600)));
            }
        }

        foreach ($posts as $post) {
            $this->engage($post, mt_rand(0, 5), mt_rand(2, 14));
        }
        $this->counts['posts'] = $posts->count();

        return $posts;
    }

    /**
     * Short replies, saves, mumbo sauce and half-smokes on a post.
     */
    protected function engage(Dispatch $post, int $replies, int $fans): void
    {
        $others = $this->crowd->where('id', '!=', $post->user_id);
        foreach ($others->random(min($replies, $others->count())) as $replier) {
            $this->reply($replier, $post, self::CHEERS[mt_rand(0, count(self::CHEERS) - 1)], $this->after($post, 5, 900));
        }
        foreach ($others->random(min($fans, $others->count())) as $fan) {
            $kind = match ($post->type) {
                'been' => ['been', 'save', 'mumbo'][mt_rand(0, 2)],
                'happening' => ['going', 'interested', 'save'][mt_rand(0, 2)],
                default => ['save', 'mumbo', 'mumbo'][mt_rand(0, 2)],
            };
            $this->mark($fan, $post, $kind);
            if ($fan->half_smokes_on && mt_rand(1, 4) === 1) {
                DB::table('half_smokes')->insert(['giver_id' => $fan->id, 'dispatch_id' => $post->id, 'created_at' => $this->after($post, 5, 600)]);
                $this->counts['half-smokes'] = ($this->counts['half-smokes'] ?? 0) + 1;
            }
        }
    }

    /**
     * Threads with replies to replies, four levels deep on most.
     */
    protected function chains(): void
    {
        foreach (self::CHAINS as [$line, $stop, $root, $replies]) {
            $people = $this->crowd->random(4)->values();
            $thread = $this->post($people[0], ['line' => $line, 'stop' => $stop, 'body' => $root], $this->recent(48));
            $at = [0 => $thread];
            foreach ($replies as $i => [$depth, $body]) {
                // Two people trade replies down each branch, with the original poster jumping in.
                $who = $depth % 2 === 0 ? $people[0] : $people[1 + intdiv($i, 4) % 3];
                $at[$depth] = $this->reply($who, $at[$depth - 1], $body, $this->after($at[$depth - 1], 10, 120));
            }
            $this->engage($thread, 2, mt_rand(8, 16));
        }
        $this->counts['nested threads'] = count(self::CHAINS);
    }

    /**
     * Ask DC questions with answers and Best Answers.
     */
    protected function asks(): Collection
    {
        $asks = collect();
        foreach (self::ASKS as [$stop, $question, $answers, $best]) {
            $ask = $this->post($this->crowd->random(), ['type' => 'ask', 'line' => 'yellow', 'stop' => $stop, 'body' => $question], $this->recent());
            $replies = [];
            foreach ($answers as $answer) {
                $replies[] = $this->reply($this->crowd->where('id', '!=', $ask->user_id)->random(), $ask, $answer, $this->after($ask, 10, 600));
            }
            if ($best !== null) {
                $ask->forceFill(['best_answer_id' => $replies[$best]->id])->saveQuietly();
            }
            $this->engage($ask, 0, mt_rand(2, 8));
            $asks->push($ask);
        }
        $this->counts['Ask DC questions'] = $asks->count();

        return $asks;
    }

    protected function polls(): void
    {
        foreach (self::POLLS as $i => [$question, $options]) {
            $when = $this->recent();
            $poll = $this->post($this->crowd->random(), ['type' => 'poll', 'line' => 'yellow', 'body' => $question,
                // One closed, the rest still open.
                'closes_at' => $i === 0 ? $when->copy()->addDay()->min($this->now->copy()->subHour()) : $this->now->copy()->addDays(mt_rand(1, 4)),
            ], $i === 0 ? $this->now->copy()->subDays(5) : $when);
            $opts = $poll->options()->createMany(array_map(fn ($o) => ['label' => $o], $options))->values();
            $votes = [];
            foreach ($this->crowd->random(min($this->crowd->count(), mt_rand(25, 60))) as $voter) {
                // Lopsided, the way real polls are.
                $votes[] = ['dispatch_id' => $poll->id, 'poll_option_id' => $opts[min(mt_rand(0, count($options)), mt_rand(0, count($options) - 1))]->id, 'user_id' => $voter->id, 'created_at' => $this->after($poll, 5, 1400), 'updated_at' => $this->now];
            }
            DB::table('poll_votes')->insertOrIgnore($votes);
            $this->engage($poll, mt_rand(0, 3), 3);
        }
        $this->counts['polls'] = count(self::POLLS);
    }

    // Happenings, lost & found, places and open houses

    protected function happenings(Collection $places): Collection
    {
        $tz = config('stoop.timezone');
        $dc = $this->now->copy()->timezone($tz)->toImmutable()->startOfDay();
        $day = fn (int $weekday, int $weeks = 0) => $dc->next($weekday)->addWeeks($weeks);

        // Verified owners of five places. Only places nobody has claimed yet.
        $owners = ['jasper_cole', 'hollis_park', 'pilar_vega', 'vince_albright', 'dom_ibarra'];
        $owned = [];
        foreach ($places->whereNull('owner_id')->unique('stop')->take(count($owners))->values() as $i => $place) {
            $owner = $this->cast[$owners[$i]];
            $place->forceFill(['owner_id' => $owner->id])->save();
            DB::table('place_claims')->insert(['place_id' => $place->id, 'user_id' => $owner->id, 'role' => 'Owner', 'evidence' => 'Business license and a call to the listed phone number (sample)', 'status' => 'approved', 'decided_by' => $this->cast['calvin_brooks']->id, 'created_at' => $this->now->copy()->subDays(20 + $i), 'updated_at' => $this->now->copy()->subDays(19 + $i)]);
            $owned[] = $place;
        }
        // One claim waiting for sales to check.
        if ($pending = $places->whereNull('owner_id')->whereNotIn('id', collect($owned)->pluck('id'))->first()) {
            DB::table('place_claims')->insert(['place_id' => $pending->id, 'user_id' => $this->cast['luis_carmona']->id, 'role' => 'Manager', 'evidence' => 'I run the front of house, happy to send a pay stub (sample)', 'status' => 'pending', 'created_at' => $this->now->copy()->subHours(5), 'updated_at' => $this->now->copy()->subHours(5)]);
        }

        // Owner deals running now.
        $deals = [['Half-price pretzels at weekday happy hour', 'Monday to Thursday, 4 to 6pm. Show the deal at the bar.'], ['$5 tickets to Wednesday open jam', 'Bring an instrument and play a song, get your drink on the house.'],
            ['Free churro with any salsa lesson', 'Thursdays this month.'], ['Oyster happy hour: a dollar off', 'Weekdays until 6pm at the bar and on the patio.'], ['Two half-smokes for $9 before noon', 'Saturdays and Sundays, while the grill’s hot.']];
        foreach ($owned as $i => $place) {
            PlaceDeal::create(['place_id' => $place->id, 'user_id' => $place->owner_id, 'title' => $deals[$i][0], 'details' => $deals[$i][1], 'starts_at' => $this->now->copy()->subDays(mt_rand(1, 6)), 'ends_at' => $this->now->copy()->addDays(mt_rand(5, 25))]);
        }
        $this->counts['claimed places'] = count($owned);
        $this->counts['owner deals'] = count($owned);

        $owner = fn (int $i) => isset($owned[$i]) ? User::find($owned[$i]->owner_id) : $this->cast['calvin_brooks'];
        // [host, line, stop, place, body, start (DC), price cents, ticket url, featured, clicks]
        $list = [
            [$owner(0), 'orange', $owned[0]->stop ?? 'F05', $owned[0] ?? null, 'Oktoberfest on the riverwalk: brass band, giant pretzels and lawn games. Dogs welcome on the patio.', $day(CarbonInterface::SATURDAY)->setTime(14, 0), 1500, 'https://example.com/tickets/riverwalk-oktoberfest', true, 48],
            [$owner(1), 'orange', $owned[1]->stop ?? 'B35', $owned[1] ?? null, 'Late set with a quartet of student musicians. Two sets, standing room after 9.', $day(CarbonInterface::FRIDAY)->setTime(21, 0), 2000, 'https://example.com/tickets/late-set-quartet', false, 31],
            [$owner(2), 'orange', $owned[2]->stop ?? 'E04', $owned[2] ?? null, 'Sunset salsa lesson on the roof, no partner needed. Beginners first, open dancing after.', $day(CarbonInterface::THURSDAY)->setTime(18, 30), 1000, 'https://example.com/tickets/rooftop-salsa', false, 22],
            [$owner(3), 'red', $owned[3]->stop ?? 'F04', $owned[3] ?? null, 'Shuck-your-own oyster class with a Chesapeake waterman. Gloves and knives provided.', $day(CarbonInterface::SATURDAY, 1)->setTime(13, 0), 4500, 'https://example.com/tickets/oyster-class', true, 17],
            [$this->cast['dom_ibarra'], 'orange', 'E05', null, 'Porch potluck on Upshur. Bring a dish to share and a chair. Kids and dogs very welcome.', $day(CarbonInterface::SUNDAY)->setTime(14, 0), 0, null, false, 0],
            [$this->cast['lorraine_tate'], 'blue', 'F06', null, 'Anacostia Riverwalk cleanup, then a picnic in the park. Bags and grabbers provided.', $day(CarbonInterface::SATURDAY)->setTime(10, 0), 0, null, false, 0],
            [$this->cast['tamsin_rowe'], 'orange', 'B05', null, 'Kids’ Halloween costume parade down Monroe Street, then cider at the arts walk.', $day(CarbonInterface::SATURDAY, 1)->setTime(11, 0), null, null, false, 0],
            [$this->cast['gwen_abernathy'], 'blue', 'A05', null, 'Book swap at the Cleveland Park library. Bring three, take three.', $day(CarbonInterface::TUESDAY)->setTime(18, 0), null, null, false, 0],
            [$this->cast['ezra_wolfe'], 'silver', 'C09', null, 'Marathon cheer station by Crystal City. Cowbells, signs and coffee from 7:30.', $day(CarbonInterface::SUNDAY)->setTime(7, 30), null, null, false, 0],
            [$this->cast['delia_haskins'], 'orange', 'F07', null, 'Free go-go in the park by Congress Heights, an afternoon set for the whole family.', $day(CarbonInterface::SUNDAY, 1)->setTime(15, 0), null, null, false, 0],
            [$this->cast['frankie_liu'], 'blue', 'B03', null, 'Sketch night in the Union Station main hall. Bring a pad, we’ll find a corner.', $day(CarbonInterface::WEDNESDAY)->setTime(19, 0), null, null, false, 0],
        ];

        $happenings = collect();
        foreach ($list as [$host, $line, $stop, $place, $body, $start, $price, $url, $featured, $clicks]) {
            $d = $this->post($host, ['type' => 'happening', 'line' => $line, 'stop' => $stop, 'place_id' => $place?->id, 'body' => $body, 'happening_at' => $start->format('D M j · g:ia')], $this->recent(72));
            $d->happening()->create(['starts_at' => $start->utc(), 'price_cents' => $price, 'ticket_url' => $url, 'featured' => $featured]);
            DB::table('happenings')->where('dispatch_id', $d->id)->update(['ticket_clicks' => $clicks]);
            $this->mark($host, $d, 'going');
            foreach ($this->crowd->where('id', '!=', $host->id)->random(mt_rand(6, 22)) as $fan) {
                $this->mark($fan, $d, mt_rand(1, 3) === 1 ? 'interested' : 'going');
            }
            $this->engage($d, mt_rand(0, 3), 0);
            $happenings->push($d);
        }
        $this->counts['happenings'] = $happenings->count();

        return $happenings;
    }

    protected function lostAndFound(): void
    {
        // [stop, kind, status, body, reunited reply or null]
        $posts = [
            ['E05', 'pet', 'lost', 'Lost: gray tabby named Biscuit, white paws, very friendly. Last seen on Quincy St near the Petworth Metro.', 'She came home on her own at 3am, meowing at the door like nothing happened. Thank you all!'],
            ['D06', 'pet', 'found', 'Found a small brown dog with a red collar, no tags, by Lincoln Park. He’s safe with us. Reply if he’s yours!', 'That’s Pickles!! He dug under the fence again. On my way.'],
            ['E04', 'pet', 'lost', 'Lost: green parakeet escaped near Meridian Hill Park. Answers to Kiwi and loves sunflower seeds.', null],
            ['F05', 'item', 'found', 'Found a set of keys with a Nats bottle opener on the bench at the Navy Yard Metro, east entrance. Describe the keychain and they’re yours.', null],
            ['B05', 'item', 'lost', 'Lost a blue SmarTrip card holder with my work badge, somewhere on the Red Line between Brookland and Union Station.', 'Someone turned it in at the Union Station kiosk. Faith in humanity restored.'],
            ['D05', 'item', 'found', 'Found a kid’s purple scooter at Garfield Park. Holding it on my porch until it finds its rider.', null],
            ['F06', 'pet', 'lost', 'Lost: black lab mix named Juniper slipped her leash on the Anacostia Riverwalk near the 11th Street Bridge.', 'Found! A jogger walked her all the way back to our street. Best neighbors.'],
            ['E03', 'item', 'lost', 'Lost a film camera in a brown leather case, maybe on the 90 bus or at the U Street Metro.', null],
        ];
        foreach ($posts as [$stop, $kind, $status, $body, $reunited]) {
            $author = $this->crowd->random();
            $when = $this->recent();
            $d = $this->post($author, ['type' => 'lost', 'line' => 'blue', 'stop' => $stop, 'body' => $body, 'details' => ['lost_kind' => $kind, 'lost_status' => $status]], $when);
            $this->reply($this->crowd->where('id', '!=', $author->id)->random(), $d, ['Sharing with our block list.', 'Will keep an eye out!', 'Checked our alley, nothing yet.'][mt_rand(0, 2)], $this->after($d, 10, 200));
            if ($reunited) {
                $back = $this->reply($author, $d, $reunited, $this->after($d, 240, 2000));
                $d->forceFill(['details' => [...$d->details, 'reunited_at' => $back->created_at->toIso8601String()]])->saveQuietly();
            }
        }
        $this->counts['lost & found'] = count($posts);
    }

    /**
     * Been here check-ins at places, with neighbors adding their own.
     */
    protected function checkIns(Collection $places): void
    {
        $notes = ['Get the special.', 'Go early, it fills up.', 'Worth the trip across town.', 'Friendly staff, quick service.', 'Ask what’s off the menu.', 'Sat at the counter, great people watching.', 'Perfect for a rainy night.', 'Bring cash just in case.'];
        $count = 0;
        foreach ($places->shuffle()->take(min(14, $places->count())) as $place) {
            foreach ($this->crowd->random(mt_rand(1, 3)) as $visitor) {
                $d = $this->post($visitor, ['type' => 'been', 'line' => 'red', 'stop' => $place->stop, 'place_id' => $place->id, 'body' => 'Checked in at '.$place->name.'. '.$notes[$count % count($notes)]], $this->recent());
                $this->mark($visitor, $d, 'been');
                $this->engage($d, mt_rand(0, 2), mt_rand(1, 6));
                $count++;
            }
        }
        $this->counts['Been here check-ins'] = $count;
    }

    protected function openHouses(): void
    {
        $tz = config('stoop.timezone');
        $saturday = $this->now->copy()->timezone($tz)->next(CarbonInterface::SATURDAY);
        // [stop, address, days after Saturday, from, to, price, agent, sponsored, body]
        $list = [
            ['E05', '1428 Upshur St NW', 1, '13:00', '15:00', 899000, 'Maria Delgado, Sample Realty', true, 'Open house Sunday: three-bedroom rowhouse with a deep front porch and a finished basement. Two blocks to the Metro.'],
            ['E04', '3312 Holmead Pl NW', 0, '11:00', '13:00', 635000, 'Sam Whitaker, Example Homes', false, 'Two-bedroom condo with a balcony and in-unit laundry. Walk to the Tivoli and the farmers market.'],
            ['F05', '1101 New Jersey Ave SE', 1, '12:00', '14:00', 549000, 'Priya Natarajan, Sample Realty', false, 'Corner one-bedroom with river views, a gym and a roof deck. Steps from the ballpark.'],
            ['F07', '3527 Martin Luther King Jr Ave SE', 0, '10:00', '12:00', 425000, 'Darnell Hughes, Example Homes', false, 'Detached home with a big backyard, new roof and a garden ready for spring.'],
            ['E02', '1715 7th St NW', 7, '14:00', '16:00', 1150000, 'Maria Delgado, Sample Realty', false, 'Renovated Victorian with original pocket doors, a rental unit downstairs and a parking pad.'],
        ];
        foreach ($list as [$stop, $address, $after, $from, $to, $price, $agent, $sponsored, $body]) {
            $d = $this->post($this->crowd->random(), ['type' => 'openhouse', 'line' => 'green', 'stop' => $stop, 'body' => $body, 'details' => array_filter([
                'block' => HomeSales::block($address), 'date' => $saturday->copy()->addDays($after)->toDateString(), 'from' => $from, 'to' => $to, 'price' => $price,
                'url' => 'https://example.com/listings/'.Str::slug(HomeSales::block($address)), 'agent' => $agent, 'sponsored' => $sponsored ?: null,
            ])], $this->recent());
            $this->engage($d, mt_rand(0, 2), mt_rand(2, 8));
        }
        $this->counts['open houses'] = count($list);
    }

    // The house bots

    protected function bots(Collection $posts, Collection $asks, Collection $happenings): void
    {
        $tz = config('stoop.timezone');
        $dc = fn (int $daysAgo, int $hour, int $minute = 0) => $this->now->copy()->timezone($tz)->subDays($daysAgo)->setTime($hour, $minute)->utc()->min($this->now->copy()->subMinutes(5));
        $bot = fn (string $username) => Bot::find($username)?->account() ?? throw new \RuntimeException("No @$username bot.");
        $count = 0;
        $say = function (string $username, array $attrs, CarbonInterface $when) use ($bot, &$count) {
            $count++;

            return $this->post($bot($username), $attrs, $when);
        };

        // @metro: a couple of incidents this week.
        $say('metro', ['line' => 'red', 'stop' => 'A03', 'body' => 'Red Line: Trains are single-tracking between Dupont Circle and Farragut North because of a disabled train. Expect 15-minute delays in both directions.'], $dc(1, 8, 12));
        $say('metro', ['line' => 'orange', 'stop' => 'D08', 'body' => 'Orange/Silver/Blue Lines: Delays near Stadium-Armory because of a track problem. Trains are moving again but expect residual crowding.'], $dc(3, 17, 40));
        $say('metro', ['line' => 'green', 'stop' => 'F05', 'body' => 'Green Line: Buses are replacing trains between Navy Yard and Anacostia this weekend for scheduled bridge work. Allow an extra 20 minutes.'], $dc(4, 9, 5));

        // @weather: every morning this week.
        $forecasts = [['Sunny', 68], ['Partly Sunny', 64], ['Chance Showers', 59], ['Mostly Sunny', 71], ['Patchy Fog then Sunny', 66], ['Rain', 57], ['Sunny', 62]];
        foreach ($forecasts as $daysAgo => [$forecast, $high]) {
            $name = $this->now->copy()->timezone($tz)->subDays($daysAgo)->format('l');
            if ($daysAgo === 0 && $this->now->copy()->timezone($tz)->hour < 7) {
                continue;
            }
            $say('weather', ['line' => 'silver', 'body' => "$name in DC: $forecast, high near {$high}°. ".WeatherBot::quip($forecast, $high)], $dc($daysAgo, 6, 30));
        }

        // @quizmaster: The Daily each morning and last night's streak leaders.
        foreach (range(6, 0) as $daysAgo) {
            if ($daysAgo === 0 && $this->now->copy()->timezone($tz)->hour < 7) {
                continue;
            }
            $day = DailyQuiz::today()->subDays($daysAgo);
            $say('quizmaster', ['line' => 'blue', 'body' => 'The Daily #'.DailyQuiz::number($day).' is up: '.DailyQuiz::PER_DAY.' questions about DC, the same for everyone. Keep your streak going. '.route('quiz')], $dc($daysAgo, 7));
        }
        $leaders = collect(['sol_tesfaye' => 16, 'meg_dunn' => 12, 'kenji_mori' => 9, 'hattie_vu' => 7, 'odell_jenkins' => 5]);
        $body = 'Streak leaders after The Daily #'.DailyQuiz::number(DailyQuiz::today()->subDay()).':';
        foreach ($leaders->keys() as $i => $username) {
            $body .= "\n".($i + 1).'. '.$this->cast[$username]->username.', '.$leaders[$username].' days';
        }
        $say('quizmaster', ['line' => 'blue', 'body' => $body."\nPlay today’s: ".route('quiz')], $dc(1, 20));

        // @history: two entries checked by an editor, and this week's post.
        DB::table('history_entries')->where(['month' => 10, 'day' => 9])->orWhere(fn ($q) => $q->where(['month' => 10, 'day' => 30]))->update(['verify' => false, 'updated_at' => $this->now]);
        $entry = 'The Million Man March fills the National Mall in 1995.';
        DB::table('history_entries')->insert(['month' => 10, 'day' => 16, 'text' => $entry, 'link' => null, 'line' => 'blue', 'stop' => 'D02', 'verify' => false, 'created_at' => $this->now->copy()->subDays(10), 'updated_at' => $this->now->copy()->subDays(10)]);
        $sixteenth = $this->now->copy()->timezone($tz)->setDate($this->now->year, 10, 16)->setTime(9, 0);
        if ($sixteenth->isPast() && $sixteenth->gt($this->now->copy()->subDays(10))) {
            $say('history', ['line' => 'blue', 'stop' => 'D02', 'body' => History::PREFIX.', October 16:'."\n\n".$entry], $sixteenth->utc());
        }

        // @weekend: last Friday's round-up of neighbors' happenings.
        $friday = $this->now->copy()->timezone($tz)->previous(CarbonInterface::FRIDAY)->setTime(12, 0);
        $last = collect([
            ['orange', 'E03', 'Go-go brunch on U Street with a live band. Come hungry.', $friday->copy()->addDay()->setTime(11, 30)],
            ['blue', 'D06', 'Neighborhood history walk from Eastern Market to the Navy Yard. Meet at the market’s north hall.', $friday->copy()->addDays(2)->setTime(10, 0)],
        ])->map(function ($h) use ($friday) {
            [$line, $stop, $body, $start] = $h;
            $d = $this->post($this->crowd->random(), ['type' => 'happening', 'line' => $line, 'stop' => $stop, 'body' => $body, 'happening_at' => $start->format('D M j · g:ia')], $friday->copy()->subDays(2)->utc());
            $d->happening()->create(['starts_at' => $start->copy()->utc()]);
            $this->engage($d, 1, mt_rand(5, 12));

            return $d;
        });
        $lines = $last->map(fn (Dispatch $d) => '• '.Str::limit($d->body, 60).' ('.$d->happening_at.') '.route('dispatches.show', $d))->join("\n");
        $say('weekend', ['line' => 'orange', 'body' => Weekend::PREFIX.', '.$friday->format('D M j').' to '.$friday->copy()->addDays(2)->format('D M j').":\n\nFrom neighbors:\n".$lines], $friday->utc());

        // @recap: last Sunday's best at the busiest stop.
        $sunday = $this->now->copy()->timezone($tz)->previous(CarbonInterface::SUNDAY)->setTime(9, 0)->utc();
        $best = $posts->where('stop', 'E04')->filter(fn ($d) => $d->created_at->lt($sunday))->take(3)->values();
        if ($best->isNotEmpty()) {
            $say('recap', ['line' => 'red', 'stop' => 'E04', 'body' => 'Your stop this week: '.Stations::name('E04')."\n\n".$best->map(fn ($d, $i) => ($i + 1).'. '.Str::limit($d->body, 70).' '.route('dispatches.show', $d))->join("\n")], $sunday);
        }

        // @homes: weekly sale prices near the busiest stations, from the real sales table.
        foreach (HomeSales::busiest(30, 1, 2) as $stop => $stats) {
            $say('homes', ['line' => 'green', 'stop' => $stop, 'body' => 'Homes near '.Stations::name($stop).' sold for a median $'.number_format($stats['median']).' this month ('.$stats['count'].' sales within 1 km). Source: DC Office of Tax and Revenue property records.'], $dc(4, 9));
        }

        // Neighbors mention bots and they answer.
        $mention = function (string $username, string $question, string $answer, ?Dispatch $parent = null, ?string $stop = null) use ($bot, &$count) {
            $asker = $this->crowd->random();
            $q = $parent ? $this->reply($asker, $parent, $question, $this->after($parent, 30, 300)) : $this->post($asker, ['line' => 'yellow', 'stop' => $stop, 'body' => $question], $this->recent());
            $this->reply($bot($username), $q, $answer, $this->after($q, 1, 2));
            $count++;
        };
        $places = Place::whereIn('stop', ['E03', 'E02', 'E04', 'A03'])->orderBy('list_rank')->take(3)->get();
        $mention('eats', '@eats cheap eats near U Street', $places->isEmpty()
            ? 'Tell me what you’re craving and where, like “@eats Ethiopian near U Street”. Or browse the lists: '.route('places')
            : 'From the magazine’s lists:'.$places->map(fn ($p, $i) => "\n".($i + 1).'. '.$p->name.($p->listLabel() ? ' ('.$p->listLabel().')' : '').' '.route('places.show', $p))->join(''), null, 'E03');
        $mention('bikeshare', '@bikeshare Navy Yard', 'Capital Bikeshare near Navy Yard: 1st & M St SE (90 m): 7 bikes (2 e), 8 docks; Yards Park (260 m): 3 bikes, 12 docks; 4th & M St SW (480 m): 11 bikes (5 e), 2 docks.', null, 'F05');
        $mention('311', '@311 there’s a streetlight out on our block by Petworth', 'Report it at https://311.dc.gov as “Streetlight Repair Investigation” with the closest street address near Petworth, or call 311. You’ll get a request number to track it.', null, 'E05');
        $mention('311', '@311 rats in the alley again, near Eastern Market', 'Report it at https://311.dc.gov as “DC Health Rodent & Vector Control” with the closest street address near Eastern Market, or call 311. You’ll get a request number to track it.', null, 'D06');
        $mention('translate', '@translate to Spanish', 'Spanish: El trámite para comprar por primera vez me tiene mareado. ¿Algún consejo sobre los programas para compradores primerizos en DC?', $posts->first(fn ($d) => str_contains($d->body, 'First-time buyer')) ?? $posts->firstWhere('line', 'green'));
        $mention('metro', '@metro next Columbia Heights', 'Next trains at Columbia Hts: Green to Branch Ave, 3 min; Green to Greenbelt, 6 min; Green to Branch Ave, 11 min.', null, 'E04');

        // @ask links an unanswered question to answered ones.
        if (($open = $asks->last()) && ($answered = $asks->where('best_answer_id', '!=', null)->take(2))->isNotEmpty()) {
            $this->reply($bot('ask'), $open, "Neighbors answered some similar questions. These might help while you wait:\n\n".$answered->map(fn ($d) => '• '.Str::limit($d->body, 70).' '.route('dispatches.show', $d))->join("\n"), $this->after($open, 30, 40));
            $count++;
        }

        $this->counts['bot posts and replies'] = $count;
    }

    // Games

    protected function games(Collection $places): void
    {
        // Metro Bingo: hand-marked squares, each with one of that person's posts from this week as proof.
        $marks = 0;
        $byUser = Dispatch::whereIn('user_id', $this->crowd->pluck('id'))->where('status', 'approved')->where('created_at', '>=', $this->week->utc())->get(['id', 'user_id'])->groupBy('user_id');
        foreach ($byUser->take(18) as $userId => $proofs) {
            $user = $this->crowd->firstWhere('id', $userId);
            $squares = collect(Bingo::layout($user, $this->week))->filter(fn ($k) => isset(Bingo::SQUARES[$k]) && ! Bingo::SQUARES[$k][1])->shuffle()->take(mt_rand(2, 5));
            foreach ($squares as $square) {
                DB::table('bingo_marks')->insertOrIgnore(['user_id' => $userId, 'week' => $this->week->toDateString(), 'square' => $square, 'dispatch_id' => $proofs->random()->id, 'created_at' => $this->now, 'updated_at' => $this->now]);
                $marks++;
            }
        }
        $this->counts['bingo marks'] = $marks;

        // A scavenger hunt, part way through.
        $pupusa = $places->first(fn ($p) => str_contains(strtolower($p->name), 'pupusa'));
        $hunt = Hunt::create(['slug' => 'fall-leaves-hunt-'.$this->now->year, 'name' => 'The Fall Leaves Hunt', 'description' => 'Five clues around the city before the leaves come down. Post a Been here or a dispatch at each spot to stamp your passport.',
            'starts_at' => $this->now->copy()->subDays(5), 'ends_at' => $this->now->copy()->addDays(12), 'created_by' => $this->cast['margot_ellison']->id]);
        $steps = [
            ['Start where the zoo’s neighbors catch the train.', 'A04', null, null, ['Hunt stop one: the zoo station. Heard a lion on the walk up, I swear.', 'Woodley Park, the zoo crowd was out in force today.', 'Made it to the zoo stop. The escalator alone counts as cardio.', 'Clue one solved before my coffee kicked in.', 'Started the hunt at Woodley Park with a bagel in hand.']],
            ['Find the market that’s been selling since 1873.', 'D06', null, null, ['Eastern Market for the hunt, and yes I bought a pie.', 'The old market hall, clue two done.', 'Clue two: market found, pancakes eaten.', 'Capitol Hill market check-in, flowers on the way home.', 'Eastern Market done, said hi to the pottery stall.']],
            ['Ride to the station that shares its name with a university on Georgia Ave.', 'E02', 'BISON', null, ['Shaw for clue three, the code word was easy to spot.', 'Third stop done, love the murals around the station.', 'Clue three down, two to go.', 'Shaw station, sunny and buzzing.', 'Found the Shaw clue on the second lap around the block.']],
            ['Where a giant baseball bat greets fans.', 'F05', 'NATITUDE', null, ['The ballpark stop! Clue four, no game today but the vibes remain.', 'Navy Yard for the hunt, walked the riverwalk after.', 'Clue four, found the bat, took a very silly photo.', 'Ballpark station, almost there.', 'Navy Yard clue done, the river looked great today.']],
            ['End with the best pupusas on the Cheap Eats list.', $pupusa?->stop ?? 'E04', null, $pupusa, ['Final clue: pupusas acquired. What a hunt.', 'Finished the hunt with loroco and curtido.', 'Last stop, and lunch was the prize.', 'Hunt complete, belly full.', 'Done! Ended the hunt with curtido on everything.']],
        ];
        $completions = 0;
        $players = $this->crowd->random(10)->values();
        foreach ($steps as $i => [$clue, $stop, $code, $place, $proofs]) {
            $step = $hunt->steps()->create(['position' => $i + 1, 'clue' => $clue, 'stop' => $place ? null : $stop, 'place_id' => $place?->id, 'code_word' => $code]);
            // Fewer players at each later step; no proof text is used by more than two people.
            foreach ($players->take(10 - $i * 2) as $j => $player) {
                $when = $hunt->starts_at->copy()->addHours(6 + $i * 12 + $j * 3)->min($this->now->copy()->subMinutes(10));
                $proof = $this->post($player, ['type' => $place ? 'been' : 'dispatch', 'line' => $place ? 'red' : 'blue', 'stop' => $stop, 'place_id' => $place?->id, 'body' => $proofs[$j % count($proofs)]], $when);
                DB::table('hunt_completions')->insert(['hunt_step_id' => $step->id, 'user_id' => $player->id, 'dispatch_id' => $proof->id, 'created_at' => $when, 'updated_at' => $when]);
                $completions++;
            }
        }
        $this->counts['hunt completions'] = $completions;

        // The Daily: streaks for the leaders, a few days here and there for everyone else.
        if (! DB::table('quiz_questions')->exists()) {
            $this->call(QuizQuestionSeeder::class);
        }
        $streaks = ['sol_tesfaye' => 16, 'meg_dunn' => 12, 'kenji_mori' => 9, 'hattie_vu' => 7, 'odell_jenkins' => 5];
        $rows = [];
        foreach ($this->crowd->take(40) as $person) {
            $days = $streaks[$person->username] ?? 0;
            $played = $days ? range(1, $days) : array_filter(range(1, 10), fn () => mt_rand(1, 3) === 1);
            foreach ($played as $back) {
                $day = DailyQuiz::today()->subDays($back);
                $ids = DailyQuiz::questionIdsFor($day);
                $answers = array_map(fn () => ['choice' => 0, 'correct' => mt_rand(1, 100) <= 72], $ids);
                $rows[] = ['user_id' => $person->id, 'day' => $day->toDateString(), 'question_ids' => json_encode($ids), 'answers' => json_encode($answers),
                    'score' => collect($answers)->where('correct', true)->count(), 'completed_at' => $day->addHours(8)->utc(), 'created_at' => $day->utc(), 'updated_at' => $day->utc()];
            }
        }
        DB::table('quiz_attempts')->insertOrIgnore($rows);
        $this->counts['quiz attempts'] = count($rows);

        // A guessing contest, Peak Bloom style.
        $snow = Contest::create(['slug' => 'first-snow-'.$this->now->year, 'title' => 'First Snow '.$this->now->year, 'question' => 'When will Reagan National record its first measurable snow of the winter?',
            'prize' => 'A Stoop mug and bragging rights (sample prize)', 'closes_at' => $this->now->copy()->addDays(30), 'winners' => 3]);
        foreach ($this->crowd->random(min(34, $this->crowd->count())) as $person) {
            $snow->entries()->create(['user_id' => $person->id, 'guess' => CarbonImmutable::create($this->now->year, 12, 1)->addDays(mt_rand(0, 75))->toDateString()]);
        }
        $this->counts['contest entries'] = min(34, $this->crowd->count());
    }

    // The magazine and the newsroom

    protected function editorial(Collection $places): void
    {
        $tz = config('stoop.timezone');
        $magazine = Magazine::account();
        [$margot, $desmond] = [$this->cast['margot_ellison'], $this->cast['desmond_pryor']];

        // Writers (invented) with titles, and readers following them and the magazine's real bylines.
        $writers = collect([['Margot Ellison', 'Food Editor', $margot], ['Desmond Pryor', 'Senior Editor, Neighborhoods', $desmond], ['Celia Navarro', 'Staff Writer', null]])
            ->map(fn ($w) => Author::firstOrCreate(['slug' => Str::slug($w[0])], ['name' => $w[0], 'title' => $w[1], 'user_id' => $w[2]?->id]));
        $follows = [];
        foreach (Author::all() as $author) {
            foreach ($this->crowd->random(mt_rand(5, 20)) as $reader) {
                $follows[] = ['author_id' => $author->id, 'user_id' => $reader->id, 'created_at' => $this->recent(), 'updated_at' => $this->now];
            }
        }
        DB::table('author_follows')->insertOrIgnore($follows);
        $this->counts['author follows'] = count($follows);

        // Sample stories by those writers, each posted by @magazine.
        $stories = [
            ['The Pupusa Trail: 12 Spots Worth the Trip (sample)', 'Food', 'red', 'E04', 0],
            ['The Corner Store Comeback (sample)', 'News & Politics', 'blue', null, 1],
            ['A Perfect Fall Day on the Green Line (sample)', 'Things to Do', 'orange', 'E05', 2],
        ];
        $articles = [];
        foreach ($stories as [$title, $category, $line, $stop, $writer]) {
            $url = 'https://example.com/magazine/'.Str::slug(str_replace('(sample)', '', $title));
            $when = $this->recent();
            $d = $this->post($magazine, ['line' => $line, 'body' => $title.' '.$url], $when);
            $id = DB::table('articles')->insertGetId(['guid_hash' => hash('sha256', $url), 'url' => $url, 'title' => $title, 'category' => $category, 'dispatch_id' => $d->id, 'published_at' => $when, 'stations' => $stop, 'created_at' => $when, 'updated_at' => $when]);
            DB::table('article_author')->insert(['article_id' => $id, 'author_id' => $writers[$writer]->id, 'position' => 0]);
            $this->engage($d, mt_rand(1, 4), mt_rand(4, 12));
            $articles[] = ['url' => $url, 'title' => $title, 'dispatch' => $d];
        }

        // Ask the editor: questions, upvotes and answers in the thread.
        $starts = $this->now->copy()->subDay();
        $ends = $this->now->copy()->addDays(2);
        $root = $this->post($margot, ['line' => 'blue', 'body' => 'Ask the editor: Margot Ellison on this fall’s restaurant openings. Questions open '.$starts->copy()->timezone($tz)->format('D M j, g:ia').' to '.$ends->copy()->timezone($tz)->format('D M j, g:ia').'. Ask yours in the thread and upvote the ones you want answered.'], $starts);
        $thread = EditorThread::create(['dispatch_id' => $root->id, 'user_id' => $margot->id, 'title' => 'Margot Ellison on this fall’s restaurant openings', 'starts_at' => $starts, 'ends_at' => $ends]);
        $root->forceFill(['details' => ['ama' => $thread->id]])->saveQuietly();
        $qa = [
            ['Which new opening are you most excited about east of the river?', 'A family-run Caribbean spot near the Anacostia Metro. The oxtail alone is worth the trip, and the patio is huge.', 'Adding it to my list for Saturday!'],
            ['Is the half-smoke still the official food of DC, or has something replaced it?', 'Still the half-smoke. Mumbo wings are a strong second and I will hear no arguments.', null],
            ['How do you decide what makes a cheap eats list?', 'Under about $20 a person, consistently great, and somewhere we’d send our own families.', 'That’s a great rule of thumb.'],
            ['Any good new spots for a big group near Union Station?', null, null],
            ['What’s a restaurant you wish more people knew about?', 'A tiny Ethiopian café in Silver Spring with the best shiro I’ve had. Go on a weekday.', null],
            ['Will there be another pupusa guide this year?', 'Funny you ask. It’s out this week, linked in the magazine’s feed.', 'Reading it now!'],
            ['How do restaurants get on your radar in the first place?', null, null],
        ];
        $votes = [];
        foreach ($qa as $i => [$question, $answer, $followUp]) {
            $asker = $this->crowd->random();
            $q = $this->reply($asker, $root, $question, $starts->copy()->addMinutes(30 + $i * 70));
            foreach ($this->crowd->where('id', '!=', $asker->id)->random(max(0, 18 - $i * 2 + mt_rand(-3, 3))) as $voter) {
                $votes[] = ['dispatch_id' => $q->id, 'user_id' => $voter->id, 'created_at' => $this->after($q, 5, 600)];
            }
            if ($answer) {
                $a = $this->reply($margot, $q, $answer, $this->after($q, 60, 400));
                if ($followUp) {
                    $this->reply($asker, $a, $followUp, $this->after($a, 10, 90));
                }
            }
        }
        DB::table('question_votes')->insertOrIgnore($votes);
        $this->counts['Ask the editor questions'] = count($qa);

        // Best of Washington: one ballot open for votes, one closed with results.
        $pick = fn (string $list, int $n) => $places->filter(fn ($p) => str_contains((string) $p->list_name, $list))->take($n)->pluck('id')->all() ?: $places->random(min($n, $places->count()))->pluck('id')->all();
        $categories = ['Best cheap eats' => $pick('Cheap', 5), 'Best bar' => $pick('Bars', 4), 'Best date night' => $pick('Restaurants', 4)];
        $open = Ballot::start('Best of Washington 2027: readers’ picks (sample)', $this->now->copy()->subDays(3), $this->now->copy()->addDays(12), $categories);
        $closed = Ballot::start('Best of Washington 2026: summer edition (sample)', $this->now->copy()->subDays(70), $this->now->copy()->subDays(40), $categories);
        $ballotVotes = 0;
        foreach ([$open, $closed] as $ballot) {
            $rows = [];
            foreach (DB::table('ballot_categories')->where('ballot_id', $ballot->id)->get() as $category) {
                $nominees = DB::table('ballot_nominees')->where('ballot_category_id', $category->id)->pluck('id')->values();
                foreach ($this->crowd->random(min($this->crowd->count(), mt_rand(30, 55))) as $voter) {
                    // A clear favorite in each category.
                    $rows[] = ['ballot_category_id' => $category->id, 'ballot_nominee_id' => $nominees[min(mt_rand(0, $nominees->count()), mt_rand(0, $nominees->count() - 1))], 'user_id' => $voter->id, 'created_at' => $ballot->opens_at, 'updated_at' => $ballot->opens_at];
                }
            }
            DB::table('ballot_votes')->insertOrIgnore($rows);
            $ballotVotes += count($rows);
        }
        $this->counts['ballot votes'] = $ballotVotes;

        // An issue thread: the issue, then each story as a reply.
        $issueStories = collect($articles)->map(fn ($a) => ['url' => $a['url'], 'title' => $a['title']])
            ->concat(DB::table('articles')->whereNotIn('url', collect($articles)->pluck('url'))->latest('id')->take(2)->get(['url', 'title'])->map(fn ($a) => ['url' => $a->url, 'title' => $a->title]))->values()->all();
        $issue = Issue::create(['slug' => 'fall-'.$this->now->year.'-sample', 'name' => 'Fall '.$this->now->year.' (sample)', 'cover_url' => null, 'stories' => $issueStories]);
        $when = $this->now->copy()->subDays(2);
        $issueThread = $this->post($magazine, ['line' => 'blue', 'body' => 'The '.$issue->name.' issue is out: '.count($issueStories).' stories. '.route('issues.show', $issue)], $when);
        foreach ($issueStories as $i => $story) {
            $this->reply($magazine, $issueThread, $story['title'].' '.$story['url'], $when->copy()->addMinute()->addSeconds($i));
        }
        $issue->forceFill(['dispatch_id' => $issueThread->id, 'published_at' => $when])->save();
        $this->engage($issueThread, 0, 12);

        // Archive stories tagged to stations.
        $archive = [
            ['How Upshur Street Became a Dinner Destination (sample)', '2019-05-14', 'Food', 'E05', 'Petworth', 'A block of rowhouses turned into one of the city’s best places to eat.'],
            ['The Long Life of Eastern Market (sample)', '2016-08-02', 'History', 'D06', 'Capitol Hill', 'Fires, renovations and the vendors who kept the market going.'],
            ['Go-Go’s Home Base (sample)', '2020-02-19', 'Music', 'E03 E02', 'U Street, Shaw', 'Why go-go still sounds like DC.'],
            ['The Anacostia Riverwalk, Mile by Mile (sample)', '2021-06-10', 'Outdoors', 'F06 F05', 'Anacostia, Navy Yard', 'A walking guide to both banks of the river.'],
            ['Columbia Heights After the Mall (sample)', '2018-11-03', 'Neighborhoods', 'E04', 'Columbia Heights', 'How a neighborhood changed, and what stayed.'],
            ['Brookland’s Arts Walk Turns Ten (sample)', '2023-09-21', 'Arts', 'B05', 'Brookland', 'Studios, a farmers market and a very good coffee window.'],
            ['A Pupusa Pilgrimage (sample)', '2022-04-08', 'Food', 'E04 B10', 'Mount Pleasant, Wheaton', 'From Mount Pleasant to Wheaton in search of the perfect curtido.'],
            ['Old Town’s Waterfront, Reimagined (sample)', '2024-07-15', 'Neighborhoods', 'C13', 'Old Town', 'Flood walls, a new park and the best crab cake debate in Virginia.'],
        ];
        foreach ($archive as [$title, $date, $section, $stations, $hoods, $description]) {
            Archive::save(['title' => $title, 'url' => 'https://example.com/magazine/archive/'.Str::slug(str_replace('(sample)', '', $title)), 'date' => $date, 'section' => $section, 'stations' => $stations, 'neighborhoods' => $hoods, 'description' => $description]);
        }
        $this->counts['archive stories'] = count($archive);

        // A callout tied to a story, with replies the newsroom may quote.
        $callout = $this->post($desmond, ['type' => 'callout', 'line' => 'blue', 'body' => 'Callout from the newsroom: has your block lost its corner store, or gained a new one? We’re reporting on how neighborhood shops are changing. Reply with your story and tick the box if we can quote you.',
            'details' => ['story' => ['title' => 'The Corner Store Comeback (sample)', 'url' => $articles[1]['url']]]], $this->now->copy()->subDays(3));
        $answers = [
            ['The bodega on our corner in Petworth closed in 2019, and a family reopened it last spring with a hot food counter. Everyone goes now.', true],
            ['We lost ours to a vape shop. I miss being able to grab eggs at 10pm.', true],
            ['The owner of our corner store in Brookland knows every kid on the block by name. He’s why I moved here.', true],
            ['There’s a new co-op grocery near Congress Heights and it’s been a game changer for seniors on our street.', true],
            ['I’d rather not be quoted, but our store in Trinidad started taking SNAP and it made a huge difference.', false],
            ['Ours never closed! Still the same owner since 1987. Shout out to Mr. Kim.', true],
            ['Following this one, curious what you find.', false],
        ];
        foreach ($answers as $i => [$body, $ok]) {
            $this->reply($this->crowd->random(), $callout, $body, $this->after($callout, 30 + $i * 90, 60 + $i * 120), $ok ? ['details' => ['quote_ok' => true]] : []);
        }
        $this->counts['callout replies'] = count($answers);

        // Private tips in the newsroom inbox.
        $tips = [
            ['The rec center pool on our block has been closed for three months and nobody can tell us when it’s reopening.', null, true, 'new', null],
            ['A new mural festival is planned for the alleys near Shaw next month. The organizers are neighbors and would love coverage.', 'https://example.com/shaw-mural-festival', true, 'new', null],
            ['Heard a beloved diner near Union Station might be closing at the end of the year. Staff mentioned it to regulars.', null, false, 'new', null],
            ['Dozens of street trees on our block in Congress Heights died after the summer. Who is responsible for replacing them?', null, true, 'following', $desmond],
            ['The Brookland arts walk has a new free studio program for teenagers. My daughter is in it and it’s wonderful.', null, true, 'following', $margot],
            ['The corner store in Petworth reopened with a hot food counter, run by the same family that owned it in the 90s.', null, true, 'used', $desmond],
            ['My neighbor’s cat is the real mayor of 14th Street.', null, false, 'dismissed', null],
        ];
        foreach ($tips as $i => [$body, $link, $contact, $status, $assignee]) {
            $when = $this->now->copy()->subHours(4 + $i * 15);
            Tip::forceCreate(['user_id' => $this->crowd->random()->id, 'body' => $body, 'link' => $link, 'contact_ok' => $contact, 'status' => $status, 'assigned_to' => $assignee?->id, 'created_at' => $when, 'updated_at' => $when]);
        }
        $this->counts['tips'] = count($tips);
    }

    // Safety and moderation

    protected function safety(): void
    {
        $password = Hash::make('password');
        $renata = $this->cast['renata_okafor'];
        $suggest = fn (Dispatch $d, array $payload) => DB::table('moderation_notes')->insert(['kind' => 'suggestion', 'dispatch_id' => $d->id, 'payload' => json_encode($payload), 'created_at' => $this->now, 'updated_at' => $this->now]);
        $rules = config('stoop.rule_reasons');

        // Two neighbors who need a word from the moderators.
        $ron = $this->person('Ron Whitcomb', 'ron_whitcomb', 'A07', $password, ['created_at' => $this->now->copy()->subDays(200)]);
        $kendra = $this->person('Kendra Pell', 'kendra_pell', 'D06', $password, ['created_at' => $this->now->copy()->subDays(140)]);

        // Held for watch phrases, with what each phrase means.
        $held = [
            [$ron, 'green', 'A07', 'Property values will drop if the wrong element starts hanging around the new rec center.'],
            [$kendra, 'orange', 'D06', 'Not sure we want the Section 8 crowd at our block party this year. Just saying.'],
            [$ron, 'red', 'F05', 'Great new restaurant, just hope the east of the river types don’t find out about it.'],
        ];
        foreach ($held as $i => [$who, $line, $stop, $body]) {
            $d = $this->post($who, ['line' => $line, 'stop' => $stop, 'body' => $body, 'status' => 'held', 'screening' => ['watch' => WatchPhrases::matches($body)]], $this->now->copy()->subHours(2 + $i * 5));
            $suggest($d, ['explanation' => 'Uses a coded phrase for race or class to say some neighbors aren’t welcome.', 'action' => 'remove', 'rule' => $rules[0], 'penalty' => $i === 0 ? 'warning' : '24', 'confidence' => 'high']);
        }

        // Held by OpenAI's check, with its scores.
        $angry = $this->post($kendra, ['line' => 'yellow', 'stop' => 'D06', 'status' => 'held', 'screening' => ['openai' => ['harassment' => 0.64, 'harassment/threatening' => 0.12, 'violence' => 0.21]],
            'body' => 'Whoever keeps parking in the alley behind my house is a total clown and I hope your car gets towed into the river.'], $this->now->copy()->subHours(3));
        $suggest($angry, ['explanation' => 'Insulting and a little heated, but about a parking annoyance, not a real threat. Approving with a reminder is reasonable.', 'action' => 'approve', 'rule' => null, 'penalty' => 'none', 'confidence' => 'medium']);

        // A brand-new account that looks automated: its first posts wait for a moderator.
        $risky = $this->person('J Q', 'zxqv8841', null, $password, ['created_at' => $this->now->copy()->subHours(3), 'risk_score' => 90,
            'risk_reasons' => ['Throwaway email domain', 'Several sign-ups from the same network in an hour', 'Random-looking username'], 'signup_ip_hash' => hash('sha256', 'showcase-risky')]);
        $risky->forceFill(['email' => 'zxqv8841@mailinator.com'])->save();
        $riskyPost = $this->post($risky, ['line' => 'orange', 'stop' => 'F05', 'status' => 'held', 'body' => 'Selling Nats tickets cheap!!! Message me now, cash only, first come first served.'], $this->now->copy()->subHours(2));
        $suggest($riskyPost, ['explanation' => 'A new, high-risk account’s first post is a ticket sale pitch, which reads as spam.', 'action' => 'remove', 'rule' => SpamRings::RULE, 'penalty' => '24', 'confidence' => 'medium']);

        // A spam ring: brand-new accounts posting near-identical pitches with the same link.
        $ring = [['Tired of renting in DC? I help neighbors buy their first rental property with zero money down. Free guide at https://dcwealthfast.example.com', 'E04'],
            ['Tired of renting in DC? I help neighbors buy a first rental property with zero money down. Get the free guide at https://dcwealthfast.example.com', 'F05'],
            ['Tired of renting in DC?? I help neighbors buy their first rental property with zero money down! Free guide: https://dcwealthfast.example.com', 'B35'],
            ['Tired of renting in DC? I help my neighbors buy their first rental property with zero money down. Free guide at https://dcwealthfast.example.com today', 'E02']];
        $names = [['Cash Flow Coach', 'cashflow_7731'], ['Rental King DC', 'rentalking_5520'], ['Invest Pro', 'investpro_2291'], ['Deals For You', 'deals4u_8842']];
        $spam = [];
        foreach ($ring as $i => [$body, $stop]) {
            $spammer = $this->person($names[$i][0], $names[$i][1], null, $password, ['created_at' => $this->now->copy()->subHours(20 - $i * 3), 'risk_score' => 20, 'risk_reasons' => ['Random-looking username']]);
            $spam[] = $this->post($spammer, ['line' => 'green', 'stop' => $stop, 'body' => $body], $this->now->copy()->subHours(14 - $i * 3));
        }

        // The ring as the detector files it: one cluster for the copied text, and one for the link
        // (the detector itself, over new accounts only, so older sample posts can't join in).
        $first = $spam[0];
        SpamCluster::create(['kind' => 'duplicate', 'signature' => md5(SpamRings::normalize($first->body)), 'summary' => count($spam).' accounts posted near-identical text', 'status' => 'open',
            'members' => collect($spam)->pluck('user_id')->all(),
            'evidence' => collect($spam)->map(fn ($d) => ['user_id' => $d->user_id, 'username' => $d->user->username, 'dispatch_id' => $d->id, 'text' => Str::limit($d->body, 140), 'at' => (string) $d->created_at])->all()]);
        SpamRings::linkSpam();
        $this->counts['spam clusters'] = SpamCluster::where('status', 'open')->count();

        // Reports.
        $reports = 0;
        foreach (array_slice($spam, 0, 2) as $d) {
            foreach ($this->crowd->random(2) as $reporter) {
                Report::create(['user_id' => $reporter->id, 'dispatch_id' => $d->id, 'reason' => 'spam']);
                $reports++;
            }
        }
        $heated = $this->post($ron, ['line' => 'green', 'stop' => 'A07', 'body' => 'If the new apartments bring more renters to this street, I’m done going to ANC meetings. These people never stay.'], $this->now->copy()->subHours(9));
        foreach ($this->crowd->random(2) as $reporter) {
            Report::create(['user_id' => $reporter->id, 'dispatch_id' => $heated->id, 'reason' => 'hate', 'note' => 'Talking down about renters as a group.']);
            $reports++;
        }
        $suggest($heated, ['explanation' => 'Frustrated about development, and “these people” is about renters, not a protected group. Within the rules.', 'action' => 'approve', 'rule' => null, 'penalty' => 'none', 'confidence' => 'low']);
        $this->counts['reports'] = $reports;

        // Penalties: a warning, and a timeout under appeal. Renata made the calls, so other moderators review the appeal.
        Penalty::forceCreate(['user_id' => $ron->id, 'subject_hash' => $ron->subjectHash(), 'type' => 'warning', 'reason' => $rules[0], 'moderator_id' => $renata->id, 'created_at' => $this->now->copy()->subDays(12)]);
        $removed = $this->post($kendra, ['line' => 'red', 'stop' => 'D06', 'status' => 'removed', 'moderated_by' => $renata->id, 'moderation_reason' => $rules[2], 'screening' => ['openai' => ['harassment' => 0.71]],
            'body' => 'The guy who runs the stand at the market is a crook and somebody should teach him a lesson.'], $this->now->copy()->subDays(2));
        $timeout = Penalty::create(['user_id' => $kendra->id, 'subject_hash' => $kendra->subjectHash(), 'type' => 'timeout', 'reason' => $rules[2], 'dispatch_id' => $removed->id, 'moderator_id' => $renata->id, 'ends_at' => $this->now->copy()->addDay()]);
        $appeal = Appeal::create(['user_id' => $kendra->id, 'penalty_id' => $timeout->id, 'dispatch_id' => $removed->id, 'body' => 'I meant teach him a lesson with a bad review, not anything physical. He overcharged me twice. I get that it read badly and I’ll word it better.']);
        DB::table('moderation_notes')->insert(['kind' => 'appeal', 'appeal_id' => $appeal->id, 'created_at' => $this->now, 'updated_at' => $this->now, 'payload' => json_encode([
            'summary' => 'The member says “teach him a lesson” meant a bad review, which the post doesn’t say.',
            'supports' => ['The complaint is about overcharging, a real dispute rather than a personal attack.', 'The member acknowledges the wording and offers to rephrase.'],
            'contradicts' => ['“Somebody should teach him a lesson” reads as a call for others to act.', 'OpenAI scored the post 0.71 for harassment.'],
        ])]);
        $this->counts['penalties'] = 2;

        // A pile-on: replies paused, flagged for moderators, and @calm's one reminder.
        [$stop, $root, $replies] = self::PILE_ON;
        $author = $this->cast['bernie_ruiz'];
        $thread = $this->post($author, ['line' => 'green', 'stop' => $stop, 'body' => $root], $this->now->copy()->subMinutes(45));
        $strangers = $this->crowd->whereNotIn('id', DB::table('follows')->where('followee_id', $author->id)->pluck('follower_id'))->where('id', '!=', $author->id)->values();
        foreach ($replies as $i => $body) {
            $this->reply($strangers[$i % $strangers->count()], $thread, $body, $this->now->copy()->subMinutes(38 - $i * 2));
        }
        $thread->forceFill(['flagged_at' => $this->now->copy()->subMinutes(8), 'replies_locked_until' => $this->now->copy()->addMinutes(52)])->saveQuietly();
        $this->reply(Bot::find('calm')->account(), $thread, Calm::LINES[$thread->id % count(Calm::LINES)], $this->now->copy()->subMinutes(5));
    }

    // Messages and the owner's notifications

    protected function messages(): void
    {
        $c = $this->cast;
        $chats = [
            [null, [$c['pilar_vega'], $c['hattie_vu']], ['Did Kiwi ever come home?', 'Not yet! But someone saw a green bird by the dog park this morning.', 'I’ll walk over at lunch and whistle.', 'You’re the best. He likes the song from the ice cream truck.']],
            [null, [$c['desmond_pryor'], $c['adaeze_n']], ['Hi! Desmond from the magazine. Loved your corner store reply. OK if I call you for the story?', 'Of course! Afternoons are best.', 'Perfect, I’ll ring Thursday around 3.']],
            [null, [$c['jasper_cole'], $c['sunny_okoye']], ['Saving you two tickets for Saturday.', 'Legend. I’ll bring the pretzel mustard.']],
            ['Petworth garden crew', [$c['dom_ibarra'], $c['yetunde_bello'], $c['hollis_park'], $c['delia_haskins']], ['Saturday at 10, library back gate.', 'I’ve got the mulch.', 'Bringing donuts for the volunteers.', 'Kids are bringing their own tiny shovels.', 'This is going to be the best garden in Ward 4.']],
            ['Stoop skate night', [$c['delia_haskins'], $c['lorraine_tate'], $c['rashad_greene'], $c['odell_jenkins'], $c['sunny_okoye']], ['Friday at 8, meet at the rink entrance.', 'Knee pads acquired.', 'I’m bringing a speaker in case the DJ needs backup.', 'Pizza after?', 'Always pizza after.']],
        ];

        // The staff room, with the owner in it when they're here.
        if ($owner = User::where('username', 'tshafer')->first()) {
            $chats[] = ['Staff room', [$c['margot_ellison'], $c['desmond_pryor'], $c['renata_okafor'], $c['calvin_brooks'], $owner], ['Ask the editor thread is live, questions are rolling in.', 'Queue is clear except a spam ring from overnight, handling it now.', 'Two new place owners verified this week, deals are up.', 'Callout replies are great, three people said we can quote them.']];
        }

        $count = 0;
        foreach ($chats as $i => [$name, $people, $lines]) {
            $start = $this->now->copy()->subHours(30 - $i * 5);
            $conversation = Conversation::create(['is_group' => (bool) $name, 'name' => $name, 'created_by' => $people[0]->id, 'last_message_at' => $start->copy()->addMinutes(count($lines) * 7)]);
            foreach ($people as $j => $person) {
                DB::table('conversation_participants')->insert(['conversation_id' => $conversation->id, 'user_id' => $person->id, 'role' => $j === 0 && $name ? 'admin' : 'member', 'accepted_at' => $start, 'last_read_at' => $j === 0 ? $start->copy()->addMinutes(count($lines) * 7) : null, 'created_at' => $start, 'updated_at' => $start]);
            }
            foreach ($lines as $j => $text) {
                $message = $conversation->messages()->create(['user_id' => $people[$j % count($people)]->id, 'body' => $text]);
                $message->forceFill(['created_at' => $start->copy()->addMinutes($j * 7), 'updated_at' => $start->copy()->addMinutes($j * 7)])->saveQuietly();
                $count++;
            }
        }
        $this->counts['conversations'] = count($chats);
        $this->counts['messages'] = $count;
    }

    /**
     * Fresh notifications for the owner (@tshafer): follows, replies, mentions, a quote and a quote request.
     * Only rows that point at them; their own account is never changed.
     */
    protected function owner(): void
    {
        $owner = User::where('username', 'tshafer')->first();
        if (! $owner) {
            return;
        }

        $sent = 0;
        $notify = function (Notification $notification, CarbonInterface $when) use ($owner, &$sent) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => $notification::class, 'notifiable_type' => $owner->getMorphClass(), 'notifiable_id' => $owner->id,
                'data' => json_encode($notification->toArray($owner)), 'read_at' => null, 'created_at' => $when, 'updated_at' => $when]);
            $sent++;
        };

        foreach ($this->crowd->take(12)->concat([$this->cast['margot_ellison']])->random(8) as $i => $fan) {
            DB::table('follows')->insertOrIgnore(['follower_id' => $fan->id, 'followee_id' => $owner->id, 'created_at' => $this->now->copy()->subHours(1 + $i * 4), 'updated_at' => $this->now]);
            $notify(new StoopActivity('follow', $fan), $this->now->copy()->subHours(1 + $i * 4));
        }

        $mentions = [['red', 'E04', '@tshafer you have to try the pupusa window on Mount Pleasant St. Loroco and cheese.'], ['orange', 'F06', 'Skate night is on Friday, @tshafer. Bring the whole crew.'], ['blue', 'D02', 'Thanks to @tshafer for building this place. My block finally talks to each other.']];
        foreach ($mentions as $i => [$line, $stop, $body]) {
            $by = $this->crowd->random();
            $d = $this->post($by, ['line' => $line, 'stop' => $stop, 'body' => $body], $this->now->copy()->subHours(2 + $i * 6));
            $notify(new StoopActivity('mention', $by, $d), $d->created_at);
        }

        $theirs = $owner->dispatches()->whereNull('parent_id')->where('status', 'approved')->latest()->take(2)->get();
        foreach ($theirs as $post) {
            foreach (['Welcome to the neighborhood!', 'Ha, love this.', 'Agreed, more of this please.'] as $i => $body) {
                $by = $this->crowd->random();
                $reply = $this->reply($by, $post, $body, $this->now->copy()->subMinutes(20 + $i * 50));
                $notify(new StoopActivity('reply', $by, $reply), $reply->created_at);
            }
        }

        if ($post = $theirs->first()) {
            $by = $this->crowd->random();
            $quote = $this->post($by, ['line' => $post->line, 'quote_id' => $post->id, 'body' => 'This is why I check Stoop every morning.'], $this->now->copy()->subMinutes(90));
            $notify(new StoopActivity('quote', $by, $quote), $quote->created_at);

            $editor = $this->cast['desmond_pryor'];
            $request = QuoteRequest::create(['dispatch_id' => $post->id, 'editor_id' => $editor->id, 'status' => 'pending', 'expires_at' => $this->now->copy()->addDays(QuoteRequest::DAYS)]);
            DB::table('quote_consents')->insert(['quote_request_id' => $request->id, 'dispatch_id' => $post->id, 'user_id' => $editor->id, 'username' => $editor->username, 'action' => 'requested', 'body_hash' => hash('sha256', (string) $post->body), 'created_at' => $this->now]);
            $notify(new MagazineActivity('quote_request', $editor, $post, ['quote_request_id' => $request->id]), $this->now->copy()->subMinutes(15));
        }

        $this->counts['notifications for @tshafer'] = $sent;
    }

    // Helpers

    protected function post(User $user, array $attrs, CarbonInterface $when): Dispatch
    {
        $d = new Dispatch;
        $d->forceFill($attrs + ['user_id' => $user->id, 'type' => 'dispatch', 'status' => 'approved', 'created_at' => $when, 'updated_at' => $when])->save();

        if (in_array($d->type, ['been', 'happening'], true) && $d->status === 'approved') {
            $this->mark($user, $d, $d->type === 'been' ? 'been' : 'going');
        }

        return $d;
    }

    protected function reply(User $user, Dispatch $parent, string $body, CarbonInterface $when, array $extra = []): Dispatch
    {
        return $this->post($user, ['parent_id' => $parent->id, 'line' => $parent->line, 'body' => $body, ...$extra], $when);
    }

    protected function mark(User $user, Dispatch $d, string $kind): void
    {
        $when = $this->after($d, 1, 1200);
        $this->marks[] = ['user_id' => $user->id, 'dispatch_id' => $d->id, 'kind' => $kind, 'created_at' => $when, 'updated_at' => $when];
    }

    /**
     * Sometime in the last week (or $hours), with over half of it since Monday so this week's games and standings fill in.
     */
    protected function recent(?int $hours = null): CarbonInterface
    {
        $sinceMonday = max(90, (int) abs($this->now->diffInMinutes($this->week)));
        $minutes = match (true) {
            $hours !== null => mt_rand(20, $hours * 60),
            mt_rand(1, 100) <= 55 => mt_rand(20, $sinceMonday),
            default => mt_rand($sinceMonday, 7 * 1440),
        };

        return $this->now->copy()->subMinutes($minutes);
    }

    /**
     * A few minutes after a post, but never in the future.
     */
    protected function after(Dispatch $d, int $min, int $max): CarbonInterface
    {
        return Carbon::instance($d->created_at)->addMinutes(mt_rand($min, max($min, $max)))->min($this->now->copy()->subMinute());
    }
}
