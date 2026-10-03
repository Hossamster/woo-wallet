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
			Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS,
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

	/**
	 * Logging a withdrawal reserves the amount from the customer's wallet
	 * immediately and has no limit, so an agent could freeze any customer's
	 * balance with it. Sites whose role was created while it still had the
	 * capability must lose it too, not only fresh installs.
	 */
	public function test_an_existing_agent_role_loses_the_create_withdrawals_capability() {
		get_role( Woo_Wallet_Staff::ROLE )->add_cap( Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS );
		$this->assertTrue( get_role( Woo_Wallet_Staff::ROLE )->has_cap( Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS ), 'Precondition: a role created by v1.9.0-1.9.2.' );

		Woo_Wallet_Staff::ensure_role();

		$this->assertFalse( get_role( Woo_Wallet_Staff::ROLE )->has_cap( Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS ) );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) ), Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS ) );
		$this->assertTrue( get_role( Woo_Wallet_Staff::ROLE )->has_cap( Woo_Wallet_Staff::CAP_VIEW ), 'The capabilities every agent keeps stay on the role.' );
		$this->assertTrue( get_role( Woo_Wallet_Staff::ROLE )->has_cap( Woo_Wallet_Staff::CAP_REQUEST_APPROVAL ), 'An existing role gains the request capability.' );
	}

	public function test_agent_cannot_create_a_withdrawal_or_reserve_a_customers_balance() {
		wp_set_current_user( $this->agent_id );
		$_POST = array(
			'_wpnonce'         => wp_create_nonce( 'woo_wallet_withdrawal_create' ),
			'user_id'          => $this->customer_id,
			'amount'           => 100,
			'bank_name'        => array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
		);
		$_REQUEST = $_POST;
		try {
			( new Woo_Wallet_Withdrawal() )->handle_admin_create_request();
			$this->fail( 'An agent must be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		$this->assertSame( 500.0, $this->balance() );
		$this->assertSame( 0, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) ) );
	}

	public function test_agent_is_not_offered_the_create_withdrawal_button() {
		wp_set_current_user( $this->agent_id );
		ob_start();
		( new Woo_Wallet_Withdrawal() )->render_admin_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'Create Withdrawal', $html );
		$this->assertStringContainsString( 'Request a withdrawal for a customer', $html );
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

	// -- double submission ---------------------------------------------------

	public function test_a_form_token_can_only_be_claimed_once() {
		wp_set_current_user( $this->manager_id );
		$token = wp_generate_uuid4();

		$this->assertTrue( Woo_Wallet_Staff::claim_form_token( $token ) );
		$this->assertFalse( Woo_Wallet_Staff::claim_form_token( $token ) );
		$this->assertFalse( Woo_Wallet_Staff::claim_form_token( $token ) );
		$this->assertFalse( Woo_Wallet_Staff::claim_form_token( '' ) );
		$this->assertFalse( Woo_Wallet_Staff::claim_form_token( 'not-a-token' ) );
	}

	/**
	 * The reported bug: one "Update balance" click that reached the server
	 * three times credited the wallet three times.
	 */
	public function test_the_same_edit_balance_form_submitted_three_times_credits_once() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		if ( ! class_exists( 'Woo_Wallet_Admin' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-admin.php';
		}
		wp_set_current_user( $this->manager_id );

		$_POST = array(
			'woo-wallet-admin-adjust-balance' => wp_create_nonce( 'woo-wallet-admin-adjust-balance' ),
			Woo_Wallet_Staff::FORM_TOKEN_FIELD => wp_generate_uuid4(),
			'user_id'                         => $this->customer_id,
			'balance_amount'                  => '200',
			'payment_type'                    => 'credit',
			'payment_description'             => '',
		);
		try {
			Woo_Wallet_Admin::instance()->handle_wallet_balance_adjustment();
			Woo_Wallet_Admin::instance()->handle_wallet_balance_adjustment();
			Woo_Wallet_Admin::instance()->handle_wallet_balance_adjustment();
			$this->assertSame( 700.0, $this->balance() );

			// A fresh form (new token) is a genuinely new adjustment.
			$_POST[ Woo_Wallet_Staff::FORM_TOKEN_FIELD ] = wp_generate_uuid4();
			Woo_Wallet_Admin::instance()->handle_wallet_balance_adjustment();
			$this->assertSame( 900.0, $this->balance() );

			// And a form with no token at all moves nothing.
			unset( $_POST[ Woo_Wallet_Staff::FORM_TOKEN_FIELD ] );
			Woo_Wallet_Admin::instance()->handle_wallet_balance_adjustment();
			$this->assertSame( 900.0, $this->balance() );
		} finally {
			$_POST = array();
		}
	}

	/**
	 * WooCommerce only lets a shop manager `edit_user` customer accounts, so
	 * gating the edit-balance dialog on that capability made it silently do
	 * nothing for every other user. The wallet permission is what applies.
	 */
	public function test_a_real_shop_manager_can_adjust_wallets_of_users_they_cannot_edit() {
		$shop_manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$subscriber   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $shop_manager );

		$this->assertFalse( current_user_can( 'edit_user', $subscriber ), 'Precondition: WooCommerce does not let a shop manager edit this account.' );
		$this->assertTrue( Woo_Wallet_Staff::can_adjust() );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $subscriber, 75, 'credit to a non-customer' ) );
		$this->assertSame( 75.0, (float) woo_wallet()->wallet->get_wallet_balance( $subscriber, 'edit' ) );
		$this->assertFalse( user_can( $shop_manager, Woo_Wallet_Staff::CAP_MANAGE_SETTINGS ) );
	}

	// -- managing agents ---------------------------------------------------

	public function test_save_agent_adds_the_role_on_top_of_the_existing_one() {
		$user_id = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'agent@example.com' ) );

		$result = Woo_Wallet_Staff::save_agent( 'agent@example.com', 'level_2', array( 'per_credit' => 15, 'daily' => 60 ) );

		$this->assertInstanceOf( 'WP_User', $result );
		$roles = get_userdata( $user_id )->roles;
		$this->assertContains( 'customer', $roles );
		$this->assertContains( Woo_Wallet_Staff::ROLE, $roles );
		$this->assertSame( array( 'per_credit' => 15.0, 'daily' => 60.0 ), Woo_Wallet_Staff::get_limits( $user_id ) );
		$this->assertTrue( user_can( $user_id, Woo_Wallet_Staff::CAP_GOODWILL_CREDIT ) );
	}

	public function test_save_agent_rejects_a_per_credit_limit_above_the_daily_limit() {
		$result = Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_2', array( 'per_credit' => 100, 'daily' => 50 ) );
		$this->assertSame( 'woo_wallet_staff_bad_limits', $result->get_error_code() );
	}

	public function test_save_agent_refuses_a_manager_and_an_unknown_user() {
		$this->assertSame( 'woo_wallet_staff_already_manager', Woo_Wallet_Staff::save_agent( $this->manager_id, 'level_2' )->get_error_code() );
		$this->assertSame( 'woo_wallet_staff_not_found', Woo_Wallet_Staff::save_agent( 'nobody@example.com', 'level_2' )->get_error_code() );
		$this->assertSame( 'woo_wallet_staff_level', Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_9' )->get_error_code() );
	}

	public function test_remove_agent_takes_the_access_and_limits_away() {
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );

		Woo_Wallet_Staff::remove_agent( $this->agent_id );

		$this->assertNotContains( Woo_Wallet_Staff::ROLE, get_userdata( $this->agent_id )->roles );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_VIEW ) );
		$this->assertSame( array( 'per_credit' => 0.0, 'daily' => 0.0 ), Woo_Wallet_Staff::get_limits( $this->agent_id ) );
	}

	// -- levels -------------------------------------------------------------

	public function test_an_agent_with_no_level_recorded_gets_the_default_level() {
		$this->assertSame( Woo_Wallet_Staff::DEFAULT_LEVEL, Woo_Wallet_Staff::get_agent_level( $this->agent_id ) );
		$this->assertTrue( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_GOODWILL_CREDIT ) );
		$this->assertTrue( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_ADD_NOTES ) );
	}

	public function test_the_level_decides_what_an_agent_can_do() {
		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_1' );
		$this->assertTrue( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_ADD_NOTES ) );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_GOODWILL_CREDIT ) );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_VIEW_BANK_DETAILS ) );

		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_3' );
		$this->assertTrue( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_GOODWILL_CREDIT ) );
		$this->assertTrue( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_VIEW_BANK_DETAILS ) );
		$this->assertTrue( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_VIEW_RECEIPTS ) );
		// Never, at any level.
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_ADJUST_BALANCE ) );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS ) );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) );
	}

	public function test_an_administrator_can_change_what_a_level_allows() {
		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_3' );
		$this->assertTrue( Woo_Wallet_Staff::save_level( 'level_3', 'Senior', array( Woo_Wallet_Staff::CAP_ADD_NOTES ), 0, 0 ) );

		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_VIEW_BANK_DETAILS ) );
		$this->assertSame( 'Senior', Woo_Wallet_Staff::get_levels()['level_3']['name'] );
	}

	public function test_a_level_cannot_switch_on_anything_that_takes_money_from_a_customer() {
		Woo_Wallet_Staff::save_level( 'level_3', 'Senior', array( Woo_Wallet_Staff::CAP_ADJUST_BALANCE, Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS, Woo_Wallet_Staff::CAP_ADD_NOTES ), 0, 0 );
		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_3' );

		$this->assertSame( array( Woo_Wallet_Staff::CAP_ADD_NOTES ), Woo_Wallet_Staff::get_levels()['level_3']['caps'] );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_ADJUST_BALANCE ) );
		$this->assertFalse( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS ) );
	}

	public function test_the_agent_uses_the_level_limits_unless_given_personal_ones() {
		Woo_Wallet_Staff::save_level( 'level_2', 'Goodwill', array( Woo_Wallet_Staff::CAP_GOODWILL_CREDIT ), 10, 30 );
		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_2' );
		$this->assertSame( array( 'per_credit' => 10.0, 'daily' => 30.0 ), Woo_Wallet_Staff::get_limits( $this->agent_id ) );

		wp_set_current_user( $this->agent_id );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 10, 'level limit' ) );
		$this->assertSame( 'woo_wallet_staff_over_limit', Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 11, 'over' )->get_error_code() );

		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_2', array( 'per_credit' => 25, 'daily' => 100 ) );
		$this->assertSame( array( 'per_credit' => 25.0, 'daily' => 100.0 ), Woo_Wallet_Staff::get_limits( $this->agent_id ) );
		$this->assertIsInt( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 25, 'personal limit' ) );

		// Saving without personal limits goes back to the level's.
		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_2' );
		$this->assertSame( array( 'per_credit' => 10.0, 'daily' => 30.0 ), Woo_Wallet_Staff::get_limits( $this->agent_id ) );
	}

	public function test_agents_added_before_levels_keep_their_limits() {
		// Exactly what v1.9.x stored: role + two limit metas, no level.
		Woo_Wallet_Staff::set_limits( $this->agent_id, 20, 50 );

		$this->assertSame( Woo_Wallet_Staff::DEFAULT_LEVEL, Woo_Wallet_Staff::get_agent_level( $this->agent_id ) );
		$this->assertSame( array( 'per_credit' => 20.0, 'daily' => 50.0 ), Woo_Wallet_Staff::get_limits( $this->agent_id ) );
		$this->assertTrue( user_can( $this->agent_id, Woo_Wallet_Staff::CAP_GOODWILL_CREDIT ) );
	}

	public function test_a_level_rejects_a_per_credit_limit_above_its_daily_limit() {
		$this->assertSame( 'woo_wallet_staff_bad_limits', Woo_Wallet_Staff::save_level( 'level_2', 'x', array(), 50, 10 )->get_error_code() );
		$this->assertSame( 'woo_wallet_staff_level', Woo_Wallet_Staff::save_level( 'level_9', 'x', array(), 0, 0 )->get_error_code() );
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

		// Deleting a customer's log would erase the record of a manual credit:
		// administrators only.
		wp_set_current_user( $this->manager_id );
		$this->assertSame( array( 'credit', 'debit' ), array_keys( $method->invoke( new Woo_Wallet_Balance_Details() ) ) );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( array( 'credit', 'debit', 'delete_log' ), array_keys( $method->invoke( new Woo_Wallet_Balance_Details() ) ) );
	}
}
