<?php
/**
 * Woo_Wallet_Security: a dedicated key (enc:v2:) that nothing but the site
 * owner changes, a canary that notices when a key has changed, a clear
 * message instead of enc:v1:… when data cannot be read, and a tool to move
 * stored data to the current key.
 *
 * Key changes are simulated with the woo_wallet_encryption_key and
 * woo_wallet_legacy_encryption_salt filters — exactly what editing
 * wp-config.php does to the derived keys.
 */
class Encryption_Key_Test extends WP_UnitTestCase {

	private $admin_id;
	private $customer_id;
	private $dedicated_key = '';
	private $legacy_salt   = '';

	public function set_up() {
		parent::set_up();
		delete_option( Woo_Wallet_Security::CANARY_OPTION );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
		$this->customer_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		woo_wallet()->wallet->credit( $this->customer_id, 1000, 'test funding' );
		add_filter( 'woo_wallet_encryption_key', array( $this, 'filter_key' ) );
		add_filter( 'woo_wallet_legacy_encryption_salt', array( $this, 'filter_salt' ) );
	}

	public function filter_key( $key ) {
		return $this->dedicated_key;
	}

	public function filter_salt( $salt ) {
		return '' !== $this->legacy_salt ? $this->legacy_salt : $salt;
	}

	private function create_withdrawal( $account = '1234567890' ) {
		$result = Woo_Wallet_Withdrawal::admin_create( $this->customer_id, 10, array_key_first( Woo_Wallet_Withdrawal::get_configured_banks() ), 'Mohamed Ali', $account, '01012345678', 'EG380019000500000000263180002', $this->admin_id, 'pending' );
		$this->assertTrue( $result['is_valid'], $result['message'] );
		return (int) $result['id'];
	}

	private function raw_account( $id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT account_number FROM {$wpdb->base_prefix}woo_wallet_withdrawals WHERE id = %d", $id ) );
	}

	public function test_without_a_dedicated_key_values_use_the_wordpress_salts() {
		$cipher = Woo_Wallet_Security::encrypt( '1234567890' );
		$this->assertStringStartsWith( 'enc:v1:', $cipher );
		$this->assertSame( '1234567890', Woo_Wallet_Security::decrypt( $cipher ) );
	}

	public function test_with_a_dedicated_key_new_values_use_it_and_old_ones_still_read() {
		$old                 = Woo_Wallet_Security::encrypt( '1111' );
		$this->dedicated_key = 'a-dedicated-key';
		$new                 = Woo_Wallet_Security::encrypt( '2222' );

		$this->assertStringStartsWith( 'enc:v2:', $new );
		$this->assertSame( '2222', Woo_Wallet_Security::decrypt( $new ) );
		$this->assertSame( '1111', Woo_Wallet_Security::decrypt( $old ) );
	}

	public function test_a_changed_wordpress_salt_shows_a_clear_message_instead_of_ciphertext() {
		$cipher            = Woo_Wallet_Security::encrypt( '1234567890' );
		$this->legacy_salt = 'regenerated-by-a-security-plugin';

		$this->assertTrue( Woo_Wallet_Security::is_unreadable( $cipher ) );
		$this->assertSame( Woo_Wallet_Security::unreadable_text(), Woo_Wallet_Security::reveal( $cipher ) );
		$this->assertSame( 'plain', Woo_Wallet_Security::reveal( 'plain' ), 'Plaintext is shown as it is.' );
	}

	public function test_the_canary_reports_a_changed_salt_only_when_encrypted_data_exists() {
		Woo_Wallet_Security::ensure_canaries();
		$this->legacy_salt = 'regenerated';
		$this->assertSame( array(), Woo_Wallet_Security::key_problems(), 'Nothing is encrypted yet, so nothing is lost.' );

		$this->legacy_salt = '';
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$this->create_withdrawal();
		$this->legacy_salt = 'regenerated';

		$this->assertSame( array( 'v1' ), Woo_Wallet_Security::key_problems() );
	}

	public function test_removing_the_dedicated_key_is_reported() {
		$this->dedicated_key = 'a-dedicated-key';
		Woo_Wallet_Security::ensure_canaries();
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$id = $this->create_withdrawal();
		$this->assertStringStartsWith( 'enc:v2:', $this->raw_account( $id ) );

		$this->dedicated_key = '';

		$this->assertSame( array( 'v2' ), Woo_Wallet_Security::key_problems() );
		$this->assertSame( Woo_Wallet_Security::unreadable_text(), Woo_Wallet_Withdrawal::get_request( $id )->account_number );
	}

	public function test_administrators_see_the_warning_on_admin_screens() {
		Woo_Wallet_Security::ensure_canaries();
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$this->create_withdrawal();
		$this->legacy_salt = 'regenerated';

		wp_set_current_user( $this->admin_id );
		ob_start();
		( new Woo_Wallet_Staff() )->maybe_show_key_problem_notice();
		$this->assertStringContainsString( 'security keys (salts)', ob_get_clean() );
	}

