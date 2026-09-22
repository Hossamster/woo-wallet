<?php
/**
 * Wallet admin-dashboard widget — data service.
 *
 * Read-only aggregate queries backing the Financial Health Snapshot + Alerts
 * section of Woo_Wallet_Dashboard_Widget. Mirrors Woo_Wallet_Reports_Data's
 * shape (delegates the liability figure to it rather than duplicating that
 * query) and reuses the same `woo_wallet_reports_cache_version` option —
 * already bumped on every `woo_wallet_transaction_recorded` event — so the
 * widget's cache invalidates in lockstep with the Reports page's, with no
 * new hook needed.
 *
 * Flow/high-value/net-flow figures are scoped to a selectable period (Today
 * / 7 days / This month — Phase 2's date-range tabs); total liability and
 * pending withdrawals are current-state figures and deliberately NOT
 * period-scoped — a "7-day outstanding liability" isn't a meaningful
 * number, it's simply what's owed right now.
 *
 * Every period query filters on `date` (site-local, since the ledger writes
 * it with `current_time( 'mysql' )` — see Woo_Wallet_Statement_Service's
 * resolve_range() docblock for the same reasoning) and `deleted = 0`,
 * matching the `idx_deleted_date` composite index added in Phase 0 —
 * without it, this becomes a full table scan on every dashboard page load.
 *
 * @package StandaleneTech
 * @since   1.7.13
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget_Data' ) ) {

	/**
	 * Aggregate queries for the wallet dashboard widget.
	 */
	class Woo_Wallet_Dashboard_Widget_Data {

		const PERIOD_TODAY = 'today';
		const PERIOD_7DAYS = '7days';
		const PERIOD_MONTH = 'month';

		/**
		 * Reused for total liability + currency formatting rather than
		 * duplicating those queries here.
		 *
		 * @var Woo_Wallet_Reports_Data
		 */
		protected $reports;

		/**
		 * Constructor.
		 */
		public function __construct() {
			require_once WOO_WALLET_ABSPATH . 'includes/services/class-woo-wallet-reports-data.php';
			$this->reports = new Woo_Wallet_Reports_Data();
		}

		/**
		 * Every period a caller may request. Anything else falls back to
		 * PERIOD_TODAY (see get_snapshot()/range_for_period()).
		 *
		 * @return string[]
		 */
		public function allowed_periods() {
			return array( self::PERIOD_TODAY, self::PERIOD_7DAYS, self::PERIOD_MONTH );
		}

		/**
		 * Human labels for the period tabs.
		 *
		 * @return array<string,string>
		 */
		public function period_labels() {
			return array(
				self::PERIOD_TODAY => __( 'Today', 'woo-wallet' ),
				self::PERIOD_7DAYS => __( '7 Days', 'woo-wallet' ),
				self::PERIOD_MONTH => __( 'This Month', 'woo-wallet' ),
			);
		}

		/**
		 * A short translated phrase describing a period, for inline alert
		 * copy (e.g. "outflow is unusually high against inflow today").
		 *
		 * @param string $period One of allowed_periods().
		 * @return string
		 */
		public function period_phrase( $period ) {
			switch ( $period ) {
				case self::PERIOD_7DAYS:
					return __( 'over the last 7 days', 'woo-wallet' );
				case self::PERIOD_MONTH:
					return __( 'this month', 'woo-wallet' );
				case self::PERIOD_TODAY:
				default:
					return __( 'today', 'woo-wallet' );
			}
		}

		/**
		 * Date boundaries for a period, site-local — same shape as
		 * Woo_Wallet_Statement_Service::resolve_range(). An unrecognised
		 * period resolves to PERIOD_TODAY rather than erroring, so a bad/
		 * forged AJAX request degrades to the default tab instead of a
		 * failed query.
		 *
		 * @param string $period One of allowed_periods().
		 * @return array{period:string,from:string,to:string,start:string,end:string}
		 */
		public function range_for_period( $period ) {
			$today = current_time( 'Y-m-d' );

			switch ( $period ) {
				case self::PERIOD_7DAYS:
					$from = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
					break;
				case self::PERIOD_MONTH:
					$from = current_time( 'Y-m-01' );
					break;
				default:
					$period = self::PERIOD_TODAY;
					$from   = $today;
					break;
			}

			return array(
				'period' => $period,
				'from'   => $from,
				'to'     => $today,
				'start'  => $from . ' 00:00:00',
				'end'    => $today . ' 23:59:59',
			);
		}

		/**
		 * Store base currency code.
		 *
		 * @return string
		 */
		public function base_currency() {
			return $this->reports->base_currency();
		}

		/**
		 * Format an amount in the store base currency.
		 *
		 * @param float $amount Amount.
		 * @return string
		 */
		public function format_amount( $amount ) {
			return $this->reports->format_amount( $amount );
		}

		/**
		 * Total outstanding wallet liability. Current-state — not period-scoped.
		 *
		 * @return float
		 */
		public function total_liability() {
			return $this->reports->total_liability();
		}

		/**
		 * Total inflow (credits) and outflow (debits) over a period, live rows only.
		 *
		 * @param string $period One of allowed_periods().
		 * @return array{inflow:float,outflow:float}
		 */
		public function flow_for_period( $period ) {
			global $wpdb;
			$range = $this->range_for_period( $period );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END), 0) AS inflow,
						COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE 0 END), 0) AS outflow
					 FROM {$wpdb->base_prefix}woo_wallet_transactions
					 WHERE deleted = 0 AND date BETWEEN %s AND %s",
					$range['start'],
					$range['end']
				)
			);
			return array(
				'inflow'  => $row ? (float) $row->inflow : 0.0,
				'outflow' => $row ? (float) $row->outflow : 0.0,
			);
		}

		/**
		 * Pending withdrawal requests: count + total requested amount.
		 * Current-state — not period-scoped.
		 *
		 * @return array{count:int,amount:float}
		 */
		public function pending_withdrawals() {
			$counts = Woo_Wallet_Withdrawal::count_requests_by_status();

			global $wpdb;
			$table = $wpdb->base_prefix . 'woo_wallet_withdrawals';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$amount = $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = 'pending'" );

			return array(
				'count'  => isset( $counts['pending'] ) ? (int) $counts['pending'] : 0,
				'amount' => (float) $amount,
			);
		}

		/**
		 * Merchant-configured single-transaction "high value" threshold.
		 * 0 (the default) disables the watch entirely — a store that hasn't
		 * set one has no defined notion of "unusually large" to alert on.
		 *
		 * @return float
		 */
		public function high_value_threshold() {
			$threshold = woo_wallet()->settings_api->get_option( 'dashboard_high_value_threshold', '_wallet_settings_general', 0 );
			return (float) apply_filters( 'woo_wallet_dashboard_high_value_threshold', (float) $threshold );
		}

		/**
		 * Count of live transactions at or above the high-value threshold,
		 * within a period. Empty/disabled threshold short-circuits to zero
		 * without a query.
		 *
		 * @param string $period One of allowed_periods().
		 * @return array{count:int,threshold:float}
		 */
		public function high_value_transactions_for_period( $period ) {
			$threshold = $this->high_value_threshold();
			if ( $threshold <= 0 ) {
				return array(
					'count'     => 0,
					'threshold' => $threshold,
				);
			}

			global $wpdb;
			$range = $this->range_for_period( $period );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->base_prefix}woo_wallet_transactions
					 WHERE deleted = 0 AND date BETWEEN %s AND %s AND amount >= %f",
					$range['start'],
					$range['end'],
					$threshold
				)
			);

			return array(
				'count'     => (int) $count,
				'threshold' => $threshold,
			);
		}

		/**
		 * Merchant-configured net-outflow alert trigger, as a percentage of
		 * inflow (e.g. 150 means "outflow at or above 150% of inflow").
		 * Always a usable positive number — falls back to the 150 default on
		 * anything unset or non-positive, so the alert can never be silently
		 * disabled by a blank/invalid setting the way the threshold above
		 * deliberately can be.
		 *
		 * @return float
		 */
		public function net_outflow_alert_percent() {
			$percent = woo_wallet()->settings_api->get_option( 'dashboard_net_outflow_alert_percent', '_wallet_settings_general', 150 );
			$percent = is_numeric( $percent ) && (float) $percent > 0 ? (float) $percent : 150.0;
			return (float) apply_filters( 'woo_wallet_dashboard_net_outflow_alert_percent', $percent );
		}

		/**
		 * Whether a period's flow trips the negative net-flow alert: outflow
		 * with zero inflow, or outflow at/above the configured percentage of
		 * inflow.
		 *
		 * @param float $inflow  Period inflow.
		 * @param float $outflow Period outflow.
		 * @return bool
		 */
		public function is_negative_net_flow( $inflow, $outflow ) {
			if ( $outflow <= 0 ) {
				return false;
			}
			if ( $inflow <= 0 ) {
				return true;
			}
			return ( $outflow / $inflow ) * 100 >= $this->net_outflow_alert_percent();
		}

		/**
		 * Assemble the full snapshot for a period, cached in a transient
		 * keyed on the shared reports cache-version option (see class
		 * docblock) and the period, so each tab caches independently.
		 *
		 * @param array $args `period` (one of allowed_periods(), default today) and/or `nocache` => true to bypass the cache.
		 * @return array
		 */
		public function get_snapshot( $args = array() ) {
			$period = isset( $args['period'] ) && in_array( $args['period'], $this->allowed_periods(), true )
				? $args['period']
				: self::PERIOD_TODAY;

			$version   = (int) get_option( 'woo_wallet_reports_cache_version', 0 );
			$cache_key = 'woo_wallet_dashboard_snapshot_' . $version . '_' . $period;
			$cached    = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) && empty( $args['nocache'] ) ) {
				return $cached;
			}

			$flow       = $this->flow_for_period( $period );
			$pending    = $this->pending_withdrawals();
			$high_value = $this->high_value_transactions_for_period( $period );

			$data = array(
				'period'                     => $period,
				'base_currency'              => $this->base_currency(),
				'total_liability'            => $this->total_liability(),
				'inflow'                     => $flow['inflow'],
				'outflow'                    => $flow['outflow'],
				'pending_withdrawals_count'  => $pending['count'],
				'pending_withdrawals_amount' => $pending['amount'],
				'high_value_count'           => $high_value['count'],
				'high_value_threshold'       => $high_value['threshold'],
				'is_negative_net_flow_alert' => $this->is_negative_net_flow( $flow['inflow'], $flow['outflow'] ),
				'generated_at'               => current_time( 'mysql' ),
			);

			$ttl = (int) apply_filters( 'woo_wallet_dashboard_cache_ttl', 5 * MINUTE_IN_SECONDS );
			set_transient( $cache_key, $data, max( 0, $ttl ) );

			return $data;
		}

		// ---------------------------------------------------------------
		// Phase 4 — Growth / Customer Insights. Deliberately NOT threaded
		// through get_snapshot(): those figures are re-fetched on every
		// period-tab click (Phase 2), and dormant-balance/checkout-share
		// below are the heaviest queries in this class — they get their
		// own, longer-lived cache entry (get_growth_insights()) instead of
		// adding weight to every tab switch, and aren't period-scoped
		// (a "7-day dormant balance" isn't a different question — the
		// dormancy window is its own separate, merchant-configured setting).
		// ---------------------------------------------------------------

		/**
		 * Merchant-configured "no order in N days" dormancy window.
		 *
		 * @return int
		 */
		public function dormant_days_threshold() {
			$days = woo_wallet()->settings_api->get_option( 'dashboard_dormant_days', '_wallet_settings_general', 30 );
			$days = is_numeric( $days ) && (int) $days > 0 ? (int) $days : 30;
			return (int) apply_filters( 'woo_wallet_dashboard_dormant_days', $days );
		}

		/**
		 * Positive-balance customers with no WooCommerce order in the
		 * configured dormancy window.
		 *
		 * Capped to the top N positive balances (by amount) as the
		 * candidate pool, cross-referenced against WooCommerce orders in
		 * one query for that whole pool — not a per-customer query — so
		 * this stays bounded on a large store. A store with more dormant
		 * wallets than the cap undercounts rather than running an
		 * unbounded scan; get_growth_insights() caches the result so the
		 * cost is paid at most once per cache window either way.
		 *
		 * @return array{count:int,amount:float,threshold_days:int}
		 */
		public function dormant_balances() {
			$days = $this->dormant_days_threshold();

			global $wpdb;
			$candidate_cap = (int) apply_filters( 'woo_wallet_dashboard_dormant_candidate_cap', 50 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) AS balance
					 FROM {$wpdb->base_prefix}woo_wallet_transactions
					 WHERE deleted = 0
					 GROUP BY user_id
					 HAVING balance > 0
					 ORDER BY balance DESC
					 LIMIT %d",
					$candidate_cap
				)
			);

			if ( ! $rows ) {
				return array(
					'count'          => 0,
					'amount'         => 0.0,
					'threshold_days' => $days,
				);
			}

			$candidate_ids = wp_list_pluck( $rows, 'user_id' );
			$cutoff        = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

			// Candidates who HAVE ordered since the cutoff — excluded below.
			$active_ids = array();
			if ( function_exists( 'wc_get_orders' ) ) {
				$recent_orders = wc_get_orders(
					array(
						'customer'   => $candidate_ids,
						'date_after' => $cutoff,
						'limit'      => -1,
						'return'     => 'objects',
					)
				);
				foreach ( $recent_orders as $order ) {
					$active_ids[ $order->get_customer_id() ] = true;
				}
			}

			$count  = 0;
			$amount = 0.0;
			foreach ( $rows as $row ) {
				if ( isset( $active_ids[ (int) $row->user_id ] ) ) {
					continue;
				}
				++$count;
				$amount += (float) $row->balance;
			}

			return array(
				'count'          => $count,
				'amount'         => $amount,
				'threshold_days' => $days,
			);
		}

		/**
		 * Today's P2P transfer volume: count + amount SENT (the debit leg
		 * only — a transfer writes both a debit and a credit row under
		 * `category = 'transfer'`, and a transfer fee can make the credited
		 * amount less than the debited amount, so summing both legs would
		 * both double-count and overstate volume).
		 *
		 * @return array{count:int,amount:float}
		 */
		public function p2p_transfer_volume_today() {
			global $wpdb;
			$range = $this->range_for_period( self::PERIOD_TODAY );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount
					 FROM {$wpdb->base_prefix}woo_wallet_transactions
					 WHERE deleted = 0 AND type = 'debit' AND category = 'transfer' AND date BETWEEN %s AND %s",
					$range['start'],
					$range['end']
				)
			);
			return array(
				'count'  => $row ? (int) $row->cnt : 0,
				'amount' => $row ? (float) $row->amount : 0.0,
			);
		}

		/**
		 * Cashback credited today — a plain sum, not a return-on-investment
		 * figure (true ROI needs joining back to the order value it drove,
		 * deliberately deferred — see the dashboard-widget feature plan).
		 *
		 * @return float
		 */
		public function cashback_credited_today() {
			global $wpdb;
			$range = $this->range_for_period( self::PERIOD_TODAY );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$amount = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(amount), 0)
					 FROM {$wpdb->base_prefix}woo_wallet_transactions
					 WHERE deleted = 0 AND type = 'credit' AND category = 'cashback' AND date BETWEEN %s AND %s",
					$range['start'],
					$range['end']
				)
			);
			return (float) $amount;
		}

		/**
		 * What share of today's checkout revenue was paid via wallet (full
		 * wallet-gateway `purchase` debits + `partial_payment` debits)
		 * against total revenue from today's paid WooCommerce orders.
		 *
		 * @return array{wallet_paid:float,total_revenue:float,percent:float}
		 */
		public function wallet_share_of_checkout_today() {
			global $wpdb;
			$range = $this->range_for_period( self::PERIOD_TODAY );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wallet_paid = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(amount), 0)
					 FROM {$wpdb->base_prefix}woo_wallet_transactions
					 WHERE deleted = 0 AND type = 'debit' AND category IN ('purchase','partial_payment') AND date BETWEEN %s AND %s",
					$range['start'],
					$range['end']
				)
			);

			$total_revenue = 0.0;
			if ( function_exists( 'wc_get_orders' ) ) {
				$orders = wc_get_orders(
					array(
						'date_created' => $range['from'] . '...' . $range['to'],
						'limit'        => -1,
						'return'       => 'objects',
						'status'       => wc_get_is_paid_statuses(),
					)
				);
				foreach ( $orders as $order ) {
					$total_revenue += (float) $order->get_total();
				}
			}

			return array(
				'wallet_paid'   => $wallet_paid,
				'total_revenue' => $total_revenue,
				'percent'       => $total_revenue > 0 ? ( $wallet_paid / $total_revenue ) * 100 : 0.0,
			);
		}

		/**
		 * Assemble the Growth Insights block, cached separately from
		 * get_snapshot() (see the section note above) under a longer TTL —
		 * these figures are informational, not alert-driving, so slightly
		 * staler data is an acceptable trade for not re-running the
		 * dormant-balance/checkout-share queries on every page load.
		 *
		 * @param array $args `nocache` => true to bypass the cache.
		 * @return array
		 */
		public function get_growth_insights( $args = array() ) {
			$version   = (int) get_option( 'woo_wallet_reports_cache_version', 0 );
			$cache_key = 'woo_wallet_dashboard_growth_' . $version;
			$cached    = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) && empty( $args['nocache'] ) ) {
				return $cached;
			}

			$dormant  = $this->dormant_balances();
			$transfer = $this->p2p_transfer_volume_today();
			$checkout = $this->wallet_share_of_checkout_today();

			$data = array(
				'dormant_count'           => $dormant['count'],
				'dormant_amount'          => $dormant['amount'],
				'dormant_threshold_days'  => $dormant['threshold_days'],
				'transfer_count'          => $transfer['count'],
				'transfer_amount'         => $transfer['amount'],
				'cashback_credited_today' => $this->cashback_credited_today(),
				'wallet_paid_today'       => $checkout['wallet_paid'],
				'checkout_total_today'    => $checkout['total_revenue'],
				'wallet_share_percent'    => $checkout['percent'],
				'generated_at'            => current_time( 'mysql' ),
			);

			$ttl = (int) apply_filters( 'woo_wallet_dashboard_growth_cache_ttl', 15 * MINUTE_IN_SECONDS );
			set_transient( $cache_key, $data, max( 0, $ttl ) );

			return $data;
		}
	}
}
