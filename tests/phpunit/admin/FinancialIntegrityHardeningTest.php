<?php
/**
 * Coverage for production-hardening financial integrity fixes:
 * 1. AES-256-CBC encryption at rest, decryption, and backward compatibility.
 * 2. Sensitive account masking helper.
 * 3. Atomic database claim in Woo_Wallet_Idempotency.
 * 4. Multi-currency validation in WooWallet_Topup_Service.
 * 5. Admin refund compensating rollback and guest pre-validation.
 */
class Financial_Integrity_Hardening_Test extends WP_Ajax_UnitTestCase {

	private $admin_id;
	private $customer_id;

	public function set_up() {
		parent::set_up();

		require_once WOO_WALLET_ABSPATH . 'includes/services/class-woo-wallet-idempotency.php';
		require_once WOO_WALLET_ABSPATH . 'includes/services/class-woo-wallet-topup-service.php';

		if ( ! class_exists( 'Woo_Wallet_Ajax' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-ajax.php';
		}
		( new ReflectionProperty( 'Woo_Wallet_Ajax', '_instance' ) )->setValue( null, null );
		Woo_Wallet_Ajax::instance();

		$this->admin_id = self::factory()->user->create();
		get_userdata( $this->admin_id )->add_cap( 'edit_shop_orders' );

		$this->customer_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->customer_id, 500, 'initial funding' );
	}

	// -- 1. Security Encryption & Backward Compatibility -------------------

	public function test_security_encrypt_decrypt_roundtrip() {
		$plaintext = 'EG980001000100000012345678901';
		$encrypted = Woo_Wallet_Security::encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $encrypted );
		$this->assertStringStartsWith( 'enc:v1:', $encrypted );

		$decrypted = Woo_Wallet_Security::decrypt( $encrypted );
		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_security_decrypt_backward_compatibility_with_plaintext() {
		$legacy_account = '12345678901234';
		$this->assertSame( $legacy_account, Woo_Wallet_Security::decrypt( $legacy_account ) );
	}

	public function test_security_masking() {
		$this->assertSame( '••••••••9012', Woo_Wallet_Security::mask( '123456789012' ) );
		$this->assertSame( '•••••••••••••••••••••••••8901', Woo_Wallet_Security::mask( 'EG980001000100000012345678901' ) );
		$this->assertSame( '123', Woo_Wallet_Security::mask( '123' ) );
		$this->assertNull( Woo_Wallet_Security::mask( '' ) );
	}

	// -- 2. Atomic Idempotency Claim ---------------------------------------

	public function test_idempotency_atomic_claim_and_replay() {
		$user_id    = $this->customer_id;
		$key        = 'idem_key_' . wp_generate_password( 10, false );
		$call_count = 0;

		$callback = function () use ( &$call_count ) {
			$call_count++;
			return new WP_REST_Response( array( 'order_id' => 999 ), 201 );
		};

		// 1. Initial execution.
		$res1 = WooWallet_Idempotency::run( $user_id, $key, $callback );
		$this->assertSame( 201, $res1->get_status() );
		$this->assertSame( 999, $res1->get_data()['order_id'] );
		$this->assertSame( 1, $call_count );

		// 2. Replayed execution with same key must return cached response without running callback again.
		$res2 = WooWallet_Idempotency::run( $user_id, $key, $callback );
		$this->assertSame( 201, $res2->get_status() );
		$this->assertSame( 999, $res2->get_data()['order_id'] );
		$this->assertSame( 'true', $res2->get_headers()['Idempotent-Replay'] );
		$this->assertSame( 1, $call_count );

		// 3. Test concurrent in-progress collision (simulating add_option collision):
		$in_flight_key   = 'idem_inflight_' . wp_generate_password( 10, false );
		$transient_name  = '_transient_' . WooWallet_Idempotency::TRANSIENT_PREFIX . $user_id . '_' . md5( $in_flight_key );
		add_option( $transient_name, array( 'state' => 'in_progress', 'at' => time() ), '', 'no' );

		$res3 = WooWallet_Idempotency::run( $user_id, $in_flight_key, $callback );
		$this->assertTrue( is_wp_error( $res3 ) );
		$this->assertSame( 'terawallet_rest_idempotency_in_progress', $res3->get_error_code() );
		$this->assertSame( 409, $res3->get_error_data()['status'] );
		$this->assertSame( 1, $call_count );
	}

