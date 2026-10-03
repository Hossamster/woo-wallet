<?php
/**
 * Staff permissions: who on the store's team may do what with wallets.
 *
 * Three tiers:
 *  - Administrator: everything, including settings.
 *  - Shop manager (anyone holding get_wallet_user_capability()): every
 *    operational action — adjust balances without a limit, process
 *    withdrawals, see bank details and receipts, export, manage support
 *    agents — but not settings.
 *  - Wallet support agent (ROLE): read-only access to wallets and
 *    withdrawals with bank details masked, may add notes, log a pending
 *    withdrawal for a customer, and give small goodwill credits within a
 *    per-agent limit set by a shop manager or administrator.
 *
 * Manager and administrator capabilities are granted dynamically (see
 * grant_capabilities()) rather than stored on the roles, so any existing
 * user or custom role that already holds the wallet capability keeps
 * working without a migration.
 *
 * @package StandaleneTech
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woo_Wallet_Staff' ) ) {

	/**
	 * Wallet staff permissions.
	 */
	class Woo_Wallet_Staff {

		const ROLE = 'wallet_support_agent';

		const CAP_VIEW                = 'woo_wallet_view';
		const CAP_ADJUST_BALANCE      = 'woo_wallet_adjust_balance';
		const CAP_GOODWILL_CREDIT     = 'woo_wallet_goodwill_credit';
		const CAP_VIEW_BANK_DETAILS   = 'woo_wallet_view_bank_details';
		const CAP_VIEW_RECEIPTS       = 'woo_wallet_view_receipts';
		const CAP_CREATE_WITHDRAWALS  = 'woo_wallet_create_withdrawals';
		const CAP_PROCESS_WITHDRAWALS = 'woo_wallet_process_withdrawals';
		const CAP_ADD_NOTES           = 'woo_wallet_add_withdrawal_notes';
		const CAP_EXPORT              = 'woo_wallet_export';
		const CAP_MANAGE_STAFF        = 'woo_wallet_manage_staff';
		const CAP_MANAGE_SETTINGS     = 'woo_wallet_manage_settings';
		const CAP_REQUEST_APPROVAL    = 'woo_wallet_request_approval';
		const CAP_APPROVE_REQUESTS    = 'woo_wallet_approve_requests';

		/**
		 * Option: the support agent levels (see get_levels()).
		 */
		const LEVELS_OPTION = 'woo_wallet_staff_levels';

		/**
		 * User meta: a support agent's level key.
		 */
		const META_LEVEL = '_woo_wallet_staff_level';

		/**
		 * The level of an agent who has none recorded — agents added before
		 * levels existed had exactly this level's permissions.
		 */
		const DEFAULT_LEVEL = 'level_2';

		/**
		 * User meta: the most a support agent may credit in one go.
		 */
		const META_CREDIT_LIMIT = '_woo_wallet_staff_credit_limit';

		/**
		 * User meta: the most a support agent may credit in total per day.
		 * Together with META_CREDIT_LIMIT, a personal override of the
		 * agent's level limits; absent means the level's limits apply.
		 */
		const META_DAILY_LIMIT = '_woo_wallet_staff_daily_limit';

		/**
		 * Ledger category for a support agent's limited credit — what the
		 * daily limit is counted against.
		 */
		const GOODWILL_CATEGORY = 'goodwill';

		/**
		 * Name of the hidden one-time token field on money-moving admin forms.
		 */
		const FORM_TOKEN_FIELD = 'woo_wallet_form_token';

		/**
		 * Hook up.
		 */
		public function __construct() {
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_capabilities' ), 10, 4 );
			add_action( 'init', array( __CLASS__, 'ensure_role' ) );
			add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'keep_admin_access' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
			add_action( 'admin_post_woo_wallet_staff_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_woo_wallet_staff_remove', array( $this, 'handle_remove' ) );
			add_action( 'admin_post_woo_wallet_staff_level', array( $this, 'handle_save_level' ) );
		}

		/* ---------------- capabilities ---------------- */

		/**
		 * Capabilities every support agent holds, whatever their level —
		 * stored on the role itself.
		 *
		 * @return string[]
		 */
		public static function support_capabilities() {
			return array(
				self::CAP_VIEW,
				self::CAP_REQUEST_APPROVAL,
			);
		}

		/**
		 * Capabilities the support agent role used to store and must not keep:
		 *  - creating a withdrawal reserves the amount from the customer's
		 *    wallet immediately, with no limit — an agent could freeze any
		 *    customer's balance that way; it now goes through an approval
		 *    request instead;
		 *  - notes and goodwill credit now come from the agent's level
		 *    (see level_capabilities()), and a capability stored on the role
		 *    would keep granting them whatever the level says.
		 *
		 * @return string[]
		 */
		public static function revoked_support_capabilities() {
			return array( self::CAP_CREATE_WITHDRAWALS, self::CAP_ADD_NOTES, self::CAP_GOODWILL_CREDIT );
		}

		/**
		 * The capabilities a level may switch on, with their labels. Anything
		 * that takes money out of a customer's wallet is deliberately not
		 * here: an agent asks for it through an approval request.
		 *
		 * @return string[]
		 */
		public static function level_capabilities() {
			return array(
				self::CAP_ADD_NOTES         => __( 'Add notes to withdrawal requests', 'woo-wallet' ),
				self::CAP_GOODWILL_CREDIT   => __( 'Give goodwill credit within the limits below', 'woo-wallet' ),
				self::CAP_VIEW_BANK_DETAILS => __( 'See full bank account numbers and IBANs', 'woo-wallet' ),
				self::CAP_VIEW_RECEIPTS     => __( 'Open transfer receipts', 'woo-wallet' ),
			);
		}

		/**
		 * The three levels a fresh install starts with.
		 *
		 * @return array
		 */
		public static function default_levels() {
			return array(
				'level_1' => array(
					'name'       => __( 'Level 1 — View and notes', 'woo-wallet' ),
					'caps'       => array( self::CAP_ADD_NOTES ),
					'per_credit' => 0.0,
					'daily'      => 0.0,
				),
				'level_2' => array(
					'name'       => __( 'Level 2 — Goodwill credit', 'woo-wallet' ),
					'caps'       => array( self::CAP_ADD_NOTES, self::CAP_GOODWILL_CREDIT ),
					'per_credit' => 0.0,
					'daily'      => 0.0,
				),
				'level_3' => array(
					'name'       => __( 'Level 3 — Senior', 'woo-wallet' ),
					'caps'       => array( self::CAP_ADD_NOTES, self::CAP_GOODWILL_CREDIT, self::CAP_VIEW_BANK_DETAILS, self::CAP_VIEW_RECEIPTS ),
					'per_credit' => 0.0,
					'daily'      => 0.0,
				),
			);
		}

		/**
		 * The support agent levels: name, capabilities and credit limits.
		 *
		 * @return array<string, array{name: string, caps: string[], per_credit: float, daily: float}>
		 */
		public static function get_levels() {
			$levels = self::default_levels();
			$saved  = get_option( self::LEVELS_OPTION, array() );
			foreach ( $levels as $key => $level ) {
				if ( empty( $saved[ $key ] ) || ! is_array( $saved[ $key ] ) ) {
					continue;
				}
				$levels[ $key ] = array(
					'name'       => isset( $saved[ $key ]['name'] ) && '' !== $saved[ $key ]['name'] ? (string) $saved[ $key ]['name'] : $level['name'],
					'caps'       => array_values( array_intersect( (array) ( $saved[ $key ]['caps'] ?? array() ), array_keys( self::level_capabilities() ) ) ),
					'per_credit' => max( 0.0, (float) ( $saved[ $key ]['per_credit'] ?? 0 ) ),
					'daily'      => max( 0.0, (float) ( $saved[ $key ]['daily'] ?? 0 ) ),
				);
			}
			return $levels;
		}

		/**
		 * Change a level.
		 *
		 * @param string   $key        Level key.
		 * @param string   $name       Name.
		 * @param string[] $caps       Capabilities from level_capabilities().
		 * @param float    $per_credit Most an agent may credit at once.
		 * @param float    $daily      Most an agent may credit per day.
		 * @return true|WP_Error
		 */
		public static function save_level( $key, $name, array $caps, $per_credit, $daily ) {
			$levels = self::get_levels();
			if ( ! isset( $levels[ $key ] ) ) {
				return new WP_Error( 'woo_wallet_staff_level', __( 'Unknown level.', 'woo-wallet' ) );
			}
			$per_credit = max( 0.0, (float) $per_credit );
			$daily      = max( 0.0, (float) $daily );
			if ( $per_credit > $daily ) {
				return new WP_Error( 'woo_wallet_staff_bad_limits', __( 'The per-credit limit cannot be higher than the daily limit.', 'woo-wallet' ) );
			}
			$levels[ $key ] = array(
				'name'       => '' !== trim( (string) $name ) ? trim( (string) $name ) : $levels[ $key ]['name'],
				'caps'       => array_values( array_intersect( $caps, array_keys( self::level_capabilities() ) ) ),
				'per_credit' => $per_credit,
				'daily'      => $daily,
			);
			update_option( self::LEVELS_OPTION, $levels, false );
			do_action( 'woo_wallet_staff_level_saved', $key, $levels[ $key ], get_current_user_id() );
			return true;
		}

		/**
		 * A support agent's level key.
		 *
		 * @param int $staff_id Staff user id.
		 * @return string
		 */
		public static function get_agent_level( $staff_id ) {
			$level  = (string) get_user_meta( $staff_id, self::META_LEVEL, true );
			$levels = self::get_levels();
			return isset( $levels[ $level ] ) ? $level : self::DEFAULT_LEVEL;
		}

		/**
		 * Capabilities a shop manager holds — everything operational.
		 *
		 * @return string[]
		 */
		public static function manager_capabilities() {
			return array(
				self::CAP_VIEW,
				self::CAP_ADD_NOTES,
				self::CAP_CREATE_WITHDRAWALS,
				self::CAP_ADJUST_BALANCE,
				self::CAP_VIEW_BANK_DETAILS,
				self::CAP_VIEW_RECEIPTS,
				self::CAP_PROCESS_WITHDRAWALS,
				self::CAP_EXPORT,
				self::CAP_MANAGE_STAFF,
				self::CAP_APPROVE_REQUESTS,
			);
		}

		/**
		 * Grant the manager tier to whoever holds the wallet capability, and
		 * settings on top to whoever can also manage the site's options. A
		 * support agent gets the capabilities their level switches on; the
		 * ones every agent has are stored on the role.
		 *
		 * @param bool[]   $allcaps Capabilities the user has.
		 * @param string[] $caps    Primitive capabilities being checked.
		 * @param array    $args    Check arguments.
		 * @param WP_User  $user    The user.
		 * @return bool[]
		 */
		public static function grant_capabilities( $allcaps, $caps, $args, $user ) {
			$wallet_caps = array_merge( self::manager_capabilities(), array( self::CAP_MANAGE_SETTINGS, self::CAP_GOODWILL_CREDIT ) );
			if ( ! array_intersect( (array) $caps, $wallet_caps ) ) {
				return $allcaps;
			}
			if ( $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) ) {
				$levels = self::get_levels();
				foreach ( $levels[ self::get_agent_level( $user->ID ) ]['caps'] as $cap ) {
					$allcaps[ $cap ] = true;
				}
			}
			if ( empty( $allcaps[ get_wallet_user_capability() ] ) ) {
				return $allcaps;
			}
			$granted = self::manager_capabilities();
			if ( ! empty( $allcaps['manage_options'] ) ) {
				$granted[] = self::CAP_MANAGE_SETTINGS;
			}
			$granted = (array) apply_filters( 'woo_wallet_staff_capabilities', $granted, $user );
			foreach ( $granted as $cap ) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		}

		/**
		 * Create the support agent role if it does not exist yet, and strip
		 * any capability it is no longer meant to have — a role's
		 * capabilities are stored in the database when it is created, so
		 * changing support_capabilities() alone never reaches an existing
		 * site. Runs on `init` so an in-place update gets it without
		 * re-activation.
		 */
		public static function ensure_role() {
			$role = get_role( self::ROLE );
			if ( $role ) {
				foreach ( self::revoked_support_capabilities() as $cap ) {
					if ( $role->has_cap( $cap ) ) {
						$role->remove_cap( $cap );
					}
				}
				foreach ( self::support_capabilities() as $cap ) {
					if ( ! $role->has_cap( $cap ) ) {
						$role->add_cap( $cap );
					}
				}
				return;
			}
			$caps = array(
				'read'                 => true,
				// WooCommerce keeps users without this out of wp-admin.
				'view_admin_dashboard' => true,
			);
			foreach ( self::support_capabilities() as $cap ) {
				$caps[ $cap ] = true;
			}
			add_role( self::ROLE, __( 'Wallet Support Agent', 'woo-wallet' ), $caps );
		}

		/**
		 * WooCommerce hides the admin bar from (and can lock wp-admin for)
		 * anyone without `edit_posts` or `manage_woocommerce`, which would
		 * leave a support agent looking like an ordinary customer with no
		 * way into the wallet screens.
		 *
		 * @param bool $disable Whether WooCommerce should apply that restriction.
		 * @return bool
		 */
		public static function keep_admin_access( $disable ) {
			return current_user_can( self::CAP_VIEW ) ? false : $disable;
		}

		/* ---------------- limits ---------------- */

		/**
		 * A support agent's credit limits: their personal override if one is
		 * set, otherwise their level's.
		 *
		 * @param int $staff_id Staff user id.
		 * @return array {per_credit:float, daily:float}
		 */
		public static function get_limits( $staff_id ) {
			if ( self::has_limit_override( $staff_id ) ) {
				return array(
					'per_credit' => max( 0.0, (float) get_user_meta( $staff_id, self::META_CREDIT_LIMIT, true ) ),
					'daily'      => max( 0.0, (float) get_user_meta( $staff_id, self::META_DAILY_LIMIT, true ) ),
				);
			}
			$levels = self::get_levels();
			$level  = $levels[ self::get_agent_level( $staff_id ) ];
			return array(
				'per_credit' => $level['per_credit'],
				'daily'      => $level['daily'],
			);
		}

		/**
		 * Whether an agent has personal limits instead of their level's.
		 *
		 * @param int $staff_id Staff user id.
		 * @return bool
		 */
		public static function has_limit_override( $staff_id ) {
			return metadata_exists( 'user', $staff_id, self::META_CREDIT_LIMIT ) && metadata_exists( 'user', $staff_id, self::META_DAILY_LIMIT );
		}

		/**
		 * Drop an agent's personal limits, so their level's apply.
		 *
		 * @param int $staff_id Staff user id.
		 */
		public static function clear_limits( $staff_id ) {
			delete_user_meta( $staff_id, self::META_CREDIT_LIMIT );
			delete_user_meta( $staff_id, self::META_DAILY_LIMIT );
		}

		/**
		 * Give a support agent personal credit limits, overriding their level's.
		 *
		 * @param int   $staff_id   Staff user id.
		 * @param float $per_credit Most they may credit in one go.
		 * @param float $daily      Most they may credit per day.
		 */
		public static function set_limits( $staff_id, $per_credit, $daily ) {
			update_user_meta( $staff_id, self::META_CREDIT_LIMIT, max( 0.0, (float) $per_credit ) );
			update_user_meta( $staff_id, self::META_DAILY_LIMIT, max( 0.0, (float) $daily ) );
		}

		/**
		 * Total goodwill credit a support agent has given today (site time).
		 *
		 * @param int $staff_id Staff user id.
		 * @return float
		 */
		public static function credited_today( $staff_id ) {
			global $wpdb;
			return (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COALESCE( SUM( amount ), 0 ) FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE created_by = %d AND type = 'credit' AND category = %s AND deleted = 0 AND date >= %s",
					$staff_id,
					self::GOODWILL_CATEGORY,
					current_time( 'Y-m-d' ) . ' 00:00:00'
				)
			);
		}

		/**
		 * Whether a staff member's balance adjustments are limited (a
		 * support agent) rather than unrestricted (a manager).
		 *
		 * @param int $staff_id Staff user id; defaults to the current user.
		 * @return bool
		 */
		public static function is_limited( $staff_id = 0 ) {
			$staff_id = $staff_id ? (int) $staff_id : get_current_user_id();
			return ! user_can( $staff_id, self::CAP_ADJUST_BALANCE ) && user_can( $staff_id, self::CAP_GOODWILL_CREDIT );
		}

		/**
		 * Whether a staff member may make any balance adjustment at all.
		 *
		 * @param int $staff_id Staff user id; defaults to the current user.
		 * @return bool
		 */
		public static function can_adjust( $staff_id = 0 ) {
			$staff_id = $staff_id ? (int) $staff_id : get_current_user_id();
			return user_can( $staff_id, self::CAP_ADJUST_BALANCE ) || user_can( $staff_id, self::CAP_GOODWILL_CREDIT );
		}

		/**
		 * Decide whether a staff member may make this balance adjustment.
		 *
		 * @param string $type        'credit' or 'debit'.
		 * @param float  $amount      Amount.
		 * @param int    $customer_id Wallet being adjusted.
		 * @param int    $staff_id    Staff user id; defaults to the current user.
		 * @return true|WP_Error
		 */
		public static function authorize_adjustment( $type, $amount, $customer_id, $staff_id = 0 ) {
			$staff_id = $staff_id ? (int) $staff_id : get_current_user_id();
			$amount   = (float) $amount;

			if ( user_can( $staff_id, self::CAP_ADJUST_BALANCE ) ) {
				return true;
			}
			if ( ! user_can( $staff_id, self::CAP_GOODWILL_CREDIT ) ) {
				return new WP_Error( 'woo_wallet_staff_forbidden', __( 'You do not have permission to adjust wallet balances.', 'woo-wallet' ) );
			}
			if ( 'credit' !== $type ) {
				return new WP_Error( 'woo_wallet_staff_forbidden', __( 'You may only add credit to a wallet, not debit it. Send a debit as a request for approval instead.', 'woo-wallet' ) );
			}
			if ( (int) $customer_id === $staff_id ) {
				return new WP_Error( 'woo_wallet_staff_self_credit', __( 'You cannot credit your own wallet.', 'woo-wallet' ) );
			}
			if ( $amount <= 0 ) {
				return new WP_Error( 'woo_wallet_staff_invalid_amount', __( 'Enter an amount greater than zero.', 'woo-wallet' ) );
			}

			$limits = self::get_limits( $staff_id );
			if ( $limits['per_credit'] <= 0 || $limits['daily'] <= 0 ) {
				return new WP_Error( 'woo_wallet_staff_no_limit', __( 'No credit limit has been set for your account yet. Ask a shop manager to set one.', 'woo-wallet' ) );
			}
			if ( $amount > $limits['per_credit'] ) {
				return new WP_Error(
					'woo_wallet_staff_over_limit',
					/* translators: %s: the agent's per-credit limit */
					sprintf( __( 'This is more than you may credit at once (your limit is %s). Send it as a request for approval instead.', 'woo-wallet' ), wp_strip_all_tags( wc_price( $limits['per_credit'] ) ) )
				);
			}
			$remaining = $limits['daily'] - self::credited_today( $staff_id );
			if ( $amount > $remaining + 0.00001 ) {
				return new WP_Error(
					'woo_wallet_staff_over_daily_limit',
					/* translators: %s: what is left of the agent's daily limit */
					sprintf( __( 'This would exceed your daily credit limit (%s left today). Send it as a request for approval instead.', 'woo-wallet' ), wp_strip_all_tags( wc_price( max( 0, $remaining ) ) ) )
				);
			}
			return true;
		}

		/**
		 * Make a balance adjustment on behalf of a staff member, enforcing
		 * their permissions. Every staff-initiated credit or debit goes
		 * through here.
		 *
		 * A support agent's credit is recorded under GOODWILL_CATEGORY and
		 * runs under a per-agent lock, so two requests sent at the same
		 * instant cannot both pass the daily-limit check.
		 *
		 * @param string $type        'credit' or 'debit'.
		 * @param int    $customer_id Wallet being adjusted.
		 * @param float  $amount      Amount.
		 * @param string $details     Description.
		 * @param array  $args        Extra ledger args (e.g. category).
		 * @return int|WP_Error Transaction id, or why it was refused.
		 */
		public static function adjust( $type, $customer_id, $amount, $details = '', $args = array() ) {
			global $wpdb;
			$staff_id = get_current_user_id();

			if ( ! self::is_limited( $staff_id ) ) {
				$allowed = self::authorize_adjustment( $type, $amount, $customer_id, $staff_id );
				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}
				$transaction_id = 'debit' === $type
					? woo_wallet()->wallet->debit( $customer_id, $amount, $details, $args )
					: woo_wallet()->wallet->credit( $customer_id, $amount, $details, $args );
				return $transaction_id ? (int) $transaction_id : new WP_Error( 'woo_wallet_staff_adjust_failed', __( 'The wallet could not be updated. Please try again.', 'woo-wallet' ) );
			}

			$lock = 'woo_wallet_staff_' . $staff_id;
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return new WP_Error( 'woo_wallet_staff_busy', __( 'Another credit is still being processed. Please try again.', 'woo-wallet' ) );
			}
			try {
				$allowed = self::authorize_adjustment( $type, $amount, $customer_id, $staff_id );
				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}
				$args['category'] = self::GOODWILL_CATEGORY;
				$transaction_id   = woo_wallet()->wallet->credit( $customer_id, $amount, $details, $args );
				return $transaction_id ? (int) $transaction_id : new WP_Error( 'woo_wallet_staff_adjust_failed', __( 'The wallet could not be updated. Please try again.', 'woo-wallet' ) );
			} finally {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		/* ---------------- one-time form tokens ---------------- */

		/**
		 * Print a hidden one-time token for a money-moving admin form. A
		 * nonce stays valid for hours and so does nothing to stop the same
		 * form being submitted twice (a double click, or a browser re-sending
		 * the POST on refresh); this token can only ever be used once.
		 */
		public static function form_token_field() {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( self::FORM_TOKEN_FIELD ), esc_attr( wp_generate_uuid4() ) );
		}

		/**
		 * Claim a form's one-time token. Atomic: of any number of identical
		 * submissions arriving together, exactly one gets true.
		 *
		 * @param string $token The submitted token.
		 * @return bool True the first time a token is seen; false for a repeat or a missing/malformed token.
		 */
		public static function claim_form_token( $token ) {
			$token = is_string( $token ) ? $token : '';
			if ( ! wp_is_uuid( $token, 4 ) ) {
				return false;
			}
			$key = 'wwform_' . md5( get_current_user_id() . '|' . $token );
			if ( ! add_option( '_transient_' . $key, time(), '', 'no' ) ) {
				return false;
			}
			// Paired timeout row, so WordPress's own expired-transient cleanup removes it.
			add_option( '_transient_timeout_' . $key, time() + DAY_IN_SECONDS, '', 'no' );
			return true;
		}

		/**
		 * Claim the one-time token submitted with the current request.
		 *
		 * @return bool
		 */
		public static function claim_submitted_form_token() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify their own nonce first.
			return self::claim_form_token( isset( $_POST[ self::FORM_TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FORM_TOKEN_FIELD ] ) ) : '' );
		}

		/* ---------------- display helpers ---------------- */

		/**
		 * A bank detail as the current user may see it: in full for staff
		 * with CAP_VIEW_BANK_DETAILS, otherwise only its last four characters.
		 *
		 * @param string $value Account number or IBAN.
		 * @return string
		 */
		public static function bank_detail( $value ) {
			$value = (string) $value;
			if ( '' === $value || current_user_can( self::CAP_VIEW_BANK_DETAILS ) ) {
				return $value;
			}
			$length = strlen( $value );
			return $length <= 4 ? str_repeat( '•', $length ) : str_repeat( '•', $length - 4 ) . substr( $value, -4 );
		}

		/* ---------------- staff screen ---------------- */

		/**
		 * Support agents.
		 *
		 * @return WP_User[]
		 */
		public static function get_agents() {
			return get_users(
				array(
					'role'    => self::ROLE,
					'orderby' => 'display_name',
				)
			);
		}

		/**
		 * Add the "Staff" submenu.
		 */
		public function admin_menu() {
			add_submenu_page( 'woo-wallet', __( 'Wallet Staff', 'woo-wallet' ), __( 'Staff', 'woo-wallet' ), self::CAP_MANAGE_STAFF, 'woo-wallet-staff', array( $this, 'render_page' ) );
		}

		/**
		 * Render the staff screen: agents, levels, and (administrators only)
		 * who gets approval emails.
		 */
		public function render_page() {
			if ( ! current_user_can( self::CAP_MANAGE_STAFF ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			$notice_key = 'woo_wallet_staff_notice_' . get_current_user_id();
			$notice     = get_transient( $notice_key );
			if ( $notice ) {
				delete_transient( $notice_key );
			}
			$tabs = array(
				'agents' => __( 'Support agents', 'woo-wallet' ),
				'levels' => __( 'Levels', 'woo-wallet' ),
			);
			if ( current_user_can( self::CAP_MANAGE_SETTINGS ) ) {
				$tabs['emails'] = __( 'Approval emails', 'woo-wallet' );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'agents';
			$tab = isset( $tabs[ $tab ] ) ? $tab : 'agents';
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Wallet Staff', 'woo-wallet' ); ?></h1>
				<?php if ( $notice ) : ?>
					<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
				<?php endif; ?>
				<?php if ( current_user_can( self::CAP_MANAGE_SETTINGS ) && Woo_Wallet_Approvals::email_recipients()['fallback'] ) : ?>
					<div class="notice notice-warning"><p><?php esc_html_e( 'No one chosen under Approval emails is currently receiving them, so new requests are being emailed to every administrator instead.', 'woo-wallet' ); ?></p></div>
				<?php endif; ?>
				<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'woo-wallet-staff', 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<?php
				if ( 'levels' === $tab ) {
					$this->render_levels_tab();
				} elseif ( 'emails' === $tab ) {
					$this->render_emails_tab();
				} else {
					$this->render_agents_tab();
				}
				?>
			</div>
			<?php
		}

		/**
		 * Level picker.
		 *
		 * @param string $name     Field name.
		 * @param string $selected Selected key.
		 * @param string $form     Optional form id the field belongs to.
		 */
		private function level_select( $name, $selected, $form = '' ) {
			?>
			<select name="<?php echo esc_attr( $name ); ?>" <?php echo $form ? 'form="' . esc_attr( $form ) . '"' : ''; ?>>
				<?php foreach ( self::get_levels() as $key => $level ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( $level['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
		}

		/**
		 * The agents tab.
		 */
		private function render_agents_tab() {
			$agents = self::get_agents();
			$levels = self::get_levels();
			?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Every agent can view wallets, transactions and withdrawal requests, and send requests for approval. Their level decides what else they can do. Leave the personal limits empty to use the level\'s limits. Shop managers and administrators are not listed: they can do everything without a limit.', 'woo-wallet' ); ?>
			</p>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Agent', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Level', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Personal max per credit', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Personal max per day', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Credited today', 'woo-wallet' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $agents ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No support agents yet.', 'woo-wallet' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $agents as $agent ) : ?>
						<?php
						$form_id  = 'woo-wallet-staff-' . $agent->ID;
						$override = self::has_limit_override( $agent->ID );
						$limits   = self::get_limits( $agent->ID );
						$level    = $levels[ self::get_agent_level( $agent->ID ) ];
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $agent->display_name ); ?></strong><br />
								<span class="description"><?php echo esc_html( $agent->user_email ); ?></span>
								<?php if ( user_can( $agent, self::CAP_ADJUST_BALANCE ) ) : ?>
									<br /><span class="description"><?php esc_html_e( 'Also a shop manager or administrator — the level does not limit them.', 'woo-wallet' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php $this->level_select( 'level', self::get_agent_level( $agent->ID ), $form_id ); ?></td>
							<td><input form="<?php echo esc_attr( $form_id ); ?>" type="number" name="per_credit" min="0" step="0.01" value="<?php echo $override ? esc_attr( $limits['per_credit'] ) : ''; ?>" placeholder="<?php echo esc_attr( $level['per_credit'] ); ?>" style="width:110px;" /></td>
							<td><input form="<?php echo esc_attr( $form_id ); ?>" type="number" name="daily" min="0" step="0.01" value="<?php echo $override ? esc_attr( $limits['daily'] ) : ''; ?>" placeholder="<?php echo esc_attr( $level['daily'] ); ?>" style="width:110px;" /></td>
							<td><?php echo wp_kses_post( wc_price( self::credited_today( $agent->ID ) ) ); ?></td>
							<td>
								<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="woo_wallet_staff_save" />
									<input type="hidden" name="staff_id" value="<?php echo esc_attr( $agent->ID ); ?>" />
									<?php wp_nonce_field( 'woo_wallet_staff_save' ); ?>
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'woo-wallet' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<input type="hidden" name="action" value="woo_wallet_staff_remove" />
									<input type="hidden" name="staff_id" value="<?php echo esc_attr( $agent->ID ); ?>" />
									<?php wp_nonce_field( 'woo_wallet_staff_remove' ); ?>
									<button type="submit" class="button-link" style="color:#b32d2e;margin-left:8px;" onclick="return confirm('<?php echo esc_js( __( 'Remove support agent access from this user?', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Remove', 'woo-wallet' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Add a support agent', 'woo-wallet' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="woo_wallet_staff_save" />
				<?php wp_nonce_field( 'woo_wallet_staff_save' ); ?>
				<table class="form-table" role="presentation" style="max-width:820px;">
					<tr>
						<th><label for="ww-staff-user"><?php esc_html_e( 'Email or username', 'woo-wallet' ); ?></label></th>
						<td>
							<input type="text" id="ww-staff-user" name="staff_user" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'An existing user account. They keep their current role and gain support agent access on top of it.', 'woo-wallet' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Level', 'woo-wallet' ); ?></label></th>
						<td><?php $this->level_select( 'level', self::DEFAULT_LEVEL ); ?></td>
					</tr>
					<tr>
						<th><label for="ww-staff-per-credit"><?php esc_html_e( 'Personal limits (optional)', 'woo-wallet' ); ?></label></th>
						<td>
							<input type="number" id="ww-staff-per-credit" name="per_credit" min="0" step="0.01" placeholder="<?php esc_attr_e( 'Max per credit', 'woo-wallet' ); ?>" />
							<input type="number" name="daily" min="0" step="0.01" placeholder="<?php esc_attr_e( 'Max per day', 'woo-wallet' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty to use the level\'s limits.', 'woo-wallet' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add agent', 'woo-wallet' ) ); ?>
			</form>
			<?php
		}

		/**
		 * The levels tab.
		 */
		private function render_levels_tab() {
			?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Debits, withdrawals and credit above these limits are never done by an agent directly: they send a request for approval instead.', 'woo-wallet' ); ?>
			</p>
			<?php foreach ( self::get_levels() as $key => $level ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card" style="max-width:820px;padding:12px 20px;">
					<input type="hidden" name="action" value="woo_wallet_staff_level" />
					<input type="hidden" name="level" value="<?php echo esc_attr( $key ); ?>" />
					<?php wp_nonce_field( 'woo_wallet_staff_level' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="ww-level-name-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Name', 'woo-wallet' ); ?></label></th>
							<td><input type="text" id="ww-level-name-<?php echo esc_attr( $key ); ?>" name="name" class="regular-text" value="<?php echo esc_attr( $level['name'] ); ?>" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Can', 'woo-wallet' ); ?></th>
							<td>
								<?php foreach ( self::level_capabilities() as $cap => $label ) : ?>
									<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="caps[]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( in_array( $cap, $level['caps'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Goodwill credit limits', 'woo-wallet' ); ?></th>
							<td>
								<label><?php esc_html_e( 'Max per credit', 'woo-wallet' ); ?> <input type="number" name="per_credit" min="0" step="0.01" value="<?php echo esc_attr( $level['per_credit'] ); ?>" style="width:110px;" /></label>
								<label style="margin-left:12px;"><?php esc_html_e( 'Max per day', 'woo-wallet' ); ?> <input type="number" name="daily" min="0" step="0.01" value="<?php echo esc_attr( $level['daily'] ); ?>" style="width:110px;" /></label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save level', 'woo-wallet' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endforeach; ?>
			<?php
		}

		/**
		 * The approval-emails tab (administrators only).
		 */
		private function render_emails_tab() {
			$chosen = Woo_Wallet_Approvals::chosen_recipient_ids();
			?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Who gets an email when a support agent sends a request for approval. Anyone chosen can pause their own emails from the Approvals screen, for example while on leave. Everyone listed here can approve requests whether or not they get the email.', 'woo-wallet' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="woo_wallet_approval_recipients" />
				<?php wp_nonce_field( 'woo_wallet_approval_recipients' ); ?>
				<table class="widefat striped" style="max-width:820px;">
					<tbody>
						<?php foreach ( Woo_Wallet_Approvals::approvers() as $approver ) : ?>
							<tr>
								<td>
									<label>
										<input type="checkbox" name="recipients[]" value="<?php echo esc_attr( $approver->ID ); ?>" <?php checked( in_array( (int) $approver->ID, $chosen, true ) ); ?> />
										<strong><?php echo esc_html( $approver->display_name ); ?></strong>
										<span class="description"><?php echo esc_html( $approver->user_email ); ?></span>
									</label>
								</td>
								<td>
									<?php echo user_can( $approver, 'manage_options' ) ? esc_html__( 'Administrator', 'woo-wallet' ) : esc_html__( 'Shop manager', 'woo-wallet' ); ?>
									<?php if ( Woo_Wallet_Approvals::is_paused( $approver->ID ) ) : ?>
										&middot; <em><?php esc_html_e( 'paused their emails', 'woo-wallet' ); ?></em>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save recipients', 'woo-wallet' ) ); ?>
			</form>
			<?php
		}

		/**
		 * Add a support agent, or change an existing agent's level and limits.
		 *
		 * @param int|string $user_ref User id, email or login.
		 * @param string     $level    Level key.
		 * @param array|null $limits   Personal {per_credit, daily}, or null to use the level's.
		 * @return WP_User|WP_Error
		 */
		public static function save_agent( $user_ref, $level, $limits = null ) {
			$user = is_numeric( $user_ref ) ? get_userdata( (int) $user_ref ) : get_user_by( 'email', (string) $user_ref );
			if ( ! $user && ! is_numeric( $user_ref ) ) {
				$user = get_user_by( 'login', (string) $user_ref );
			}
			if ( ! $user ) {
				return new WP_Error( 'woo_wallet_staff_not_found', __( 'No user found with that email or username.', 'woo-wallet' ) );
			}
			if ( ! isset( self::get_levels()[ $level ] ) ) {
				return new WP_Error( 'woo_wallet_staff_level', __( 'Unknown level.', 'woo-wallet' ) );
			}
			if ( is_array( $limits ) ) {
				$per_credit = max( 0.0, (float) $limits['per_credit'] );
				$daily      = max( 0.0, (float) $limits['daily'] );
				if ( $per_credit > $daily ) {
					return new WP_Error( 'woo_wallet_staff_bad_limits', __( 'The per-credit limit cannot be higher than the daily limit.', 'woo-wallet' ) );
				}
			}
			self::ensure_role();
			if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
				if ( user_can( $user, self::CAP_ADJUST_BALANCE ) ) {
					return new WP_Error( 'woo_wallet_staff_already_manager', __( 'This user is already a shop manager or administrator and can do everything without a limit.', 'woo-wallet' ) );
				}
				$user->add_role( self::ROLE );
			}
			update_user_meta( $user->ID, self::META_LEVEL, $level );
			if ( is_array( $limits ) ) {
				self::set_limits( $user->ID, $per_credit, $daily );
			} else {
				self::clear_limits( $user->ID );
			}
			do_action( 'woo_wallet_staff_agent_saved', $user->ID, $level, $limits, get_current_user_id() );
			return $user;
		}

		/**
		 * Take support agent access away from a user.
		 *
		 * @param int $staff_id User id.
		 */
		public static function remove_agent( $staff_id ) {
			$user = get_userdata( (int) $staff_id );
			if ( ! $user ) {
				return;
			}
			$user->remove_role( self::ROLE );
			self::clear_limits( $user->ID );
			delete_user_meta( $user->ID, self::META_LEVEL );
			do_action( 'woo_wallet_staff_agent_removed', $user->ID, get_current_user_id() );
		}

		/**
		 * Handle the add/update agent form.
		 */
		public function handle_save() {
			if ( ! current_user_can( self::CAP_MANAGE_STAFF ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_staff_save' );

			$user_ref   = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : ( isset( $_POST['staff_user'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_user'] ) ) : '' );
			$per_credit = isset( $_POST['per_credit'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['per_credit'] ) ) ) : '';
			$daily      = isset( $_POST['daily'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['daily'] ) ) ) : '';
			$limits     = ( '' === $per_credit && '' === $daily ) ? null : array(
				'per_credit' => (float) $per_credit,
				'daily'      => (float) $daily,
			);
			$result     = self::save_agent(
				$user_ref,
				isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : self::DEFAULT_LEVEL,
				$limits
			);
			$this->redirect_with_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				/* translators: %s: agent display name */
				is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Saved %s.', 'woo-wallet' ), $result->display_name )
			);
		}

		/**
		 * Handle the remove button.
		 */
		public function handle_remove() {
			if ( ! current_user_can( self::CAP_MANAGE_STAFF ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_staff_remove' );
			self::remove_agent( isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0 );
			$this->redirect_with_notice( 'success', __( 'Support agent access removed.', 'woo-wallet' ) );
		}

		/**
		 * Handle a level form.
		 */
		public function handle_save_level() {
			if ( ! current_user_can( self::CAP_MANAGE_STAFF ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_staff_level' );
			$result = self::save_level(
				isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '',
				isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				isset( $_POST['caps'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['caps'] ) ) : array(),
				isset( $_POST['per_credit'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['per_credit'] ) ) : 0,
				isset( $_POST['daily'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['daily'] ) ) : 0
			);
			$this->redirect_with_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'Level saved.', 'woo-wallet' ),
				'levels'
			);
		}

		/**
		 * Store a one-shot notice and go back to the staff screen.
		 *
		 * @param string $type    'success' or 'error'.
		 * @param string $message Message.
		 * @param string $tab     Tab to return to.
		 */
		private function redirect_with_notice( $type, $message, $tab = 'agents' ) {
			set_transient(
				'woo_wallet_staff_notice_' . get_current_user_id(),
				array(
					'type'    => $type,
					'message' => $message,
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=woo-wallet-staff&tab=' . $tab ) );
			exit();
		}
	}
}
