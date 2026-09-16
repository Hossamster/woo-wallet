# Customer API (`terawallet/v1/me/*`)

See [API-OVERVIEW.md](API-OVERVIEW.md) for auth, idempotency, pagination and
error conventions shared by everything below. Every route here requires a
logged-in cookie session and is always scoped to the calling user — no
endpoint accepts a request-supplied user id.

Withdrawals (`me/withdrawals`) are documented separately in
[API-WITHDRAWALS.md](API-WITHDRAWALS.md).

---

## `GET /me`

Profile snapshot for an app shell: id, display name, email, locale,
currency, balance, and a capability flag map deciding which features to show.

```json
{
  "id": 7,
  "display_name": "Mohamed Ali",
  "email": "mohamed@example.com",
  "locale": "ar",
  "currency": "EGP",
  "balance": { "amount": 1250.5, "formatted": "EGP1,250.50" },
  "capabilities": {
    "can_topup": true,
    "can_transfer": true,
    "can_withdraw": true,
    "can_view_referrals": true
  }
}
```

`capabilities` is filterable (`terawallet_rest_me_capabilities`) — a Pro
add-on adds its own flags (e.g. `can_view_expiring_credits`) the same way.

---

## `GET /me/balance`

Lightweight, pollable balance read — separate from `/me` so a client can
refresh just the balance pill cheaply.

```json
{
  "amount": 1250.5,
  "currency": "EGP",
  "formatted": "EGP1,250.50",
  "base_currency": "EGP",
  "base_amount": 1250.5,
  "base_formatted": "EGP1,250.50",
  "mode": "single_base",
  "balances": [
    { "currency": "EGP", "amount": 1250.5, "formatted": "EGP1,250.50" }
  ]
}
```

- In `single_base` mode (the default) the wallet has one canonical balance;
  `balances` holds one entry equal to `amount`.
- In `per_currency` mode (opt-in, gated by the `woo_wallet_enable_per_currency_mode`
  filter), `balances` enumerates every currency the customer has ledger rows
  in, and `base_amount`/`base_formatted` report the total normalised back to
  the shop's base currency via the active multicurrency provider — use
  `base_amount` for "you have X across all your balances," and `balances`
  for a per-currency breakdown.

---

## `GET /me/transactions`

The calling customer's own ledger, newest first by default.

**Query params:** `page`, `per_page` (max 100), `orderby` (`date` \|
`amount` \| `transaction_id`), `order` (`asc` \| `desc`), `type` (`credit` \|
`debit`), `category`, `search` (substring match on the note/details text).

Response includes `X-WP-Total`/`X-WP-TotalPages` and an `ETag` header (hash
of `user_id:total:latest_id`) for cheap client-side revalidation.

```json
[
  {
    "id": 5502,
    "type": "debit",
    "amount": 510,
    "currency": "EGP",
    "original_amount": null,
    "original_currency": null,
    "details": "Withdrawal request reserved for payout to National Bank of Egypt",
    "date": "2026-09-16T09:58:12",
    "category": "withdrawal",
    "cashback_expires_at": null,
    "formatted": { "amount": "EGP510.00", "original": null }
  }
]
```

`category` enum values: `topup`, `cashback`, `cashback_adjustment`,
`cashback_refund`, `partial_payment`, `purchase`, `transfer`, `withdrawal`,
`withdrawal_refund`, `refund`, `adjustment`, `other`.

### `GET /me/transactions/{id}`

A single transaction, ownership-checked (404 if it belongs to another
customer).

---

## `POST /me/topup`

Creates a top-up order for the calling user and returns a payment URL to
redirect to — the chosen WooCommerce gateway then handles its own
redirect/return flow exactly as it would for a cart checkout.

**Body:** `amount` (number, required, > 0), `payment_method` (string,
WooCommerce gateway id), `currency` (optional ISO 4217 code — creates the
order in that currency).

Idempotent (`Idempotency-Key` honoured; a replay returns the original
order's URL rather than creating a second order).

```json
{ "order_id": 9142, "amount": 500, "currency": "EGP", "payment_url": "https://example.com/checkout/order-pay/9142/?..." }
```

Errors surface with whatever `code`/`status` the underlying topup service
reports (e.g. amount out of the configured min/max range, feature disabled).

---

## `POST /me/transfer`

Peer-to-peer transfer to another registered customer.

**Body:**

| Field | Type | Notes |
|---|---|---|
| `recipient_id` | integer | Either this or `recipient_email` is required |
| `recipient_email` | string (email) | Resolved to a user id server-side |
| `amount` | number | Required, > 0 |
| `note` | string | Optional, attached to the recipient's credit transaction |
| `currency` | string | Optional ISO 4217 code; in `per_currency` mode this scopes the balance check and the resulting ledger rows — cross-currency transfers are rejected |

Idempotent (`Idempotency-Key` honoured).

```json
{
  "transaction_id": 5501,
  "credit_id": 5502,
  "charge": 5,
  "message": "Amount transferred successfully!",
  "balance": { "amount": 745.5, "currency": "", "formatted": "EGP745.50" }
}
```

`transaction_id` is the sender's debit row; `credit_id` is the recipient's
credit row; `charge` is the fee (if any) charged to the sender on top of
`amount`, per the site's `_wallet_settings_general` transfer-charge config.

### `GET /me/transfer/recipients?search=`

Recipient autocomplete by email/name substring — **disabled by default**.
Enable it with `add_filter( 'terawallet_rest_allow_recipient_lookup', '__return_true' )`.
When disabled the route returns `404` (not `403`) so its existence isn't
disclosed. Returns up to 10 matches with a masked email
(`a***e@example.com`) to limit harvesting:

```json
[ { "id": 12, "display_name": "Aya Ahmed", "email_masked": "a***a@example.com" } ]
```

---

## `GET /me/referrals`

Requires the referrals action to be enabled (`woo_wallet_is_enable_referrals`
filter, on by default); returns `404` when disabled.

```json
{
  "handle": "wwref",
  "code": "mohamed",
  "share_url": "https://example.com/my-account/?wwref=mohamed",
  "stats": {
    "visitors": 42,
    "signups": 5,
    "pending": 1,
    "earning": { "amount": 150, "currency": "EGP", "formatted": "EGP150.00" },
    "legacy_earning": { "amount": 0, "currency": "EGP", "formatted": "EGP0.00" }
  }
}
```

`legacy_earning` is the frozen pre-1.6.2 referral total (before per-referral
history tracking existed) — always separate from, never merged into,
`earning`.

---

## `GET /me/cashback-rules`

Read-only summary of the store's cashback program — no admin-only detail
(role exclusions, per-product overrides) is exposed.

```json
{
  "enabled": true,
  "scope": "cart",
  "type": "percent",
  "amount": 2,
  "max_amount": 100,
  "min_cart": 200,
  "formatted": { "amount": "2%", "max_amount": "EGP100.00", "min_cart": "EGP200.00" }
}
```

`scope`: `cart` \| `product` \| `category`. `type`: `percent` \| `flat`.
