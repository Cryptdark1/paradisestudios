# Paradise Studios — Payment Backend

Provider-independent payment foundation for the studio-booking marketplace.

**Live payments are disabled (`PAYMENT_LIVE=false`), and no payment provider is
holding funds on anyone's behalf.** Bookings use the status value `escrow_held`,
which here means only *"the payment provider confirmed this booking was paid"*.
It does not mean a regulated third party is holding funds, and the codebase
never claims it does.

---

## 1. Quick start (no gateway account needed)

Everything runs locally against the **mock provider**. No keys, no network.

```bash
# 1. Apply the schema
mysql -u root paradisestudios < sql/migrations/001_payments.sql
mysql -u root paradisestudios < sql/migrations/002_mock_gateway.sql
mysql -u root paradisestudios < sql/migrations/003_owner_transfers.sql

# 2. Run the tests
php tests/run.php

# 3. Local webhook simulator (signed, delivered over real HTTP)
php tests/send-webhook.php --ref=ps_1_your_reference
```

Migration **002** adds `mock_gateway_transactions`, which gives the mock
provider server-side state. It is required for the flow to work across separate
requests; see §13.

Migration **003** adds masked owner payout-account records, transfer attempt
history and the mock transfer store. Account numbers are verified but never
persisted; only the final four digits and the provider recipient code are kept.
Owners can request transfers only after earnings become eligible, and uncertain
attempts must be reconciled before another request. A CLI eligibility refresher
is available at `scripts/refresh-payout-eligibility.php`; schedule it with
Windows Task Scheduler using `C:\xampp\php\php.exe` and the script's absolute
path. That job changes eligibility only; it does not initiate transfers.

Owner transfers use the same `PAYMENT_PROVIDER` and guarded Paystack credentials
as payments. Keep `PAYMENT_LIVE=false` and use test credentials for test-mode
provider behavior. This implementation was not exercised against Paystack, and
an account must have transfer capability enabled before provider transfers will
work. Do not enable that operation with live credentials until the application's
operational controls and provider account are reviewed.

The mock performs **real** HMAC-SHA512 webhook signing, so the local pipeline —
signature, replay, idempotency — is exercised end to end rather than bypassed.

### Optional Paystack test mode

For checkout testing, set `PAYMENT_PROVIDER=paystack`, configure only Paystack
test keys in the external environment file, and keep `PAYMENT_LIVE=false`.
The adapter refuses live keys in this mode. Never put keys in the repository or
turn on live mode to test a booking.

The local callback and cancel URLs can point to
`http://localhost/paradisestudios/payment-return.php` when the same machine's
browser is being used for checkout. The callback is a browser redirect; it
does not make the local site publicly reachable. This project verifies the
result server-to-server after the browser returns. A real checkout against
Paystack was not exercised as part of this implementation.

If your Paystack account or a remote test browser requires a public HTTPS
callback, a tunnel such as `ngrok http 80` can forward to the local Apache
server. Set both callback environment values to the tunnel's
`https://<assigned-host>/paradisestudios/payment-return.php` URL for that test.
This temporarily exposes the local Apache site to the internet: use test
credentials only, do not expose other local apps or sensitive data, and stop
the tunnel immediately afterwards. Do not use a tunnel unless required.

---

## 2. Configuration

Secrets live **outside the web root**, at `C:\xampp\paradisestudios.env`
(override with the `PS_ENV_FILE` environment variable). Copy the template:

```bash
copy config\.env.example C:\xampp\paradisestudios.env
```

With no file at all the defaults are already safe: mock provider, live payments
off. Real environment variables override the file.

