<?php
/**
 * Marking a withdrawal paid needs its proof (reference number + receipt);
 * shop managers and administrators can correct a withdrawal's details —
 * also after payment — with every change written to its notes and the
 * audit log; and a percentage withdrawal charge can be capped.
 */
class Withdrawal_Proof_Edit_Charge_Test extends WP_UnitTestCase {

	private $admin_id;
	private $other_admin_id;
	private $manager_id;
	private $customer_id;

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		Woo_Wallet_Staff::ensure_role();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'owner@example.com' ) );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		$this->other_admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'partner@example.com' ) );
		get_userdata( $this->other_admin_id )->add_cap( 'manage_woocommerce' );
		$this->manager_id  = self::factory()->user->create( array( 'role' => 'shop_manager', 'display_name' => 'Sara Manager' ) );
		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		woo_wallet()->wallet->credit( $this->customer_id, 200000, 'test funding' );
	}

	public function tear_down() {
		reset_phpmailer_instance();
		$_GET = array();
		parent::tear_down();
	}

	private function receipt( $file = 'one-blue-pixel-100x100.png' ) {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
		update_post_meta( $id, Woo_Wallet_Withdrawal::RECEIPT_UPLOAD_META, $this->manager_id );
		return $id;
	}

	private function pending( $amount = 100, $receipt_id = 0 ) {
		$result = Woo_Wallet_Withdrawal::admin_create( $this->customer_id, $amount, array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ), 'Mohamed Ali', '1234567890', '01012345678', '', $this->manager_id, 'pending', '', $receipt_id );
		$this->assertTrue( $result['is_valid'], $result['message'] );
		return (int) $result['id'];
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	private function audit_events( $event ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Woo_Wallet_Audit::table() . ' WHERE event = %s', $event ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function render_detail( $id ) {
		$_GET = array( 'page' => 'woo-wallet-withdrawals', 'action' => 'view', 'id' => $id );
		ob_start();
		( new Woo_Wallet_Withdrawal() )->render_admin_page();
		return ob_get_clean();
	}

	// -- proof of payment --------------------------------------------------------

	public function test_marking_paid_needs_both_the_reference_number_and_the_receipt() {
		$id = $this->pending();

		$no_ref = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id, '', $this->receipt() );
		$this->assertFalse( $no_ref['is_valid'] );
		$this->assertStringContainsString( 'reference number', $no_ref['message'] );

		$no_receipt = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id, 'TRX-1' );
		$this->assertFalse( $no_receipt['is_valid'] );
		$this->assertStringContainsString( 'receipt', $no_receipt['message'] );
		$this->assertSame( 'pending', Woo_Wallet_Withdrawal::get_request( $id )->status );

		$ok = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id, 'TRX-1', $this->receipt() );
		$this->assertTrue( $ok['is_valid'] );
		$this->assertSame( 'paid', Woo_Wallet_Withdrawal::get_request( $id )->status );
	}

	public function test_a_receipt_already_attached_counts() {
		$id = $this->pending( 100, $this->receipt() );
		$this->assertTrue( Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id, 'TRX-2' )['is_valid'] );
	}

	public function test_rejecting_needs_no_proof() {
		$id = $this->pending();
		$this->assertTrue( Woo_Wallet_Withdrawal::admin_process( $id, 'reject', $this->manager_id )['is_valid'] );
	}

	public function test_logging_an_already_paid_withdrawal_needs_proof_and_reserves_nothing_without_it() {
		$before = $this->balance();
		$result = Woo_Wallet_Withdrawal::admin_create( $this->customer_id, 100, array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ), 'Mohamed Ali', '1234567890', '01012345678', '', $this->manager_id, 'paid' );

		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( $before, $this->balance() );
		$this->assertSame( 0, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) ) );
	}

	public function test_the_requirement_can_be_switched_off_in_code() {
		add_filter( 'woo_wallet_withdrawal_require_payment_proof', '__return_false' );
		$id = $this->pending();
		$this->assertTrue( Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id )['is_valid'] );
	}

	public function test_the_process_form_marks_the_proof_as_required() {
		$id = $this->pending();
		wp_set_current_user( $this->manager_id );
		$html = $this->render_detail( $id );
		$this->assertStringContainsString( 'wwConfirmPaid', $html );
		$this->assertStringContainsString( 'Required to mark as paid.', $html );
	}

	// -- editing ---------------------------------------------------------------------

	private function paid() {
		$id = $this->pending();
		Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id, 'TRX-1', $this->receipt() );
		return $id;
	}

	public function test_a_manager_corrects_the_reference_of_a_paid_withdrawal_and_it_is_logged() {
		$id = $this->paid();

		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array( 'reference_no' => 'TRX-1-CORRECTED' ), 0, $this->manager_id );

		$this->assertTrue( $result['is_valid'], $result['message'] );
		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 'TRX-1-CORRECTED', $row->reference_no );
		$this->assertSame( 'paid', $row->status, 'Editing never changes the status.' );
		$notes = implode( "\n", wp_list_pluck( Woo_Wallet_Withdrawal::get_notes( $id ), 'note' ) );
		$this->assertStringContainsString( 'Sara Manager', $notes );
		$this->assertStringContainsString( 'TRX-1 → TRX-1-CORRECTED', $notes );
		$this->assertCount( 1, $this->audit_events( Woo_Wallet_Audit::EVENT_WITHDRAWAL_EDITED ) );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'A routine correction is not an alert.' );
	}

	public function test_changing_the_bank_details_of_a_paid_withdrawal_alerts_administrators() {
		$id = $this->paid();
		reset_phpmailer_instance();
		wp_set_current_user( $this->manager_id );

		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array( 'account_number' => '9999888877' ), 0, $this->manager_id );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( '9999888877', Woo_Wallet_Withdrawal::get_request( $id )->account_number );
		$this->assertCount( 1, $this->audit_events( Woo_Wallet_Audit::EVENT_PAID_BANK_DETAILS_CHANGED ) );
		$notes = implode( "\n", wp_list_pluck( Woo_Wallet_Withdrawal::get_notes( $id ), 'note' ) );
		$this->assertStringContainsString( '7890', $notes );
		$this->assertStringNotContainsString( '1234567890', $notes, 'Bank numbers are logged by their last four digits.' );
		$to = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			foreach ( $mail['to'] as $recipient ) {
				$to[] = $recipient[0];
			}
		}
		$this->assertContains( 'owner@example.com', $to );
	}

	public function test_a_paid_withdrawal_cannot_lose_its_reference_number() {
		$id     = $this->paid();
		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array( 'reference_no' => '' ), 0, $this->manager_id );
		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( 'TRX-1', Woo_Wallet_Withdrawal::get_request( $id )->reference_no );
	}

	public function test_replacing_the_receipt_of_a_paid_withdrawal() {
		$id       = $this->paid();
		$old_file = Woo_Wallet_Withdrawal::resolve_receipt_file( Woo_Wallet_Withdrawal::get_request( $id ) );

		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array(), $this->receipt( 'one-blue-pixel-1-100x100.png' ), $this->manager_id );

		$this->assertTrue( $result['is_valid'] );
		$new_file = Woo_Wallet_Withdrawal::resolve_receipt_file( Woo_Wallet_Withdrawal::get_request( $id ) );
		$this->assertNotSame( $old_file['path'], $new_file['path'] );
		$this->assertFileDoesNotExist( $old_file['path'] );
		$this->assertStringContainsString( 'Receipt replaced', implode( "\n", wp_list_pluck( Woo_Wallet_Withdrawal::get_notes( $id ), 'note' ) ) );
	}

	public function test_saving_without_changes_changes_nothing_and_logs_nothing() {
		$id     = $this->paid();
		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array( 'reference_no' => 'TRX-1' ), 0, $this->manager_id );
		$this->assertSame( 'Nothing was changed.', $result['message'] );
		$this->assertCount( 0, $this->audit_events( Woo_Wallet_Audit::EVENT_WITHDRAWAL_EDITED ) );
	}

	public function test_edited_bank_details_are_encrypted_when_encryption_is_on() {
		global $wpdb;
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$id = $this->paid();
		Woo_Wallet_Withdrawal::admin_edit( $id, array( 'iban' => 'EG380019000500000000263180002' ), 0, $this->manager_id );

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT iban FROM {$wpdb->base_prefix}woo_wallet_withdrawals WHERE id = %d", $id ) );
		$this->assertStringStartsWith( 'enc:', $raw );
		$this->assertSame( 'EG380019000500000000263180002', Woo_Wallet_Withdrawal::get_request( $id )->iban );
	}

	public function test_only_shop_managers_and_administrators_can_edit() {
		$agent      = self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) );
		$accountant = self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ACCOUNTANT_ROLE ) );
		$this->assertTrue( user_can( $this->manager_id, Woo_Wallet_Staff::CAP_EDIT_WITHDRAWALS ) );
		$this->assertTrue( user_can( $this->admin_id, Woo_Wallet_Staff::CAP_EDIT_WITHDRAWALS ) );
		$this->assertFalse( user_can( $agent, Woo_Wallet_Staff::CAP_EDIT_WITHDRAWALS ) );
		$this->assertFalse( user_can( $accountant, Woo_Wallet_Staff::CAP_EDIT_WITHDRAWALS ) );

		$id = $this->paid();
		wp_set_current_user( $this->manager_id );
		$this->assertStringContainsString( 'woo_wallet_withdrawal_edit', $this->render_detail( $id ) );
		wp_set_current_user( $accountant );
		$this->assertStringNotContainsString( 'woo_wallet_withdrawal_edit', $this->render_detail( $id ) );
	}

	// -- charge cap ----------------------------------------------------------------------

	private function set_charge( $type, $amount, $max ) {
		$settings                             = (array) get_option( '_wallet_settings_withdrawal', array() );
		$settings['withdrawal_charge_type']   = $type;
		$settings['withdrawal_charge_amount'] = $amount;
		$settings['withdrawal_charge_max']    = $max;
		update_option( '_wallet_settings_withdrawal', $settings );
	}

	public function test_a_percentage_charge_is_capped_at_the_maximum() {
		$this->set_charge( 'percent', 0.1, 20 );
		$this->assertSame( 20.0, Woo_Wallet_Withdrawal::calculate_charge( 100000 ) );
		$this->assertSame( 5.0, Woo_Wallet_Withdrawal::calculate_charge( 5000 ), 'Below the cap the percentage applies.' );
	}

	public function test_no_cap_by_default() {
		$this->set_charge( 'percent', 0.1, 0 );
		$this->assertSame( 100.0, Woo_Wallet_Withdrawal::calculate_charge( 100000 ) );
	}

	public function test_the_capped_charge_is_what_the_customer_pays() {
		$this->set_charge( 'percent', 0.1, 20 );
		$before = $this->balance();
		$id     = $this->pending( 100000 );

		$this->assertSame( 20.0, (float) Woo_Wallet_Withdrawal::get_request( $id )->charge );
		$this->assertSame( $before - 100020, $this->balance() );
	}

	// -- review fixes -----------------------------------------------------------------------

	public function test_a_leftover_cap_does_not_undercut_a_fixed_charge() {
		$this->set_charge( 'fixed', 30, 20 );
		$this->assertSame( 30.0, Woo_Wallet_Withdrawal::calculate_charge( 5000 ) );

		$before = $this->balance();
		$id     = $this->pending( 5000 );
		$this->assertSame( 30.0, (float) Woo_Wallet_Withdrawal::get_request( $id )->charge );
		$this->assertSame( $before - 5030, $this->balance() );
	}

	public function test_the_charge_is_rounded_so_the_stored_total_matches_the_ledger() {
		$this->set_charge( 'percent', 2.5, 0 );
		$this->assertSame( 0.83, Woo_Wallet_Withdrawal::calculate_charge( 33.33 ) );

		$before = $this->balance();
		$id     = $this->pending( 33.33 );
		$row    = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 0.83, (float) $row->charge );
		$this->assertSame( round( $before - 34.16, 2 ), round( $this->balance(), 2 ) );
		$this->assertSame( 34.16, round( (float) $row->amount + (float) $row->charge, 8 ) );
	}

	public function test_an_edit_cannot_save_an_invalid_iban() {
		$id     = $this->pending();
		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array( 'iban' => 'EG3800190005000000002631800' ), 0, $this->manager_id );
		$this->assertFalse( $result['is_valid'] );
		$this->assertStringContainsString( 'IBAN', $result['message'] );
	}

	public function test_an_edit_cannot_set_a_bank_outside_the_configured_list() {
		$id     = $this->pending();
		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array( 'bank_name' => 'cib bank' ), 0, $this->manager_id );
		$this->assertFalse( $result['is_valid'] );

		$banks = array_keys( Woo_Wallet_Withdrawal::get_configured_banks() );
		$this->assertTrue( Woo_Wallet_Withdrawal::admin_edit( $id, array( 'bank_name' => end( $banks ) ), 0, $this->manager_id )['is_valid'] );
	}

	public function test_an_older_free_text_bank_can_stay_while_other_fields_are_corrected() {
		global $wpdb;
		$id = $this->pending();
		$wpdb->update( $wpdb->base_prefix . 'woo_wallet_withdrawals', array( 'bank_name' => 'Some old bank' ), array( 'id' => $id ) );

		$result = Woo_Wallet_Withdrawal::admin_edit( $id, array( 'bank_name' => 'Some old bank', 'phone' => '01099998888' ), 0, $this->manager_id );
		$this->assertTrue( $result['is_valid'], $result['message'] );
	}

	public function test_staff_logging_a_withdrawal_with_an_invalid_iban_is_refused() {
		$result = Woo_Wallet_Withdrawal::admin_create( $this->customer_id, 100, array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ), 'Mohamed Ali', '1234567890', '01012345678', 'EG12', $this->manager_id, 'pending' );
		$this->assertFalse( $result['is_valid'] );
	}

	public function test_a_reference_number_already_on_the_request_counts_when_marking_paid() {
		$id = $this->pending();
		Woo_Wallet_Withdrawal::admin_edit( $id, array( 'reference_no' => 'TRX-55' ), 0, $this->manager_id );

		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id, '', $this->receipt() );

		$this->assertTrue( $result['is_valid'], $result['message'] );
		$this->assertSame( 'TRX-55', Woo_Wallet_Withdrawal::get_request( $id )->reference_no );
	}

	public function test_a_new_account_with_the_same_last_four_digits_is_called_out() {
		global $wpdb;
		$id = $this->pending();
		$wpdb->update( $wpdb->base_prefix . 'woo_wallet_withdrawals', array( 'account_number' => '1000200030001234' ), array( 'id' => $id ) );
		Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->manager_id, 'TRX-1', $this->receipt() );

		Woo_Wallet_Withdrawal::admin_edit( $id, array( 'account_number' => '9999888877771234' ), 0, $this->manager_id );

		$notes = implode( "\n", wp_list_pluck( Woo_Wallet_Withdrawal::get_notes( $id ), 'note' ) );
		$this->assertStringContainsString( 'a different number with the same last 4 digits', $notes );
		$this->assertMatchesRegularExpression( '/1234 #([0-9a-f]{6}) → \S+1234 #(?!\1)[0-9a-f]{6}/u', $notes );
		$this->assertStringNotContainsString( '9999888877771234', $notes );
		$this->assertStringNotContainsString( '1000200030001234', $notes );
	}

	public function test_an_approval_request_with_an_invalid_iban_is_refused_up_front() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) ) );
		$result = Woo_Wallet_Approvals::create( 'withdrawal', $this->customer_id, 10, array( 'bank_name' => 'Bank', 'beneficiary_name' => 'X', 'account_number' => '123', 'phone' => '01012345678', 'iban' => 'EG12' ), '' );
		$this->assertSame( 'woo_wallet_approval_iban', $result->get_error_code() );
	}
}
