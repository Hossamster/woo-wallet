<?php
/**
 * Wallet admin-dashboard widget — data service.
 *
 * Read-only aggregate queries backing the Financial Health Snapshot + Alerts
 * section of Woo_Wallet_Dashboard_Widget (Phase 1 of the dashboard-widget
 * feature). Mirrors Woo_Wallet_Reports_Data's shape (delegates the liability
 * figure to it rather than duplicating that query) and reuses the same
 * `woo_wallet_reports_cache_version` option — already bumped on every
 * `woo_wallet_transaction_recorded` event — so the widget's cache
 * invalidates in lockstep with the Reports page's, with no new hook needed.
 *
 * Every "today" query filters on `date` (site-local, since the ledger writes
 * it with `current_time( 'mysql' )` — see Woo_Wallet_Statement_Service's
 * resolve_range() docblock for the same reasoning) and `deleted = 0`,
 * matching the new `idx_deleted_date` composite index added in Phase 0 —
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
		 * Today's date boundaries, site-local — same shape as
		 * Woo_Wallet_Statement_Service::resolve_range().
		 *
		 * @return array{date:string,start:string,end:string}
		 */
		public function today_range() {
			$today = current_time( 'Y-m-d' );
			return array(
				'date'  => $today,
				'start' => $today . ' 00:00:00',
				'end'   => $today . ' 23:59:59',
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
		 * Total outstanding wallet liability.
		 *
		 * @return float
		 */
		public function total_liability() {
			return $this->reports->total_liability();
		}

		/**
		 * Today's total inflow (credits) and outflow (debits), live rows only.
		 *
		 * @return array{inflow:float,outflow:float}
		 */
		public function today_flow() {
			global $wpdb;
			$range = $this->today_range();
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
		 * Count of today's live transactions at or above the high-value
		 * threshold. Empty/disabled threshold short-circuits to zero without
		 * a query.
		 *
		 * @return array{count:int,threshold:float}
		 */
		public function high_value_transactions_today() {
			$threshold = $this->high_value_threshold();
			if ( $threshold <= 0 ) {
				return array(
					'count'     => 0,
					'threshold' => $threshold,
				);
			}

			global $wpdb;
			$range = $this->today_range();
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
		 * Whether today's flow trips the negative net-flow alert: outflow is
		 * zero-inflow-with-any-outflow, or at/above the configured percentage
		 * of inflow.
		 *
		 * @param float $inflow  Today's inflow.
		 * @param float $outflow Today's outflow.
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
		 * Assemble the full Phase 1 snapshot, cached in a transient keyed on
		 * the shared reports cache-version option (see class docblock).
		 *
		 * @param array $args Pass `array( 'nocache' => true )` to bypass the cache.
		 * @return array
		 */
		public function get_snapshot( $args = array() ) {
			$version   = (int) get_option( 'woo_wallet_reports_cache_version', 0 );
			$cache_key = 'woo_wallet_dashboard_snapshot_' . $version;
			$cached    = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) && empty( $args['nocache'] ) ) {
				return $cached;
			}

			$flow       = $this->today_flow();
			$pending    = $this->pending_withdrawals();
			$high_value = $this->high_value_transactions_today();

			$data = array(
				'base_currency'              => $this->base_currency(),
				'total_liability'            => $this->total_liability(),
				'today_inflow'               => $flow['inflow'],
				'today_outflow'              => $flow['outflow'],
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
	}
}
