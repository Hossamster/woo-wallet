<?php
/**
 * REST coverage for terawallet/v1/me/withdrawals — the customer-facing
 * surface. Focus: auth scoping (a customer must never see or affect another
 * customer's requests, and non-cookie auth must be rejected outright on this
 * namespace), and that the Idempotency-Key replay path actually prevents a
 * double submission from double-reserving funds.
 */
class Me_Withdrawal_Rest_Test extends WP_Test_REST_TestCase {

	private $user_id;
	private $other_user_id;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->user_id       = self::factory()->user->create();
		$this->other_user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->user_id, 1000, 'test funding' );
		woo_wallet()->wallet->credit( $this->other_user_id, 1000, 'test funding' );

		update_option(
			'_wallet_settings_withdrawal',
			array(
				'is_enable_wallet_withdrawal' => 'on',
				'min_withdrawal_amount'       => 0,
				'max_withdrawal_amount'       => 0,
				'withdrawal_charge_type'      => 'fixed',
				'withdrawal_charge_amount'    => 0,
			)
		);
	}

	private function valid_bank() {
		return array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() );
	}

	private function create_request( $overrides = array() ) {
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/me/withdrawals' );
		$params  = array_merge(
			array(
				'amount'           => 100,
				'bank_name'        => $this->valid_bank(),
				'beneficiary_name' => 'Mohamed Ali',
				'account_number'   => '1234567890',
				'phone'            => '01012345678',
			),
			$overrides
		);
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	// -- authentication / scoping -----------------------------------------

	public function test_anonymous_cannot_list() {
		wp_set_current_user( 0 );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/me/withdrawals' ) );
		$this->assertErrorResponse( 'rest_not_logged_in', $response, 401 );
	}

	public function test_anonymous_cannot_create() {
		wp_set_current_user( 0 );
		$response = $this->dispatch( $this->create_request() );
		$this->assertErrorResponse( 'rest_not_logged_in', $response, 401 );
	}

	public function test_consumer_key_auth_rejected_on_me_namespace() {
		wp_set_current_user( $this->user_id );
		$_SERVER['PHP_AUTH_USER'] = 'ck_deadbeef1234567890';
		try {
			$response = $this->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/me/withdrawals' ) );
			$this->assertErrorResponse( 'rest_consumer_key_in_me_namespace', $response, 401 );
		} finally {
			unset( $_SERVER['PHP_AUTH_USER'] );
		}
	}

	public function test_create_rejected_when_withdrawal_feature_disabled() {
		update_option(
			'_wallet_settings_withdrawal',
			array( 'is_enable_wallet_withdrawal' => 'off' )
		);
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch( $this->create_request() );
		$this->assertErrorResponse( 'rest_withdrawal_disabled', $response, 403 );
	}

	public function test_list_only_returns_the_current_users_own_requests() {
		wp_set_current_user( $this->user_id );
		$this->dispatch( $this->create_request( array( 'amount' => 50 ) ) );

		wp_set_current_user( $this->other_user_id );
		$this->dispatch( $this->create_request( array( 'amount' => 75 ) ) );

		wp_set_current_user( $this->user_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/me/withdrawals' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 50.0, (float) $data[0]['amount'] );
	}

	public function test_get_item_for_another_users_request_returns_404_not_403() {
		wp_set_current_user( $this->other_user_id );
		$create = $this->dispatch( $this->create_request( array( 'amount' => 60 ) ) );
		$id     = $create->get_data()['id'];

		wp_set_current_user( $this->user_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/me/withdrawals/' . $id ) );

		$this->assertErrorResponse( 'rest_withdrawal_not_found', $response, 404 );
	}

	public function test_get_own_item_returns_200() {
		wp_set_current_user( $this->user_id );
		$create = $this->dispatch( $this->create_request( array( 'amount' => 60 ) ) );
		$id     = $create->get_data()['id'];

		$response = $this->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/me/withdrawals/' . $id ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['id'] );
	}

	// -- validation ---------------------------------------------------------

	public function test_create_with_invalid_bank_returns_400() {
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch( $this->create_request( array( 'bank_name' => 'Not A Real Bank' ) ) );
		$this->assertErrorResponse( 'rest_withdrawal_request_failed', $response, 400 );
	}

	public function test_create_missing_required_phone_returns_400_from_schema() {
		wp_set_current_user( $this->user_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/me/withdrawals' );
		$request->set_param( 'amount', 100 );
		$request->set_param( 'bank_name', $this->valid_bank() );
		$request->set_param( 'beneficiary_name', 'Mohamed Ali' );
		$request->set_param( 'account_number', '1234567890' );
		// 'phone' deliberately omitted — required by the route's arg schema.
		$response = $this->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	// -- response shape -------------------------------------------------

	public function test_successful_create_reserves_funds_and_echoes_submitted_fields() {
		wp_set_current_user( $this->user_id );
		$balance_before = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );

		$response = $this->dispatch( $this->create_request( array( 'amount' => 100, 'phone' => '01099998888' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 100.0, (float) $data['amount'] );
		$this->assertSame( '01099998888', $data['phone'] );
		$this->assertSame( 'pending', $data['status'] );

		$balance_after = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );
		$this->assertSame( $balance_before - 100.0, $balance_after );
	}

	public function test_pagination_headers_are_present() {
		wp_set_current_user( $this->user_id );
		$this->dispatch( $this->create_request( array( 'amount' => 10 ) ) );
		$this->dispatch( $this->create_request( array( 'amount' => 10 ) ) );

		$request = new WP_REST_Request( 'GET', '/terawallet/v1/me/withdrawals' );
		$request->set_param( 'per_page', 1 );
		$response = $this->dispatch( $request );
		$headers  = $response->get_headers();

		$this->assertSame( '2', $headers['X-WP-Total'] );
		$this->assertSame( '2', $headers['X-WP-TotalPages'] );
	}

	// -- idempotency ------------------------------------------------------

	public function test_idempotency_key_replay_does_not_reserve_funds_twice() {
		wp_set_current_user( $this->user_id );
		$balance_before = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );

		$key = 'test-key-' . wp_generate_password( 12, false );

		$request1 = $this->create_request( array( 'amount' => 100 ) );
		$request1->set_header( 'Idempotency-Key', $key );
		$response1 = $this->dispatch( $request1 );

		$request2 = $this->create_request( array( 'amount' => 100 ) );
		$request2->set_header( 'Idempotency-Key', $key );
		$response2 = $this->dispatch( $request2 );

		$this->assertSame( 201, $response1->get_status() );
		$this->assertSame( $response1->get_status(), $response2->get_status() );
		$this->assertSame( $response1->get_data()['id'], $response2->get_data()['id'], 'A replayed request must return the same withdrawal id, not create a second one.' );

		$balance_after = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );
		$this->assertSame( $balance_before - 100.0, $balance_after, 'A replayed request must not reserve funds a second time.' );

		$this->assertSame( 1, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->user_id ) ) );
	}

	public function test_different_idempotency_keys_create_separate_requests() {
		wp_set_current_user( $this->user_id );

		$request1 = $this->create_request( array( 'amount' => 50 ) );
		$request1->set_header( 'Idempotency-Key', 'key-one' );
		$response1 = $this->dispatch( $request1 );

		$request2 = $this->create_request( array( 'amount' => 50 ) );
		$request2->set_header( 'Idempotency-Key', 'key-two' );
		$response2 = $this->dispatch( $request2 );

		$this->assertNotSame( $response1->get_data()['id'], $response2->get_data()['id'] );
		$this->assertSame( 2, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->user_id ) ) );
	}
}
