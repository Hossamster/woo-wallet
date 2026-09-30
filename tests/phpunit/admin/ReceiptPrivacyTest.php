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
}
