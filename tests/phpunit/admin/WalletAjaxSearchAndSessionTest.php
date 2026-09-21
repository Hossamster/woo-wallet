<?php
/**
 * Two lower-severity findings from the same security review as
 * WalletAjaxNonceTest.php:
 *
 * 1. woo_wallet_user_search() had a nonce but no rate limiting. With the
 *    default exact-match mode, any logged-in customer could probe arbitrary
 *    email addresses and learn (a) whether that email is registered on the
 *    site and (b) the matching username — an enumeration oracle, not a
 *    money-moving bug, but real info disclosure with no throttling at all.
 *    Fixed by rate-limiting it the same way withdrawals/transfers already
 *    are (a per-user transient counter).
 *
 * 2. woo_wallet_partial_payment_update_session() had no nonce at all
 *    (explicitly phpcs:ignore'd). Impact is minor — it only writes a
 *    same-user session preference, never moves money (the actual payment
 *    amount is re-validated under lock at checkout) — but the fix is cheap,
 *    so it's included here too rather than left as accepted debt.
 */
class Wallet_Ajax_Search_And_Session_Test extends WP_Ajax_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Woo_Wallet_Ajax' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-ajax.php';
		}
		// See WalletAjaxNonceTest.php for why this reset is necessary every test.
		( new ReflectionProperty( 'Woo_Wallet_Ajax', '_instance' ) )->setValue( null, null );
		Woo_Wallet_Ajax::instance();

		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );
	}

	// -- woo_wallet_user_search(): rate limiting -----------------------

	public function test_user_search_requires_a_valid_nonce() {
		self::factory()->user->create( array( 'user_email' => 'findme@example.com' ) );
		$_POST['term'] = 'findme@example.com';
		// No 'security' field — forged request.

		try {
			$this->_handleAjax( 'woo-wallet-user-search' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected.
		}
		$this->assertSame( '', $this->_last_response );
	}

	public function test_user_search_finds_a_matching_user_with_a_valid_nonce() {
		self::factory()->user->create( array( 'user_email' => 'findme@example.com' ) );

		$_POST['term']     = 'findme@example.com';
		$_POST['security'] = wp_create_nonce( 'search-user' );

		try {
			$this->_handleAjax( 'woo-wallet-user-search' );
			$this->fail( 'wp_send_json() always ends execution.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$data = json_decode( $this->_last_response, true );
		$this->assertCount( 1, $data );
	}

	public function test_user_search_rate_limits_after_the_configured_threshold() {
		add_filter(
			'woo_wallet_user_search_rate_limit_per_minute',
			function () {
				return 3;
			}
		);
		self::factory()->user->create( array( 'user_email' => 'findme@example.com' ) );

		$search = function () {
			// dieHandler() appends to _last_response rather than replacing
			// it, since a single real request can die more than once as
			// hooks unwind — reset it before each fresh dispatch here, or
			// every call after the first would fail json_decode() on the
			// accumulated (invalid, multi-JSON-document) string.
			$this->_last_response = '';
			$_POST['term']     = 'findme@example.com';
			$_POST['security'] = wp_create_nonce( 'search-user' );
			try {
				$this->_handleAjax( 'woo-wallet-user-search' );
			} catch ( WPAjaxDieContinueException $e ) {
				// Expected every time — rate limiting still returns valid JSON (an empty array), not a hard stop.
			}
			return json_decode( $this->_last_response, true );
		};

		$this->assertCount( 1, $search(), 'Request 1 of 3 must still find the match.' );
		$this->assertCount( 1, $search(), 'Request 2 of 3 must still find the match.' );
		$this->assertCount( 1, $search(), 'Request 3 of 3 must still find the match.' );
		$this->assertCount( 0, $search(), 'Request 4 must be rate-limited — same term, same valid nonce, but the 4th call within a minute.' );
	}

	// -- woo_wallet_partial_payment_update_session(): nonce ------------

	public function test_partial_payment_session_requires_a_valid_nonce() {
		$_POST['checked'] = 'true';
		// No 'security' field — forged request.

		try {
			$this->_handleAjax( 'woo_wallet_partial_payment_update_session' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			$this->assertSame( '', $this->_last_response );
		}
	}

	public function test_partial_payment_session_updates_with_a_valid_nonce() {
		woo_wallet()->wallet->credit( $this->user_id, 100, 'test funding' );
		WC()->session->set( 'partial_payment_amount', 0 );

		$_POST['checked']  = 'true';
		$_POST['security'] = wp_create_nonce( 'woo-wallet-partial-payment-session' );

		try {
			$this->_handleAjax( 'woo_wallet_partial_payment_update_session' );
		} catch ( WPAjaxDieContinueException $e ) {
			// wp_die() with no message still routes through here since dieHandler()
			// only distinguishes on whether _last_response ended up non-empty.
		} catch ( WPAjaxDieStopException $e ) {
			// Also acceptable — wp_die() with empty output.
		}

		$this->assertSame( 100.0, (float) WC()->session->get( 'partial_payment_amount' ), 'A legitimately-authorized request must still update the session preference.' );
	}
}
