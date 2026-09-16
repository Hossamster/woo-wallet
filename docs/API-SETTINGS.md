# Settings API (`terawallet/v1/settings/*`)

See [API-OVERVIEW.md](API-OVERVIEW.md) for auth, idempotency and error
conventions. Every route here (except `settings/public`, documented at the
bottom) requires `get_wallet_user_capability()` — `manage_woocommerce` by
default, filterable independently of the rest of the admin namespace.

This is the API that powers the plugin's own React settings screen
(TeraWallet → Settings) — the same endpoints are safe to drive from your
own tooling if you'd rather manage configuration outside the UI.

---

## `GET /settings`

Returns the full settings schema: every section, every field's definition
(type, label, validation hints, `show_if` conditional-display rules), the
currently-saved values for every section, and a `context` block of
reference data the field renderer needs (payment gateway list, order
statuses, user roles, nav menu locations, tax classes).

```json
{
  "sections": [
    { "id": "_wallet_settings_general", "title": "General Options", "icon": "dashicons-admin-generic" },
    { "id": "_wallet_settings_withdrawal", "title": "Withdrawal", "icon": "dashicons-money" }
  ],
  "fields": {
    "_wallet_settings_withdrawal": [
      { "name": "is_enable_wallet_withdrawal", "label": "Enable Wallet Withdrawal", "type": "checkbox", "default": "off", "group": "wallet_withdrawal", "...": "..." }
    ]
  },
  "values": {
    "_wallet_settings_withdrawal": { "is_enable_wallet_withdrawal": "on", "min_withdrawal_amount": "50" }
  },
  "context": {
    "currencySymbol": "£", "gateways": { "...": "..." }, "allowedGateways": { "...": "..." },
    "orderStatuses": { "...": "..." }, "userRoles": { "...": "..." },
    "menuLocations": { "...": "..." }, "taxClasses": { "...": "..." }, "taxEnabled": true
  }
}
```

Every field definition's `desc` is renamed to `hint` for this response (kept
as `hint` rather than `desc` to match the settings UI's own naming), a
`select` field with `multiple: true` is reported as `type: "multiselect"`,
and `label`/`hint` are passed through `wp_kses_post()` as defence-in-depth
since they can originate from third-party filters.

Sections/fields registered dynamically by JS (`wallet_ext_*` and
`_wallet_settings_*` option rows not already covered by a PHP-registered
section) are included in `values` too, so a reloaded page rehydrates
correctly.

---

## `POST /settings/section`

Save one section's values — the canonical way to change any wallet setting
(withdrawal limits, transfer charges, cashback rules, everything under
TeraWallet → Settings).

**Body:** `section_id` (required — must be one of the ids from `GET
/settings`), `values` (required, object of `{field_name: value}`).

Each field is sanitized according to its own declared `type` (or a custom
`sanitize_callback` the field registered): checkboxes coerce to `'on'`/`'off'`,
numbers pass through `is_numeric()`, `attachment` fields to `absint()`,
constrained `select` values are snapped to a valid option, arrays are
sanitized element-wise, everything else through `sanitize_text_field()`
unless the field explicitly registered a different callback (e.g.
`sanitize_textarea_field` for the multi-line withdrawal bank list — a
field's own `sanitize_callback` always wins over the generic per-type
default, which is why a multi-line value survives).

```bash
curl -X POST '.../wp-json/terawallet/v1/settings/section' \
  -H 'X-WP-Nonce: <nonce>' -H 'Content-Type: application/json' \
  --cookie '...' \
  -d '{"section_id": "_wallet_settings_withdrawal", "values": {"is_enable_wallet_withdrawal": "on", "min_withdrawal_amount": 50}}'
```

Returns `{"section_id": ..., "values": {...the saved values, re-read from the option...}}`.
`400 woo_wallet_invalid_section` for an unknown `section_id`.

The special section `_wallet_settings_actions` flattens every registered
"action" (New user bonus, Daily login reward, etc.) into one option row with
`{action_id}__{field_key}` keys — `GET /settings` already returns them
pre-flattened this way, so round-tripping the same keys back through this
endpoint just works.

---

## `POST /settings/js-section`

Save a section that was registered client-side (a JS-only settings tab, not
declared in PHP) rather than from the fixed schema `GET /settings` returns
for PHP-registered sections.

**Body:** `section_id` (required, must match `^(?:_wallet_settings_|wallet_ext_)[a-z0-9_]+$`
— anything else, or a reserved WP core option name, is rejected),
`fields_schema` (required array of `{name, sanitize}` — `sanitize` is
validated against a fixed whitelist server-side and silently coerced to
`text` if not recognised, so a compromised or buggy client can never smuggle
an unsafe sanitizer), `values` (required object).

Whitelisted `sanitize` hints: `text`, `textarea`, `kses_post`, `number`,
`absint`, `float`, `bool`, `email`, `url`, `key`, `array_of_text`,
`array_of_int`, `attachment_id`, `color_hex`.

Fires `do_action( 'woo_wallet_js_section_saved', $section_id, $sanitized, $old_values )`
on save. Returns `{"section_id": ..., "values": {...}}`.

---

## `POST /settings/action` — deprecated

Old per-action `{action_id, values}` shape. Internally proxies to `POST
/settings/section` with `section_id=_wallet_settings_actions` and flattened
`{action_id}__{key}` keys, and emits a PHP `_doing_it_wrong()` notice.
**Use `POST /settings/section` directly instead.**

---

## `GET /settings/public`

The one **unauthenticated** endpoint in this whole namespace — the
site-wide configuration a customer-facing app needs before login (or before
authenticating at all): currency, and enabled/min/max/charge for top-up,
transfer and withdrawal. Public, cached 60 seconds.

```json
{
  "currency": { "code": "EGP", "symbol": "£" },
  "topup": { "enabled": true, "min": 10, "max": 5000 },
  "transfer": { "enabled": true, "min": 5, "max": 2000, "charge_type": "percent", "charge_value": 1 },
  "withdrawal": { "enabled": true, "min": 50, "max": 5000, "charge_type": "fixed", "charge_value": 10, "banks": ["National Bank of Egypt (البنك الأهلي المصري)", "..."] },
  "cashback_enabled": true,
  "partial_payment": true,
  "terms_url": ""
}
```

`withdrawal.banks` is exactly the list `bank_name` must match on
`POST me/withdrawals` — see [API-WITHDRAWALS.md](API-WITHDRAWALS.md).
Filterable via `terawallet_rest_public_settings`; nothing user-scoped
belongs in this payload (anything about a specific customer goes in `/me/*`
instead, behind the authenticated gate).
