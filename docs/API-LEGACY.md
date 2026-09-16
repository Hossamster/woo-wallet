# Legacy API (`wc/v3/wallet/*`) — deprecated

**Do not target these for new integrations.** They exist only so
already-deployed server-to-server integrations (using WooCommerce consumer
keys) keep working, and will be removed in the plugin's next major version
(2.0). Every response from this namespace carries:

```
X-TeraWallet-Deprecated: 1
X-TeraWallet-Successor: <canonical terawallet/v1 path>
```

If you're building something new, or maintaining something old, use the
successor path from that header — see [API-OVERVIEW.md](API-OVERVIEW.md) and
the other docs in this folder.

## Routing table

| Legacy route | Canonical replacement | Notes |
|---|---|---|
| `GET /wc/v3/wallet` | `GET terawallet/v1/admin/transactions` | List transactions |
| `POST /wc/v3/wallet` | `POST terawallet/v1/admin/transactions` | Create a credit/debit — legacy body uses `email` instead of `user_id` |
| `GET /wc/v3/wallet/{id}` | `GET terawallet/v1/admin/transactions/{id}` | |
| `GET /wc/v3/wallet/balance?email=` | `GET terawallet/v1/admin/users/{id}/balance` | Legacy takes `email`, canonical takes a path `id` |
| `GET /wc/v3/wallet/settings` | `GET terawallet/v1/settings` | Thin proxy, no independent logic |
| `POST /wc/v3/wallet/settings/section` | `POST terawallet/v1/settings/section` | Thin proxy |
| `POST /wc/v3/wallet/settings/action` | `POST terawallet/v1/settings/section` (with `_wallet_settings_actions`) | Thin proxy to the *already-deprecated* `settings/action` shim — two deprecation layers deep, migrate straight to `settings/section` |
| `POST /wc/v3/wallet/settings/js-section` | `POST terawallet/v1/settings/js-section` | Thin proxy |
| `GET /wc/v3/wallet/multicurrency` | `GET terawallet/v1/multicurrency` | Thin proxy, no independent logic |

The `wc/v3/wallet` and `wc/v3/wallet/balance` routes (transactions +
balance) retain their **original** 1.3.x-era request/response shape for
back-compat (e.g. identifying a user by `email` rather than `user_id`) — they
are not simple proxies like the settings/multicurrency shims, which forward
verbatim to the canonical controller and add nothing of their own. There is
no withdrawal, transfer, referral or cashback-rules equivalent in this
legacy namespace — those features either postdate it or were never exposed
here; use `terawallet/v1` for all of them.
