<?php
/**
 * Woo_Wallet_Dashboard_Widget — widget registration + rendering.
 *
 * This is the first thing that registers on wp_dashboard_setup in this
 * plugin — nothing else did before, so there's no prior art to copy for
 * "does it actually show up". These tests prove: the widget is offered to a
 * user with the wallet capability, is NOT offered to one without it (same
 * gate as every other admin screen, via get_wallet_user_capability()), and
 * render() produces real output (the Phase 1 Financial Health Snapshot,
 * backed by Woo_Wallet_Dashboard_Widget_Data — see DashboardWidgetDataTest
 * for the aggregate-query coverage) rather than a fatal/blank screen.
 */
class Dashboard_Widget_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// wp_add_dashboard_widget() lives in wp-admin/includes/dashboard.php,
		// which is only loaded on real wp-admin requests — the PHPUnit
		// bootstrap doesn't pull it in, so a CLI test needs it explicitly.
		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		// wp_add_dashboard_widget() -> add_meta_box() keys $wp_meta_boxes by
		// get_current_screen()->id and silently no-ops (returns early, no
		// error) when there's no current screen — true on the CLI bootstrap,
		// never true on a real wp-admin request. Set it explicitly, the same
		// way WP core's own dashboard-widget tests do.
		set_current_screen( 'dashboard' );
		// Only loaded by the plugin when is_request('admin') is true (see
		// Woo_Wallet::includes() / includes/class-woo-wallet.php) — never
		// autoloaded on the frontend/AJAX/CLI test bootstrap, so a test that
		// never boots a real wp-admin request must load it explicitly.
		if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget' ) ) {
			require_once WOO_WALLET_ABSPATH . 'includes/class-woo-wallet-dashboard-widget.php';
		}

		global $wp_meta_boxes;
		$wp_meta_boxes = array();
	}

	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function register_widget_as( $user_id ) {
		wp_set_current_user( $user_id );
		$widget = new Woo_Wallet_Dashboard_Widget();
		$widget->register_widget();
		return $widget;
	}

	public function test_widget_registers_for_a_user_with_the_wallet_capability() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->register_widget_as( $admin_id );

		global $wp_meta_boxes;
		$this->assertArrayHasKey(
			Woo_Wallet_Dashboard_Widget::WIDGET_ID,
			$wp_meta_boxes['dashboard']['normal']['core'] ?? array(),
			'A user with manage_woocommerce must be offered the widget.'
		);
	}

	public function test_widget_does_not_register_for_a_user_without_the_wallet_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->register_widget_as( $subscriber_id );

		global $wp_meta_boxes;
		$this->assertArrayNotHasKey(
			Woo_Wallet_Dashboard_Widget::WIDGET_ID,
			$wp_meta_boxes['dashboard']['normal']['core'] ?? array(),
			'A user without manage_woocommerce must not see the widget offered at all.'
		);
	}

	public function test_render_outputs_the_snapshot_body_with_no_fatal() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$widget = new Woo_Wallet_Dashboard_Widget();

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'woo-wallet-dashboard-widget', $output );
		$this->assertStringContainsString( 'Outstanding liability', $output );
	}

	/**
	 * Proves render() actually reaches the data service and reflects real
	 * ledger/withdrawal state, not just static markup — a pending
	 * withdrawal's amount must show up formatted in the output.
	 */
	public function test_render_reflects_a_pending_withdrawal() {
		global $wpdb;
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
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