	public function test_acknowledging_a_lost_key_stops_the_warning_but_not_the_truth() {
		Woo_Wallet_Security::ensure_canaries();
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$id                = $this->create_withdrawal();
		$this->legacy_salt = 'regenerated';

		Woo_Wallet_Security::acknowledge_lost_key( 'v1' );

		$this->assertSame( array(), Woo_Wallet_Security::key_problems() );
		$this->assertSame( Woo_Wallet_Security::unreadable_text(), Woo_Wallet_Withdrawal::get_request( $id )->account_number );
	}

	public function test_turning_encryption_on_encrypts_new_and_existing_withdrawals() {
		$existing = $this->create_withdrawal( '1111222233' );
		$this->assertSame( '1111222233', $this->raw_account( $existing ) );

		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$new = $this->create_withdrawal( '4444555566' );
		$this->assertStringStartsWith( 'enc:', $this->raw_account( $new ) );

		$result = Woo_Wallet_Security::reencrypt_stored();
		$this->assertSame( 0, $result['remaining'] );
		$this->assertStringStartsWith( 'enc:', $this->raw_account( $existing ) );
		$this->assertSame( '1111222233', Woo_Wallet_Withdrawal::get_request( $existing )->account_number );
	}

	public function test_reencrypting_moves_salt_encrypted_data_to_the_dedicated_key() {
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$withdrawal = $this->create_withdrawal();
		wp_set_current_user( self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) ) );
		$request = Woo_Wallet_Approvals::create( 'withdrawal', $this->customer_id, 10, array( 'bank_name' => 'Bank', 'beneficiary_name' => 'X', 'account_number' => '999988887777', 'phone' => '01012345678' ), '' );
		$this->assertStringStartsWith( 'enc:v1:', $this->raw_account( $withdrawal ) );

		$this->dedicated_key = 'a-dedicated-key';
		Woo_Wallet_Security::ensure_canaries();
		$result = Woo_Wallet_Security::reencrypt_stored();

		$this->assertSame( 0, $result['remaining'] );
		$this->assertStringStartsWith( 'enc:v2:', $this->raw_account( $withdrawal ) );
		$this->assertStringContainsString( 'enc:v2:', Woo_Wallet_Approvals::get( $request )->payload );
		$this->assertStringNotContainsString( 'enc:v1:', Woo_Wallet_Approvals::get( $request )->payload );
		$this->assertSame( '999988887777', Woo_Wallet_Approvals::details( Woo_Wallet_Approvals::get( $request ) )['account_number'] );
		$this->assertSame( '1234567890', Woo_Wallet_Withdrawal::get_request( $withdrawal )->account_number );
	}

	public function test_reencrypting_is_refused_while_a_key_has_changed() {
		Woo_Wallet_Security::ensure_canaries();
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$this->create_withdrawal();
		$this->legacy_salt = 'regenerated';

		$this->assertSame( 'woo_wallet_key_problem', Woo_Wallet_Security::reencrypt_stored()->get_error_code() );
	}

	public function test_an_approval_with_unreadable_bank_details_is_not_carried_out() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Woo_Wallet_Staff::ROLE ) ) );
		$request           = Woo_Wallet_Approvals::create( 'withdrawal', $this->customer_id, 10, array( 'bank_name' => 'Bank', 'beneficiary_name' => 'X', 'account_number' => '999988887777', 'phone' => '01012345678' ), '' );
		$this->legacy_salt = 'regenerated';

		wp_set_current_user( $this->admin_id );
		$result = Woo_Wallet_Approvals::approve( $request );

		$this->assertSame( 'woo_wallet_approval_unreadable', $result->get_error_code() );
		$this->assertSame( 0, Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $this->customer_id ) ) );
	}

	/**
	 * An encrypted IBAN is ~115 characters; the column used to be
	 * varchar(64), so with encryption on, every withdrawal with an IBAN was
	 * refused at insert.
	 */
	public function test_an_encrypted_iban_fits_its_column() {
		update_option( Woo_Wallet_Security::ENABLED_OPTION, 'yes' );
		$id = $this->create_withdrawal();
		$this->assertSame( 'EG380019000500000000263180002', Woo_Wallet_Withdrawal::get_request( $id )->iban );
	}

	public function test_the_safeguards_tab_renders() {
		wp_set_current_user( $this->admin_id );
		$_GET = array( 'page' => 'woo-wallet-staff', 'tab' => 'safeguards' );
		ob_start();
		( new Woo_Wallet_Staff() )->render_page();
		$html = ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( 'WOO_WALLET_ENCRYPTION_KEY', $html );
		$this->assertStringContainsString( 'large_threshold', $html );
		$this->assertStringContainsString( 'encrypt_bank_details', $html );
	}
}
