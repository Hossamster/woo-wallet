<?php
/**
 * Woo_Wallet_Cashback::calculate_cashback() — the pure calculation engine
 * behind the cashback reward program. This class never writes to the wallet
 * ledger itself (that happens in Woo_Wallet_Wallet::wallet_cashback(), see
 * WalletCashbackCreditTest.php); it only computes an amount from settings +
 * an order. Exercised via the order path (calculate_cashback(false, $id,
 * true)) rather than the cart path, since that needs a real WC()->cart
 * session rather than just an order object.
 */
class Cashback_Calculation_Test extends WP_UnitTestCase {

	private $customer_id;

	public function set_up() {
		parent::set_up();
		$this->customer_id = self::factory()->user->create();
		$this->set_credit_option( 'is_enable_cashback_reward_program', 'on' );
		$this->set_credit_option( 'cashback_rule', 'cart' );
		$this->set_credit_option( 'cashback_type', 'percent' );
		$this->set_credit_option( 'cashback_amount', 10 );
		$this->set_credit_option( 'max_cashback_amount', 0 );
		$this->set_credit_option( 'min_cart_amount', 0 );
		$this->set_credit_option( 'exclude_role', array() );
		$this->set_credit_option( 'max_cashback_scope', 'per_order' );
	}

	private function set_credit_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_credit', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_credit', $options );
	}

	private function create_order( $total, $overrides = array() ) {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_total( $total );
		foreach ( $overrides as $key => $value ) {
			$order->{"set_{$key}"}( $value );
		}
		$order->save();
		return $order;
	}

	private function cashback_for( $order ) {
		return (float) Woo_Wallet_Cashback::calculate_cashback( false, $order->get_id(), true );
	}

	public function test_returns_zero_when_the_program_is_disabled() {
		$this->set_credit_option( 'is_enable_cashback_reward_program', 'off' );
		$order = $this->create_order( 100 );
		$this->assertSame( 0.0, $this->cashback_for( $order ) );
	}

	public function test_returns_zero_for_a_customer_in_an_excluded_role() {
		$this->set_credit_option( 'exclude_role', array( 'customer' ) );
		wp_update_user( array( 'ID' => $this->customer_id, 'role' => 'customer' ) );

		$order = $this->create_order( 100 );
		$this->assertSame( 0.0, $this->cashback_for( $order ) );
	}

	public function test_percent_cashback_on_order_total() {
		$this->set_credit_option( 'cashback_type', 'percent' );
		$this->set_credit_option( 'cashback_amount', 10 );

		$order = $this->create_order( 200 );
		$this->assertSame( 20.0, $this->cashback_for( $order ) );
	}

	public function test_fixed_cashback_ignores_order_total() {
		$this->set_credit_option( 'cashback_type', 'fixed' );
		$this->set_credit_option( 'cashback_amount', 15 );

		$order = $this->create_order( 500 );
		$this->assertSame( 15.0, $this->cashback_for( $order ) );
	}

	public function test_below_minimum_cart_amount_earns_no_cashback() {
		$this->set_credit_option( 'min_cart_amount', 100 );

		$order = $this->create_order( 50 );
		$this->assertSame( 0.0, $this->cashback_for( $order ) );
	}

	public function test_at_or_above_minimum_cart_amount_earns_cashback() {
		$this->set_credit_option( 'min_cart_amount', 100 );
		$this->set_credit_option( 'cashback_amount', 10 );

		$order = $this->create_order( 100 );
		$this->assertSame( 10.0, $this->cashback_for( $order ) );
	}

	public function test_percent_cashback_is_capped_at_the_configured_maximum() {
		$this->set_credit_option( 'cashback_type', 'percent' );
		$this->set_credit_option( 'cashback_amount', 50 ); // 50% of 1000 = 500.
		$this->set_credit_option( 'max_cashback_amount', 30 );

		$order = $this->create_order( 1000 );
		$this->assertSame( 30.0, $this->cashback_for( $order ) );
	}

	public function test_zero_max_cashback_amount_means_uncapped() {
		$this->set_credit_option( 'cashback_type', 'percent' );
		$this->set_credit_option( 'cashback_amount', 50 );
		$this->set_credit_option( 'max_cashback_amount', 0 );

		$order = $this->create_order( 1000 );
		$this->assertSame( 500.0, $this->cashback_for( $order ) );
	}

	public function test_a_wallet_recharge_order_earns_no_cashback() {
		$recharge_product = get_wallet_rechargeable_product();
		$order            = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->add_product( $recharge_product, 1 );
		$order->set_total( 100 );
		$order->save();

		$this->assertSame( 0.0, $this->cashback_for( $order ) );
	}

	public function test_woo_wallet_form_order_cashback_amount_filter_can_override_the_result() {
		add_filter(
			'woo_wallet_form_order_cashback_amount',
			function () {
				return 42.0;
			}
		);

		$order = $this->create_order( 100 );
		$this->assertSame( 42.0, $this->cashback_for( $order ) );
	}
}
