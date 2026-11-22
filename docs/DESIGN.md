# Pixelforge integration: design

Each section is one decision and the reason behind it. Agreed before implementation; the README summarises the result.

## 1. Money: integer nanodollars

- Every amount is a `BIGINT` count of **nanodollars** (1 = $0.000000001). Pixelforge reports `cost_usd`
  with exactly nine decimals, so our provider cost reconciles digit for digit with `GET /v1/usage`.
- No floats anywhere. Decimal strings from the API (`"12.50"`, `"0.046125000"`) are parsed with string
  arithmetic into integers, never via `(float)`.
- Headroom: int64 holds ~9.2 billion dollars in nanodollars. The most a generation can cost is ~$0.37.
- **Provider cost** = Σ tokens × rate. Rates are USD per 1M tokens, so we compute
  `Σ (tokens × rate_in_nanodollars_per_1M) / 1_000_000` and divide **once**, at the end.
  At current rates the division is always exact.
- **Charge** = provider cost × 1.25, computed as `(cost × 5 + 2) intdiv 4`. That's exact today. If a future
  rate ever made it fractional, it rounds half-up to the nearest nanodollar (max error $0.0000000005 per
  generation). Documented, deterministic, and in our favour by at most half a nanodollar.
- Display: show 6 decimals in the UI ($0.046125); the full 9 are shown in the admin report and README.

## 2. Estimate before submit: show a range, store the max

Rates come from `GET /v1/pricing` (cached for 10 min, never hardcoded). Then:

| Component    | Typical                 | Max                            |
| ------------ | ----------------------- | ------------------------------ |
| Input text   | `ceil(chars / 4)`       | `ceil(ceil(chars / 4) × 1.15)` |
| Input images | 765 × refs              | same (exact)                   |
| Output       | table by quality × size | same (exact)                   |
| Reasoning    | midpoint of range       | max of range                   |

The UI shows "about $X, at most $Y" (both with the 25% markup). We store `estimated_max_charge` on the
generation. With balances (stretch), the max is what gets held, so a user can never be charged more than
the number they saw.

The **actual** cost uses the `rates` snapshot on the generation object, not the current pricing, because
Pixelforge bills at the rates in force when the generation was created.

## 3. One table row per user intent, one idempotency key per row

```
generations
  id (ULID)  user_id  status
  prompt  size  quality  reference_images (json)
  idempotency_key (uuid, unique)        -- sent on every POST attempt for this row
  provider_id (gen_..., unique, null)   -- set once Pixelforge accepts
  rates (json, snapshot)  usage (json)
  estimated_typical_charge  estimated_max_charge        -- nanodollars
  provider_cost  charge                                 -- nanodollars, null until succeeded
  error_code  error_message
  image_path  provider_image_url  image_url_expires_at
  submitted_at  started_at  completed_at  timestamps

webhook_events
  event_id (evt_..., unique)  type  provider_generation_id  payload (json)  received_at
```

- The row is created **before** we call Pixelforge. Its UUID idempotency key is reused on every retry of
  the POST, so a timeout followed by a retry can never create (or bill) two generations.
- We also send `metadata: { generation_id: <our ULID> }`. A webhook can arrive before our POST returns,
  i.e. before we know the `gen_` id; the metadata lets us match it anyway.
- Retry (after a failure) = a **new row** with a new key. One row is one provider generation, which keeps
  reconciliation 1:1.

## 4. State machine: forward-only, driven by snapshots

```
submitting ──► queued ──► processing ──► succeeded
     │            │            │
     └────────────┴────────────┴──────► failed
```

- Each status has a rank: submitting 0, queued 1, processing 2, succeeded/failed 3 (terminal).
- We only ever **apply a generation snapshot** (`data.object` from a webhook, or a `GET` response), and only
  if its status ranks higher than the current one. We go by the object's status, not the event type, as the
  docs advise.
- Terminal states never move. So a late `generation.processing` after `succeeded` is a no-op.
- The transition happens inside a transaction with `SELECT ... FOR UPDATE` on the row. Cost and charge are
  written **only on the transition into `succeeded`**, so a concurrent webhook + poll can't both record it.
- `failed` sets charge to 0 (explicitly, not null), so "failures are free" is visible in the data.

## 5. Webhooks: verify, dedupe, apply atomically

1. Verify HMAC-SHA256 over `"{t}.{raw body}"` with `hash_equals`; reject if `|now − t| > 300s`. Return 400.
2. In one DB transaction: `INSERT` the event id into `webhook_events` (unique), then apply the snapshot.
    - Duplicate event id → unique violation → return 200 straight away (already handled).
    - If applying throws, the whole transaction rolls back (including the event insert) and we return 500,
      so Pixelforge's retry gets a real second chance. Recording "seen" without applying would lose events.
3. Slow work (downloading the PNG before its 24h URL expires) goes to a queued job. The endpoint does
   a few indexed queries, comfortably within the 10s budget.
4. `ping` events are recorded and acknowledged.

Event dedupe is the first line of defence. The forward-only state machine is the second: even a
replayed event with a fresh id can't double-charge.

## 6. Submitting: a queued job that respects limits

`POST /generate` validates input, creates the row (`submitting`), and dispatches `SubmitGeneration`.
The job POSTs with the row's idempotency key:

| Response                                | Action                                                                                                  |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| 202                                     | store `provider_id`, rates, apply snapshot (may already be ahead via webhook)                           |
| 429 rate / concurrency, 503 overloaded  | `release()` after `Retry-After` + growing backoff, max 6 attempts, then `failed` (`submission_gave_up`) |
| 429 daily_limit_exceeded                | `failed` right away, clear message (won't clear until UTC midnight)                                     |
| 400 content policy, 422 validation, 409 | `failed`, not retried, provider message shown                                                           |
| network error / 5xx                     | retry (safe: same idempotency key)                                                                      |

Concurrency (3 per account) is the limit users will hit most. Users see "Waiting for a free slot…"
rather than an error.

## 7. Polling fallback: reconcile stragglers

Webhook delivery isn't guaranteed. A scheduled command every minute `GET`s any generation that is
`queued`/`processing` and hasn't changed in 2 minutes, then applies the snapshot through the same code
path as webhooks. One code path for state changes means one place to get right.

## 8. UI

- **Generate**: prompt, size, quality, up to 4 reference URLs. The estimate updates live from a JSON
  endpoint that runs the same PHP estimator billing uses (no money maths in the browser).
- **History**: status badge, estimated vs actual charge, image (our stored copy, falling back to the
  provider URL), error message + Retry on failure. While any row is non-terminal, the page polls itself
  every 3s via Inertia `usePoll`. No websockets needed.

## Stretch (after required parts work)

- Admin report: GROUP BY `completed_at::date` (UTC), user → Σ charge, Σ provider_cost, profit; plus a
  "Pixelforge says" column from `/v1/usage` and a ✓/✗ match per day.
- Balances: append-only `credit_ledger` (purchase, hold, settle, release) with
  `UNIQUE(generation_id, kind)` so a settlement can't be written twice; balance = Σ amount.
