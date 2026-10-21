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

Seeded logins (password `password`): `demo@example.com` (regular user) and `food@example.com` (staff, can open `/moderation` and `/operations`). All seeded people and posts are sample data.

`composer run dev` starts everything: the web server, queue, Vite, the Reverb server for real-time updates, and the scheduler (scheduled posts, article import, the weekly email).

Optional settings in `.env`:

- `ANTHROPIC_API_KEY`: lets @stoop answer with Claude, turns on translation and the reader insights summary. Without it, @stoop answers from `resources/knowledge/stoop.md` and the Translate button is hidden.
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

Staff pages for this area: Admin → Real estate (sales import status, open houses, the sponsored label) and Admin → Insights (the k-anonymous reader report for advertisers, with CSV and printable views).

Happenings, Lost & found and games: `/happenings` (calendar, .ics, a private feed link at `/calendar/<token>.ics`), `/lost-and-found`, `/bingo`, `/hunts` and `/line-cup`. Editors create hunts in Admin → Hunts and pull readers’ picks (CSV) from Admin → Readers’ picks. Ticketed happenings link out to the host’s ticket page; Stoop takes no payments.

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

- **@eats**: reply “@eats Ethiopian near U Street” for up to three places from the magazine’s lists near that station (stations within about 1.2 km) or neighborhood. Keyword matching; Claude re-ranks the matches when `ANTHROPIC_API_KEY` is set.
- **@weekend**: Fridays at noon, a round-up of city events and Happening posts for Friday to Sunday. Staff mark editors’ picks from a Happening post’s ••• menu (“Pick for @weekend”). Skipped when nothing’s on.
- **@history**: 9am “On this day in DC” from Admin → History. Seeded entries are marked VERIFY and don’t post until an editor checks them. “Draft with AI” fills the form when the key is set.
- **@quizmaster**: The Daily at 7am, the five longest current streaks at 8pm, and one cheer per shared result.
## Staff roles and spam tools

Staff have roles: moderator, editor, sales and admin (admin does everything; existing staff became admins). Admins set them in Admin → Roles. Admin → Spam lists suspected spam rings (`php artisan stoop:detect-spam`, also every 10 minutes); Admin → Bots reviews the house bots and edits @stoop’s knowledge base; Admin → Activity shows a live feed and 30-day charts. Admins get a daily email when moderation items wait more than a day (`stoop:admin-digest`). Throwaway email domains and the risk score that holds a new account’s first posts are in `config/stoop.php`.

## Tests

```
php artisan test
```