	// -- 3. Topup Currency Validation --------------------------------------

	public function test_topup_rejects_malformed_currency_code() {
		$result = WooWallet_Topup_Service::create_order( $this->customer_id, 100, '', 'US' );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_invalid_currency', $result['code'] );
	}

	public function test_topup_rejects_unsupported_currency_code() {
		$result = WooWallet_Topup_Service::create_order( $this->customer_id, 100, '', 'XYZ' );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_unsupported_currency', $result['code'] );
	}

	public function test_topup_accepts_supported_woocommerce_currency() {
		$result = WooWallet_Topup_Service::create_order( $this->customer_id, 50, '', 'USD' );
		$this->assertTrue( $result['is_valid'] );
		$this->assertNotEmpty( $result['order_id'] );
		$this->assertSame( 'USD', $result['currency'] );

		$order = wc_get_order( $result['order_id'] );
		$this->assertSame( 'USD', $order->get_currency() );
	}

	// -- 4. Admin Refund Rollback & Customer Validation -------------------

	public function test_admin_refund_rejects_guest_order_before_creating_refund() {
		wp_set_current_user( $this->admin_id );

		// Create guest order (customer_id = 0).
		$order = wc_create_order( array( 'customer_id' => 0 ) );
		$order->set_total( 100 );
		$order->save();

		$this->_last_response      = '';
		$_POST['security']         = wp_create_nonce( 'order-item' );
		$_POST['order_id']         = $order->get_id();
		$_POST['refund_amount']    = '50.00';
		$_POST['refunded_amount']  = '0.00';
		$_POST['line_item_qtys']   = '{}';
		$_POST['line_item_totals'] = '{}';

		try {
			$this->_handleAjax( 'woo_wallet_order_refund' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'does not belong to a registered customer', $response['data']['error'] );

		// Verify no refund object was created on the order.
		$this->assertSame( 0, count( $order->get_refunds() ) );
		$this->assertSame( 0.0, (float) $order->get_total_refunded() );
	}

	public function test_admin_refund_rolls_back_refund_when_wallet_credit_fails() {
		wp_set_current_user( $this->admin_id );

		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_total( 100 );
		$order->save();

		// Lock customer wallet so credit fails.
		update_user_meta( $this->customer_id, '_is_wallet_locked', true );

		$this->_last_response      = '';
		$_POST['security']         = wp_create_nonce( 'order-item' );
		$_POST['order_id']         = $order->get_id();
		$_POST['refund_amount']    = '50.00';
		$_POST['refunded_amount']  = '0.00';
		$_POST['line_item_qtys']   = '{}';
		$_POST['line_item_totals'] = '{}';

		try {
			$this->_handleAjax( 'woo_wallet_order_refund' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Order refund was rolled back', $response['data']['error'] );

		// Verify compensating rollback deleted the WC_Order_Refund object.
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 0, count( $order->get_refunds() ), 'The refund object must be deleted on rollback.' );
		$this->assertSame( 0.0, (float) $order->get_total_refunded(), 'Refunded total must remain 0.' );

		// Verify order note was added.
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$note_texts = array_column( $notes, 'content' );
		$matching_notes = array_filter(
			$note_texts,
			function ( $content ) {
				return str_contains( $content, 'Wallet refund failed: unable to credit' );
			}
		);
		$this->assertNotEmpty( $matching_notes, 'Order note detailing rollback must be recorded.' );
	}
}
