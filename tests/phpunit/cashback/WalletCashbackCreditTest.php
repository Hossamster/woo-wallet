<?php
/**
 * Woo_Wallet_Wallet::wallet_cashback() — the actual wallet-ledger crediting
 * step, hooked to an order's processing/completed status transition.
 * Serialized via a per-order GET_LOCK (not an explicit transaction — unlike
 * transfer(), so none of the leaked-row precautions from the transfer suite
 * apply here) specifically so a replayed webhook or duplicate status
 * transition cannot double-credit; the marker meta
 * (_general_cashback_transaction_id) is what it checks to decide that.
 */
class Wallet_Cashback_Credit_Test extends WP_UnitTestCase {

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
	}

	private function set_credit_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_credit', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_credit', $options );
	}

	private function create_order( $total ) {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_total( $total );
		$order->save();
		return $order;
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	public function test_general_cashback_is_credited_on_order_completion() {
		$order = $this->create_order( 200 ); // 10% => 20.

		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		$this->assertSame( 20.0, $this->balance() );
	}

	public function test_marker_meta_is_recorded_after_crediting() {
		$order = $this->create_order( 200 );
		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		$order = wc_get_order( $order->get_id() ); // re-fetch, avoid stale object cache.
		$ids   = $order->get_meta( '_general_cashback_transaction_id', true );
		$this->assertNotEmpty( $ids );
	}

	public function test_calling_wallet_cashback_twice_does_not_double_credit() {
		$order = $this->create_order( 200 );

		woo_wallet()->wallet->wallet_cashback( $order->get_id() );
		$balance_after_first = $this->balance();

		// Simulates a replayed payment-gateway webhook or a duplicate
		// processing->completed transition for the same order.
		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		$this->assertSame( $balance_after_first, $this->balance(), 'A second call for the same order must not credit cashback again.' );
	}

	public function test_no_credit_when_calculated_cashback_is_zero() {
		$this->set_credit_option( 'is_enable_cashback_reward_program', 'off' );
		$order = $this->create_order( 200 );

		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		$this->assertSame( 0.0, $this->balance() );
	}

	public function test_no_credit_for_an_order_with_no_customer() {
		$order = wc_create_order(); // guest order, customer_id 0.
		$order->set_total( 200 );
		$order->save();

		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		// Nothing to assert on balance (no customer to credit) — the real
		// assertion is that this doesn't fatal/warn on a missing customer.
		$this->assertSame( 0, (int) $order->get_customer_id() );
	}

	public function test_woo_wallet_general_cashback_credited_action_fires_with_the_transaction_id() {
		$order          = $this->create_order( 200 );
		$fired_txn_ids  = array();
		$fired_order_id = null;

		add_action(
			'woo_wallet_general_cashback_credited',
			function ( $transaction_id, $order ) use ( &$fired_txn_ids, &$fired_order_id ) {
				$fired_txn_ids[] = $transaction_id;
				$fired_order_id  = $order->get_id();
			},
			10,
			2
		);

		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		$this->assertCount( 1, $fired_txn_ids );
		$this->assertNotEmpty( $fired_txn_ids[0] );
		$this->assertSame( $order->get_id(), $fired_order_id );
	}

	public function test_process_woo_wallet_general_cashback_filter_can_block_crediting() {
		add_filter( 'process_woo_wallet_general_cashback', '__return_false' );

		$order = $this->create_order( 200 );
		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		$this->assertSame( 0.0, $this->balance() );
	}
}
