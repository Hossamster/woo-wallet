<?php
/**
 * Tests targeting the exact money-safety guarantees added after the manual
 * review that found: (1) two staff members could race to process the same
 * request, (2) a reject could double-refund if interrupted mid-flight. These
 * tests exercise Woo_Wallet_Withdrawal::admin_process(), admin_recover(),
 * and the shared idempotent_refund() they both call — not just the happy
 * path, but the exact crash/retry/race scenarios the ledger-tag design
 * exists to survive.
 */
class Admin_Process_Money_Safety_Test extends WP_UnitTestCase {

	private $user_id;
	private $admin_id;

	public function set_up() {
		parent::set_up();
		$this->user_id  = self::factory()->user->create();
		$this->admin_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->user_id, 1000, 'test funding' );
	}

	private function balance() {
		return (float) woo_wallet()->wallet->get_wallet_balance( $this->user_id, 'edit' );
	}

	/**
	 * Create a pending withdrawal request directly, bypassing submit_request()
	 * validation — these tests are about admin_process()/admin_recover(), not
	 * customer-facing validation (already covered in SubmitRequestTest).
	 */
	private function create_pending_request( $amount = 100, $charge = 0 ) {
		$banks = Woo_Wallet_Withdrawal::get_configured_banks();
		$transaction_id = woo_wallet()->wallet->debit( $this->user_id, $amount + $charge, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );
		$this->assertNotFalse( $transaction_id, 'Precondition: debit must succeed to set up a pending request.' );

		$id = Woo_Wallet_Withdrawal::insert_request(
			array(
				'user_id'          => $this->user_id,
				'created_by'       => $this->user_id,
				'transaction_id'   => $transaction_id,
				'amount'           => $amount,
				'charge'           => $charge,
				'currency'         => woo_wallet()->wallet->resolve_active_currency(),
				'bank_name'        => array_key_first( $banks ),
				'beneficiary_name' => 'Mohamed Ali',
				'account_number'   => '1234567890',
				'phone'            => '01012345678',
				'iban'             => '',
				'reference_no'     => '',
				'status'           => 'pending',
			)
		);
		$this->assertNotEmpty( $id, 'Precondition: insert_request() must succeed.' );
		return $id;
	}

	private function count_ledger_credits_for_user() {
		global $wpdb;
		$table = $wpdb->prefix . 'woo_wallet_transactions';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND type = 'credit' AND deleted = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->user_id
			)
		);
	}

	// -- 'paid' path -----------------------------------------------------

	public function test_marking_paid_does_not_touch_the_wallet() {
		$id              = $this->create_pending_request( 100 );
		$balance_before  = $this->balance();

		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( $balance_before, $this->balance() );
		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 'paid', $row->status );
		$this->assertSame( $this->admin_id, (int) $row->processed_by );
	}

	public function test_two_admins_racing_to_mark_paid_only_one_wins() {
		$id = $this->create_pending_request( 100 );

		// Simulate the race directly: the real safety is the atomic
		// `WHERE status = 'pending'` conditional update, so calling
		// admin_process() twice in a row against the same starting state
		// exercises exactly that — the second call must see status is no
		// longer 'pending' and no-op.
		$first  = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id );
		$second = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', self::factory()->user->create() );

		$this->assertTrue( $first['is_valid'] );
		$this->assertFalse( $second['is_valid'] );

		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( $this->admin_id, (int) $row->processed_by, 'The second, losing call must not have overwritten processed_by.' );
	}

	// -- 'reject' path: single call ---------------------------------------

	public function test_rejecting_refunds_the_reserved_amount_plus_charge() {
		$id             = $this->create_pending_request( 100, 10 );
		$balance_before = $this->balance();

		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'reject', $this->admin_id );

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( $balance_before + 110.0, $this->balance() );

		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 'rejected', $row->status );
		$this->assertNotEmpty( $row->refund_transaction_id );
	}

	// -- reject called twice (retry / two admins racing) -------------------

	public function test_rejecting_an_already_rejected_request_does_not_refund_twice() {
		$id = $this->create_pending_request( 100 );

		$first = Woo_Wallet_Withdrawal::admin_process( $id, 'reject', $this->admin_id );
		$this->assertTrue( $first['is_valid'] );
		$balance_after_first_reject = $this->balance();
		$credits_after_first        = $this->count_ledger_credits_for_user();

		// Second call: whether from a naive retry or a second admin who
		// raced to reject the same row — the status is no longer 'pending',
		// so the atomic claim step must reject it outright.
		$second = Woo_Wallet_Withdrawal::admin_process( $id, 'reject', self::factory()->user->create() );

		$this->assertFalse( $second['is_valid'] );
		$this->assertSame( $balance_after_first_reject, $this->balance(), 'A second reject attempt must not move any money.' );
		$this->assertSame( $credits_after_first, $this->count_ledger_credits_for_user(), 'A second reject attempt must not create a second ledger credit.' );
	}

	// -- the interrupted-reject scenario: this is the exact bug the ledger-tag
	// design was built to prevent (crash after credit(), before the row is
	// finalized to 'rejected') -------------------------------------------

	public function test_idempotent_refund_reused_when_called_twice_for_the_same_request() {
		$id  = $this->create_pending_request( 100 );
		$row = Woo_Wallet_Withdrawal::get_request( $id );

		$reflection = new ReflectionMethod( 'Woo_Wallet_Withdrawal', 'idempotent_refund' );

		$balance_before = $this->balance();

		// First call: as the live reject flow does — issues one real credit.
		$first_credit_id = $reflection->invoke( null, $row );
		$this->assertNotEmpty( $first_credit_id );
		$this->assertSame( $balance_before + 100.0, $this->balance() );

		// Second call against the *same unchanged row object* — simulates a
		// recovery attempt (or a retried call) that runs before the row's
		// own `refund_transaction_id` column was ever written, i.e. exactly
		// the crash window between credit() succeeding and the row being
		// finalized. It must find the ledger tag and reuse it rather than
		// crediting again.
		$second_credit_id = $reflection->invoke( null, $row );

		$this->assertSame( $first_credit_id, $second_credit_id, 'A repeated refund attempt must return the same, already-issued transaction id.' );
		$this->assertSame( $balance_before + 100.0, $this->balance(), 'A repeated refund attempt must not move any additional money.' );
	}

	public function test_recovering_a_request_stuck_on_processing_refunds_exactly_once() {
		$id = $this->create_pending_request( 100 );

		// Manually put the row into the 'processing' state that a reject
		// interrupted mid-flight (after claiming the row, before the refund
		// resolved) would leave behind — see admin_process()'s reject branch.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'woo_wallet_withdrawals',
			array(
				'status'       => 'processing',
				'processed_by' => $this->admin_id,
			),
			array( 'id' => $id )
		);

		$balance_before = $this->balance();

		$first = Woo_Wallet_Withdrawal::admin_recover( $id, $this->admin_id );
		$this->assertTrue( $first['is_valid'] );
		$this->assertSame( $balance_before + 100.0, $this->balance() );

		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 'rejected', $row->status );

		// A second recovery attempt (another admin also opening the stuck
		// request, or a retried call) must find the row already resolved
		// and, even if it somehow ran again, idempotent_refund() would find
		// the existing ledger tag rather than crediting a second time.
		$second = Woo_Wallet_Withdrawal::admin_recover( $id, self::factory()->user->create() );
		$this->assertFalse( $second['is_valid'], 'Recovering an already-rejected (no longer processing) request should no-op.' );
		$this->assertSame( $balance_before + 100.0, $this->balance(), 'A second recovery attempt must not refund again.' );
	}

	public function test_recovering_a_request_that_is_not_stuck_is_a_safe_noop() {
		$id = $this->create_pending_request( 100 );
		$balance_before = $this->balance();

		// Still 'pending', never claimed — nothing to recover.
		$result = Woo_Wallet_Withdrawal::admin_recover( $id, $this->admin_id );

		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( $balance_before, $this->balance() );
		$this->assertSame( 'pending', Woo_Wallet_Withdrawal::get_request( $id )->status );
	}

	// -- refund failure path: money must not vanish -----------------------

	public function test_reject_reverts_to_pending_when_the_refund_credit_fails() {
		$id = $this->create_pending_request( 100 );

		// Force the one call idempotent_refund() makes — wallet->credit() —
		// to fail, by swapping in a stub for the duration of this test.
		// `Woo_Wallet::$wallet` is a plain public property, so this is a
		// clean substitution with no DB-schema tampering involved.
		$real_wallet = woo_wallet()->wallet;
		woo_wallet()->wallet = new class() {
			public function credit( ...$args ) {
				return false;
			}
		};

		try {
			$result = Woo_Wallet_Withdrawal::admin_process( $id, 'reject', $this->admin_id );
		} finally {
			woo_wallet()->wallet = $real_wallet;
		}

		$this->assertFalse( $result['is_valid'] );
		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 'pending', $row->status, 'A failed refund must leave the request pending (retryable), never stuck rejected/processing.' );
	}
}
