<?php
/**
 * Receipt handling: retention-window configuration, the daily cleanup cron
 * scheduling, and cleanup_old_receipts() itself — the sweep that actually
 * deletes the Media Library attachment and clears receipt_id once a request
 * is older than the retention window. This is the feature the user
 * explicitly confirmed they want enforced, not just described in the UI.
 *
 * maybe_handle_receipt_upload()'s successful-upload path is not covered
 * here: it delegates to core's wp_handle_upload(), which gates on the real
 * is_uploaded_file() check — true only for an actual HTTP file upload, which
 * a PHPUnit CLI run cannot produce. Its "no file submitted" branch (no
 * upload attempted) is covered, since that doesn't touch that check.
 */
class Receipt_Retention_Test extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create();
		woo_wallet()->wallet->credit( $this->user_id, 1000, 'test funding' );

		// The real plugin-registered Woo_Wallet_Withdrawal instance schedules
		// this on 'init' during the very first bootstrap, before any test's
		// DB transaction opens — so removing it in tear_down() gets rolled
		// back along with everything else, and it reappears "already
		// scheduled" at the start of the next test. Normalize here too.
		wp_clear_scheduled_hook( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK );
	}

	public function tear_down() {
		// wp_schedule_event()/wp_clear_scheduled_hook() touch the cron
		// option directly — leave no scheduled event behind for later tests.
		wp_clear_scheduled_hook( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK );
		parent::tear_down();
	}

	/**
	 * maybe_schedule_receipt_cleanup()/cleanup_old_receipts() are plain
	 * instance methods, but the plugin's own `new Woo_Wallet_Withdrawal()`
	 * (bottom of the class file) isn't exposed via any accessor. Neither
	 * method reads instance state, so a throwaway instance behaves
	 * identically — cached per test run to avoid re-registering its
	 * constructor's hooks (admin screens, form handlers, ...) once per test.
	 *
	 * @return Woo_Wallet_Withdrawal
	 */
	private static function withdrawal_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new Woo_Wallet_Withdrawal();
		}
		return $instance;
	}

	private function create_request_with_receipt( $days_old, $receipt_id = null ) {
		$banks = Woo_Wallet_Withdrawal::get_configured_banks();
		if ( null === $receipt_id ) {
			$receipt_id = self::factory()->attachment->create_object(
				array(
					'post_mime_type' => 'application/pdf',
					'post_title'     => 'receipt.pdf',
				)
			);
		}
		$transaction_id = woo_wallet()->wallet->debit( $this->user_id, 100, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );
		$id             = Woo_Wallet_Withdrawal::insert_request(
			array(
				'user_id'          => $this->user_id,
				'created_by'       => $this->user_id,
				'transaction_id'   => $transaction_id,
				'amount'           => 100,
				'charge'           => 0,
				'currency'         => woo_wallet()->wallet->resolve_active_currency(),
				'bank_name'        => array_key_first( $banks ),
				'beneficiary_name' => 'Mohamed Ali',
				'account_number'   => '1234567890',
				'phone'            => '01012345678',
				'iban'             => '',
				'reference_no'     => '',
				'status'           => 'paid',
				'receipt_id'       => $receipt_id,
			)
		);

		global $wpdb;
		$date_created = gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) );
		$wpdb->update( $wpdb->prefix . 'woo_wallet_withdrawals', array( 'date_created' => $date_created ), array( 'id' => $id ) );

		return array( $id, $receipt_id );
	}

	// -- retention_days() ---------------------------------------------------

	public function test_default_retention_is_90_days() {
		$this->assertSame( 90, Woo_Wallet_Withdrawal::receipt_retention_days() );
	}

	public function test_retention_is_filterable() {
		add_filter(
			'woo_wallet_withdrawal_receipt_retention_days',
			function () {
				return 30;
			}
		);
		$this->assertSame( 30, Woo_Wallet_Withdrawal::receipt_retention_days() );
	}

	// -- cron scheduling ------------------------------------------------

	public function test_maybe_schedule_receipt_cleanup_schedules_a_daily_event() {
		$this->assertFalse( wp_next_scheduled( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK ) );

		self::withdrawal_instance()->maybe_schedule_receipt_cleanup();

		$this->assertNotFalse( wp_next_scheduled( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK ) );
		$event = wp_get_scheduled_event( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK );
		$this->assertSame( 'daily', $event->schedule );
	}

	public function test_maybe_schedule_receipt_cleanup_does_not_duplicate_an_existing_schedule() {
		self::withdrawal_instance()->maybe_schedule_receipt_cleanup();
		$first_timestamp = wp_next_scheduled( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK );

		self::withdrawal_instance()->maybe_schedule_receipt_cleanup();
		$second_timestamp = wp_next_scheduled( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK );

		$this->assertSame( $first_timestamp, $second_timestamp );
	}

	public function test_unschedule_receipt_cleanup_clears_the_event() {
		self::withdrawal_instance()->maybe_schedule_receipt_cleanup();
		$this->assertNotFalse( wp_next_scheduled( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK ) );

		self::withdrawal_instance()->unschedule_receipt_cleanup();

		$this->assertFalse( wp_next_scheduled( Woo_Wallet_Withdrawal::RECEIPT_CLEANUP_HOOK ) );
	}

	// -- cleanup_old_receipts() ------------------------------------------

	public function test_cleanup_removes_a_receipt_past_the_retention_window() {
		list( $id, $receipt_id ) = $this->create_request_with_receipt( 91 ); // default retention is 90 days.

		$fired_ids = array();
		add_action(
			'woo_wallet_withdrawal_receipt_expired',
			function ( $withdrawal_id ) use ( &$fired_ids ) {
				$fired_ids[] = $withdrawal_id;
			}
		);

		self::withdrawal_instance()->cleanup_old_receipts();

		$this->assertNull( get_post( $receipt_id ), 'The Media Library attachment must actually be deleted.' );

		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 0, (int) $row->receipt_id, 'receipt_id must be cleared on the request row.' );

		$notes = Woo_Wallet_Withdrawal::get_notes( $id, 'private' );
		$this->assertNotEmpty( $notes );
		$this->assertStringContainsString( 'automatically removed', $notes[0]->note );

		$this->assertSame( array( $id ), array_map( 'intval', $fired_ids ), 'woo_wallet_withdrawal_receipt_expired must fire exactly once with this request id.' );
	}

	public function test_cleanup_leaves_a_receipt_within_the_retention_window_untouched() {
		list( $id, $receipt_id ) = $this->create_request_with_receipt( 30 ); // well within the default 90-day window.

		self::withdrawal_instance()->cleanup_old_receipts();

		$this->assertNotNull( get_post( $receipt_id ), 'A receipt still within the retention window must not be deleted.' );
		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( $receipt_id, (int) $row->receipt_id );
	}

	public function test_cleanup_does_nothing_when_retention_is_disabled() {
		list( $id, $receipt_id ) = $this->create_request_with_receipt( 365 ); // very old.

		add_filter(
			'woo_wallet_withdrawal_receipt_retention_days',
			function () {
				return 0;
			}
		);

		self::withdrawal_instance()->cleanup_old_receipts();

		$this->assertNotNull( get_post( $receipt_id ), 'retention_days <= 0 must disable the sweep entirely.' );
		$this->assertSame( $receipt_id, (int) Woo_Wallet_Withdrawal::get_request( $id )->receipt_id );
	}

	public function test_cleanup_ignores_requests_with_no_receipt() {
		$banks          = Woo_Wallet_Withdrawal::get_configured_banks();
		$transaction_id = woo_wallet()->wallet->debit( $this->user_id, 100, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );
		$id             = Woo_Wallet_Withdrawal::insert_request(
			array(
				'user_id'          => $this->user_id,
				'created_by'       => $this->user_id,
				'transaction_id'   => $transaction_id,
				'amount'           => 100,
				'charge'           => 0,
				'currency'         => woo_wallet()->wallet->resolve_active_currency(),
				'bank_name'        => array_key_first( $banks ),
				'beneficiary_name' => 'Mohamed Ali',
				'account_number'   => '1234567890',
				'phone'            => '01012345678',
				'iban'             => '',
				'reference_no'     => '',
				'status'           => 'paid',
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'woo_wallet_withdrawals', array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( 365 * DAY_IN_SECONDS ) ) ), array( 'id' => $id ) );

		// Must not error/warn on a row with receipt_id = 0.
		self::withdrawal_instance()->cleanup_old_receipts();

		$this->assertSame( 0, (int) Woo_Wallet_Withdrawal::get_request( $id )->receipt_id );
	}

	public function test_cleanup_handles_multiple_expired_receipts_in_one_sweep() {
		list( $id1 ) = $this->create_request_with_receipt( 100 );
		list( $id2 ) = $this->create_request_with_receipt( 200 );
		list( $id3 ) = $this->create_request_with_receipt( 10 ); // not expired.

		self::withdrawal_instance()->cleanup_old_receipts();

		$this->assertSame( 0, (int) Woo_Wallet_Withdrawal::get_request( $id1 )->receipt_id );
		$this->assertSame( 0, (int) Woo_Wallet_Withdrawal::get_request( $id2 )->receipt_id );
		$this->assertGreaterThan( 0, (int) Woo_Wallet_Withdrawal::get_request( $id3 )->receipt_id );
	}

	// -- maybe_handle_receipt_upload(): only the no-file branch is testable
	// without a real HTTP upload (see class docblock). ---------------------

	public function test_maybe_handle_receipt_upload_returns_zero_when_no_file_submitted() {
		$reflection = new ReflectionMethod( 'Woo_Wallet_Withdrawal', 'maybe_handle_receipt_upload' );
		unset( $_FILES['receipt'] );

		$result = $reflection->invoke( null, 'receipt' );

		$this->assertSame( 0, $result['id'] );
		$this->assertSame( '', $result['error'] );
	}
}
