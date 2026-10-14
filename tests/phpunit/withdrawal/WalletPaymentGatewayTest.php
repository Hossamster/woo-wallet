<?php
/**
 * Woo_Gateway_Wallet_payment::process_payment() — what happens around the
 * wallet debit, which only really happens inside
 * WC_Order::payment_complete() (woocommerce_pre_payment_complete).
 *
 * The balance check passes before that, and a concurrent order can spend
 * the balance in between. The order must then stay unpaid with its stock
 * untouched and the cart intact — and the customer must be told, rather
 * than being sent to a "thank you" page for an order that was never paid.
 */
class Wallet_Payment_Gateway_Test extends WP_UnitTestCase {

	private $customer_id;
	private $product;
	private $gateway;

	public function set_up() {
		parent::set_up();
		wc_clear_notices();
		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		woo_wallet()->wallet->credit( $this->customer_id, 500, 'test funding' );

		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Phone case' );
		$this->product->set_regular_price( 100 );
		$this->product->set_manage_stock( true );
		$this->product->set_stock_quantity( 10 );
		$this->product->save();

		$gateways      = WC()->payment_gateways()->payment_gateways();
		$this->gateway = $gateways['wallet'];
	}

	public function tear_down() {
		wc_clear_notices();
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		parent::tear_down();
	}

	private function order( $customer_id = null ) {
		$order = wc_create_order( array( 'customer_id' => null === $customer_id ? $this->customer_id : $customer_id ) );
		$order->add_product( $this->product, 1 );
		$order->set_payment_method( 'wallet' );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();
		return $order;
	}

	private function balance( $user_id = null ) {
		return (float) woo_wallet()->wallet->get_wallet_balance( null === $user_id ? $this->customer_id : $user_id, 'edit' );
	}

	private function stock() {
		return (int) wc_get_product( $this->product->get_id() )->get_stock_quantity();
	}

	private function load_cart_with_product() {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertFalse( WC()->cart->is_empty(), 'Precondition: the cart holds the product.' );
	}

	public function test_a_successful_wallet_payment_charges_reduces_stock_and_empties_the_cart() {
		$this->load_cart_with_product();
		$order = $this->order();

		wp_set_current_user( $this->customer_id );
		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 400.0, $this->balance() );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( 9, $this->stock() );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	/**
	 * Two orders, both seeing enough balance; the other one is paid in the
	 * gap before this order's debit.
	 */
	public function test_a_wallet_drained_before_the_debit_leaves_the_order_unpaid_with_stock_and_cart_intact() {
		$this->load_cart_with_product();
		$order = $this->order();
		wp_set_current_user( $this->customer_id );

		// Runs just before the gateway's own debit hook (priority 10).
		add_action(
			'woocommerce_pre_payment_complete',
			function () {
				woo_wallet()->wallet->debit( $this->customer_id, 500, 'the concurrent order', array( 'category' => 'purchase' ) );
			},
			1
		);

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'], 'The customer must not be sent to a thank-you page for an unpaid order.' );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 10, $this->stock(), 'Stock must not be reduced for an order that was not paid.' );
		$this->assertFalse( WC()->cart->is_empty(), 'The cart must survive so the customer can try again.' );
		$this->assertSame( 0.0, $this->balance(), 'Only the other order took the money.' );
		$errors = wc_get_notices( 'error' );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Payment error', $errors[0]['notice'] );
	}

	public function test_a_wallet_too_low_from_the_start_fails_before_anything_is_touched() {
		$this->load_cart_with_product();
		$poor  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order = $this->order( $poor );
		wp_set_current_user( $poor );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 10, $this->stock() );
		$this->assertFalse( WC()->cart->is_empty() );
		$this->assertStringContainsString( 'balance is low', wc_get_notices( 'error' )[0]['notice'] );
	}

	/**
	 * The debit takes the order customer's money, so that is the balance to
	 * check — not that of whoever is logged in.
	 */
	public function test_the_check_uses_the_orders_customer_not_the_logged_in_user() {
		$rich_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		woo_wallet()->wallet->credit( $rich_admin, 100000, 'admin funds' );
		$poor  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order = $this->order( $poor );
		wp_set_current_user( $rich_admin );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertStringContainsString( 'balance is low', wc_get_notices( 'error' )[0]['notice'] );
		$this->assertSame( 100000.0, $this->balance( $rich_admin ), 'The administrator\'s wallet is never involved.' );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_paying_on_a_customers_behalf_charges_the_customer_not_the_payer() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$order = $this->order();
		wp_set_current_user( $admin );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 400.0, $this->balance() );
		$this->assertSame( 0.0, $this->balance( $admin ) );
	}

	public function test_an_order_with_no_customer_is_refused_with_a_clear_message() {
		$order = $this->order( 0 );
		wp_set_current_user( $this->customer_id );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertStringContainsString( 'does not belong to a wallet account', wc_get_notices( 'error' )[0]['notice'] );
		$this->assertSame( 500.0, $this->balance() );
	}

	public function test_it_does_not_need_a_cart_to_complete_a_payment() {
		// An administrator paying from wp-admin / the REST API: no cart exists.
		WC()->cart = null;
		$order     = $this->order();
		wp_set_current_user( $this->customer_id );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		wc_load_cart();
	}
}
