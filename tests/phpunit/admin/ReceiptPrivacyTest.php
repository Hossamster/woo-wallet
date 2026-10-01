<?php
/**
 * Woo_Wallet_Withdrawal::protect_receipt_file()/receipt_storage_dir()/
 * receipt_view_url() — closing the gap where a withdrawal receipt (bank
 * account number, IBAN, proof-of-payment document) remained reachable at
 * its ordinary, guessable Media Library URL forever, even though
 * TeraWallet_REST_Me_Withdrawal_Controller::get_receipt() already existed
 * as a capability/ownership-checked way to fetch it: that endpoint
 * controlled access to a copy it read via get_attached_file(), but never
 * stopped the original public URL from working, and nothing in the admin
 * UI or admin REST API used it anyway — both still linked the raw
 * wp_get_attachment_url() directly.
 */
class Receipt_Privacy_Test extends WP_UnitTestCase {

	/**
	 * A real uploaded attachment with a genuine file on disk —
	 * create_object()'s bare post-only fixture has no _wp_attached_file at
	 * all, which is exactly the "nothing to protect" case protect_receipt_file()
	 * must no-op on, so it's unsuitable for testing the real relocation path.
	 */
	private function create_uploaded_attachment( $filename = 'one-blue-pixel-100x100.png' ) {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $filename );
	}

	// -- receipt_storage_dir() ------------------------------------------

	public function test_receipt_storage_dir_is_denied_via_htaccess() {
		$dir = Woo_Wallet_Withdrawal::receipt_storage_dir();

		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( trailingslashit( $dir ) . '.htaccess' );
		$this->assertStringContainsString( 'deny from all', file_get_contents( trailingslashit( $dir ) . '.htaccess' ) );
		$this->assertFileExists( trailingslashit( $dir ) . 'index.html' );
	}

	// -- protect_receipt_file() ------------------------------------------

	public function test_protect_receipt_file_moves_the_file_out_of_the_public_uploads_path() {
		$attachment_id = $this->create_uploaded_attachment();
		$original_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $original_path );

		Woo_Wallet_Withdrawal::protect_receipt_file( $attachment_id );

		$this->assertFileDoesNotExist( $original_path, 'The file must no longer exist at its original, publicly-reachable location.' );

		$new_path = get_attached_file( $attachment_id );
		$this->assertFileExists( $new_path );
		$this->assertStringStartsWith(
			wp_normalize_path( Woo_Wallet_Withdrawal::receipt_storage_dir() ),
			wp_normalize_path( $new_path )
		);
	}

	public function test_protect_receipt_file_updates_the_attachment_url_to_the_protected_directory() {
		$attachment_id = $this->create_uploaded_attachment();
		Woo_Wallet_Withdrawal::protect_receipt_file( $attachment_id );

		// wp_get_attachment_url() is driven by _wp_attached_file, which
		// protect_receipt_file() updated — so even code that (wrongly) still
		// called it directly would no longer see the original public URL.
		$this->assertStringContainsString( 'woo-wallet-receipts', wp_get_attachment_url( $attachment_id ) );
	}

	public function test_protect_receipt_file_is_idempotent() {
		$attachment_id = $this->create_uploaded_attachment();
		Woo_Wallet_Withdrawal::protect_receipt_file( $attachment_id );
		$path_after_first = get_attached_file( $attachment_id );

		// A second call must not move it again, rename it to a
		// wp_unique_filename()-deduplicated sibling, or error.
		Woo_Wallet_Withdrawal::protect_receipt_file( $attachment_id );

		$this->assertSame( $path_after_first, get_attached_file( $attachment_id ) );
		$this->assertFileExists( $path_after_first );
	}

	public function test_protect_receipt_file_does_nothing_for_a_non_attachment_id() {
		$post_id = self::factory()->post->create();
		// Must not error or touch anything for an id that isn't an attachment.
		Woo_Wallet_Withdrawal::protect_receipt_file( $post_id );
		$this->assertSame( '', (string) get_attached_file( $post_id ) );
	}

	public function test_protect_receipt_file_does_nothing_for_zero_or_missing_id() {
		Woo_Wallet_Withdrawal::protect_receipt_file( 0 );
		Woo_Wallet_Withdrawal::protect_receipt_file( 999999 );
		$this->assertTrue( true ); // Must not throw/fatal.
	}

	/**
	 * Nothing in this plugin ever requests a receipt at any size but the
	 * original — a generated thumbnail left behind at a predictable
	 * filename in the old public directory would otherwise go on leaking
	 * the image after the original is protected.
	 */
	public function test_protect_receipt_file_deletes_generated_intermediate_sizes() {
		// 426x640 — large enough that WP actually generates a thumbnail;
		// the default fixture (100x100) is smaller than the default
		// thumbnail size (150x150), so WP skips generating one for it.
		$attachment_id = $this->create_uploaded_attachment( '2004-07-22-DSC_0007.jpg' );
		// create_upload_object() doesn't generate intermediate sizes itself —
		// do it explicitly, the way a real wp/v2/media upload would.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$original_path = get_attached_file( $attachment_id );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $original_path ) );

		$metadata_before = wp_get_attachment_metadata( $attachment_id );
		$this->assertNotEmpty( $metadata_before['sizes'] ?? array(), 'Precondition: the fixture must actually generate at least one intermediate size.' );

		$old_dir        = trailingslashit( dirname( $original_path ) );
		$size_file_paths = array();
		foreach ( $metadata_before['sizes'] as $size ) {
			$size_file_paths[] = $old_dir . $size['file'];
		}
		foreach ( $size_file_paths as $path ) {
			$this->assertFileExists( $path, 'Precondition: the generated size file must exist before protecting.' );
		}

		Woo_Wallet_Withdrawal::protect_receipt_file( $attachment_id );

		foreach ( $size_file_paths as $path ) {
			$this->assertFileDoesNotExist( $path, 'A generated intermediate size must not be left behind in the old public location.' );
		}

		$metadata_after = wp_get_attachment_metadata( $attachment_id );
		$this->assertEmpty( $metadata_after['sizes'] ?? array() );
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

	/**
	 * TeraWallet_REST_Me_Withdrawal_Controller::get_receipt() resolves the
	 * file purely via get_attached_file() + file_exists() + get_post_mime_type()
	 * — it never touches wp_get_attachment_url() or otherwise assumes a
	 * location — before calling readfile()+exit() on success. Calling the
	 * real method here isn't safe (that exit() would terminate the whole
	 * PHPUnit process, not just this test), so this proves the exact
	 * preconditions it checks still hold after relocation, which is the
	 * only way protecting the file could have broken it.
	 */
	public function test_the_protected_endpoints_own_preconditions_still_resolve_after_relocation() {
		$attachment_id = $this->create_uploaded_attachment();
		Woo_Wallet_Withdrawal::protect_receipt_file( $attachment_id );

		$file_path = get_attached_file( $attachment_id );
		$this->assertNotEmpty( $file_path );
		$this->assertFileExists( $file_path );
		$this->assertNotEmpty( get_post_mime_type( $attachment_id ) );
	}

	/**
	 * The `.htaccess` deny only ever applies on Apache with AllowOverride
	 * permitting it. On any other server configuration it does nothing at
	 * all, which means the filename itself is the only thing left standing
	 * between a request and the file — it must not be a recognisable,
	 * guessable name.
	 */
	public function test_protect_receipt_file_uses_a_random_unguessable_filename() {
		$attachment_id = $this->create_uploaded_attachment( 'one-blue-pixel-100x100.png' );
		Woo_Wallet_Withdrawal::protect_receipt_file( $attachment_id );

		$new_basename = wp_basename( get_attached_file( $attachment_id ) );

		$this->assertStringNotContainsString( 'one-blue-pixel', $new_basename );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{40}(-\d+)?\.png$/', $new_basename );
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
				$dir      = Woo_Wallet_Withdrawal::receipt_storage_dir();
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

	public function tear_down() {
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'front' );
		}
		parent::tear_down();
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

	// -- migration: woo_wallet_update_182_protect_existing_receipts() -----

	private function seed_withdrawal_with_receipt( $customer_id, $receipt_id ) {
		woo_wallet()->wallet->credit( $customer_id, 1000, 'test funding' );
		$transaction_id = woo_wallet()->wallet->debit( $customer_id, 10, 'reserved for withdrawal test', array( 'category' => 'withdrawal' ) );
		return Woo_Wallet_Withdrawal::insert_request(
			array(
				'user_id'          => $customer_id,
				'created_by'       => $customer_id,
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
				'status'           => 'pending',
				'receipt_id'       => $receipt_id,
			)
		);
	}

	public function test_migration_protects_every_existing_receipt() {
		if ( ! function_exists( 'woo_wallet_update_182_protect_existing_receipts' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/helper/woo-wallet-update-functions.php';
		}

		$customer_id  = self::factory()->user->create();
		$attachment_1 = $this->create_uploaded_attachment( 'one-blue-pixel-100x100.png' );
		$attachment_2 = $this->create_uploaded_attachment( 'one-blue-pixel-1-100x100.png' );

		$original_path_1 = get_attached_file( $attachment_1 );
		$original_path_2 = get_attached_file( $attachment_2 );

		$this->seed_withdrawal_with_receipt( $customer_id, $attachment_1 );
		$this->seed_withdrawal_with_receipt( $customer_id, $attachment_2 );

		woo_wallet_update_182_protect_existing_receipts();

		$this->assertFileDoesNotExist( $original_path_1 );
		$this->assertFileDoesNotExist( $original_path_2 );
		$this->assertStringContainsString( 'woo-wallet-receipts', wp_normalize_path( get_attached_file( $attachment_1 ) ) );
		$this->assertStringContainsString( 'woo-wallet-receipts', wp_normalize_path( get_attached_file( $attachment_2 ) ) );
	}

	public function test_migration_is_a_no_op_with_no_existing_receipts() {
		if ( ! function_exists( 'woo_wallet_update_182_protect_existing_receipts' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/helper/woo-wallet-update-functions.php';
		}
		woo_wallet_update_182_protect_existing_receipts();
		$this->assertTrue( true ); // Must not error.
	}
}
