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

	/**
	 * Previously the customer column showed only an email address — the
	 * display name was dropped entirely, and there was no way to reach the
	 * customer's user profile or their wallet transaction history without
	 * leaving the Withdrawals screen and searching manually.
	 */
	public function test_column_customer_shows_the_display_name_and_links_to_profile_and_wallet() {
		$row   = $this->seed_request();
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'customer' );

		$this->assertStringContainsString( 'Mohamed Ali', $html );
		$this->assertStringContainsString( esc_url( get_edit_user_link( $this->customer_id ) ), $html );
		$this->assertStringContainsString( 'user_id=' . $this->customer_id, $html );
		$this->assertStringContainsString( 'page=woo-wallet-transactions', $html );
		$this->assertStringContainsString( 'View wallet', $html );
	}

	public function test_column_customer_falls_back_to_the_bare_id_for_a_deleted_user() {
		// wp_delete_user() lives in wp-admin/includes/user.php, only loaded
		// on real wp-admin requests — not pulled in by the CLI bootstrap.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$deleted_user_id = self::factory()->user->create();
		$row             = $this->seed_request( array( 'user_id' => $deleted_user_id, 'created_by' => $deleted_user_id ) );
		wp_delete_user( $deleted_user_id );

		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'customer' );

		$this->assertStringContainsString( '#' . $deleted_user_id, $html );
	}

	// -- 'id' column: receipt badge --------------------------------------

	public function test_column_id_shows_a_receipt_badge_when_a_receipt_is_attached() {
		$attachment_id = self::factory()->attachment->create_object( array( 'post_mime_type' => 'application/pdf' ) );
		$row           = $this->seed_request( array( 'receipt_id' => $attachment_id ) );
		$table         = new Woo_Wallet_Withdrawal_Report();
		$html          = $table->column_default( $row, 'id' );

		$this->assertStringContainsString( 'dashicons-paperclip', $html );
		$this->assertStringContainsString( esc_url( wp_get_attachment_url( $attachment_id ) ), $html );
	}

	public function test_column_id_has_no_receipt_badge_without_a_receipt() {
		$row   = $this->seed_request(); // receipt_id defaults to 0.
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'id' );

		$this->assertStringNotContainsString( 'dashicons-paperclip', $html );
	}

	public function test_column_bank_combines_bank_beneficiary_account_and_iban() {
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
		$this->assertStringContainsString( 'EG380019000500000000263180002', $html );
	}

	/**
	 * Phone is already shown in the 'customer' column (see
	 * test_column_customer_shows_name_email_and_phone_with_links below) —
	 * repeating it in 'bank' was pure duplication, removed deliberately.
	 */
	public function test_column_bank_does_not_repeat_the_phone_number() {
		$row   = $this->seed_request( array( 'phone' => '01099998888' ) );
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'bank' );
		$this->assertStringNotContainsString( '01099998888', $html );
	}

	public function test_column_bank_account_number_and_iban_have_copy_buttons() {
		$row   = $this->seed_request( array( 'iban' => 'EG380019000500000000263180002' ) );
		$table = new Woo_Wallet_Withdrawal_Report();
		$html  = $table->column_default( $row, 'bank' );

		$this->assertStringContainsString( 'woo-wallet-copy-btn', $html );
		$this->assertStringContainsString( 'data-copy-value="1234567890"', $html );
		$this->assertStringContainsString( 'data-copy-value="EG380019000500000000263180002"', $html );
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

	// -- list screen: summary totals + advanced filters ----------------------

	public function test_list_screen_shows_the_summary_totals_for_the_current_filter() {
		$this->seed_request( array( 'amount' => 100 ) );
		$this->seed_request( array( 'amount' => 250 ) );
		$this->seed_request( array( 'amount' => 999, 'status' => 'paid' ) ); // different status — excluded once filtered.

		// Unfiltered: every request counted.
		$html = $this->render_admin_page();
		$this->assertStringContainsString( 'Total amount for 3 displayed requests', $html );

		// Filtered to pending only: totals must reflect just those two —
		// i.e. 350.00 (100 + 250), not 1349.00 (all three).
		$_GET['withdrawal_status'] = 'pending';
		$html                      = $this->render_admin_page();
		$this->assertStringContainsString( 'Total amount for 2 displayed requests', $html );
		$this->assertStringContainsString( '350.00', $html );
	}

	public function test_list_screen_advanced_filters_collapsed_by_default() {
		$html = $this->render_admin_page();
		$this->assertStringContainsString( 'class="woo-wallet-withdrawal-filters__advanced" >', $html );
	}

	public function test_list_screen_advanced_filters_expanded_when_an_advanced_filter_is_active() {
		$_GET['withdrawal_min_amount'] = '50';
		$html                          = $this->render_admin_page();
		$this->assertStringContainsString( 'class="woo-wallet-withdrawal-filters__advanced" open>', $html );
	}

	public function test_list_screen_date_fields_have_visible_from_to_labels() {
		$html = $this->render_admin_page();
		$this->assertStringContainsString( 'From:', $html );
		$this->assertStringContainsString( 'To:', $html );
	}

	/**
	 * Previously Reset always linked to the bare list URL, dropping the
	 * status tab the admin was on (e.g. Pending) back to "All" along with
	 * the actual filters. It must now keep the tab and only offer to clear
	 * the search/bank/advanced filters.
	 */
	public function test_reset_link_preserves_the_current_status_tab() {
		$_GET['withdrawal_status'] = 'pending';
		$_GET['withdrawal_bank']   = array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() );
		$html                      = $this->render_admin_page();

		$this->assertMatchesRegularExpression( '/href="[^"]*withdrawal_status=pending[^"]*"[^>]*>Reset/', $html );
	}

	public function test_reset_link_is_not_shown_when_only_the_status_tab_is_active() {
		$_GET['withdrawal_status'] = 'pending';
		$html                      = $this->render_admin_page();
		$this->assertDoesNotMatchRegularExpression( '/>Reset</', $html );
	}

	public function test_export_csv_button_form_includes_a_nonce_field() {
		$html = $this->render_admin_page();
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	// -- N+1 query fix: primed user cache -------------------------------

	public function test_prepare_items_primes_the_user_cache_for_customers_and_staff() {
		$staff = self::factory()->user->create( array( 'display_name' => 'Priming Staff' ) );
		$this->seed_request( array( 'created_by' => $staff ) );

		$table = new Woo_Wallet_Withdrawal_Report();
		$table->prepare_items();

		// If prepare_items() primed the cache, get_userdata() for both ids
		// resolves from the object cache without a fresh DB round trip —
		// checked directly via wp_cache_get() rather than counting queries,
		// which would be too fragile against unrelated query-count changes.
		$this->assertNotFalse( wp_cache_get( $this->customer_id, 'users' ) );
		$this->assertNotFalse( wp_cache_get( $staff, 'users' ) );
	}
}
