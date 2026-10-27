# Agent Host

Multi-agent host for Claude Code, Google Antigravity CLI and OpenRouter, with a cascading failover chain and an LLM Council war room.

Laravel 13 + PostgreSQL backend, React 19 frontend built with Vite.

## Layout

| Concern | Location |
| --- | --- |
| Models | `app/Models` |
| Controllers | `app/Http/Controllers` |
| Agent, council, tmux and OpenRouter logic | `app/Services` |
| Background work (OpenRouter replies, council deliberation) | `app/Jobs` |
| tmux discovery and failover loop | `app/Console/Commands/MonitorAgents.php` |
| React views | `resources/js/components` |
| Styles | `resources/css` |
| Defaults (models, advisors, fallback chain) | `config/agenthost.php` |

## Setup

```bash
cp .env.example .env            # set DB_USERNAME / DB_PASSWORD for PostgreSQL
composer install
npm install
php artisan key:generate
createdb agent_host
php artisan migrate --seed
```

## Run (development)

```bash
composer dev
```

Starts the web server on http://localhost:3116, Vite, the default and `council` queue workers, the log tail, and `agents:monitor`.

## Run (production)

```bash
npm run build
php artisan serve --host=0.0.0.0 --port=3116
php artisan queue:work --tries=1 --timeout=0
php artisan queue:work --queue=council --tries=1 --timeout=0
php artisan agents:monitor
```

Keep `DB_QUEUE_RETRY_AFTER` above the longest council run (default 1900 seconds).

## Tests

```bash
php artisan test
```
