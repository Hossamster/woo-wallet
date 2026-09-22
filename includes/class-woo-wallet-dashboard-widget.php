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
		 * check_ajax_referer()/wp_create_nonce() action name for the
		 * period-tab AJAX refresh.
		 *
		 * @var string
		 */
		const AJAX_NONCE_ACTION = 'woo-wallet-dashboard-widget';

		/**
		 * Class constructor.
		 */
		public function __construct() {
			add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
			add_action( 'wp_ajax_woo_wallet_dashboard_widget_refresh', array( $this, 'ajax_refresh' ) );
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
		 * Load the data service. Required lazily by render()/ajax_refresh()
		 * rather than at file-load time — never needed on a request that
		 * only registers the widget (e.g. wp_dashboard_setup running for a
		 * user who never opens the Dashboard screen).
		 *
		 * @return Woo_Wallet_Dashboard_Widget_Data
		 */
		protected function data_service() {
			require_once WOO_WALLET_ABSPATH . 'includes/services/class-woo-wallet-dashboard-widget-data.php';
			return new Woo_Wallet_Dashboard_Widget_Data();
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
			$data     = $this->data_service();
			$snapshot = $data->get_snapshot( array( 'period' => Woo_Wallet_Dashboard_Widget_Data::PERIOD_TODAY ) );

			include WOO_WALLET_ABSPATH . 'templates/admin/dashboard-widget.php';
		}

		/**
		 * AJAX: re-render the snapshot body for a selected period tab
		 * (Today / 7 days / This month), so switching tabs doesn't reload
		 * the whole wp-admin dashboard. Same capability gate as
		 * register_widget() — a request without it is rejected before any
		 * query runs, same as every other wallet AJAX action.
		 */
		public function ajax_refresh() {
			check_ajax_referer( self::AJAX_NONCE_ACTION, 'security' );
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( -1 );
			}

			$data   = $this->data_service();
			$period = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : Woo_Wallet_Dashboard_Widget_Data::PERIOD_TODAY; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().
			if ( ! in_array( $period, $data->allowed_periods(), true ) ) {
				$period = Woo_Wallet_Dashboard_Widget_Data::PERIOD_TODAY;
			}

			$snapshot = $data->get_snapshot( array( 'period' => $period ) );

			ob_start();
			include WOO_WALLET_ABSPATH . 'templates/admin/dashboard-widget-body.php';
			$html = ob_get_clean();

			wp_send_json_success(
				array(
					'html'   => $html,
					'period' => $snapshot['period'],
				)
			);
		}
	}
}

new Woo_Wallet_Dashboard_Widget();