| Setting | Default | Meaning |
|---|---|---|
| `PAYMENT_PROVIDER` | `mock` | `mock` or `paystack` |
| `PAYMENT_LIVE` | `false` | Must be `true` for real money |
| `PAYSTACK_SECRET_KEY` | *(empty)* | `sk_test_…` / `sk_live_…` |
| `PAYSTACK_PUBLIC_KEY` | *(empty)* | `pk_test_…` / `pk_live_…` |
| `PAYMENT_SUCCESS_URL` / `PAYMENT_CANCEL_URL` | *(empty)* | Where Paystack returns the customer |
| `CURRENCY` | `NGN` | ISO 4217 |
| `PLATFORM_COMMISSION_BPS` | `1000` | 10000 = 100%, so 1000 = 10% |
| `FULL_REFUND_WINDOW_HOURS` | `24` | Matches the existing cancellation policy |
| `FULL_REFUND_BPS` / `LATE_REFUND_BPS` | `10000` / `5000` | 100% / 50% |
| `PAYMENT_EXPIRY_MINUTES` | `30` | How long an unpaid payment holds a slot |
| `PAYOUT_ELIGIBILITY_DELAY_HOURS` | `24` | Delay before an owner earning is payable |

### The live-key guard

`PaystackPaymentProvider` refuses to operate unless the key prefix matches the
mode:

| `PAYMENT_LIVE` | Configured key | Result |
|---|---|---|
| `false` | `sk_live_…` | **Refused** — a live key is never exercised in development |
| `true` | `sk_test_…` | **Refused** — a test key cannot take real money |
| either | no key | **Refused** — clear configuration error, no payment attempted |

The mock also refuses to run when `PAYMENT_LIVE=true`, so it can never be
mistaken for a live gateway.

---

## 3. Money representation

**Every amount is an integer in minor units.** For NGN that is kobo: NGN 15,000.00
is `1500000`.

Floating point is never used for a financial total. `includes/money.php` parses
decimal *strings* into integers, so no value passes through a binary float on the
way in or out.

`bookings.total_amount` remains `DECIMAL(10,2)` because the existing frontends
read and format it. `bookings.total_amount_minor` is the authoritative integer
for the payment layer, backfilled at migration time by pure string surgery.

Rates are integers in **basis points** (10000 = 100%). A percentage would put a
float exactly where money is split between two parties.

The split is derived, never computed twice:

```
commission = round_half_up(gross × commission_bps / 10000)
owner_net  = gross − commission        // derived, so reconciliation is exact
```

`owner_payouts` carries a `CHECK` constraint enforcing
`commission + owner_net = gross` at the database level.

---

## 4. Payment lifecycle

Payment state is **separate** from booking state:

* `bookings.status` — the session (`pending` → `escrow_held` → `completed`)
* `bookings.payment_state` — the money (`unpaid` → `pending` → `paid` → …)

A booking is treated as paid only when `payment_state = 'paid'` **and** a
captured payment row exists.

### Statuses

| `payment_state` | Meaning |
|---|---|
| `unpaid` | No payment attempted |
| `pending` | Initialised, awaiting provider confirmation |
| `paid` | Provider confirmed capture; ledger credited |
| `failed` | Provider reported failure |
| `expired` | Window elapsed without confirmation |
| `partially_refunded` | Some, not all, returned |
| `refunded` | Fully returned |
| `legacy_unverified` | **Predates this system. Not proof of payment.** |

### Backward compatibility

New bookings are created `pending` and only become `escrow_held` on confirmed
payment. The four existing bookings were backfilled as `legacy_unverified` (or
`failed` where already cancelled) — they keep their original `status` so history
renders unchanged, but no money is claimed for them.

---

## 5. The trust boundary

**The browser is never trusted.**

| Claim | Treated as |
|---|---|
| "The user clicked pay" | A request to *start* a payment |
| "I paid" / success redirect | Nothing at all |
| Amount in the request body | Ignored — read from `bookings.total_amount_minor` |
| Amount/currency in a webhook body | Ignored — re-fetched from the provider |

`confirm()` asks the **provider** server-to-server and then checks that the
reference, amount, currency and booking all agree with what we recorded when the
money was requested. Any mismatch raises `PaymentVerificationException` and
nothing is credited.

A redirect to the success page cannot mark a booking paid. The success page calls
`POST /api/payment-verify.php`, which re-asks the provider.

### Idempotency

Three independent layers:

1. The payment row is locked `FOR UPDATE` during confirmation, with a second
   status check **inside** the transaction.
2. A payment already `succeeded` short-circuits and returns the original result,
   writing nothing.
3. Database constraints: `UNIQUE reference`, `UNIQUE (provider, provider_reference)`,
   `UNIQUE owner_payouts.booking_id`.

