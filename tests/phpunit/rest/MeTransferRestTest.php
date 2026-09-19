<?php
require_once dirname( __DIR__ ) . '/transfer/class-transfer-test-case.php';

/**
 * REST coverage for terawallet/v1/me/transfer — the customer self-service
 * wallet-to-wallet transfer route. Extends Transfer_Test_Case (which itself
 * extends WP_Test_REST_TestCase, for assertErrorResponse()) since it
 * exercises WooWallet_Transfer_Service, which calls
 * Woo_Wallet_Wallet::transfer() — see that class's docblock for why
 * leftover-row cleanup is needed for any test that reaches it.
 */
class Me_Transfer_Rest_Test extends Transfer_Test_Case {

	private $sender_id;
	private $recipient_id;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->sender_id    = self::factory()->user->create();
		$this->recipient_id = self::factory()->user->create( array( 'user_email' => 'recipient@example.com' ) );
		$this->track_users( $this->sender_id, $this->recipient_id );

		woo_wallet()->wallet->credit( $this->sender_id, 1000, 'test funding' );

		update_option(
			'_wallet_settings_general',
			array(
				'is_enable_wallet_transfer' => 'on',
				'min_transfer_amount'       => 0,
				'max_transfer_amount'       => 0,
				'transfer_charge_type'      => 'percent',
				'transfer_charge_amount'    => 0,
			)
		);
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function balance( $user_id ) {
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}

	private function transfer_request( array $params ) {
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/me/transfer' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	// -- auth / feature gate ---------------------------------------------

	public function test_anonymous_cannot_transfer() {
		wp_set_current_user( 0 );
		$response = $this->dispatch( $this->transfer_request( array( 'recipient_id' => $this->recipient_id, 'amount' => 10 ) ) );
		$this->assertErrorResponse( 'rest_not_logged_in', $response, 401 );
	}

	public function test_rejected_when_transfer_feature_disabled() {
		update_option( '_wallet_settings_general', array( 'is_enable_wallet_transfer' => 'off' ) );
		wp_set_current_user( $this->sender_id );
		$response = $this->dispatch( $this->transfer_request( array( 'recipient_id' => $this->recipient_id, 'amount' => 10 ) ) );
		$this->assertErrorResponse( 'rest_transfer_disabled', $response, 403 );
	}

	// -- recipient resolution -----------------------------------------

	public function test_transfer_by_recipient_id() {
		wp_set_current_user( $this->sender_id );
		$response = $this->dispatch( $this->transfer_request( array( 'recipient_id' => $this->recipient_id, 'amount' => 100 ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 900.0, $this->balance( $this->sender_id ) );
		$this->assertSame( 100.0, $this->balance( $this->recipient_id ) );
	}

	public function test_transfer_by_recipient_email() {
		wp_set_current_user( $this->sender_id );
		$response = $this->dispatch( $this->transfer_request( array( 'recipient_email' => 'recipient@example.com', 'amount' => 50 ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 50.0, $this->balance( $this->recipient_id ) );
	}

	public function test_missing_recipient_returns_400() {
		wp_set_current_user( $this->sender_id );
		$response = $this->dispatch( $this->transfer_request( array( 'amount' => 10 ) ) );
		$this->assertErrorResponse( 'rest_invalid_recipient', $response, 400 );
	}

	public function test_unresolvable_recipient_email_returns_400() {
		wp_set_current_user( $this->sender_id );
		$response = $this->dispatch( $this->transfer_request( array( 'recipient_email' => 'no-such-user@example.com', 'amount' => 10 ) ) );
		$this->assertErrorResponse( 'rest_invalid_recipient', $response, 400 );
	}

	public function test_transfer_to_self_returns_400() {
		wp_set_current_user( $this->sender_id );
		$response = $this->dispatch( $this->transfer_request( array( 'recipient_id' => $this->sender_id, 'amount' => 10 ) ) );
		$this->assertErrorResponse( 'rest_invalid_recipient', $response, 400 );
	}

	// -- response shape -------------------------------------------------

	public function test_successful_response_reports_balance_and_charge() {
		wp_set_current_user( $this->sender_id );
		$response = $this->dispatch( $this->transfer_request( array( 'recipient_id' => $this->recipient_id, 'amount' => 100 ) ) );
		$data     = $response->get_data();

		$this->assertSame( 0.0, $data['charge'] );
		$this->assertSame( 900.0, $data['balance']['amount'] );
		$this->assertNotEmpty( $data['transaction_id'] );
		$this->assertNotEmpty( $data['credit_id'] );
	}

	// -- idempotency ------------------------------------------------------

	public function test_idempotency_key_replay_does_not_transfer_twice() {
		wp_set_current_user( $this->sender_id );
		$key = 'transfer-key-' . wp_generate_password( 12, false );

		$make = function () use ( $key ) {
			$request = $this->transfer_request( array( 'recipient_id' => $this->recipient_id, 'amount' => 100 ) );
			$request->set_header( 'Idempotency-Key', $key );
			return $request;
		};

		$response1 = $this->dispatch( $make() );
		$response2 = $this->dispatch( $make() );

		$this->assertSame( 201, $response1->get_status() );
		$this->assertSame( $response1->get_data(), $response2->get_data() );
		$this->assertSame( 900.0, $this->balance( $this->sender_id ), 'A replayed transfer must not move funds a second time.' );
		$this->assertSame( 100.0, $this->balance( $this->recipient_id ) );
	}

	// -- recipient autocomplete -------------------------------------------

	public function test_recipient_lookup_is_disabled_by_default() {
		wp_set_current_user( $this->sender_id );
		$request = new WP_REST_Request( 'GET', '/terawallet/v1/me/transfer/recipients' );
		$request->set_param( 'search', 'recipient' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'rest_recipient_lookup_disabled', $response, 404 );
	}

	public function test_recipient_lookup_when_enabled_excludes_self_and_masks_email() {
		add_filter( 'terawallet_rest_allow_recipient_lookup', '__return_true' );

		wp_set_current_user( $this->sender_id );
		$request = new WP_REST_Request( 'GET', '/terawallet/v1/me/transfer/recipients' );
		$request->set_param( 'search', 'recipient@example.com' );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$ids = array_column( $data, 'id' );
		$this->assertContains( $this->recipient_id, $ids );
		$this->assertNotContains( $this->sender_id, $ids );

		$found = current(
			array_filter(
				$data,
				function ( $row ) {
					return $row['id'] === $this->recipient_id;
				}
			)
		);
		$this->assertStringNotContainsString( 'recipient@example.com', $found['email_masked'] );
		$this->assertStringContainsString( '@example.com', $found['email_masked'] );
	}
}
