<?php
/**
 * Woo_Wallet_Withdrawal_Report::get_filter_args() and resolve_customers() —
 * the pure $_GET-to-query-args translation layer shared by prepare_items()
 * and extra_tablenav() so the admin Withdrawals list and its filter form can
 * never disagree about what's currently filtered. These are plain static
 * methods reading superglobals, so tests just set $_GET and inspect the
 * returned args array — no WP_List_Table/admin-screen setup needed.
 */
class Withdrawal_Filter_Args_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// The plugin only loads this class lazily from inside actual admin
		// screen rendering (render_admin_list()/maybe_handle_admin_csv_export()
		// in class-woo-wallet-withdrawal.php) — never on the front end or via
		// autoload, so a test that never renders that screen must load it
		// itself before calling any of its static methods.
		require_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-withdrawal-report.php';
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function valid_bank() {
		return array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() );
	}

	// -- get_filter_args(): defaults & status -------------------------------

	public function test_no_get_params_returns_empty_args() {
		$_GET = array();
		$this->assertSame( array(), Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	public function test_status_only_accepts_known_values() {
		$_GET = array( 'withdrawal_status' => 'pending' );
		$this->assertSame( array( 'status' => 'pending' ), Woo_Wallet_Withdrawal_Report::get_filter_args() );

		$_GET = array( 'withdrawal_status' => 'not-a-real-status' );
		$this->assertArrayNotHasKey( 'status', Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	// -- search / legacy customer param --------------------------------

	public function test_search_param_maps_to_args_search() {
		$_GET = array( 'withdrawal_search' => 'mohamed' );
		$this->assertSame( array( 'search' => 'mohamed' ), Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	public function test_legacy_customer_param_used_when_search_is_absent() {
		$_GET = array( 'withdrawal_customer' => 'mohamed@example.com' );
		$this->assertSame( array( 'search' => 'mohamed@example.com' ), Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	public function test_search_takes_precedence_over_legacy_customer_param() {
		$_GET = array(
			'withdrawal_search'   => 'new-value',
			'withdrawal_customer' => 'old-value',
		);
		$this->assertSame( array( 'search' => 'new-value' ), Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	// -- bank ------------------------------------------------------------

	public function test_bank_only_accepts_a_configured_bank_name() {
		$_GET = array( 'withdrawal_bank' => $this->valid_bank() );
		$this->assertSame( array( 'bank_name' => $this->valid_bank() ), Woo_Wallet_Withdrawal_Report::get_filter_args() );

		$_GET = array( 'withdrawal_bank' => 'Not A Real Bank' );
		$this->assertArrayNotHasKey( 'bank_name', Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	// -- receipt / requested_by enums -------------------------------------

	public function test_receipt_only_accepts_has_or_missing() {
		$_GET = array( 'withdrawal_receipt' => 'has' );
		$this->assertSame( array( 'receipt' => 'has' ), Woo_Wallet_Withdrawal_Report::get_filter_args() );

		$_GET = array( 'withdrawal_receipt' => 'missing' );
		$this->assertSame( array( 'receipt' => 'missing' ), Woo_Wallet_Withdrawal_Report::get_filter_args() );

		$_GET = array( 'withdrawal_receipt' => 'bogus' );
		$this->assertArrayNotHasKey( 'receipt', Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	public function test_requested_by_only_accepts_self_or_staff() {
		$_GET = array( 'withdrawal_requested_by' => 'self' );
		$this->assertSame( array( 'created_by' => 'self' ), Woo_Wallet_Withdrawal_Report::get_filter_args() );

		$_GET = array( 'withdrawal_requested_by' => 'anything-else' );
		$this->assertArrayNotHasKey( 'created_by', Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	// -- processed_by ------------------------------------------------------

	public function test_processed_by_requires_a_positive_id() {
		$_GET = array( 'withdrawal_processed_by' => '7' );
		$this->assertSame( array( 'processed_by' => 7 ), Woo_Wallet_Withdrawal_Report::get_filter_args() );

		$_GET = array( 'withdrawal_processed_by' => '0' );
		$this->assertArrayNotHasKey( 'processed_by', Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	// -- min/max amount ---------------------------------------------------

	public function test_min_and_max_amount_are_parsed_as_floats() {
		$_GET = array(
			'withdrawal_min_amount' => '50.5',
			'withdrawal_max_amount' => '200',
		);
		$args = Woo_Wallet_Withdrawal_Report::get_filter_args();
		$this->assertSame( 50.5, $args['min_amount'] );
		$this->assertSame( 200.0, $args['max_amount'] );
	}

	public function test_blank_amount_strings_are_ignored_not_treated_as_zero() {
		$_GET = array(
			'withdrawal_min_amount' => '',
			'withdrawal_max_amount' => '   ',
		);
		$args = Woo_Wallet_Withdrawal_Report::get_filter_args();
		$this->assertArrayNotHasKey( 'min_amount', $args );
		$this->assertArrayNotHasKey( 'max_amount', $args );
	}

	public function test_zero_amount_is_a_valid_explicit_filter() {
		$_GET = array( 'withdrawal_min_amount' => '0' );
		$this->assertSame( array( 'min_amount' => 0.0 ), Woo_Wallet_Withdrawal_Report::get_filter_args() );
	}

	public function test_negative_amounts_are_rejected() {
		$_GET = array(
			'withdrawal_min_amount' => '-10',
			'withdrawal_max_amount' => '-5',
		);
		$args = Woo_Wallet_Withdrawal_Report::get_filter_args();
		$this->assertArrayNotHasKey( 'min_amount', $args );
		$this->assertArrayNotHasKey( 'max_amount', $args );
	}

	// -- date range --------------------------------------------------------

	public function test_date_filters_require_ymd_format_and_get_time_bounds_appended() {
		$_GET = array(
			'withdrawal_after'  => '2026-01-01',
			'withdrawal_before' => '2026-01-31',
		);
		$args = Woo_Wallet_Withdrawal_Report::get_filter_args();
		$this->assertSame( '2026-01-01 00:00:00', $args['after'] );
		$this->assertSame( '2026-01-31 23:59:59', $args['before'] );
	}

	public function test_malformed_dates_are_ignored() {
		$_GET = array(
			'withdrawal_after'  => 'not-a-date',
			'withdrawal_before' => '01/31/2026',
		);
		$args = Woo_Wallet_Withdrawal_Report::get_filter_args();
		$this->assertArrayNotHasKey( 'after', $args );
		$this->assertArrayNotHasKey( 'before', $args );
	}

	// -- resolve_customers() ------------------------------------------------

	public function test_resolve_customers_by_numeric_id() {
		$user_id = self::factory()->user->create();
		$this->assertSame( array( $user_id ), Woo_Wallet_Withdrawal_Report::resolve_customers( (string) $user_id ) );
	}

	public function test_resolve_customers_by_unknown_id_returns_empty() {
		$this->assertSame( array(), Woo_Wallet_Withdrawal_Report::resolve_customers( '999999' ) );
	}

	public function test_resolve_customers_by_exact_login() {
		$user_id = self::factory()->user->create( array( 'user_login' => 'mohamedali' ) );
		$this->assertSame( array( $user_id ), Woo_Wallet_Withdrawal_Report::resolve_customers( 'mohamedali' ) );
	}

	public function test_resolve_customers_by_exact_email() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'mohamed@example.com' ) );
		$this->assertSame( array( $user_id ), Woo_Wallet_Withdrawal_Report::resolve_customers( 'mohamed@example.com' ) );
	}

	public function test_resolve_customers_by_display_name_returns_every_match() {
		$id1 = self::factory()->user->create( array( 'display_name' => 'Mohamed Ali Search Target' ) );
		$id2 = self::factory()->user->create( array( 'display_name' => 'Mohamed Ali Search Target Two' ) );
		self::factory()->user->create( array( 'display_name' => 'Someone Unrelated' ) );

		$result = Woo_Wallet_Withdrawal_Report::resolve_customers( 'Search Target' );
		sort( $result );
		$expected = array( $id1, $id2 );
		sort( $expected );
		$this->assertSame( $expected, $result );
	}

	public function test_resolve_customers_no_match_returns_empty_array() {
		$this->assertSame( array(), Woo_Wallet_Withdrawal_Report::resolve_customers( 'no-such-customer-exists' ) );
	}
}
