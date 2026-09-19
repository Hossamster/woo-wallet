<?php
/**
 * Admin-side rendering: the "Create Withdrawal" form and single-request
 * detail screen (both private methods on Woo_Wallet_Withdrawal, reached
 * through the public render_admin_page() dispatcher — matching how WordPress
 * itself invokes them), plus Woo_Wallet_Withdrawal_Report's column rendering
 * and status-tab view counts.
 */
class Withdrawal_Admin_Render_Test extends WP_UnitTestCase {

	private $admin_id;
	private $customer_id;
	private $withdrawal;

	public function set_up() {
		parent::set_up();
		require_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-withdrawal-report.php';

		$this->admin_id = self::factory()->user->create();
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $this->admin_id );

		$this->customer_id = self::factory()->user->create( array( 'display_name' => 'Mohamed Ali' ) );
		woo_wallet()->wallet->credit( $this->customer_id, 1000, 'test funding' );

		$this->withdrawal = new Woo_Wallet_Withdrawal();
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function render_admin_page() {
		ob_start();
		$this->withdrawal->render_admin_page();
		return ob_get_clean();
	}

	private function seed_request( array $overrides = array() ) {
		$banks          = Woo_Wallet_Withdrawal::get_configured_banks();
		$transaction_id = woo_wallet()->wallet->debit( $this->customer_id, 100, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );
		$id             = Woo_Wallet_Withdrawal::insert_request(
			array_merge(
				array(
					'user_id'          => $this->customer_id,
					'created_by'       => $this->customer_id,
					'transaction_id'   => $transaction_id,
					'amount'           => 100,
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
		return Woo_Wallet_Withdrawal::get_request( $id );
	}

	// -- capability gate ------------------------------------------------

	public function test_render_admin_page_denies_a_user_without_capability() {
		$plain_user = self::factory()->user->create();
		wp_set_current_user( $plain_user );

		$this->expectException( 'WPDieException' );
		$this->withdrawal->render_admin_page();
	}

	// -- create form --------------------------------------------------

	public function test_create_form_includes_a_required_phone_field() {
		$_GET['action'] = 'new';
		$html           = $this->render_admin_page();

		$this->assertStringContainsString( 'name="phone"', $html );
		$this->assertMatchesRegularExpression( '/name="phone"[^>]*required/', $html );
	}

	public function test_create_form_bank_dropdown_lists_configured_banks_plus_other_fallback() {
		$_GET['action'] = 'new';
		$html           = $this->render_admin_page();

		foreach ( Woo_Wallet_Withdrawal::get_configured_banks() as $value => $label ) {
			$this->assertStringContainsString( esc_html( $label ), $html );
		}
		$this->assertStringContainsString( 'Other', $html );
	}

	// -- detail screen --------------------------------------------------

	public function test_detail_screen_for_unknown_id_shows_not_found() {
		$html = $this->render_detail( 999999 );
		$this->assertStringContainsString( 'Withdrawal not found', $html );
	}

	public function test_detail_screen_shows_phone_bank_and_account_number() {
		$row  = $this->seed_request( array( 'phone' => '01055554444' ) );
		$html = $this->render_detail( $row->id );

		$this->assertStringContainsString( '01055554444', $html );
		$this->assertStringContainsString( '1234567890', $html );
		$this->assertStringContainsString( $row->bank_name, $html );
	}

	public function test_detail_screen_shows_iban_when_present() {
		$row  = $this->seed_request( array( 'iban' => 'EG380019000500000000263180002' ) );
		$html = $this->render_detail( $row->id );
		$this->assertStringContainsString( 'EG380019000500000000263180002', $html );
	}

	public function test_detail_screen_marks_self_service_vs_staff_logged() {
		$self_service = $this->seed_request(); // created_by === user_id.
		$html         = $this->render_detail( $self_service->id );
		$this->assertStringContainsString( 'self-service', strtolower( $html ) );

		$staff_logged = $this->seed_request( array( 'created_by' => $this->admin_id ) );
		$html         = $this->render_detail( $staff_logged->id );
		$this->assertStringContainsString( 'Logged manually by staff', $html );
	}

	public function test_detail_screen_shows_receipt_retention_note_when_receipt_present() {
		$attachment_id = self::factory()->attachment->create_object( array( 'post_mime_type' => 'application/pdf' ) );
		$row           = $this->seed_request( array( 'receipt_id' => $attachment_id ) );
		$html          = $this->render_detail( $row->id );

		$this->assertStringContainsString( 'View receipt', $html );
		$this->assertStringContainsString( 'Automatically removed', $html );
	}

	private function render_detail( $id ) {
		$_GET['action'] = 'view';
		$_GET['id']     = $id;
		return $this->render_admin_page();
	}

	// -- list table columns ------------------------------------------

	public function test_column_customer_shows_email_and_phone() {
		$row   = $this->seed_request( array( 'phone' => '01012345678' ) );
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'customer' );

		$this->assertStringContainsString( get_userdata( $this->customer_id )->user_email, $html );
		$this->assertStringContainsString( '01012345678', $html );
	}

	public function test_column_bank_combines_bank_beneficiary_account_phone_and_iban() {
		$row   = $this->seed_request(
			array(
				'beneficiary_name' => 'Combined Field Test',
				'iban'             => 'EG380019000500000000263180002',
			)
		);
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'bank' );

		$this->assertStringContainsString( $row->bank_name, $html );
		$this->assertStringContainsString( 'Combined Field Test', $html );
		$this->assertStringContainsString( '1234567890', $html );
		$this->assertStringContainsString( '01099998888', $html );
		$this->assertStringContainsString( 'EG380019000500000000263180002', $html );
	}

	public function test_column_bank_omits_iban_when_blank() {
		$row   = $this->seed_request( array( 'iban' => '' ) );
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'bank' );
		$this->assertStringNotContainsString( 'IBAN:', $html );
	}

