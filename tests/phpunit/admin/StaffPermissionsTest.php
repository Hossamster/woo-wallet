<?php
/**
 * Woo_Wallet_Staff: who on the team may do what.
 *
 *  - Administrator: everything, including settings.
 *  - Shop manager: every operational action, balance adjustments without a
 *    limit, but not settings.
 *  - Support agent: read-only with masked bank details, notes, a pending
 *    withdrawal on a customer's behalf, and goodwill credit within the
 *    per-agent limits a manager set for them.
 */
class Staff_Permissions_Test extends WP_UnitTestCase {

	private $admin_id;
	private $manager_id;
	private $agent_id;
	private $customer_id;

	public function set_up() {
		parent::set_up();
		Woo_Wallet_Staff::ensure_role();

		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		$this->manager_id = self::factory()->user->create();
		get_userdata( $this->manager_id )->add_cap( 'manage_woocommerce' );
		$this->agent_id    = self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) );
		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		woo_wallet()->wallet->credit( $this->customer_id, 500, 'test funding' );
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	// -- the matrix -------------------------------------------------------

	public function test_support_agent_capabilities() {
		foreach ( Woo_Wallet_Staff::support_capabilities() as $cap ) {
			$this->assertTrue( user_can( $this->agent_id, $cap ), $cap );
		}
		foreach ( array(
			Woo_Wallet_Staff::CAP_ADJUST_BALANCE,
			Woo_Wallet_Staff::CAP_VIEW_BANK_DETAILS,
			Woo_Wallet_Staff::CAP_VIEW_RECEIPTS,
			Woo_Wallet_Staff::CAP_PROCESS_WITHDRAWALS,
			Woo_Wallet_Staff::CAP_EXPORT,
			Woo_Wallet_Staff::CAP_MANAGE_STAFF,
			Woo_Wallet_Staff::CAP_MANAGE_SETTINGS,
			'manage_woocommerce',
		) as $cap ) {
			$this->assertFalse( user_can( $this->agent_id, $cap ), $cap );
		}
	}

	public function test_shop_manager_has_everything_operational_but_not_settings() {
		foreach ( Woo_Wallet_Staff::manager_capabilities() as $cap ) {
			$this->assertTrue( user_can( $this->manager_id, $cap ), $cap );
		}
		$this->assertFalse( user_can( $this->manager_id, Woo_Wallet_Staff::CAP_MANAGE_SETTINGS ) );
	}

	public function test_administrator_also_manages_settings() {
		foreach ( Woo_Wallet_Staff::manager_capabilities() as $cap ) {
			$this->assertTrue( user_can( $this->admin_id, $cap ), $cap );
		}
		$this->assertTrue( user_can( $this->admin_id, Woo_Wallet_Staff::CAP_MANAGE_SETTINGS ) );
	}

	public function test_a_customer_has_no_staff_capabilities() {
		foreach ( array_merge( Woo_Wallet_Staff::manager_capabilities(), array( Woo_Wallet_Staff::CAP_GOODWILL_CREDIT, Woo_Wallet_Staff::CAP_MANAGE_SETTINGS ) ) as $cap ) {
			$this->assertFalse( user_can( $this->customer_id, $cap ), $cap );
		}
	}

	/**
	 * WooCommerce hides the admin bar from users without edit_posts or
	 * manage_woocommerce — a support agent must still get it, or they have
	 * no way into the wallet screens.
	 */
	public function test_support_agent_keeps_the_admin_bar_but_a_customer_does_not() {
		wp_set_current_user( $this->agent_id );
		$this->assertTrue( wc_disable_admin_bar( true ) );

		wp_set_current_user( $this->customer_id );
		$this->assertFalse( wc_disable_admin_bar( true ) );
	}

	// -- balance adjustments ------------------------------------------------

	public function test_shop_manager_can_credit_and_debit_without_a_limit() {
		wp_set_current_user( $this->manager_id );

		$this->assertTrue( Woo_Wallet_Staff::authorize_adjustment( 'credit', 1000000, $this->customer_id ) );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 250, 'manager credit' ) );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'debit', $this->customer_id, 50, 'manager debit' ) );
		$this->assertSame( 700.0, $this->balance() );
	}

	public function test_agent_cannot_credit_until_a_limit_is_set() {
		wp_set_current_user( $this->agent_id );

		$result = Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 5, 'goodwill' );

		$this->assertWPError( $result );
		$this->assertSame( 'woo_wallet_staff_no_limit', $result->get_error_code() );
		$this->assertSame( 500.0, $this->balance() );
	}

	public function test_agent_can_credit_within_the_limit_and_it_is_recorded_as_goodwill() {
		global $wpdb;
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );
		wp_set_current_user( $this->agent_id );

		$transaction_id = Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'sorry for the delay', array( 'category' => 'adjustment' ) );

		$this->assertIsInt( $transaction_id );
		$this->assertSame( 520.0, $this->balance() );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT category, created_by FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE transaction_id = %d", $transaction_id ) );
		$this->assertSame( Woo_Wallet_Staff::GOODWILL_CATEGORY, $row->category );
		$this->assertSame( $this->agent_id, (int) $row->created_by );
		$this->assertSame( 20.0, Woo_Wallet_Staff::credited_today( $this->agent_id ) );
	}

	public function test_agent_cannot_exceed_the_per_credit_limit() {
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );
		wp_set_current_user( $this->agent_id );

		$result = Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20.01, 'too much' );

		$this->assertSame( 'woo_wallet_staff_over_limit', $result->get_error_code() );
		$this->assertSame( 500.0, $this->balance() );
	}

	public function test_agent_cannot_exceed_the_daily_limit_across_several_credits() {
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );
		wp_set_current_user( $this->agent_id );

		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'one' ) );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'two' ) );
		$third = Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'three' );

		$this->assertSame( 'woo_wallet_staff_over_daily_limit', $third->get_error_code() );
		$this->assertSame( 540.0, $this->balance() );
		// Exactly what is left is still allowed.
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 10, 'the rest' ) );
		$this->assertSame( 550.0, $this->balance() );
	}

	public function test_one_agents_usage_does_not_count_against_another() {
		$other_agent = self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) );
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 20 );
		Woo_Wallet_Staff::set_limits( $other_agent, 20, 20 );

		wp_set_current_user( $this->agent_id );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'a' ) );

		wp_set_current_user( $other_agent );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'b' ) );
	}

	public function test_agent_can_never_debit() {
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );
		wp_set_current_user( $this->agent_id );

		$result = Woo_Wallet_Staff::adjust( 'debit', $this->customer_id, 5, 'nope' );

		$this->assertSame( 'woo_wallet_staff_forbidden', $result->get_error_code() );
		$this->assertSame( 500.0, $this->balance() );
	}

	public function test_agent_cannot_credit_their_own_wallet() {
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );
		wp_set_current_user( $this->agent_id );

		$result = Woo_Wallet_Staff::adjust( 'credit', $this->agent_id, 5, 'for me' );

		$this->assertSame( 'woo_wallet_staff_self_credit', $result->get_error_code() );
		$this->assertSame( 0.0, (float) woo_wallet()->wallet->get_wallet_balance( $this->agent_id, 'edit' ) );
	}

	public function test_a_customer_cannot_adjust_anything() {
		wp_set_current_user( $this->customer_id );
		$this->assertWPError( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 5, 'self service' ) );
		$this->assertSame( 500.0, $this->balance() );
	}

	// -- managing agents ---------------------------------------------------

	public function test_save_agent_adds_the_role_on_top_of_the_existing_one() {
		$user_id = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'agent@example.com' ) );

		$result = Woo_Wallet_Staff::save_agent( 'agent@example.com', 15, 60 );

		$this->assertInstanceOf( 'WP_User', $result );
		$roles = get_userdata( $user_id )->roles;
		$this->assertContains( 'customer', $roles );
		$this->assertContains( Woo_Wallet_Staff::ROLE, $roles );
		$this->assertSame( array( 'per_credit' => 15.0, 'daily' => 60.0 ), Woo_Wallet_Staff::get_limits( $user_id ) );
		$this->assertTrue( user_can( $user_id, Woo_Wallet_Staff::CAP_GOODWILL_CREDIT ) );
	}

	public function test_save_agent_rejects_a_per_credit_limit_above_the_daily_limit() {
		$result = Woo_Wallet_Staff::save_agent( $this->agent_id, 100, 50 );
		$this->assertSame( 'woo_wallet_staff_bad_limits', $result->get_error_code() );
	}

	public function test_save_agent_refuses_a_manager_and_an_unknown_user() {
		$this->assertSame( 'woo_wallet_staff_already_manager', Woo_Wallet_Staff::save_agent( $this->manager_id, 10, 10 )->get_error_code() );
		$this->assertSame( 'woo_wallet_staff_not_found', Woo_Wallet_Staff::save_agent( 'nobody@example.com', 10, 10 )->get_error_code() );
	}

	public function test_remove_agent_takes_the_access_and_limits_away() {
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );

		Woo_Wallet_Staff::remove_agent( $this->agent_id );

		$this->assertNotContains( Woo_Wallet_Staff::ROLE, get_userdata( $this->agent_id )->roles );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_VIEW ) );
		$this->assertSame( array( 'per_credit' => 0.0, 'daily' => 0.0 ), Woo_Wallet_Staff::get_limits( $this->agent_id ) );
	}

	// -- what an agent sees on the withdrawal screens ----------------------

	private function seed_withdrawal() {
		$result = Woo_Wallet_Withdrawal::admin_create(
			$this->customer_id,
			50,
			array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
			'Mohamed Ali',
			'1234567890',
			'01012345678',
			'EG380019000500000000263180002',
			$this->manager_id,
			'pending'
		);
		return (int) $result['id'];
	}

	private function render_detail( $id ) {
		$_GET['action'] = 'view';
		$_GET['id']     = $id;
		ob_start();
		( new Woo_Wallet_Withdrawal() )->render_admin_page();
		return ob_get_clean();
	}

	public function test_bank_details_are_masked_for_an_agent_and_full_for_a_manager() {
		wp_set_current_user( $this->agent_id );
		$this->assertSame( '••••••7890', Woo_Wallet_Staff::bank_detail( '1234567890' ) );

		wp_set_current_user( $this->manager_id );
		$this->assertSame( '1234567890', Woo_Wallet_Staff::bank_detail( '1234567890' ) );
	}

	public function test_agent_sees_a_read_only_detail_screen_with_masked_bank_details() {
		$id = $this->seed_withdrawal();

		wp_set_current_user( $this->agent_id );
		$html = $this->render_detail( $id );

		$this->assertStringNotContainsString( '1234567890', $html );
		$this->assertStringContainsString( '7890', $html );
		$this->assertStringNotContainsString( 'EG380019000500000000263180002', $html );
		$this->assertStringNotContainsString( 'Process this request', $html );
		$this->assertStringNotContainsString( 'woo_wallet_withdrawal_process', $html );
		// Notes stay available.
		$this->assertStringContainsString( 'woo_wallet_withdrawal_add_note', $html );
	}

	public function test_manager_sees_full_details_and_the_process_form() {
		$id = $this->seed_withdrawal();

		wp_set_current_user( $this->manager_id );
		$html = $this->render_detail( $id );

		$this->assertStringContainsString( '1234567890', $html );
		$this->assertStringContainsString( 'Process this request', $html );
	}

	public function test_a_customer_cannot_open_the_withdrawals_screen() {
		wp_set_current_user( $this->customer_id );
		$this->expectException( 'WPDieException' );
		( new Woo_Wallet_Withdrawal() )->render_admin_page();
	}

	public function test_agent_cannot_read_another_customers_receipt() {
		$id = $this->seed_withdrawal();
		wp_set_current_user( $this->agent_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/me/withdrawals/' . $id . '/receipt' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_withdrawal_not_found', $response->as_error()->get_error_code() );
		$GLOBALS['wp_rest_server'] = null;
	}

	public function test_agent_is_only_offered_the_credit_bulk_action() {
		require_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-balance-details.php';
		$GLOBALS['hook_suffix'] = 'woo-wallet-users';
		$method                 = new ReflectionMethod( 'Woo_Wallet_Balance_Details', 'get_bulk_actions' );

		wp_set_current_user( $this->agent_id );
		$this->assertSame( array( 'credit' ), array_keys( $method->invoke( new Woo_Wallet_Balance_Details() ) ) );

		wp_set_current_user( $this->manager_id );
		$this->assertSame( array( 'credit', 'debit', 'delete_log' ), array_keys( $method->invoke( new Woo_Wallet_Balance_Details() ) ) );
	}
}
