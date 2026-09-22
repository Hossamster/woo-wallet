<?php
/**
 * Woo_Wallet_Dashboard_Widget::ajax_quick_credit() — the Quick Credit action
 * (Phase 3), the one credit-only AJAX path the widget offers.
 *
 * Extends WP_Ajax_UnitTestCase for the same reason as
 * DashboardWidgetAjaxTest/WalletAjaxNonceTest: check_ajax_referer() and
 * wp_send_json_*() both call a raw die() unless routed through
 * WP_Ajax_UnitTestCase's dieHandler().
 */
class Dashboard_Widget_Quick_Credit_Test extends WP_Ajax_UnitTestCase {

	private $admin_id;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-dashboard-widget.php';
		}
		// Fresh instance per test re-attaches the wp_ajax_ hooks
		// _restore_hooks() wipes after every test — see
		// DashboardWidgetAjaxTest's docblock for the full reasoning.
		new Woo_Wallet_Dashboard_Widget();

		// 'administrator' only has manage_woocommerce when
		// WC_Install::create_roles() has run against this test database —
		// not guaranteed on a fresh CI database. Grant it explicitly, same
		// as WalletAjaxNonceTest does for its own capability.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $this->admin_id )->add_cap( 'manage_woocommerce' );
	}

	private function balance( $user_id ) {
		return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
	}

	public function test_rejects_a_request_without_a_valid_nonce() {
		wp_set_current_user( $this->admin_id );
		$_POST['user']   = 'someone@example.com';
		$_POST['amount'] = 10;

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_quick_credit' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected: no output at all.
		}

		$this->assertSame( '', $this->_last_response );
	}

	public function test_rejects_a_valid_nonce_without_the_wallet_capability() {
		$plain_user = self::factory()->user->create(); // no manage_woocommerce.
		wp_set_current_user( $plain_user );
		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['user']      = 'someone@example.com';
		$_POST['amount']    = 10;

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_quick_credit' );
			$this->fail( 'Expected the capability check to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected: wp_die( -1 ), no output.
		}

		$this->assertSame( '', $this->_last_response );
	}

	public function test_rejects_an_unknown_customer() {
		wp_set_current_user( $this->admin_id );
		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['user']      = 'nobody-with-this-email@example.com';
		$_POST['amount']    = 10;

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_quick_credit' );
			$this->fail( 'wp_send_json_error() always ends execution.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: real JSON output.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
	}

	public function test_rejects_a_zero_or_negative_amount() {
		$customer = self::factory()->user->create( array( 'user_email' => 'quickcredit@example.com' ) );
		wp_set_current_user( $this->admin_id );
		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['user']      = 'quickcredit@example.com';
		$_POST['amount']    = 0;

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_quick_credit' );
			$this->fail( 'wp_send_json_error() always ends execution.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 0.0, $this->balance( $customer ) );
	}

	public function test_credits_the_customer_found_by_email() {
		$customer = self::factory()->user->create( array( 'user_email' => 'byemail@example.com' ) );
		wp_set_current_user( $this->admin_id );
		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['user']      = 'byemail@example.com';
		$_POST['amount']    = 25;
		$_POST['note']      = 'test quick credit';

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_quick_credit' );
			$this->fail( 'wp_send_json_success() always ends execution.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 25.0, $this->balance( $customer ) );
	}

	public function test_credits_the_customer_found_by_login_when_not_an_email() {
		$customer = self::factory()->user->create( array( 'user_login' => 'quickcreditlogin' ) );
		wp_set_current_user( $this->admin_id );
		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['user']      = 'quickcreditlogin';
		$_POST['amount']    = 15;

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_quick_credit' );
			$this->fail( 'wp_send_json_success() always ends execution.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 15.0, $this->balance( $customer ) );
	}
}
