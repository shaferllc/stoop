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

Seeded logins (password `password`): `demo@example.com` (regular user) and `food@example.com` (staff, can open `/moderation` and `/operations`). All seeded people and posts are sample data.

Run the scheduler and queue worker too (`composer run dev` starts the queue): scheduled dispatches publish and deleted ones are erased by `php artisan schedule:work`.

Optional settings in `.env`:

- `ANTHROPIC_API_KEY`: lets @stoop answer with Claude and turns on translation. Without it, @stoop answers from `resources/knowledge/stoop.md` and the Translate button is hidden.
- `WMATA_API_KEY`: shows live Metro delays in the sidebar.
- `STOOP_REQUIRE_SUBSCRIPTION=true`: only subscribers can post.

## Tests

```
php artisan test
```
