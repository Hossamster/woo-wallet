<?php
/**
 * Woo_Wallet_Dashboard_Widget_Data — Phase 1 aggregate queries backing the
 * dashboard widget's Financial Health Snapshot + Alerts section.
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

	// -- today_flow() -------------------------------------------------------

	public function test_today_flow_sums_only_todays_live_transactions() {
		$user_id = self::factory()->user->create();
		$today   = current_time( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		$this->insert_transaction( $user_id, 'credit', 100, $today . ' 10:00:00' );
		$this->insert_transaction( $user_id, 'debit', 30, $today . ' 11:00:00' );
		// Outside today's range — must not be counted.
		$this->insert_transaction( $user_id, 'credit', 500, $yesterday . ' 10:00:00' );

		$flow = $this->data->today_flow();

		$this->assertSame( 100.0, $flow['inflow'] );
		$this->assertSame( 30.0, $flow['outflow'] );
	}

	public function test_today_flow_ignores_soft_deleted_rows() {
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

		$flow = $this->data->today_flow();

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

	// -- high_value_transactions_today() -------------------------------------

	public function test_high_value_watch_disabled_by_default() {
		$this->assertSame( 0.0, $this->data->high_value_threshold() );

		$user_id = self::factory()->user->create();
		$this->insert_transaction( $user_id, 'credit', 100000, current_time( 'Y-m-d' ) . ' 10:00:00' );

		$result = $this->data->high_value_transactions_today();
		$this->assertSame( 0, $result['count'], 'A zero/unset threshold must disable the watch, not match everything.' );
	}

	public function test_high_value_watch_counts_only_todays_qualifying_transactions() {
		$this->set_general_option( 'dashboard_high_value_threshold', 500 );
		$user_id = self::factory()->user->create();
		$today   = current_time( 'Y-m-d' );

		$this->insert_transaction( $user_id, 'credit', 500, $today . ' 10:00:00' ); // at threshold, counts.
		$this->insert_transaction( $user_id, 'credit', 499.99, $today . ' 10:00:00' ); // below, doesn't count.
		$this->insert_transaction( $user_id, 'debit', 600, $today . ' 11:00:00' ); // either type counts.

		$result = $this->data->high_value_transactions_today();

		$this->assertSame( 500.0, $result['threshold'] );
		$this->assertSame( 2, $result['count'] );
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

		foreach ( array( 'base_currency', 'total_liability', 'today_inflow', 'today_outflow', 'pending_withdrawals_count', 'pending_withdrawals_amount', 'high_value_count', 'high_value_threshold', 'is_negative_net_flow_alert', 'generated_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $snapshot );
		}
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
