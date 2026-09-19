<?php
/**
 * Shared base for any test that calls Woo_Wallet_Wallet::transfer()
 * (directly, or via WooWallet_Transfer_Service::execute()).
 *
 * transfer() issues its own explicit START TRANSACTION/COMMIT around the
 * debit+credit pair (needed for its GET_LOCK-based concurrency safety in
 * real use). MySQL doesn't support real nested transactions: that inner
 * START TRANSACTION implicitly commits WP_UnitTestCase's own per-test outer
 * transaction the moment it runs — permanently, for real, regardless of
 * tear_down()'s later ROLLBACK. Confirmed empirically while building this
 * suite: a single test that funds a wallet and calls transfer() leaves 3
 * permanent rows (the funding credit, the transfer debit, the transfer
 * credit) in woo_wallet_transactions after the test "finishes".
 *
 * WordPress's own object factories do NOT defend against this — they rely
 * on the same transaction rollback everything else does, same as this
 * plugin's own tables. Confirmed empirically: a two-test probe where test 1
 * creates users and calls transfer() showed test 2 could still see test 1's
 * "rolled back" users and ledger rows. This base class deletes every wallet
 * ledger row AND every wp_users/wp_usermeta row belonging to any user id a
 * test registers via track_users(), so this class of test leaves the shared
 * local/CI database exactly as it found it.
 *
 * File is named without a "Test.php" suffix so PHPUnit's directory-based
 * suite discovery (see phpunit.xml.dist) doesn't try to load it as a test
 * case in its own right.
 */
abstract class Transfer_Test_Case extends WP_Test_REST_TestCase {

	/**
	 * @var int[]
	 */
	private $tracked_user_ids = array();

	public function set_up() {
		parent::set_up();
		// The plugin only loads this lazily from the frontend form handler or
		// the REST controller — never on its own — so a test that calls
		// WooWallet_Transfer_Service directly must load it itself first.
		require_once WOO_WALLET_ABSPATH . 'includes/services/class-woo-wallet-transfer-service.php';
	}

	/**
	 * Register a user id whose wallet ledger rows must be swept in
	 * tear_down(), because this test may end up calling transfer() at some
	 * point and implicitly commit whatever was written for that user before
	 * then, too.
	 *
	 * @param int ...$user_ids
	 */
	protected function track_users( ...$user_ids ) {
		foreach ( $user_ids as $id ) {
			if ( $id ) {
				$this->tracked_user_ids[] = (int) $id;
			}
		}
	}

	public function tear_down() {
		if ( $this->tracked_user_ids ) {
			global $wpdb;
			$ids          = array_values( array_unique( array_map( 'intval', $this->tracked_user_ids ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$txn_table    = $wpdb->prefix . 'woo_wallet_transactions';
			$meta_table   = $wpdb->prefix . 'woo_wallet_transaction_meta';

			$txn_ids = $wpdb->get_col( $wpdb->prepare( "SELECT transaction_id FROM {$txn_table} WHERE user_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			if ( $txn_ids ) {
				$txn_placeholders = implode( ',', array_fill( 0, count( $txn_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE transaction_id IN ({$txn_placeholders})", $txn_ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$txn_table} WHERE user_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE ID IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

			/*
			 * transfer()'s own internal COMMIT (see the class docblock) already
			 * closed WP_UnitTestCase's outer per-test transaction, so with
			 * autocommit still off, everything since then — including the
			 * DELETEs just above — is sitting in a new, uncommitted implicit
			 * transaction. Without this, parent::tear_down()'s ROLLBACK
			 * (meant to undo the *test's* work) undoes this cleanup too,
			 * right back to leaking the same rows it just removed.
			 */
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			$this->tracked_user_ids = array();
		}
		parent::tear_down();
	}
}
