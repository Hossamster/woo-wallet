<?php
require_once __DIR__ . '/class-transfer-test-case.php';

/**
 * WooWallet_Transfer_Service::execute() — the shared validation +
 * charge-calculation layer the frontend transfer form and the
 * POST /me/transfer REST route both call, so the two surfaces can never
 * drift on rules (rate limit, min/max amount, charge type).
 */
class Transfer_Service_Test extends Transfer_Test_Case {

	private $from_id;
	private $to_id;

	public function set_up() {
		parent::set_up();
		$this->from_id = self::factory()->user->create();
		$this->to_id   = self::factory()->user->create();
		$this->track_users( $this->from_id, $this->to_id );

		woo_wallet()->wallet->credit( $this->from_id, 1000, 'test funding' );

		$this->set_general_option( 'min_transfer_amount', 0 );
		$this->set_general_option( 'max_transfer_amount', 0 );
		$this->set_general_option( 'transfer_charge_type', 'percent' );
		$this->set_general_option( 'transfer_charge_amount', 0 );
	}

	/**
	 * Woo_Wallet_Settings_API stores every field for a section under one
	 * serialized WP option — same pattern used for withdrawal settings in
	 * the withdrawal test suite.
	 */
	private function set_general_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_general', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_general', $options );
	}

	private function balance( $user_id ) {
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}

	public function test_rejects_logged_out_sender() {
		$result = WooWallet_Transfer_Service::execute( 0, $this->to_id, 10 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_not_logged_in', $result['code'] );
	}

	public function test_rejects_zero_or_negative_amount() {
		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 0 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_invalid_amount', $result['code'] );

		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, -5 );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_transfer_to_self() {
		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->from_id, 10 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_invalid_recipient', $result['code'] );
	}

	public function test_rejects_nonexistent_recipient() {
		$result = WooWallet_Transfer_Service::execute( $this->from_id, 999999, 10 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_invalid_recipient', $result['code'] );
	}

	public function test_rejects_malformed_currency_code() {
		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 10, '', 'usd1' );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_invalid_currency', $result['code'] );
	}

	public function test_rejects_amount_below_configured_minimum() {
		$this->set_general_option( 'min_transfer_amount', 50 );
		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 10 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_amount_below_minimum', $result['code'] );
	}

	public function test_rejects_amount_above_configured_maximum() {
		$this->set_general_option( 'max_transfer_amount', 100 );
		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 500 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_amount_above_maximum', $result['code'] );
	}

	public function test_rejects_amount_greater_than_balance() {
		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 5000 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_insufficient_balance', $result['code'] );
		$this->assertSame( 422, $result['status'] );
	}

	public function test_successful_transfer_with_no_charge() {
		$balance_before = $this->balance( $this->from_id );

		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 100 );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( 0.0, $result['charge'] );
		$this->assertSame( $balance_before - 100.0, $this->balance( $this->from_id ) );
		$this->assertSame( 100.0, $this->balance( $this->to_id ) );
	}

	public function test_percent_charge_is_added_to_the_debit_not_the_credit() {
		$this->set_general_option( 'transfer_charge_type', 'percent' );
		$this->set_general_option( 'transfer_charge_amount', 10 ); // 10%.

		$balance_before = $this->balance( $this->from_id );
		$result         = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 100 );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( 10.0, $result['charge'] );
		$this->assertSame( $balance_before - 110.0, $this->balance( $this->from_id ) ); // 100 + 10% charge.
		$this->assertSame( 100.0, $this->balance( $this->to_id ) ); // recipient gets the full amount, not amount-charge.
	}

	public function test_fixed_charge_type() {
		$this->set_general_option( 'transfer_charge_type', 'fixed' );
		$this->set_general_option( 'transfer_charge_amount', 5 );

		$balance_before = $this->balance( $this->from_id );
		$result         = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 100 );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( 5.0, $result['charge'] );
		$this->assertSame( $balance_before - 105.0, $this->balance( $this->from_id ) );
	}

	public function test_rate_limit_blocks_transfers_past_the_configured_threshold() {
		add_filter(
			'woo_wallet_transfer_rate_limit_per_minute',
			function () {
				return 2;
			}
		);

		$this->assertTrue( WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 10 )['is_valid'] );
		$this->assertTrue( WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 10 )['is_valid'] );
		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 10 );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'rest_rate_limited', $result['code'] );
		$this->assertSame( 429, $result['status'] );
	}

	public function test_woo_wallet_transfer_user_id_filter_can_redirect_the_recipient() {
		$redirect_target = self::factory()->user->create();
		$this->track_users( $redirect_target );

		add_filter(
			'woo_wallet_transfer_user_id',
			function () use ( $redirect_target ) {
				return $redirect_target;
			}
		);

		$result = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 50 );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( 0.0, $this->balance( $this->to_id ), 'The originally requested recipient must not receive anything once redirected.' );
		$this->assertSame( 50.0, $this->balance( $redirect_target ) );
	}

	public function test_woo_wallet_transfer_charge_amount_filter_can_override_the_computed_charge() {
		add_filter(
			'woo_wallet_transfer_charge_amount',
			function () {
				return 1.5;
			}
		);

		$balance_before = $this->balance( $this->from_id );
		$result         = WooWallet_Transfer_Service::execute( $this->from_id, $this->to_id, 100 );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( 1.5, $result['charge'] );
		$this->assertSame( $balance_before - 101.5, $this->balance( $this->from_id ) );
	}
}
