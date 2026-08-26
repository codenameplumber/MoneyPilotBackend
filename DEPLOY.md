# Deploying MoneyPilot

Laravel 13 · Livewire 3 · MySQL 9 · PHP 8.3 · **no frontend build step**

There is no `npm run build`. Blade renders everything, the CSS is hand-written
and served from `public/css`, and the only JavaScript is an icon hydrator and a
theme toggle. Nothing to compile means nothing to break in CI.

---

## Queues on Laravel Cloud

### What is queued, and why it matters

Exactly one thing today: the **welcome email**, sent when somebody finishes
registering. It is queued so SMTP never sits between a person and the screen
they just spent three steps building. If the mail provider is slow, the member
does not wait — and if it is down, the job retries instead of losing the email.

Everything else is synchronous on purpose. A budget must exist before the
dashboard renders, so `BudgetService::rebuild()` is not a job.

### Setting it up

**1 · Add a Redis database.** In your Laravel Cloud environment →
**Databases** → add **Redis**. Cloud injects `REDIS_*` for you; do not paste a
connection string by hand.

**2 · Set the environment variables:**

```
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

Redis for cache matters more than it looks. The console caches its coverage grid
and network health, and `database` cache on a multi-container deployment means
each container keeps its own copy — so an operator's numbers change depending on
which container answered.

**3 · Add a worker process.** Environment → **Processes** → **Add process**:

| Field | Value |
| --- | --- |
| Command | `php artisan queue:work --queue=default --tries=3 --max-time=3600` |
| Size | the smallest tier — this queue is nearly idle |
| Instances | 1 |

`--max-time=3600` recycles the worker hourly. A long-lived PHP process
accumulates memory and holds a stale copy of the bootstrapped app; restarting it
is cheaper than debugging why a job saw old config.

**4 · Add the scheduler.** Environment → **Scheduler** → enable it, or add a
process running `php artisan schedule:work`.

This is not optional. `routes/console.php` schedules `prices:refresh` hourly,
and the staleness weighting halves every 21 days — so a median moves even on a
day when nobody reports anything. Without the scheduler, every price in the
product slowly drifts out of date while continuing to look authoritative.

**5 · Deploy commands.** Environment → **Deploy script**:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

`queue:restart` is the one people forget. Workers are long-lived, so without it
they keep running the code from the previous deploy.

### Checking it works

```bash
php artisan queue:monitor default --max=100   # alert if the queue backs up
php artisan queue:failed                      # anything that gave up after 3 tries
php artisan queue:retry all
```

### Locally

`QUEUE_CONNECTION=database` and the `jobs` table already exists, so:

```bash
php artisan queue:work --tries=3
```

Or set `QUEUE_CONNECTION=sync` to run jobs inline and skip the worker entirely —
fine for development, wrong for production, because the request then waits on
SMTP.

---

## Environment

### Database

```
DB_CONNECTION=mysql
DB_DATABASE=moneypilot
```

Laravel Cloud injects the rest. Seeding takes about 80 seconds and is safe to
run once on a fresh environment:

```bash
php artisan migrate:fresh --seed --force
```

**Never on an environment with real members** — it drops every table.

### Mail

Mailtrap is active in `.env` for testing. Resend is written underneath it,
commented, ready to swap:

```bash
composer require resend/resend-laravel
```

```
MAIL_MAILER=resend
RESEND_API_KEY=re_xxxxxxxxxxxx
MAIL_FROM_ADDRESS="hello@yourdomain.com"
```

Verify the sending domain with Resend first. `MAIL_FROM_ADDRESS` must be on that
domain or every send is rejected — which looks exactly like a broken queue.

Check the whole path end to end:

```bash
php artisan mail:check
```

Sends the verification link, the password reset and the first-budget email
through whatever mailer is configured, and reports which ones failed.

### OpenAI

```
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
OPENAI_ENABLED=true
```

Entirely optional. Leave the key blank and the product behaves identically, in
the engine's own words — the model only ever rephrases an answer the engine has
already computed, and any rewrite that drops, alters or invents a naira figure
is discarded before anyone sees it.

Set `OPENAI_ENABLED=false` to force the deterministic path even with a key
present. Useful for a demo where cost or latency is a risk.

Verify the key against the real API before you need it to work:

```bash
php artisan ai:check          # one real call, plus the figure guard
php artisan ai:check --live   # also asks one real member question end to end
```

It prints the model, a fingerprint of the key (never the key), the latency, and
whether the rewrite was accepted or rejected. A failure names the likely cause:
401 invalid key, 429 no credit, 404 unknown model.

`max_tokens` is what the Chat Completions API expects for `gpt-4o-mini`. The
newer reasoning models want `max_completion_tokens` instead and will reject the
request with a 400, so changing `OPENAI_MODEL` to one of those needs a one-line
change in `OpenAiService::chat()` as well.

---

## Before a demo

```bash
php artisan demo:reset      # ~1s — clears activity on the four demo accounts
php artisan perf --warm     # times every screen, flags anything slow
```

`demo:reset` restores an empty assistant thread and a zero contributor record on
the demo accounts, which is what makes them worth showing. Walking through the
app spends that; this puts it back without a full reseed.

---

## Health

| Command | What it tells you |
| --- | --- |
| `php artisan test` | 133 tests over the engine, screens, email, AI seam and scale |
| `php artisan perf --warm` | Per-screen wall clock and query count |
| `php artisan prices:refresh` | Recomputes every median by hand |
| `php artisan mail:check` | Sends one of each email for real |
| `php artisan queue:failed` | Jobs that gave up |
| `node tools/shots.mjs <email>` | Screenshots every screen, reports console errors |
