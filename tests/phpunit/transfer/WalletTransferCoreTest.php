<?php
require_once __DIR__ . '/class-transfer-test-case.php';

/**
 * Woo_Wallet_Wallet::transfer() — the low-level atomic debit+credit primitive
 * (GET_LOCK-based concurrency safety, single DB transaction). Both the
 * customer self-service flow (via WooWallet_Transfer_Service, see
 * TransferServiceTest.php) and the admin REST transfer route call this
 * directly, so its own guarantees are tested independently of either caller.
 */
class Wallet_Transfer_Core_Test extends Transfer_Test_Case {

	private $from_id;
	private $to_id;

	public function set_up() {
		parent::set_up();
		$this->from_id = self::factory()->user->create();
		$this->to_id   = self::factory()->user->create();
		$this->track_users( $this->from_id, $this->to_id );
	}

	private function balance( $user_id ) {
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}

	public function test_rejects_transfer_to_self() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );
		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->from_id, 10, 'debit', 'credit' );
		$this->assertFalse( $result );
	}

	public function test_rejects_zero_or_negative_amount() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );
		$this->assertFalse( woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 0, 'd', 'c' ) );
		$this->assertFalse( woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, -10, 'd', 'c' ) );
	}

	public function test_rejects_when_sender_or_recipient_id_is_missing() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );
		$this->assertFalse( woo_wallet()->wallet->transfer( 0, $this->to_id, 10, 'd', 'c' ) );
		$this->assertFalse( woo_wallet()->wallet->transfer( $this->from_id, 0, 10, 'd', 'c' ) );
	}

	public function test_rejects_when_sender_balance_is_insufficient() {
		woo_wallet()->wallet->credit( $this->from_id, 50, 'funding' );
		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 100, 'd', 'c' );

		$this->assertFalse( $result );
		$this->assertSame( 50.0, $this->balance( $this->from_id ) );
		$this->assertSame( 0.0, $this->balance( $this->to_id ) );
	}

	public function test_rejects_when_sender_has_zero_balance() {
		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 10, 'd', 'c' );
		$this->assertFalse( $result );
	}

	public function test_rejects_when_sender_account_is_locked() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );
		update_user_meta( $this->from_id, '_is_wallet_locked', true );

		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 10, 'd', 'c' );
		$this->assertFalse( $result );
		$this->assertSame( 100.0, $this->balance( $this->from_id ) );
	}

	public function test_rejects_when_recipient_account_is_locked() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );
		update_user_meta( $this->to_id, '_is_wallet_locked', true );

		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 10, 'd', 'c' );
		$this->assertFalse( $result );
	}

	public function test_successful_transfer_moves_the_full_amount_by_default() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );

		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 40, 'debit note', 'credit note' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['debit'] );
		$this->assertNotEmpty( $result['credit'] );
		$this->assertSame( 60.0, $this->balance( $this->from_id ) );
		$this->assertSame( 40.0, $this->balance( $this->to_id ) );
	}

	public function test_debit_and_credit_amounts_can_differ_eg_for_a_transfer_charge() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );

		// Sender pays 40 + charge (debited amount), recipient only gets 40 (credit_amount).
		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 45, 'debit note', 'credit note', 40 );

		$this->assertIsArray( $result );
		$this->assertSame( 55.0, $this->balance( $this->from_id ) ); // 100 - 45.
		$this->assertSame( 40.0, $this->balance( $this->to_id ) );
	}

	public function test_a_failed_transfer_leaves_no_partial_rows() {
		woo_wallet()->wallet->credit( $this->from_id, 10, 'funding' );

		global $wpdb;
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woo_wallet_transactions WHERE user_id IN (%d,%d)", $this->from_id, $this->to_id ) );

		woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 1000, 'd', 'c' ); // insufficient balance.

		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woo_wallet_transactions WHERE user_id IN (%d,%d)", $this->from_id, $this->to_id ) );
		$this->assertSame( $before, $after );
	}

	public function test_locks_are_released_so_a_failed_transfer_does_not_block_a_later_one() {
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );

		// This one fails (insufficient balance against the *attempted* amount).
		$this->assertFalse( woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 1000, 'd', 'c' ) );

		// If the GET_LOCK from the failed attempt weren't released, this would
		// time out and also fail even though the balance is now sufficient.
		$result = woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 10, 'd', 'c' );
		$this->assertIsArray( $result );
		$this->assertSame( 90.0, $this->balance( $this->from_id ) );
	}

	public function test_transfer_order_does_not_matter_for_lock_acquisition() {
		// The lock ordering is by min/max user id, not by who's sender vs
		// recipient — exercise both directions between the same pair.
		woo_wallet()->wallet->credit( $this->from_id, 100, 'funding' );
		woo_wallet()->wallet->credit( $this->to_id, 100, 'funding' );

		$this->assertIsArray( woo_wallet()->wallet->transfer( $this->from_id, $this->to_id, 10, 'd', 'c' ) );
		$this->assertIsArray( woo_wallet()->wallet->transfer( $this->to_id, $this->from_id, 10, 'd', 'c' ) );

		$this->assertSame( 100.0, $this->balance( $this->from_id ) ); // -10 +10.
		$this->assertSame( 100.0, $this->balance( $this->to_id ) );
	}
}
