<?php
/**
 * Woo_Wallet_Approvals: a support agent asks, a shop manager or
 * administrator decides. Nothing in a customer's wallet changes until a
 * request is approved, and it then runs exactly once, in the approver's name.
 */
class Approval_Requests_Test extends WP_UnitTestCase {

	private $admin_id;
	private $manager_id;
	private $agent_id;
	private $customer_id;

	public function set_up() {
		parent::set_up();
		Woo_Wallet_Staff::ensure_role();
		reset_phpmailer_instance();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.com' ) );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		$this->manager_id = self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'manager@example.com' ) );
		$this->agent_id   = self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE, 'user_email' => 'agent@example.com' ) );
		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		woo_wallet()->wallet->credit( $this->customer_id, 500, 'test funding' );
	}

	public function tear_down() {
		reset_phpmailer_instance();
		delete_option( Woo_Wallet_Approvals::EXCLUDED_OPTION );
		delete_option( Woo_Wallet_Approvals::LEGACY_RECIPIENTS_OPTION );
		parent::tear_down();
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
	}

	private function withdrawal_details() {
		return array(
			'bank_name'        => array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
			'beneficiary_name' => 'Mohamed Ali',
			'account_number'   => '1234567890',
			'phone'            => '01012345678',
			'iban'             => 'EG380019000500000000263180002',
		);
	}

	private function request( $type, $amount, $reason = 'customer asked', array $details = array() ) {
		wp_set_current_user( $this->agent_id );
		$id = Woo_Wallet_Approvals::create( $type, $this->customer_id, $amount, $details, $reason );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	private function sent_to() {
		$mailer = tests_retrieve_phpmailer_instance();
		$to     = array();
		foreach ( $mailer->mock_sent as $mail ) {
			foreach ( $mail['to'] as $recipient ) {
				$to[] = $recipient[0];
			}
		}
		return $to;
	}

	// -- filing a request ---------------------------------------------------

	public function test_a_withdrawal_request_reserves_nothing_until_approved() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_WITHDRAWAL, 100, '', $this->withdrawal_details() );

		$this->assertSame( 500.0, $this->balance() );
		$this->assertSame( 0, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) ) );
		$this->assertSame( Woo_Wallet_Approvals::STATUS_PENDING, Woo_Wallet_Approvals::get( $id )->status );
	}

	public function test_bank_details_are_encrypted_at_rest() {
		$id  = $this->request( Woo_Wallet_Approvals::TYPE_WITHDRAWAL, 100, '', $this->withdrawal_details() );
		$row = Woo_Wallet_Approvals::get( $id );

		$this->assertStringNotContainsString( '1234567890', $row->payload );
		$this->assertStringNotContainsString( 'EG380019000500000000263180002', $row->payload );
		$this->assertSame( '1234567890', Woo_Wallet_Approvals::details( $row )['account_number'] );
	}

	public function test_invalid_requests_are_refused() {
		wp_set_current_user( $this->agent_id );
		$this->assertSame( 'woo_wallet_approval_amount', Woo_Wallet_Approvals::create( 'credit', $this->customer_id, 0, array(), 'x' )->get_error_code() );
		$this->assertSame( 'woo_wallet_approval_reason', Woo_Wallet_Approvals::create( 'debit', $this->customer_id, 10, array(), '' )->get_error_code() );
		$this->assertSame( 'woo_wallet_approval_type', Woo_Wallet_Approvals::create( 'transfer', $this->customer_id, 10, array(), 'x' )->get_error_code() );
		$this->assertSame( 'woo_wallet_approval_customer', Woo_Wallet_Approvals::create( 'credit', 999999, 10, array(), 'x' )->get_error_code() );
		$this->assertSame( 'woo_wallet_approval_self', Woo_Wallet_Approvals::create( 'credit', $this->agent_id, 10, array(), 'x' )->get_error_code() );
		$this->assertSame( 'woo_wallet_approval_bank', Woo_Wallet_Approvals::create( 'withdrawal', $this->customer_id, 10, array( 'phone' => '01012345678' ), '' )->get_error_code() );
	}

	public function test_a_customer_cannot_file_requests() {
		wp_set_current_user( $this->customer_id );
		$this->assertSame( 'woo_wallet_approval_forbidden', Woo_Wallet_Approvals::create( 'credit', $this->agent_id, 10, array(), 'x' )->get_error_code() );
	}

	// -- deciding ------------------------------------------------------------

	public function test_approving_a_withdrawal_request_creates_the_withdrawal_in_the_approvers_name() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_WITHDRAWAL, 100, 'called in', $this->withdrawal_details() );

		wp_set_current_user( $this->manager_id );
		$this->assertTrue( Woo_Wallet_Approvals::approve( $id, 'ok' ) );

		$row        = Woo_Wallet_Approvals::get( $id );
		$withdrawal = Woo_Wallet_Withdrawal::get_request( (int) $row->result_id );
		$this->assertSame( Woo_Wallet_Approvals::STATUS_APPROVED, $row->status );
		$this->assertSame( $this->manager_id, (int) $row->decided_by );
		$this->assertSame( 'pending', $withdrawal->status );
		$this->assertSame( $this->manager_id, (int) $withdrawal->created_by );
		$this->assertSame( 100.0, (float) $withdrawal->amount );
		$this->assertLessThan( 500.0, $this->balance(), 'The amount is reserved at approval.' );
		$notes = wp_list_pluck( Woo_Wallet_Withdrawal::get_notes( $withdrawal->id ), 'note' );
		$this->assertStringContainsString( 'Approval request #' . $id, implode( ' ', $notes ) );
	}

	public function test_approving_credit_and_debit_requests() {
		$credit = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 300, 'big goodwill' );
		$debit  = $this->request( Woo_Wallet_Approvals::TYPE_DEBIT, 50, 'duplicate refund' );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Woo_Wallet_Approvals::approve( $credit ) );
		$this->assertTrue( Woo_Wallet_Approvals::approve( $debit ) );

		$this->assertSame( 750.0, $this->balance() );
		global $wpdb;
		$created_by = $wpdb->get_var( $wpdb->prepare( "SELECT created_by FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE transaction_id = %d", Woo_Wallet_Approvals::get( $credit )->result_id ) );
		$this->assertSame( $this->admin_id, (int) $created_by );
	}

	/**
	 * The description is what the customer sees in their wallet history and
	 * in the transaction email: only the agent's reason — not the internal
	 * request number or which agent asked, which go in the meta instead.
	 */
	public function test_the_customer_sees_only_the_reason_not_internal_details() {
		global $wpdb;
		$id = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'Sorry for the late delivery' );

		wp_set_current_user( $this->manager_id );
		Woo_Wallet_Approvals::approve( $id );

		$transaction_id = (int) Woo_Wallet_Approvals::get( $id )->result_id;
		$details        = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE transaction_id = %d", $transaction_id ) );
		$this->assertSame( 'Sorry for the late delivery', $details );
		$this->assertSame( (string) $id, (string) get_wallet_transaction_meta( $transaction_id, '_woo_wallet_approval_request_id', true ) );
		$this->assertSame( (string) $this->agent_id, (string) get_wallet_transaction_meta( $transaction_id, '_woo_wallet_requested_by', true ) );
	}

	public function test_a_debit_the_customer_can_no_longer_afford_fails_without_changing_anything() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_DEBIT, 800, 'chargeback' );

		wp_set_current_user( $this->manager_id );
		$result = Woo_Wallet_Approvals::approve( $id );

		$this->assertSame( 'woo_wallet_approval_insufficient', $result->get_error_code() );
		$row = Woo_Wallet_Approvals::get( $id );
		$this->assertSame( Woo_Wallet_Approvals::STATUS_FAILED, $row->status );
		$this->assertNotEmpty( $row->failure_reason );
		$this->assertSame( 500.0, $this->balance() );
	}

	public function test_a_request_is_carried_out_only_once_even_if_approved_twice() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );

		wp_set_current_user( $this->manager_id );
		$this->assertTrue( Woo_Wallet_Approvals::approve( $id ) );
		wp_set_current_user( $this->admin_id );
		$second = Woo_Wallet_Approvals::approve( $id );

		$this->assertSame( 'woo_wallet_approval_decided', $second->get_error_code() );
		$this->assertSame( 600.0, $this->balance() );
		$this->assertSame( $this->manager_id, (int) Woo_Wallet_Approvals::get( $id )->decided_by );
	}

	public function test_an_agent_cannot_approve() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );
		$this->assertSame( 'woo_wallet_approval_forbidden', Woo_Wallet_Approvals::approve( $id )->get_error_code() );
		$this->assertSame( 500.0, $this->balance() );
	}

	public function test_rejecting_requires_a_note_and_moves_nothing() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );

		wp_set_current_user( $this->manager_id );
		$this->assertSame( 'woo_wallet_approval_note', Woo_Wallet_Approvals::reject( $id, '' )->get_error_code() );
		$this->assertTrue( Woo_Wallet_Approvals::reject( $id, 'not eligible' ) );

		$row = Woo_Wallet_Approvals::get( $id );
		$this->assertSame( Woo_Wallet_Approvals::STATUS_REJECTED, $row->status );
		$this->assertSame( 'not eligible', $row->decision_note );
		$this->assertSame( 500.0, $this->balance() );
		$this->assertSame( 'woo_wallet_approval_decided', Woo_Wallet_Approvals::approve( $id )->get_error_code() );
	}

	public function test_only_the_requesting_agent_can_cancel() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );

		$other_agent = self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) );
		wp_set_current_user( $other_agent );
		$this->assertWPError( Woo_Wallet_Approvals::cancel( $id ) );

		wp_set_current_user( $this->agent_id );
		$this->assertTrue( Woo_Wallet_Approvals::cancel( $id ) );
		$this->assertSame( Woo_Wallet_Approvals::STATUS_CANCELLED, Woo_Wallet_Approvals::get( $id )->status );

		wp_set_current_user( $this->manager_id );
		$this->assertSame( 'woo_wallet_approval_decided', Woo_Wallet_Approvals::approve( $id )->get_error_code() );
		$this->assertSame( 500.0, $this->balance() );
	}

	public function test_requests_never_expire_on_their_own() {
		global $wpdb;
		$id = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );
		$wpdb->update( Woo_Wallet_Approvals::table(), array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS ) ), array( 'id' => $id ) );

		wp_set_current_user( $this->manager_id );
		$this->assertTrue( Woo_Wallet_Approvals::approve( $id ) );
	}

	// -- emails ---------------------------------------------------------------

	public function test_new_request_emails_every_approver_by_default() {
		$this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );

		$to = $this->sent_to();
		$this->assertContains( 'admin@example.com', $to );
		$this->assertContains( 'manager@example.com', $to );
		$this->assertNotContains( 'agent@example.com', $to );
	}

	public function test_only_chosen_recipients_who_have_not_paused_get_new_request_emails() {
		$second_manager = self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'manager2@example.com' ) );
		$approver_ids   = wp_list_pluck( Woo_Wallet_Approvals::approvers(), 'ID' );
		update_option( Woo_Wallet_Approvals::EXCLUDED_OPTION, array_values( array_diff( $approver_ids, array( $this->manager_id, $second_manager ) ) ) );
		update_user_meta( $second_manager, Woo_Wallet_Approvals::PAUSE_META, 1 );

		$this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );

		$this->assertSame( array( 'manager@example.com' ), $this->sent_to() );
	}

	public function test_if_every_chosen_recipient_paused_administrators_get_it_instead() {
		$approver_ids = wp_list_pluck( Woo_Wallet_Approvals::approvers(), 'ID' );
		update_option( Woo_Wallet_Approvals::EXCLUDED_OPTION, array_values( array_diff( $approver_ids, array( $this->manager_id ) ) ) );
		update_user_meta( $this->manager_id, Woo_Wallet_Approvals::PAUSE_META, 1 );

		$recipients = Woo_Wallet_Approvals::email_recipients();
		$this->assertTrue( $recipients['fallback'] );

		$this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );
		$to = $this->sent_to();
		$this->assertContains( 'admin@example.com', $to );
		$this->assertNotContains( 'manager@example.com', $to, 'A shop manager is not an administrator.' );
	}

	/**
	 * Someone who becomes an approver after an administrator saved the list
	 * must get the emails without anyone having to remember to add them —
	 * while the people the administrator took off stay off.
	 */
	public function test_a_new_approver_gets_emails_and_an_excluded_one_stays_excluded() {
		update_option( Woo_Wallet_Approvals::EXCLUDED_OPTION, array( $this->manager_id ) );
		$new_manager = self::factory()->user->create( array( 'role' => 'shop_manager', 'user_email' => 'new-manager@example.com' ) );

		$this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );

		$to = $this->sent_to();
		$this->assertContains( 'new-manager@example.com', $to );
		$this->assertNotContains( 'manager@example.com', $to );
	}

	public function test_a_recipient_list_saved_by_an_earlier_build_is_converted_to_exclusions() {
		update_option( Woo_Wallet_Approvals::LEGACY_RECIPIENTS_OPTION, array( $this->admin_id ) );

		$chosen = Woo_Wallet_Approvals::chosen_recipient_ids();

		$this->assertContains( $this->admin_id, $chosen );
		$this->assertNotContains( $this->manager_id, $chosen );
		$this->assertFalse( get_option( Woo_Wallet_Approvals::LEGACY_RECIPIENTS_OPTION ) );
		$this->assertContains( $this->manager_id, Woo_Wallet_Approvals::excluded_recipient_ids() );
	}

	public function test_the_agent_is_emailed_the_decision() {
		$id = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'goodwill' );
		reset_phpmailer_instance();

		wp_set_current_user( $this->manager_id );
		Woo_Wallet_Approvals::reject( $id, 'not eligible' );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( array( 'agent@example.com' ), $this->sent_to() );
		$this->assertStringContainsString( 'not eligible', $mailer->get_sent( 0 )->body );
	}

	public function test_the_new_request_email_never_contains_the_full_account_number() {
		$this->request( Woo_Wallet_Approvals::TYPE_WITHDRAWAL, 100, '', $this->withdrawal_details() );

		$body = tests_retrieve_phpmailer_instance()->get_sent( 0 )->body;
		$this->assertStringNotContainsString( '1234567890', $body );
		$this->assertStringContainsString( '7890', $body );
	}

	// -- screens ---------------------------------------------------------------

	private function render( $object, $get ) {
		$_GET = $get;
		ob_start();
		try {
			$object->render_page();
		} finally {
			$html = ob_get_clean();
			$_GET = array();
		}
		return $html;
	}

	public function test_the_agent_sees_only_their_own_requests_with_a_cancel_button() {
		$mine        = $this->request( Woo_Wallet_Approvals::TYPE_CREDIT, 100, 'my request' );
		$other_agent = self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) );
		wp_set_current_user( $other_agent );
		Woo_Wallet_Approvals::create( Woo_Wallet_Approvals::TYPE_CREDIT, $this->customer_id, 5, array(), 'someone else' );

		wp_set_current_user( $this->agent_id );
		$html = $this->render( new Woo_Wallet_Approvals(), array( 'page' => 'woo-wallet-approvals' ) );

		$this->assertStringContainsString( 'My Requests', $html );
		$this->assertStringContainsString( 'my request', $html );
		$this->assertStringNotContainsString( 'someone else', $html );
		$this->assertStringContainsString( 'woo_wallet_approval_cancel', $html );
		$this->assertStringNotContainsString( 'value="approve"', $html );
		$this->assertStringContainsString( '#' . $mine, $html );
	}

	public function test_the_approver_sees_pending_requests_with_approve_and_reject() {
		$this->request( Woo_Wallet_Approvals::TYPE_WITHDRAWAL, 100, 'called in', $this->withdrawal_details() );

		wp_set_current_user( $this->manager_id );
		$html = $this->render( new Woo_Wallet_Approvals(), array( 'page' => 'woo-wallet-approvals' ) );

		$this->assertStringContainsString( 'Approval Requests', $html );
		$this->assertStringContainsString( 'value="approve"', $html );
		$this->assertStringContainsString( 'value="reject"', $html );
		$this->assertStringContainsString( '1234567890', $html, 'A shop manager sees the full account number.' );
		$this->assertStringContainsString( 'Pause my emails', $html );
	}

	public function test_the_new_request_form_renders_for_an_agent() {
		wp_set_current_user( $this->agent_id );
		$html = $this->render( new Woo_Wallet_Approvals(), array( 'page' => 'woo-wallet-approvals', 'action' => 'new', 'type' => 'withdrawal' ) );

		$this->assertStringContainsString( 'woo_wallet_approval_create', $html );
		$this->assertStringContainsString( 'name="account_number"', $html );
		$this->assertStringContainsString( Woo_Wallet_Staff::FORM_TOKEN_FIELD, $html );
	}

	public function test_the_staff_screen_tabs_render() {
		Woo_Wallet_Staff::save_agent( $this->agent_id, 'level_3', array( 'per_credit' => 20, 'daily' => 50 ) );
		$staff = new Woo_Wallet_Staff();

		wp_set_current_user( $this->admin_id );
		$agents = $this->render( $staff, array( 'page' => 'woo-wallet-staff' ) );
		$levels = $this->render( $staff, array( 'page' => 'woo-wallet-staff', 'tab' => 'levels' ) );
		$emails = $this->render( $staff, array( 'page' => 'woo-wallet-staff', 'tab' => 'emails' ) );

		$this->assertStringContainsString( 'agent@example.com', $agents );
		$this->assertStringContainsString( 'Level 3', $agents );
		$this->assertStringContainsString( 'woo_wallet_staff_level', $levels );
		$this->assertStringContainsString( 'Last 4 digits only', $levels, 'The comparison table shows what a level without bank details sees.' );
		$this->assertStringContainsString( 'By approval request', $levels );
		$this->assertStringContainsString( 'No limit set yet', $levels );
		$this->assertStringContainsString( 'manager@example.com', $emails );

		// A shop manager manages agents and levels, but not who gets emails.
		wp_set_current_user( $this->manager_id );
		$this->assertStringNotContainsString( 'Approval emails', $this->render( $staff, array( 'page' => 'woo-wallet-staff' ) ) );
		$this->assertStringNotContainsString( 'woo_wallet_approval_recipients', $this->render( $staff, array( 'page' => 'woo-wallet-staff', 'tab' => 'emails' ) ) );
	}
}
