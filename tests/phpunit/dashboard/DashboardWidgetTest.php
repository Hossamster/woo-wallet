<?php
/**
 * Woo_Wallet_Dashboard_Widget — rendering + hook registration.
 *
 * The overview panel was previously registered as a wp-admin Dashboard
 * widget (wp-admin/index.php). It now hooks onto `woo_wallet_reports_page_top`
 * and is embedded in the plugin's own Reports page (`admin.php?page=woo-wallet`).
 *
 * These tests prove:
 *   - render() produces output containing the Financial Health Snapshot
 *     markup (backed by Woo_Wallet_Dashboard_Widget_Data).
 *   - render() returns nothing for a user without the wallet capability.
 *   - The hook is woo_wallet_reports_page_top (not wp_dashboard_setup).
 */
class Dashboard_Widget_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		// Load the module the same way Woo_Wallet::includes() would on an
		// admin request (is_request('admin') branch).
		if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-dashboard-widget.php';
		}
	}

	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The 'administrator' role only has manage_woocommerce when
	 * WC_Install::create_roles() has run against this test database — not
	 * guaranteed on a fresh CI database. Grant the exact capability needed
	 * explicitly rather than relying on the role implying it.
	 */
	private function create_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user_id )->add_cap( 'manage_woocommerce' );
		return $user_id;
	}

	/**
	 * The overview panel now hooks onto woo_wallet_reports_page_top, NOT
	 * wp_dashboard_setup — verify the action is wired up when the class loads.
	 */
	public function test_overview_panel_hooks_onto_reports_page_top() {
		$widget = new Woo_Wallet_Dashboard_Widget();

		$this->assertGreaterThan(
			0,
			has_action( 'woo_wallet_reports_page_top', array( $widget, 'render' ) ),
			'render() must be hooked onto woo_wallet_reports_page_top.'
		);
	}

	/**
	 * The panel must NOT hook onto wp_dashboard_setup any more — its place is
	 * the plugin Reports page, not the WordPress main dashboard.
	 */
	public function test_overview_panel_does_not_register_on_wp_dashboard_setup() {
		$widget = new Woo_Wallet_Dashboard_Widget();

		$this->assertFalse(
			has_action( 'wp_dashboard_setup', array( $widget, 'register_widget' ) ),
			'register_widget() must no longer be hooked onto wp_dashboard_setup.'
		);
	}

	/**
	 * render() must produce Financial Health Snapshot markup for a user who
	 * holds the wallet capability.
	 */
	public function test_render_outputs_the_snapshot_body_with_no_fatal() {
		$admin_id = $this->create_admin();
		wp_set_current_user( $admin_id );

		$widget = new Woo_Wallet_Dashboard_Widget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'woo-wallet-dashboard-widget', $output );
		$this->assertStringContainsString( 'Outstanding liability', $output );
	}

	/**
	 * render() must return nothing (empty output) for a user without the
	 * wallet capability — the capability check is in the template itself.
	 */
	public function test_render_is_empty_for_a_user_without_wallet_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$widget = new Woo_Wallet_Dashboard_Widget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString(
			'woo-wallet-dashboard-widget',
			$output,
			'A user without manage_woocommerce must see no overview panel output.'
		);
	}

	/**
	 * Proves render() actually reaches the data service and reflects real
	 * ledger/withdrawal state — a pending withdrawal's amount must show up
	 * formatted in the output.
	 */
	public function test_render_reflects_a_pending_withdrawal() {
		global $wpdb;
		$admin_id    = $this->create_admin();
		$customer_id = self::factory()->user->create();
		$wpdb->insert(
			$wpdb->base_prefix . 'woo_wallet_withdrawals',
			array(
				'user_id'      => $customer_id,
				'amount'       => 42,
				'status'       => 'pending',
				'date_created' => current_time( 'mysql' ),
			)
		);
		wp_set_current_user( $admin_id );

		$widget = new Woo_Wallet_Dashboard_Widget();
		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '42', $output );
		$this->assertStringContainsString( 'waiting for review', $output );
	}
}
