<?php
/**
 * Cashback is issued when an order is Completed — delivered and paid — and
 * never earlier. It used to default to processing AND completed, so it could
 * be spent or withdrawn before an order that was then cancelled or returned,
 * leaving nothing to take back.
 */
class Cashback_On_Completed_Test extends WP_UnitTestCase {

	private $customer_id;

	public function set_up() {
		parent::set_up();
		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
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
			)
		);
	}

	private function order( $total = 100 ) {
		$order = wc_create_order( array( 'customer_id' => $this->customer_id ) );
		$order->set_total( $total );
		$order->save();
		return $order;
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	public function test_only_completed_triggers_cashback() {
		$this->assertSame( array( 'completed' ), WOO_Wallet_Helper::get_cashback_order_statuses() );
	}

	/**
	 * A site that saved the old setting (processing + completed) must not keep
	 * paying cashback early after the update.
	 */
	public function test_a_previously_saved_setting_no_longer_matters() {
		$options                            = get_option( '_wallet_settings_credit' );
		$options['process_cashback_status'] = array( 'processing', 'completed' );
		update_option( '_wallet_settings_credit', $options );

		$this->assertSame( array( 'completed' ), WOO_Wallet_Helper::get_cashback_order_statuses() );
	}

	public function test_a_filter_cannot_bring_back_the_early_statuses() {
		add_filter(
			'wallet_cashback_order_status',
			function () {
				return array( 'wc-processing', 'on-hold', 'pending', 'completed' );
			}
		);
		$this->assertSame( array( 'completed' ), WOO_Wallet_Helper::get_cashback_order_statuses() );
	}

	public function test_a_store_with_its_own_delivered_status_can_use_it() {
		add_filter(
			'wallet_cashback_order_status',
			function () {
				return array( 'wc-delivered' );
			}
		);
		$this->assertSame( array( 'delivered' ), WOO_Wallet_Helper::get_cashback_order_statuses() );
	}

	public function test_a_filter_that_leaves_nothing_falls_back_to_completed() {
		add_filter( 'wallet_cashback_order_status', '__return_empty_array' );
		$this->assertSame( array( 'completed' ), WOO_Wallet_Helper::get_cashback_order_statuses() );
		remove_all_filters( 'wallet_cashback_order_status' );
		add_filter(
			'wallet_cashback_order_status',
			function () {
				return array( 'processing' );
			}
		);
		$this->assertSame( array( 'completed' ), WOO_Wallet_Helper::get_cashback_order_statuses() );
	}

	public function test_an_order_in_processing_earns_nothing_until_it_is_completed() {
		$order = $this->order( 100 );

		$order->update_status( 'processing' );
		$this->assertSame( 0.0, $this->balance(), 'No cashback while the order is only being processed.' );

		$order->update_status( 'completed' );
		$this->assertSame( 10.0, $this->balance() );
	}

	public function test_an_order_cancelled_before_completion_never_earned_anything() {
		$order = $this->order( 100 );
		$order->update_status( 'processing' );
		$order->update_status( 'cancelled' );

		$this->assertSame( 0.0, $this->balance(), 'Nothing was paid, so there is nothing to take back.' );
	}

	/**
	 * Orders that already got their cashback while processing, before this
	 * change, must not be paid a second time when they complete.
	 */
	public function test_an_order_already_credited_while_processing_is_not_credited_again() {
		$order = $this->order( 100 );
		$order->set_status( 'processing' );
		$order->save();
		woo_wallet()->wallet->wallet_cashback( $order->get_id() );
		$this->assertSame( 10.0, $this->balance(), 'Precondition: credited under the old behaviour.' );

		wc_get_order( $order->get_id() )->update_status( 'completed' );

		$this->assertSame( 10.0, $this->balance() );
	}

	public function test_the_setting_is_gone_from_the_settings_screen() {
		if ( ! class_exists( 'Woo_Wallet_Settings' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-settings.php';
		}
		$fields = ( new Woo_Wallet_Settings( woo_wallet()->settings_api ) )->get_settings_fields();
		$names  = wp_list_pluck( (array) ( $fields['_wallet_settings_credit'] ?? array() ), 'name' );

		$this->assertContains( 'is_enable_cashback_reward_program', $names, 'Precondition: the cashback fields are there.' );
		$this->assertNotContains( 'process_cashback_status', $names );
	}
}
