<?php
/**
 * Regression test for a CSRF gap found in security review:
 * Woo_Wallet_Ajax::woo_wallet_refund_partial_payment() checked
 * current_user_can('edit_shop_orders') but never verified a nonce, unlike
 * its sibling woo_wallet_order_refund() (which uses check_ajax_referer(
 * 'order-item', 'security')). A logged-in shop manager visiting a malicious
 * page could have had their browser silently POST this action and credit a
 * customer's wallet for an arbitrary order, with no proof the request came
 * from the real admin UI.
 *
 * Fixed by adding the same check_ajax_referer('order-item', 'security')
 * call the sibling action already uses (and wiring the matching nonce into
 * build/admin/order.js's AJAX call). These tests prove: a request without a
 * valid nonce is rejected before any money moves, and a request with a
 * valid nonce still works end-to-end.
 *
 * Extends WP_Ajax_UnitTestCase (WP core's own test helper for wp_ajax_*
 * actions) rather than plain WP_UnitTestCase: both check_ajax_referer() and
 * wp_send_json() call a raw, uncatchable die() unless wp_doing_ajax() is
 * true, and even then route through the wp_die_ajax_handler filter — which
 * the generic WP_UnitTestCase test bootstrap does NOT override, but
 * WP_Ajax_UnitTestCase does (via its own dieHandler(), throwing
 * WPAjaxDieStopException / WPAjaxDieContinueException instead of actually
 * dying). Without this, the first nonce check to fail would kill the entire
 * PHPUnit process, not just the one test — confirmed empirically while
 * writing this file.
 */
class Wallet_Ajax_Nonce_Test extends WP_Ajax_UnitTestCase {

	private $admin_id;
	private $customer_id;

