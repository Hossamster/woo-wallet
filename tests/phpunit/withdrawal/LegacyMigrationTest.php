<?php
/**
 * Regression test for a bug found while building the Phase 0 test harness:
 * the legacy 1.0.8–1.3.21 `$db_updates` migrations in
 * woo-wallet-update-functions.php built their SHOW COLUMNS / ALTER TABLE
 * queries with `$wpdb->prepare( '... `%s` ...', $table_name )`. Since %s is
 * always substituted as a quoted string, that produced
 * `` SHOW COLUMNS FROM `'wp_woo_wallet_transactions'` `` (backticks around a
 * quoted string — a syntax error) instead of a real backtick-quoted
 * identifier, and `LIKE `` `column` `` `` instead of `LIKE 'column'`. On a
 * fresh install this silently no-ops (dbDelta's current schema already has
 * every column these add), so it was never caught — but it would have
 * failed outright on a real upgrade from a pre-1.0.8 install missing these
 * columns. These tests simulate that: drop a column dbDelta's current
 * schema already includes, run the historical migration that's supposed to
 * add it back, and assert it actually does — with no SQL error.
 */
class Legacy_Migration_Test extends WP_UnitTestCase {

	private $table;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->table = $wpdb->base_prefix . 'woo_wallet_transactions';
	}

	public function tear_down() {
		// Dropping a column/index is DDL (implicit commit — WP_UnitTestCase's
		// transaction rollback won't undo it), so explicitly restore the
		// full modern schema afterwards rather than relying on rollback.
		// install() alone doesn't reliably re-add a dropped *index* on an
		// existing table (see the migration's own docblock) — re-run that
		// migration explicitly too, so a test that drops idx_deleted_date
		// can't leave it missing for whatever test runs next.
		Woo_Wallet_Install::install();
		woo_wallet_update_1712_db_schema();
		parent::tear_down();
	}

	private function column_exists( $column ) {
		global $wpdb;
		return (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table}` LIKE '{$column}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	private function drop_column( $column ) {
		global $wpdb;
		$wpdb->query( "ALTER TABLE `{$this->table}` DROP COLUMN `{$column}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertFalse( $this->column_exists( $column ), 'Precondition: column must actually be gone before testing the migration that re-adds it.' );
	}

	public function test_update_108_adds_missing_currency_column_without_sql_error() {
		global $wpdb;
		$this->drop_column( 'currency' );

		woo_wallet_update_108_db_column();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertTrue( $this->column_exists( 'currency' ) );
	}

	public function test_update_110_adds_missing_blog_id_column_without_sql_error() {
		global $wpdb;
		$this->drop_column( 'blog_id' );

		woo_wallet_update_110_db_column();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertTrue( $this->column_exists( 'blog_id' ) );
	}

	public function test_update_117_adds_missing_deleted_column_without_sql_error() {
		global $wpdb;
		$this->drop_column( 'deleted' );

		woo_wallet_update_117_db_column();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertTrue( $this->column_exists( 'deleted' ) );
	}

	public function test_update_1312_adds_missing_created_by_column_without_sql_error() {
		global $wpdb;
		$this->drop_column( 'created_by' );

		woo_wallet_update_1312_db_column();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertTrue( $this->column_exists( 'created_by' ) );
	}

	public function test_update_1310_modifies_amount_and_balance_columns_without_sql_error() {
		global $wpdb;
		// `balance` doesn't exist in the current schema (removed in 1.5.18),
		// so this only exercises the `amount` MODIFY — still the exact
		// query shape (`ALTER TABLE ... MODIFY COLUMN`) that was broken.
		woo_wallet_update_1310_db_column();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertTrue( $this->column_exists( 'amount' ) );
	}

	/**
	 * Confirmed empirically while adding this index (see the docblock on
	 * woo_wallet_update_1712_db_schema()): dbDelta() reliably adds a brand
	 * new table with every index from get_schema() intact, but silently
	 * fails to ALTER an *existing* table to add a new secondary index — the
	 * exact situation this migration runs in on a real upgrade. If this
	 * migration were naively written as a plain dbDelta() call (as every
	 * other schema migration in this file is, since dbDelta is otherwise
	 * reliable for column changes), it would no-op on real upgrades and
	 * this test would catch that: it fails today by asserting the index is
	 * present after the drop+run, not merely that no SQL error occurred.
	 */
	public function test_update_1712_adds_missing_transactions_date_index() {
		global $wpdb;
		$wpdb->query( "ALTER TABLE `{$this->table}` DROP INDEX `idx_deleted_date`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '', $wpdb->last_error, 'Precondition: the drop itself must succeed.' );

		woo_wallet_update_1712_db_schema();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertNotEmpty(
			$wpdb->get_results( "SHOW INDEX FROM `{$this->table}` WHERE Key_name = 'idx_deleted_date'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'idx_deleted_date must exist after the migration runs against a table that was missing it.'
		);
	}

	public function test_update_1712_is_idempotent_when_the_index_already_exists() {
		global $wpdb;
		// Don't drop it first — runs against a table that already has the
		// index (the normal case for every subsequent plugin load after the
		// first). Must not error trying to add a duplicate key.
		woo_wallet_update_1712_db_schema();
		woo_wallet_update_1712_db_schema();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertCount(
			2, // one row per column in the composite (deleted, date) index.
			$wpdb->get_results( "SHOW INDEX FROM `{$this->table}` WHERE Key_name = 'idx_deleted_date'" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}
}