	public function test_column_requested_by_self_service() {
		$row   = $this->seed_request(); // created_by === user_id.
		$table = new Woo_Wallet_Withdrawal_Report();
		$this->assertSame( 'Self-service', $table->column_default( $row, 'requested_by' ) );
	}

	public function test_column_requested_by_staff() {
		$row   = $this->seed_request( array( 'created_by' => $this->admin_id ) );
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'requested_by' );
		$this->assertStringContainsString( 'Staff:', $html );
		$this->assertStringContainsString( get_userdata( $this->admin_id )->display_name, $html );
	}

	public function test_column_status_renders_a_labeled_span() {
		$row   = $this->seed_request( array( 'status' => 'paid' ) );
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'status' );
		$this->assertStringContainsString( 'woo-wallet-withdrawal-status--paid', $html );
		$this->assertStringContainsString( 'Paid', $html );
	}

	public function test_column_amount_shows_charge_when_present() {
		$row   = $this->seed_request( array( 'amount' => 100, 'charge' => 5 ) );
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'amount' );
		$this->assertStringContainsString( 'charge', $html );
	}

	// -- status-tab view counts -------------------------------------------

	public function test_views_reflect_status_counts() {
		$this->seed_request( array( 'status' => 'pending' ) );
		$this->seed_request( array( 'status' => 'pending' ) );
		$this->seed_request( array( 'status' => 'paid' ) );

		$table      = new Woo_Wallet_Withdrawal_Report();
		$reflection = new ReflectionMethod( $table, 'get_views' );
		$views = $reflection->invoke( $table );

		$this->assertStringContainsString( '(2)', $views['pending'] );
		$this->assertStringContainsString( '(1)', $views['paid'] );
		$this->assertStringContainsString( '(3)', $views['all'] );
	}

	public function test_processing_tab_hidden_when_no_processing_requests() {
		$this->seed_request( array( 'status' => 'pending' ) );

		$table      = new Woo_Wallet_Withdrawal_Report();
		$reflection = new ReflectionMethod( $table, 'get_views' );
		$views = $reflection->invoke( $table );

		$this->assertArrayNotHasKey( 'processing', $views );
	}

	public function test_processing_tab_appears_when_a_request_is_stuck_processing() {
		$this->seed_request( array( 'status' => 'processing' ) );

		$table      = new Woo_Wallet_Withdrawal_Report();
		$reflection = new ReflectionMethod( $table, 'get_views' );
		$views = $reflection->invoke( $table );

		$this->assertArrayHasKey( 'processing', $views );
		$this->assertStringContainsString( '(1)', $views['processing'] );
	}
}
