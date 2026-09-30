<?php
/**
 * Coverage for production-hardening financial integrity fixes:
 * 1. AES-256-CBC encryption at rest, decryption, and backward compatibility.
 * 2. Sensitive account masking helper.
 * 3. Atomic database claim in Woo_Wallet_Idempotency.
 * 4. Multi-currency validation in WooWallet_Topup_Service.
 * 5. Admin refund compensating rollback and guest pre-validation.
 * 6. Admin refund reconciliation flag when a *gateway* refund's own wallet
 *    credit fails (as opposed to #5, where no gateway-side money ever moved).
 */

/**
 * A minimal WC_Payment_Gateway stand-in that reports refund support and
 * always succeeds, so wc_create_refund( refund_payment: true ) genuinely
 * exercises the "gateway already refunded the customer" path in tests —
 * none of WooCommerce's own bundled gateways (BACS, Cheque, COD, PayPal
 * Standard) support automatic refunds.
 */
if ( ! class_exists( 'Woo_Wallet_Test_Refundable_Gateway' ) ) {
	class Woo_Wallet_Test_Refundable_Gateway extends WC_Payment_Gateway {
		public function __construct() {
			$this->id       = 'wwtest_refundable';
			$this->method_title = 'WW Test Refundable Gateway';
			$this->supports = array( 'refunds' );
		}

		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			return true;
		}
	}
}

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

	/**
	 * Under a persistent external object cache (Redis/Memcached),
	 * get_transient()/set_transient() read and write the cache, not
	 * wp_options — a different store than the one the atomic add_option()
	 * claim can ever target. WooWallet_Idempotency must never read through
	 * that API for this data: proven here by poisoning the transients
	 * cache group directly (simulating what a completely different value
	 * sitting in Redis would look like) and confirming run() ignores it
	 * entirely, reading and writing only the raw wp_options row.
	 */
	public function test_idempotency_never_reads_through_the_transients_cache_layer() {
		$user_id = $this->customer_id;
		$key     = 'idem_cache_poison_' . wp_generate_password( 10, false );

		$callback = function () {
			return new WP_REST_Response( array( 'from' => 'real_callback' ), 201 );
		};

		$first = WooWallet_Idempotency::run( $user_id, $key, $callback );
		$this->assertSame( 'real_callback', $first->get_data()['from'] );

		// Force the exact condition the bug depended on: with an external
		// object cache active, get_transient()/set_transient() stop touching
		// wp_options at all and read/write this cache group instead.
		// Poisoning it here is what a stale/mismatched Redis entry would look
		// like if the class ever consulted it.
		$was_using_ext_cache = wp_using_ext_object_cache( true );
		$transient           = WooWallet_Idempotency::TRANSIENT_PREFIX . $user_id . '_' . md5( $key );
		wp_cache_set( $transient, array( 'status' => 200, 'body' => array( 'from' => 'poisoned_cache' ) ), 'transient' );

		try {
			$second = WooWallet_Idempotency::run( $user_id, $key, $callback );
			$this->assertSame( 'real_callback', $second->get_data()['from'], 'Must replay from the raw wp_options row, never from the object cache.' );
		} finally {
			wp_using_ext_object_cache( $was_using_ext_cache );
		}
	}

	/**
	 * The regression this guards: an in-progress claim's timestamp must be
	 * readable straight back out of wp_options with nothing lost — the bug
	 * being fixed made this unreadable via get_transient() the moment an
	 * external object cache was in play, so a stale claim could never be
	 * detected as expired and taken over, wedging the key at 409 forever.
	 */
	public function test_idempotency_takes_over_a_stale_in_progress_claim() {
		$user_id = $this->customer_id;
		$key     = 'idem_stale_claim_' . wp_generate_password( 10, false );

		$option_name = '_transient_' . WooWallet_Idempotency::TRANSIENT_PREFIX . $user_id . '_' . md5( $key );
		add_option(
			$option_name,
			array(
				'state' => 'in_progress',
				'at'    => time() - WooWallet_Idempotency::IN_FLIGHT_TTL - 60,
				'token' => 'a-crashed-requests-token',
			),
			'',
			'no'
		);

		$result = WooWallet_Idempotency::run(
			$user_id,
			$key,
			function () {
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}

	/**
	 * The completed result must survive for the full TTL (this class's own
	 * documented replay window), not just the short in-flight window used
	 * while a request is still running.
	 */
	public function test_idempotency_completed_result_gets_the_full_ttl_not_the_in_flight_ttl() {
		$user_id = $this->customer_id;
		$key     = 'idem_ttl_' . wp_generate_password( 10, false );

		WooWallet_Idempotency::run(
			$user_id,
			$key,
			function () {
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}
		);

		$transient    = WooWallet_Idempotency::TRANSIENT_PREFIX . $user_id . '_' . md5( $key );
		$timeout_name = '_transient_timeout_' . $transient;
		$timeout      = (int) get_option( $timeout_name );

		$this->assertGreaterThan( time() + WooWallet_Idempotency::IN_FLIGHT_TTL, $timeout, 'A completed replay record must be kept for the full TTL, not just the in-flight window.' );
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

	/**
	 * $api_refund (posted as 'api_refund' => 'true') makes wc_create_refund()
	 * call the order's payment gateway to actually move money back to the
	 * customer through their original payment method — a completely
	 * different, real-world event from the wallet-ledger-only rollback
	 * covered above. If the wallet credit then fails, the gateway refund has
	 * already happened and cannot be "undone" by deleting our own
	 * WC_Order_Refund record — that would just erase the one record of a
	 * real financial event. This must NOT delete the refund; it must flag
	 * the order for manual reconciliation instead.
	 */
	public function test_admin_refund_flags_for_reconciliation_instead_of_deleting_a_real_gateway_refund() {
		wp_set_current_user( $this->admin_id );

		// A minimal gateway that reports success on process_refund(), so
		// wc_create_refund( refund_payment: true ) genuinely succeeds at the
		// "gateway" step — exactly the situation where deleting the refund
		// afterwards would be a lie about what actually happened.
		$gateway           = new Woo_Wallet_Test_Refundable_Gateway();
		$original_gateways = WC_Payment_Gateways::instance()->payment_gateways;
		WC_Payment_Gateways::instance()->payment_gateways[] = $gateway;

		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_payment_method( $gateway );
		$order->set_total( 100 );
		$order->save();

		// Lock the wallet so the credit step fails after the gateway refund
		// has already gone through.
		update_user_meta( $this->customer_id, '_is_wallet_locked', true );

		try {
			$this->_last_response      = '';
			$_POST['security']         = wp_create_nonce( 'order-item' );
			$_POST['order_id']         = $order->get_id();
			$_POST['refund_amount']    = '50.00';
			$_POST['refunded_amount']  = '0.00';
			$_POST['line_item_qtys']   = '{}';
			$_POST['line_item_totals'] = '{}';
			$_POST['api_refund']       = 'true';

			try {
				$this->_handleAjax( 'woo_wallet_order_refund' );
			} catch ( WPAjaxDieContinueException $e ) {
				// Expected.
			}
		} finally {
			WC_Payment_Gateways::instance()->payment_gateways = $original_gateways;
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'flagged for manual reconciliation', $response['data']['error'] );
		$this->assertStringNotContainsString( 'rolled back', $response['data']['error'], 'Must not claim a rollback that cannot actually happen for a real gateway refund.' );

		$order = wc_get_order( $order->get_id() );

		// The refund the gateway actually processed must survive — deleting
		// it would erase the only record of a real financial event.
		$this->assertSame( 1, count( $order->get_refunds() ), 'The gateway-refunded WC_Order_Refund must not be deleted.' );
		$this->assertSame( 50.0, (float) $order->get_total_refunded() );

		$this->assertNotEmpty( $order->get_meta( '_woo_wallet_refund_reconciliation_needed' ), 'The order must be flagged for manual reconciliation.' );

		$notes          = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$note_texts     = array_column( $notes, 'content' );
		$matching_notes = array_filter(
			$note_texts,
			function ( $content ) {
				return str_contains( $content, 'manual reconciliation' ) && str_contains( $content, 'do NOT retry' );
			}
		);
		$this->assertNotEmpty( $matching_notes, 'The order note must clearly warn against retrying the refund.' );
	}

	public function test_admin_refund_reconciliation_hook_fires_with_the_right_details() {
		wp_set_current_user( $this->admin_id );

		$gateway            = new Woo_Wallet_Test_Refundable_Gateway();
		$original_gateways  = WC_Payment_Gateways::instance()->payment_gateways;
		WC_Payment_Gateways::instance()->payment_gateways[] = $gateway;

		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_payment_method( $gateway );
		$order->set_total( 100 );
		$order->save();

		update_user_meta( $this->customer_id, '_is_wallet_locked', true );

		$fired = array();
		add_action(
			'woo_wallet_order_refund_needs_reconciliation',
			function ( $hook_order, $hook_refund, $hook_customer_id, $hook_amount ) use ( &$fired ) {
				$fired[] = array(
					'order_id'    => $hook_order->get_id(),
					'customer_id' => $hook_customer_id,
					'amount'      => $hook_amount,
				);
			},
			10,
			4
		);

		try {
			$this->_last_response      = '';
			$_POST['security']         = wp_create_nonce( 'order-item' );
			$_POST['order_id']         = $order->get_id();
			$_POST['refund_amount']    = '50.00';
			$_POST['refunded_amount']  = '0.00';
			$_POST['line_item_qtys']   = '{}';
			$_POST['line_item_totals'] = '{}';
			$_POST['api_refund']       = 'true';

			try {
				$this->_handleAjax( 'woo_wallet_order_refund' );
			} catch ( WPAjaxDieContinueException $e ) {
				// Expected.
			}
		} finally {
			WC_Payment_Gateways::instance()->payment_gateways = $original_gateways;
		}

		$this->assertCount( 1, $fired );
		$this->assertSame( $order->get_id(), $fired[0]['order_id'] );
		$this->assertSame( $this->customer_id, $fired[0]['customer_id'] );
		$this->assertSame( 50.0, (float) $fired[0]['amount'] );
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
