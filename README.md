# up-keep-agent

A small Laravel package that lets up-keep, a private self-hosted uptime monitor, see inside an app. up-keep already checks each app from the outside; the agent reports what only the app itself knows:

- **Errors:** every exception the app reports, grouped and counted.
- **Health:** whether the database and Redis answer, how long the oldest ready job has waited in each queue, and when the scheduler last ran.
- **Server:** CPU, memory and disk use, plus the PHP, Laravel and OS versions.

up-keep reads it all from one page, `/upkeep-agent`, every minute. The app never calls up-keep.

Works with Laravel 12 and 13 on PHP 8.2 or newer, including Octane.

## Install

1. Require the package in the app:

   ```bash
   composer require passioncorners/up-keep-agent
   ```

2. On the machine that runs up-keep, run `php artisan upkeep:agent`, pick the app, and add the secret it prints to the app's production `.env`:

   ```dotenv
   UPKEEP_AGENT_SECRET=the-secret-up-keep-printed
   ```

3. Deploy as usual. `php artisan migrate` creates the agent's two tables (`upkeep_errors` and `upkeep_agent_state`), and `php artisan config:cache` picks up the secret. On Octane, reload the workers afterwards.

There is nothing else to change in the app. The agent adds its own every-minute task (`upkeep-agent:sample`) to the app's scheduler, so the scheduler must be running, as it already is for any app with scheduled work.

Without a secret the page answers 404, but errors and the scheduler check-in are still recorded, so turning the agent on later shows recent history.

## What it never sends

Request bodies, headers, cookies, query-string values, the logged-in user, environment values and SQL bound values never leave the app. URLs are reported as route patterns (`/orders/{order}`), and database error messages keep their `?` placeholders instead of the values.

Errors that haven't happened for 30 days are deleted.

## Development

```bash
composer test   # Pint, PHPStan (level 7) and Pest
```

Tests use a temporary SQLite file. To run them against MySQL, set `UPKEEP_TEST_DB=mysql` and the `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` variables. The Redis queue test runs when Redis answers on `127.0.0.1:6379`.
