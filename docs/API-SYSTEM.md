# System API (`terawallet/v1/multicurrency`)

See [API-OVERVIEW.md](API-OVERVIEW.md) for auth and error conventions.
Requires `get_wallet_user_capability()` (default `manage_woocommerce`).

## `GET /multicurrency`

Read-only inspection of the currency manager's runtime state — which
multicurrency plugin (if any) is active, what mode the wallet ledger is
storing in, and which currencies are usable. Powers the "Currency Handling"
panel in TeraWallet → Settings; useful for any external tooling that needs
to know which currency to send in a top-up/transfer/withdrawal request.

```json
{
  "base_currency": "EGP",
  "active_currency": "EGP",
  "mode": "single_base",
  "mode_setting": "single_base",
  "per_currency_enabled": false,
  "active_provider": null,
  "all_providers": [
    { "id": "yaycurrency", "label": "YayCurrency", "available": false },
    { "id": "woocs", "label": "WOOCS", "available": false }
  ],
  "supported_currencies": ["EGP"]
}
```

- `mode` is the **effective** storage mode (what the ledger is actually
  doing right now); `mode_setting` is the raw saved option — they can differ
  when `per_currency_enabled` is `false` (a site-wide feature gate via the
  `woo_wallet_enable_per_currency_mode` filter), in which case `mode` always
  falls back to `single_base` regardless of `mode_setting`.
- `active_provider` is `null` when no multicurrency plugin (YayCurrency,
  WOOCS, WCML, CURCY, Aelia, or the generic `woocommerce_currency`-filter
  fallback) is active — in that case the store only ever has one currency,
  `base_currency`.
- Response carries `Cache-Control: private, no-store` — this reflects live
  plugin state, never cache it.

Filterable via `terawallet_rest_multicurrency_state`.