Verified: three consecutive confirmations produce exactly one capture entry, one
commission entry, one earning entry and one payout row.

---

## 6. Ledger

`financial_ledger` is **append-only** — there is no update or delete path in the
application. Balances are derived with `SUM`; no running total is stored, so a
mistake in one place cannot corrupt a total held elsewhere.

Every row stores the `commission_bps` that was actually in force. **Changing the
commission setting never restates history.**

A capture writes four rows that must reconcile exactly:

```
payment_captured     gross             (party: provider)
provider_fee         fee, if reported  (party: provider)
platform_commission  commission        (party: platform)
owner_earning        gross − commission(party: owner)
```

The processing fee is recorded **only when the provider reports one**. It is
never estimated from a published rate, because a wrong fee silently changes the
owner's net.

---

## 7. Refunds

A refund is a **request plus an outcome**. Requesting one does not move money;
only a provider-confirmed success does. Nothing here claims a calculated figure
was returned to a customer.

**Cancellation policy** (unchanged from `api/cancel-booking.php`):

| When cancelled | Refund |
|---|---|
| ≥ 24h before start | 100% |
| < 24h before start | 50% |
| After start | 0% |

**Interaction with commission and earnings.** When `R` is refunded it comes out of
the same pot, so the reversal is proportional and uses the *same* rate applied at
capture:

```
commission_reversed = R × commission_bps
owner_reversed      = R − commission_reversed
```

**Provider fees are not refunded.** Gateways do not return processing fees, so on
a full refund the fee remains a cost to the platform. This is a business
decision, stated rather than hidden: on a full refund of NGN 15,000 whose fee was
NGN 325, the platform is out NGN 325 even though the customer was made whole.

**Guards:** refunds may never exceed *captured − already refunded*, derived from
**confirmed ledger entries** — never from `bookings.total_amount`, which says
nothing about whether money arrived. An `idempotency_key` makes a retried request
return the original refund rather than paying out twice.

---

## 8. Owner earnings and payout eligibility

**Eligibility is not payout.** This system decides when money an owner has earned
*may* be released, and records that decision. It never creates a bank recipient,
never initiates a transfer, and never asks a provider to hold funds for an owner.
No payment gateway works that way.

An earning becomes eligible when **all** hold:

1. payment confirmed (`payment_state = 'paid'`)
2. the session has **ended**
3. the eligibility delay has passed (default 24h — gives a customer time to raise
   a problem before the owner is credited)
4. the booking is **not disputed**
5. the earning has **not** been fully reversed by a refund

States never move backwards silently:

* `paid` and `reversed` are **terminal**
* `on_hold` is **sticky** — a routine re-evaluation cannot release a manual hold

---

## 9. Webhooks

`POST /api/payment-webhook.php`

| Code | Meaning |
|---|---|
| 200 | Processed, ignored (unsupported), or duplicate — acknowledged so the provider stops retrying |
| 400 | Structurally unusable |
| 401 | Signature invalid — **nothing changes** |
| 429 | Rate limited |
| 500 | Internal error — deliberately a failure so the provider **retries**, which is safe because processing is idempotent |

**Signature:** HMAC-SHA512 over the **exact raw request body**, compared with
`hash_equals`. `php://input` is used rather than re-encoding the decoded JSON,
because PHP escapes `/` as `\/` — a re-encoded body produces a different digest
and would reject legitimate events.

**Duplicates and ordering** are handled by recording the event first and relying
on `UNIQUE fingerprint` and `UNIQUE (provider, event_id)`.

One subtlety worth knowing: a body that arrives once with a **bad** signature
does not block the same body arriving later with a **good** one. Only rows whose
signature was valid count as "already seen" — otherwise a spoofed request could
permanently block a real payment.

**Diagnostics never contain** a secret key, an authorization code or card digits.
The stored payload is an allow-list of safe fields.

### Localhost

Paystack cannot POST a webhook to `http://localhost`; a local-only webhook
endpoint is not publicly reachable. The checkout return URL is different: it is
a browser redirect, and can reach localhost only when the customer's browser is
running on the same machine as Apache. The local payment-return page requires a
signed-in artist session. A successful local page load does not prove that
Paystack accepted the callback or that checkout was completed.

