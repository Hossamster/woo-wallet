<?php
/**
 * Woo_Wallet_Cashback_Refund — cashback is deducted from the refund itself
 * rather than taken back from the customer's wallet, so it never depends on
 * a balance they may have spent, withdrawn, or tied up in a withdrawal.
 *
 *   paid 100, earned 10 → returns it → 90 refunded, 10 recorded as a separate
 *   "Cashback adjustment" refund that is not paid out.
 */
if ( ! class_exists( 'Woo_Wallet_Test_Capturing_Gateway' ) ) {
	/**
	 * A gateway that records what it is asked to pay out.
	 */
	class Woo_Wallet_Test_Capturing_Gateway extends WC_Payment_Gateway {
		public static $paid   = array();
		public static $result = true;

		public function __construct() {
			$this->id           = 'wwtest_capture';
			$this->method_title = 'WW Test Capturing Gateway';
			$this->supports     = array( 'refunds' );
		}

		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			self::$paid[] = (float) $amount;
			return self::$result;
		}
	}
}

class Cashback_Refund_Deduction_Test extends WP_Ajax_UnitTestCase {

	private $customer_id;
	private $admin_id;
	private $original_gateways;

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		Woo_Wallet_Test_Capturing_Gateway::$paid   = array();
		Woo_Wallet_Test_Capturing_Gateway::$result = true;

		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $this->admin_id )->add_cap( 'edit_shop_orders' );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		update_option(
			'_wallet_settings_credit',
			array(
				'is_enable_cashback_reward_program' => 'on',
				'cashback_rule'                     => 'cart',
				'cashback_type'                     => 'percent',
				'cashback_amount'                   => 10,
				'max_cashback_amount'               => 0,
				'min_cart_amount'                   => 0,
				'exclude_role'                      => array(),
				'max_cashback_scope'                => 'per_order',
				'cashback_deduct_from_refund'       => 'on',
			)
		);

		if ( ! class_exists( 'Woo_Wallet_Ajax' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-ajax.php';
		}
		( new ReflectionProperty( 'Woo_Wallet_Ajax', '_instance' ) )->setValue( null, null );
		Woo_Wallet_Ajax::instance();

		$this->original_gateways                            = WC_Payment_Gateways::instance()->payment_gateways;
		WC_Payment_Gateways::instance()->payment_gateways[] = new Woo_Wallet_Test_Capturing_Gateway();
	}

	public function tear_down() {
		WC_Payment_Gateways::instance()->payment_gateways = $this->original_gateways;
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function set_credit_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_credit', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_credit', $options );
	}

	/**
	 * A completed order that earned 10% cashback (cashback is only paid once
	 * an order is Completed).
	 */
	private function completed_order( $total = 100 ) {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_payment_method( 'wwtest_capture' );
		$order->set_total( $total );
		$order->save();
		$order->update_status( 'completed' );
		$this->assertEqualsWithDelta( $total / 10, (float) get_total_order_cashback_amount( $order->get_id() ), 0.01, 'Precondition: the order earned its cashback.' );
		return wc_get_order( $order->get_id() );
	}

	private function refund( $order, $amount, $pay = false ) {
		$refund = wc_create_refund(
			array(
				'amount'         => $amount,
				'reason'         => 'returned',
				'order_id'       => $order->get_id(),
				'refund_payment' => $pay,
			)
		);
		$this->assertNotWPError( $refund );
		return $refund;
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	// -- the core rule ----------------------------------------------------------

	public function test_a_full_return_refunds_the_price_less_the_cashback() {
		$order = $this->completed_order( 100 );

		$refund = $this->refund( $order, 100, true );

		$this->assertSame( 90.0, (float) $refund->get_amount(), 'What goes back to the customer.' );
		$this->assertSame( array( 90.0 ), Woo_Wallet_Test_Capturing_Gateway::$paid, 'The gateway is asked to pay 90, not 100.' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 100.0, (float) $order->get_total_refunded(), 'The order is fully refunded: 90 paid out + 10 adjustment.' );
		$this->assertSame( 0.0, (float) $order->get_remaining_refund_amount(), 'Nothing is left to refund — the 10 cannot be refunded again.' );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( 10.0, (float) $order->get_meta( Woo_Wallet_Cashback_Refund::ORDER_RECOVERED_META ) );

		$refunds = $order->get_refunds();
		$this->assertCount( 2, $refunds );
		$amounts = array_map(
			function ( $r ) {
				return (float) $r->get_amount();
			},
			$refunds
		);
		sort( $amounts );
		$this->assertSame( array( 10.0, 90.0 ), $amounts );
	}

	/**
	 * The customer keeps the 10 they were given, whether or not they spent it:
	 * what they take out of the order is exactly the 100 they paid.
	 */
	public function test_it_does_not_depend_on_what_is_left_in_the_wallet() {
		$order = $this->completed_order( 100 );
		woo_wallet()->wallet->debit( $this->customer_id, 10, 'spent the cashback' );
		$this->assertSame( 0.0, $this->balance(), 'Precondition: nothing left in the wallet.' );

		$refund = $this->refund( $order, 100, true );

		$this->assertSame( 90.0, (float) $refund->get_amount() );
		$this->assertSame( 0.0, $this->balance(), 'Nothing was taken from the wallet — and nothing needed to be.' );
	}

	public function test_the_cashback_is_still_deducted_when_it_is_tied_up_in_a_withdrawal() {
		$order = $this->completed_order( 100 );
		update_option( '_wallet_settings_withdrawal', array( 'is_enable_wallet_withdrawal' => 'on', 'withdrawal_charge_type' => 'fixed', 'withdrawal_charge_amount' => 0 ) );
		$withdrawal = Woo_Wallet_Withdrawal::admin_create( $this->customer_id, 10, array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ), 'Ahmed', '1234567890', '01012345678', '', $this->admin_id, 'pending' );
		$this->assertTrue( $withdrawal['is_valid'], 'Precondition: the whole balance is reserved.' );
		$this->assertSame( 0.0, $this->balance() );

		$refund = $this->refund( $order, 100, true );

		$this->assertSame( 90.0, (float) $refund->get_amount() );
	}

	// -- partial refunds ----------------------------------------------------------

	public function test_a_partial_refund_deducts_its_share_and_the_last_one_takes_the_rest() {
		$order = $this->completed_order( 100 );

		$first = $this->refund( $order, 50, true );
		$this->assertSame( 45.0, (float) $first->get_amount() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 5.0, (float) $order->get_meta( Woo_Wallet_Cashback_Refund::ORDER_RECOVERED_META ) );
		$this->assertNotSame( 'refunded', $order->get_status() );
		$this->assertSame( 50.0, (float) $order->get_remaining_refund_amount() );

		$second = $this->refund( $order, 50, true );
		$this->assertSame( 45.0, (float) $second->get_amount() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 10.0, (float) $order->get_meta( Woo_Wallet_Cashback_Refund::ORDER_RECOVERED_META ), 'Never more than the cashback earned.' );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( array( 45.0, 45.0 ), Woo_Wallet_Test_Capturing_Gateway::$paid );
	}

	public function test_rounding_never_leaves_cashback_behind_on_the_final_refund() {
		$this->set_credit_option( 'cashback_amount', 10 );
		$order = $this->completed_order( 100.01 );
		$cashback = (float) get_total_order_cashback_amount( $order->get_id() );

		$this->refund( $order, 33.33, true );
		$this->refund( wc_get_order( $order->get_id() ), 33.33, true );
		$this->refund( wc_get_order( $order->get_id() ), 33.35, true );

		$this->assertEqualsWithDelta( $cashback, (float) wc_get_order( $order->get_id() )->get_meta( Woo_Wallet_Cashback_Refund::ORDER_RECOVERED_META ), 0.0001 );
		$this->assertEqualsWithDelta( 100.01 - $cashback, array_sum( Woo_Wallet_Test_Capturing_Gateway::$paid ), 0.0001, 'The customer is refunded exactly what they paid, less the cashback.' );
	}

	// -- failure -------------------------------------------------------------------

	public function test_a_gateway_failure_leaves_no_adjustment_behind() {
		$order = $this->completed_order( 100 );
		Woo_Wallet_Test_Capturing_Gateway::$result = new WP_Error( 'declined', 'Gateway declined' );

		$result = wc_create_refund(
			array(
				'amount'         => 100,
				'order_id'       => $order->get_id(),
				'refund_payment' => true,
			)
		);

		$this->assertWPError( $result );
		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $order->get_refunds(), 'No refund, and no stray cashback adjustment.' );
		$this->assertSame( 0.0, (float) $order->get_total_refunded() );
		$this->assertEmpty( $order->get_meta( Woo_Wallet_Cashback_Refund::ORDER_RECOVERED_META ) );

		// The retry that follows is deducted exactly once.
		Woo_Wallet_Test_Capturing_Gateway::$result = true;
		$retry = $this->refund( $order, 100, true );
		$this->assertSame( 90.0, (float) $retry->get_amount() );
		$this->assertSame( 10.0, (float) wc_get_order( $order->get_id() )->get_meta( Woo_Wallet_Cashback_Refund::ORDER_RECOVERED_META ) );
	}

	// -- when it does not apply ------------------------------------------------------

	public function test_it_is_off_unless_enabled() {
		$this->set_credit_option( 'cashback_deduct_from_refund', 'no' );
		$order = $this->completed_order( 100 );

		$refund = $this->refund( $order, 100, true );

		$this->assertSame( 100.0, (float) $refund->get_amount() );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	public function test_an_order_that_earned_no_cashback_is_refunded_in_full() {
		$this->set_credit_option( 'cashback_amount', 0 );
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_payment_method( 'wwtest_capture' );
		$order->set_total( 100 );
		$order->save();
		$order->update_status( 'completed' );

		$refund = $this->refund( $order, 100, true );

		$this->assertSame( 100.0, (float) $refund->get_amount() );
	}

	public function test_a_guest_order_is_refunded_in_full() {
		$order = wc_create_order( array( 'customer_id' => 0 ) );
		$order->set_payment_method( 'wwtest_capture' );
		$order->set_total( 100 );
		$order->save();

		$this->assertSame( 100.0, (float) $this->refund( $order, 100, true )->get_amount() );
	}

	public function test_a_marketplace_can_switch_it_off_for_a_refund() {
		add_filter( 'woo_wallet_cashback_refund_clawback_amount', '__return_zero' );
		$order = $this->completed_order( 100 );

		$this->assertSame( 100.0, (float) $this->refund( $order, 100, true )->get_amount() );
	}

	public function test_the_older_wallet_clawback_is_not_applied_on_top() {
		$this->set_credit_option( 'cashback_clawback_on_refund', 'on' );
		$order = $this->completed_order( 100 );
		$this->assertSame( 10.0, $this->balance() );

		$this->refund( $order, 100, true );

		$this->assertSame( 10.0, $this->balance(), 'The 10 is deducted from the refund; taking it from the wallet too would recover it twice.' );
	}

	// -- the wallet refund screen ----------------------------------------------------

	/**
	 * "Refund to wallet" credited the amount typed in, not what the refund
	 * actually pays out — that would hand the cashback back.
	 */
	public function test_refund_to_wallet_credits_what_the_refund_pays_out() {
		wp_set_current_user( $this->admin_id );
		$order  = $this->completed_order( 100 );
		$before = $this->balance();

		$this->_last_response      = '';
		$_POST['security']         = wp_create_nonce( 'order-item' );
		$_POST['order_id']         = $order->get_id();
		$_POST['refund_amount']    = '100.00';
		$_POST['refunded_amount']  = '0.00';
		$_POST['line_item_qtys']   = '{}';
		$_POST['line_item_totals'] = '{}';
		try {
			$this->_handleAjax( 'woo_wallet_order_refund' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], $this->_last_response );
		$this->assertSame( $before + 90.0, $this->balance(), 'The wallet gets 90, not 100.' );
		$this->assertSame( 100.0, (float) wc_get_order( $order->get_id() )->get_total_refunded() );
	}

	// -- deleting refunds -------------------------------------------------------------

	public function test_deleting_the_refund_takes_its_adjustment_with_it() {
		$order  = $this->completed_order( 100 );
		$refund = $this->refund( $order, 100, true );
		$id     = $refund->get_id();

		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $id, $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( 0.0, (float) $order->get_meta( Woo_Wallet_Cashback_Refund::ORDER_RECOVERED_META ) );
		$this->assertSame( 100.0, (float) $order->get_remaining_refund_amount() );
	}

	public function test_deleting_only_the_adjustment_is_logged() {
		$order  = $this->completed_order( 100 );
		$refund = $this->refund( $order, 100, true );
		$pair   = wc_get_order( $order->get_id() )->get_meta( Woo_Wallet_Cashback_Refund::ORDER_PAIRS_META );
		$this->assertArrayHasKey( $refund->get_id(), $pair, 'Precondition: the refund is paired with its adjustment.' );
		$adj_id = (int) $pair[ $refund->get_id() ]['adjustment'];

		wc_get_order( $adj_id )->delete( true );
		do_action( 'woocommerce_refund_deleted', $adj_id, $order->get_id() );

		global $wpdb;
		$events = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Woo_Wallet_Audit::table() . ' WHERE event = %s', Woo_Wallet_Audit::EVENT_CASHBACK_ADJUSTMENT_DELETED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertCount( 1, $events );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'deleted on its own', $notes );
	}

	// -- bookkeeping --------------------------------------------------------------------

	public function test_the_order_gets_a_note_and_the_adjustment_refund_is_labelled() {
		$order = $this->completed_order( 100 );
		$this->refund( $order, 100, true );

		$order = wc_get_order( $order->get_id() );
		$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'Cashback adjustment', $notes );
		$reasons = implode(
			' ',
			array_map(
				function ( $r ) {
					return $r->get_reason();
				},
				$order->get_refunds()
			)
		);
		$this->assertStringContainsString( 'not paid out', $reasons );
	}

	public function test_the_customer_is_sent_one_refund_email_not_two() {
		WC()->mailer();
		$order = $this->completed_order( 100 );
		$order->set_billing_email( 'ahmed@example.com' );
		$order->save();
		reset_phpmailer_instance();

		$this->refund( wc_get_order( $order->get_id() ), 100, true );

		$subjects = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			foreach ( $mail['to'] as $recipient ) {
				if ( 'ahmed@example.com' === $recipient[0] ) {
					$subjects[] = $mail['subject'];
				}
			}
		}
		$refund_emails = array_filter(
			$subjects,
			function ( $subject ) {
				return false !== stripos( $subject, 'refund' );
			}
		);
		$this->assertCount( 1, $refund_emails, 'One refund email — the cashback adjustment must not send a second. Sent: ' . implode( ' | ', $subjects ) );
	}

	// -- wallet-paid orders ----------------------------------------------------------------

	public function test_a_wallet_paid_order_gets_back_what_the_refund_pays_out() {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_payment_method( 'wallet' );
		$order->set_total( 100 );
		$order->save();
		$order->update_status( 'completed' );
		$before = $this->balance();

		$this->refund( wc_get_order( $order->get_id() ), 100, true );

		$this->assertEqualsWithDelta( $before + 90.0, $this->balance(), 0.001, 'The wallet gateway pays out 90, not 100.' );
	}

	/**
	 * The adjustment is bookkeeping, not money going back — it must not
	 * trigger a second proportional wallet refund of a partial payment.
	 */
	public function test_the_adjustment_refund_does_not_refund_the_wallet_portion_a_second_time() {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_payment_method( 'wwtest_capture' );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Via Wallet' );
		$fee->set_total( 40 );
		$order->add_item( $fee );
		$order->update_meta_data( '_partial_pay_through_wallet_compleate', true );
		$order->set_total( 100 );
		$order->save();
		$order->update_status( 'completed' );

		$credits = function () {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE user_id = %d AND type = 'credit' AND details LIKE %s", $this->customer_id, '%efund%' ) );
		};
		$before = $credits();

		$this->refund( wc_get_order( $order->get_id() ), 100, false );

		$this->assertSame( 1, $credits() - $before, 'One proportional wallet refund — for the refund, not for its cashback adjustment too.' );
	}
}
