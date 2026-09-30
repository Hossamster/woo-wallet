<?php
/**
 * Test transactions CSV export in admin panel.
 *
 * Covers:
 * - maybe_export_transactions_csv() guard conditions (nonce, capability, page).
 * - generate_transactions_csv() streaming (UTF-8 BOM, headers, row formatting,
 *   Excel formula injection safety, and filter respect).
 * - UI output of Export CSV button in header and tablenav.
 */
class TransactionCsvExportTest extends WP_UnitTestCase {

	/**
	 * @var Woo_Wallet_Admin
	 */
	private $admin;

	/**
	 * @var int
	 */
	private $admin_id;

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		if ( ! class_exists( 'Woo_Wallet_Admin' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-admin.php';
		}

		$this->admin = Woo_Wallet_Admin::instance();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	private function insert_transaction( $user_id, $amount, $type = 'credit', $category = 'other', $details = 'Test details', $created_by = 1, $date = null ) {
		global $wpdb;
		if ( null === $date ) {
			$date = current_time( 'mysql' );
		}
		$wpdb->insert(
			$wpdb->base_prefix . 'woo_wallet_transactions',
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'category'   => $category,
				'amount'     => $amount,
				'currency'   => 'USD',
				'details'    => $details,
				'created_by' => $created_by,
				'deleted'    => 0,
				'date'       => $date,
			)
		);
		return (int) $wpdb->insert_id;
	}

	// -- Guard Conditions --

	public function test_does_nothing_outside_the_transactions_page() {
		$_GET = array(
			'page'          => 'some-other-page',
			'export_action' => 'transactions_csv',
		);
		ob_start();
		$this->admin->maybe_export_transactions_csv();
		$output = ob_get_clean();
		$this->assertSame( '', $output );
	}

	public function test_does_nothing_when_export_action_is_not_set() {
		$_GET = array(
			'page' => 'woo-wallet-transactions',
		);
		ob_start();
		$this->admin->maybe_export_transactions_csv();
		$output = ob_get_clean();
		$this->assertSame( '', $output );
	}

	public function test_denies_export_without_a_valid_nonce() {
		$_GET = array(
			'page'          => 'woo-wallet-transactions',
			'export_action' => 'transactions_csv',
		);
		wp_set_current_user( $this->admin_id );

		$this->expectException( WPDieException::class );
		$this->admin->maybe_export_transactions_csv();
	}

	public function test_denies_export_with_valid_nonce_without_capability() {
		$plain_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $plain_user );

		$_GET = array(
			'page'          => 'woo-wallet-transactions',
			'export_action' => 'transactions_csv',
			'_wpnonce'      => wp_create_nonce( 'woo_wallet_export_transactions' ),
		);

		$this->expectException( WPDieException::class );
		$this->admin->maybe_export_transactions_csv();
	}

	// -- CSV Generation & Content Tests --

	public function test_generate_transactions_csv_writes_bom_and_headers() {
		$stream = fopen( 'php://temp', 'r+' );
		$count  = $this->admin->generate_transactions_csv( $stream, array( 'user_id' => 0 ) );
		rewind( $stream );
		$content = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame( 0, $count );
		// Verify UTF-8 BOM
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $content );
		// Verify standard headers
		$this->assertStringContainsString( 'Transaction ID', $content );
		$this->assertStringContainsString( 'Customer Name', $content );
		$this->assertStringContainsString( 'Customer Email', $content );
		$this->assertStringContainsString( 'Amount', $content );
		$this->assertStringContainsString( 'Currency', $content );
		$this->assertStringContainsString( 'Created By', $content );
	}

	public function test_generate_transactions_csv_exports_seeded_rows_accurately() {
		$customer_id = self::factory()->user->create(
			array(
				'display_name' => 'Alice Wonder',
				'user_email'   => 'alice@example.com',
			)
		);
		$txn_id = $this->insert_transaction(
			$customer_id,
			45.50,
			'credit',
			'cashback',
			'Order #100 cashback bonus',
			$this->admin_id
		);

		$stream = fopen( 'php://temp', 'r+' );
		$count  = $this->admin->generate_transactions_csv( $stream, array( 'user_id' => $customer_id ) );
		rewind( $stream );
		$content = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame( 1, $count );
		$this->assertStringContainsString( (string) $txn_id, $content );
		$this->assertStringContainsString( 'Alice Wonder', $content );
		$this->assertStringContainsString( 'alice@example.com', $content );
		$this->assertStringContainsString( '45.5', $content );
		$this->assertStringContainsString( 'USD', $content );
		$this->assertStringContainsString( 'Order #100 cashback bonus', $content );
	}

	public function test_formula_injection_prevention() {
		$customer_id = self::factory()->user->create(
			array(
				'display_name' => '=HYPERLINK("http://evil.com","Click")',
				'user_email'   => 'attacker@example.com',
			)
		);
		$this->insert_transaction(
			$customer_id,
			10.00,
			'credit',
			'other',
			'=1+1 formula',
			$customer_id
		);

		$stream = fopen( 'php://temp', 'r+' );
		$this->admin->generate_transactions_csv( $stream, array( 'user_id' => $customer_id ) );
		rewind( $stream );
		$content = stream_get_contents( $stream );
		fclose( $stream );

		// All formula characters should be escaped with leading single quote
		$this->assertStringContainsString( "'=HYPERLINK", $content );
		$this->assertStringContainsString( "'=1+1 formula", $content );
		$this->assertStringContainsString( 'attacker@example.com', $content );
	}

	public function test_export_respects_category_filter() {
		$user_id = self::factory()->user->create();
		$t1      = $this->insert_transaction( $user_id, 10, 'credit', 'cashback' );
		$t2      = $this->insert_transaction( $user_id, 20, 'debit', 'transfer' );

		$stream = fopen( 'php://temp', 'r+' );
		$count  = $this->admin->generate_transactions_csv( $stream, array( 'user_id' => $user_id, 'category' => 'cashback' ) );
		rewind( $stream );
		$content = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame( 1, $count );
		$this->assertStringContainsString( (string) $t1, $content );
		$this->assertStringNotContainsString( (string) $t2, $content );
	}

	public function test_export_handles_nonexistent_user_filter() {
		// When searching for an unknown customer, get_filter_args() returns false
		$stream = fopen( 'php://temp', 'r+' );
		$count  = $this->admin->generate_transactions_csv( $stream, false );
		rewind( $stream );
		$content = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame( 0, $count );
		// Should still output BOM and headers
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $content );
		$this->assertStringContainsString( 'Transaction ID', $content );
	}

	// -- UI Elements in Admin Screen --

	public function test_transaction_details_page_renders_export_csv_action() {
		wp_set_current_user( $this->admin_id );
		require_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-transaction-details.php';
		$this->admin->transaction_details_table = new Woo_Wallet_Transaction_Details();

		ob_start();
		$this->admin->transaction_details_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'page-title-action', $html );
		$this->assertStringContainsString( 'Export CSV', $html );
		$this->assertStringContainsString( 'export_action=transactions_csv', $html );
	}

	public function test_extra_tablenav_renders_export_csv_submit_button_and_nonce() {
		require_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-transaction-details.php';
		$table = new Woo_Wallet_Transaction_Details();

		ob_start();
		$table->extra_tablenav( 'top' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="export_action"', $html );
		$this->assertStringContainsString( 'value="Export CSV"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}
}
