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
				add_action( 'admin_post_woo_wallet_withdrawal_process', array( $this, 'handle_admin_process_request' ) );
				add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			}
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

			$charge_type   = woo_wallet()->settings_api->get_option( 'withdrawal_charge_type', '_wallet_settings_withdrawal', 'fixed' );
			$charge_amount = (float) woo_wallet()->settings_api->get_option( 'withdrawal_charge_amount', '_wallet_settings_withdrawal', 0 );
			$charge        = 'percent' === $charge_type ? ( $amount * $charge_amount ) / 100 : $charge_amount;
			$charge        = (float) apply_filters( 'woo_wallet_withdrawal_charge_amount', $charge, $user_id, $amount );
			$debit_amount  = $amount + $charge;

			$current_balance = woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
			if ( $current_balance <= 0 || $debit_amount > $current_balance ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Entered amount is greater than your current wallet balance.', 'woo-wallet' ),
				);
			}

			/* translators: %s: bank name */
			$debit_note = sprintf( __( 'Withdrawal request reserved for payout to %s', 'woo-wallet' ), $bank_name );
			$debit_note = apply_filters( 'woo_wallet_withdrawal_debit_note', $debit_note, $user_id, $amount );

			$transaction_id = woo_wallet()->wallet->debit( $user_id, $debit_amount, $debit_note, array( 'category' => 'withdrawal' ) );
			if ( ! $transaction_id ) {
				return array(
					'is_valid' => false,
					'message'  => __( 'Entered amount is greater than your current wallet balance.', 'woo-wallet' ),
				);
			}

			$withdrawal_id = self::insert_request(
				array(
					'user_id'          => $user_id,
					'transaction_id'   => $transaction_id,
					'amount'           => $amount,
					'charge'           => $charge,
					'currency'         => woo_wallet()->wallet->resolve_active_currency(),
					'bank_name'        => $bank_name,
					'beneficiary_name' => $beneficiary_name,
					'account_number'   => $account_number,
					'iban'             => $iban,
					'status'           => 'pending',
				)
			);

			do_action( 'woo_wallet_withdrawal_requested', $withdrawal_id, $user_id, $debit_amount );

			return array(
				'is_valid' => true,
				'message'  => __( 'Your withdrawal request has been submitted and is awaiting approval.', 'woo-wallet' ),
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
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'user_id'          => (int) $data['user_id'],
					'transaction_id'   => (int) $data['transaction_id'],
					'amount'           => (float) $data['amount'],
					'charge'           => (float) $data['charge'],
					'currency'         => (string) $data['currency'],
					'bank_name'        => (string) $data['bank_name'],
					'beneficiary_name' => (string) $data['beneficiary_name'],
					'account_number'   => (string) $data['account_number'],
					'iban'             => (string) $data['iban'],
					'status'           => (string) $data['status'],
					'date_created'     => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
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
		 * Add the "Withdrawals" submenu under the Axfit Wallet admin menu.
		 */
		public function admin_menu() {
			add_submenu_page( 'woo-wallet', __( 'Withdrawals', 'woo-wallet' ), __( 'Withdrawals', 'woo-wallet' ), get_wallet_user_capability(), 'woo-wallet-withdrawals', array( $this, 'render_admin_page' ) );
		}

		/**
		 * Render the admin Withdrawals review screen.
		 */
		public function render_admin_page() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-wallet' ) );
			}
			if ( ! class_exists( 'Woo_Wallet_Withdrawal_Report' ) ) {
				include_once WOO_WALLET_ABSPATH . 'includes/admin/class-woo-wallet-withdrawal-report.php';
			}
			$table = new Woo_Wallet_Withdrawal_Report();
			$table->prepare_items();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Wallet Withdrawals', 'woo-wallet' ); ?></h1>
				<?php
				/**
				 * Deliberately not wrapped in a `<form>` — unlike the read-only
				 * Referral Report table, every pending row here renders its own
				 * `<form method="post">` (Mark paid / Reject) inside a cell, and
				 * forms cannot nest. Pagination links are plain GET anchors and
				 * the status filter carries its own `<form>` in extra_tablenav(),
				 * so nothing here needs an enclosing form.
				 */
				$table->display();
				?>
			</div>
			<?php
		}

		/**
		 * Handle the admin "mark paid" / "reject" action from the Withdrawals screen.
		 */
		public function handle_admin_process_request() {
			if ( ! current_user_can( get_wallet_user_capability() ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_withdrawal_process' );

			$id     = isset( $_POST['withdrawal_id'] ) ? absint( $_POST['withdrawal_id'] ) : 0;
			$action = isset( $_POST['ww_action'] ) ? sanitize_key( wp_unslash( $_POST['ww_action'] ) ) : '';
			$note   = isset( $_POST['admin_note'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_note'] ) ) : '';

			$request = $id ? self::get_request( $id ) : null;
			$notice  = array( 'type' => 'error', 'message' => __( 'Withdrawal request not found.', 'woo-wallet' ) );

			if ( $request && 'pending' !== $request->status ) {
				$notice = array( 'type' => 'error', 'message' => __( 'This request has already been processed.', 'woo-wallet' ) );
			} elseif ( $request && 'paid' === $action ) {
				global $wpdb;
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::table(),
					array(
						'status'       => 'paid',
						'admin_note'   => $note,
						'date_updated' => current_time( 'mysql' ),
					),
					array( 'id' => $request->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				do_action( 'woo_wallet_withdrawal_paid', $request->id, $request->user_id );
				$notice = array(
					'type'    => 'success',
					/* translators: %d: withdrawal request id */
					'message' => sprintf( __( 'Withdrawal request #%d marked as paid.', 'woo-wallet' ), $request->id ),
				);
			} elseif ( $request && 'reject' === $action ) {
				$refund_amount = (float) $request->amount + (float) $request->charge;
				/* translators: %d: withdrawal request id */
				$credit_note = sprintf( __( 'Withdrawal request #%d rejected - funds returned', 'woo-wallet' ), $request->id );
				if ( $note ) {
					$credit_note .= ' (' . $note . ')';
				}
				$credit_id = woo_wallet()->wallet->credit( $request->user_id, $refund_amount, $credit_note, array( 'category' => 'withdrawal_refund' ) );

				global $wpdb;
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::table(),
					array(
						'status'       => 'rejected',
						'admin_note'   => $note,
						'date_updated' => current_time( 'mysql' ),
					),
					array( 'id' => $request->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				do_action( 'woo_wallet_withdrawal_rejected', $request->id, $request->user_id, $credit_id );
				$notice = array(
					'type'    => 'success',
					/* translators: %d: withdrawal request id */
					'message' => sprintf( __( 'Withdrawal request #%d rejected and funds returned to the customer wallet.', 'woo-wallet' ), $request->id ),
				);
			}

			set_transient( 'woo_wallet_withdrawal_admin_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=woo-wallet-withdrawals' ) );
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
