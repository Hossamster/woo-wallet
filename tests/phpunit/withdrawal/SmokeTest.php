<?php
/**
 * Confirms the test harness itself boots: WordPress, WooCommerce, and this
 * plugin are all loaded and talking to the test database.
 */
class Smoke_Test extends WP_UnitTestCase {

	public function test_wordpress_and_woocommerce_are_loaded() {
		$this->assertTrue( function_exists( 'wc_price' ) );
		$this->assertTrue( class_exists( 'WooCommerce' ) );
	}

	public function test_woo_wallet_plugin_is_loaded() {
		$this->assertTrue( function_exists( 'woo_wallet' ) );
		$this->assertTrue( class_exists( 'Woo_Wallet_Withdrawal' ) );
	}

	public function test_withdrawal_table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'woo_wallet_withdrawals';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_wallet_credit_and_debit_actually_move_money() {
		global $wpdb;
		$transactions_table = $wpdb->prefix . 'woo_wallet_transactions';
		$this->assertSame( $transactions_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $transactions_table ) ) );

		$user_id = self::factory()->user->create();

		$credit_id = woo_wallet()->wallet->credit( $user_id, 100, 'smoke test credit' );
		$this->assertNotFalse( $credit_id );
		$this->assertSame( 100.0, (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' ) );

		$debit_id = woo_wallet()->wallet->debit( $user_id, 40, 'smoke test debit' );
		$this->assertNotFalse( $debit_id );
		$this->assertSame( 60.0, (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' ) );
	}
}
