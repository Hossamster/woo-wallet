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

			if ( is_admin() ) {
				add_action( 'admin_menu', array( $this, 'admin_menu' ), 70 );
				add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
				add_action( 'admin_post_woo_wallet_withdrawal_process', array( $this, 'handle_admin_process_request' ) );
				add_action( 'admin_post_woo_wallet_withdrawal_create', array( $this, 'handle_admin_create_request' ) );
				add_action( 'admin_post_woo_wallet_withdrawal_add_note', array( $this, 'handle_admin_add_note' ) );
				add_action( 'admin_notices', array( $this, 'admin_notices' ) );
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
			return 'on' === woo_wallet()->settings_api->get_option( 'is_enable_wallet_withdrawal', '_wallet_settings_withdrawal', 'off' );
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
		 * Validate a submitted request, reserve the funds, and record it.
		 *
		 * @return array {is_valid, message}
		 */
		private function handle_withdraw_request() {
			if ( ! $this->is_withdraw_enabled() ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Wallet withdrawal is not available right now.', 'woo-wallet' ),
				);
			}

			$user_id = get_current_user_id();
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

			$amount = isset( $_POST['woo_wallet_withdraw_amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_amount'] ) ) : 0;
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

			$bank_name = isset( $_POST['woo_wallet_withdraw_bank'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_bank'] ) ) : '';
			$allowed_banks = self::get_configured_banks();
			if ( '' === $bank_name || ! isset( $allowed_banks[ $bank_name ] ) ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please select a valid bank.', 'woo-wallet' ),
				);
			}

			$beneficiary_name = isset( $_POST['woo_wallet_withdraw_beneficiary'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_beneficiary'] ) ) : '';
			if ( '' === $beneficiary_name ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter the beneficiary name.', 'woo-wallet' ),
				);
			}

			$account_number = isset( $_POST['woo_wallet_withdraw_account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_account_number'] ) ) : '';
			$account_number = preg_replace( '/\s+/', '', (string) $account_number );
			if ( '' === $account_number ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter the bank account number.', 'woo-wallet' ),
				);
			}

			$iban = isset( $_POST['woo_wallet_withdraw_iban'] ) ? sanitize_text_field( wp_unslash( $_POST['woo_wallet_withdraw_iban'] ) ) : '';
			$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
			if ( '' !== $iban && ! apply_filters( 'woo_wallet_is_valid_egyptian_iban', (bool) preg_match( '/^EG\d{27}$/', $iban ), $iban ) ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Please enter a valid Egyptian IBAN (starts with EG, 29 characters) or leave it blank.', 'woo-wallet' ),
				);
			}

			$result = self::reserve_and_insert( $user_id, $amount, $bank_name, $beneficiary_name, $account_number, $iban, $user_id, 'pending', '' );
			if ( ! $result['is_valid'] ) {
				return $result;
			}

			do_action( 'woo_wallet_withdrawal_requested', $result['id'], $user_id, $amount + $result['charge'] );

			return array(
				'is_valid' => true,
				'message'  => __( 'Your withdrawal request has been submitted and is awaiting approval.', 'woo-wallet' ),
			);
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
		 * @param string $iban             Optional IBAN.
		 * @param int    $created_by       User id who created the request (customer themself, or the staff member logging it).
		 * @param string $status           Initial status: 'pending' or 'paid'.
		 * @param string $reference_no     Optional bank transfer reference number.
		 * @return array {is_valid, message, id, charge}
		 */
		private static function reserve_and_insert( $user_id, $amount, $bank_name, $beneficiary_name, $account_number, $iban, $created_by, $status = 'pending', $reference_no = '' ) {
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
				'iban'             => $iban,
				'reference_no'     => $reference_no,
				'status'           => $status,
			);
			if ( 'paid' === $status ) {
				$row_args['processed_by'] = $created_by;
			}
			$withdrawal_id = self::insert_request( $row_args );

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
				'iban'             => (string) $data['iban'],
				'reference_no'     => isset( $data['reference_no'] ) ? (string) $data['reference_no'] : '',
				'receipt_id'       => isset( $data['receipt_id'] ) ? (int) $data['receipt_id'] : 0,
				'status'           => (string) $data['status'],
				'created_by'       => isset( $data['created_by'] ) ? (int) $data['created_by'] : 0,
				'processed_by'     => isset( $data['processed_by'] ) ? (int) $data['processed_by'] : 0,
				'date_created'     => current_time( 'mysql' ),
			);
			$formats = array( '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s' );
			if ( 'paid' === $row['status'] ) {
				$row['date_updated'] = current_time( 'mysql' );
				$formats[]           = '%s';
			}
			$wpdb->insert( self::table(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return (int) $wpdb->insert_id;
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
		 * Fetch a page of requests, optionally filtered by status/user.
		 *
		 * @param array $args {status, user_id, limit, offset}.
		 * @return array
		 */
		public static function get_requests( array $args = array() ) {
			global $wpdb;
			$where  = array( '1=1' );
			$params = array();

			if ( ! empty( $args['status'] ) ) {
				$where[]  = 'status = %s';
				$params[] = $args['status'];
			}
			if ( ! empty( $args['user_id'] ) ) {
				$where[]  = 'user_id = %d';
				$params[] = (int) $args['user_id'];
			}

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
		 * Count requests, optionally filtered by status/user — same filter shape as get_requests().
		 *
		 * @param array $args {status, user_id}.
		 * @return int
		 */
		public static function count_requests( array $args = array() ) {
			global $wpdb;
			$where  = array( '1=1' );
			$params = array();

			if ( ! empty( $args['status'] ) ) {
				$where[]  = 'status = %s';
				$params[] = $args['status'];
			}
			if ( ! empty( $args['user_id'] ) ) {
				$where[]  = 'user_id = %d';
				$params[] = (int) $args['user_id'];
			}

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
							<td><input type="text" id="ww-bank" name="bank_name" class="regular-text" required /></td>
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
						<th><?php esc_html_e( 'Reference No.', 'woo-wallet' ); ?></th>
						<td><?php echo $request->reference_no ? esc_html( $request->reference_no ) : '&ndash;'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Receipt', 'woo-wallet' ); ?></th>
						<td>
							<?php if ( $request->receipt_id && wp_get_attachment_url( $request->receipt_id ) ) : ?>
								<a href="<?php echo esc_url( wp_get_attachment_url( $request->receipt_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View receipt', 'woo-wallet' ); ?></a>
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
			$notice   = array();

			$target_user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			$customer        = $target_user_id ? get_userdata( $target_user_id ) : false;
			$amount          = isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0;
			$bank_name       = isset( $_POST['bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_name'] ) ) : '';
			$beneficiary     = isset( $_POST['beneficiary_name'] ) ? sanitize_text_field( wp_unslash( $_POST['beneficiary_name'] ) ) : '';
			$account_number  = isset( $_POST['account_number'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['account_number'] ) ) ) : '';
			$iban            = isset( $_POST['iban'] ) ? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['iban'] ) ) ) ) : '';
			$reference_no    = isset( $_POST['reference_no'] ) ? sanitize_text_field( wp_unslash( $_POST['reference_no'] ) ) : '';
			$status          = isset( $_POST['status'] ) && 'paid' === $_POST['status'] ? 'paid' : 'pending';
			$note            = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$note_visibility = isset( $_POST['note_visibility'] ) && 'public' === $_POST['note_visibility'] ? 'public' : 'private';

			if ( ! $customer ) {
				$notice = array(
					'type'    => 'error',
					'message' => __( 'Please select a customer.', 'woo-wallet' ),
				);
			} elseif ( $amount <= 0 ) {
				$notice = array(
					'type'    => 'error',
					'message' => __( 'Amount must be greater than zero.', 'woo-wallet' ),
				);
			} elseif ( '' === $bank_name || '' === $beneficiary || '' === $account_number ) {
				$notice = array(
					'type'    => 'error',
					'message' => __( 'Bank, beneficiary name and account number are required.', 'woo-wallet' ),
				);
			} else {
				$result = self::reserve_and_insert( $target_user_id, $amount, $bank_name, $beneficiary, $account_number, $iban, $admin_id, $status, $reference_no );
				if ( ! $result['is_valid'] ) {
					$notice = array(
						'type'    => 'error',
						'message' => $result['message'],
					);
				} else {
					$receipt = self::maybe_handle_receipt_upload( 'receipt' );
					if ( $receipt['id'] ) {
						global $wpdb;
						$wpdb->update( self::table(), array( 'receipt_id' => $receipt['id'] ), array( 'id' => $result['id'] ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					}
					if ( $note ) {
						self::add_note( $result['id'], $note, $note_visibility, $admin_id );
					}
					do_action( 'woo_wallet_withdrawal_requested', $result['id'], $target_user_id, $amount + $result['charge'] );
					if ( 'paid' === $status ) {
						do_action( 'woo_wallet_withdrawal_paid', $result['id'], $target_user_id );
					}
					set_transient(
						'woo_wallet_withdrawal_admin_notice_' . $admin_id,
						array(
							'type'    => 'success',
							/* translators: %d: withdrawal request id */
							'message' => sprintf( __( 'Withdrawal #%d created.', 'woo-wallet' ), $result['id'] ),
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
			}

			set_transient( 'woo_wallet_withdrawal_admin_notice_' . $admin_id, $notice, MINUTE_IN_SECONDS );
			wp_safe_redirect( admin_url( 'admin.php?page=woo-wallet-withdrawals&action=new' ) );
			exit();
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

			$request = $id ? self::get_request( $id ) : null;
			$notice  = array(
				'type'    => 'error',
				'message' => __( 'Withdrawal request not found.', 'woo-wallet' ),
			);

			if ( $request && 'pending' !== $request->status ) {
				$notice = array(
					'type'    => 'error',
					'message' => __( 'This request has already been processed.', 'woo-wallet' ),
				);
			} elseif ( $request && in_array( $action, array( 'paid', 'reject' ), true ) ) {
				$receipt = self::maybe_handle_receipt_upload( 'receipt' );

				global $wpdb;
				$update = array(
					'status'       => 'paid' === $action ? 'paid' : 'rejected',
					'processed_by' => $admin_id,
					'date_updated' => current_time( 'mysql' ),
				);
				$formats = array( '%s', '%d', '%s' );
				if ( $reference_no ) {
					$update['reference_no'] = $reference_no;
					$formats[]              = '%s';
				}
				if ( $receipt['id'] ) {
					$update['receipt_id'] = $receipt['id'];
					$formats[]             = '%d';
				}
				$wpdb->update( self::table(), $update, array( 'id' => $request->id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

				if ( $note ) {
					self::add_note( $request->id, $note, $note_visibility, $admin_id );
				}

				if ( 'paid' === $action ) {
					do_action( 'woo_wallet_withdrawal_paid', $request->id, $request->user_id );
					$notice = array(
						'type'    => 'success',
						/* translators: %d: withdrawal request id */
						'message' => sprintf( __( 'Withdrawal request #%d marked as paid.', 'woo-wallet' ), $request->id ),
					);
				} else {
					$refund_amount = (float) $request->amount + (float) $request->charge;
					/* translators: %d: withdrawal request id */
					$credit_note = sprintf( __( 'Withdrawal request #%d rejected - funds returned', 'woo-wallet' ), $request->id );
					$credit_id   = woo_wallet()->wallet->credit( $request->user_id, $refund_amount, $credit_note, array( 'category' => 'withdrawal_refund' ) );
					do_action( 'woo_wallet_withdrawal_rejected', $request->id, $request->user_id, $credit_id );
					$notice = array(
						'type'    => 'success',
						/* translators: %d: withdrawal request id */
						'message' => sprintf( __( 'Withdrawal request #%d rejected and funds returned to the customer wallet.', 'woo-wallet' ), $request->id ),
					);
				}
			}

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
	}
}

new Woo_Wallet_Withdrawal();
