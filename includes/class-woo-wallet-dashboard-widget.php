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
			add_action( 'wp_ajax_woo_wallet_dashboard_widget_quick_credit', array( $this, 'ajax_quick_credit' ) );
			add_action( 'admin_post_woo_wallet_dashboard_export_today', array( $this, 'handle_export_today' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		}

		/**
		 * Enqueue wc-backbone-modal for the Quick Credit modal, Dashboard
		 * screen only — same scoped-enqueue pattern as
		 * Woo_Wallet_Withdrawal::admin_enqueue_scripts() for its own
		 * customer-search modal assets.
		 */
		public function admin_enqueue_scripts() {
			$screen = get_current_screen();
			if ( ! $screen || 'dashboard' !== $screen->id ) {
				return;
			}
			wp_enqueue_script( 'wc-backbone-modal' );
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
		 * this plugin. Exposes the data service, snapshot, and growth as
		 * local variables ($data, $snapshot, $growth) rather than relying
		 * on the template reading $this — same convention as
		 * templates/admin/html-exporter.php.
		 */
		public function render() {
			$data     = $this->data_service();
			$snapshot = $data->get_snapshot( array( 'period' => Woo_Wallet_Dashboard_Widget_Data::PERIOD_TODAY ) );
			$growth   = $data->get_growth_insights();

			include WOO_WALLET_ABSPATH . 'templates/admin/dashboard-widget.php';
			include WOO_WALLET_ABSPATH . 'templates/admin/dashboard-widget-quick-credit-modal.php';
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

		/**
		 * AJAX: Quick Credit — the one credit-only action offered from the
		 * widget (no debit, no bulk targeting; the full Credit/Debit bulk
		 * action already exists on Wallet -> Users for anything larger).
		 * Resolves the customer by email or username, entered as plain text
		 * rather than a select2 customer-search field: fewer moving parts
		 * inside a Backbone-modal-injected template, and this is a small
		 * widget-box action, not a full admin screen.
		 */
		public function ajax_quick_credit() {
			check_ajax_referer( self::AJAX_NONCE_ACTION, 'security' );
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( -1 );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().
			$identifier = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().
			$amount = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0.0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer().
			$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

			$user = '' !== $identifier ? get_user_by( 'email', $identifier ) : false;
			if ( ! $user && '' !== $identifier ) {
				$user = get_user_by( 'login', $identifier );
			}

			if ( ! $user ) {
				wp_send_json_error( array( 'message' => __( 'No customer found with that email or username.', 'woo-wallet' ) ) );
			}

			if ( $amount <= 0 ) {
				wp_send_json_error( array( 'message' => __( 'Enter an amount greater than zero.', 'woo-wallet' ) ) );
			}

			$details        = '' !== $note ? $note : __( 'Quick credit from wallet dashboard widget', 'woo-wallet' );
			$transaction_id = woo_wallet()->wallet->credit( $user->ID, $amount, $details, array( 'category' => 'adjustment' ) );

			if ( ! $transaction_id ) {
				wp_send_json_error( array( 'message' => __( 'Could not credit this wallet. Please try again.', 'woo-wallet' ) ) );
			}

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: formatted amount, 2: customer display name */
						__( 'Credited %1$s to %2$s.', 'woo-wallet' ),
						$this->data_service()->format_amount( $amount ),
						$user->display_name
					),
				)
			);
		}

		/**
		 * Build today's transactions CSV via TeraWallet_CSV_Exporter, driving
		 * the same write_to_csv()/get_percent_complete() step loop
		 * Woo_Wallet_Ajax::terawallet_do_ajax_transaction_export() uses —
		 * just synchronously in one request instead of paginated over many,
		 * since "today" is a small, bounded window. Kept separate from
		 * handle_export_today() so it's callable/testable without also
		 * triggering that method's final export()-then-die() file stream.
		 *
		 * @return TeraWallet_CSV_Exporter The exporter, with the file already written to disk.
		 */
		public function export_today_transactions() {
			require_once WOO_WALLET_ABSPATH . 'includes/export/class-terawallet-csv-exporter.php';
			$exporter = new TeraWallet_CSV_Exporter();
			$exporter->set_export_type( 'transactions' );

			$today = current_time( 'Y-m-d' );
			$exporter->set_start_date( $today . ' 00:00:00' );
			$exporter->set_end_date( $today . ' 23:59:59' );
			// Random suffix: two admins exporting "today" around the same
			// moment must not race on the same filename mid-write.
			$exporter->set_filename( 'wallet-statement-' . $today . '-' . wp_generate_password( 8, false ) );

			do {
				$exporter->write_to_csv();
			} while ( $exporter->get_percent_complete() < 100 );

			return $exporter;
		}

		/**
		 * admin-post.php handler for the "Export today's statement" quick
		 * action — a plain link, not AJAX: the response IS the file
		 * download, so a normal navigation is the right transport, same as
		 * Woo_Wallet_Admin::download_export_file() for the full exporter.
		 */
		public function handle_export_today() {
			check_admin_referer( 'woo-wallet-dashboard-export-today' );
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to export wallet transactions.', 'woo-wallet' ) );
			}

			wc_set_time_limit( 0 );
			$exporter = $this->export_today_transactions();
			$exporter->export(); // Streams the file, deletes it, then die().
		}
	}
}

new Woo_Wallet_Dashboard_Widget();
