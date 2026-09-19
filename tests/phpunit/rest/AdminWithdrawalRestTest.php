<?php
/**
 * REST coverage for terawallet/v1/admin/withdrawals. Focus: capability
 * gating (a non-admin must be rejected outright), and that every
 * money-moving route (create, process, recover) actually enforces the
 * Idempotency-Key header and that replaying a key never moves money twice —
 * the same guarantee AdminProcessMoneySafetyTest proved at the
 * Woo_Wallet_Withdrawal:: level, now proved through the REST surface that
 * wraps it.
 */
class Admin_Withdrawal_Rest_Test extends WP_Test_REST_TestCase {

	private $admin_id;
	private $customer_id;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->admin_id = self::factory()->user->create();
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );

		$this->customer_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->customer_id, 1000, 'test funding' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function valid_bank() {
		return array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() );
	}

	private function create_pending_request_via_backend( $amount = 100 ) {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals' );
		$request->set_header( 'Idempotency-Key', 'setup-' . wp_generate_password( 12, false ) );
		foreach ( array(
			'user_id'          => $this->customer_id,
			'amount'           => $amount,
			'bank_name'        => $this->valid_bank(),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = $this->dispatch( $request );
		return $response->get_data()['id'];
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	// -- capability gating --------------------------------------------------

	public function test_non_admin_cannot_list() {
		// Logged in but lacking manage_woocommerce → 403 (rest_authorization_required_code()
		// returns 401 only for a fully anonymous caller).
		wp_set_current_user( $this->customer_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/admin/withdrawals' ) );
		$this->assertErrorResponse( 'woocommerce_rest_cannot_read', $response, 403 );
	}

	public function test_non_admin_cannot_create() {
		// WP_REST_Server validates required args before invoking the
		// permission_callback, so the request must otherwise be well-formed
		// to actually exercise the capability check rather than a schema error.
		wp_set_current_user( $this->customer_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals' );
		$request->set_header( 'Idempotency-Key', 'x' );
		foreach ( array(
			'user_id'          => $this->customer_id,
			'amount'           => 50,
			'bank_name'        => $this->valid_bank(),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'woocommerce_rest_cannot_edit', $response, 403 );
	}

	public function test_anonymous_cannot_process() {
		$id = $this->create_pending_request_via_backend();
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/process" );
		$request->set_header( 'Idempotency-Key', 'x' );
		$request->set_param( 'action', 'paid' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'woocommerce_rest_cannot_edit', $response, 401 );
	}

	// -- idempotency-key enforcement -----------------------------------------

	public function test_create_without_idempotency_key_returns_400() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals' );
		foreach ( array(
			'user_id'          => $this->customer_id,
			'amount'           => 50,
			'bank_name'        => $this->valid_bank(),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_idempotency_key_required', $response, 400 );
		$this->assertSame( 0, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) ), 'No key must mean no side effect at all.' );
	}

	public function test_process_without_idempotency_key_returns_400() {
		$id = $this->create_pending_request_via_backend();
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/process" );
		$request->set_param( 'action', 'paid' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_idempotency_key_required', $response, 400 );
		$this->assertSame( 'pending', Woo_Wallet_Withdrawal::get_request( $id )->status );
	}

	public function test_recover_without_idempotency_key_returns_400() {
		$id = $this->create_pending_request_via_backend();
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'woo_wallet_withdrawals', array( 'status' => 'processing' ), array( 'id' => $id ) );

		wp_set_current_user( $this->admin_id );
		$request  = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/recover" );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_idempotency_key_required', $response, 400 );
	}

	public function test_notes_does_not_require_idempotency_key() {
		$id = $this->create_pending_request_via_backend();
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/notes" );
		$request->set_param( 'note', 'called the customer, confirmed bank details' );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
	}

	// -- idempotent replay: create ------------------------------------------

	public function test_create_replay_with_same_key_does_not_debit_twice() {
		wp_set_current_user( $this->admin_id );
		$balance_before = $this->balance();
		$key            = 'admin-create-' . wp_generate_password( 12, false );

		$make_request = function () use ( $key ) {
			$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals' );
			$request->set_header( 'Idempotency-Key', $key );
			foreach ( array(
				'user_id'          => $this->customer_id,
				'amount'           => 100,
				'bank_name'        => $this->valid_bank(),
				'beneficiary_name' => 'Mohamed Ali',
				'account_number'   => '1234567890',
				'phone'            => '01012345678',
			) as $k => $v ) {
				$request->set_param( $k, $v );
			}
			return $request;
		};

		$response1 = $this->dispatch( $make_request() );
		$response2 = $this->dispatch( $make_request() );

		$this->assertSame( 201, $response1->get_status() );
		$this->assertSame( $response1->get_data()['id'], $response2->get_data()['id'] );
		$this->assertSame( $balance_before - 100.0, $this->balance(), 'Replaying the same create key must not reserve funds twice.' );
		$this->assertSame( 1, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) ) );
	}

	public function test_create_invalid_user_id_returns_404() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals' );
		$request->set_header( 'Idempotency-Key', 'k' );
		foreach ( array(
			'user_id'          => 999999,
			'amount'           => 50,
			'bank_name'        => $this->valid_bank(),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_invalid_user', $response, 404 );
	}

	// -- idempotent replay: process (reject) — the exact double-refund guarantee

	public function test_process_reject_replay_with_same_key_does_not_refund_twice() {
		$id = $this->create_pending_request_via_backend( 100 );
		wp_set_current_user( $this->admin_id );
		$balance_before = $this->balance();
		$key            = 'admin-process-' . wp_generate_password( 12, false );

		$make_request = function () use ( $id, $key ) {
			$request = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/process" );
			$request->set_header( 'Idempotency-Key', $key );
			$request->set_param( 'action', 'reject' );
			return $request;
		};

		$response1 = $this->dispatch( $make_request() );
		$response2 = $this->dispatch( $make_request() );

		$this->assertSame( 200, $response1->get_status() );
		$this->assertSame( $response1->get_data(), $response2->get_data(), 'A replayed process call must return the exact same cached response.' );
		$this->assertTrue( (bool) $response2->get_headers()['Idempotent-Replay'] );
		$this->assertSame( $balance_before + 100.0, $this->balance(), 'Replaying the same reject key must not refund twice.' );
	}

	public function test_process_already_processed_request_returns_409_with_a_new_key() {
		$id = $this->create_pending_request_via_backend( 100 );
		wp_set_current_user( $this->admin_id );

		$first = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/process" );
		$first->set_header( 'Idempotency-Key', 'key-a' );
		$first->set_param( 'action', 'paid' );
		$this->dispatch( $first );

		// A *different* key against the same, now-already-processed request —
		// simulates a second admin (or a naive client retry that generated a
		// fresh key rather than reusing the original). The atomic
		// `WHERE status='pending'` guard in admin_process() must still catch
		// this even though the idempotency cache has no record of this key.
		$second = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/process" );
		$second->set_header( 'Idempotency-Key', 'key-b' );
		$second->set_param( 'action', 'paid' );
		$response = $this->dispatch( $second );

		$this->assertErrorResponse( 'terawallet_rest_withdrawal_process_failed', $response, 409 );
	}

	public function test_process_nonexistent_request_returns_404() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals/999999/process' );
		$request->set_header( 'Idempotency-Key', 'k' );
		$request->set_param( 'action', 'paid' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_withdrawal_not_found', $response, 404 );
	}

	// -- idempotent replay: recover -----------------------------------------

	public function test_recover_replay_with_same_key_does_not_refund_twice() {
		$id = $this->create_pending_request_via_backend( 100 );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'woo_wallet_withdrawals', array( 'status' => 'processing' ), array( 'id' => $id ) );

		wp_set_current_user( $this->admin_id );
		$balance_before = $this->balance();
		$key            = 'admin-recover-' . wp_generate_password( 12, false );

		$make_request = function () use ( $id, $key ) {
			$request = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/recover" );
			$request->set_header( 'Idempotency-Key', $key );
			return $request;
		};

		$response1 = $this->dispatch( $make_request() );
		$response2 = $this->dispatch( $make_request() );

		$this->assertSame( 200, $response1->get_status() );
		$this->assertSame( $response1->get_data(), $response2->get_data() );
		$this->assertSame( $balance_before + 100.0, $this->balance() );
	}

	public function test_recover_a_non_processing_request_returns_409() {
		$id = $this->create_pending_request_via_backend( 100 ); // still 'pending'.
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$id}/recover" );
		$request->set_header( 'Idempotency-Key', 'k' );
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_withdrawal_recover_failed', $response, 409 );
	}

	// -- filters --------------------------------------------------------

	public function test_list_filters_by_status() {
		$paid_id = $this->create_pending_request_via_backend( 50 );
		wp_set_current_user( $this->admin_id );
		$process = new WP_REST_Request( 'POST', "/terawallet/v1/admin/withdrawals/{$paid_id}/process" );
		$process->set_header( 'Idempotency-Key', 'k1' );
		$process->set_param( 'action', 'paid' );
		$this->dispatch( $process );

		$this->create_pending_request_via_backend( 60 ); // stays pending.

		$request = new WP_REST_Request( 'GET', '/terawallet/v1/admin/withdrawals' );
		$request->set_param( 'status', 'paid' );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'paid', $data[0]['status'] );
		$this->assertSame( $paid_id, $data[0]['id'] );
	}

	public function test_list_filters_by_user_id() {
		$other_customer = self::factory()->user->create();
		woo_wallet()->wallet->credit( $other_customer, 500, 'test funding' );

		$mine_id = $this->create_pending_request_via_backend( 40 );

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals' );
		$request->set_header( 'Idempotency-Key', 'other-customer-req' );
		foreach ( array(
			'user_id'          => $other_customer,
			'amount'           => 30,
			'bank_name'        => $this->valid_bank(),
			'beneficiary_name' => 'Someone Else',
			'account_number'   => '999',
			'phone'            => '01011112222',
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$this->dispatch( $request );

		$list = new WP_REST_Request( 'GET', '/terawallet/v1/admin/withdrawals' );
		$list->set_param( 'user_id', $this->customer_id );
		$response = $this->dispatch( $list );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( $mine_id, $data[0]['id'] );
	}

	public function test_list_filters_by_requested_by_self_vs_staff() {
		// Staff-logged (created_by = admin, not the customer).
		$this->create_pending_request_via_backend( 20 );

		// Self-service (created_by = user_id).
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
		wp_set_current_user( $this->customer_id );
		$self_request = new WP_REST_Request( 'POST', '/terawallet/v1/me/withdrawals' );
		foreach ( array(
			'amount'           => 25,
			'bank_name'        => $this->valid_bank(),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
		) as $key => $value ) {
			$self_request->set_param( $key, $value );
		}
		$this->dispatch( $self_request );

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'GET', '/terawallet/v1/admin/withdrawals' );
		$request->set_param( 'requested_by', 'self' );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertTrue( $data[0]['self_service'] );
	}

	// -- receipt validation ------------------------------------------------

	public function test_receipt_id_must_be_a_valid_attachment() {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/terawallet/v1/admin/withdrawals' );
		$request->set_header( 'Idempotency-Key', 'k' );
		foreach ( array(
			'user_id'          => $this->customer_id,
			'amount'           => 50,
			'bank_name'        => $this->valid_bank(),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
			'receipt_id'       => 999999,
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = $this->dispatch( $request );
		$this->assertErrorResponse( 'terawallet_rest_invalid_receipt', $response, 400 );
	}
}