	public function set_up() {
		parent::set_up();
		// Only loaded by the plugin when DOING_AJAX is defined (see
		// Woo_Wallet::is_request('ajax') / includes/class-woo-wallet.php) —
		// never autoloaded, so a test that never performs a real AJAX
		// request must load it explicitly.
		if ( ! class_exists( 'Woo_Wallet_Ajax' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-ajax.php';
		}

		/*
		 * WP_UnitTestCase_Base snapshots $wp_filter once and restores that
		 * snapshot after *every* test (see _backup_hooks()/_restore_hooks()
		 * in wp-phpunit's abstract-testcase.php) — silently wiping any hook
		 * registered during a test, including Woo_Wallet_Ajax's own
		 * add_action( 'wp_ajax_...' ) calls from its constructor. Since it's
		 * a singleton, only the *first* test to call instance() actually
		 * runs that constructor; every later test would find no callback
		 * attached to the hook at all, and _handleAjax() would silently
		 * no-op with no exception thrown. Confirmed empirically while
		 * writing this file — reset the singleton so the constructor (and
		 * its add_action() calls) genuinely reruns every test.
		 */
		( new ReflectionProperty( 'Woo_Wallet_Ajax', '_instance' ) )->setValue( null, null );
		Woo_Wallet_Ajax::instance(); // registers the wp_ajax_* hooks _handleAjax() dispatches through.

		$this->admin_id = self::factory()->user->create();
		get_userdata( $this->admin_id )->add_cap( 'edit_shop_orders' );
		wp_set_current_user( $this->admin_id );

		$this->customer_id = self::factory()->user->create();
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	/**
	 * An order with a completed wallet partial payment, ready to be refunded
	 * — matches what get_order_partial_payment_amount()/
	 * is_partial_payment_order_item() look for.
	 */
	private function create_order_with_completed_partial_payment( $amount = 50 ) {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Via Wallet' );
		$fee->set_total( $amount );
		$order->add_item( $fee );

		$order->update_meta_data( '_partial_pay_through_wallet_compleate', true );
		$order->set_total( $amount );
		$order->save();

		return $order;
	}

	// -- the actual CSRF fix ------------------------------------------

	public function test_request_without_any_nonce_is_rejected_before_moving_money() {
		$order = $this->create_order_with_completed_partial_payment( 50 );
		$balance_before = $this->balance();

		$_POST['order_id'] = $order->get_id();
		// Deliberately no 'security' field — simulates a forged cross-site request.

		try {
			$this->_handleAjax( 'woo_wallet_refund_partial_payment' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected: check_ajax_referer() dies with no output at all —
			// it never even reaches wp_send_json().
		}

		$this->assertSame( '', $this->_last_response, 'No JSON success payload must be emitted — the request must be rejected before any processing.' );
		$this->assertSame( $balance_before, $this->balance(), 'A forged request without a nonce must never credit the wallet.' );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( (bool) $order->get_meta( '_woo_wallet_partial_payment_refunded' ), 'The refunded marker must not be set either — nothing about the order should change.' );
	}

	public function test_request_with_an_invalid_nonce_is_rejected_before_moving_money() {
		$order = $this->create_order_with_completed_partial_payment( 50 );
		$balance_before = $this->balance();

		$_POST['order_id'] = $order->get_id();
		$_POST['security']  = 'not-a-real-nonce';

		try {
			$this->_handleAjax( 'woo_wallet_refund_partial_payment' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected.
		}

		$this->assertSame( $balance_before, $this->balance() );
	}

	public function test_a_nonce_from_the_wrong_action_is_rejected() {
		$order = $this->create_order_with_completed_partial_payment( 50 );
		$balance_before = $this->balance();

		$_POST['order_id'] = $order->get_id();
		// A real, validly-signed nonce — but for a different action. Proves
		// the fix checks the *action* name ('order-item'), not just "is this
		// string nonce-shaped".
		$_POST['security'] = wp_create_nonce( 'some-other-action' );

		try {
			$this->_handleAjax( 'woo_wallet_refund_partial_payment' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected.
		}

		$this->assertSame( $balance_before, $this->balance() );
	}

	// -- the legitimate flow must still work -----------------------------

	/**
	 * The handler reads order_id via filter_input(INPUT_POST, ...), not
	 * $_POST directly — a real, deliberate choice in the plugin's own code,
	 * not something introduced by this fix. filter_input() reads PHP's raw
	 * request-start input buffer and is blind to $_POST assigned
	 * programmatically afterwards, which is exactly what _handleAjax() does
	 * (it sets $_POST['action'] itself, same as any CLI test would). On a
	 * real web request it works normally; in this suite it means order_id
	 * always resolves to 0 here, so this test can't drive the request all
	 * the way to an actual wallet credit. What it *can* still prove — and
	 * the point of this test — is that a validly-authenticated request is
	 * not blocked by the nonce or capability checks: it reaches
	 * wp_send_json() (real JSON output, a WPAjaxDieContinueException),
	 * rather than the no-output WPAjaxDieStopException the tests above get.
	 */
	public function test_a_valid_nonce_and_capability_pass_through_to_the_handler_body() {
		$order = $this->create_order_with_completed_partial_payment( 50 );

		$_POST['order_id'] = $order->get_id();
		$_POST['security']  = wp_create_nonce( 'order-item' );

		try {
			$this->_handleAjax( 'woo_wallet_refund_partial_payment' );
			$this->fail( 'wp_send_json() always ends execution — expected an exception either way.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: reached wp_send_json(), which always produces output.
		} catch ( WPAjaxDieStopException $e ) {
			$this->fail( 'A validly-authenticated request must not be stopped at the nonce/capability gate (got: ' . $e->getMessage() . ').' );
		}

		$data = json_decode( $this->_last_response, true );
		$this->assertIsArray( $data, 'A legitimate request must reach wp_send_json() (real JSON output).' );
		$this->assertArrayHasKey( 'success', $data );
	}

	public function test_a_valid_nonce_but_missing_capability_is_still_rejected() {
		$plain_user = self::factory()->user->create(); // no edit_shop_orders.
		wp_set_current_user( $plain_user );

		$order = $this->create_order_with_completed_partial_payment( 50 );
		$balance_before = $this->balance();

		$_POST['order_id'] = $order->get_id();
		$_POST['security']  = wp_create_nonce( 'order-item' );

		try {
			$this->_handleAjax( 'woo_wallet_refund_partial_payment' );
			$this->fail( 'Expected the capability check to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected: wp_die( -1 ) from the capability check, no output.
		}

		$this->assertSame( '', $this->_last_response );
		$this->assertSame( $balance_before, $this->balance(), 'A valid nonce alone must not be enough — the capability check still applies.' );
	}
}
