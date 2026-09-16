# Wallet Withdrawals REST API

One of several docs covering the plugin's REST surface — see
[API-OVERVIEW.md](API-OVERVIEW.md) for the full index, and
[API-ME.md](API-ME.md) / [API-ADMIN.md](API-ADMIN.md) for the rest of the
customer and admin endpoints. Withdrawals get their own doc because of the
status lifecycle and money-safety rules involved.

Base URL: `https://<your-site>/wp-json/terawallet/v1`

All requests and responses are JSON. All money amounts are plain numbers in
the store's currency unless noted otherwise (see `formatted` fields for
pre-formatted display strings).

## Authentication

| Namespace | Who | Auth |
|---|---|---|
| `/me/withdrawals*` | The logged-in customer, about their own requests only | WordPress cookie session + REST nonce (`X-WP-Nonce` header). Consumer-key auth is rejected. |
| `/admin/withdrawals*` | Store staff | Cookie session with the `manage_woocommerce` capability (or whatever `woo_wallet_rest_check_permissions` allows), + REST nonce. |
| `/settings/public` | Anyone, including logged-out | None — public, cached 60s. |

Every write goes through the same validation and money-movement code the
admin screens use (`Woo_Wallet_Withdrawal::submit_request()` /
`::admin_create()` / `::admin_process()` / `::admin_recover()`), so behaviour
never drifts between the UI and the API.

## Idempotency

Every endpoint that can move money (`POST /me/withdrawals`,
`POST /admin/withdrawals`, `POST /admin/withdrawals/{id}/process`,
`POST /admin/withdrawals/{id}/recover`) requires an `Idempotency-Key` header
on the admin routes, and honours one when present on the customer route.
Generate a fresh UUID per user-initiated submission and resend the *same*
key if you retry after a timeout or dropped connection — the server replays
the original response instead of re-running the action, so a retried
network failure can never double-charge or double-refund a wallet.

```
Idempotency-Key: 3fa3a13e-6f0e-4b0a-9c1a-5b6b6c9b6a11
```

## Status lifecycle

```
pending  →  paid
         →  processing  →  rejected   (normal reject)
                        →  pending    (reject's refund failed — safe to retry)
```

