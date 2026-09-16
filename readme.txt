=== Axfit Wallet ===
Tags: woocommerce wallet, cashback, store credit, partial payment, digital wallet
Requires PHP: 7.4
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 1.7.6
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

✨ WooCommerce wallet with cashback rewards, store credit, partial payment, top-ups & bank withdrawals. Boost customer loyalty effortlessly.

== Description ==

Maximize convenience and savings for your customers with **Axfit Wallet**. This all-in-one digital wallet and store credit system is specifically designed to streamline the checkout process and boost customer loyalty.

Axfit Wallet empowers your customers to deposit funds into their personal accounts, transfer money to other users, request a payout to their bank account, and make purchases effortlessly using their wallet balance. By reducing the need for repeated payment detail entries, you provide a frictionless shopping experience that encourages repeat business.

Beyond core wallet functionality, Axfit Wallet features a robust **Cashback Rewards System**. Incentivize purchases by offering rewards based on cart totals, specific products, or categories. You can even convert WooCommerce coupons into wallet rewards, providing a unique way to drive engagement.

== ✨ Why choose Axfit Wallet? ==

*   🚀 **Frictionless Checkout:** One-click payments via wallet balance reduce cart abandonment.
*   💰 **Automated Cashback:** Automated rewards keep customers coming back for more.
*   🏦 **Store Credit System:** Easily handle refunds by crediting the user's wallet instantly.
*   🔄 **Wallet Transfers:** Allow customers to share funds with friends and family.
*   🏧 **Bank Withdrawals:** Let customers cash out their wallet balance to a bank account.

== 🛠 Features ==

*   🏦 **Core Wallet Management:** A centralized ledger system that tracks every credit and debit with 100% accuracy using SQL-level locking to prevent race conditions.
*   💰 **Dynamic Cashback System:**
    *   **Cart-Wise:** Rewards based on the total order value.
    *   **Product-Wise:** Granular control over rewards for individual items.
    *   **Category-Wise:** Rewards based on product taxonomies.
*   💳 **Smart Checkout Options:**
    *   **Full Payment:** Pay for the entire order using the wallet gateway.
    *   **Partial Payment:** Use wallet balance for part of the total and pay the rest via other gateways (Stripe, PayPal, etc.).
    *   **Auto-Deduct:** Automatically apply available balance as a discount at checkout.
*   🔄 **User Empowerment:**
    *   **Wallet Top-ups:** Customers can add funds via their dashboard using any supported payment method.
    *   **Peer-to-Peer Transfers:** Securely send wallet balance to other registered users via email.
    *   **Bank Withdrawals:** Customers can request a payout of their wallet balance to a bank account (bank, beneficiary name, account number, optional IBAN), with an admin approval queue under Axfit Wallet → Withdrawals.
*   🎁 **Engagement Rewards:** Credit users for specific actions:
    *   New user registration bonus.
    *   Daily login rewards.
    *   Product review rewards.
*   🛠 **Admin Control Center:**
    *   View all user balances and transaction history.
    *   Manually adjust (credit/debit) any user's balance with detailed notes.
    *   Lock/Unlock user wallets for security and fraud prevention.
*   🔗 **Seamless Integrations:**
    *   Full support for WooCommerce Blocks checkout.
    *   Compatible with WPML and WooCommerce Subscriptions.
    *   Built-in support for Dokan, WCFM, and WCMarketplace.

