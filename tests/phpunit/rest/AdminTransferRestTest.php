<?php
require_once dirname( __DIR__ ) . '/transfer/class-transfer-test-case.php';

/**
 * REST coverage for terawallet/v1/admin/transfer — the admin-initiated
 * peer-to-peer transfer route. Wraps Woo_Wallet_Wallet::transfer() directly
 * (not through WooWallet_Transfer_Service — no rate limit, no charge, no
 * min/max amount check for this admin-only surface), gated by
 * Idempotency-Key like the other admin money-moving routes.
 */
class Admin_Transfer_Rest_Test extends Transfer_Test_Case {

	private $admin_id;
	private $from_id;
	private $to_id;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->admin_id = self::factory()->user->create();
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );

		$this->from_id = self::factory()->user->create();
		$this->to_id   = self::factory()->user->create();
		$this->track_users( $this->admin_id, $this->from_id, $this->to_id );

		woo_wallet()->wallet->credit( $this->from_id, 1000, 'test funding' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function balance( $user_id ) {
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}

	private function transfer_request( array $overrides = array() ) {
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/transfer' );
		$params  = array_merge(
			array(
				'from_user_id' => $this->from_id,
				'to_user_id'   => $this->to_id,
				'amount'       => 100,
			),
			$overrides
		);
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	// -- capability gating --------------------------------------------

	public function test_non_admin_cannot_transfer() {
		wp_set_current_user( $this->from_id );
		$request = $this->transfer_request();
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'woocommerce_rest_cannot_edit', $response, 403 );
	}

	public function test_anonymous_cannot_transfer() {
		wp_set_current_user( 0 );
		$request = $this->transfer_request();
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'woocommerce_rest_cannot_edit', $response, 401 );
	}

	// -- idempotency-key enforcement -----------------------------------

	public function test_missing_idempotency_key_returns_400_with_no_side_effect() {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( $this->transfer_request() );
		$this->assertErrorResponse( 'terawallet_rest_idempotency_key_required', $response, 400 );
		$this->assertSame( 1000.0, $this->balance( $this->from_id ) );
	}

	// -- validation -------------------------------------------------------

	public function test_same_from_and_to_user_returns_400() {
		wp_set_current_user( $this->admin_id );
		$request = $this->transfer_request( array( 'to_user_id' => $this->from_id ) );
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_transfer_same_user', $response, 400 );
	}

	public function test_invalid_from_user_id_returns_404() {
		wp_set_current_user( $this->admin_id );
		$request = $this->transfer_request( array( 'from_user_id' => 999999 ) );
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_invalid_user', $response, 404 );
	}

	public function test_invalid_to_user_id_returns_404() {
		wp_set_current_user( $this->admin_id );
		$request = $this->transfer_request( array( 'to_user_id' => 999999 ) );
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_invalid_user', $response, 404 );
	}

	public function test_insufficient_balance_returns_a_failure_response() {
		wp_set_current_user( $this->admin_id );
		$request = $this->transfer_request( array( 'amount' => 5000 ) );
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_transfer_failed', $response, 500 );
		$this->assertSame( 1000.0, $this->balance( $this->from_id ) );
	}

	// -- successful transfer + idempotent replay --------------------------

	public function test_successful_transfer_moves_funds() {
		wp_set_current_user( $this->admin_id );
		$request = $this->transfer_request( array( 'amount' => 100 ) );
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['transferred'] );
		$this->assertSame( 900.0, $this->balance( $this->from_id ) );
		$this->assertSame( 100.0, $this->balance( $this->to_id ) );
	}

	public function test_replay_with_same_key_does_not_transfer_twice() {
		wp_set_current_user( $this->admin_id );
		$key = 'admin-transfer-' . wp_generate_password( 12, false );

		$make = function () use ( $key ) {
			$request = $this->transfer_request( array( 'amount' => 100 ) );
			$request->set_header( 'Idempotency-Key', $key );
			return $request;
		};

		$response1 = $this->dispatch( $make() );
		$response2 = $this->dispatch( $make() );

		$this->assertSame( 200, $response1->get_status() );
		$this->assertSame( $response1->get_data(), $response2->get_data() );
		$this->assertSame( 900.0, $this->balance( $this->from_id ), 'A replayed admin transfer must not move funds a second time.' );
		$this->assertSame( 100.0, $this->balance( $this->to_id ) );
	}

	public function test_different_idempotency_keys_transfer_independently() {
		wp_set_current_user( $this->admin_id );

		$request1 = $this->transfer_request( array( 'amount' => 100 ) );
		$request1->set_header( 'Idempotency-Key', 'key-one' );
		$this->dispatch( $request1 );

		$request2 = $this->transfer_request( array( 'amount' => 100 ) );
		$request2->set_header( 'Idempotency-Key', 'key-two' );
		$this->dispatch( $request2 );

		$this->assertSame( 800.0, $this->balance( $this->from_id ) ); // 1000 - 100 - 100.
		$this->assertSame( 200.0, $this->balance( $this->to_id ) );
	}
}