`processing` is a short-lived internal state used only while a **reject** is
being processed (crediting the reservation back can itself fail, so the
request is claimed first, then finalized). A request almost never stays
`processing` for more than a few hundred milliseconds; if a server crash
ever leaves one stuck there, `POST /admin/withdrawals/{id}/recover` resolves
it safely — see [Recovery](#post-adminwithdrawalsidrecover) below. The
customer-facing endpoints report `processing` as `pending`, since from the
customer's point of view nothing meaningfully different has happened.

## Receipt retention

An uploaded receipt (attached via `receipt_id` on create or on
`admin/withdrawals/{id}/process`) is only kept for a limited time — **90
days after the request's `date_created` by default** — after which a daily
housekeeping sweep permanently deletes the Media Library file and clears
`receipt_id`/`receipt_url` on the request. This is intentional (storage
hygiene, not a bug): don't rely on a receipt URL staying valid indefinitely,
and don't cache it past `receipt_expires_at`.

- The retention window is filterable server-side
  (`woo_wallet_withdrawal_receipt_retention_days`, default `90`; `0` or less
  disables the sweep entirely) — treat 90 days as the default, not a
  guarantee, and always read `receipt_expires_at` from the response rather
  than hardcoding it.
- Once expired, `receipt_url` (and, on the admin object, `receipt_id`)
  simply read `null` again, exactly as if no receipt had ever been attached
  — there is no separate "expired" status or error.
- The request record itself (amount, bank details, status, reference
  number, notes) is never affected — only the uploaded file and the column
  pointing at it. A private note is added to the request when this happens,
  visible via `GET /admin/withdrawals/{id}`.
- If a customer needs their receipt after it's expired, there is no API to
  recover it — it has been permanently deleted. Point them to your support
  channel before then.

---

## `GET /settings/public`

Public, unauthenticated, cached 60s. The `withdrawal` block tells a client
whether withdrawals are enabled at all, the amount limits, the charge, and
the bank dropdown options — everything needed to render the request form
before calling `POST /me/withdrawals`.

```json
{
  "withdrawal": {
    "enabled": true,
    "min": 50,
    "max": 5000,
    "charge_type": "fixed",
    "charge_value": 10,
    "banks": [
      "National Bank of Egypt (البنك الأهلي المصري)",
      "Commercial International Bank - CIB (البنك التجاري الدولي)"
    ]
  }
}
```

`bank_name` on `POST /me/withdrawals` must exactly match one of the strings
in `withdrawal.banks`.

---

## Customer endpoints (`/me/withdrawals`)

### `GET /me/withdrawals`

List the current user's own withdrawal requests, newest first.

**Query params:** `page` (default 1), `per_page` (default 20, max 100).

**Response:** `200`, array of [withdrawal objects](#customer-withdrawal-object).
Pagination via `X-WP-Total` / `X-WP-TotalPages` response headers.

### `POST /me/withdrawals`

Submit a new request. The amount (plus any configured charge) is reserved
from the wallet immediately.

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `amount` | number | yes | > 0, within the site's configured min/max |
| `bank_name` | string | yes | Must match an entry from `settings/public`'s `withdrawal.banks` |
| `beneficiary_name` | string | yes | |
| `account_number` | string | yes | |
| `phone` | string | yes | Contact number in case staff need to reach the customer about this request; at least 8 digits |
| `iban` | string | no | Egyptian IBAN: `EG` + 27 digits |

```bash
curl -X POST 'https://example.com/wp-json/terawallet/v1/me/withdrawals' \
  -H 'X-WP-Nonce: <nonce>' \
  -H 'Idempotency-Key: 3fa3a13e-6f0e-4b0a-9c1a-5b6b6c9b6a11' \
  -H 'Content-Type: application/json' \
  --cookie 'wordpress_logged_in_...=...' \
  -d '{
    "amount": 500,
    "bank_name": "National Bank of Egypt (البنك الأهلي المصري)",
    "beneficiary_name": "Mohamed Ali",
    "account_number": "1234567890",
    "phone": "01012345678",
    "iban": "EG380019000500000000263180002"
  }'
```

**Response:** `201` with the created [withdrawal object](#customer-withdrawal-object), or `400` with
`{"code": "rest_withdrawal_request_failed", "message": "..."}` when validation
fails (insufficient balance, amount out of range, unknown bank, disabled
feature, rate limit, ...).

### `GET /me/withdrawals/{id}`

Fetch one of the current user's own requests. Returns `404` for a request
that doesn't exist or belongs to another customer (never `403` — existence
of another user's request id is not disclosed).

### Customer withdrawal object

```json
{
  "id": 42,
  "amount": 500,
  "charge": 10,
  "currency": "EGP",
  "bank_name": "National Bank of Egypt (البنك الأهلي المصري)",
  "beneficiary_name": "Mohamed Ali",
  "account_number": "1234567890",
  "phone": "01012345678",
  "iban": "EG380019000500000000263180002",
  "reference_no": "TRX-99213",
  "receipt_url": "https://example.com/wp-content/uploads/2026/09/receipt.pdf",
  "receipt_expires_at": "2026-12-15T09:58:12",
  "status": "paid",
  "notes": [
    { "note": "Sent via instant transfer, should land within an hour.", "date": "2026-09-16T10:02:00" }
  ],
  "date_created": "2026-09-16T09:58:12",
  "date_updated": null,
  "formatted": { "amount": "EGP500.00", "charge": "EGP10.00" }
}
```

Only **public** notes appear here — private staff notes never leave the
admin namespace. `reference_no`, `account_number`, `phone` and `iban` are the
customer's own submitted data, echoed back.

`receipt_url` is `null` until an admin attaches one (see
`admin/withdrawals/{id}/process`) and again once it's expired — see
[Receipt retention](#receipt-retention) below. While it's set,
`receipt_expires_at` tells you exactly when the link will stop working, so a
client can warn the customer to save a copy before then.

---

## Admin endpoints (`/admin/withdrawals`)

Requires `manage_woocommerce` (or whatever `woo_wallet_rest_check_permissions`
allows).

### `GET /admin/withdrawals`

List/filter every customer's requests.

**Query params:**

| Param | Notes |
|---|---|
| `page`, `per_page` | Default 1 / 20, max 100 |
| `status` | `pending` \| `processing` \| `paid` \| `rejected` |
| `user_id` | Exact customer id — wins over `search` if both are given |
| `search` | Match a customer by login, email or display name — a plain-text name match can hit more than one customer, and every match is included (not just the first) |
| `bank_name` | Exact match against one of the configured bank names (see `settings/public`'s `withdrawal.banks`) |
| `requested_by` | `self` (customer self-service only) \| `staff` (manually logged by an admin only) — omit for both |
| `after`, `before` | ISO 8601 date-time bounds on the request date |

### `POST /admin/withdrawals`

Manually log a withdrawal on a customer's behalf — e.g. a request that came
in by phone. The record is attributed to the calling admin (`created_by`),
distinct from the customer (`user_id`).

A receipt is attached by id, not by raw upload: **upload the file to the
standard `POST /wp/v2/media` endpoint first**, then pass the returned id as
`receipt_id` here. Only PDF, PNG and JPG are accepted (checked by real mime
type, not file extension).

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `user_id` | integer | yes | The customer whose wallet is charged |
| `amount` | number | yes | |
| `bank_name` | string | yes | Free text — not constrained to the customer dropdown |
| `beneficiary_name` | string | yes | |
| `account_number` | string | yes | |
| `phone` | string | yes | Contact number in case staff need to reach the customer about this request; at least 8 digits |
| `iban` | string | no | |
| `reference_no` | string | no | Bank transfer reference |
| `status` | `pending` \| `paid` | no, default `pending` | Use `paid` when the transfer was already sent (e.g. logging a completed phone request) |
| `receipt_id` | integer | no | An attachment id from `POST /wp/v2/media` |
| `note` | string | no | |
| `note_visibility` | `public` \| `private` | no, default `private` | `public` notes are visible to the customer via `/me/withdrawals` |

Requires `Idempotency-Key`. Returns `201` with the
[admin withdrawal object](#admin-withdrawal-object), or `404` for an
unknown `user_id`, or `400` for a validation failure (including an invalid
or wrong-type `receipt_id`).

### `GET /admin/withdrawals/{id}`

Fetch one request with its full notes thread (public **and** private).

### `POST /admin/withdrawals/{id}/process`

Mark a pending request paid or rejected.

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `action` | `paid` \| `reject` | yes | |
| `reference_no` | string | no | |
| `receipt_id` | integer | no | Same as the create endpoint |
| `note` | string | no | |
| `note_visibility` | `public` \| `private` | no, default `private` | |

Requires `Idempotency-Key`. On `reject`, the reserved amount is credited
back to the customer's wallet — this uses the same ledger-checked idempotent
refund path described in [Recovery](#post-adminwithdrawalsidrecover), so a
retried/duplicated `process` call can never refund twice.

Returns the updated [admin withdrawal object](#admin-withdrawal-object) on
success. Returns `409` with `terawallet_rest_withdrawal_process_failed` when
the request is no longer pending (already processed, or — extremely rarely —
a concurrent `process` call from another admin won the race first) or when
the reject's refund could not be completed right now (the request is left
`pending`, safe to retry).

### `POST /admin/withdrawals/{id}/recover`

Resolves a request stuck on the transient `processing` status (a reject
interrupted by a server crash or database error between crediting the
refund and finalizing the record). Takes no body.

Safe to call any number of times, from any admin, in any order relative to
other calls — it always checks the wallet ledger itself for an existing
tagged refund before ever crediting, so it can never send the same refund
twice, and the finalizing update is conditioned on the row still being
`processing`, so two concurrent recoveries can't both fire the completion
hook.

Requires `Idempotency-Key`. Returns the updated
[admin withdrawal object](#admin-withdrawal-object) on success (including
when it finds the request was already recovered by someone else — that is
also a successful end state). Returns `409` with
`terawallet_rest_withdrawal_recover_failed` when the refund still can't be
issued right now (try again shortly) or the request wasn't in `processing`
to begin with.

### `POST /admin/withdrawals/{id}/notes`

Add a note without changing status. Not idempotency-gated — it moves no
money, so a duplicate on retry is a cosmetic annoyance, not a safety issue.

**Body:** `note` (string, required), `visibility` (`public` \| `private`,
default `private`).

Returns the updated [admin withdrawal object](#admin-withdrawal-object).

### Admin withdrawal object

```json
{
  "id": 42,
  "user_id": 7,
  "user": { "id": 7, "login": "mohamed", "email": "mohamed@example.com", "display_name": "Mohamed Ali", "avatar_url": "..." },
  "amount": 500,
  "charge": 10,
  "currency": "EGP",
  "bank_name": "National Bank of Egypt (البنك الأهلي المصري)",
  "beneficiary_name": "Mohamed Ali",
  "account_number": "1234567890",
  "phone": "01012345678",
  "iban": "EG380019000500000000263180002",
  "reference_no": "TRX-99213",
  "receipt_id": 1391,
  "receipt_url": "https://example.com/wp-content/uploads/2026/09/receipt.pdf",
  "receipt_expires_at": "2026-12-15T09:58:12",
  "status": "paid",
  "transaction_id": 5502,
  "refund_transaction_id": null,
  "created_by": 7,
  "created_by_user": { "...": "..." },
  "self_service": true,
  "processed_by": 3,
  "processed_by_user": { "...": "..." },
  "notes": [
    { "id": 12, "note": "Sent via instant transfer.", "visibility": "public", "created_by": 3, "author": "Store Admin", "date": "2026-09-16T10:05:00" }
  ],
  "date_created": "2026-09-16T09:58:12",
  "date_updated": "2026-09-16T10:05:00",
  "formatted": { "amount": "EGP500.00", "charge": "EGP10.00" }
}
```

`self_service` is `true` when the customer created the request themself
(`created_by === user_id`); `false` means a staff member logged it on their
behalf (phone request) — check `created_by_user` for who.
`refund_transaction_id` is only ever set on a `rejected` request, and is the
wallet ledger transaction id of the refund.

---

## Error shape

Every error response is a standard WP REST error:

```json
{ "code": "rest_withdrawal_request_failed", "message": "Entered amount is greater than your current wallet balance.", "data": { "status": 400 } }
```

Common codes: `rest_not_logged_in` (401), `rest_forbidden` /
`woocommerce_rest_cannot_edit` (403), `*_not_found` (404),
`terawallet_rest_idempotency_key_required` (400, admin money-moving routes
only), `terawallet_rest_idempotency_in_progress` (409, a request with the
same idempotency key is still running).
