<?php
/**
 * Staff oversight: nobody adjusts their own wallet, transaction history and
 * cashback rules are administrator-only, every sensitive action is logged,
 * the riskiest are alerted at once, and a digest goes out every day.
 */
class Staff_Oversight_Test extends WP_UnitTestCase {

	private $admin_id;
	private $other_admin_id;
	private $manager_id;
	private $customer_id;

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'owner@example.com' ) );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		$this->other_admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'partner@example.com' ) );
		get_userdata( $this->other_admin_id )->add_cap( 'manage_woocommerce' );
		$this->manager_id  = self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'manager@example.com' ) );
		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'customer@example.com' ) );
		// Old enough that the "new account" rule doesn't fire unless a test wants it to.
		wp_update_user( array( 'ID' => $this->customer_id, 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
		wp_update_user( array( 'ID' => $this->manager_id, 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
		woo_wallet()->wallet->credit( $this->customer_id, 500, 'test funding' );
	}

	public function tear_down() {
		reset_phpmailer_instance();
		$GLOBALS['wp_rest_server'] = null;
		$_GET                      = array();
		parent::tear_down();
	}

	private function balance( $user_id ) {
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}

	private function sent_to() {
		$to = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			foreach ( $mail['to'] as $recipient ) {
				$to[] = $recipient[0];
			}
		}
		return $to;
	}

	private function events( $event ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Woo_Wallet_Audit::table() . ' WHERE event = %s', $event ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function rest( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Idempotency-Key', wp_generate_password( 12, false ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	// -- self-adjustment --------------------------------------------------------

	public function test_a_shop_manager_cannot_credit_their_own_wallet() {
		wp_set_current_user( $this->manager_id );

		$result = Woo_Wallet_Staff::adjust( 'credit', $this->manager_id, 500, 'bonus' );

		$this->assertSame( 'woo_wallet_staff_self_credit', $result->get_error_code() );
		$this->assertSame( 0.0, $this->balance( $this->manager_id ) );
	}

	public function test_a_shop_manager_cannot_credit_their_own_wallet_through_the_rest_api() {
		wp_set_current_user( $this->manager_id );

		$response = $this->rest( 'POST', '/terawallet/v1/admin/transactions', array( 'user_id' => $this->manager_id, 'type' => 'credit', 'amount' => 500 ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0.0, $this->balance( $this->manager_id ) );
	}

	public function test_an_administrator_must_give_a_reason_to_adjust_their_own_wallet() {
		wp_set_current_user( $this->admin_id );

		$this->assertSame( 'woo_wallet_staff_self_reason', Woo_Wallet_Staff::adjust( 'credit', $this->admin_id, 50, '' )->get_error_code() );
		$this->assertSame( 0.0, $this->balance( $this->admin_id ) );
	}

	public function test_an_administrators_own_adjustment_is_flagged_and_alerts_the_other_administrators() {
		wp_set_current_user( $this->admin_id );

		$transaction_id = Woo_Wallet_Staff::adjust( 'credit', $this->admin_id, 50, 'testing checkout' );

		$this->assertIsInt( $transaction_id );
		$this->assertSame( '1', (string) get_wallet_transaction_meta( $transaction_id, '_woo_wallet_self_adjustment' ) );
		$this->assertCount( 1, $this->events( Woo_Wallet_Audit::EVENT_SELF_ADJUSTMENT ) );
		$to = $this->sent_to();
		$this->assertContains( 'partner@example.com', $to );
		$this->assertNotContains( 'owner@example.com', $to, 'The administrator is not alerted about their own action.' );
	}

	public function test_a_manual_adjustment_is_recorded_as_an_adjustment_not_other() {
		global $wpdb;
		wp_set_current_user( $this->manager_id );
		$transaction_id = Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'compensation' );
		$category       = $wpdb->get_var( $wpdb->prepare( "SELECT category FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE transaction_id = %d", $transaction_id ) );
		$this->assertSame( 'adjustment', $category );
	}

	// -- transaction history ------------------------------------------------------

	public function test_a_shop_manager_cannot_delete_or_edit_transaction_history_through_the_rest_api() {
		$transaction_id = woo_wallet()->wallet->credit( $this->customer_id, 10, 'original' );
		wp_set_current_user( $this->manager_id );

		$this->assertSame( 403, $this->rest( 'DELETE', '/terawallet/v1/admin/transactions/' . $transaction_id )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/terawallet/v1/admin/transactions/' . $transaction_id, array( 'details' => 'rewritten' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/terawallet/v1/admin/users/' . $this->customer_id . '/transactions/purge', array( 'delete_mode' => 'hard', 'balance_handling' => 'keep' ) )->get_status() );
		$this->assertSame( 510.0, $this->balance( $this->customer_id ) );
	}

	public function test_an_administrator_deleting_a_transaction_is_logged_and_alerted() {
		$transaction_id = woo_wallet()->wallet->credit( $this->customer_id, 10, 'original' );
		wp_set_current_user( $this->admin_id );

		$this->assertSame( 200, $this->rest( 'DELETE', '/terawallet/v1/admin/transactions/' . $transaction_id )->get_status() );

		$events = $this->events( Woo_Wallet_Audit::EVENT_TRANSACTION_DELETED );
		$this->assertCount( 1, $events );
		$this->assertSame( $this->admin_id, (int) $events[0]->actor_id );
		$this->assertContains( 'partner@example.com', $this->sent_to() );
	}

	// -- cashback rules -------------------------------------------------------------

	public function test_a_shop_manager_cannot_change_product_cashback_by_any_route() {
		$product_id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, '_cashback_amount', '5' );

		wp_set_current_user( $this->manager_id );
		update_post_meta( $product_id, '_cashback_amount', '15' );
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->update_meta_data( '_cashback_amount', '20' );
			$product->save();
		}
		delete_post_meta( $product_id, '_cashback_amount' );

		wp_set_current_user( 0 );
		$this->assertSame( '5', get_post_meta( $product_id, '_cashback_amount', true ) );
	}

	public function test_a_shop_manager_cannot_change_category_cashback() {
		$term = self::factory()->term->create( array( 'taxonomy' => 'product_cat' ) );
		update_term_meta( $term, '_woo_cashback_amount', '3' );

		wp_set_current_user( $this->manager_id );
		update_term_meta( $term, '_woo_cashback_amount', '30' );

		$this->assertSame( '3', get_term_meta( $term, '_woo_cashback_amount', true ) );
	}

	public function test_an_administrators_cashback_change_is_logged_with_old_and_new_values() {
		$product_id = self::factory()->post->create( array( 'post_type' => 'product', 'post_title' => 'Phone case' ) );
		update_post_meta( $product_id, '_cashback_amount', '5' );

		wp_set_current_user( $this->admin_id );
		update_post_meta( $product_id, '_cashback_amount', '15' );

		$this->assertSame( '15', get_post_meta( $product_id, '_cashback_amount', true ) );
		$events  = $this->events( Woo_Wallet_Audit::EVENT_CASHBACK_CHANGED );
		$details = json_decode( end( $events )->details, true );
		$this->assertSame( '5', $details['from'] );
		$this->assertSame( '15', $details['to'] );
		$this->assertSame( 'Phone case', $details['name'] );
	}

	public function test_saving_a_product_without_changing_cashback_is_not_blocked_or_logged() {
		$product_id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $product_id, '_cashback_amount', '5' );
		$before = count( $this->events( Woo_Wallet_Audit::EVENT_CASHBACK_CHANGED ) );

		wp_set_current_user( $this->manager_id );
		update_post_meta( $product_id, '_cashback_amount', '5' );

		$this->assertSame( $before, count( $this->events( Woo_Wallet_Audit::EVENT_CASHBACK_CHANGED ) ) );
	}

	public function test_the_cashback_fields_are_read_only_for_a_shop_manager() {
		wp_set_current_user( $this->manager_id );
		$this->assertSame( array( 'disabled' => 'disabled' ), Woo_Wallet_Staff::cashback_field_attributes() );
		wp_set_current_user( $this->admin_id );
		$this->assertSame( array(), Woo_Wallet_Staff::cashback_field_attributes() );
	}

	// -- the daily digest -----------------------------------------------------------

	private function digest_today() {
		$until = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + MINUTE_IN_SECONDS );
		return Woo_Wallet_Audit::digest_data( gmdate( 'Y-m-d H:i:s', strtotime( $until ) - DAY_IN_SECONDS ), $until );
	}

	private function rules( array $data ) {
		return wp_list_pluck( $data['suspicious'], 'rule' );
	}

	public function test_the_digest_lists_manual_adjustments_by_staff_member() {
		wp_set_current_user( $this->manager_id );
		Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'compensation' );
		Woo_Wallet_Staff::adjust( 'debit', $this->customer_id, 5, 'correction' );

		$data = $this->digest_today();

		$this->assertCount( 2, $data['adjustments'] );
		$this->assertSame( 20.0, $data['staff'][ $this->manager_id ]['credit'] );
		$this->assertSame( 5.0, $data['staff'][ $this->manager_id ]['debit'] );
		$this->assertSame( array(), $data['suspicious'] );
	}

	public function test_the_digest_flags_credit_to_a_brand_new_account() {
		$new_customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $this->manager_id );
		Woo_Wallet_Staff::adjust( 'credit', $new_customer, 20, 'welcome' );

		$this->assertContains( 'new_account', $this->rules( $this->digest_today() ) );
	}

	public function test_the_digest_flags_a_customer_whose_email_or_phone_matches_the_staff_member() {
		update_user_meta( $this->manager_id, 'billing_phone', '+20 101 234 5678' );
		update_user_meta( $this->customer_id, 'billing_phone', '01012345678' );
		wp_set_current_user( $this->manager_id );
		Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'compensation' );

		$this->assertContains( 'identity_match', $this->rules( $this->digest_today() ) );
	}

	public function test_email_and_phone_aliases_are_normalised() {
		$this->assertSame( 'mohamedali@gmail.com', Woo_Wallet_Audit::normalize_email( 'Mohamed.Ali+shop@googlemail.com' ) );
		$this->assertSame( 'm.ali@example.com', Woo_Wallet_Audit::normalize_email( 'M.Ali+x@example.com' ) );
		$this->assertSame( '1012345678', Woo_Wallet_Audit::normalize_phone( '+20 101 234 5678' ) );
		$this->assertSame( '1012345678', Woo_Wallet_Audit::normalize_phone( '01012345678' ) );
	}

	public function test_the_digest_flags_many_adjustments_within_an_hour() {
		wp_set_current_user( $this->manager_id );
		foreach ( range( 1, 10 ) as $i ) {
			$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
			Woo_Wallet_Staff::adjust( 'credit', $customer, 50, 'promo ' . $i );
		}

		$this->assertContains( 'burst', $this->rules( $this->digest_today() ) );
	}

	public function test_the_digest_is_sent_to_administrators_even_when_nothing_happened() {
		Woo_Wallet_Audit::send_digest();

		$to = $this->sent_to();
		$this->assertContains( 'owner@example.com', $to );
		$this->assertContains( 'partner@example.com', $to );
		$this->assertNotContains( 'manager@example.com', $to );
		$this->assertStringContainsString( 'No manual credits or debits', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body );
	}

	public function test_the_digest_is_scheduled() {
		Woo_Wallet_Audit::unschedule_digest();
		Woo_Wallet_Audit::maybe_schedule_digest();
		$this->assertNotFalse( wp_next_scheduled( Woo_Wallet_Audit::DIGEST_HOOK ) );
	}

	// -- where a withdrawn balance came from -------------------------------------------

	public function test_the_withdrawal_screen_shows_how_much_of_the_balance_staff_credited_by_hand() {
		wp_set_current_user( $this->manager_id );
		Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 500, 'goodwill' );
		$result = Woo_Wallet_Withdrawal::admin_create( $this->customer_id, 900, array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ), 'Mohamed Ali', '1234567890', '01012345678', '', $this->admin_id, 'pending' );

		$sources = Woo_Wallet_Audit::manual_credit_sources( $this->customer_id, 7, 900 );
		$this->assertSame( 500.0, $sources['total'] );
		$this->assertSame( 50.0, $sources['share'] );
		$this->assertSame( array( $this->manager_id => 500.0 ), $sources['by_staff'] );

		$_GET = array(
			'page'   => 'woo-wallet-withdrawals',
			'action' => 'view',
			'id'     => $result['id'],
		);
		ob_start();
		( new Woo_Wallet_Withdrawal() )->render_admin_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'was credited by hand by staff', $html );
	}

	// -- screens ----------------------------------------------------------------------

	public function test_the_activity_tab_is_for_administrators_only() {
		wp_set_current_user( $this->manager_id );
		Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 20, 'compensation' );
		$staff = new Woo_Wallet_Staff();

		wp_set_current_user( $this->admin_id );
		$_GET = array( 'page' => 'woo-wallet-staff', 'tab' => 'activity' );
		ob_start();
		$staff->render_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'compensation', $html );

		wp_set_current_user( $this->manager_id );
		ob_start();
		$staff->render_page();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'Manual credits and debits by staff member', $html );
	}

	// -- accountant ---------------------------------------------------------------------

	public function test_an_accountant_reviews_and_pays_out_withdrawals_and_nothing_else() {
		Woo_Wallet_Staff::ensure_role();
		$accountant = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $this->admin_id );
		$this->assertInstanceOf( 'WP_User', Woo_Wallet_Staff::save_accountant( $accountant ) );

		foreach ( Woo_Wallet_Staff::accountant_capabilities() as $cap ) {
			$this->assertTrue( user_can( $accountant, $cap ), $cap );
		}
		foreach ( array( Woo_Wallet_Staff::CAP_ADJUST_BALANCE, Woo_Wallet_Staff::CAP_GOODWILL_CREDIT, Woo_Wallet_Staff::CAP_CREATE_WITHDRAWALS, Woo_Wallet_Staff::CAP_APPROVE_REQUESTS, Woo_Wallet_Staff::CAP_REQUEST_APPROVAL, Woo_Wallet_Staff::CAP_MANAGE_STAFF, Woo_Wallet_Staff::CAP_MANAGE_SETTINGS, Woo_Wallet_Staff::CAP_DELETE_LOGS, 'manage_woocommerce', 'edit_products', 'edit_shop_orders' ) as $cap ) {
			$this->assertFalse( user_can( $accountant, $cap ), $cap );
		}
		$this->assertContains( 'customer', get_userdata( $accountant )->roles, 'They keep their existing role.' );

		$result = Woo_Wallet_Withdrawal::admin_create( $this->customer_id, 100, array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ), 'Mohamed Ali', '1234567890', '01012345678', '', $this->admin_id, 'pending' );

		wp_set_current_user( $accountant );
		$this->assertWPError( Woo_Wallet_Staff::adjust( 'credit', $this->customer_id, 10, 'nope' ) );

		$_GET = array( 'page' => 'woo-wallet-withdrawals', 'action' => 'view', 'id' => $result['id'] );
		ob_start();
		( new Woo_Wallet_Withdrawal() )->render_admin_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( '1234567890', $html, 'Full bank details.' );
		$this->assertStringContainsString( 'Process this request', $html );

		$processed = Woo_Wallet_Withdrawal::admin_process( $result['id'], 'paid', $accountant, 'TRX-1' );
		$this->assertTrue( $processed['is_valid'] );

		Woo_Wallet_Staff::remove_accountant( $accountant );
		$this->assertFalse( user_can( $accountant, Woo_Wallet_Staff::CAP_PROCESS_WITHDRAWALS ) );
	}

	public function test_a_manager_cannot_be_made_an_accountant() {
		$this->assertSame( 'woo_wallet_staff_already_manager', Woo_Wallet_Staff::save_accountant( $this->manager_id )->get_error_code() );
	}

	// -- large amounts ---------------------------------------------------------------------

	public function test_large_amounts_need_the_amount_typed_twice() {
		update_option( Woo_Wallet_Staff::LARGE_THRESHOLD_OPTION, 1000 );
		wp_set_current_user( $this->admin_id );

		$this->assertTrue( Woo_Wallet_Staff::check_large_amount( 1000, 1000, null ), 'At the threshold is fine.' );
		$this->assertSame( 'woo_wallet_confirmation_required', Woo_Wallet_Staff::check_large_amount( 5000, 5000, null )->get_error_code() );
		$this->assertSame( 'woo_wallet_confirmation_required', Woo_Wallet_Staff::check_large_amount( 5000, 5000, '50' )->get_error_code() );
		$this->assertTrue( Woo_Wallet_Staff::check_large_amount( 5000, 5000, '5000' ) );
		// A bulk action counts the total, but the person re-types the amount per wallet.
		$this->assertSame( 'woo_wallet_confirmation_required', Woo_Wallet_Staff::check_large_amount( 50 * 100, 50, null, 100 )->get_error_code() );
		$this->assertTrue( Woo_Wallet_Staff::check_large_amount( 50 * 100, 50, '50', 100 ) );

		$this->assertCount( 2, $this->events( Woo_Wallet_Audit::EVENT_LARGE_CONFIRMED ) );

		update_option( Woo_Wallet_Staff::LARGE_THRESHOLD_OPTION, 0 );
		$this->assertTrue( Woo_Wallet_Staff::check_large_amount( 999999, 999999, null ), '0 turns it off.' );
	}

	public function test_the_edit_balance_form_refuses_an_unconfirmed_large_amount() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		if ( ! class_exists( 'Woo_Wallet_Admin' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-admin.php';
		}
		update_option( Woo_Wallet_Staff::LARGE_THRESHOLD_OPTION, 1000 );
		wp_set_current_user( $this->manager_id );
		$post = array(
			'woo-wallet-admin-adjust-balance' => wp_create_nonce( 'woo-wallet-admin-adjust-balance' ),
			'user_id'                         => $this->customer_id,
			'balance_amount'                  => '5000',
			'payment_type'                    => 'credit',
			'payment_description'             => 'refund',
		);
		try {
			$_POST    = $post + array( Woo_Wallet_Staff::FORM_TOKEN_FIELD => wp_generate_uuid4() );
			$_REQUEST = $_POST;
			Woo_Wallet_Admin::instance()->handle_wallet_balance_adjustment();
			$this->assertSame( 500.0, $this->balance( $this->customer_id ) );

			$_POST    = $post + array( Woo_Wallet_Staff::FORM_TOKEN_FIELD => wp_generate_uuid4(), Woo_Wallet_Staff::CONFIRM_FIELD => '5000' );
			$_REQUEST = $_POST;
			Woo_Wallet_Admin::instance()->handle_wallet_balance_adjustment();
			$this->assertSame( 5500.0, $this->balance( $this->customer_id ) );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
	}

	public function test_the_rest_api_refuses_an_unconfirmed_large_amount() {
		update_option( Woo_Wallet_Staff::LARGE_THRESHOLD_OPTION, 1000 );
		wp_set_current_user( $this->manager_id );

		$refused = $this->rest( 'POST', '/terawallet/v1/admin/transactions', array( 'user_id' => $this->customer_id, 'type' => 'credit', 'amount' => 5000 ) );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 500.0, $this->balance( $this->customer_id ) );

		$accepted = $this->rest( 'POST', '/terawallet/v1/admin/transactions', array( 'user_id' => $this->customer_id, 'type' => 'credit', 'amount' => 5000, 'confirm_amount' => 5000 ) );
		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertSame( 5500.0, $this->balance( $this->customer_id ) );
	}
}
