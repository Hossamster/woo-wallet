<?php
/**
 * Woo_Wallet_Withdrawal::submit_request() — the customer self-service entry
 * point shared by the frontend form and the /me/withdrawals REST route.
 */
class Submit_Request_Test extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->user_id, 1000, 'test funding' );

		$this->set_withdrawal_option( 'is_enable_wallet_withdrawal', 'on' );
		$this->set_withdrawal_option( 'min_withdrawal_amount', 0 );
		$this->set_withdrawal_option( 'max_withdrawal_amount', 0 );
		$this->set_withdrawal_option( 'withdrawal_charge_type', 'fixed' );
		$this->set_withdrawal_option( 'withdrawal_charge_amount', 0 );
	}

	/**
	 * Woo_Wallet_Settings_API stores every field for a section under one
	 * serialized WP option (e.g. `_wallet_settings_withdrawal`) — there is
	 * no per-field setter, so tests merge directly into that option array.
	 */
	private function set_withdrawal_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_withdrawal', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_withdrawal', $options );
	}

	private function valid_bank() {
		$banks = Woo_Wallet_Withdrawal::get_configured_banks();
		return array_key_first( $banks );
	}

	private function submit( array $overrides = array() ) {
		$args = array_merge(
			array(
				'user_id'          => $this->user_id,
				'amount'           => 100,
				'bank_name'        => $this->valid_bank(),
				'beneficiary_name' => 'Mohamed Ali',
				'account_number'   => '1234567890',
				'phone'            => '01012345678',
				'iban'             => '',
			),
			$overrides
		);
		return Woo_Wallet_Withdrawal::submit_request( $args['user_id'], $args['amount'], $args['bank_name'], $args['beneficiary_name'], $args['account_number'], $args['phone'], $args['iban'] );
	}

	public function test_rejects_when_withdrawal_feature_disabled() {
		$this->set_withdrawal_option( 'is_enable_wallet_withdrawal', 'off' );
		$result = $this->submit();
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_logged_out_user() {
		$result = $this->submit( array( 'user_id' => 0 ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_zero_or_negative_amount() {
		$this->assertFalse( $this->submit( array( 'amount' => 0 ) )['is_valid'] );
		$this->assertFalse( $this->submit( array( 'amount' => -50 ) )['is_valid'] );
	}

	public function test_rejects_amount_below_configured_minimum() {
		$this->set_withdrawal_option( 'min_withdrawal_amount', 200 );
		$result = $this->submit( array( 'amount' => 100 ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_amount_above_configured_maximum() {
		$this->set_withdrawal_option( 'max_withdrawal_amount', 50 );
		$result = $this->submit( array( 'amount' => 100 ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_unknown_bank() {
		$result = $this->submit( array( 'bank_name' => 'Not A Real Bank' ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_empty_beneficiary_name() {
		$result = $this->submit( array( 'beneficiary_name' => '   ' ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_empty_account_number() {
		$result = $this->submit( array( 'account_number' => '' ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_rejects_short_phone_number() {
		$result = $this->submit( array( 'phone' => '12345' ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_strips_non_digit_characters_from_phone_before_length_check() {
		// "010 123 45678" has 11 real digits once spaces are stripped — valid.
		$result = $this->submit( array( 'phone' => '010 123 45678' ) );
		$this->assertTrue( $result['is_valid'] );
	}

	public function test_rejects_invalid_egyptian_iban() {
		$result = $this->submit( array( 'iban' => 'GB1234567890' ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_accepts_blank_iban_since_it_is_optional() {
		$result = $this->submit( array( 'iban' => '' ) );
		$this->assertTrue( $result['is_valid'] );
	}

	public function test_rejects_amount_greater_than_wallet_balance() {
		$result = $this->submit( array( 'amount' => 5000 ) );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_valid_request_reserves_funds_immediately() {
		$balance_before = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );

		$result = $this->submit( array( 'amount' => 100 ) );

		$this->assertTrue( $result['is_valid'] );
		$this->assertNotEmpty( $result['id'] );

		$balance_after = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );
		$this->assertSame( $balance_before - 100.0, $balance_after );

		$row = Woo_Wallet_Withdrawal::get_request( $result['id'] );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( '01012345678', $row->phone );
		$this->assertSame( $this->user_id, (int) $row->user_id );
	}

	public function test_valid_request_reserves_amount_plus_configured_charge() {
		$this->set_withdrawal_option( 'withdrawal_charge_type', 'fixed' );
		$this->set_withdrawal_option( 'withdrawal_charge_amount', 10 );

		$balance_before = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );
		$result         = $this->submit( array( 'amount' => 100 ) );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( 10.0, (float) $result['charge'] );

		$balance_after = (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );
		$this->assertSame( $balance_before - 110.0, $balance_after );
	}

	public function test_rate_limit_blocks_requests_past_the_configured_threshold() {
		add_filter(
			'woo_wallet_withdrawal_rate_limit_per_minute',
			function () {
				return 2;
			}
		);

		$this->assertTrue( $this->submit( array( 'amount' => 10 ) )['is_valid'] );
		$this->assertTrue( $this->submit( array( 'amount' => 10 ) )['is_valid'] );
		$this->assertFalse( $this->submit( array( 'amount' => 10 ) )['is_valid'] );
	}
}
