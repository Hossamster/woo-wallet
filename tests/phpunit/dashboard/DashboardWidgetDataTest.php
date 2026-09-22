<?php
/**
 * Woo_Wallet_Dashboard_Widget_Data — aggregate queries backing the dashboard
 * widget's Financial Health Snapshot + Alerts section (Phase 1) and its
 * Today / 7 Days / This Month date-range tabs (Phase 2).
 */
class Dashboard_Widget_Data_Test extends WP_UnitTestCase {

	/**
	 * @var Woo_Wallet_Dashboard_Widget_Data
	 */
	private $data;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget_Data' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/services/class-woo-wallet-dashboard-widget-data.php';
		}
		$this->data = new Woo_Wallet_Dashboard_Widget_Data();
	}

	/**
	 * Woo_Wallet_Settings_API stores every field for a section under one
	 * serialized WP option — same helper pattern as SubmitRequestTest.
	 */
	private function set_general_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_general', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_general', $options );
	}

	/**
	 * Insert a transaction row directly, bypassing woo_wallet()->wallet, so
	 * the `date` column can be backdated — the wallet API always stamps
	 * `current_time('mysql')` and has no "as of" parameter.
	 */
	private function insert_transaction( $user_id, $type, $amount, $date ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'woo_wallet_transactions',
			array(
				'user_id'  => $user_id,
				'type'     => $type,
				'category' => 'other',
				'amount'   => $amount,
				'currency' => 'USD',
				'deleted'  => 0,
				'date'     => $date,
			)
		);
	}

	private function insert_withdrawal( $user_id, $amount, $status ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'woo_wallet_withdrawals',
			array(
				'user_id'      => $user_id,
				'amount'       => $amount,
				'status'       => $status,
				'date_created' => current_time( 'mysql' ),
			)
		);
	}

	// -- total_liability() ------------------------------------------------

	public function test_total_liability_delegates_to_reports_data() {
		$user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $user_id, 100, 'test' );
		woo_wallet()->wallet->debit( $user_id, 40, 'test' );

		$this->assertSame( 60.0, $this->data->total_liability() );
	}

	// -- range_for_period() --------------------------------------------------

	public function test_range_for_period_today_is_a_single_day() {
		$today = current_time( 'Y-m-d' );
		$range = $this->data->range_for_period( 'today' );

		$this->assertSame( 'today', $range['period'] );
		$this->assertSame( $today, $range['from'] );
		$this->assertSame( $today, $range['to'] );
	}

	public function test_range_for_period_7days_spans_seven_days_inclusive() {
		$today = current_time( 'Y-m-d' );
		$range = $this->data->range_for_period( '7days' );

		$this->assertSame( gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ), $range['from'] );
		$this->assertSame( $today, $range['to'] );
	}

	public function test_range_for_period_month_starts_on_the_first() {
		$range = $this->data->range_for_period( 'month' );

		$this->assertSame( current_time( 'Y-m-01' ), $range['from'] );
		$this->assertSame( current_time( 'Y-m-d' ), $range['to'] );
	}

	public function test_range_for_period_falls_back_to_today_for_an_unknown_period() {
		$today = current_time( 'Y-m-d' );
		$range = $this->data->range_for_period( 'not-a-real-period' );

		$this->assertSame( 'today', $range['period'] );
		$this->assertSame( $today, $range['from'] );
	}

	public function test_allowed_periods_and_labels_are_consistent() {
		$allowed = $this->data->allowed_periods();
		$labels  = $this->data->period_labels();

		foreach ( $allowed as $period ) {
			$this->assertArrayHasKey( $period, $labels );
		}
	}

	// -- flow_for_period() ----------------------------------------------------

	public function test_flow_for_period_today_sums_only_todays_live_transactions() {
		$user_id   = self::factory()->user->create();
		$today     = current_time( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		$this->insert_transaction( $user_id, 'credit', 100, $today . ' 10:00:00' );
		$this->insert_transaction( $user_id, 'debit', 30, $today . ' 11:00:00' );
		// Outside today's range — must not be counted.
		$this->insert_transaction( $user_id, 'credit', 500, $yesterday . ' 10:00:00' );

		$flow = $this->data->flow_for_period( 'today' );

		$this->assertSame( 100.0, $flow['inflow'] );
		$this->assertSame( 30.0, $flow['outflow'] );
	}

	public function test_flow_for_period_7days_includes_transactions_from_earlier_in_the_window() {
		$user_id     = self::factory()->user->create();
		$today       = current_time( 'Y-m-d' );
		$three_ago   = gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) );
		$eight_ago   = gmdate( 'Y-m-d', strtotime( $today . ' -8 days' ) );

		$this->insert_transaction( $user_id, 'credit', 100, $three_ago . ' 10:00:00' );
		// Outside the 7-day window — must not be counted.
		$this->insert_transaction( $user_id, 'credit', 500, $eight_ago . ' 10:00:00' );

		$flow = $this->data->flow_for_period( '7days' );

		$this->assertSame( 100.0, $flow['inflow'] );
	}

	public function test_flow_for_period_ignores_soft_deleted_rows() {
		global $wpdb;
		$user_id = self::factory()->user->create();
		$today   = current_time( 'Y-m-d' );

		$wpdb->insert(
			$wpdb->base_prefix . 'woo_wallet_transactions',
			array(
				'user_id'  => $user_id,
				'type'     => 'credit',
				'category' => 'other',
				'amount'   => 999,
				'currency' => 'USD',
				'deleted'  => 1,
				'date'     => $today . ' 09:00:00',
			)
		);

		$flow = $this->data->flow_for_period( 'today' );

		$this->assertSame( 0.0, $flow['inflow'] );
	}

	// -- pending_withdrawals() ----------------------------------------------

	public function test_pending_withdrawals_counts_and_sums_pending_only() {
		$user_id = self::factory()->user->create();
		$this->insert_withdrawal( $user_id, 50, 'pending' );
		$this->insert_withdrawal( $user_id, 75, 'pending' );
		$this->insert_withdrawal( $user_id, 200, 'paid' );

		$pending = $this->data->pending_withdrawals();

		$this->assertSame( 2, $pending['count'] );
		$this->assertSame( 125.0, $pending['amount'] );
	}

	public function test_pending_withdrawals_is_zero_when_none_pending() {
		$pending = $this->data->pending_withdrawals();

		$this->assertSame( 0, $pending['count'] );
		$this->assertSame( 0.0, $pending['amount'] );
	}

	// -- high_value_transactions_for_period() ---------------------------------

	public function test_high_value_watch_disabled_by_default() {
		$this->assertSame( 0.0, $this->data->high_value_threshold() );

		$user_id = self::factory()->user->create();
		$this->insert_transaction( $user_id, 'credit', 100000, current_time( 'Y-m-d' ) . ' 10:00:00' );

		$result = $this->data->high_value_transactions_for_period( 'today' );
		$this->assertSame( 0, $result['count'], 'A zero/unset threshold must disable the watch, not match everything.' );
	}

	public function test_high_value_watch_counts_only_qualifying_transactions_in_period() {
		$this->set_general_option( 'dashboard_high_value_threshold', 500 );
		$user_id = self::factory()->user->create();
		$today   = current_time( 'Y-m-d' );

		$this->insert_transaction( $user_id, 'credit', 500, $today . ' 10:00:00' ); // at threshold, counts.
		$this->insert_transaction( $user_id, 'credit', 499.99, $today . ' 10:00:00' ); // below, doesn't count.
		$this->insert_transaction( $user_id, 'debit', 600, $today . ' 11:00:00' ); // either type counts.

		$result = $this->data->high_value_transactions_for_period( 'today' );

		$this->assertSame( 500.0, $result['threshold'] );
		$this->assertSame( 2, $result['count'] );
	}

	public function test_high_value_watch_respects_the_selected_period() {
		$this->set_general_option( 'dashboard_high_value_threshold', 500 );
		$user_id   = self::factory()->user->create();
		$eight_ago = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -8 days' ) );

		// Qualifies for the amount, but outside both the "today" and the
		// 7-day window.
		$this->insert_transaction( $user_id, 'credit', 600, $eight_ago . ' 10:00:00' );

		$this->assertSame( 0, $this->data->high_value_transactions_for_period( 'today' )['count'] );
		$this->assertSame( 0, $this->data->high_value_transactions_for_period( '7days' )['count'] );
	}

	// -- net_outflow_alert_percent() / is_negative_net_flow() ---------------

	public function test_net_outflow_alert_percent_defaults_to_150() {
		$this->assertSame( 150.0, $this->data->net_outflow_alert_percent() );
	}

	public function test_net_outflow_alert_percent_reads_the_configured_value() {
		$this->set_general_option( 'dashboard_net_outflow_alert_percent', 200 );
		$this->assertSame( 200.0, $this->data->net_outflow_alert_percent() );
	}

	public function test_negative_net_flow_is_false_when_there_is_no_outflow() {
		$this->assertFalse( $this->data->is_negative_net_flow( 0, 0 ) );
		$this->assertFalse( $this->data->is_negative_net_flow( 100, 0 ) );
	}

	public function test_negative_net_flow_is_true_when_outflow_with_zero_inflow() {
		$this->assertTrue( $this->data->is_negative_net_flow( 0, 1 ) );
	}

	public function test_negative_net_flow_triggers_at_the_configured_percentage() {
		// Default 150%: outflow must be >= 1.5x inflow.
		$this->assertFalse( $this->data->is_negative_net_flow( 100, 149 ) );
		$this->assertTrue( $this->data->is_negative_net_flow( 100, 150 ) );
	}

	// -- get_snapshot() -------------------------------------------------------

	public function test_get_snapshot_shape() {
		$snapshot = $this->data->get_snapshot();

		foreach ( array( 'period', 'base_currency', 'total_liability', 'inflow', 'outflow', 'pending_withdrawals_count', 'pending_withdrawals_amount', 'high_value_count', 'high_value_threshold', 'is_negative_net_flow_alert', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $snapshot );
		}
	}

	public function test_get_snapshot_defaults_to_the_today_period() {
		$snapshot = $this->data->get_snapshot();
		$this->assertSame( 'today', $snapshot['period'] );
	}

	public function test_get_snapshot_falls_back_to_today_for_an_unknown_period() {
		$snapshot = $this->data->get_snapshot( array( 'period' => 'not-a-real-period' ) );
		$this->assertSame( 'today', $snapshot['period'] );
	}

	public function test_get_snapshot_scopes_flow_to_the_requested_period() {
		$user_id   = self::factory()->user->create();
		$three_ago = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -3 days' ) );
		$this->insert_transaction( $user_id, 'credit', 250, $three_ago . ' 10:00:00' );

		$today_snapshot = $this->data->get_snapshot( array( 'period' => 'today' ) );
		$week_snapshot  = $this->data->get_snapshot( array( 'period' => '7days' ) );

		$this->assertSame( 0.0, $today_snapshot['inflow'] );
		$this->assertSame( 250.0, $week_snapshot['inflow'] );
	}

	public function test_get_snapshot_caches_each_period_independently() {
		$user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $user_id, 100, 'test' );

		// Warm only the "today" cache entry.
		$this->data->get_snapshot( array( 'period' => 'today' ) );

		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->base_prefix}woo_wallet_transactions SET amount = 999" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// "7days" was never cached — it must compute fresh and see the
		// update, proving it isn't reusing "today"'s cache entry.
		$week = $this->data->get_snapshot( array( 'period' => '7days' ) );
		$this->assertSame( 999.0, $week['total_liability'], "An uncached period must not reuse another period's cached entry." );

		// "today" must still serve its own untouched, earlier cache.
		$today_again = $this->data->get_snapshot( array( 'period' => 'today' ) );
		$this->assertSame( 100.0, $today_again['total_liability'] );
	}

	public function test_get_snapshot_is_cached_until_the_reports_cache_version_bumps() {
		$user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $user_id, 100, 'test' );

		$first = $this->data->get_snapshot();
		$this->assertSame( 100.0, $first['total_liability'] );

		// Ledger changes without a cache-version bump — same cached figure.
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->base_prefix}woo_wallet_transactions SET amount = 999" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$second = $this->data->get_snapshot();
		$this->assertSame( 100.0, $second['total_liability'], 'Must serve the cached snapshot, not recompute on every call.' );

		// The real write path (wallet->credit/debit) bumps the version via
		// the woo_wallet_transaction_recorded hook — simulate that directly.
		update_option( 'woo_wallet_reports_cache_version', (int) get_option( 'woo_wallet_reports_cache_version', 0 ) + 1 );
		$third = $this->data->get_snapshot();
		$this->assertSame( 999.0, $third['total_liability'], 'A bumped cache version must recompute.' );
	}

	public function test_get_snapshot_nocache_bypasses_the_cache() {
		$user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $user_id, 100, 'test' );
		$this->data->get_snapshot(); // warm the cache.

		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->base_prefix}woo_wallet_transactions SET amount = 999" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$fresh = $this->data->get_snapshot( array( 'nocache' => true ) );
		$this->assertSame( 999.0, $fresh['total_liability'] );
	}
}
