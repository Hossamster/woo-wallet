<?php
/**
 * Standalone runner for Woo_Wallet_Withdrawal::export_csv().
 *
 * export_csv() unconditionally calls exit() right after writing the CSV
 * body — calling it in-process from a PHPUnit test would kill the whole
 * test run, not just the one test. Run it out-of-process instead: this
 * script boots a fresh throwaway WP+plugin environment (same bootstrap the
 * main suite uses), seeds one fully-populated withdrawal request, and calls
 * export_csv(). Its stdout carries the CSV body back to the parent test
 * (see CsvExportTest.php), which asserts on that captured output.
 */
// Run via plain `php`, not `vendor/bin/phpunit` — which normally loads
// PHPUnit itself before this bootstrap runs and checks PHPUnit's version.
// Load the same autoloader ourselves first so that check doesn't fail.
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/bootstrap.php';

/*
 * This script's rows are real INSERTs that a normal in-process PHPUnit test
 * would get for free via WP_UnitTestCase's per-test transaction rollback —
 * but this runs as its own separate process/connection with no such
 * wrapper, so anything it commits stays in the local dev DB across runs.
 * Unique-per-run logins/emails avoid a collision with a prior run's leftover
 * fixtures (harmless either way — a fresh ephemeral DB per job in CI never
 * accumulates anything).
 */
$run_id = uniqid( 'csvexport', true );

$customer_id = wp_insert_user(
	array(
		'user_login'   => $run_id . '_customer',
		'user_pass'    => wp_generate_password(),
		'user_email'   => $run_id . '_customer@example.com',
		'display_name' => 'CSV Export Customer',
	)
);
woo_wallet()->wallet->credit( $customer_id, 1000, 'csv export test funding' );

$admin_id = wp_insert_user(
	array(
		'user_login'   => $run_id . '_admin',
		'user_pass'    => wp_generate_password(),
		'user_email'   => $run_id . '_admin@example.com',
		'display_name' => 'CSV Export Admin',
	)
);
get_userdata( $admin_id )->add_cap( 'manage_woocommerce' );
wp_set_current_user( $admin_id );

$banks          = Woo_Wallet_Withdrawal::get_configured_banks();
$transaction_id = woo_wallet()->wallet->debit( $customer_id, 100, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );

$withdrawal_id = Woo_Wallet_Withdrawal::insert_request(
	array(
		'user_id'          => $customer_id,
		'created_by'       => $customer_id, // self-service.
		'transaction_id'   => $transaction_id,
		'amount'           => 100,
		'charge'           => 5,
		'currency'         => woo_wallet()->wallet->resolve_active_currency(),
		'bank_name'        => array_key_first( $banks ),
		'beneficiary_name' => 'CSV Export Beneficiary',
		'account_number'   => '9988776655',
		'phone'            => '01055512345',
		'iban'             => 'EG380019000500000000263180002',
		'reference_no'     => 'TRX-CSV-1',
		'status'           => 'pending',
	)
);

/*
 * export_csv() exits before returning control here, so this cleanup only
 * ever runs as a shutdown function. It runs after the CSV body's own writes
 * to php://output (those go straight to the stream, not through an output
 * buffer this could race with) — this just keeps the local/CI test database
 * from accumulating rows across runs of this one out-of-process script.
 */
register_shutdown_function(
	function () use ( $customer_id, $admin_id, $withdrawal_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woo_wallet_withdrawal_notes', array( 'withdrawal_id' => $withdrawal_id ) );
		$wpdb->delete( $wpdb->prefix . 'woo_wallet_withdrawals', array( 'id' => $withdrawal_id ) );
		$wpdb->delete( $wpdb->prefix . 'woo_wallet_transactions', array( 'user_id' => $customer_id ) );
		foreach ( array( $customer_id, $admin_id ) as $uid ) {
			$wpdb->delete( $wpdb->users, array( 'ID' => $uid ) );
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $uid ) );
		}
	}
);

$_GET['page']          = 'woo-wallet-withdrawals';
$_GET['export_action'] = '1';

Woo_Wallet_Withdrawal::export_csv(); // Writes the CSV to stdout, then exit()s.