*   🌍 **Multi-Currency Support:** First-class integrations with the most-used WooCommerce currency switchers. Wallet balances, top-ups, transfers, and cashback are all converted through the active provider's live rates.
    *   [YayCurrency – Multi-Currency Switcher](https://wordpress.org/plugins/yaycurrency/)
    *   [WOOCS – WooCommerce Currency Switcher (FOX)](https://wordpress.org/plugins/woocommerce-currency-switcher/)
    *   [WPML Multilingual & Multi-Currency](https://wpml.org/) (WCML)
    *   [CURCY – Multi Currency for WooCommerce](https://wordpress.org/plugins/woo-multi-currency/) (VillaTheme)
    *   [Aelia Currency Switcher](https://aelia.co/shop/currency-switcher-woocommerce/)
    *   **Generic fallback** for any other plugin that filters `woocommerce_currency` — active-currency detection still works, conversion falls open to the stored amount with an audit-log warning.

== Installation ==

= Minimum Requirements =

* PHP 7.4 or greater is required (PHP 8.0 or greater is recommended)
* MySQL 5.6 or greater, OR MariaDB version 10.1 or greater, is required
* WordPress 6.4 or greater is required
* WooCommerce 7.2 or greater is required

= Manual installation =

Upload the plugin folder to `/wp-content/plugins/` via FTP, then activate it from the Plugins menu.

= Updating =

As always, ensure you backup your site before updating.

If on the off-chance you do encounter issues with the wallet endpoints pages after an update you simply need to flush the permalinks by going to WordPress > Settings > Permalinks and hitting 'save'. That should return things to normal.

= Important =

A hidden "Wallet Topup" product is automatically created upon activation. Ensure it remains **Published** and **Private**.

== Frequently Asked Questions ==

= How does wallet payment work? =
Wallet payment acts as a native WooCommerce gateway. Customers with sufficient balance can select "Wallet" at checkout to pay for their order instantly.

= Does it support partial payment? =
Yes! If enabled in settings, customers can use their wallet balance to pay for a portion of the order and cover the remainder with another gateway like Stripe or PayPal.

= When is cashback applied? =
Cashback is triggered by order status changes. You can configure which status (e.g., 'Completed' or 'Processing') triggers the reward in the plugin settings.

= Why is the wallet not visible at checkout? =
Ensure the Wallet gateway is enabled in **WooCommerce > Settings > Payments**. Also, check if "Hide if empty" is enabled in Axfit Wallet settings if the user has a zero balance.

= Can customers withdraw their wallet balance to a bank account? =
Yes, once enabled under Axfit Wallet → Settings → Withdrawal. Customers submit a request from the "Withdraw" tab on their wallet dashboard; the amount is reserved immediately and an admin approves or rejects it under Axfit Wallet → Withdrawals.

= Where is the REST API documentation? =
See the `docs/` folder in the plugin: `docs/API-OVERVIEW.md` is the index (auth, idempotency, conventions), with `API-ME.md`, `API-ADMIN.md`, `API-WITHDRAWALS.md`, `API-SETTINGS.md`, `API-SYSTEM.md` and `API-LEGACY.md` covering each part of the `terawallet/v1` namespace.

== Screenshots ==

1. User wallet dashboard page.
2. Wallet topup page.
3. Transfer wallet balance.
4. Transaction details page.
5. Admin wallet details page.
6. Admin adjust wallet balance.
7. Admin wallet transaction details page.
8. Wallet payment gateway.
9. WooCommerce refund.
10. Wallet actions.

== Changelog ==

= v1.7.6 =
* New - Richer filters on the Axfit Wallet → Withdrawals admin screen: search by customer, filter by bank, by "self-service vs staff-logged", and by a date range, alongside the existing status filter. The same filters are now available on `GET terawallet/v1/admin/withdrawals` (`bank_name`, `requested_by`, `after`, `before`) — a customer-name search there also returns every match instead of only the first.
* New - The "Create Withdrawal" admin form's Bank field is now a dropdown of the configured banks, with an "Other" fallback to type one manually.
* New - The customer's "Your Withdrawal Requests" table now also shows the beneficiary name, account number and IBAN that were submitted with each request.

= v1.7.5 =
* New - Uploaded withdrawal receipts are now shown to the customer too (a "Receipt" column on the "Your Withdrawal Requests" table, with a link) — previously only visible to admins.
* New - Receipts are automatically deleted 90 days after the request date (filterable via `woo_wallet_withdrawal_receipt_retention_days`) via a daily housekeeping sweep, to keep the Media Library from growing forever with proof-of-payment files. A note on both the customer's history table and the admin's request detail page explains this, and the REST API exposes a `receipt_expires_at` field so integrations can warn customers before the link goes stale.
* Fix - `uninstall.php` was missing the `woo_wallet_withdrawals` / `woo_wallet_withdrawal_notes` tables from its full-removal cleanup (`WALLET_REMOVE_ALL_DATA`) — added.

= v1.7.4 =
* New - REST API for wallet withdrawals. Customers: `GET/POST terawallet/v1/me/withdrawals`, `GET terawallet/v1/me/withdrawals/{id}` (own requests only, cookie auth). Admins: `GET/POST terawallet/v1/admin/withdrawals`, `GET .../{id}`, `POST .../{id}/process`, `POST .../{id}/recover`, `POST .../{id}/notes` — the same concurrency-safe, idempotent-refund logic the admin screens use, so there is one implementation regardless of which surface calls it. `GET terawallet/v1/settings/public` now also reports withdrawal limits, charge and the configured bank list.

= v1.7.3 =
* Fix - Concurrency and money-safety hardening on withdrawal processing: two staff members can no longer both process the same request (mark-paid/reject is now conditioned on an atomic status change, not a plain read-then-write); a database error while recording a new request now credits the reservation straight back instead of leaving it dangling; a failed receipt upload now aborts the action instead of being silently ignored.
* Fix - Withdrawal refunds (on reject) are now idempotent against the wallet ledger itself, not just a status column: a reject interrupted mid-refund (server crash / DB error) lands the request on a 'processing' status, and the recovery action under Axfit Wallet → Withdrawals is safe to click any number of times from any staff member — it checks the ledger for an existing refund before ever crediting, so it can never send the same refund twice even if the request's own bookkeeping failed to record that the refund had already gone out.

= v1.7.2 =
* New - Store-wide Transactions screen (Axfit Wallet → Transactions, now visible in the sidebar): every credit/debit across every customer, filterable by customer, category and date range — no need to open a customer's own statement to see who transferred to whom.
* New - Admin can log a withdrawal manually on a customer's behalf (Axfit Wallet → Withdrawals → Create Withdrawal) for phone/offline requests; the record is attributed to the staff member who created it and, separately, to whoever marks it paid or rejects it.
* New - Withdrawal requests can carry a bank transfer reference number and an uploaded receipt (PDF/PNG/JPG), plus a running thread of notes — each marked private (staff only) or public (shown to the customer in their withdrawal history).

= v1.7.1 =
* New - Wallet withdrawal requests: customers can ask for part of their wallet balance to be paid out to a bank account (bank dropdown, beneficiary name, account number, optional IBAN) from a new "Withdraw" tab on the wallet dashboard. Requested funds are reserved from the wallet immediately; admins review, approve or reject requests under Axfit Wallet → Withdrawals. Configure minimum/maximum amounts, an optional charge, and the bank list under Axfit Wallet → Settings → Withdrawal.

== Upgrade Notice ==

= 1.7.3 =
Important money-safety fixes for withdrawal processing — recommended for anyone running 1.7.1/1.7.2 with withdrawals enabled. See the changelog for details.

= 1.7.2 =
Adds a store-wide Transactions screen, manual/staff-attributed withdrawal creation, and receipt/reference/notes on withdrawal requests.

= 1.7.1 =
Adds wallet withdrawal requests to bank accounts (off by default — enable it under Axfit Wallet → Settings → Withdrawal).
