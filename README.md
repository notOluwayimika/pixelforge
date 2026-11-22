# Pixelforge for PromptEdit

A Laravel 13 + Vue 3 + Inertia app where signed-in users generate images through the (simulated)
[Pixelforge API](https://pixelforge.promptedit.dev/docs) and pay Pixelforge's cost plus 25%.

- **Live:** _deployed URL goes here_
- **Design notes:** [docs/DESIGN.md](docs/DESIGN.md)

## What to look at first

| Concern | Where |
|---|---|
| Money type and markup | [`app/Billing/Nanodollars.php`](app/Billing/Nanodollars.php) |
| Cost from tokens × rates | [`app/Pixelforge/Rates.php`](app/Pixelforge/Rates.php) |
| Estimate before submit | [`app/Billing/CostEstimator.php`](app/Billing/CostEstimator.php) |
| Every status change (and the only place cost is written) | [`app/Pixelforge/GenerationStateMachine.php`](app/Pixelforge/GenerationStateMachine.php) |
| Webhooks: signature, dedupe, ordering | [`app/Http/Controllers/Webhooks/PixelforgeWebhookController.php`](app/Http/Controllers/Webhooks/PixelforgeWebhookController.php) |
| Rate limits, concurrency, overloads, idempotency | [`app/Jobs/SubmitGeneration.php`](app/Jobs/SubmitGeneration.php) |
| Lost webhooks | [`app/Console/Commands/ReconcileGenerations.php`](app/Console/Commands/ReconcileGenerations.php) |
| Tests that pin all of the above | [`tests/Feature/Pixelforge`](tests/Feature/Pixelforge), [`tests/Unit/Billing`](tests/Unit/Billing) |

## Money

**Every amount is an integer number of nanodollars** (`BIGINT`, 1 = $0.000000001).

- Pixelforge reports exact nine-decimal USD strings, so nanodollars hold every provider amount exactly
  and our provider cost matches `GET /v1/usage` to the last digit. Decimal strings are parsed with
  string arithmetic. No floats are used anywhere.
- **Provider cost** = Σ tokens × rate. Rates are per 1M tokens, so we multiply first and divide by
  1,000,000 once at the end. Costs use the `rates` snapshot stored on each generation (fixed by Pixelforge
  at creation), not current pricing.
- **Charge** = provider cost × 1.25, computed as `(cost × 5 + 2) intdiv 4`. At current rates every cost
  is a multiple of 4 nanodollars, so this is exact. If future rates made it fractional, it rounds
  half-up to the nearest nanodollar (worst case ±$0.0000000005 per generation), and the rule is unit-tested.
- **Failures are free:** `charge` and `provider_cost` are set to `0` (not left null) when a generation
  fails, so "not charged" is explicit in the data.
- **Display** is exact too: `$0.3818875` is shown as is, with trailing zeros trimmed and never rounded.

### Estimates

Two of the four token counts are only known after completion (prompt tokens vary ±15%; reasoning
varies by quality). The Generate page shows **"about $X, at most $Y"**:

- *typical*: `ceil(chars / 4)` text tokens, midpoint reasoning
- *max*: text tokens + 15% (rounded up), maximum reasoning. This reproduces the docs' worked example
  ($0.373875) exactly.

Both are stored on the generation. The estimate is computed server-side by the same code that bills,
so the browser never does money maths. Token tables and rates come from `GET /v1/pricing` (cached for 10 min).

## Correctness under an unreliable provider

**Idempotency.** The generation row (with a UUID `idempotency_key`) is saved *before* we call
Pixelforge, and every POST attempt for that row sends the same key, so a retried request can't
create or bill a second generation. Our row id also goes in `metadata`, because a webhook can arrive
before our POST returns. A user-initiated **Retry** of a failed generation creates a *new* row with a
new key, which keeps one row = one provider generation for reconciliation.

**State machine.** `submitting → queued → processing → succeeded | failed`. Webhooks, the submit
job and the reconciler all apply a *snapshot of the generation object* through
`GenerationStateMachine`, which:

1. locks the row (`SELECT … FOR UPDATE`) for the whole read-decide-write, and
2. only moves forward. A late `processing` after `succeeded` is a no-op, and final statuses never change.

Cost is written only on the move into `succeeded`, so it's recorded once even if a webhook and a
poll race each other.

**Webhooks.** We verify the HMAC-SHA256 signature over the raw body, compare in constant time, and reject
timestamps more than 5 minutes old. Then, *in one transaction*, we insert the event id into
`webhook_events` (unique) and apply the snapshot. A duplicate hits the unique index and gets a `200`
without doing anything. If applying fails, the insert rolls back too and we return `500`, so
Pixelforge's retry isn't mistaken for a duplicate. Image downloads (signed URLs expire after 24h) are
queued, which keeps the endpoint fast.

**Limits.** Submission runs in a queued job:

| Pixelforge says | We do |
|---|---|
| `429 rate_limit_exceeded`, `429 concurrency_limit_exceeded`, `503 overloaded`, other 5xx | wait for `Retry-After`, doubling each time (capped at 60s, plus jitter); give up after 10 minutes |
| `429 daily_limit_exceeded`, `400 content_policy_violation`, `422`, `409` | fail immediately (retrying can't help), not charged |
| timeout / connection error | retry with the same idempotency key (safe) |

While waiting, the History page says **"Waiting for a free slot"** instead of showing an error.

**Lost webhooks.** `pixelforge:reconcile` runs every minute and polls any generation that has been
`queued`/`processing` for more than 2 minutes without changing.

**Live status.** The History page uses Inertia's `usePoll` to reload just its data every 3s, and only
while something is in flight.

## Known limitations

- If every POST attempt *times out* (rather than being rejected), Pixelforge may have created the
  generation even though we mark it `submission_gave_up`. The idempotency key prevents duplicates,
  but such an orphan would show up in `/v1/usage` without a matching charge. A fix would match orphans
  by `metadata.generation_id` via `GET /v1/generations` during reconciliation.
- Our own concurrency isn't throttled: with many users, most submissions would wait on Pixelforge's
  3-at-a-time limit. A Redis `funnel` limiter in `SubmitGeneration` would smooth this out.

## Running locally

Requires PHP 8.3+, Node 22, PostgreSQL.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
createdb pixelforge && createdb pixelforge_testing
php artisan migrate --seed            # test@example.com / password
composer run dev                      # server, queue worker, vite
php artisan schedule:work             # reconciler (separate terminal)
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` from a Pixelforge API key created for
local use, and point its webhook URL at a tunnel (e.g. `expose share http://localhost:8000`)
+ `/webhooks/pixelforge`.

Prompt keywords for exercising failure paths: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`,
`[overloaded]`, `[succeed]`.

## Tests

```bash
php artisan test         # Pest, against PostgreSQL (unique constraints and row locks matter)
vendor/bin/phpstan       # level from phpstan.neon, no baseline
npm run types:check
```
