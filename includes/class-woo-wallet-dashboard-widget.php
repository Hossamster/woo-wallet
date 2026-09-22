<?php
/**
 * Native WordPress admin-dashboard widget (wp-admin/index.php) for wallet
 * financial health, actionable alerts, and quick actions.
 *
 * Self-contained module, same shape as class-woo-wallet-withdrawal.php:
 * registers its own hooks in the constructor and self-instantiates at the
 * bottom of this file. Admin-only — included from Woo_Wallet::includes()
 * inside the `is_request( 'admin' )` branch, so it never loads on the
 * frontend or on AJAX-only requests.
 *
 * Markup/inline styles live in templates/admin/dashboard-widget.php, same
 * convention as templates/woo-wallet-partial-payment.php — this plugin has
 * no working build pipeline for new compiled assets in this checkout (no
 * package.json / webpack config / src/ present, only pre-built output under
 * build/), so small admin-only UI here is plain inline CSS/JS in the
 * template rather than a new enqueued bundle.
 *
 * @package StandaleneTech
 * @since   1.7.12
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Woo_Wallet_Dashboard_Widget' ) ) {

	/**
	 * Wallet dashboard widget.
	 */
	class Woo_Wallet_Dashboard_Widget {

		/**
		 * wp_add_dashboard_widget() id.
		 *
		 * @var string
		 */
		const WIDGET_ID = 'woo_wallet_dashboard_widget';

		/**
		 * Class constructor.
		 */
		public function __construct() {
			add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		}

		/**
		 * Register the widget — gated on the same capability every other
		 * wallet admin screen uses, so a user who can't manage the wallet
		 * doesn't even see it offered on their dashboard.
		 */
		public function register_widget() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				return;
			}
			wp_add_dashboard_widget(
				self::WIDGET_ID,
				__( 'Axfit Wallet', 'woo-wallet' ),
				array( $this, 'render' )
			);
		}

		/**
		 * Render the widget body. Kept as a separate template file, not
		 * inline HTML in this method, matching every other admin view in
		 * this plugin. Exposes the data service and snapshot as local
		 * variables ($data, $snapshot) rather than relying on the
		 * template reading $this — same convention as
		 * templates/admin/html-exporter.php.
		 */
		public function render() {
			require_once WOO_WALLET_ABSPATH . 'includes/services/class-woo-wallet-dashboard-widget-data.php';
			$data     = new Woo_Wallet_Dashboard_Widget_Data();
			$snapshot = $data->get_snapshot();

			include WOO_WALLET_ABSPATH . 'templates/admin/dashboard-widget.php';
		}
	}
}

new Woo_Wallet_Dashboard_Widget();
