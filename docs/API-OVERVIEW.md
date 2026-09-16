# Axfit Wallet REST API — Overview

Base URL: `https://<your-site>/wp-json/`

The plugin ships one canonical REST namespace, **`terawallet/v1`**, plus a
deprecated legacy namespace (`wc/v3/wallet/*`) kept only for old integrations.
All new integrations should use `terawallet/v1`.

## Document map

| Doc | Covers |
|---|---|
| **This file** | Auth model, idempotency, error shape, conventions shared by every endpoint below |
| [API-ME.md](API-ME.md) | Customer-facing `terawallet/v1/me/*` — profile, balance, transactions, top-up, transfer, referrals, cashback rules |
| [API-WITHDRAWALS.md](API-WITHDRAWALS.md) | Wallet withdrawals — both `me/withdrawals` (customer) and `admin/withdrawals` (staff); kept as its own deep-dive because of the status lifecycle and money-safety rules involved |
| [API-ADMIN.md](API-ADMIN.md) | Staff-facing `terawallet/v1/admin/*` — transactions ledger, users, transfer, reports |
| [API-SETTINGS.md](API-SETTINGS.md) | `terawallet/v1/settings/*` and the public `settings/public` endpoint |
| [API-SYSTEM.md](API-SYSTEM.md) | `terawallet/v1/multicurrency` |
| [API-LEGACY.md](API-LEGACY.md) | Deprecated `wc/v3/wallet/*` shims — routing table only, no new integration should target these |

## Namespaces and who calls what

| Namespace segment | Who | Auth |
|---|---|---|
| `/me/*` | The logged-in customer, about their own data only | WordPress cookie session + REST nonce (`X-WP-Nonce`). **Consumer-key auth is rejected** — a leaked API key must never be able to read another customer's wallet. |
| `/admin/*` | Store staff | Cookie session with `manage_woocommerce` (or whatever `woo_wallet_rest_check_permissions` / `woo_wallet_reports_capability` filters to) + REST nonce. Consumer-key auth **is** accepted here (server-to-server integrations). |
| `/settings/*` | Store staff managing wallet configuration | Cookie session with `get_wallet_user_capability()` (default `manage_woocommerce`, filterable) + REST nonce. |
| `/multicurrency` | Store staff | Same as `/settings/*`. |
| `settings/public` | Anyone, including logged-out | None. Public, cached. |

Every `/me/*` request is always scoped to `get_current_user_id()` — no
endpoint accepts a request-supplied user id or email to select whose data to
read. A `/me/*` item route (e.g. `GET /me/transactions/{id}`) returns `404`
rather than `403` when the id belongs to another customer, so an attacker
probing ids can't tell which ones exist.

### Getting a nonce

A REST nonce is required on every authenticated request. If you're calling
from a browser context where WordPress already localized
`wpApiSettings.nonce` (or similar) for you, use that. Otherwise obtain one
via `wp_create_nonce( 'wp_rest' )` server-side and send it as:

```
X-WP-Nonce: <nonce>
```

alongside the request's session cookies.

## Idempotency

Every endpoint that moves wallet money requires or honours an
`Idempotency-Key` header:

- **Admin money-moving routes** (`POST admin/transactions`,
  `admin/transactions/bulk`, `admin/transfer`, `admin/withdrawals`,
  `admin/withdrawals/{id}/process`, `admin/withdrawals/{id}/recover`,
  `admin/users/{id}/transactions/purge`) — **require** the header; omitting
  it returns `400 terawallet_rest_idempotency_key_required`.
- **Customer money-moving routes** (`POST me/topup`, `me/transfer`,
  `me/withdrawals`) — **honour** the header when present but don't require
  it (a customer-facing SPA should still always send one).

Generate a fresh UUID per user-initiated submission and resend the *same*
key only when retrying that exact submission after a timeout or dropped
connection. The server replays the original response instead of re-running
the action, so a retried network failure can never double-charge, double-pay,
or double-refund a wallet.

```
Idempotency-Key: 3fa3a13e-6f0e-4b0a-9c1a-5b6b6c9b6a11
```

A second concurrent request using the same key while the first is still
running gets `409 terawallet_rest_idempotency_in_progress` rather than
executing a second time.

Endpoints that only add metadata and never move money (e.g. adding a note to
a withdrawal) are **not** idempotency-gated — a duplicate on retry is a
cosmetic annoyance there, not a safety issue.

## Response conventions

- **Pagination**: list endpoints take `page` (default 1) and `per_page`
  (default 20, capped at 100 or 200 depending on the endpoint) and report
  `X-WP-Total` / `X-WP-TotalPages` response headers rather than a body
  envelope.
- **Money fields**: raw numeric amounts are always plain numbers in the
  relevant currency (never strings). Most objects also include a
  `formatted` block with `wc_price()`-rendered display strings (HTML
  stripped) for the same figures — use `formatted` for display and the raw
  field for calculation.
- **Dates**: ISO 8601 / RFC 3339 (`mysql_to_rfc3339()`), e.g.
  `"2026-09-16T09:58:12"`.
- **Customer-scoped responses** (`/me/*`) always carry
  `Cache-Control: private, no-store, max-age=0` — never cached by a shared
  cache or CDN.
- **`_links`**: most objects include a `self` link and, where relevant, a
  `collection` link and an `embeddable` `user` link
  (`wp/v2/users/{id}`) — standard WP REST HATEOAS conventions.
- **Schema**: GET-only single-item endpoints expose `get_item_schema()` via
  `OPTIONS` on the route, per standard WP REST conventions.

## Error shape

Every error is a standard WP REST error object:

```json
{ "code": "rest_not_logged_in", "message": "You must be logged in to access this resource.", "data": { "status": 401 } }
```

Codes you'll see across most endpoints:

| Code | Status | Meaning |
|---|---|---|
| `rest_not_logged_in` | 401 | No valid session |
| `rest_consumer_key_in_me_namespace` | 401 | A `/me/*` route was called with a WooCommerce consumer key — rejected on purpose |
| `rest_forbidden` / `woocommerce_rest_cannot_{context}` | 403 | Logged in, but lacks the required capability |
| `*_not_found` | 404 | Resource doesn't exist, or (on `/me/*`) belongs to someone else |
| `terawallet_rest_idempotency_key_required` | 400 | Missing `Idempotency-Key` on an admin money-moving route |
| `terawallet_rest_idempotency_in_progress` | 409 | Same idempotency key already running |

Endpoint-specific codes are documented alongside each endpoint in the other
files.

## Extensibility

Nearly every response payload is run through a `terawallet_rest_prepare_*`
or `terawallet_rest_*` filter before being returned (named per-endpoint in
each doc below), so a Pro add-on or custom code can add fields without a
core change. Query args on list endpoints are similarly filterable
(`*_query_args`).
