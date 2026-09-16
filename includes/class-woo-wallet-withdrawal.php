<?php
/**
 * Wallet withdrawal requests.
 *
 * Self-contained module (settings tab, frontend request form, admin review
 * queue) that lets a customer ask for part of their wallet balance to be
 * paid out to an Egyptian bank account. The requested amount (plus any
 * configured charge) is reserved out of the wallet the moment the request is
 * made — via a normal `debit()` call, so it goes through the same
 * lock/insufficient-balance gate as every other ledger write — and the row
 * in `woo_wallet_withdrawals` tracks the request through to 'paid' or
 * 'rejected' (which credits the reservation back).
 *
 * Everything here is wired through the same extension points the rest of
 * the plugin uses (`woo_wallet_settings_sections` / `_fields`,
 * `woo_wallet_nav_menu_items`, `woo_wallet_{action}_content`,
 * `woo_wallet_allowed_dashboard_actions`, `woo_wallet_transaction_types`) so
 * it does not need to touch any core class.
 *
 * @package StandaleneTech
 * @since   1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'woo_wallet_get_egyptian_banks' ) ) {
	/**
	 * Bank options offered in the withdrawal request dropdown.
	 *
	 * Filterable so a store can trim the list or add a bank that is missing.
	 * Keys are stored on the request row (and in the settings textarea);
	 * values are the label shown to the customer.
	 *
	 * @return array
	 */
	function woo_wallet_get_egyptian_banks() {
		$banks = array(
			'nbe'               => __( 'National Bank of Egypt (البنك الأهلي المصري)', 'woo-wallet' ),
			'banque_misr'       => __( 'Banque Misr (بنك مصر)', 'woo-wallet' ),
			'cib'               => __( 'Commercial International Bank - CIB (البنك التجاري الدولي)', 'woo-wallet' ),
			'qnb'               => __( 'QNB Alahli (بنك قطر الوطني الأهلي)', 'woo-wallet' ),
			'aaib'              => __( 'Arab African International Bank (العربي الأفريقي الدولي)', 'woo-wallet' ),
			'banque_du_caire'   => __( 'Banque du Caire (بنك القاهرة)', 'woo-wallet' ),
			'hdb'               => __( 'Housing and Development Bank (التعمير والإسكان)', 'woo-wallet' ),
			'egyptian_gulf'     => __( 'Egyptian Gulf Bank (المصرف المصري الخليجي)', 'woo-wallet' ),
			'hsbc_egypt'        => __( 'HSBC Bank Egypt', 'woo-wallet' ),
			'faisal_islamic'    => __( 'Faisal Islamic Bank of Egypt (فيصل الإسلامي)', 'woo-wallet' ),
			'al_baraka'         => __( 'Al Baraka Bank Egypt (البركة)', 'woo-wallet' ),
			'adib_egypt'        => __( 'Abu Dhabi Islamic Bank - Egypt (أبوظبي الإسلامي)', 'woo-wallet' ),
			'suez_canal'        => __( 'Suez Canal Bank (قناة السويس)', 'woo-wallet' ),
			'credit_agricole'   => __( 'Credit Agricole Egypt', 'woo-wallet' ),
			'arab_international' => __( 'Arab International Bank', 'woo-wallet' ),
			'arab_bank'         => __( 'Arab Bank - Egypt', 'woo-wallet' ),
			'nbk_egypt'         => __( 'National Bank of Kuwait - Egypt', 'woo-wallet' ),
			'emirates_nbd'      => __( 'Emirates NBD Egypt', 'woo-wallet' ),
			'ahli_united'       => __( 'Ahli United Bank', 'woo-wallet' ),
			'attijariwafa'      => __( 'Attijariwafa Bank Egypt', 'woo-wallet' ),
			'mashreq'           => __( 'Mashreq Bank Egypt', 'woo-wallet' ),
			'bank_nxt'          => __( 'Bank NXT', 'woo-wallet' ),
			'idb'               => __( 'Industrial Development Bank', 'woo-wallet' ),
			'egyptian_arab_land' => __( 'Egyptian Arab Land Bank (العقاري المصري العربي)', 'woo-wallet' ),
			'united_bank_egypt' => __( 'United Bank of Egypt (المتحد)', 'woo-wallet' ),
			'nasser_social'     => __( 'Nasser Social Bank (ناصر الاجتماعي)', 'woo-wallet' ),
			'alex_bank'         => __( 'AlexBank - Bank of Alexandria (الإسكندرية)', 'woo-wallet' ),
			'fab_egypt'         => __( 'First Abu Dhabi Bank Egypt', 'woo-wallet' ),
			'post_office'       => __( 'Egypt Post - National Post Office (البريد المصري)', 'woo-wallet' ),
		);
		return apply_filters( 'woo_wallet_egyptian_banks', $banks );
	}
}

