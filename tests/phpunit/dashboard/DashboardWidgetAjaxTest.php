<?php
/**
 * Woo_Wallet_Dashboard_Widget::ajax_refresh() — the AJAX action backing the
 * Phase 2 date-range tabs (Today / 7 Days / This Month): re-renders the
 * snapshot body for a selected period without a full wp-admin page reload.
 *
 * Extends WP_Ajax_UnitTestCase for the same reason WalletAjaxNonceTest does
 * (see that file's docblock): check_ajax_referer() and wp_send_json_*()
 * both call a raw die() unless routed through WP_Ajax_UnitTestCase's own
 * dieHandler(), which throws WPAjaxDieStopException (no output) or
 * WPAjaxDieContinueException (real output) instead.
 */
class Dashboard_Widget_Ajax_Test extends WP_Ajax_UnitTestCase {

	private $admin_id;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-dashboard-widget.php';
		}
		/*
		 * WP_UnitTestCase_Base's _restore_hooks() wipes every hook
		 * registered during a test (see WalletAjaxNonceTest's docblock for
		 * the full explanation) — including this class's own wp_ajax_
		 * registration from its constructor. Unlike Woo_Wallet_Ajax this
		 * class isn't a singleton, so a plain `new` (rather than a
		 * Reflection-based singleton reset) is enough to re-run the
		 * constructor and re-attach the hook fresh for this test.
		 */
		new Woo_Wallet_Dashboard_Widget();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_rejects_a_request_without_a_valid_nonce() {
		wp_set_current_user( $this->admin_id );
		$_POST['period'] = '7days';
		// Deliberately no 'security' field.

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_refresh' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected: no output at all.
		}

		$this->assertSame( '', $this->_last_response );
	}

	public function test_rejects_a_nonce_from_the_wrong_action() {
		wp_set_current_user( $this->admin_id );
		$_POST['security'] = wp_create_nonce( 'some-other-action' );
		$_POST['period']   = 'today';

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_refresh' );
			$this->fail( 'Expected check_ajax_referer() to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected.
		}

		$this->assertSame( '', $this->_last_response );
	}

	public function test_rejects_a_valid_nonce_without_the_wallet_capability() {
		$plain_user = self::factory()->user->create(); // no manage_woocommerce.
		wp_set_current_user( $plain_user );
		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['period']   = 'today';

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_refresh' );
			$this->fail( 'Expected the capability check to stop execution.' );
		} catch ( WPAjaxDieStopException $e ) {
			// Expected: wp_die( -1 ), no output.
		}

		$this->assertSame( '', $this->_last_response );
	}

	public function test_valid_request_returns_the_requested_periods_body_html() {
		global $wpdb;
		wp_set_current_user( $this->admin_id );
		$customer_id = self::factory()->user->create();
		$three_ago   = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -3 days' ) );
		$wpdb->insert(
			$wpdb->base_prefix . 'woo_wallet_transactions',
			array(
				'user_id'  => $customer_id,
				'type'     => 'credit',
				'category' => 'other',
				'amount'   => 77,
				'currency' => 'USD',
				'deleted'  => 0,
				'date'     => $three_ago . ' 10:00:00',
			)
		);

		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['period']   = '7days';

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_refresh' );
			$this->fail( 'wp_send_json_success() always ends execution.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected: real JSON output.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertSame( '7days', $response['data']['period'] );
		$this->assertStringContainsString( '77', $response['data']['html'] );
	}

	public function test_an_unrecognised_period_falls_back_to_today() {
		wp_set_current_user( $this->admin_id );
		$_POST['security'] = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
		$_POST['period']   = 'not-a-real-period';

		try {
			$this->_handleAjax( 'woo_wallet_dashboard_widget_refresh' );
			$this->fail( 'wp_send_json_success() always ends execution.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertSame( 'today', $response['data']['period'] );
	}
}
