# Admin API (`terawallet/v1/admin/*`)

See [API-OVERVIEW.md](API-OVERVIEW.md) for auth, idempotency, pagination and
error conventions. Every route here requires `manage_woocommerce` (or
whatever `woo_wallet_rest_check_permissions` / `woo_wallet_reports_capability`
allow) and, unlike `/me/*`, **does** accept WooCommerce consumer-key auth for
server-to-server integrations.

Withdrawals (`admin/withdrawals`) are documented separately in
[API-WITHDRAWALS.md](API-WITHDRAWALS.md).

---

## Transactions ledger — `admin/transactions`

Store-wide ledger CRUD, powering the admin transactions DataView.

### `GET /admin/transactions`

**Query params:**

| Param | Notes |
|---|---|
| `page`, `per_page` | Default 1 / 20, max 200 |
| `orderby` | `id` (alias for `transaction_id`) \| `transaction_id` \| `date` \| `amount` \| `currency` \| `type` \| `user_id` |
| `order` | `asc` \| `desc`, default `desc` |
| `user_id` | Exact match |
| `user_ids` | Array of ids — used when `user_id` isn't given |
| `type` | `credit` \| `debit` |
| `currency`, `original_currency` | ISO code exact match |
| `category` | See the category enum in [API-ME.md](API-ME.md#get-metransactions) |
| `after`, `before` | ISO 8601 date-time bounds |
| `include`, `exclude` | Arrays of transaction ids |
| `include_deleted` | boolean, default false |
| `search` | Matches against user login/email/display name — resolved to `user_ids` server-side (adds to, doesn't replace, an explicit `user_id`/`user_ids`) |

Response items use the shared `build_transaction_data()` projection (same
shape philosophy as the customer ledger, plus `user_id`, `user`,
`created_by`, `deleted` — fields a customer never sees):

```json
{
  "id": 5501, "user_id": 7,
  "user": { "id": 7, "login": "mohamed", "email": "mohamed@example.com", "display_name": "Mohamed Ali", "avatar_url": "..." },
  "type": "debit", "amount": 510, "currency": "EGP",
  "original_amount": null, "original_currency": null, "original_rate": null,
  "mode": 0, "details": "Withdrawal request reserved for payout to National Bank of Egypt",
  "date": "2026-09-16T09:58:12", "created_by": 7, "deleted": false,
  "category": "withdrawal", "cashback_expires_at": null,
  "formatted": { "amount": "EGP510.00", "original_amount": null, "date": "16 Sep 2026 9:58 am", "type_label": "Debit", "category_label": "Withdrawal" }
}
```

### `POST /admin/transactions`

Manually credit or debit a customer's wallet.

**Body:** `user_id` (required), `type` (`credit` \| `debit`, required),
`amount` (required, > 0), `currency` (optional), `note` (optional).
Requires `Idempotency-Key`. Returns `201` with the created transaction, or
`404` for an invalid `user_id`, `500` `terawallet_rest_transaction_failed`
if the ledger write itself failed (e.g. insufficient balance on a debit).

### `GET /admin/transactions/{id}`

Single transaction by id, including its full meta rows.

### `PATCH /admin/transactions/{id}`

Edit the `details` note on an existing transaction. **Does not touch the
balance** — admin metadata editing only. Body: `details` (string, required).

### `DELETE /admin/transactions/{id}?force=`

Soft-deletes (sets `deleted=1`) by default; `force=true` hard-deletes. Either
way, **the balance is not automatically adjusted** — see
`Woo_Wallet_Wallet::delete_transaction()` if you need balance-preserving
cleanup logic (`woo_wallet_purge_user_transactions()`, used by
`admin/users/{id}/transactions/purge` below, does adjust balance).

### `POST /admin/transactions/bulk`

Bulk credit, debit, or delete. Requires `Idempotency-Key`; internally each
row also gets its own per-row idempotency claim (`{key}:{user_id}`), so a
retry after a mid-loop crash never re-credits rows that already succeeded on
the first pass — a row still `in_progress` when the retry runs is reported
as `{"ok": false, "in_progress": true}`, not as a failure.

**Body (credit/debit):** `action` (`credit` \| `debit`), `user_ids`
(array), `amount`, `currency` (optional), `note` (optional).
**Body (delete):** `action: "delete"`, `ids` (array), `force` (optional bool).

```json
{ "action": "credit", "results": [
  { "user_id": 7, "transaction_id": 5503, "ok": true },
  { "user_id": 12, "transaction_id": 0, "ok": false, "in_progress": true }
] }
```

---

## Users — `admin/users`

Per-user wallet balance summary, mirroring the legacy "Wallet Users" screen.

### `GET /admin/users`

**Query params:** `page`, `per_page` (max 100), `orderby`
(`login` \| `email` \| `display_name` \| `registered` \| `balance`), `order`
(`asc` \| `desc`), `search`, `role`.

```json
{
  "id": 7, "login": "mohamed", "email": "mohamed@example.com", "display_name": "Mohamed Ali",
  "registered": "2025-01-02T10:00:00", "avatar_url": "...", "roles": ["customer"],
  "base_currency": "EGP", "balance": 1250.5, "balance_formatted": "EGP1,250.50",
  "by_currency": { "EGP": 1250.5 },
  "total_deposits": 5000, "total_deposits_formatted": "EGP5,000.00",
  "total_spent": 3239.5, "total_spent_formatted": "EGP3,239.50",
  "cashback_earned": 100, "cashback_earned_formatted": "EGP100.00",
  "is_locked": false
}
```

### `GET /admin/users/{id}/balance`

Just the multicurrency balance breakdown for one user (the same shape
`woo_wallet_get_balance_by_currency()` returns, embedded in the list rows
above).

### `POST /admin/users/{id}/transactions/purge`

Delete a user's transaction history. **Requires `Idempotency-Key`** — this
is destructive and cannot be undone.

**Body:** `delete_mode` (`soft` \| `hard`, default `soft`),
`balance_handling` (`keep` \| `wipe`, default `keep`).

```json
{ "purged": true, "user_id": 7, "...": "additional fields from woo_wallet_purge_user_transactions()" }
```

---

## Transfer — `admin/transfer`

Admin-initiated peer-to-peer transfer (moves money between two customers
directly, bypassing the sender's own balance-owner context — an admin
action, not a customer one).

### `POST /admin/transfer`

**Body:** `from_user_id` (required), `to_user_id` (required, must differ
from `from_user_id`), `amount` (required, > 0), `debit_note` (optional),
`credit_note` (optional). Requires `Idempotency-Key`.

```json
{ "transferred": true, "from_user_id": 7, "to_user_id": 12, "amount": 100, "result": { "debit": 5504, "credit": 5505 } }
```

`404 terawallet_rest_invalid_user` for an unknown id;
`400 terawallet_rest_transfer_same_user` when sender and recipient match.

---

## Reports — `admin/reports/summary`

### `GET /admin/reports/summary`

Read-only store-wide wallet liability summary — the same data the admin
Dashboard page's cards show (total liability, composition breakdown, etc.).
Cached; pass `?nocache=1` to bypass (used by the dashboard's own Refresh
button). Response shape is intentionally open-ended (filtered through
`woo_wallet_reports_summary_data` so a Pro add-on can inject extra fields) —
inspect a live response for the current field set rather than relying on a
fixed schema here. Requires whatever `woo_wallet_reports_capability` filters
to (defaults to `manage_woocommerce`, same as everything else in this
namespace, but can be narrowed independently of the other admin routes).
