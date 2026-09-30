<?php
/**
 * Woo_Wallet_Wallet::process_partial_payment_refund() — the automatic
 * partial-payment refund hooked onto `woocommerce_order_refunded`, fired
 * whenever ANY refund (gateway or manual) is created on an order that had a
 * completed wallet partial payment.
 *
 * Before this fix, a failed wallet credit here still left the claim in
 * place: `_woo_wallet_partial_refunded_total` stayed inflated by an amount
 * never actually credited, and the refund id was permanently recorded as
 * processed — silently and permanently diverging the order's own
 * bookkeeping from the wallet ledger, with no route back to correct it
 * (the next legitimate refund on the order would be short-changed by the
 * phantom already-refunded amount). These tests prove the rollback closes
 * that gap.
 */
class Partial_Refund_Auto_Rollback_Test extends WP_UnitTestCase {

	private $customer_id;

	public function set_up() {
		parent::set_up();
		$this->customer_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->customer_id, 1000, 'test funding' );
	}

	/**
	 * A completed wallet partial-payment order: a "Via Wallet" fee line
	 * (what get_order_partial_payment_amount()/is_partial_payment_order_item()
	 * look for) plus the completion marker — same shape as
	 * WalletAjaxNonceTest::create_order_with_completed_partial_payment().
	 */
	private function create_partial_payment_order( $via_wallet_amount, $order_total ) {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Via Wallet' );
		$fee->set_total( $via_wallet_amount );
		$order->add_item( $fee );

		$order->update_meta_data( '_partial_pay_through_wallet_compleate', true );
		$order->set_total( $order_total );
		$order->save();

		return $order;
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	public function test_failed_credit_rolls_back_the_refunded_total_and_processed_id() {
		$order = $this->create_partial_payment_order( 40, 100 );

		update_user_meta( $this->customer_id, '_is_wallet_locked', true );
		$balance_before = $this->balance();

		// wc_create_refund() fires woocommerce_order_refunded itself, which
		// is exactly what triggers process_partial_payment_refund() in real
		// use (a gateway or manual order refund), so this exercises the real
		// hook path end to end rather than calling the method directly.
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 50, // 50% of order total -> 50% of the 40 via-wallet amount = 20.
			)
		);
		$this->assertFalse( is_wp_error( $refund ) );

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 0.0, (float) $order->get_meta( '_woo_wallet_partial_refunded_total' ), 'A failed credit must not leave the refunded total inflated.' );
		$this->assertNotContains( (string) $refund->get_id(), (array) $order->get_meta( '_woo_wallet_partial_refund_ids' ), 'A failed credit must not be recorded as processed — it would be skipped forever otherwise.' );
		$this->assertEmpty( $order->get_meta( '_woo_wallet_partial_payment_refunded' ) );
		$this->assertSame( $balance_before, $this->balance(), 'No credit must have actually landed.' );

		$notes      = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$note_texts = array_column( $notes, 'content' );
		$this->assertNotEmpty(
			array_filter(
				$note_texts,
				function ( $content ) {
					return str_contains( $content, 'rolled back' );
				}
			)
		);
	}

	/**
	 * The real-world consequence of the bug: without the rollback, a later
	 * genuine refund on the same order would be capped against a
	 * refunded-total that included the phantom, never-credited amount from
	 * the failed attempt — silently under-crediting the customer a second
	 * time. Confirms the rollback actually restores correct behaviour, not
	 * just correct-looking meta.
	 */
	public function test_a_later_successful_refund_is_not_short_changed_by_the_failed_ones_phantom_total() {
		$order = $this->create_partial_payment_order( 40, 100 );

		update_user_meta( $this->customer_id, '_is_wallet_locked', true );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 50, // Fails: wallet locked. Would-be credit: 20.
			)
		);

		update_user_meta( $this->customer_id, '_is_wallet_locked', false );
		$balance_before = $this->balance();

		$second_refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 50, // The remaining 50% of the order -> the other 20 of the via-wallet amount.
			)
		);
		$this->assertFalse( is_wp_error( $second_refund ) );

		// If the failed attempt's 20 had stuck around in
		// _woo_wallet_partial_refunded_total, this credit would have been
		// capped to via_wallet(40) - already(20) = 20 as well by coincidence
		// here — so assert the actual ledger credit directly, not just the
		// meta, to prove the full 20 for *this* refund event landed.
		$this->assertSame( $balance_before + 20.0, $this->balance() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 20.0, (float) $order->get_meta( '_woo_wallet_partial_refunded_total' ) );
		$this->assertContains( (string) $second_refund->get_id(), (array) $order->get_meta( '_woo_wallet_partial_refund_ids' ) );
	}

	public function test_successful_credit_marks_fully_refunded_when_the_wallet_amount_is_exhausted() {
		$order = $this->create_partial_payment_order( 40, 100 );

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 100, // Full order refund -> the whole 40 via-wallet amount.
			)
		);
		$this->assertFalse( is_wp_error( $refund ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 40.0, (float) $order->get_meta( '_woo_wallet_partial_refunded_total' ) );
		$this->assertNotEmpty( $order->get_meta( '_woo_wallet_partial_payment_refunded' ) );
	}

	public function test_a_fully_refunding_failed_credit_does_not_leave_the_fully_refunded_flag_set() {
		$order = $this->create_partial_payment_order( 40, 100 );

		update_user_meta( $this->customer_id, '_is_wallet_locked', true );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 100, // Would exhaust the via-wallet amount, if it had succeeded.
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertEmpty( $order->get_meta( '_woo_wallet_partial_payment_refunded' ), 'The flag must not be left set by an attempt whose credit failed.' );
	}
}
