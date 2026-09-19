<?php
/**
 * Woo_Wallet_Withdrawal::maybe_handle_admin_csv_export() / export_csv() —
 * the "Export CSV" button on the admin Withdrawals list.
 *
 * export_csv() itself calls exit() right after writing its body, so it's
 * exercised out-of-process via csv-export-runner.php (see that file's
 * docblock) rather than called directly here. The wrapper's guard
 * conditions (wrong page, no export requested, missing capability) never
 * reach that exit() and are tested normally, in-process.
 */
class Csv_Export_Test extends WP_UnitTestCase {

	private $withdrawal;

	public function set_up() {
		parent::set_up();
		$this->withdrawal = new Woo_Wallet_Withdrawal();

		// maybe_handle_admin_csv_export()'s very first check is is_admin(),
		// which is false by default in this CLI test boot (no wp-admin
		// screen was ever loaded) — that would make every guard-condition
		// test below trivially pass for the wrong reason (short-circuiting
		// before ever inspecting $_GET). Defining WP_ADMIN here is the same
		// technique WooCommerce's own test suite uses for admin-only code:
		// idempotent, and nothing else in this suite reads is_admin(), so
		// there's no cross-test interaction to worry about.
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}
	}

	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	// -- wrapper guard conditions (in-process; none of these reach exit()) --

	public function test_does_nothing_outside_the_withdrawals_page() {
		$_GET = array( 'page' => 'some-other-page', 'export_action' => '1' );
		ob_start();
		$this->withdrawal->maybe_handle_admin_csv_export();
		$output = ob_get_clean();
		$this->assertSame( '', $output );
	}

	public function test_does_nothing_when_export_action_is_not_set() {
		$_GET = array( 'page' => 'woo-wallet-withdrawals' );
		ob_start();
		$this->withdrawal->maybe_handle_admin_csv_export();
		$output = ob_get_clean();
		$this->assertSame( '', $output );
	}

	public function test_denies_a_user_without_capability() {
		$_GET = array(
			'page'          => 'woo-wallet-withdrawals',
			'export_action' => '1',
		);
		$plain_user = self::factory()->user->create();
		wp_set_current_user( $plain_user );

		$this->expectException( 'WPDieException' );
		$this->withdrawal->maybe_handle_admin_csv_export();
	}

	// -- actual CSV body content (out-of-process; see csv-export-runner.php) --

	public function test_export_csv_produces_a_well_formed_csv_with_the_seeded_row() {
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open(
			escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/csv-export-runner.php' ),
			$descriptors,
			$pipes
		);
		$this->assertIsResource( $process, 'Failed to spawn the CSV export runner subprocess.' );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		$this->assertSame( 0, $exit_code, "csv-export-runner.php did not exit cleanly.\nstderr:\n{$stderr}\nstdout:\n{$stdout}" );

		// UTF-8 BOM, for Excel's Arabic-text compatibility. Not necessarily
		// the very first bytes of the captured stream — the WP test
		// bootstrap itself prints a couple of informational lines
		// ("Installing...", etc.) to stdout before our script ever runs.
		$this->assertStringContainsString( "\xEF\xBB\xBF", $stdout );

		// Header row.
		foreach ( array( 'ID', 'Customer Email', 'Customer Name', 'Phone', 'Amount', 'Charge', 'Total Debited', 'Beneficiary Name', 'Account Number', 'IBAN', 'Has Receipt', 'Requested By' ) as $expected_header ) {
			$this->assertStringContainsString( $expected_header, $stdout );
		}

		// The seeded row's data.
		$this->assertStringContainsString( '_customer@example.com', $stdout );
		$this->assertStringContainsString( 'CSV Export Customer', $stdout );
		$this->assertStringContainsString( 'CSV Export Beneficiary', $stdout );
		// Account number / phone / IBAN are prefixed with a leading apostrophe
		// so Excel doesn't mangle them as numbers — assert that prefix survives.
		$this->assertStringContainsString( "'9988776655", $stdout );
		$this->assertStringContainsString( "'01055512345", $stdout );
		$this->assertStringContainsString( "'EG380019000500000000263180002", $stdout );
		$this->assertStringContainsString( 'TRX-CSV-1', $stdout );
		$this->assertStringContainsString( '100.00', $stdout );
		$this->assertStringContainsString( '5.00', $stdout );
		$this->assertStringContainsString( '105.00', $stdout ); // amount + charge.
		$this->assertStringContainsString( 'Self-service (Customer)', $stdout );
		$this->assertStringContainsString( 'Pending,No,', $stdout ); // status, then has_receipt: none attached.
	}
}
