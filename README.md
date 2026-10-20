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

Seeded logins (password `password`): `demo@example.com` (regular user) and `food@example.com` (staff, can open `/moderation`). All seeded people and posts are sample data.

## Tests

```
php artisan test
```