if ( ! class_exists( 'Woo_Wallet_Withdrawal' ) ) {

	/**
	 * Wallet withdrawal feature.
	 */
	class Woo_Wallet_Withdrawal {

		/**
		 * WP-Cron hook name for the receipt-retention sweep.
		 *
		 * @var string
		 */
		const RECEIPT_CLEANUP_HOOK = 'woo_wallet_withdrawal_cleanup_receipts_cron';

		/**
		 * DB table name (no prefix helper needed elsewhere — kept private to this class).
		 *
		 * @return string
		 */
		private static function table() {
			global $wpdb;
			return $wpdb->base_prefix . 'woo_wallet_withdrawals';
		}

		/**
		 * Notes table name.
		 *
		 * @return string
		 */
		private static function notes_table() {
			global $wpdb;
			return $wpdb->base_prefix . 'woo_wallet_withdrawal_notes';
		}

		/**
		 * Class constructor.
		 */
		public function __construct() {
			// Settings tab — needed wherever settings are read/rendered (admin
			// screen and the REST settings controller both load this).
			add_filter( 'woo_wallet_settings_sections', array( $this, 'register_settings_section' ) );
			add_filter( 'woo_wallet_settings_fields', array( $this, 'register_settings_fields' ) );
			add_filter( 'woo_wallet_transaction_types', array( $this, 'register_transaction_types' ) );

			// Frontend: request form tab + submission handling.
			add_filter( 'woo_wallet_nav_menu_items', array( $this, 'add_withdraw_nav_item' ), 10, 2 );
			add_filter( 'woo_wallet_is_enable_withdraw', array( $this, 'is_withdraw_enabled' ) );
			add_action( 'woo_wallet_withdraw_content', array( $this, 'render_withdraw_content' ) );
			add_action( 'wp_loaded', array( $this, 'maybe_handle_withdraw_request' ) );

			// Receipt retention: scheduled independently of admin/frontend context,
			// since WP-Cron's actual trigger is a pageload on the public site just
			// as often as it is one in wp-admin.
			add_action( 'init', array( $this, 'maybe_schedule_receipt_cleanup' ) );
			add_action( self::RECEIPT_CLEANUP_HOOK, array( $this, 'cleanup_old_receipts' ) );
			add_action( 'woo_wallet_deactivated', array( $this, 'unschedule_receipt_cleanup' ) );

			if ( is_admin() ) {
				add_action( 'admin_menu', array( $this, 'admin_menu' ), 70 );
				add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
				add_action( 'admin_post_woo_wallet_withdrawal_process', array( $this, 'handle_admin_process_request' ) );
				add_action( 'admin_post_woo_wallet_withdrawal_create', array( $this, 'handle_admin_create_request' ) );
				add_action( 'admin_post_woo_wallet_withdrawal_add_note', array( $this, 'handle_admin_add_note' ) );
				add_action( 'admin_post_woo_wallet_withdrawal_recover', array( $this, 'handle_admin_recover_request' ) );
				add_action( 'admin_notices', array( $this, 'admin_notices' ) );
				add_action( 'admin_notices', array( $this, 'maybe_show_stuck_processing_notice' ) );
			}
		}

		/**
		 * Enqueue WooCommerce's enhanced-select (select2) assets on the
		 * Withdrawals screens, for the "search for a customer" field on the
		 * manual-create form. Reuses the same `wc-customer-search` widget /
		 * `woocommerce_json_search_customers` AJAX action WooCommerce's own
		 * order-edit screen uses, rather than shipping a second implementation.
		 */
		public function admin_enqueue_scripts() {
			$screen = get_current_screen();
			if ( ! $screen || woo_wallet_get_screen_id( 'woo-wallet-withdrawals' ) !== $screen->id ) {
				return;
			}
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
		}

		/**
		 * Whether withdrawal is enabled store-wide.
		 *
		 * @return bool
		 */
		public function is_withdraw_enabled() {
			return self::is_enabled_static();
		}

		/**
		 * Register the "Wallet Withdrawal" settings section.
		 *
		 * @param array $sections Existing sections.
		 * @return array
		 */
		public function register_settings_section( $sections ) {
			if ( ! is_array( $sections ) ) {
				return $sections;
			}
			$sections[] = array(
				'id'    => '_wallet_settings_withdrawal',
				'title' => __( 'Withdrawal', 'woo-wallet' ),
				'icon'  => 'dashicons-money',
			);
			return $sections;
		}

		/**
		 * Register the withdrawal settings fields.
		 *
		 * @param array $fields Existing fields, keyed by section id.
		 * @return array
		 */
		public function register_settings_fields( $fields ) {
			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			$enabled_show_if = array(
				'field'  => 'is_enable_wallet_withdrawal',
				'equals' => 'on',
			);
			$currency_symbol = html_entity_decode( get_woocommerce_currency_symbol( get_option( 'woocommerce_currency' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$default_banks   = implode( "\n", array_values( woo_wallet_get_egyptian_banks() ) );

			$fields['_wallet_settings_withdrawal'] = array(
				array(
					'name'              => 'is_enable_wallet_withdrawal',
					'label'             => __( 'Enable Wallet Withdrawal', 'woo-wallet' ),
					'desc'              => __( 'Allow customers to request that part of their wallet balance be paid out to a bank account. Requests are reserved from the wallet immediately and go into an approval queue under Axfit Wallet → Withdrawals.', 'woo-wallet' ),
					'type'              => 'checkbox',
					'default'           => 'off',
					'group'             => 'wallet_withdrawal',
					'group_title'       => __( 'Wallet Withdrawal', 'woo-wallet' ),
					'group_description' => __( 'Let customers cash out their wallet balance to a bank account', 'woo-wallet' ),
				),
				array(
					'name'    => 'min_withdrawal_amount',
					'label'   => __( 'Minimum Withdrawal Amount', 'woo-wallet' ),
					'desc'    => __( 'Customers cannot request less than this amount. Leave blank for no minimum.', 'woo-wallet' ),
					'type'    => 'number',
					'prefix'  => $currency_symbol,
					'step'    => '0.01',
					'group'   => 'wallet_withdrawal',
					'show_if' => $enabled_show_if,
					'half'    => true,
				),
				array(
					'name'    => 'max_withdrawal_amount',
					'label'   => __( 'Maximum Withdrawal Amount', 'woo-wallet' ),
					'desc'    => __( 'Customers cannot request more than this amount per request. Leave blank for no maximum.', 'woo-wallet' ),
					'type'    => 'number',
					'prefix'  => $currency_symbol,
					'step'    => '0.01',
					'group'   => 'wallet_withdrawal',
					'show_if' => $enabled_show_if,
					'half'    => true,
				),
				array(
					'name'    => 'withdrawal_charge_type',
					'label'   => __( 'Withdrawal Charge Type', 'woo-wallet' ),
					'desc'    => __( 'Choose how the withdrawal fee is calculated', 'woo-wallet' ),
					'type'    => 'select',
					'options' => array(
						'percent' => __( 'Percentage (%)', 'woo-wallet' ),
						'fixed'   => __( 'Fixed', 'woo-wallet' ),
					),
					'default' => 'fixed',
					'size'    => 'regular-text wc-enhanced-select',
					'group'   => 'wallet_withdrawal',
					'show_if' => $enabled_show_if,
					'half'    => true,
				),
				array(
					'name'    => 'withdrawal_charge_amount',
					'label'   => __( 'Withdrawal Charge Amount', 'woo-wallet' ),
					'desc'    => __( 'Fee deducted from the wallet in addition to the requested amount. Leave 0 for no charge.', 'woo-wallet' ),
					'type'    => 'number',
					'step'    => '0.01',
					'default' => '0',
					'group'   => 'wallet_withdrawal',
					'show_if' => $enabled_show_if,
					'half'    => true,
				),
				array(
					'name'              => 'withdrawal_banks',
					'label'             => __( 'Available Banks', 'woo-wallet' ),
					'desc'              => __( 'One bank name per line. Shown to the customer as the bank dropdown on the withdrawal request form.', 'woo-wallet' ),
					'type'              => 'textarea',
					'default'           => $default_banks,
					// Multi-line value — the default per-field sanitizer (sanitize_text_field)
					// collapses newlines, which would merge every bank onto one line.
					'sanitize_callback' => 'sanitize_textarea_field',
					'group'             => 'wallet_withdrawal',
					'show_if'           => $enabled_show_if,
				),
			);

			return $fields;
		}

		/**
		 * Register the ledger categories used by withdrawal transactions.
		 *
		 * @param array $types Existing categories.
		 * @return array
		 */
		public function register_transaction_types( $types ) {
			if ( ! is_array( $types ) ) {
				return $types;
			}
			$types['withdrawal'] = array(
				'label'            => __( 'Withdrawal', 'woo-wallet' ),
				'description'      => __( 'Wallet balance reserved for a bank withdrawal request.', 'woo-wallet' ),
				'default_template' => '',
			);
			$types['withdrawal_refund'] = array(
				'label'            => __( 'Withdrawal refund', 'woo-wallet' ),
				'description'      => __( 'A rejected withdrawal request returned to the wallet.', 'woo-wallet' ),
				'default_template' => '',
			);
			return $types;
		}

		/**
		 * The bank options configured by the admin, as `label => label` pairs
		 * (the textarea stores free-text lines, so the value the customer
		 * submits is validated against this same list).
		 *
		 * @return array
		 */
		public static function get_configured_banks() {
			$raw = woo_wallet()->settings_api->get_option( 'withdrawal_banks', '_wallet_settings_withdrawal', '' );
			if ( '' === trim( (string) $raw ) ) {
				$raw = implode( "\n", array_values( woo_wallet_get_egyptian_banks() ) );
			}
			$banks = array();
			foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$banks[ $line ] = $line;
				}
			}
			return $banks;
		}

		/**
		 * Add the "Withdraw" tab to the wallet dashboard nav.
		 *
		 * @param array $items                     Nav items.
		 * @param bool  $is_rendred_from_myaccount Whether rendering inside My Account.
		 * @return array
		 */
		public function add_withdraw_nav_item( $items, $is_rendred_from_myaccount = true ) {
			if ( ! $this->is_withdraw_enabled() ) {
				return $items;
			}
			$items['withdraw'] = array(
				'title' => apply_filters( 'woo_wallet_account_withdraw_menu_title', __( 'Withdraw', 'woo-wallet' ) ),
				'url'   => $is_rendred_from_myaccount ? esc_url( wc_get_endpoint_url( get_option( 'woocommerce_woo_wallet_endpoint', 'my-wallet' ), 'withdraw', wc_get_page_permalink( 'myaccount' ) ) ) : add_query_arg( 'wallet_action', 'withdraw' ),
				'icon'  => 'dashicons dashicons-download',
			);
			return $items;
		}

		/**
		 * Render the withdraw tab content template.
		 */
		public function render_withdraw_content() {
			if ( ! $this->is_withdraw_enabled() ) {
				return;
			}
			woo_wallet()->get_template( 'withdraw.php' );
		}

		/**
		 * Process a submitted withdrawal request form.
		 */
		public function maybe_handle_withdraw_request() {
			if ( ! isset( $_POST['woo_wallet_withdraw_request'] ) ) {
				return;
			}
			if ( ! isset( $_POST['woo_wallet_withdraw'] ) || ! wp_verify_nonce( wp_unslash( $_POST['woo_wallet_withdraw'] ), 'woo_wallet_withdraw' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				wc_add_notice( __( 'Cheatin&#8217; huh?', 'woo-wallet' ), 'error' );
				return;
			}

			$result = $this->handle_withdraw_request();
			if ( ! $result['is_valid'] ) {
				wc_add_notice( $result['message'], 'error' );
				return;
			}
			wc_add_notice( $result['message'] );
			$location = wp_get_raw_referer() ? wp_get_raw_referer() : esc_url( wc_get_account_endpoint_url( get_option( 'woocommerce_woo_wallet_endpoint', 'my-wallet' ) ) );
			wp_safe_redirect( $location );
			exit();
		}

		/**
		 * Parse $_POST and delegate to submit_request() — the form-handler side
		 * of the customer self-service flow. The REST controller
		 * (TeraWallet_REST_Me_Withdrawal_Controller) calls submit_request()
		 * directly with already-validated-by-the-schema params instead of
		 * going through this method, the same way the wallet-transfer form
		 * handler and its REST controller both sit in front of
		 * WooWallet_Transfer_Service::execute().
		 *
		 * @return array {is_valid, message}
		 */
		private function handle_withdraw_request() {
			$amount           = isset( $_POST['woo_wallet_withdraw_amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_amount'] ) ) : 0;
			$bank_name        = isset( $_POST['woo_wallet_withdraw_bank'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_bank'] ) ) : '';
			$beneficiary_name = isset( $_POST['woo_wallet_withdraw_beneficiary'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_beneficiary'] ) ) : '';
			$account_number   = isset( $_POST['woo_wallet_withdraw_account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_account_number'] ) ) : '';
			$phone            = isset( $_POST['woo_wallet_withdraw_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_phone'] ) ) : '';
			$iban             = isset( $_POST['woo_wallet_withdraw_iban'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_iban'] ) ) : '';

			return self::submit_request( get_current_user_id(), $amount, $bank_name, $beneficiary_name, $account_number, $phone, $iban );
		}

		/**
		 * Validate a customer's own withdrawal request, reserve the funds, and
		 * record it. The single entry point for customer self-service —
		 * shared by the frontend form (via handle_withdraw_request() above)
		 * and TeraWallet_REST_Me_Withdrawal_Controller::create_item(), so the
		 * two surfaces can never drift on validation rules.
		 *
		 * @param int    $user_id          Customer id (always the logged-in user — never trust a request-supplied id here).
		 * @param float  $amount           Requested payout amount.
		 * @param string $bank_name        Must match one of get_configured_banks().
		 * @param string $beneficiary_name Beneficiary name.
		 * @param string $account_number   Bank account number.
		 * @param string $phone            Contact phone number, so staff can reach the customer about this request.
		 * @param string $iban             Optional IBAN.
		 * @return array {is_valid, message, id?, charge?}
		 */
		public static function submit_request( $user_id, $amount, $bank_name, $beneficiary_name, $account_number, $phone, $iban = '' ) {
			if ( ! self::is_enabled_static() ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Wallet withdrawal is not available right now.', 'woo-wallet' ),
				);
			}

			$user_id = (int) $user_id;
			if ( ! $user_id ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'You must be logged in to request a withdrawal.', 'woo-wallet' ),
				);
			}

			// Per-user soft rate limit, same shape as wallet transfer.
			$rate_limit = (int) apply_filters( 'woo_wallet_withdrawal_rate_limit_per_minute', 5, $user_id );
			$rate_key   = 'woo_wallet_withdraw_rate_' . $user_id;
			$rate_count = (int) get_transient( $rate_key );
			if ( $rate_limit > 0 && $rate_count >= $rate_limit ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Too many withdrawal requests in a short time. Please wait a minute and try again.', 'woo-wallet' ),
				);
			}
			set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );

			$amount = (float) $amount;
			if ( $amount <= 0 ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Withdrawal amount must be greater than zero.', 'woo-wallet' ),
				);
			}

			$min_amount = (float) woo_wallet()->settings_api->get_option( 'min_withdrawal_amount', '_wallet_settings_withdrawal', 0 );
			if ( $min_amount && $min_amount > $amount ) {
				return array(
					'is_valid' => false,
					/* translators: Min withdrawal amount */
					'message'  => sprintf( __( 'Minimum withdrawal amount is %s', 'woo-wallet' ), wc_price( $min_amount, woo_wallet_wc_price_args() ) ),
				);
			}
			$max_amount = (float) woo_wallet()->settings_api->get_option( 'max_withdrawal_amount', '_wallet_settings_withdrawal', 0 );
			if ( $max_amount && $max_amount < $amount ) {
				return array(
					'is_valid' => false,
					/* translators: Max withdrawal amount */
					'message'  => sprintf( __( 'Maximum withdrawal amount is %s', 'woo-wallet' ), wc_price( $max_amount, woo_wallet_wc_price_args() ) ),
				);
			}

			$bank_name     = (string) $bank_name;
			$allowed_banks = self::get_configured_banks();
			if ( '' === $bank_name || ! isset( $allowed_banks[ $bank_name ] ) ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please select a valid bank.', 'woo-wallet' ),
				);
			}

			$beneficiary_name = trim( (string) $beneficiary_name );
			if ( '' === $beneficiary_name ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter the beneficiary name.', 'woo-wallet' ),
				);
			}

			$account_number = preg_replace( '/\s+/', '', (string) $account_number );
			if ( '' === $account_number ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter the bank account number.', 'woo-wallet' ),
				);
			}

			$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
			if ( strlen( $phone ) < 8 ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter a valid contact phone number.', 'woo-wallet' ),
				);
			}

			$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
			if ( '' !== $iban && ! apply_filters( 'woo_wallet_is_valid_egyptian_iban', (bool) preg_match( '/^EG\d{27}$/', $iban ), $iban ) ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter a valid Egyptian IBAN (starts with EG, 29 characters) or leave it blank.', 'woo-wallet' ),
				);
			}

			$result = self::reserve_and_insert( $user_id, $amount, $bank_name, $beneficiary_name, $account_number, $phone, $iban, $user_id, 'pending', '' );
			if ( ! $result['is_valid'] ) {
				return $result;
			}

			do_action( 'woo_wallet_withdrawal_requested', $result['id'], $user_id, $amount + $result['charge'] );

			return array(
				'is_valid' => true,
				'message'  => __( 'Your withdrawal request has been submitted and is awaiting approval.', 'woo-wallet' ),
				'id'       => $result['id'],
				'charge'   => $result['charge'],
			);
		}

		/**
		 * Static-context version of is_withdraw_enabled() — the REST
		 * controller calls this without an instance.
		 *
		 * @return bool
		 */
		public static function is_enabled_static() {
			return 'on' === woo_wallet()->settings_api->get_option( 'is_enable_wallet_withdrawal', '_wallet_settings_withdrawal', 'off' );
		}

		/**
		 * Compute the configured charge, reserve the funds (debit the wallet),
		 * and insert the withdrawal request row. Shared by the customer
		 * self-service form and the admin manual-create form — the only two
		 * places money actually moves for a withdrawal request.
		 *
		 * @param int    $user_id          Customer whose wallet is charged.
		 * @param float  $amount           Requested payout amount (gross).
		 * @param string $bank_name        Bank name.
		 * @param string $beneficiary_name Beneficiary name.
		 * @param string $account_number   Bank account number.
		 * @param string $phone            Contact phone number.
		 * @param string $iban             Optional IBAN.
		 * @param int    $created_by       User id who created the request (customer themself, or the staff member logging it).
		 * @param string $status           Initial status: 'pending' or 'paid'.
		 * @param string $reference_no     Optional bank transfer reference number.
		 * @return array {is_valid, message, id, charge}
		 */
		private static function reserve_and_insert( $user_id, $amount, $bank_name, $beneficiary_name, $account_number, $phone, $iban, $created_by, $status = 'pending', $reference_no = '' ) {
			$charge_type   = woo_wallet()->settings_api->get_option( 'withdrawal_charge_type', '_wallet_settings_withdrawal', 'fixed' );
			$charge_amount = (float) woo_wallet()->settings_api->get_option( 'withdrawal_charge_amount', '_wallet_settings_withdrawal', 0 );
			$charge        = 'percent' === $charge_type ? ( $amount * $charge_amount ) / 100 : $charge_amount;
			$charge        = (float) apply_filters( 'woo_wallet_withdrawal_charge_amount', $charge, $user_id, $amount );
			$debit_amount  = $amount + $charge;

			$current_balance = woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
			if ( $current_balance <= 0 || $debit_amount > $current_balance ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Entered amount is greater than the customer&#8217;s current wallet balance.', 'woo-wallet' ),
				);
			}

			/* translators: %s: bank name */
			$debit_note = sprintf( __( 'Withdrawal request reserved for payout to %s', 'woo-wallet' ), $bank_name );
			$debit_note = apply_filters( 'woo_wallet_withdrawal_debit_note', $debit_note, $user_id, $amount );

			$transaction_id = woo_wallet()->wallet->debit( $user_id, $debit_amount, $debit_note, array( 'category' => 'withdrawal' ) );
			if ( ! $transaction_id ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Entered amount is greater than the customer&#8217;s current wallet balance.', 'woo-wallet' ),
				);
			}

			$row_args = array(
				'user_id'          => $user_id,
				'created_by'       => $created_by,
				'transaction_id'   => $transaction_id,
				'amount'           => $amount,
				'charge'           => $charge,
				'currency'         => woo_wallet()->wallet->resolve_active_currency(),
				'bank_name'        => $bank_name,
				'beneficiary_name' => $beneficiary_name,
				'account_number'   => $account_number,
				'phone'            => $phone,
				'iban'             => $iban,
				'reference_no'     => $reference_no,
				'status'           => $status,
			);
			if ( 'paid' === $status ) {
				$row_args['processed_by'] = $created_by;
			}
			$withdrawal_id = self::insert_request( $row_args );

			// The wallet was already debited above. If the row that tracks that
			// reservation failed to insert (DB error), the customer would be
			// charged with no request to show for it — credit the reservation
			// straight back rather than leave that dangling.
			if ( ! $withdrawal_id ) {
				/* translators: %s: bank name */
				$refund_note = sprintf( __( 'Withdrawal request to %s could not be recorded — reservation reversed', 'woo-wallet' ), $bank_name );
				$refund_id   = woo_wallet()->wallet->credit( $user_id, $debit_amount, $refund_note, array( 'category' => 'withdrawal_refund' ) );

				if ( $refund_id ) {
					return array(
						'is_valid' => false,
						'message'  => __( 'Something went wrong recording the withdrawal request. The wallet balance was not affected — please try again.', 'woo-wallet' ),
					);
				}

				// The compensating credit itself failed — there is no request row
				// to attach this to (that is exactly what just failed to insert),
				// so a customer-visible "balance unaffected" message would be a
				// lie. Log loudly and give integrations a hook to alert on, since
				// this needs a human to reconcile the ledger manually.
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'Axfit Wallet: withdrawal reservation could not be recorded AND the compensating refund failed. user_id=%d amount=%s debit_transaction_id=%d — manual reconciliation required.',
						$user_id,
						$debit_amount,
						$transaction_id
					)
				);
				do_action( 'woo_wallet_withdrawal_reservation_credit_failed', $user_id, $debit_amount, $transaction_id );

				return array(
					'is_valid' => false,
					'message'  => __( 'Something went wrong recording the withdrawal request, and the wallet could not be automatically corrected. Please contact support before trying again — do not resubmit.', 'woo-wallet' ),
				);
			}

			return array(
				'is_valid' => true,
				'message'  => '',
				'id'       => $withdrawal_id,
				'charge'   => $charge,
			);
		}

		/**
		 * Insert a withdrawal request row.
		 *
		 * @param array $data Row data.
		 * @return int Insert id.
		 */
		public static function insert_request( array $data ) {
			global $wpdb;
			$row = array(
				'user_id'          => (int) $data['user_id'],
				'transaction_id'   => (int) $data['transaction_id'],
				'amount'           => (float) $data['amount'],
				'charge'           => (float) $data['charge'],
				'currency'         => (string) $data['currency'],
				'bank_name'        => (string) $data['bank_name'],
				'beneficiary_name' => (string) $data['beneficiary_name'],
				'account_number'   => (string) $data['account_number'],
				'phone'            => isset( $data['phone'] ) ? (string) $data['phone'] : '',
				'iban'             => (string) $data['iban'],
				'reference_no'     => isset( $data['reference_no'] ) ? (string) $data['reference_no'] : '',
				'receipt_id'       => isset( $data['receipt_id'] ) ? (int) $data['receipt_id'] : 0,
				'status'           => (string) $data['status'],
				'created_by'       => isset( $data['created_by'] ) ? (int) $data['created_by'] : 0,
				'processed_by'     => isset( $data['processed_by'] ) ? (int) $data['processed_by'] : 0,
				'date_created'     => current_time( 'mysql' ),
			);
			$formats = array( '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s' );
			if ( 'paid' === $row['status'] ) {
				$row['date_updated'] = current_time( 'mysql' );
				$formats[]           = '%s';
			}
			// `$wpdb->insert_id` is not reset on failure, so it can hold a stale id
			// from an earlier query — check `$wpdb->insert()`'s own return value
			// (rows affected, or false) rather than trusting insert_id alone.
			$inserted = $wpdb->insert( self::table(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $inserted ? (int) $wpdb->insert_id : 0;
		}

		/**
		 * Fetch a single request row.
		 *
		 * @param int $id Request id.
		 * @return object|null
		 */
		public static function get_request( $id ) {
			global $wpdb;
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		/**
		 * Build the shared WHERE clause + bound params for get_requests() /
		 * count_requests(), so the two can never drift on what a given filter
		 * set means.
		 *
		 * @param array $args {
		 *     @type string       $status      Exact status match.
		 *     @type int          $user_id     Exact customer id. Wins over $user_ids.
		 *     @type int[]        $user_ids    Customer ids (e.g. resolved from a name/email search).
		 *     @type string       $bank_name   Exact bank name match (see get_configured_banks()).
		 *     @type string       $created_by  'self' (customer self-service) or 'staff' (admin-logged).
		 *     @type string       $after       'Y-m-d H:i:s' lower bound on date_created.
		 *     @type string       $before      'Y-m-d H:i:s' upper bound on date_created.
		 * }
		 * @return array {0: string[] where clauses (already includes the leading '1=1'), 1: array bound params}
		 */
		private static function build_where( array $args ) {
			$where  = array( '1=1' );
			$params = array();

			if ( ! empty( $args['status'] ) ) {
				$where[]  = 'status = %s';
				$params[] = (string) $args['status'];
			}
			if ( ! empty( $args['user_id'] ) ) {
				$where[]  = 'user_id = %d';
				$params[] = (int) $args['user_id'];
			} elseif ( ! empty( $args['user_ids'] ) && is_array( $args['user_ids'] ) ) {
				$ids = array_filter( array_map( 'absint', $args['user_ids'] ) );
				if ( $ids ) {
					$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					$where[]      = "user_id IN ({$placeholders})";
					foreach ( $ids as $id ) {
						$params[] = $id;
					}
				} else {
					// A search that resolved to zero customers must match zero
					// rows, not "no user filter at all".
					$where[] = '1=0';
				}
			}
			if ( ! empty( $args['bank_name'] ) ) {
				$where[]  = 'bank_name = %s';
				$params[] = (string) $args['bank_name'];
			}
			if ( ! empty( $args['created_by'] ) ) {
				if ( 'self' === $args['created_by'] ) {
					$where[] = 'created_by > 0 AND created_by = user_id';
				} elseif ( 'staff' === $args['created_by'] ) {
					$where[] = 'created_by > 0 AND created_by <> user_id';
				}
			}
			if ( ! empty( $args['after'] ) ) {
				$where[]  = 'date_created >= %s';
				$params[] = (string) $args['after'];
			}
			if ( ! empty( $args['before'] ) ) {
				$where[]  = 'date_created <= %s';
				$params[] = (string) $args['before'];
			}

			return array( $where, $params );
		}

		/**
		 * Fetch a page of requests. See build_where() for the supported filters;
		 * `limit`/`offset` are additionally supported for pagination.
		 *
		 * @param array $args Filters plus optional {limit, offset}.
		 * @return array
		 */
		public static function get_requests( array $args = array() ) {
			global $wpdb;
			list( $where, $params ) = self::build_where( $args );

			$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC';
			if ( ! empty( $args['limit'] ) ) {
				$sql     .= ' LIMIT %d OFFSET %d';
				$params[] = (int) $args['limit'];
				$params[] = ! empty( $args['offset'] ) ? (int) $args['offset'] : 0;
			}

			if ( $params ) {
				return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			}
			return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		}

		/**
		 * Count requests — same filter shape as get_requests(), see build_where().
		 *
		 * @param array $args Filters.
		 * @return int
		 */
		public static function count_requests( array $args = array() ) {
			global $wpdb;
			list( $where, $params ) = self::build_where( $args );

			$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
			if ( $params ) {
				return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			}
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		}

		/**
		 * Add a note to a withdrawal request.
		 *
		 * @param int    $withdrawal_id Request id.
		 * @param string $note          Note text.
		 * @param string $visibility    'public' (shown to the customer) or 'private' (staff only).
		 * @param int    $created_by    Author user id (0 for a system-generated note).
		 * @return int Insert id, or 0 if the note text is empty.
		 */
		public static function add_note( $withdrawal_id, $note, $visibility = 'private', $created_by = 0 ) {
			$note = trim( (string) $note );
			if ( '' === $note ) {
				return 0;
			}
			global $wpdb;
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::notes_table(),
				array(
					'withdrawal_id' => absint( $withdrawal_id ),
					'note'          => $note,
					'visibility'    => 'public' === $visibility ? 'public' : 'private',
					'created_by'    => (int) $created_by,
					'date_created'  => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%d', '%s' )
			);
			return (int) $wpdb->insert_id;
		}

		/**
		 * Fetch notes for a withdrawal request, newest first.
		 *
		 * @param int    $withdrawal_id Request id.
		 * @param string $visibility    Optional filter: 'public' or 'private'. Omit for all notes.
		 * @return array
		 */
		public static function get_notes( $withdrawal_id, $visibility = '' ) {
			global $wpdb;
			$sql    = 'SELECT * FROM ' . self::notes_table() . ' WHERE withdrawal_id = %d';
			$params = array( absint( $withdrawal_id ) );
			if ( in_array( $visibility, array( 'public', 'private' ), true ) ) {
				$sql     .= ' AND visibility = %s';
				$params[] = $visibility;
			}
			$sql .= ' ORDER BY id DESC';
			return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		}

		/**
		 * Meta key tagging a wallet credit transaction as the refund for a
		 * specific withdrawal request.
		 *
		 * @return string
		 */
		private static function refund_meta_key() {
			return '_woo_wallet_withdrawal_refund_for';
		}

		/**
		 * Look up an existing refund transaction for a withdrawal directly in
		 * the wallet ledger, independent of whatever the withdrawals row
		 * itself says. The row's own `refund_transaction_id` column is written
		 * as a *separate* statement after the credit succeeds — if that write
		 * fails or the process dies between the two, the column would read 0
		 * even though the refund already happened. The ledger (the credit
		 * transaction plus this tag) is what actually moved money, so it is
		 * the only thing idempotent_refund() trusts.
		 *
		 * @param int $withdrawal_id Withdrawal request id.
		 * @return int Transaction id, or 0 if no tagged refund exists yet.
		 */
		private static function find_refund_transaction_id( $withdrawal_id ) {
			global $wpdb;
			$meta_table = $wpdb->base_prefix . 'woo_wallet_transaction_meta';
			$txn_table  = $wpdb->base_prefix . 'woo_wallet_transactions';
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT t.transaction_id FROM {$meta_table} m INNER JOIN {$txn_table} t ON t.transaction_id = m.transaction_id WHERE m.meta_key = %s AND m.meta_value = %s AND t.deleted = 0 ORDER BY t.transaction_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::refund_meta_key(),
					(string) absint( $withdrawal_id )
				)
			);
		}

		/**
		 * Refund a rejected withdrawal's reservation, idempotently.
		 *
		 * This is the ONLY place that credits a withdrawal refund — the live
		 * reject flow and the 'processing' recovery flow both call this and
		 * nothing else, so there is exactly one code path that can ever move
		 * the money for a given withdrawal. It always checks the ledger first
		 * (via find_refund_transaction_id()) and reuses an existing tagged
		 * credit rather than issuing a new one, so calling it twice for the
		 * same withdrawal — whether from a legitimate retry, a recovery
		 * attempt, or two staff members racing — can never double-refund.
		 *
		 * The credit and its tag are still two separate statements (the
		 * underlying `credit()` API does not support attaching meta
		 * atomically), so a crash between them is still conceivable — but the
		 * next call anywhere will find nothing tagged and safely retry the
		 * credit, which is a false negative (a stray untagged credit sitting
		 * in the ledger, caught by manual reconciliation) rather than the
		 * false positive this whole design exists to prevent.
		 *
		 * @param object $request Withdrawal row.
		 * @return int Refund transaction id, or 0 if a new credit was needed and failed.
		 */
		private static function idempotent_refund( $request ) {
			$existing = self::find_refund_transaction_id( $request->id );
			if ( $existing ) {
				return $existing;
			}

			$refund_amount = (float) $request->amount + (float) $request->charge;
			/* translators: %d: withdrawal request id */
			$credit_note = sprintf( __( 'Withdrawal request #%d rejected - funds returned', 'woo-wallet' ), $request->id );
			$credit_id   = woo_wallet()->wallet->credit( $request->user_id, $refund_amount, $credit_note, array( 'category' => 'withdrawal_refund' ) );
			if ( $credit_id ) {
				update_wallet_transaction_meta( $credit_id, self::refund_meta_key(), (string) $request->id, $request->user_id );
			}
			return (int) $credit_id;
		}

		/**
		 * Handle an uploaded receipt file (PDF/PNG/JPG) as a Media Library
		 * attachment, restricted to those three types regardless of the
		 * site's normal upload_mimes allowlist.
		 *
		 * @param string $file_field `$_FILES` key.
		 * @return array {id:int, error:string} id is 0 when no file was submitted or empty on error (error explains why).
		 */
		private static function maybe_handle_receipt_upload( $file_field ) {
			if ( empty( $_FILES[ $file_field ] ) || empty( $_FILES[ $file_field ]['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return array(
					'id'    => 0,
					'error' => '',
				);
			}

			$allowed = array(
				'pdf'  => 'application/pdf',
				'png'  => 'image/png',
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
			);

			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$restrict_mimes = function ( $mimes ) use ( $allowed ) {
				return $allowed;
			};
			add_filter( 'upload_mimes', $restrict_mimes );
			$attachment_id = media_handle_upload( $file_field, 0, array(), array( 'test_form' => false ) );
			remove_filter( 'upload_mimes', $restrict_mimes );

			if ( is_wp_error( $attachment_id ) ) {
				return array(
					'id'    => 0,
					'error' => $attachment_id->get_error_message(),
				);
			}
			return array(
				'id'    => (int) $attachment_id,
				'error' => '',
			);
		}

		/**
		 * How long an uploaded receipt is kept before the cleanup sweep removes
		 * it, in days. Filterable; a value of 0 (or less) disables the sweep
		 * entirely, since a scheduled event that never has anything to do is
		 * harmless, but might as well not run.
		 *
		 * @return int
		 */
		public static function receipt_retention_days() {
			return (int) apply_filters( 'woo_wallet_withdrawal_receipt_retention_days', 90 );
		}

		/**
		 * Schedule the daily receipt-retention sweep if it isn't already
		 * scheduled. Runs on `init` rather than only on plugin activation, so
		 * an in-place code update (no re-activation) still gets it scheduled.
		 */
		public function maybe_schedule_receipt_cleanup() {
			if ( ! wp_next_scheduled( self::RECEIPT_CLEANUP_HOOK ) ) {
				wp_schedule_event( time(), 'daily', self::RECEIPT_CLEANUP_HOOK );
			}
		}

		/**
		 * Unschedule the sweep on plugin deactivation (hooked to
		 * `woo_wallet_deactivated`, fired from Woo_Wallet::deactivate_plugin()).
		 */
		public function unschedule_receipt_cleanup() {
			$timestamp = wp_next_scheduled( self::RECEIPT_CLEANUP_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::RECEIPT_CLEANUP_HOOK );
			}
		}

		/**
		 * Remove receipts older than receipt_retention_days() — both the Media
		 * Library attachment and the row's reference to it, so the customer's
		 * and admin's UI stop showing a link to a file that's been deleted, and
		 * uploaded proof-of-payment doesn't sit in the server's storage forever.
		 * Leaves a private note on each affected request so an admin reviewing
		 * it later understands why `receipt_id` is empty rather than assuming
		 * one was never attached.
		 *
		 * The request row itself (amount, bank details, status, reference
		 * number, notes) is never touched — only the uploaded file and the
		 * column pointing at it.
		 */
		public function cleanup_old_receipts() {
			$retention_days = self::receipt_retention_days();
			if ( $retention_days <= 0 ) {
				return;
			}

			global $wpdb;
			// current_time('timestamp') is already site-local; gmdate() here
			// (not date()) avoids double-applying the server's own timezone
			// offset on top of it — matches how date_created itself is written
			// (current_time('mysql')) elsewhere in this class.
			$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $retention_days * DAY_IN_SECONDS ) );

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT id, receipt_id FROM ' . self::table() . ' WHERE receipt_id > 0 AND date_created < %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff
				)
			);

			foreach ( $rows as $row ) {
				wp_delete_attachment( (int) $row->receipt_id, true );
				$wpdb->update( self::table(), array( 'receipt_id' => 0 ), array( 'id' => $row->id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::add_note(
					$row->id,
					sprintf(
						/* translators: %d: retention period in days */
						__( 'Receipt automatically removed after the %d-day retention period.', 'woo-wallet' ),
						$retention_days
					),
					'private',
					0
				);
				do_action( 'woo_wallet_withdrawal_receipt_expired', $row->id );
			}
		}

		/**
		 * Add the "Withdrawals" submenu under the Axfit Wallet admin menu.
		 */
		public function admin_menu() {
			add_submenu_page( 'woo-wallet', __( 'Withdrawals', 'woo-wallet' ), __( 'Withdrawals', 'woo-wallet' ), get_wallet_user_capability(), 'woo-wallet-withdrawals', array( $this, 'render_admin_page' ) );
		}

		/**
		 * Route the Withdrawals screen: the list (default), the manual-create
		 * form (`&action=new`), or a single request's detail view (`&action=view&id=`).
		 */
		public function render_admin_page() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-wallet' ) );
			}
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'new' === $action ) {
				$this->render_admin_create_form();
			} elseif ( 'view' === $action && ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$this->render_admin_detail( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} else {
				$this->render_admin_list();
			}
		}

		/**
		 * The Withdrawals list screen.
		 */
		private function render_admin_list() {
			if ( ! class_exists( 'Woo_Wallet_Withdrawal_Report' ) ) {
				include_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-withdrawal-report.php';
			}
			$table = new Woo_Wallet_Withdrawal_Report();
			$table->prepare_items();
			$new_url = add_query_arg(
				array(
					'page'   => 'woo-wallet-withdrawals',
					'action' => 'new',
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="wrap">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Wallet Withdrawals', 'woo-wallet' ); ?></h1>
				<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Create Withdrawal', 'woo-wallet' ); ?></a>
				<hr class="wp-header-end" />
				<?php
				/**
				 * Not wrapped in a `<form>` — the status filter in
				 * extra_tablenav() carries its own `<form>`, and row actions
				 * are now plain links (the detail screen owns every POST
				 * form), so nothing here needs an enclosing form.
				 */
				$table->display();
				?>
			</div>
			<?php
		}

		/**
		 * The manual "log a withdrawal for a customer" form — for a customer
		 * who phones/messages in rather than using the self-service tab.
		 */
		private function render_admin_create_form() {
			$list_url = admin_url( 'admin.php?page=woo-wallet-withdrawals' );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Create Withdrawal', 'woo-wallet' ); ?></h1>
				<p><?php esc_html_e( 'Log a withdrawal you are handling directly — e.g. a customer who called in. The amount is reserved from their wallet exactly as a self-service request would be, and this record is attributed to your account.', 'woo-wallet' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="max-width:640px;">
					<input type="hidden" name="action" value="woo_wallet_withdrawal_create" />
					<?php wp_nonce_field( 'woo_wallet_withdrawal_create' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="ww-customer"><?php esc_html_e( 'Customer', 'woo-wallet' ); ?></label></th>
							<td>
								<select id="ww-customer" name="user_id" class="wc-customer-search" style="width:100%;" data-placeholder="<?php esc_attr_e( 'Search by name or email&hellip;', 'woo-wallet' ); ?>" data-allow_clear="true" required></select>
							</td>
						</tr>
						<tr>
							<th><label for="ww-amount"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></label></th>
							<td><input type="number" step="0.01" min="0.01" id="ww-amount" name="amount" required /></td>
						</tr>
						<tr>
							<th><label for="ww-bank"><?php esc_html_e( 'Bank', 'woo-wallet' ); ?></label></th>
							<td>
								<select id="ww-bank" name="bank_name" class="regular-text">
									<option value=""><?php esc_html_e( '— Other (type below) —', 'woo-wallet' ); ?></option>
									<?php foreach ( Woo_Wallet_Withdrawal::get_configured_banks() as $ww_bank_value => $ww_bank_label ) : ?>
										<option value="<?php echo esc_attr( $ww_bank_value ); ?>"><?php echo esc_html( $ww_bank_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="text" id="ww-bank-other" name="bank_name_other" class="regular-text" placeholder="<?php esc_attr_e( 'Only used when "Other" is selected above', 'woo-wallet' ); ?>" style="margin-top:4px;" />
							</td>
						</tr>
						<tr>
							<th><label for="ww-beneficiary"><?php esc_html_e( 'Beneficiary Name', 'woo-wallet' ); ?></label></th>
							<td><input type="text" id="ww-beneficiary" name="beneficiary_name" class="regular-text" required /></td>
						</tr>
						<tr>
							<th><label for="ww-account"><?php esc_html_e( 'Account Number', 'woo-wallet' ); ?></label></th>
							<td><input type="text" id="ww-account" name="account_number" class="regular-text" required /></td>
						</tr>
						<tr>
							<th><label for="ww-phone"><?php esc_html_e( 'Contact Phone Number', 'woo-wallet' ); ?></label></th>
							<td><input type="tel" id="ww-phone" name="phone" class="regular-text" required /></td>
						</tr>
						<tr>
							<th><label for="ww-iban"><?php esc_html_e( 'IBAN (optional)', 'woo-wallet' ); ?></label></th>
							<td><input type="text" id="ww-iban" name="iban" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="ww-reference"><?php esc_html_e( 'Reference No. (optional)', 'woo-wallet' ); ?></label></th>
							<td><input type="text" id="ww-reference" name="reference_no" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="ww-receipt"><?php esc_html_e( 'Receipt (optional)', 'woo-wallet' ); ?></label></th>
							<td>
								<input type="file" id="ww-receipt" name="receipt" accept=".pdf,.png,.jpg,.jpeg" />
								<p class="description"><?php esc_html_e( 'PDF, PNG or JPG.', 'woo-wallet' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="ww-status"><?php esc_html_e( 'Status', 'woo-wallet' ); ?></label></th>
							<td>
								<select id="ww-status" name="status">
									<option value="pending"><?php esc_html_e( 'Pending — I still need to send the transfer', 'woo-wallet' ); ?></option>
									<option value="paid"><?php esc_html_e( 'Already paid — I already sent the transfer', 'woo-wallet' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="ww-note"><?php esc_html_e( 'Note (optional)', 'woo-wallet' ); ?></label></th>
							<td>
								<textarea id="ww-note" name="note" class="large-text" rows="3"></textarea>
								<p>
									<label><input type="radio" name="note_visibility" value="private" checked /> <?php esc_html_e( 'Private (staff only)', 'woo-wallet' ); ?></label>
									&nbsp;&nbsp;
									<label><input type="radio" name="note_visibility" value="public" /> <?php esc_html_e( 'Public (visible to the customer)', 'woo-wallet' ); ?></label>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Create Withdrawal', 'woo-wallet' ) ); ?>
					<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'woo-wallet' ); ?></a>
				</form>
			</div>
			<?php
		}

		/**
		 * Single withdrawal request detail: full field dump, notes thread, and
		 * (while pending) the mark-paid/reject form.
		 *
		 * @param int $id Request id.
		 */
		private function render_admin_detail( $id ) {
			$request = self::get_request( $id );
			if ( ! $request ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Withdrawal not found', 'woo-wallet' ) . '</h1></div>';
				return;
			}
			$customer     = get_userdata( $request->user_id );
			$created_by   = (int) $request->created_by;
			$processed_by = (int) $request->processed_by;
			$notes        = self::get_notes( $request->id );
			$post_url     = admin_url( 'admin-post.php' );
			?>
			<div class="wrap">
				<h1><?php echo esc_html( sprintf( /* translators: %d: withdrawal request id */ __( 'Withdrawal #%d', 'woo-wallet' ), $request->id ) ); ?></h1>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Customer', 'woo-wallet' ); ?></th>
						<td><?php echo $customer ? esc_html( $customer->display_name . ' <' . $customer->user_email . '>' ) : esc_html( '#' . $request->user_id ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></th>
						<td>
							<?php echo wp_kses_post( wc_price( (float) $request->amount, array( 'currency' => $request->currency ? $request->currency : get_option( 'woocommerce_currency' ) ) ) ); ?>
							<?php if ( (float) $request->charge > 0 ) : ?>
								<?php
								printf(
									/* translators: %s: charge amount */
									esc_html__( '(+ %s charge, reserved from wallet)', 'woo-wallet' ),
									wp_kses_post( wc_price( (float) $request->charge, array( 'currency' => $request->currency ? $request->currency : get_option( 'woocommerce_currency' ) ) ) )
								);
								?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bank details', 'woo-wallet' ); ?></th>
						<td>
							<?php echo esc_html( $request->bank_name ); ?><br />
							<?php echo esc_html( $request->beneficiary_name ); ?><br />
							<?php echo esc_html( $request->account_number ); ?>
							<?php if ( ! empty( $request->iban ) ) : ?>
								<br />IBAN: <?php echo esc_html( $request->iban ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Contact Phone', 'woo-wallet' ); ?></th>
						<td><?php echo $request->phone ? esc_html( $request->phone ) : '&ndash;'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Reference No.', 'woo-wallet' ); ?></th>
						<td><?php echo $request->reference_no ? esc_html( $request->reference_no ) : '&ndash;'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Receipt', 'woo-wallet' ); ?></th>
						<td>
							<?php if ( $request->receipt_id && wp_get_attachment_url( $request->receipt_id ) ) : ?>
								<a href="<?php echo esc_url( wp_get_attachment_url( $request->receipt_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View receipt', 'woo-wallet' ); ?></a>
								<?php $ww_retention_days = self::receipt_retention_days(); ?>
								<?php if ( $ww_retention_days > 0 ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %d: retention period in days */
											esc_html__( 'Automatically removed %d days after the request date.', 'woo-wallet' ),
											(int) $ww_retention_days
										);
										?>
									</p>
								<?php endif; ?>
							<?php else : ?>
								&ndash;
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'woo-wallet' ); ?></th>
						<td><span class="woo-wallet-withdrawal-status woo-wallet-withdrawal-status--<?php echo esc_attr( $request->status ); ?>"><?php echo esc_html( ucfirst( $request->status ) ); ?></span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Requested by', 'woo-wallet' ); ?></th>
						<td>
							<?php if ( $created_by && $created_by === (int) $request->user_id ) : ?>
								<?php esc_html_e( 'The customer (self-service request)', 'woo-wallet' ); ?>
							<?php elseif ( $created_by ) : ?>
								<?php $staff = get_userdata( $created_by ); ?>
								<?php
								printf(
									/* translators: %s: staff member name */
									esc_html__( 'Logged manually by staff: %s', 'woo-wallet' ),
									esc_html( $staff ? $staff->display_name : '#' . $created_by )
								);
								?>
							<?php else : ?>
								&ndash;
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $processed_by ) : ?>
					<tr>
						<th><?php esc_html_e( 'Processed by', 'woo-wallet' ); ?></th>
						<td>
							<?php $staff = get_userdata( $processed_by ); ?>
							<?php echo esc_html( $staff ? $staff->display_name : '#' . $processed_by ); ?>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Requested on', 'woo-wallet' ); ?></th>
						<td><?php echo esc_html( wc_string_to_datetime( $request->date_created )->date_i18n( wc_date_format() . ' ' . wc_time_format() ) ); ?></td>
					</tr>
				</table>

				<?php if ( 'pending' === $request->status ) : ?>
					<h2><?php esc_html_e( 'Process this request', 'woo-wallet' ); ?></h2>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="woo_wallet_withdrawal_process" />
						<input type="hidden" name="withdrawal_id" value="<?php echo esc_attr( $request->id ); ?>" />
						<?php wp_nonce_field( 'woo_wallet_withdrawal_process' ); ?>
						<table class="form-table">
							<tr>
								<th><label for="ww-proc-reference"><?php esc_html_e( 'Reference No.', 'woo-wallet' ); ?></label></th>
								<td><input type="text" id="ww-proc-reference" name="reference_no" class="regular-text" value="<?php echo esc_attr( $request->reference_no ); ?>" /></td>
							</tr>
							<tr>
								<th><label for="ww-proc-receipt"><?php esc_html_e( 'Receipt', 'woo-wallet' ); ?></label></th>
								<td>
									<input type="file" id="ww-proc-receipt" name="receipt" accept=".pdf,.png,.jpg,.jpeg" />
									<p class="description"><?php esc_html_e( 'PDF, PNG or JPG.', 'woo-wallet' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="ww-proc-note"><?php esc_html_e( 'Note', 'woo-wallet' ); ?></label></th>
								<td>
									<textarea id="ww-proc-note" name="note" class="large-text" rows="3"></textarea>
									<p>
										<label><input type="radio" name="note_visibility" value="private" checked /> <?php esc_html_e( 'Private (staff only)', 'woo-wallet' ); ?></label>
										&nbsp;&nbsp;
										<label><input type="radio" name="note_visibility" value="public" /> <?php esc_html_e( 'Public (visible to the customer)', 'woo-wallet' ); ?></label>
									</p>
								</td>
							</tr>
						</table>
						<button type="submit" name="ww_action" value="paid" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Mark this withdrawal as paid? Make sure you have already sent the bank transfer.', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Mark paid', 'woo-wallet' ); ?></button>
						<button type="submit" name="ww_action" value="reject" class="button" onclick="return confirm('<?php echo esc_js( __( 'Reject this request? The reserved amount will be returned to the customer wallet.', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Reject', 'woo-wallet' ); ?></button>
					</form>
				<?php elseif ( 'processing' === $request->status ) : ?>
					<div class="notice notice-warning inline">
						<h2><?php esc_html_e( 'Needs recovery', 'woo-wallet' ); ?></h2>
						<p><?php esc_html_e( 'This reject was interrupted by a server or database error before it could finish. Recovering it is safe to click regardless of whether the refund already went out — the refund is only ever sent once per request, whether this is the first attempt or a retry.', 'woo-wallet' ); ?></p>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="woo_wallet_withdrawal_recover" />
							<input type="hidden" name="withdrawal_id" value="<?php echo esc_attr( $request->id ); ?>" />
							<?php wp_nonce_field( 'woo_wallet_withdrawal_recover' ); ?>
							<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Recover this request? This finishes it as rejected, sending the refund only if it has not already gone out.', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Recover', 'woo-wallet' ); ?></button>
						</form>
					</div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Notes', 'woo-wallet' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-bottom:16px;">
					<input type="hidden" name="action" value="woo_wallet_withdrawal_add_note" />
					<input type="hidden" name="withdrawal_id" value="<?php echo esc_attr( $request->id ); ?>" />
					<?php wp_nonce_field( 'woo_wallet_withdrawal_add_note' ); ?>
					<textarea name="note" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'Add a note&hellip;', 'woo-wallet' ); ?>" required></textarea>
					<p>
						<label><input type="radio" name="note_visibility" value="private" checked /> <?php esc_html_e( 'Private (staff only)', 'woo-wallet' ); ?></label>
						&nbsp;&nbsp;
						<label><input type="radio" name="note_visibility" value="public" /> <?php esc_html_e( 'Public (visible to the customer)', 'woo-wallet' ); ?></label>
						<?php submit_button( __( 'Add note', 'woo-wallet' ), 'secondary', 'submit', false ); ?>
					</p>
				</form>

				<?php if ( $notes ) : ?>
					<table class="widefat striped" style="max-width:800px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Note', 'woo-wallet' ); ?></th>
								<th><?php esc_html_e( 'Visibility', 'woo-wallet' ); ?></th>
								<th><?php esc_html_e( 'By', 'woo-wallet' ); ?></th>
								<th><?php esc_html_e( 'Date', 'woo-wallet' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $notes as $note_row ) : ?>
								<?php $author = $note_row->created_by ? get_userdata( $note_row->created_by ) : null; ?>
								<tr>
									<td><?php echo esc_html( $note_row->note ); ?></td>
									<td><?php echo 'public' === $note_row->visibility ? esc_html__( 'Public', 'woo-wallet' ) : esc_html__( 'Private', 'woo-wallet' ); ?></td>
									<td><?php echo esc_html( $author ? $author->display_name : __( 'System', 'woo-wallet' ) ); ?></td>
									<td><?php echo esc_html( wc_string_to_datetime( $note_row->date_created )->date_i18n( wc_date_format() . ' ' . wc_time_format() ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'No notes yet.', 'woo-wallet' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Handle the manual "Create Withdrawal" form.
		 */
		public function handle_admin_create_request() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_withdrawal_create' );

			$admin_id = get_current_user_id();

			$target_user_id  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			$amount          = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0;
			// The form offers a dropdown of configured banks plus a free-text
			// fallback (`bank_name_other`) for a bank that isn't on the list —
			// the dropdown wins when a real option was picked.
			$bank_name       = isset( $_POST['bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_name'] ) ) : '';
			if ( '' === $bank_name && isset( $_POST['bank_name_other'] ) ) {
				$bank_name = sanitize_text_field( wp_unslash( $_POST['bank_name_other'] ) );
			}
			$beneficiary     = isset( $_POST['beneficiary_name'] ) ? sanitize_text_field( wp_unslash( $_POST['beneficiary_name'] ) ) : '';
			$account_number  = isset( $_POST['account_number'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['account_number'] ) ) ) : '';
			$phone           = isset( $_POST['phone'] ) ? preg_replace( '/[^0-9+]/', '', sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';
			$iban            = isset( $_POST['iban'] ) ? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['iban'] ) ) ) ) : '';
			$reference_no    = isset( $_POST['reference_no'] ) ? sanitize_text_field( wp_unslash( $_POST['reference_no'] ) ) : '';
			$status          = isset( $_POST['status'] ) && 'paid' === $_POST['status'] ? 'paid' : 'pending';
			$note            = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$note_visibility = isset( $_POST['note_visibility'] ) && 'public' === $_POST['note_visibility'] ? 'public' : 'private';

			// Cheap pre-check purely to decide whether it's worth uploading the
			// receipt at all — admin_create() re-validates authoritatively below
			// regardless, this just avoids wasting an upload on a doomed submission.
			$looks_valid = $target_user_id && $amount > 0 && '' !== $bank_name && '' !== $beneficiary && '' !== $account_number && strlen( $phone ) >= 8;

			if ( ! $looks_valid ) {
				$notice = array(
					'type'    => 'error',
					'message' => __( 'Please select a customer and fill in the amount, bank, beneficiary name, account number and a valid contact phone number.', 'woo-wallet' ),
				);
				set_transient( 'woo_wallet_withdrawal_admin_notice_' . $admin_id, $notice, MINUTE_IN_SECONDS );
				wp_safe_redirect( admin_url( 'admin.php?page=woo-wallet-withdrawals&action=new' ) );
				exit();
			}

			// Validate/upload the receipt (if one was submitted) before reserving
			// any funds, so a failed upload never leaves a debit sitting against
			// the customer's wallet with nothing to show for it.
			$receipt = self::maybe_handle_receipt_upload( 'receipt' );
			if ( $receipt['error'] ) {
				$notice = array(
					'type'    => 'error',
					/* translators: %s: upload error message */
					'message' => sprintf( __( 'Receipt upload failed, so the withdrawal was not created: %s', 'woo-wallet' ), $receipt['error'] ),
				);
				set_transient( 'woo_wallet_withdrawal_admin_notice_' . $admin_id, $notice, MINUTE_IN_SECONDS );
				wp_safe_redirect( admin_url( 'admin.php?page=woo-wallet-withdrawals&action=new' ) );
				exit();
			}

			$result = self::admin_create( $target_user_id, $amount, $bank_name, $beneficiary, $account_number, $phone, $iban, $admin_id, $status, $reference_no, $receipt['id'], $note, $note_visibility );

			if ( ! $result['is_valid'] ) {
				// This upload was created fresh for this one submission (unlike a
				// REST caller's receipt_id, which may be a media item they intend
				// to keep or reuse) — safe, and correct, to clean it up here.
				if ( $receipt['id'] ) {
					wp_delete_attachment( $receipt['id'], true );
				}
				$notice = array(
					'type'    => 'error',
					'message' => $result['message'],
				);
				set_transient( 'woo_wallet_withdrawal_admin_notice_' . $admin_id, $notice, MINUTE_IN_SECONDS );
				wp_safe_redirect( admin_url( 'admin.php?page=woo-wallet-withdrawals&action=new' ) );
				exit();
			}

			set_transient(
				'woo_wallet_withdrawal_admin_notice_' . $admin_id,
				array(
					'type'    => 'success',
					'message' => $result['message'],
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'woo-wallet-withdrawals',
						'action' => 'view',
						'id'     => $result['id'],
					),
					admin_url( 'admin.php' )
				)
			);
			exit();
		}

		/**
		 * Manually log a withdrawal on a customer's behalf — staff-attributed
		 * (created_by = $admin_id, distinct from the customer's own user_id).
		 * Shared by the classic admin-post handler above and
		 * TeraWallet_REST_Admin_Withdrawal_Controller::create_item().
		 *
		 * Deliberately does NOT constrain $bank_name to get_configured_banks():
		 * this is a staff-entered record of a request that may have come in by
		 * phone, not the customer self-service dropdown (submit_request()),
		 * which does constrain it.
		 *
		 * @param int    $target_user_id   Customer whose wallet is charged.
		 * @param float  $amount           Requested payout amount.
		 * @param string $bank_name        Free-text bank name.
		 * @param string $beneficiary_name Beneficiary name.
		 * @param string $account_number   Bank account number.
		 * @param string $phone            Contact phone number.
		 * @param string $iban             Optional IBAN.
		 * @param int    $admin_id         Staff member creating the request.
		 * @param string $status           'pending' or 'paid'.
		 * @param string $reference_no     Optional bank transfer reference number.
		 * @param int    $receipt_id       Optional, an already-uploaded attachment id.
		 * @param string $note             Optional note.
		 * @param string $note_visibility  'public' or 'private'.
		 * @return array {is_valid, message, id?}
		 */
		public static function admin_create( $target_user_id, $amount, $bank_name, $beneficiary_name, $account_number, $phone, $iban, $admin_id, $status, $reference_no = '', $receipt_id = 0, $note = '', $note_visibility = 'private' ) {
			$target_user_id   = (int) $target_user_id;
			$customer         = $target_user_id ? get_userdata( $target_user_id ) : false;
			$amount           = (float) $amount;
			$bank_name        = trim( (string) $bank_name );
			$beneficiary_name = trim( (string) $beneficiary_name );
			$account_number   = preg_replace( '/\s+/', '', (string) $account_number );
			$phone            = preg_replace( '/[^0-9+]/', '', (string) $phone );
			$status           = 'paid' === $status ? 'paid' : 'pending';

			if ( ! $customer ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please select a customer.', 'woo-wallet' ),
				);
			}
			if ( $amount <= 0 ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Amount must be greater than zero.', 'woo-wallet' ),
				);
			}
			if ( '' === $bank_name || '' === $beneficiary_name || '' === $account_number ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Bank, beneficiary name and account number are required.', 'woo-wallet' ),
				);
			}
			if ( strlen( $phone ) < 8 ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter a valid contact phone number.', 'woo-wallet' ),
				);
			}

			$result = self::reserve_and_insert( $target_user_id, $amount, $bank_name, $beneficiary_name, $account_number, $phone, (string) $iban, (int) $admin_id, $status, (string) $reference_no );
			if ( ! $result['is_valid'] ) {
				return $result;
			}

			if ( $receipt_id ) {
				global $wpdb;
				$wpdb->update( self::table(), array( 'receipt_id' => (int) $receipt_id ), array( 'id' => $result['id'] ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
			if ( $note ) {
				self::add_note( $result['id'], $note, $note_visibility, $admin_id );
			}
			do_action( 'woo_wallet_withdrawal_requested', $result['id'], $target_user_id, $amount + $result['charge'] );
			if ( 'paid' === $status ) {
				do_action( 'woo_wallet_withdrawal_paid', $result['id'], $target_user_id );
			}

			return array(
				'is_valid' => true,
				/* translators: %d: withdrawal request id */
				'message'  => sprintf( __( 'Withdrawal #%d created.', 'woo-wallet' ), $result['id'] ),
				'id'       => $result['id'],
			);
		}

		/**
		 * Handle a standalone note added from the detail screen.
		 */
		public function handle_admin_add_note() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_withdrawal_add_note' );

			$id         = isset( $_POST['withdrawal_id'] ) ? absint( $_POST['withdrawal_id'] ) : 0;
			$note       = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$visibility = isset( $_POST['note_visibility'] ) && 'public' === $_POST['note_visibility'] ? 'public' : 'private';

			if ( $id && $note ) {
				self::add_note( $id, $note, $visibility, get_current_user_id() );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'woo-wallet-withdrawals',
						'action' => 'view',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit();
		}

		/**
		 * Handle the admin "mark paid" / "reject" action from the request detail screen.
		 */
		public function handle_admin_process_request() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_withdrawal_process' );

			$admin_id        = get_current_user_id();
			$id              = isset( $_POST['withdrawal_id'] ) ? absint( $_POST['withdrawal_id'] ) : 0;
			$action          = isset( $_POST['ww_action'] ) ? sanitize_key( wp_unslash( $_POST['ww_action'] ) ) : '';
			$reference_no    = isset( $_POST['reference_no'] ) ? sanitize_text_field( wp_unslash( $_POST['reference_no'] ) ) : '';
			$note            = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$note_visibility = isset( $_POST['note_visibility'] ) && 'public' === $_POST['note_visibility'] ? 'public' : 'private';

			// Validate/upload the receipt (if one was submitted) before touching
			// any state, so a failed upload never leaves the request half-processed.
			$receipt = self::maybe_handle_receipt_upload( 'receipt' );
			if ( $receipt['error'] ) {
				$notice = array(
					'type'    => 'error',
					/* translators: %s: upload error message */
					'message' => sprintf( __( 'Receipt upload failed, so the request was not changed: %s', 'woo-wallet' ), $receipt['error'] ),
				);
				set_transient( 'woo_wallet_withdrawal_admin_notice_' . $admin_id, $notice, MINUTE_IN_SECONDS );
				wp_safe_redirect( add_query_arg( array( 'page' => 'woo-wallet-withdrawals', 'action' => 'view', 'id' => $id ), admin_url( 'admin.php' ) ) );
				exit();
			}

			$result = self::admin_process( $id, $action, $admin_id, $reference_no, $receipt['id'], $note, $note_visibility );

			// This upload was created fresh for this one submission (unlike a REST
			// caller's receipt_id, which may be a media item they intend to keep or
			// reuse) — safe, and correct, to clean it up if it never got attached.
			if ( ! empty( $result['receipt_orphaned'] ) && $receipt['id'] ) {
				wp_delete_attachment( $receipt['id'], true );
			}

			$notice = array(
				'type'    => $result['is_valid'] ? 'success' : 'error',
				'message' => $result['message'],
			);

			set_transient( 'woo_wallet_withdrawal_admin_notice_' . $admin_id, $notice, MINUTE_IN_SECONDS );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'woo-wallet-withdrawals',
						'action' => 'view',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit();
		}

		/**
		 * Mark a pending withdrawal request paid or rejected. Shared by the
		 * classic admin-post handler above and
		 * TeraWallet_REST_Admin_Withdrawal_Controller::process_item().
		 *
		 * @param int    $id              Withdrawal request id.
		 * @param string $action          'paid' or 'reject'.
		 * @param int    $admin_id        Staff member processing the request.
		 * @param string $reference_no    Optional bank transfer reference number.
		 * @param int    $receipt_id      Optional, an already-uploaded attachment id.
		 * @param string $note            Optional note.
		 * @param string $note_visibility 'public' or 'private'.
		 * @return array {is_valid, message, receipt_orphaned?} `receipt_orphaned` is
		 *               true only when $receipt_id was passed but the request could
		 *               not be claimed (a race with another staff member) — the only
		 *               case where a fresh upload never gets attached to anything.
		 */
		public static function admin_process( $id, $action, $admin_id, $reference_no = '', $receipt_id = 0, $note = '', $note_visibility = 'private' ) {
			$request = $id ? self::get_request( $id ) : null;
			if ( ! $request ) {
				return array(
					'is_valid'         => false,
					'message'          => __( 'Withdrawal request not found.', 'woo-wallet' ),
					'receipt_orphaned' => (bool) $receipt_id,
				);
			}
			if ( 'pending' !== $request->status ) {
				return array(
					'is_valid'         => false,
					'message'          => __( 'This request has already been processed.', 'woo-wallet' ),
					'receipt_orphaned' => (bool) $receipt_id,
				);
			}
			if ( ! in_array( $action, array( 'paid', 'reject' ), true ) ) {
				return array(
					'is_valid'         => false,
					'message'          => __( 'Invalid action.', 'woo-wallet' ),
					'receipt_orphaned' => (bool) $receipt_id,
				);
			}

			global $wpdb;

			if ( 'paid' === $action ) {
				// Marking paid has no follow-up money movement (the admin already
				// sent the transfer manually) — one atomic, race-safe update is enough.
				$update = array(
					'status'       => 'paid',
					'processed_by' => $admin_id,
					'date_updated' => current_time( 'mysql' ),
				);
				$formats = array( '%s', '%d', '%s' );
				if ( $reference_no ) {
					$update['reference_no'] = $reference_no;
					$formats[]              = '%s';
				}
				if ( $receipt_id ) {
					$update['receipt_id'] = $receipt_id;
					$formats[]             = '%d';
				}

				// Conditioned on `status = 'pending'` and checked via the
				// affected-row count: this is what makes two staff members
				// racing to process the same request safe.
				$affected = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::table(),
					$update,
					array(
						'id'     => $request->id,
						'status' => 'pending',
					),
					$formats,
					array( '%d', '%s' )
				);

				if ( ! $affected ) {
					return array(
						'is_valid'         => false,
						'message'          => __( 'This request was just processed by someone else — no changes were made.', 'woo-wallet' ),
						'receipt_orphaned' => (bool) $receipt_id,
					);
				}
				if ( $note ) {
					self::add_note( $request->id, $note, $note_visibility, $admin_id );
				}
				do_action( 'woo_wallet_withdrawal_paid', $request->id, $request->user_id );
				return array(
					'is_valid' => true,
					/* translators: %d: withdrawal request id */
					'message'  => sprintf( __( 'Withdrawal request #%d marked as paid.', 'woo-wallet' ), $request->id ),
				);
			}

			// Reject DOES have a follow-up money movement (crediting the
			// reservation back), which can itself fail. Two-phase: first
			// atomically CLAIM the row (pending -> processing) so no other
			// staff member can act on it concurrently; only after the
			// refund actually succeeds does it finalize to 'rejected'. If
			// the refund fails, it is reverted to 'pending' so it can be
			// retried — never left stuck as 'rejected' with the money
			// never having moved (which is the whole bug this avoids).
			$claim = array(
				'status'       => 'processing',
				'processed_by' => $admin_id,
				'date_updated' => current_time( 'mysql' ),
			);
			$claim_formats = array( '%s', '%d', '%s' );
			if ( $reference_no ) {
				$claim['reference_no'] = $reference_no;
				$claim_formats[]       = '%s';
			}
			if ( $receipt_id ) {
				$claim['receipt_id'] = $receipt_id;
				$claim_formats[]      = '%d';
			}
			$claimed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				$claim,
				array(
					'id'     => $request->id,
					'status' => 'pending',
				),
				$claim_formats,
				array( '%d', '%s' )
			);

			if ( ! $claimed ) {
				return array(
					'is_valid'         => false,
					'message'          => __( 'This request was just processed by someone else — no changes were made.', 'woo-wallet' ),
					'receipt_orphaned' => (bool) $receipt_id,
				);
			}

			// idempotent_refund() checks the wallet ledger itself before
			// crediting anything — see its docblock for why that (and not
			// the refund_transaction_id column written below) is the
			// actual guarantee against a double refund.
			$credit_id = self::idempotent_refund( $request );

			if ( $credit_id ) {
				// One statement, not two: if this is lost to a crash, the
				// row is simply stuck on 'processing' with the refund
				// already tagged in the ledger, which the Recover action
				// resolves safely by calling idempotent_refund() again
				// (finds the tag, credits nothing new) — the column here
				// is fast-display bookkeeping, not the safety mechanism.
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::table(),
					array(
						'status'                => 'rejected',
						'refund_transaction_id' => $credit_id,
					),
					array( 'id' => $request->id ),
					array( '%s', '%d' ),
					array( '%d' )
				);
				if ( $note ) {
					self::add_note( $request->id, $note, $note_visibility, $admin_id );
				}
				do_action( 'woo_wallet_withdrawal_rejected', $request->id, $request->user_id, $credit_id );
				return array(
					'is_valid' => true,
					/* translators: %d: withdrawal request id */
					'message'  => sprintf( __( 'Withdrawal request #%d rejected and funds returned to the customer wallet.', 'woo-wallet' ), $request->id ),
				);
			}

			// The refund failed — put the request back to 'pending' rather than
			// leave it stuck 'rejected'/'processing' with the customer never
			// actually paid back. The receipt/reference already written during
			// the claim step stay on the row (not orphaned) — only a lost race
			// (above) ever leaves a fresh upload unattached to anything.
			$refund_amount = (float) $request->amount + (float) $request->charge;
			$wpdb->update( self::table(), array( 'status' => 'pending' ), array( 'id' => $request->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $note ) {
				self::add_note( $request->id, $note, $note_visibility, $admin_id );
			}
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Axfit Wallet: withdrawal #%d reject refund FAILED — reverted to pending for retry. user_id=%d amount=%s',
					$request->id,
					$request->user_id,
					$refund_amount
				)
			);
			do_action( 'woo_wallet_withdrawal_reject_refund_failed', $request->id, $request->user_id, $refund_amount );
			return array(
				'is_valid' => false,
				'message'  => __( 'The refund to the customer wallet failed, so the request was left pending. Please try Reject again.', 'woo-wallet' ),
			);
		}

		/**
		 * Print the one-shot admin notice left by handle_admin_process_request().
		 */
		public function admin_notices() {
			$key    = 'woo_wallet_withdrawal_admin_notice_' . get_current_user_id();
			$notice = get_transient( $key );
			if ( ! $notice ) {
				return;
			}
			delete_transient( $key );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'success' === $notice['type'] ? 'success' : 'error',
				esc_html( $notice['message'] )
			);
		}

		/**
		 * Flag any withdrawal requests stuck on the transient 'processing'
		 * status — a reject that was interrupted between claiming the row and
		 * finishing it (see handle_admin_process_request()). Deliberately not
		 * `is-dismissible`: dismissing it client-side would not fix anything,
		 * and the underlying row still needs an admin to open it and recover it.
		 */
		public function maybe_show_stuck_processing_notice() {
			$screen = get_current_screen();
			if ( ! $screen || woo_wallet_get_screen_id( 'woo-wallet-withdrawals' ) !== $screen->id ) {
				return;
			}
			$stuck = self::count_requests( array( 'status' => 'processing' ) );
			if ( ! $stuck ) {
				return;
			}
			$url = add_query_arg(
				array(
					'page'              => 'woo-wallet-withdrawals',
					'withdrawal_status' => 'processing',
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: 1: number of stuck requests, 2: link open tag, 3: link close tag */
						_n(
							'%1$d withdrawal request is stuck mid-processing (interrupted by a server error) and needs manual recovery. %2$sReview it%3$s.',
							'%1$d withdrawal requests are stuck mid-processing (interrupted by a server error) and need manual recovery. %2$sReview them%3$s.',
							$stuck,
							'woo-wallet'
						),
						$stuck,
						'<a href="' . esc_url( $url ) . '">',
						'</a>'
					)
				)
			);
		}

		/**
		 * Recover a withdrawal request stuck on 'processing' — a reject that
		 * was interrupted (server crash / DB error) between claiming the row
		 * and finishing it.
		 *
		 * Deliberately does NOT branch on the request's own
		 * `refund_transaction_id` column — that column is written as a
		 * best-effort statement *after* the credit succeeds, so it can read 0
		 * even when the refund already went out (if the process died between
		 * the two writes), which would make a column-based decision here
		 * reset an already-refunded request back to 'pending' and refund it
		 * again on the next Reject. Instead this just calls
		 * idempotent_refund() — the same call the live reject flow makes —
		 * which checks the wallet ledger itself before crediting anything, so
		 * calling it here can never double-refund regardless of what this
		 * row's own bookkeeping managed to record.
		 *
		 * The claim→finalize step is itself atomic (conditioned on the row
		 * still being 'processing'), so two staff members recovering the same
		 * request concurrently cannot both finalize it and double-fire the
		 * 'rejected' hook.
		 */
		public function handle_admin_recover_request() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_withdrawal_recover' );

			$admin_id = get_current_user_id();
			$id       = isset( $_POST['withdrawal_id'] ) ? absint( $_POST['withdrawal_id'] ) : 0;
			$result   = self::admin_recover( $id, $admin_id );
			$notice   = array(
				'type'    => $result['is_valid'] ? 'success' : 'error',
				'message' => $result['message'],
			);

			set_transient( 'woo_wallet_withdrawal_admin_notice_' . $admin_id, $notice, MINUTE_IN_SECONDS );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'woo-wallet-withdrawals',
						'action' => 'view',
						'id'     => $id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit();
		}

		/**
		 * Recover a withdrawal request stuck on 'processing'. Shared by the
		 * classic admin-post handler above and
		 * TeraWallet_REST_Admin_Withdrawal_Controller::recover_item().
		 *
		 * See handle_admin_recover_request()'s docblock for why this always
		 * calls idempotent_refund() rather than branching on the request's own
		 * refund_transaction_id column.
		 *
		 * @param int $id       Withdrawal request id.
		 * @param int $admin_id Staff member performing the recovery.
		 * @return array {is_valid, message} `is_valid` is true both when this
		 *               call finished the recovery and when it finds the
		 *               request was already recovered by someone else — both
		 *               are a successful end state for the caller.
		 */
		public static function admin_recover( $id, $admin_id ) {
			$request = $id ? self::get_request( $id ) : null;
			if ( ! $request || 'processing' !== $request->status ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'This request is not awaiting recovery (it may already have been resolved).', 'woo-wallet' ),
				);
			}

			$credit_id = self::idempotent_refund( $request );

			if ( ! $credit_id ) {
				// The refund still couldn't be issued right now (wallet locked,
				// DB error...) — leave it on 'processing' rather than guess;
				// the caller can try Recover again once the underlying issue
				// clears. Resetting to 'pending' here would be exactly the bug
				// this design avoids if the refund actually did go out on a
				// prior attempt this call simply failed to find.
				return array(
					'is_valid' => false,
					'message'  => __( 'The refund could not be issued right now, so nothing was changed. Please try Recover again.', 'woo-wallet' ),
				);
			}

			global $wpdb;
			// Conditioned on `status = 'processing'`: if two staff members both
			// land here for the same row, only one of these updates affects a
			// row, so the hook/note below fire exactly once.
			$affected = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'status'                => 'rejected',
					'refund_transaction_id' => $credit_id,
				),
				array(
					'id'     => $request->id,
					'status' => 'processing',
				),
				array( '%s', '%d' ),
				array( '%d', '%s' )
			);

			if ( ! $affected ) {
				return array(
					'is_valid' => true,
					'message'  => __( 'This request was already recovered by someone else — no changes were made.', 'woo-wallet' ),
				);
			}

			self::add_note(
				$request->id,
				sprintf(
					/* translators: %d: refund transaction id */
					__( 'Recovered: finished as rejected. Refund is wallet transaction #%d (reused the existing refund if the interruption happened after it was already sent — never sent a second one).', 'woo-wallet' ),
					$credit_id
				),
				'private',
				$admin_id
			);
			do_action( 'woo_wallet_withdrawal_rejected', $request->id, $request->user_id, $credit_id );
			return array(
				'is_valid' => true,
				/* translators: %d: withdrawal request id */
				'message'  => sprintf( __( 'Withdrawal request #%d recovered and finished as rejected.', 'woo-wallet' ), $request->id ),
			);
		}
	}
}

new Woo_Wallet_Withdrawal();
