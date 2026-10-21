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

- `ANTHROPIC_API_KEY`: lets @stoop answer with Claude and turns on translation. Without it, @stoop answers from `resources/knowledge/stoop.md` and the Translate button is hidden.
- `WMATA_API_KEY`: shows live Metro delays in the sidebar.
- `STOOP_REQUIRE_SUBSCRIPTION=true`: only subscribers can post.
- `STOOP_MAGAZINE_FEED`: the magazine’s RSS or Atom feed. New articles post to Stoop automatically.
- `STOOP_SUBSCRIPTION_SECRET`: shared secret for the subscription webhook (`POST /api/subscriptions`, see `app/Http/Controllers/Api/SubscriptionWebhookController.php`).
- `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET`: Cloudflare’s bot check on sign-up.
- `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`: push notifications (already generated for local use).
- `OPENAI_API_KEY`: OpenAI’s free moderation endpoint checks every new post (text and photos) first and holds hate, harassment, threats and similar. Thresholds are in `config/stoop.php` (`openai_thresholds`).
- `STOOP_MODEL_MODERATION=false`: turn off the Claude check on new posts while keeping the bot and translation. The model defaults to `claude-opus-5-5`; `STOOP_MODERATION_MODEL` changes it.
- `STOOP_AUTO_VERIFY=false`: require the email link again.

House bots live in `app/Bots`, one class each, and register their own schedules. @ask links unanswered Ask DC questions (2+ hours old) to similar answered ones; @welcome sends each new account one in-app note with tips for their home stops and people to follow; @recap posts “Your stop this week” for the 5 busiest stops on Sunday mornings; reply `@translate to Spanish` in a thread to translate the post above; @calm posts one neighborly reminder when a pile-on-flagged thread reopens. Only @ask (one framing sentence) and @translate use Claude, and both work or say so without `ANTHROPIC_API_KEY`.

Embed on magazine article pages:

```html
<div data-stoop-discussion></div>
<script src="https://stoop.test/embed.js" async></script>
```

## Tests

```
php artisan test
```
