<?php
/**
 * Integration coverage for the admin Withdrawals list's actual filtering:
 * Woo_Wallet_Withdrawal::get_requests()/count_requests() (backed by
 * build_where()) against real seeded rows, plus count_requests_by_status()
 * and get_processing_staff() (used by the status tabs and the
 * "processed by" filter dropdown).
 */
class Withdrawal_List_Filters_Test extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		// build_where()'s customer-name search delegates to
		// Woo_Wallet_Withdrawal_Report::resolve_customers(), which the plugin
		// only loads lazily from actual admin screen rendering — see the same
		// note in WithdrawalFilterArgsTest.
		require_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-withdrawal-report.php';

		$this->user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->user_id, 100000, 'test funding' );
	}

	/**
	 * Insert a withdrawal row directly with full control over every
	 * filterable column, bypassing submit_request()/admin_create() validation
	 * since this suite is about the query layer, not request validation.
	 */
	private function seed( array $overrides = array() ) {
		$banks   = Woo_Wallet_Withdrawal::get_configured_banks();
		$user_id = isset( $overrides['user_id'] ) ? $overrides['user_id'] : $this->user_id;

		$transaction_id = woo_wallet()->wallet->debit( $user_id, 10, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );

		$row = array_merge(
			array(
				'user_id'          => $user_id,
				'created_by'       => $user_id,
				'transaction_id'   => $transaction_id,
				'amount'           => 100,
				'charge'           => 0,
				'currency'         => woo_wallet()->wallet->resolve_active_currency(),
				'bank_name'        => array_key_first( $banks ),
				'beneficiary_name' => 'Mohamed Ali',
				'account_number'   => '1234567890',
				'phone'            => '01012345678',
				'iban'             => '',
				'reference_no'     => '',
				'status'           => 'pending',
			),
			$overrides
		);
		unset( $row['date_created'] );
		$id = Woo_Wallet_Withdrawal::insert_request( $row );

		if ( isset( $overrides['date_created'] ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'woo_wallet_withdrawals', array( 'date_created' => $overrides['date_created'] ), array( 'id' => $id ) );
		}

		return $id;
	}

	private function ids( array $rows ) {
		return array_map(
			function ( $r ) {
				return (int) $r->id;
			},
			$rows
		);
	}

	// -- universal search ---------------------------------------------

	public function test_search_matches_by_phone() {
		$match = $this->seed( array( 'phone' => '01099998888' ) );
		$this->seed( array( 'phone' => '01011112222' ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'search' => '9999' ) );
		$this->assertSame( array( $match ), $this->ids( $rows ) );
	}

	public function test_search_matches_by_account_number() {
		$match = $this->seed( array( 'account_number' => 'ACCT-UNIQUE-777' ) );
		$this->seed( array( 'account_number' => 'ACCT-OTHER-111' ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'search' => 'UNIQUE-777' ) );
		$this->assertSame( array( $match ), $this->ids( $rows ) );
	}

	public function test_search_matches_by_iban() {
		$match = $this->seed( array( 'iban' => 'EG380019000500000000263180002' ) );
		$this->seed( array( 'iban' => '' ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'search' => '26318' ) );
		$this->assertSame( array( $match ), $this->ids( $rows ) );
	}

	public function test_search_matches_by_reference_no() {
		$match = $this->seed( array( 'reference_no' => 'TRX-UNIQUE-42' ) );
		$this->seed( array( 'reference_no' => 'TRX-OTHER-1' ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'search' => 'UNIQUE-42' ) );
		$this->assertSame( array( $match ), $this->ids( $rows ) );
	}

	public function test_search_matches_by_beneficiary_name() {
		$match = $this->seed( array( 'beneficiary_name' => 'Zzyzx Unique Beneficiary' ) );
		$this->seed( array( 'beneficiary_name' => 'Someone Else' ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'search' => 'Zzyzx' ) );
		$this->assertSame( array( $match ), $this->ids( $rows ) );
	}

	public function test_search_matches_by_customer_name_via_resolve_customers() {
		$named_user = self::factory()->user->create( array( 'display_name' => 'Findable Customer Xyz' ) );
		woo_wallet()->wallet->credit( $named_user, 1000, 'test funding' );
		$match = $this->seed( array( 'user_id' => $named_user ) );
		$this->seed(); // belongs to $this->user_id, unrelated display name.

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'search' => 'Findable Customer' ) );
		$this->assertSame( array( $match ), $this->ids( $rows ) );
	}

	public function test_search_with_no_matches_returns_empty_not_everything() {
		$this->seed();
		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'search' => 'zzz-nothing-matches-this-zzz' ) );
		$this->assertSame( array(), $rows );
	}

	// -- receipt filter -----------------------------------------------

	public function test_receipt_has_filter() {
		$attachment = self::factory()->attachment->create_object( array( 'post_mime_type' => 'application/pdf' ) );
		$with       = $this->seed( array( 'receipt_id' => $attachment ) );
		$without    = $this->seed();

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'receipt' => 'has' ) );
		$this->assertSame( array( $with ), $this->ids( $rows ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'receipt' => 'missing' ) );
		$this->assertSame( array( $without ), $this->ids( $rows ) );
	}

	// -- amount range -------------------------------------------------

	public function test_min_and_max_amount_range() {
		$low  = $this->seed( array( 'amount' => 10 ) );
		$mid  = $this->seed( array( 'amount' => 50 ) );
		$high = $this->seed( array( 'amount' => 500 ) );

		$rows = Woo_Wallet_Withdrawal::get_requests(
			array(
				'min_amount' => 20,
				'max_amount' => 100,
			)
		);
		$this->assertSame( array( $mid ), $this->ids( $rows ) );
	}

	// -- date range -----------------------------------------------------

	public function test_date_range_filter() {
		$old = $this->seed( array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) ) ) );
		$mid = $this->seed( array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( 15 * DAY_IN_SECONDS ) ) ) );
		$new = $this->seed( array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() ) ) );

		$rows = Woo_Wallet_Withdrawal::get_requests(
			array(
				'after'  => gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ),
				'before' => gmdate( 'Y-m-d H:i:s', time() - ( 1 * DAY_IN_SECONDS ) ),
			)
		);
		$this->assertSame( array( $mid ), $this->ids( $rows ) );
	}

	// -- requested_by: self vs staff -------------------------------------

	public function test_requested_by_self_vs_staff() {
		$staff_id = self::factory()->user->create();
		$self     = $this->seed( array( 'created_by' => $this->user_id ) ); // created_by === user_id.
		$staff    = $this->seed( array( 'created_by' => $staff_id ) ); // created_by !== user_id.

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'created_by' => 'self' ) );
		$this->assertSame( array( $self ), $this->ids( $rows ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'created_by' => 'staff' ) );
		$this->assertSame( array( $staff ), $this->ids( $rows ) );
	}

	// -- processed_by -------------------------------------------------

	public function test_processed_by_filter() {
		$admin_a = self::factory()->user->create();
		$admin_b = self::factory()->user->create();
		$by_a    = $this->seed( array( 'processed_by' => $admin_a, 'status' => 'paid' ) );
		$this->seed( array( 'processed_by' => $admin_b, 'status' => 'paid' ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'processed_by' => $admin_a ) );
		$this->assertSame( array( $by_a ), $this->ids( $rows ) );
	}

	// -- bank_name --------------------------------------------------------

	public function test_bank_name_filter() {
		$banks = array_keys( Woo_Wallet_Withdrawal::get_configured_banks() );
		$this->assertGreaterThanOrEqual( 2, count( $banks ), 'Precondition: need at least two configured banks to test bank filtering meaningfully.' );

		$match = $this->seed( array( 'bank_name' => $banks[0] ) );
		$this->seed( array( 'bank_name' => $banks[1] ) );

		$rows = Woo_Wallet_Withdrawal::get_requests( array( 'bank_name' => $banks[0] ) );
		$this->assertSame( array( $match ), $this->ids( $rows ) );
	}

	// -- combined filters (AND, not OR) -----------------------------------

	public function test_filters_combine_with_and_not_or() {
		$this->seed( array( 'status' => 'pending', 'amount' => 500 ) ); // status matches, amount doesn't.
		$this->seed( array( 'status' => 'paid', 'amount' => 50 ) ); // amount matches, status doesn't.
		$both = $this->seed( array( 'status' => 'pending', 'amount' => 50 ) );

		$rows = Woo_Wallet_Withdrawal::get_requests(
			array(
				'status'     => 'pending',
				'max_amount' => 100,
			)
		);
		$this->assertSame( array( $both ), $this->ids( $rows ) );
	}

	// -- count_requests_by_status() ----------------------------------------

	public function test_count_requests_by_status_groups_correctly() {
		$this->seed( array( 'status' => 'pending' ) );
		$this->seed( array( 'status' => 'pending' ) );
		$this->seed( array( 'status' => 'paid' ) );
		$this->seed( array( 'status' => 'rejected' ) );

		$counts = Woo_Wallet_Withdrawal::count_requests_by_status();

		$this->assertSame( 2, $counts['pending'] );
		$this->assertSame( 1, $counts['paid'] );
		$this->assertSame( 1, $counts['rejected'] );
		$this->assertSame( 0, $counts['processing'] );
		$this->assertSame( 4, $counts['all'] );
	}

	// -- get_processing_staff() ---------------------------------------

	public function test_get_processing_staff_returns_distinct_staff_with_display_names() {
		$staff = self::factory()->user->create( array( 'display_name' => 'Staff Member One' ) );
		$this->seed( array( 'processed_by' => $staff, 'status' => 'paid' ) );
		$this->seed( array( 'processed_by' => $staff, 'status' => 'paid' ) ); // same staff again — must not duplicate.
		$this->seed(); // processed_by = 0 (unprocessed) — must be excluded.

		$result = Woo_Wallet_Withdrawal::get_processing_staff();

		$this->assertSame( array( $staff => 'Staff Member One' ), $result );
	}

	// -- get_requests(): orderby/order --------------------------------------

	public function test_get_requests_defaults_to_id_descending() {
		$a = $this->seed();
		$b = $this->seed();
		$c = $this->seed();

		$this->assertSame( array( $c, $b, $a ), $this->ids( Woo_Wallet_Withdrawal::get_requests() ) );
	}

	public function test_get_requests_orders_by_amount() {
		$low  = $this->seed( array( 'amount' => 10 ) );
		$high = $this->seed( array( 'amount' => 500 ) );
		$mid  = $this->seed( array( 'amount' => 100 ) );

		$asc = $this->ids( Woo_Wallet_Withdrawal::get_requests( array( 'orderby' => 'amount', 'order' => 'ASC' ) ) );
		$this->assertSame( array( $low, $mid, $high ), $asc );

		$desc = $this->ids( Woo_Wallet_Withdrawal::get_requests( array( 'orderby' => 'amount', 'order' => 'DESC' ) ) );
		$this->assertSame( array( $high, $mid, $low ), $desc );
	}

	public function test_get_requests_orders_by_date_created() {
		$oldest = $this->seed( array( 'date_created' => '2026-01-01 00:00:00' ) );
		$newest = $this->seed( array( 'date_created' => '2026-03-01 00:00:00' ) );
		$middle = $this->seed( array( 'date_created' => '2026-02-01 00:00:00' ) );

		$asc = $this->ids( Woo_Wallet_Withdrawal::get_requests( array( 'orderby' => 'date_created', 'order' => 'ASC' ) ) );
		$this->assertSame( array( $oldest, $middle, $newest ), $asc );
	}

	/**
	 * The whole point of whitelisting orderby against real column names in
	 * build_order_by(): a value that isn't one of them must never reach the
	 * SQL string, not even to silently degrade — it must fall back to the
	 * safe default (id DESC) exactly as if no orderby had been given.
	 */
	public function test_get_requests_rejects_an_unknown_orderby_column() {
		$a = $this->seed();
		$b = $this->seed();

		$injected = $this->ids(
			Woo_Wallet_Withdrawal::get_requests(
				array( 'orderby' => 'id; DROP TABLE wp_users; --' )
			)
		);
		$this->assertSame( array( $b, $a ), $injected, 'An unrecognised orderby must fall back to the default id DESC, not error or apply attacker input.' );
	}

	public function test_get_requests_order_defaults_to_desc_for_an_unrecognised_value() {
		$a = $this->seed();
		$b = $this->seed();

		$result = $this->ids( Woo_Wallet_Withdrawal::get_requests( array( 'orderby' => 'id', 'order' => 'sideways' ) ) );
		$this->assertSame( array( $b, $a ), $result );
	}

	// -- sum_requests_amount() -----------------------------------------------

	public function test_sum_requests_amount_totals_the_filtered_set() {
		$this->seed( array( 'amount' => 100 ) );
		$this->seed( array( 'amount' => 250 ) );
		$this->seed( array( 'amount' => 50, 'status' => 'paid' ) );

		$this->assertSame( 400.0, Woo_Wallet_Withdrawal::sum_requests_amount() );
		$this->assertSame( 350.0, Woo_Wallet_Withdrawal::sum_requests_amount( array( 'status' => 'pending' ) ) );
	}

	public function test_sum_requests_amount_is_zero_with_no_matching_rows() {
		$this->assertSame( 0.0, Woo_Wallet_Withdrawal::sum_requests_amount( array( 'status' => 'paid' ) ) );
	}
}
