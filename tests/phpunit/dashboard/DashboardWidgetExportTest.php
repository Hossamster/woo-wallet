<?php
/**
 * Woo_Wallet_Dashboard_Widget's "Export Today's Statement" quick action
 * (Phase 3).
 *
 * handle_export_today() itself ends in TeraWallet_CSV_Exporter::export(),
 * which streams the file and calls a raw die() — uncatchable in a normal
 * PHPUnit test (same class of issue as the AJAX raw-die() gotchas
 * documented elsewhere in this suite, just via a different code path: this
 * one isn't routed through WP_Ajax_UnitTestCase at all, since it's an
 * admin-post.php handler, not a wp_ajax_ action). So these tests split the
 * concern: the nonce/capability gate on handle_export_today() itself
 * (which fails via ordinary wp_die() -> WPDieException, safely catchable),
 * and the actual CSV content, built and asserted via the separate
 * export_today_transactions() method that stops short of export()/die().
 */
class Dashboard_Widget_Export_Test extends WP_UnitTestCase {

	/**
	 * @var Woo_Wallet_Dashboard_Widget
	 */
	private $widget;

	private $admin_id;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-dashboard-widget.php';
		}
		$this->widget   = new Woo_Wallet_Dashboard_Widget();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function insert_transaction( $user_id, $amount, $date ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->base_prefix . 'woo_wallet_transactions',
			array(
				'user_id'  => $user_id,
				'type'     => 'credit',
				'category' => 'other',
				'amount'   => $amount,
				'currency' => 'USD',
				'deleted'  => 0,
				'date'     => $date,
			)
		);
	}

	/**
	 * Deletes the file export_today_transactions() wrote — export() would
	 * normally do this, but the tests below stop short of calling it (see
	 * class docblock), so clean up manually.
	 */
	private function delete_export_file( TeraWallet_CSV_Exporter $exporter ) {
		$path = trailingslashit( wp_upload_dir()['basedir'] ) . 'woo-wallet-exports/' . $exporter->get_filename();
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		}
	}

	// -- handle_export_today() gate --------------------------------------

	public function test_handle_export_today_rejects_a_request_without_a_valid_nonce() {
		wp_set_current_user( $this->admin_id );
		unset( $_REQUEST['_wpnonce'] );

		$this->expectException( WPDieException::class );
		$this->widget->handle_export_today();
	}

	public function test_handle_export_today_rejects_a_valid_nonce_without_the_wallet_capability() {
		$plain_user = self::factory()->user->create(); // no manage_woocommerce.
		wp_set_current_user( $plain_user );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'woo-wallet-dashboard-export-today' );

		$this->expectException( WPDieException::class );
		$this->widget->handle_export_today();
	}

	// -- export_today_transactions() content ------------------------------

	public function test_export_today_transactions_is_scoped_to_transactions_today() {
		$user_id   = self::factory()->user->create();
		$today     = current_time( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		$this->insert_transaction( $user_id, 55, $today . ' 10:00:00' );
		// Outside today's range — must not appear in the export.
		$this->insert_transaction( $user_id, 999, $yesterday . ' 10:00:00' );

		$exporter = $this->widget->export_today_transactions();
		$content  = $exporter->get_file();
		$this->delete_export_file( $exporter );

		$this->assertSame( 'transactions', $exporter->get_export_type() );
		$this->assertStringContainsString( '55', $content );
		$this->assertStringNotContainsString( '999', $content );
	}

	public function test_export_today_transactions_includes_a_header_row_even_with_no_transactions() {
		$exporter = $this->widget->export_today_transactions();
		$content  = $exporter->get_file();
		$this->delete_export_file( $exporter );

		$this->assertStringContainsString( 'amount', $content );
	}
}
