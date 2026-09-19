<?php
/**
 * Cashback clawback on order cancellation — Woo_Wallet_Wallet::
 * process_cancelled_order() / execute_cashback_clawback(). This is the
 * money-safety heart of the cashback feature: when a cashback-earning order
 * is cancelled, the credited cashback must be reversed according to one of
 * three configurable strategies (the customer may have already spent it):
 *   - 'partial' (default): debit whatever balance is available, note the rest.
 *   - 'full_or_skip': reverse the full amount, or nothing at all.
 *   - 'force_negative': always reverse in full, allowing a negative balance
 *     (opt-in via cashback_clawback_allow_negative).
 */
class Cashback_Clawback_Test extends WP_UnitTestCase {

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
		$this->set_credit_option( 'cashback_clawback_allow_negative', 'off' );
	}

	private function set_credit_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_credit', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_credit', $options );
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	/**
	 * Create an order, credit its cashback, and return it — the common setup
	 * for every clawback scenario ("the cashback was already earned").
	 */
	private function create_order_with_credited_cashback( $total ) {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_total( $total );
		$order->save();

		woo_wallet()->wallet->wallet_cashback( $order->get_id() );

		return wc_get_order( $order->get_id() ); // re-fetch: pick up the meta wallet_cashback() just wrote.
	}

	// -- 'partial' strategy (default) --------------------------------------

	public function test_partial_strategy_reverses_full_cashback_when_untouched() {
		$order = $this->create_order_with_credited_cashback( 200 ); // 10% => 20 credited.
		$this->assertSame( 20.0, $this->balance() );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( 0.0, $this->balance() );
	}

	public function test_partial_strategy_reverses_only_what_remains_when_some_was_spent() {
		$order = $this->create_order_with_credited_cashback( 200 ); // 20 credited.
		woo_wallet()->wallet->debit( $this->customer_id, 15, 'customer spent most of it' );
		$this->assertSame( 5.0, $this->balance() );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( 0.0, $this->balance(), 'Partial strategy debits whatever is left, never goes negative.' );
	}

	public function test_partial_strategy_records_the_unreversed_amount_when_nothing_is_left() {
		$order = $this->create_order_with_credited_cashback( 200 ); // 20 credited.
		woo_wallet()->wallet->debit( $this->customer_id, 20, 'customer spent it all' );
		$this->assertSame( 0.0, $this->balance() );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( 0.0, $this->balance() );
		$order = wc_get_order( $order->get_id() );
		$this->assertEqualsWithDelta( 20.0, (float) $order->get_meta( '_cashback_unreversed_amount' ), 0.001 );
	}

	// -- 'full_or_skip' strategy --------------------------------------

	public function test_full_or_skip_reverses_the_full_amount_when_available() {
		add_filter(
			'woo_wallet_cashback_clawback_strategy',
			function () {
				return 'full_or_skip';
			}
		);

		$order = $this->create_order_with_credited_cashback( 200 ); // 20 credited.
		woo_wallet()->wallet->credit( $this->customer_id, 100, 'unrelated top-up' );
		$this->assertSame( 120.0, $this->balance() );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( 100.0, $this->balance() );
	}

	public function test_full_or_skip_reverses_nothing_when_balance_is_insufficient() {
		add_filter(
			'woo_wallet_cashback_clawback_strategy',
			function () {
				return 'full_or_skip';
			}
		);

		$order = $this->create_order_with_credited_cashback( 200 ); // 20 credited.
		woo_wallet()->wallet->debit( $this->customer_id, 15, 'customer spent most of it' );
		$this->assertSame( 5.0, $this->balance() );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( 5.0, $this->balance(), 'full_or_skip must not partially debit — leaves the balance untouched.' );
	}

	// -- 'force_negative' strategy --------------------------------------

	public function test_force_negative_is_downgraded_to_partial_when_not_opted_in() {
		add_filter(
			'woo_wallet_cashback_clawback_strategy',
			function () {
				return 'force_negative';
			}
		);
		// cashback_clawback_allow_negative left 'off' (the set_up() default).

		$order = $this->create_order_with_credited_cashback( 200 ); // 20 credited.
		woo_wallet()->wallet->debit( $this->customer_id, 20, 'customer spent it all' );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( 0.0, $this->balance(), 'Without explicit opt-in, force_negative must behave like partial (never negative).' );
	}

	public function test_force_negative_drives_the_balance_negative_when_opted_in() {
		$this->set_credit_option( 'cashback_clawback_allow_negative', 'on' );
		add_filter(
			'woo_wallet_cashback_clawback_strategy',
			function () {
				return 'force_negative';
			}
		);

		$order = $this->create_order_with_credited_cashback( 200 ); // 20 credited.
		woo_wallet()->wallet->debit( $this->customer_id, 20, 'customer spent it all' );
		$this->assertSame( 0.0, $this->balance() );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( -20.0, $this->balance(), 'force_negative (opted in) must reverse in full even below zero.' );
	}

	// -- idempotency / no-op cases ------------------------------------

	public function test_cancelling_an_order_with_no_cashback_does_nothing() {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_total( 200 );
		$order->save();
		// Never credited — no _general_cashback_transaction_id meta at all.

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		$this->assertSame( 0.0, $this->balance() );
	}

	public function test_clawback_debit_is_tagged_with_the_refund_category() {
		$order = $this->create_order_with_credited_cashback( 200 );

		woo_wallet()->wallet->process_cancelled_order( $order->get_id() );

		global $wpdb;
		$category = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT category FROM {$wpdb->prefix}woo_wallet_transactions WHERE user_id = %d AND type = 'debit' ORDER BY transaction_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->customer_id
			)
		);
		$this->assertSame( 'refund', $category );
	}
}