For local application and webhook tests, use the simulator, which produces
correctly signed events and POSTs them over real HTTP:

```bash
php tests/send-webhook.php --ref=ps_12_abc123
php tests/send-webhook.php --ref=ps_12_abc123 --invalid    # expect 401
php tests/send-webhook.php --ref=ps_12_abc123 --duplicate  # expect 200 duplicate
php tests/send-webhook.php --ref=ps_12_abc123 --amount=99999999  # forged amount
```

---

## 10. When you create a Paystack account

1. Create the account and copy the **test** keys from the dashboard.
2. Add them to `C:\xampp\paradisestudios.env`:
   ```
   PAYMENT_PROVIDER=paystack
   PAYSTACK_SECRET_KEY=sk_test_…
   PAYSTACK_PUBLIC_KEY=pk_test_…
   PAYMENT_SUCCESS_URL=https://your-domain/paradisestudios/payment-return.php
   PAYMENT_CANCEL_URL=https://your-domain/paradisestudios/payment-return.php
   ```
3. Leave `PAYMENT_LIVE=false` and re-run `php tests/run.php`. The automated
   HTTP payment flow intentionally skips provider-contacting sections when
   `PAYMENT_PROVIDER=paystack`; provider operations there would use real API
   credentials, even when those credentials are test keys.
4. Configure the webhook in the Paystack dashboard to POST to
   `https://your-domain/paradisestudios/api/payment-webhook.php` and subscribe
   to `charge.success`, `charge.failed`, `charge.abandoned`.
5. Use a public HTTPS endpoint for webhooks and any checkout return that must
   work from a different device. For a temporary local test, see the warning
   and tunnel steps in §2. Paystack cannot deliver a webhook to localhost.
6. Test end to end with test credentials only. Do not enable live mode as part
   of test checkout.

**No code changes are required** — the provider is chosen entirely by
configuration.

---

## 11. What remains before real money

See the final report for the full list. In short: live keys, a public HTTPS
webhook, a real refund reconciliation job, owner bank details with a verified
payout provider, and a decision on commission split of processing fees.

---

## 12. Rollback

```bash
mysql -u root paradisestudios < sql/migrations/002_mock_gateway.down.sql
mysql -u root paradisestudios < sql/migrations/001_payments.down.sql
```

Removes the five payment tables, the mock gateway table, and the three columns
added to `bookings`. `total_amount` and every pre-existing table are untouched.
**This destroys payment history** — take a `mysqldump` first, and only roll back
before any real capture.

The rollback was verified against a real database: bookings came back
byte-identical, and the migration is safe to re-apply.

---

## 13. Why the mock needs its own table

The mock provider originally kept its transactions in a PHP static array. The
whole automated suite passed, and the flow was still broken.

The reason is that the real flow is several separate requests:

```
POST /api/payment-initialize.php   → the mock creates a transaction
POST /api/payment-verify.php       → the mock must FIND that transaction
POST /api/payment-webhook.php      → the mock must find it again
```

Each is a distinct PHP process. A static array is empty by the second one, so
verification failed with *"the provider does not recognise that transaction"* —
even though the money flow looked perfectly healthy from inside the test
runner.

A real gateway keeps server-side state, so the mock now does too, in
`mock_gateway_transactions`. That table holds no real money, is never read by
the Paystack adapter, and should be emptied whenever you switch
`PAYMENT_PROVIDER` to `paystack`.

**This class of bug only shows up in an end-to-end test over real HTTP.** That
is why `tests/e2e-http-flow.php` exists and why it is part of `tests/run.php`.

---

## 14. What is deliberately not here

* **No escrow.** Nothing holds funds on a third party's behalf. `escrow_held`
  is a legacy status meaning "the provider confirmed a payment".
* **No payout execution.** Eligibility is recorded; no transfer is ever
  initiated, no bank recipient is ever created.
* **No frontend changes.** No HTML, CSS or JavaScript was edited. New response
  keys were *added* to existing ones so the current frontends keep working
  untouched.
* **No handling of partial disputes or chargebacks.** A customer disputing a
  card payment is a real problem this system does not address, and it must be
  built before live money.
