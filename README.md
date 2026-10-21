# Stoop

A social network for DC, modeled on [/dev/null](https://dev-null.sh). Laravel 13, Livewire 4, Flux, Tailwind 4, SQLite.

- What /dev/null has and how it maps to Stoop: [docs/DEVNULL_FEATURES.md](docs/DEVNULL_FEATURES.md)
- What's built and what's next: [TASKS.md](TASKS.md)

## Run it

```
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
composer run dev
```

For a full month of sample activity on top of that (about 60 neighbors, hundreds of posts and replies, places, passports, polls, quizzes, rivalries, events, game picks, messages and a moderation queue), run `php artisan db:seed --class=DemoCitySeeder`. It adds to the database without wiping it, and every sample person logs in with the password `password`.

To show off everything at once, run `php artisan db:seed --class=ShowcaseSeeder` after that: about 40 more neighbors (two editors, a moderator and a sales staffer), a fresh week on every line, the house bots, happenings, lost & found, open houses, the games, the newsroom tools, a moderation queue with a spam ring and an appeal, and new notifications for `@tshafer`. It adds to the database, runs only once (the `showcase_seeded_at` site setting) and takes a few seconds.

Seeded logins (password `password`): `demo@example.com` (regular user) and `food@example.com` (staff, can open `/moderation` and `/operations`). All seeded people and posts are sample data.

`composer run dev` starts everything: the web server, queue, Vite, the Reverb server for real-time updates, and the scheduler (scheduled posts, article import, the weekly email).

Optional settings in `.env`:

- `ANTHROPIC_API_KEY`: lets @stoop answer with Claude, turns on translation, the reader insights summary, Catch me up summaries, composer suggestions from Claude and ANC summary drafts. Without it, @stoop answers from `resources/knowledge/stoop.md` and the Translate button is hidden.
- `WMATA_API_KEY`: shows live Metro delays in the sidebar, and lets @metro post major disruptions and answer “@metro next <station>”.
- `STOOP_REQUIRE_SUBSCRIPTION=true`: only subscribers can post.
- `STOOP_MAGAZINE_FEED`: the magazine’s RSS or Atom feed. New articles post to Stoop automatically.
- `STOOP_SUBSCRIPTION_SECRET`: shared secret for the subscription webhook (`POST /api/subscriptions`, see `app/Http/Controllers/Api/SubscriptionWebhookController.php`).
- `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET`: Cloudflare’s bot check on sign-up.
- `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`: push notifications (already generated for local use).
- `OPENAI_API_KEY`: OpenAI’s free moderation endpoint checks every new post (text and photos) first and holds hate, harassment, threats and similar. Thresholds are in `config/stoop.php` (`openai_thresholds`).
- `ANTHROPIC_API_KEY` also turns on moderator help (the moderation page, Admin → Insights, person pages and the Stoop Admin app): a suggested call on each queue item, notes on appeals, a brief on a person's record, and a second look for coded hate at posts OpenAI scored as borderline (it can only hold them). Without the key, moderators still get past decisions on similar posts, plain-fact person summaries, threshold insights, watch phrases and blurring.
- `STOOP_MODEL_MODERATION=false`: turn off the Claude check on new posts while keeping the bot and translation. The model defaults to `claude-opus-5-5`; `STOOP_MODERATION_MODEL` changes it.
- `STOOP_AUTO_VERIFY=false`: require the email link again.
- `STOOP_DC_311=false`: hide the nearby DC 311 requests on stop pages (on by default; DC GIS needs no key).

Ads (sales role): Admin → Ads makes sponsored dispatches and Best of Washington party ticket promotions, and lists every running and upcoming placement with delivery, clicks, CTR and CSVs (also in the Mac app). Ads live apart from dispatches, show at most once per 10 posts, are matched only to the stop or topic being read, count an impression once per person per day (a keyed hash of the account or session and the day, no extra cookie), and click through `/ad/{kind}/{id}` to their stored link only. There is no paid ad-free option.

“Presented by” (Admin → Ads, sales): one sponsor at a time per house bot or game (`presenters` table, App\Models\Presenter). `Bot::post()` adds the line to a bot’s top-level scheduled posts unless the post passes `'sponsorable' => false` (safety posts do); a post too long for it drops whole lines from the end, and a one-line post it can't fit is left alone; replies never get it. Games use `<x-presented-by target="…" />`. Sponsored meetups (Admin → Moments, sales): a host venue on a Stoop Sits meetup (`meetup_sponsors`), RSVPs (`event_rsvps`), and offer codes for checked-in attendees (`meetup_redemptions`) the venue owner marks used on their place page. Both are ad kinds `presenter` and `meetup` in App\Support\Ads.

Staff pages for this area: Admin → Real estate (sales import status, open houses, the sponsored label) and Admin → Insights (the k-anonymous reader report for advertisers, with CSV and printable views).

Happenings, Lost & found and games: `/happenings` (calendar, .ics, a private feed link at `/calendar/<token>.ics`), `/lost-and-found`, `/bingo`, `/hunts` and `/line-cup`. Editors create hunts in Admin → Hunts and pull readers’ picks (CSV) from Admin → Readers’ picks. Ticketed happenings link out to the host’s ticket page; Stoop takes no payments.

Magazine events and list challenges: `stoop:import-events` (hourly) posts the “Things to Do” roundups from `STOOP_MAGAZINE_FEED` as @magazine Happenings; Admin → Magazine events shows the last run, has “Import now” and takes a CSV (`title, date, time, venue, url, description`). `/challenges` lists challenges on the magazine’s lists (“Eat at 10 of the 100 Very Best”); editors create them in Admin → Challenges and download finishers as CSV to send prizes by hand. `stoop:check-challenges` (hourly) catches finishes the check-in itself missed.

Embed on magazine article pages:

```html
<div data-stoop-discussion></div>               <!-- neighbors talking about this article -->
<div data-stoop-neighborhood="E02"></div>       <!-- what neighbors at a Metro stop are saying -->
<div data-stoop-place="le-diplomate"></div>     <!-- Been here count and recent check-ins (slug or id) -->
<div data-stoop-quote="12"></div>               <!-- a reader quote its author allowed -->
<div data-stoop-poll="345"></div>               <!-- live results of a reader poll -->
<div data-stoop-byline="ann-limpert"></div>     <!-- a writer's byline with a follow link -->
<script src="https://stoop.test/embed.js" async></script>
```

The boxes read public JSON from `/api/embed/*` (cached, throttled, no cookies or tracking). Editors post reader polls and manage writers and quote requests in Admin → Magazine; “Request to quote” is in the … menu on any dispatch, and the author allows or declines from Notifications (every step is kept in `quote_consents`). Writers are made from the bylines (`dc:creator` or Atom `author`) of imported articles and have pages at `/authors/{slug}` that readers can follow. `php artisan stoop:backfill-authors` credits writers on articles imported before bylines were stored.
## House bots

Each bot is one class in `app/Bots`, found automatically, with its scheduled posts on the scheduler (DC time):

Every bot answers a mention with something specific, no AI needed: @stoop greets with things to try and answers from its knowledge base (or points to /play, /happenings, /bots, /settings or /rules), “@metro” lists delays and says when Metro is closed (hours in `stoop.metro_hours`), “@weather” gives the NWS forecast and alerts, “@cherry” the bloom stage or season dates (last peak from NPS or `STOOP_CHERRY_LAST_PEAK`), “@ask what’s happening tonight” the calendar, “@weekend” the round-up any day, and @311, @quizmaster, @recap, @history, @homes and @linecup their own data. Mentioning @magazine gets its three latest stories.

- **@eats**: reply “@eats Ethiopian near U Street” for up to three places from the magazine’s lists near that station (stations within about 1.2 km) or neighborhood. Keyword matching; Claude re-ranks the matches when `ANTHROPIC_API_KEY` is set.
- **@weekend**: Fridays at noon, a round-up of city events and Happening posts for Friday to Sunday. Staff mark editors’ picks from a Happening post’s ••• menu (“Pick for @weekend”). Skipped when nothing’s on.
- **@history**: 9am “On this day in DC” from Admin → History. Seeded entries are marked VERIFY and don’t post until an editor checks them. “Draft with AI” fills the form when the key is set.
- **@quizmaster**: The Daily at 7am, the five longest current streaks at 8pm, and one cheer per shared result.
- **@bingo**: new Metro Bingo cards Mondays at 8am, a count of the week’s bingos Sundays at 6pm (counts only), and “@bingo” replies with your card progress.
- **@hunt**: a hunt’s first clue when it opens, a 10am clue reminder while one runs, a first-finisher shout-out, and “@hunt” for your next clue. Never says answers or code words.
- **@linecup**: Line Cup standings with friendly trash talk aimed at lines Mondays at 9am, and the winning line on the 1st.
- **@sentinel**: staff only, never posts. Hourly in-app summary to moderators of the last hour’s spam clusters, held and watch-phrase posts, OpenAI flags and reports (Claude writes it from counts when `ANTHROPIC_API_KEY` is set). Also on the admin Overview, the Mac app Overview and `GET /api/admin/sentinel`.
- **@gameday**: from 8am, a pick thread for each DC game that day (Going Out line, at the venue’s stop; picks close at the start), and the final score in that thread once. Reply “@gameday Caps” for the live score, last result or next game. Its `stoop:sync-sports` run every 5 minutes imports schedules and scores for the teams in `config/sports.php` from ESPN’s public JSON (no key): hourly, or every run while a DC game is live. `php artisan stoop:sync-sports --force` imports now. Scores, standings, team pages and watch parties are at `/sports`.
## Staff roles and spam tools

Staff have roles: moderator, editor, sales and admin (admin does everything; existing staff became admins). Admins set them in Admin → Roles. Admin → Spam lists suspected spam rings (`php artisan stoop:detect-spam`, also every 10 minutes); Admin → Bots reviews the house bots and edits @stoop’s knowledge base; Admin → Activity shows a live feed and 30-day charts. Admins get a daily email when moderation items wait more than a day (`stoop:admin-digest`). Throwaway email domains and the risk score that holds a new account’s first posts are in `config/stoop.php`.

## Newsroom tools

Staff find these under Admin → Tips, Radar, Call-outs, Archive and Issues; Radar and Tips are also in the Stoop Admin Mac app.

- Readers send private tips from the footer or a dispatch’s … menu; editors reply in Stoop messages only if the tipster allowed it.
- Radar compares the last 7 days with the 4 weeks before. With `ANTHROPIC_API_KEY`, each trend can get a Claude story idea.
- Archive stories are imported as CSV (`title,url,date,section,stations,neighborhoods,description`) and tagged to stations by name and the alias list. Station pages show three of them.
- When a feed article changes, or an editor posts a correction, @magazine replies in its thread and everyone who joined in is notified once.

## Tests

```
php artisan test
```
