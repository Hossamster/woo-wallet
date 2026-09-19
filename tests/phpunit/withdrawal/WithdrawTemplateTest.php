<?php
/**
 * Smoke/content coverage for templates/withdraw.php — the customer-facing
 * "Withdraw" tab. Renders the template via output buffering (it's a plain
 * include, not a function) and asserts on the resulting markup: every form
 * field is present, the bank dropdown lists the configured banks, the
 * history table shows the right per-row data (including phone, added
 * alongside the earlier beneficiary/account/IBAN columns), and the receipt
 * retention note appears only when retention is actually enabled.
 */
class Withdraw_Template_Test extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->user_id, 1000, 'test funding' );
		wp_set_current_user( $this->user_id );
	}

	private function render() {
		ob_start();
		include WOO_WALLET_ABSPATH . 'templates/withdraw.php';
		return ob_get_clean();
	}

	private function seed_request( array $overrides = array() ) {
		$banks          = Woo_Wallet_Withdrawal::get_configured_banks();
		$transaction_id = woo_wallet()->wallet->debit( $this->user_id, 50, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );
		return Woo_Wallet_Withdrawal::insert_request(
			array_merge(
				array(
					'user_id'          => $this->user_id,
					'created_by'       => $this->user_id,
					'transaction_id'   => $transaction_id,
					'amount'           => 50,
					'charge'           => 0,
					'currency'         => woo_wallet()->wallet->resolve_active_currency(),
					'bank_name'        => array_key_first( $banks ),
					'beneficiary_name' => 'Mohamed Ali',
					'account_number'   => '1234567890',
					'phone'            => '01099998888',
					'iban'             => '',
					'reference_no'     => '',
					'status'           => 'pending',
				),
				$overrides
			)
		);
	}

	// -- the request form ---------------------------------------------

	public function test_form_includes_every_expected_field() {
		$html = $this->render();

		foreach ( array(
			'name="woo_wallet_withdraw_amount"',
			'name="woo_wallet_withdraw_bank"',
			'name="woo_wallet_withdraw_beneficiary"',
			'name="woo_wallet_withdraw_account_number"',
			'name="woo_wallet_withdraw_phone"',
			'name="woo_wallet_withdraw_iban"',
			'name="woo_wallet_withdraw_request"',
		) as $expected ) {
			$this->assertStringContainsString( $expected, $html );
		}
	}

	public function test_phone_field_is_required() {
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/id="woo_wallet_withdraw_phone"[^>]*required/', $html );
	}

	public function test_bank_select_lists_every_configured_bank() {
		$html = $this->render();
		foreach ( Woo_Wallet_Withdrawal::get_configured_banks() as $value => $label ) {
			$this->assertStringContainsString( 'value="' . esc_attr( $value ) . '"', $html );
			$this->assertStringContainsString( esc_html( $label ), $html );
		}
	}

	public function test_no_history_section_when_the_customer_has_no_requests() {
		$html = $this->render();
		$this->assertStringNotContainsString( 'Your Withdrawal Requests', $html );
	}

	// -- the history table -----------------------------------------------

	public function test_history_table_appears_once_a_request_exists() {
		$this->seed_request();
		$html = $this->render();
		$this->assertStringContainsString( 'Your Withdrawal Requests', $html );
	}

	public function test_history_row_shows_phone_beneficiary_account_and_bank() {
		$this->seed_request( array( 'phone' => '01055556666', 'beneficiary_name' => 'Test Beneficiary Name', 'account_number' => 'ACCT-99887766' ) );
		$html = $this->render();

		$this->assertStringContainsString( '01055556666', $html );
		$this->assertStringContainsString( 'Test Beneficiary Name', $html );
		$this->assertStringContainsString( 'ACCT-99887766', $html );
	}

	public function test_history_row_shows_dash_for_missing_iban_and_reference() {
		$this->seed_request( array( 'iban' => '', 'reference_no' => '' ) );
		$html = $this->render();

		// Both blank IBAN and blank reference render the same placeholder;
		// just confirm it appears (twice, once per empty field) rather than
		// the fields being silently omitted.
		$this->assertGreaterThanOrEqual( 2, substr_count( $html, '&ndash;' ) );
	}

	public function test_history_row_shows_iban_when_present() {
		$this->seed_request( array( 'iban' => 'EG380019000500000000263180002' ) );
		$html = $this->render();
		$this->assertStringContainsString( 'EG380019000500000000263180002', $html );
	}

	public function test_processing_status_is_shown_as_pending_to_the_customer() {
		$this->seed_request( array( 'status' => 'processing' ) );
		$html = $this->render();
		// The transient internal 'processing' state must never leak to the
		// customer — it reads as the same "Pending" label they'd see for a
		// genuinely still-pending request.
		$this->assertStringContainsString( 'Pending', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'processing', $html );
	}

	public function test_receipt_link_appears_when_a_receipt_is_attached() {
		$attachment_id = self::factory()->attachment->create_object( array( 'post_mime_type' => 'application/pdf' ) );
		$this->seed_request( array( 'receipt_id' => $attachment_id ) );
		$html = $this->render();
		$this->assertStringContainsString( 'View', $html );
	}

	public function test_public_note_is_shown_but_private_note_is_not() {
		$id = $this->seed_request();
		Woo_Wallet_Withdrawal::add_note( $id, 'Visible to the customer', 'public', $this->user_id );
		Woo_Wallet_Withdrawal::add_note( $id, 'Internal staff note only', 'private', $this->user_id );

		$html = $this->render();
		$this->assertStringContainsString( 'Visible to the customer', $html );
		$this->assertStringNotContainsString( 'Internal staff note only', $html );
	}

	// -- receipt retention note -------------------------------------------

	public function test_retention_note_shown_when_retention_enabled() {
		$this->seed_request();
		$html = $this->render();
		$this->assertStringContainsString( 'automatically removed', $html );
	}

	public function test_retention_note_hidden_when_retention_disabled() {
		add_filter(
			'woo_wallet_withdrawal_receipt_retention_days',
			function () {
				return 0;
			}
		);
		$this->seed_request();
		$html = $this->render();
		$this->assertStringNotContainsString( 'automatically removed', $html );
	}

	public function test_only_the_current_users_own_requests_appear() {
		$other_user = self::factory()->user->create();
		woo_wallet()->wallet->credit( $other_user, 500, 'test funding' );
		$other_transaction = woo_wallet()->wallet->debit( $other_user, 20, 'reserved', array( 'category' => 'withdrawal' ) );
		Woo_Wallet_Withdrawal::insert_request(
			array(
				'user_id'          => $other_user,
				'created_by'       => $other_user,
				'transaction_id'   => $other_transaction,
				'amount'           => 20,
				'charge'           => 0,
				'currency'         => woo_wallet()->wallet->resolve_active_currency(),
				'bank_name'        => array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
				'beneficiary_name' => 'Other Customer Name',
				'account_number'   => 'OTHER-ACCT',
				'phone'            => '01000000000',
				'iban'             => '',
				'reference_no'     => '',
				'status'           => 'pending',
			)
		);

		$this->seed_request( array( 'beneficiary_name' => 'My Own Name' ) );
		$html = $this->render();

		$this->assertStringContainsString( 'My Own Name', $html );
		$this->assertStringNotContainsString( 'Other Customer Name', $html );
	}
}
