<?php
/**
 * Withdrawal receipt privacy: a receipt (bank account number, IBAN,
 * proof-of-payment document) must not stay in the Media Library once it is
 * attached to a withdrawal. Attaching copies the upload into receipt
 * storage under a random key, verifies the copy, saves only that key on the
 * row, and only then deletes the temporary attachment — so neither the
 * original uploads URL nor wp/v2/media/<id> can reach it afterwards, and the
 * only way to read it is the ownership-checked
 * terawallet/v1/me/withdrawals/{id}/receipt endpoint.
 */
class Receipt_Privacy_Test extends WP_UnitTestCase {

	private $customer_id;
	private $admin_id;
	private $external_dir = '';

	public function set_up() {
		parent::set_up();
		$this->customer_id = self::factory()->user->create();
		$this->admin_id    = self::factory()->user->create();
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		woo_wallet()->wallet->credit( $this->customer_id, 1000, 'test funding' );
	}

	public function tear_down() {
		@chmod( Woo_Wallet_Withdrawal::fallback_receipt_dir(), 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $this->external_dir && is_dir( $this->external_dir ) ) {
			array_map( 'unlink', (array) glob( trailingslashit( $this->external_dir ) . '*' ) );
			rmdir( $this->external_dir );
		}
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'front' );
		}
		$GLOBALS['wp_rest_server'] = null;
		$this->remove_added_uploads();
		parent::tear_down();
	}

	// -- fixtures ---------------------------------------------------------

	/**
	 * A real uploaded attachment with a genuine file on disk, NOT marked as
	 * a wallet receipt upload — i.e. an ordinary Media Library item.
	 */
	private function create_uploaded_attachment( $filename = 'one-blue-pixel-100x100.png', $parent = 0 ) {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $filename, $parent );
	}

	/**
	 * The same, marked the way handle_receipt_upload() marks its uploads.
	 */
	private function create_receipt_upload( $filename = 'one-blue-pixel-100x100.png' ) {
		$attachment_id = $this->create_uploaded_attachment( $filename );
		update_post_meta( $attachment_id, Woo_Wallet_Withdrawal::RECEIPT_UPLOAD_META, $this->admin_id );
		return $attachment_id;
	}

	private function create_pending_request() {
		$result = Woo_Wallet_Withdrawal::admin_create(
			$this->customer_id,
			50,
			array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
			'Mohamed Ali',
			'1234567890',
			'01012345678',
			'',
			$this->admin_id,
			'pending'
		);
		$this->assertTrue( $result['is_valid'], 'Precondition: the pending request must be created.' );
		return (int) $result['id'];
	}

	private function seed_legacy_request( $receipt_id ) {
		$transaction_id = woo_wallet()->wallet->debit( $this->customer_id, 10, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );
		return Woo_Wallet_Withdrawal::insert_request(
			array(
				'user_id'          => $this->customer_id,
				'created_by'       => $this->customer_id,
				'transaction_id'   => $transaction_id,
				'amount'           => 10,
				'charge'           => 0,
				'currency'         => woo_wallet()->wallet->resolve_active_currency(),
				'bank_name'        => array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
				'beneficiary_name' => 'Test',
				'account_number'   => '123',
				'phone'            => '01000000000',
				'iban'             => '',
				'reference_no'     => '',
				'status'           => 'paid',
				'receipt_id'       => $receipt_id,
			)
		);
	}

	private function rest( $method, $route ) {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, $route ) );
	}

	private function use_external_dir() {
		$this->external_dir = trailingslashit( get_temp_dir() ) . 'ww-receipts-' . uniqid();
		mkdir( $this->external_dir );
		$dir = $this->external_dir;
		add_filter(
			'woo_wallet_receipts_dir',
			function () use ( $dir ) {
				return $dir;
			}
		);
		return untrailingslashit( wp_normalize_path( realpath( $dir ) ) );
	}

	/**
	 * Make the storage directory read-only so the copy genuinely fails.
	 */
	private function break_receipt_storage() {
		$dir = Woo_Wallet_Withdrawal::fallback_receipt_dir();
		chmod( $dir, 0555 );
		clearstatcache();
		if ( @file_put_contents( trailingslashit( $dir ) . 'probe.txt', 'x' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			unlink( trailingslashit( $dir ) . 'probe.txt' );
			chmod( $dir, 0755 );
			$this->markTestSkipped( 'Cannot make the receipt directory read-only in this environment (running as root?).' );
		}
	}

	// -- storage directories ----------------------------------------------

	public function test_fallback_receipt_dir_is_denied_via_htaccess() {
		$dir = Woo_Wallet_Withdrawal::fallback_receipt_dir();

		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( trailingslashit( $dir ) . '.htaccess' );
		$this->assertStringContainsString( 'deny from all', file_get_contents( trailingslashit( $dir ) . '.htaccess' ) );
		$this->assertFileExists( trailingslashit( $dir ) . 'index.html' );
	}

	public function test_storage_falls_back_to_uploads_when_no_private_dir_is_configured() {
		$this->assertSame( '', Woo_Wallet_Withdrawal::external_receipt_dir() );
		$this->assertFalse( Woo_Wallet_Withdrawal::receipt_dir_misconfigured() );
		$this->assertSame( Woo_Wallet_Withdrawal::fallback_receipt_dir(), Woo_Wallet_Withdrawal::receipt_storage_dir() );
	}

	public function test_a_valid_private_dir_outside_the_web_root_is_used() {
		$expected = $this->use_external_dir();

		$this->assertSame( $expected, Woo_Wallet_Withdrawal::external_receipt_dir() );
		$this->assertSame( $expected, Woo_Wallet_Withdrawal::receipt_storage_dir() );
		$this->assertFalse( Woo_Wallet_Withdrawal::receipt_dir_misconfigured() );
	}

	public function test_a_private_dir_inside_uploads_is_rejected() {
		$upload_dir = wp_upload_dir();
		$inside     = trailingslashit( $upload_dir['basedir'] ) . 'not-actually-private';
		wp_mkdir_p( $inside );
		add_filter(
			'woo_wallet_receipts_dir',
			function () use ( $inside ) {
				return $inside;
			}
		);

		try {
			$this->assertSame( '', Woo_Wallet_Withdrawal::external_receipt_dir(), 'A directory inside uploads is web-reachable and must not count as private.' );
			$this->assertTrue( Woo_Wallet_Withdrawal::receipt_dir_misconfigured() );
			$this->assertSame( Woo_Wallet_Withdrawal::fallback_receipt_dir(), Woo_Wallet_Withdrawal::receipt_storage_dir() );
		} finally {
			rmdir( $inside );
		}
	}

	public function test_a_missing_private_dir_is_rejected_and_the_feature_keeps_working() {
		add_filter(
			'woo_wallet_receipts_dir',
			function () {
				return '/definitely/not/a/real/directory';
			}
		);

		$this->assertSame( '', Woo_Wallet_Withdrawal::external_receipt_dir() );
		$this->assertTrue( Woo_Wallet_Withdrawal::receipt_dir_misconfigured() );

		$id     = $this->create_pending_request();
		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, '', $this->create_receipt_upload() );
		$this->assertTrue( $result['is_valid'] );
		$this->assertNotNull( Woo_Wallet_Withdrawal::resolve_receipt_file( Woo_Wallet_Withdrawal::get_request( $id ) ) );
	}

	public function test_misconfigured_private_dir_shows_a_warning_on_the_withdrawals_screen() {
		add_filter(
			'woo_wallet_receipts_dir',
			function () {
				return '/definitely/not/a/real/directory';
			}
		);
		$this->go_to_withdrawals_screen();
		delete_option( Woo_Wallet_Withdrawal::RECEIPT_PROTECTION_STATUS_OPTION );

		ob_start();
		( new Woo_Wallet_Withdrawal() )->maybe_show_receipt_protection_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'WOO_WALLET_RECEIPTS_DIR', $html );
	}

	// -- attaching a receipt ----------------------------------------------

	/**
	 * The whole point: after attaching, the receipt is no longer a Media
	 * Library attachment at all.
	 */
	public function test_attaching_a_receipt_removes_it_from_the_media_library_entirely() {
		// 426x640 — large enough that WP actually generates thumbnails.
		$attachment_id = $this->create_receipt_upload( '2004-07-22-DSC_0007.jpg' );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$original_path = get_attached_file( $attachment_id );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $original_path ) );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->assertNotEmpty( $metadata['sizes'] ?? array(), 'Precondition: the fixture must generate at least one thumbnail.' );
		$size_paths = array();
		foreach ( $metadata['sizes'] as $size ) {
			$size_paths[] = trailingslashit( dirname( $original_path ) ) . $size['file'];
		}
		$original_hash = hash_file( 'sha256', $original_path );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/media/' . $attachment_id )->get_status(), 'Precondition: the upload is visible in the Media API before it is attached.' );

		$id     = $this->create_pending_request();
		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, 'REF-1', $attachment_id );
		$this->assertTrue( $result['is_valid'] );

		// The original public file and every thumbnail are gone — the
		// original uploads URL has nothing left to serve (a 404).
		$this->assertFileDoesNotExist( $original_path );
		foreach ( $size_paths as $size_path ) {
			$this->assertFileDoesNotExist( $size_path );
		}
		$this->assertFalse( wp_get_attachment_url( $attachment_id ) );

		// The Media Library record is gone: wp/v2/media/<old-id> is a 404.
		$this->assertNull( get_post( $attachment_id ) );
		$this->assertSame( 404, $this->rest( 'GET', '/wp/v2/media/' . $attachment_id )->get_status() );

		// The row holds only a key — no attachment id, no path.
		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 0, (int) $row->receipt_id );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.jpg$/', $row->receipt_key );
		$this->assertStringNotContainsString( 'DSC_0007', $row->receipt_key );

		// And the stored copy is byte-identical to what was uploaded.
		$file = Woo_Wallet_Withdrawal::resolve_receipt_file( $row );
		$this->assertNotNull( $file );
		$this->assertSame( 'image/jpeg', $file['mime'] );
		$this->assertSame( $original_hash, hash_file( 'sha256', $file['path'] ) );
	}

	public function test_the_protected_endpoint_still_serves_the_receipt_to_its_owner() {
		$attachment_id = $this->create_receipt_upload();
		$original_hash = hash_file( 'sha256', get_attached_file( $attachment_id ) );
		$id            = $this->create_pending_request();
		Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, '', $attachment_id );

		add_filter( 'terawallet_rest_receipt_serve_file', '__return_false' );

		wp_set_current_user( $this->customer_id );
		$response = $this->rest( 'GET', '/terawallet/v1/me/withdrawals/' . $id . '/receipt' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'image/png', $response->get_headers()['Content-Type'] );
		$this->assertSame( $original_hash, hash_file( 'sha256', $response->get_data()['file'] ) );

		// The admin can read it too…
		wp_set_current_user( $this->admin_id );
		$this->assertSame( 200, $this->rest( 'GET', '/terawallet/v1/me/withdrawals/' . $id . '/receipt' )->get_status() );

		// …but another customer cannot.
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 404, $this->rest( 'GET', '/terawallet/v1/me/withdrawals/' . $id . '/receipt' )->get_status() );
	}

	public function test_admin_create_attaches_a_receipt_the_same_way() {
		$attachment_id = $this->create_receipt_upload();
		$original_path = get_attached_file( $attachment_id );

		$result = Woo_Wallet_Withdrawal::admin_create(
			$this->customer_id,
			50,
			array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
			'Mohamed Ali',
			'1234567890',
			'01012345678',
			'',
			$this->admin_id,
			'paid',
			'REF-2',
			$attachment_id
		);

		$this->assertTrue( $result['is_valid'] );
		$row = Woo_Wallet_Withdrawal::get_request( $result['id'] );
		$this->assertTrue( Woo_Wallet_Withdrawal::is_valid_receipt_key( $row->receipt_key ) );
		$this->assertSame( 0, (int) $row->receipt_id );
		$this->assertNull( get_post( $attachment_id ) );
		$this->assertFileDoesNotExist( $original_path );
		$this->assertNotNull( Woo_Wallet_Withdrawal::resolve_receipt_file( $row ) );
	}

	public function test_receipts_are_written_to_the_private_dir_when_one_is_configured() {
		$external = $this->use_external_dir();
		$id       = $this->create_pending_request();
		Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, '', $this->create_receipt_upload() );

		$row  = Woo_Wallet_Withdrawal::get_request( $id );
		$file = Woo_Wallet_Withdrawal::resolve_receipt_file( $row );

		$this->assertSame( trailingslashit( $external ) . $row->receipt_key, wp_normalize_path( $file['path'] ) );
		$this->assertFileDoesNotExist( trailingslashit( Woo_Wallet_Withdrawal::fallback_receipt_dir() ) . $row->receipt_key );
	}

	public function test_a_receipt_stored_in_the_fallback_dir_still_resolves_after_a_private_dir_is_added() {
		$id = $this->create_pending_request();
		Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, '', $this->create_receipt_upload() );

		$this->use_external_dir();

		$this->assertNotNull( Woo_Wallet_Withdrawal::resolve_receipt_file( Woo_Wallet_Withdrawal::get_request( $id ) ) );
	}

	// -- ownership: never delete someone else's media ----------------------

	public function test_an_ordinary_media_library_item_cannot_be_attached_or_deleted() {
		$attachment_id = $this->create_uploaded_attachment(); // not marked.
		$original_path = get_attached_file( $attachment_id );
		$id            = $this->create_pending_request();

		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, '', $attachment_id );

		$this->assertFalse( $result['is_valid'] );
		$this->assertNotNull( get_post( $attachment_id ), 'An attachment the wallet did not upload must never be deleted.' );
		$this->assertFileExists( $original_path );

		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 'pending', $row->status );
		$this->assertFalse( Woo_Wallet_Withdrawal::has_receipt( $row ) );
	}

	public function test_stage_receipt_rejects_unmarked_and_non_attachment_ids() {
		$this->assertWPError( Woo_Wallet_Withdrawal::stage_receipt( $this->create_uploaded_attachment() ) );
		$this->assertWPError( Woo_Wallet_Withdrawal::stage_receipt( self::factory()->post->create() ) );
		$this->assertWPError( Woo_Wallet_Withdrawal::stage_receipt( 999999 ) );
		$this->assertWPError( Woo_Wallet_Withdrawal::stage_receipt( 0 ) );
	}

	public function test_receipt_path_rejects_anything_that_is_not_a_well_formed_key() {
		foreach ( array( '', '../../wp-config.php', 'index.html', '.htaccess', 'abc.png', '11111111-2222-3333-4444-555555555555.php', '../11111111-2222-3333-4444-555555555555.png' ) as $bad ) {
			$this->assertSame( '', Woo_Wallet_Withdrawal::receipt_path( $bad ), 'Must reject: ' . $bad );
		}
	}

	// -- failure safety: a failed copy must not lose the receipt -----------

	public function test_a_failed_copy_does_not_delete_the_upload_or_change_the_request() {
		$attachment_id = $this->create_receipt_upload();
		$original_path = get_attached_file( $attachment_id );
		$id            = $this->create_pending_request();
		$this->break_receipt_storage();

		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, 'REF-3', $attachment_id );

		$this->assertFalse( $result['is_valid'] );
		$this->assertTrue( $result['receipt_orphaned'] );

		// The upload is untouched — nothing was lost.
		$this->assertNotNull( get_post( $attachment_id ) );
		$this->assertFileExists( $original_path );
		$this->assertSame( $original_path, get_attached_file( $attachment_id ), 'The attachment must still point at its original file.' );

		// And it was not linked to the request, which was not processed.
		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( '', (string) $row->receipt_key );
		$this->assertSame( 0, (int) $row->receipt_id );
	}

	public function test_a_failed_copy_on_create_reserves_no_funds_and_keeps_the_upload() {
		$attachment_id  = $this->create_receipt_upload();
		$original_path  = get_attached_file( $attachment_id );
		$balance_before = (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' );
		$count_before   = Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) );
		$this->break_receipt_storage();

		$result = Woo_Wallet_Withdrawal::admin_create(
			$this->customer_id,
			50,
			array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ),
			'Mohamed Ali',
			'1234567890',
			'01012345678',
			'',
			$this->admin_id,
			'paid',
			'',
			$attachment_id
		);

		$this->assertFalse( $result['is_valid'] );
		$this->assertSame( $balance_before, (float) woo_wallet()->wallet->get_wallet_balance( $this->customer_id, 'edit' ) );
		$this->assertSame( $count_before, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) ) );
		$this->assertNotNull( get_post( $attachment_id ) );
		$this->assertFileExists( $original_path );
	}

	public function test_a_lost_race_leaves_the_upload_alone_and_no_stray_copy_behind() {
		$id = $this->create_pending_request();
		Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id );

		$attachment_id = $this->create_receipt_upload();
		$before        = glob( trailingslashit( Woo_Wallet_Withdrawal::fallback_receipt_dir() ) . '*.png' );

		$result = Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, '', $attachment_id );

		$this->assertFalse( $result['is_valid'] );
		$this->assertTrue( $result['receipt_orphaned'] );
		$this->assertNotNull( get_post( $attachment_id ) );
		$this->assertSame( $before, glob( trailingslashit( Woo_Wallet_Withdrawal::fallback_receipt_dir() ) . '*.png' ) );
	}

	// -- migration of receipts attached before this model ------------------

	public function test_migration_moves_legacy_receipts_out_of_the_media_library() {
		require_once WOO_WALLET_ABSPATH . 'includes/helper/woo-wallet-update-functions.php';

		$attachment_1 = $this->create_uploaded_attachment( 'one-blue-pixel-100x100.png' );
		$attachment_2 = $this->create_uploaded_attachment( 'one-blue-pixel-1-100x100.png' );
		$path_1       = get_attached_file( $attachment_1 );
		$path_2       = get_attached_file( $attachment_2 );
		$hash_1       = hash_file( 'sha256', $path_1 );
		$id_1         = $this->seed_legacy_request( $attachment_1 );
		$id_2         = $this->seed_legacy_request( $attachment_2 );

		// The upgrade routine itself also runs dbDelta(), whose DDL would
		// implicitly commit this test's transaction — so only the sweep it
		// delegates to is exercised here.
		$this->assertTrue( function_exists( 'woo_wallet_update_183_receipts_out_of_media_library' ) );
		$this->assertSame( 2, Woo_Wallet_Withdrawal::migrate_legacy_receipts() );

		foreach ( array( $id_1, $id_2 ) as $id ) {
			$row = Woo_Wallet_Withdrawal::get_request( $id );
			$this->assertSame( 0, (int) $row->receipt_id );
			$this->assertTrue( Woo_Wallet_Withdrawal::is_valid_receipt_key( $row->receipt_key ) );
		}
		$this->assertFileDoesNotExist( $path_1 );
		$this->assertFileDoesNotExist( $path_2 );
		$this->assertNull( get_post( $attachment_1 ) );
		$this->assertNull( get_post( $attachment_2 ) );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 404, $this->rest( 'GET', '/wp/v2/media/' . $attachment_1 )->get_status() );

		$file = Woo_Wallet_Withdrawal::resolve_receipt_file( Woo_Wallet_Withdrawal::get_request( $id_1 ) );
		$this->assertSame( $hash_1, hash_file( 'sha256', $file['path'] ) );
	}

	public function test_migration_leaves_a_receipt_it_cannot_copy_exactly_as_it_was() {
		// No file on disk at all — the copy cannot succeed.
		$attachment_id = self::factory()->attachment->create_object( array( 'post_mime_type' => 'application/pdf' ) );
		$id            = $this->seed_legacy_request( $attachment_id );

		$this->assertSame( 0, Woo_Wallet_Withdrawal::migrate_legacy_receipts() );

		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( $attachment_id, (int) $row->receipt_id );
		$this->assertSame( '', (string) $row->receipt_key );
		$this->assertNotNull( get_post( $attachment_id ) );
	}

	public function test_migration_keeps_the_original_when_storage_is_unwritable() {
		$attachment_id = $this->create_uploaded_attachment();
		$path          = get_attached_file( $attachment_id );
		$id            = $this->seed_legacy_request( $attachment_id );
		$this->break_receipt_storage();

		Woo_Wallet_Withdrawal::migrate_legacy_receipts();

		$this->assertFileExists( $path );
		$this->assertNotNull( get_post( $attachment_id ) );
		$this->assertSame( $attachment_id, (int) Woo_Wallet_Withdrawal::get_request( $id )->receipt_id );
	}

	public function test_migration_does_not_delete_an_attachment_that_is_in_use_elsewhere() {
		$post_id       = self::factory()->post->create();
		$attachment_id = $this->create_uploaded_attachment( 'one-blue-pixel-100x100.png', $post_id );
		$path          = get_attached_file( $attachment_id );
		$id            = $this->seed_legacy_request( $attachment_id );

		Woo_Wallet_Withdrawal::migrate_legacy_receipts();

		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertTrue( Woo_Wallet_Withdrawal::is_valid_receipt_key( $row->receipt_key ), 'The receipt itself must still be moved to secure storage.' );
		$this->assertNotNull( get_post( $attachment_id ), 'An attachment that belongs to a post must not be deleted.' );
		$this->assertFileExists( $path );

		$notes = wp_list_pluck( Woo_Wallet_Withdrawal::get_notes( $id ), 'note' );
		$this->assertStringContainsString( 'in use elsewhere', implode( ' ', $notes ) );
	}

	public function test_migration_handles_one_attachment_shared_by_two_requests() {
		$attachment_id = $this->create_uploaded_attachment();
		$id_1          = $this->seed_legacy_request( $attachment_id );
		$id_2          = $this->seed_legacy_request( $attachment_id );

		$this->assertSame( 2, Woo_Wallet_Withdrawal::migrate_legacy_receipts() );

		$row_1 = Woo_Wallet_Withdrawal::get_request( $id_1 );
		$row_2 = Woo_Wallet_Withdrawal::get_request( $id_2 );
		$this->assertNotSame( $row_1->receipt_key, $row_2->receipt_key );
		$this->assertNotNull( Woo_Wallet_Withdrawal::resolve_receipt_file( $row_1 ) );
		$this->assertNotNull( Woo_Wallet_Withdrawal::resolve_receipt_file( $row_2 ) );
		$this->assertNull( get_post( $attachment_id ) );
	}

	public function test_migration_is_a_no_op_with_no_existing_receipts() {
		$this->assertSame( 0, Woo_Wallet_Withdrawal::migrate_legacy_receipts() );
	}

	// -- cleanup ------------------------------------------------------------

	public function test_retention_sweep_deletes_the_stored_file_and_clears_the_key() {
		global $wpdb;
		$id = $this->create_pending_request();
		Woo_Wallet_Withdrawal::admin_process( $id, 'paid', $this->admin_id, '', $this->create_receipt_upload() );
		$file = Woo_Wallet_Withdrawal::resolve_receipt_file( Woo_Wallet_Withdrawal::get_request( $id ) );
		$this->assertFileExists( $file['path'] );

		$wpdb->update( $wpdb->prefix . 'woo_wallet_withdrawals', array( 'date_created' => gmdate( 'Y-m-d H:i:s', time() - ( 365 * DAY_IN_SECONDS ) ) ), array( 'id' => $id ) );
		( new Woo_Wallet_Withdrawal() )->cleanup_old_receipts();

		$this->assertFileDoesNotExist( $file['path'] );
		$row = Woo_Wallet_Withdrawal::get_request( $id );
		$this->assertSame( '', (string) $row->receipt_key );
		$this->assertFalse( Woo_Wallet_Withdrawal::has_receipt( $row ) );
	}

	public function test_orphan_sweep_deletes_only_stale_wallet_uploads() {
		$two_days_ago = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$backdate     = function ( $attachment_id ) use ( $two_days_ago ) {
			wp_update_post(
				array(
					'ID'            => $attachment_id,
					'post_date'     => $two_days_ago,
					'post_date_gmt' => $two_days_ago,
				)
			);
		};

		$stale_wallet_upload = $this->create_receipt_upload();
		$fresh_wallet_upload = $this->create_receipt_upload( 'one-blue-pixel-1-100x100.png' );
		$stale_ordinary      = $this->create_uploaded_attachment( 'test-image.png' );
		$backdate( $stale_wallet_upload );
		$backdate( $stale_ordinary );

		Woo_Wallet_Withdrawal::cleanup_orphaned_receipt_uploads();

		$this->assertNull( get_post( $stale_wallet_upload ) );
		$this->assertNotNull( get_post( $fresh_wallet_upload ), 'An upload that may still be about to be attached must be kept.' );
		$this->assertNotNull( get_post( $stale_ordinary ), 'An ordinary Media Library item must never be swept.' );
	}

	public function test_canary_check_is_skipped_when_every_receipt_lives_in_the_private_dir() {
		$this->use_external_dir();
		array_map( 'unlink', (array) glob( trailingslashit( Woo_Wallet_Withdrawal::fallback_receipt_dir() ) . '*-*-*-*-*.*' ) );
		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'No HTTP request should be made when nothing is stored in uploads.' );
			}
		);

		$this->assertSame( 'protected', $this->invoke_verify_receipt_storage_protection() );
	}

	// -- receipt_view_url() -----------------------------------------------

	public function test_receipt_view_url_points_to_the_protected_rest_endpoint() {
		$url = Woo_Wallet_Withdrawal::receipt_view_url( 42, false );
		$this->assertStringContainsString( '/terawallet/v1/me/withdrawals/42/receipt', $url );
	}

	public function test_receipt_view_url_includes_a_nonce_by_default() {
		$url = Woo_Wallet_Withdrawal::receipt_view_url( 42 );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	public function test_receipt_view_url_can_omit_the_nonce() {
		$url = Woo_Wallet_Withdrawal::receipt_view_url( 42, false );
		$this->assertStringNotContainsString( '_wpnonce=', $url );
	}

	// -- receipt_storage_protection_status() / verify_receipt_storage_protection() --

	private function mock_receipt_canary_response( $response_or_error ) {
		add_filter(
			'pre_http_request',
			function () use ( $response_or_error ) {
				return $response_or_error;
			}
		);
	}

	/**
	 * Reads back exactly what verify_receipt_storage_protection() just wrote
	 * to disk and returns it as the HTTP response body — precisely what a
	 * server that does NOT block the directory would actually do.
	 */
	private function mock_receipt_canary_served_back() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$dir      = Woo_Wallet_Withdrawal::fallback_receipt_dir();
				$filename = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$path     = trailingslashit( $dir ) . $filename;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => file_exists( $path ) ? file_get_contents( $path ) : '', // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	private function invoke_verify_receipt_storage_protection() {
		$method = new ReflectionMethod( 'Woo_Wallet_Withdrawal', 'verify_receipt_storage_protection' );
		return $method->invoke( null );
	}

	public function test_verify_returns_protected_when_the_canary_request_is_blocked() {
		$this->mock_receipt_canary_response(
			array(
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'body'     => '',
				'headers'  => array(),
			)
		);

		$this->assertSame( 'protected', $this->invoke_verify_receipt_storage_protection() );
	}

	/**
	 * The confirmed-leak case: a 200 response whose body is the canary's
	 * exact (random, unpredictable) content — proof the server actually
	 * served the file rather than merely responding 200 to something else.
	 */
	public function test_verify_returns_leaking_when_the_canary_content_is_served_back() {
		$this->mock_receipt_canary_served_back();

		$this->assertSame( 'leaking', $this->invoke_verify_receipt_storage_protection() );
	}

	/**
	 * A site that can't complete the check at all (e.g. outbound loopback
	 * HTTP requests blocked by the host) must not be reported as either
	 * confirmed state — the inability to verify is not evidence of safety,
	 * but it is not evidence of a leak either.
	 */
	public function test_verify_returns_unknown_on_a_connection_error() {
		$this->mock_receipt_canary_response( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );

		$this->assertSame( 'unknown', $this->invoke_verify_receipt_storage_protection() );
	}

	public function test_receipt_storage_protection_status_reads_the_cached_value() {
		update_option( Woo_Wallet_Withdrawal::RECEIPT_PROTECTION_STATUS_OPTION, array( 'status' => 'leaking', 'checked_at' => time() ) );
		$this->assertSame( 'leaking', Woo_Wallet_Withdrawal::receipt_storage_protection_status() );
	}

	public function test_receipt_storage_protection_status_is_null_before_the_first_check() {
		delete_option( Woo_Wallet_Withdrawal::RECEIPT_PROTECTION_STATUS_OPTION );
		$this->assertNull( Woo_Wallet_Withdrawal::receipt_storage_protection_status() );
	}

	public function test_check_receipt_protection_caches_the_verification_result() {
		$this->mock_receipt_canary_response(
			array(
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'body'     => '',
				'headers'  => array(),
			)
		);

		( new Woo_Wallet_Withdrawal() )->check_receipt_protection();

		$this->assertSame( 'protected', Woo_Wallet_Withdrawal::receipt_storage_protection_status() );
	}

	// -- maybe_show_receipt_protection_notice() ---------------------------

	private function go_to_withdrawals_screen() {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( woo_wallet_get_screen_id( 'woo-wallet-withdrawals' ) );
	}

	public function test_receipt_protection_notice_shows_when_leaking() {
		$this->go_to_withdrawals_screen();
		update_option( Woo_Wallet_Withdrawal::RECEIPT_PROTECTION_STATUS_OPTION, array( 'status' => 'leaking', 'checked_at' => time() ) );

		ob_start();
		( new Woo_Wallet_Withdrawal() )->maybe_show_receipt_protection_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'does not restrict access', $html );
	}

	public function test_receipt_protection_notice_hidden_unless_confirmed_leaking() {
		$this->go_to_withdrawals_screen();
		$withdrawal = new Woo_Wallet_Withdrawal();

		foreach ( array( 'protected', 'unknown', null ) as $status ) {
			if ( null === $status ) {
				delete_option( Woo_Wallet_Withdrawal::RECEIPT_PROTECTION_STATUS_OPTION );
			} else {
				update_option( Woo_Wallet_Withdrawal::RECEIPT_PROTECTION_STATUS_OPTION, array( 'status' => $status, 'checked_at' => time() ) );
			}

			ob_start();
			$withdrawal->maybe_show_receipt_protection_notice();
			$html = ob_get_clean();

			$this->assertSame( '', $html, 'Must show nothing for status: ' . var_export( $status, true ) );
		}
	}

	public function test_receipt_protection_notice_hidden_outside_the_withdrawals_screen() {
		set_current_screen( 'front' );
		update_option( Woo_Wallet_Withdrawal::RECEIPT_PROTECTION_STATUS_OPTION, array( 'status' => 'leaking', 'checked_at' => time() ) );

		ob_start();
		( new Woo_Wallet_Withdrawal() )->maybe_show_receipt_protection_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
