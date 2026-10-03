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

		/**
		 * Reviews and pays out withdrawals — nothing else.
		 */
		const ACCOUNTANT_ROLE = 'wallet_accountant';

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
		const CAP_DELETE_LOGS         = 'woo_wallet_delete_logs';
		const CAP_MANAGE_CASHBACK     = 'woo_wallet_manage_cashback';

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
		 * Option: manual adjustments above this total need the amount typed a
		 * second time. 0 turns the check off.
		 */
		const LARGE_THRESHOLD_OPTION = 'woo_wallet_large_adjustment_threshold';

		/**
		 * Request field carrying the re-typed amount.
		 */
		const CONFIRM_FIELD = 'confirm_amount';

		/**
		 * Hook up.
		 */
		public function __construct() {
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_capabilities' ), 10, 4 );
			add_action( 'init', array( __CLASS__, 'ensure_role' ) );
			add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'keep_admin_access' ) );
			foreach ( array( 'add', 'update', 'delete' ) as $op ) {
				add_filter( "{$op}_post_metadata", array( __CLASS__, 'guard_product_cashback' ), 10, 4 );
				add_filter( "{$op}_term_metadata", array( __CLASS__, 'guard_category_cashback' ), 10, 4 );
			}
			// WooCommerce's product objects (and so its REST API and CSV
			// import) write meta by meta id, which the filters above never see.
			foreach ( array( 'post', 'term' ) as $meta_type ) {
				add_filter( "update_{$meta_type}_metadata_by_mid", array( __CLASS__, 'guard_cashback_by_mid' ), 10, 4 );
				add_filter( "delete_{$meta_type}_metadata_by_mid", array( __CLASS__, 'guard_cashback_by_mid' ), 10, 2 );
			}
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
			add_action( 'admin_post_woo_wallet_staff_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_woo_wallet_staff_remove', array( $this, 'handle_remove' ) );
			add_action( 'admin_post_woo_wallet_staff_level', array( $this, 'handle_save_level' ) );
			add_action( 'admin_post_woo_wallet_send_digest', array( $this, 'handle_send_digest' ) );
			add_action( 'admin_post_woo_wallet_accountant_save', array( $this, 'handle_save_accountant' ) );
			add_action( 'admin_post_woo_wallet_safeguards', array( $this, 'handle_save_safeguards' ) );
			add_action( 'admin_post_woo_wallet_reencrypt', array( $this, 'handle_reencrypt' ) );
			add_action( 'admin_post_woo_wallet_acknowledge_lost_key', array( $this, 'handle_acknowledge_lost_key' ) );
			add_filter( 'woo_wallet_encrypt_bank_details', array( 'Woo_Wallet_Security', 'bank_details_encryption_enabled' ), 5 );
			add_action( 'admin_init', array( 'Woo_Wallet_Security', 'ensure_canaries' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_key_problem_notice' ) );
			add_action( 'admin_post_woo_wallet_accountant_remove', array( $this, 'handle_remove_accountant' ) );
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
		 * Capabilities an accountant holds: review withdrawals with full bank
		 * details and receipts, mark them paid or reject them, add notes and
		 * export. No balance adjustments, no requests, no settings — and,
		 * not holding manage_woocommerce, no access to products or orders.
		 *
		 * @return string[]
		 */
		public static function accountant_capabilities() {
			return array(
				self::CAP_VIEW,
				self::CAP_VIEW_BANK_DETAILS,
				self::CAP_VIEW_RECEIPTS,
				self::CAP_PROCESS_WITHDRAWALS,
				self::CAP_ADD_NOTES,
				self::CAP_EXPORT,
			);
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
		 * Capabilities only an administrator holds on top of the manager tier:
		 * settings, deleting or rewriting transaction history (which would
		 * erase the evidence of a manual credit), and cashback rules.
		 *
		 * @return string[]
		 */
		public static function administrator_capabilities() {
			return array( self::CAP_MANAGE_SETTINGS, self::CAP_DELETE_LOGS, self::CAP_MANAGE_CASHBACK );
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
			$wallet_caps = array_merge( self::manager_capabilities(), self::administrator_capabilities(), array( self::CAP_GOODWILL_CREDIT ) );
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
				$granted = array_merge( $granted, self::administrator_capabilities() );
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
			if ( ! get_role( self::ACCOUNTANT_ROLE ) ) {
				$caps = array(
					'read'                 => true,
					'view_admin_dashboard' => true,
				);
				foreach ( self::accountant_capabilities() as $cap ) {
					$caps[ $cap ] = true;
				}
				add_role( self::ACCOUNTANT_ROLE, __( 'Wallet Accountant', 'woo-wallet' ), $caps );
			}

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

		/* ---------------- cashback rules ---------------- */

		/**
		 * Product, variation and category cashback meta keys.
		 */
		const PRODUCT_CASHBACK_KEYS  = array( '_cashback_type', '_cashback_amount' );
		const CATEGORY_CASHBACK_KEYS = array( '_woo_cashback_type', '_woo_cashback_amount' );

		/**
		 * Whether the current user may change cashback rules. Checked at the
		 * metadata layer, so it holds however the change arrives — the
		 * product screen, a variation, a category, a CSV import, the REST API.
		 * Code running with no logged-in user (cron, CLI, upgrades) is not
		 * restricted.
		 *
		 * @param int $object_id Product or term id.
		 * @return bool
		 */
		public static function can_change_cashback( $object_id = 0 ) {
			$user_id = get_current_user_id();
			$allowed = ! $user_id || user_can( $user_id, self::CAP_MANAGE_CASHBACK );
			// A marketplace vendor sets cashback on their own products.
			if ( ! $allowed && $object_id && function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( $user_id ) && (int) get_post_field( 'post_author', $object_id ) === $user_id ) {
				$allowed = true;
			}
			return (bool) apply_filters( 'woo_wallet_can_change_cashback', $allowed, $user_id, $object_id );
		}

		/**
		 * Extra attributes for a cashback form field: read-only for anyone
		 * who may not change cashback, so they can still see the rule.
		 *
		 * @param array $attributes Existing attributes.
		 * @return array
		 */
		public static function cashback_field_attributes( array $attributes = array() ) {
			if ( ! self::can_change_cashback() ) {
				$attributes['disabled'] = 'disabled';
			}
			return $attributes;
		}

		/**
		 * The note shown under read-only cashback fields.
		 *
		 * @return string HTML.
		 */
		public static function cashback_read_only_note() {
			return self::can_change_cashback() ? '' : '<p class="description"><em>' . esc_html__( 'Only an administrator can change cashback rules.', 'woo-wallet' ) . '</em></p>';
		}

		/**
		 * Block, or record, a change to a product's or variation's cashback.
		 *
		 * @param mixed  $check      Short-circuit value.
		 * @param int    $object_id  Post id.
		 * @param string $meta_key   Meta key.
		 * @param mixed  $meta_value Value being written.
		 * @return mixed
		 */
		public static function guard_product_cashback( $check, $object_id, $meta_key, $meta_value = null ) {
			if ( null !== $check || ! in_array( $meta_key, self::PRODUCT_CASHBACK_KEYS, true ) || ! in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
				return $check;
			}
			return self::guard_cashback( 'post', $object_id, $meta_key, $meta_value );
		}

		/**
		 * The by-meta-id variant of the guards.
		 *
		 * @param mixed  $check      Short-circuit value.
		 * @param int    $meta_id    Meta row id.
		 * @param mixed  $meta_value Value being written (updates only).
		 * @param string $meta_key   Meta key being written (updates only).
		 * @return mixed
		 */
		public static function guard_cashback_by_mid( $check, $meta_id, $meta_value = null, $meta_key = '' ) {
			if ( null !== $check ) {
				return $check;
			}
			$meta_type = 0 === strpos( current_filter(), 'update_term' ) || 0 === strpos( current_filter(), 'delete_term' ) ? 'term' : 'post';
			$meta      = get_metadata_by_mid( $meta_type, $meta_id );
			if ( ! $meta ) {
				return $check;
			}
			$key = '' !== (string) $meta_key ? $meta_key : $meta->meta_key;
			if ( 'term' === $meta_type ) {
				return self::guard_category_cashback( $check, (int) $meta->term_id, $key, $meta_value );
			}
			return self::guard_product_cashback( $check, (int) $meta->post_id, $key, $meta_value );
		}

		/**
		 * Block, or record, a change to a category's cashback.
		 *
		 * @param mixed  $check      Short-circuit value.
		 * @param int    $object_id  Term id.
		 * @param string $meta_key   Meta key.
		 * @param mixed  $meta_value Value being written.
		 * @return mixed
		 */
		public static function guard_category_cashback( $check, $object_id, $meta_key, $meta_value = null ) {
			if ( null !== $check || ! in_array( $meta_key, self::CATEGORY_CASHBACK_KEYS, true ) ) {
				return $check;
			}
			return self::guard_cashback( 'term', $object_id, $meta_key, $meta_value );
		}

		/**
		 * Shared by both guards.
		 *
		 * @param string $type       'post' or 'term'.
		 * @param int    $object_id  Object id.
		 * @param string $meta_key   Meta key.
		 * @param mixed  $meta_value Value being written (ignored for a delete).
		 * @return mixed null to let the write through, false to refuse it.
		 */
		private static function guard_cashback( $type, $object_id, $meta_key, $meta_value ) {
			$value = 0 === strpos( current_filter(), 'delete_' ) ? null : $meta_value;
			$old   = get_metadata( $type, $object_id, $meta_key, true );
			if ( null === $value && '' === (string) $old ) {
				return null; // Deleting something that is not there.
			}
			if ( null !== $value && (string) $old === (string) $value ) {
				return null; // Not actually a change.
			}
			if ( ! self::can_change_cashback( 'post' === $type ? $object_id : 0 ) ) {
				return false;
			}
			if ( get_current_user_id() && class_exists( 'Woo_Wallet_Audit' ) ) {
				Woo_Wallet_Audit::record(
					Woo_Wallet_Audit::EVENT_CASHBACK_CHANGED,
					0,
					$object_id,
					0,
					array(
						'on'   => 'post' === $type ? get_post_type( $object_id ) : 'product_cat',
						'name' => 'post' === $type ? get_the_title( $object_id ) : ( get_term( $object_id ) ? get_term( $object_id )->name : '' ),
						'rule' => $meta_key,
						'from' => (string) $old,
						'to'   => null === $value ? '' : (string) $value,
					)
				);
			}
			return null;
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
		 * Nobody adjusts their own wallet by hand — separation of duties. An
		 * administrator is the one exception (a store may have only one, and
		 * no one else to ask), but must give a reason, and every other
		 * administrator is alerted at once (see Woo_Wallet_Audit).
		 *
		 * @param string      $type        'credit' or 'debit'.
		 * @param float       $amount      Amount.
		 * @param int         $customer_id Wallet being adjusted.
		 * @param int         $staff_id    Staff user id; defaults to the current user.
		 * @param string|null $reason      The description being recorded, when known.
		 * @return true|WP_Error
		 */
		public static function authorize_adjustment( $type, $amount, $customer_id, $staff_id = 0, $reason = null ) {
			$staff_id = $staff_id ? (int) $staff_id : get_current_user_id();
			$amount   = (float) $amount;

			if ( (int) $customer_id === $staff_id ) {
				if ( ! user_can( $staff_id, 'manage_options' ) || ! user_can( $staff_id, self::CAP_ADJUST_BALANCE ) ) {
					return new WP_Error( 'woo_wallet_staff_self_credit', __( 'You cannot credit or debit your own wallet. Ask another member of staff.', 'woo-wallet' ) );
				}
				if ( null !== $reason && '' === trim( wp_strip_all_tags( (string) $reason ) ) ) {
					return new WP_Error( 'woo_wallet_staff_self_reason', __( 'Adjusting your own wallet needs a description explaining why. Every other administrator will be emailed about it.', 'woo-wallet' ) );
				}
			}

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

			$args = is_array( $args ) ? $args : array();
			if ( empty( $args['category'] ) ) {
				// What the daily digest and the balance-source check count as
				// a person changing a balance by hand.
				$args['category'] = 'adjustment';
			}

			if ( ! self::is_limited( $staff_id ) ) {
				$allowed = self::authorize_adjustment( $type, $amount, $customer_id, $staff_id, (string) $details );
				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}
				$transaction_id = 'debit' === $type
					? woo_wallet()->wallet->debit( $customer_id, $amount, $details, $args )
					: woo_wallet()->wallet->credit( $customer_id, $amount, $details, $args );
				if ( ! $transaction_id ) {
					return new WP_Error( 'woo_wallet_staff_adjust_failed', __( 'The wallet could not be updated. Please try again.', 'woo-wallet' ) );
				}
				if ( (int) $customer_id === $staff_id ) {
					update_wallet_transaction_meta( (int) $transaction_id, '_woo_wallet_self_adjustment', 1, $staff_id );
					do_action( 'woo_wallet_staff_self_adjustment', (int) $transaction_id, $staff_id, $type, (float) $amount, (string) $details );
				}
				return (int) $transaction_id;
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

		/* ---------------- large amounts ---------------- */

		/**
		 * The large-adjustment threshold (0 = off).
		 *
		 * @return float
		 */
		public static function large_threshold() {
			return max( 0.0, (float) get_option( self::LARGE_THRESHOLD_OPTION, 0 ) );
		}

		/**
		 * Whether a manual adjustment totalling this much needs confirming.
		 *
		 * @param float $total Total being moved (amount × number of wallets for a bulk action).
		 * @return bool
		 */
		public static function needs_confirmation( $total ) {
			$threshold = self::large_threshold();
			return $threshold > 0 && (float) $total > $threshold;
		}

		/**
		 * Require the amount to have been typed a second time when the total is
		 * over the threshold — the guard against a mistyped 5,000 for 50. This
		 * is the server-side check; the confirmation box in each form is only
		 * the way people satisfy it.
		 *
		 * @param float      $total   Total being moved.
		 * @param float      $amount  The amount per wallet.
		 * @param mixed|null $confirm The re-typed amount, if any.
		 * @param int        $wallets How many wallets (for the audit log).
		 * @return true|WP_Error
		 */
		public static function check_large_amount( $total, $amount, $confirm, $wallets = 1 ) {
			if ( ! self::needs_confirmation( $total ) ) {
				return true;
			}
			if ( null === $confirm || '' === trim( (string) $confirm ) || abs( (float) $confirm - (float) $amount ) > 0.0049 ) {
				return new WP_Error(
					'woo_wallet_confirmation_required',
					sprintf(
						/* translators: 1: total, 2: threshold */
						__( 'This adjustment totals %1$s, above the %2$s safety threshold. Type the amount again to confirm it.', 'woo-wallet' ),
						wp_strip_all_tags( wc_price( $total ) ),
						wp_strip_all_tags( wc_price( self::large_threshold() ) )
					)
				);
			}
			if ( class_exists( 'Woo_Wallet_Audit' ) ) {
				Woo_Wallet_Audit::record(
					Woo_Wallet_Audit::EVENT_LARGE_CONFIRMED,
					0,
					0,
					(float) $total,
					array(
						'amount'  => (float) $amount,
						'wallets' => (int) $wallets,
					)
				);
			}
			return true;
		}

		/**
		 * The re-typed amount from the current request, if any.
		 *
		 * @return string|null
		 */
		public static function submitted_confirmation() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- callers verify their own nonce first.
			return isset( $_REQUEST[ self::CONFIRM_FIELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::CONFIRM_FIELD ] ) ) : null;
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
			if ( '' === $value || Woo_Wallet_Security::unreadable_text() === $value || current_user_can( self::CAP_VIEW_BANK_DETAILS ) ) {
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
				'agents'      => __( 'Support agents', 'woo-wallet' ),
				'levels'      => __( 'Levels', 'woo-wallet' ),
				'accountants' => __( 'Accountants', 'woo-wallet' ),
			);
			if ( current_user_can( self::CAP_MANAGE_SETTINGS ) ) {
				$tabs['emails']   = __( 'Approval emails', 'woo-wallet' );
				$tabs['activity']   = __( 'Activity', 'woo-wallet' );
				$tabs['safeguards'] = __( 'Safeguards', 'woo-wallet' );
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
				} elseif ( 'activity' === $tab ) {
					$this->render_activity_tab();
				} elseif ( 'accountants' === $tab ) {
					$this->render_accountants_tab();
				} elseif ( 'safeguards' === $tab ) {
					$this->render_safeguards_tab();
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
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'woo-wallet-staff', 'tab' => 'levels' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'See what each level can do', 'woo-wallet' ); ?></a>
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
		 * What each level — and a shop manager and administrator — can do,
		 * side by side.
		 */
		private function render_levels_matrix() {
			$levels = self::get_levels();
			$yes    = '<span class="dashicons dashicons-yes" style="color:#008a20;" aria-label="' . esc_attr__( 'Yes', 'woo-wallet' ) . '"></span>';
			$no     = '<span class="dashicons dashicons-no-alt" style="color:#b32d2e;" aria-label="' . esc_attr__( 'No', 'woo-wallet' ) . '"></span>';
			$ask    = '<span class="description">' . esc_html__( 'By approval request', 'woo-wallet' ) . '</span>';
			$cell   = function ( $level, $cap, $no_text = '' ) use ( $yes, $no ) {
				if ( in_array( $cap, $level['caps'], true ) ) {
					return $yes;
				}
				return '' !== $no_text ? '<span class="description">' . esc_html( $no_text ) . '</span>' : $no;
			};
			$rows = array(
				array(
					'label'      => __( 'View wallets, transactions and withdrawal requests', 'woo-wallet' ),
					'levels'     => array_fill_keys( array_keys( $levels ), $yes ),
					'accountant' => $yes,
					'manager'    => $yes,
					'admin'   => $yes,
				),
				array(
					'label'   => __( 'Send requests for approval', 'woo-wallet' ),
					'levels'  => array_fill_keys( array_keys( $levels ), $yes ),
					'manager' => '<span class="description">' . esc_html__( 'Not needed', 'woo-wallet' ) . '</span>',
					'admin'   => '<span class="description">' . esc_html__( 'Not needed', 'woo-wallet' ) . '</span>',
				),
				array( 'label' => __( 'Add notes to withdrawal requests', 'woo-wallet' ), 'accountant' => $yes, 'cap' => self::CAP_ADD_NOTES ),
				array( 'label' => __( 'Goodwill credit', 'woo-wallet' ), 'cap' => self::CAP_GOODWILL_CREDIT, 'limits' => true ),
				array( 'label' => __( 'Credit above the limit, or debit a wallet', 'woo-wallet' ), 'agents' => $ask, 'manager' => esc_html__( 'Yes, no limit', 'woo-wallet' ), 'admin' => esc_html__( 'Yes, no limit', 'woo-wallet' ) ),
				array( 'label' => __( 'Log a withdrawal for a customer', 'woo-wallet' ), 'agents' => $ask ),
				array( 'label' => __( 'Bank account numbers and IBANs', 'woo-wallet' ), 'accountant' => $yes, 'cap' => self::CAP_VIEW_BANK_DETAILS, 'no_text' => __( 'Last 4 digits only', 'woo-wallet' ) ),
				array( 'label' => __( 'Open transfer receipts', 'woo-wallet' ), 'accountant' => $yes, 'cap' => self::CAP_VIEW_RECEIPTS ),
				array( 'label' => __( 'Mark withdrawals paid or reject them', 'woo-wallet' ), 'accountant' => $yes, 'agents' => $no ),
				array( 'label' => __( 'Approve requests', 'woo-wallet' ), 'agents' => $no ),
				array( 'label' => __( 'Export to CSV', 'woo-wallet' ), 'accountant' => $yes, 'agents' => $no ),
				array( 'label' => __( 'Manage support agents and levels', 'woo-wallet' ), 'agents' => $no ),
				array( 'label' => __( 'Credit or debit their own wallet', 'woo-wallet' ), 'agents' => $no, 'manager' => $no, 'admin' => esc_html__( 'With a reason; other administrators are alerted', 'woo-wallet' ) ),
				array( 'label' => __( 'Delete or edit transaction history', 'woo-wallet' ), 'agents' => $no, 'manager' => $no ),
				array( 'label' => __( 'Change product and category cashback', 'woo-wallet' ), 'agents' => $no, 'manager' => $no ),
				array( 'label' => __( 'See staff activity and the daily digest', 'woo-wallet' ), 'agents' => $no, 'manager' => $no ),
				array( 'label' => __( 'Choose who gets approval emails', 'woo-wallet' ), 'agents' => $no, 'manager' => $no ),
				array( 'label' => __( 'Wallet settings', 'woo-wallet' ), 'agents' => $no, 'manager' => $no ),
			);
			?>
			<table class="widefat striped" style="max-width:1100px;margin-bottom:24px;">
				<thead>
					<tr>
						<th></th>
						<?php foreach ( $levels as $level ) : ?>
							<th><?php echo esc_html( $level['name'] ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Accountant', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Shop manager', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Administrator', 'woo-wallet' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<?php foreach ( $levels as $key => $level ) : ?>
								<td>
									<?php
									if ( isset( $row['levels'] ) ) {
										$out = $row['levels'][ $key ];
									} elseif ( isset( $row['cap'] ) ) {
										$out = $cell( $level, $row['cap'], $row['no_text'] ?? '' );
										if ( ! empty( $row['limits'] ) && in_array( $row['cap'], $level['caps'], true ) ) {
											$out .= $level['daily'] > 0
												? '<br /><span class="description">' . esc_html(
													sprintf(
														/* translators: 1: per-credit limit, 2: daily limit */
														__( 'Up to %1$s at a time, %2$s a day', 'woo-wallet' ),
														wp_strip_all_tags( wc_price( $level['per_credit'] ) ),
														wp_strip_all_tags( wc_price( $level['daily'] ) )
													)
												) . '</span>'
												: '<br /><span class="description" style="color:#b32d2e;">' . esc_html__( 'No limit set yet — cannot credit', 'woo-wallet' ) . '</span>';
										}
									} else {
										$out = $row['agents'];
									}
									echo wp_kses_post( $out );
									?>
								</td>
							<?php endforeach; ?>
							<td><?php echo wp_kses_post( $row['accountant'] ?? $no ); ?></td>
							<td><?php echo wp_kses_post( $row['manager'] ?? $yes ); ?></td>
							<td><?php echo wp_kses_post( $row['admin'] ?? $yes ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:1100px;margin-top:-16px;margin-bottom:24px;"><?php esc_html_e( 'An agent\'s personal limits, if set on the Support agents tab, replace their level\'s goodwill credit limits.', 'woo-wallet' ); ?></p>
			<?php
		}

		/**
		 * The levels tab.
		 */
		private function render_levels_tab() {
			$this->render_levels_matrix();
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
		 * The safeguards tab (administrators only).
		 */
		private function render_safeguards_tab() {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="woo_wallet_safeguards" />
				<?php wp_nonce_field( 'woo_wallet_safeguards' ); ?>
				<table class="form-table" role="presentation" style="max-width:900px;">
					<tr>
						<th><label for="ww-large-threshold"><?php esc_html_e( 'Confirm manual adjustments above', 'woo-wallet' ); ?></label></th>
						<td>
							<input type="number" id="ww-large-threshold" name="large_threshold" min="0" step="0.01" value="<?php echo esc_attr( self::large_threshold() ); ?>" />
							<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
							<p class="description"><?php esc_html_e( 'Anyone crediting or debiting more than this by hand — including shop managers and administrators, and including the total of a bulk action — has to type the amount a second time. It catches a mistyped 5,000 for 50 without slowing everyday adjustments down. 0 turns it off.', 'woo-wallet' ); ?></p>
						</td>
					</tr>
				</table>
					<tr>
						<th><?php esc_html_e( 'Encrypt bank details', 'woo-wallet' ); ?></th>
						<td>
							<label><input type="checkbox" name="encrypt_bank_details" value="1" <?php checked( Woo_Wallet_Security::bank_details_encryption_enabled( (bool) apply_filters( 'woo_wallet_encrypt_bank_details', false ) ) ); ?> /> <?php esc_html_e( 'Store account numbers and IBANs on withdrawal requests encrypted', 'woo-wallet' ); ?></label>
							<p class="description"><?php esc_html_e( 'Protects them if the database alone leaks (a leaked backup, an SQL injection) — not if the whole server is compromised, since the key lives in wp-config.php. Searching withdrawals by account number stops working for encrypted records. Bank details in approval requests are always encrypted.', 'woo-wallet' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'woo-wallet' ) ); ?>
			</form>
			<?php $this->render_encryption_status(); ?>
			<?php
		}

		/**
		 * Encryption key status, problems, and the re-encrypt tool.
		 */
		private function render_encryption_status() {
			$problems = Woo_Wallet_Security::key_problems();
			$current  = Woo_Wallet_Security::current_version();
			$counts   = array(
				'v1'    => Woo_Wallet_Security::count_encrypted( 'v1' ),
				'v2'    => Woo_Wallet_Security::count_encrypted( 'v2' ),
				'plain' => Woo_Wallet_Security::count_encrypted( null ),
			);
			$outdated = ( 'v2' === $current ? $counts['v1'] : $counts['v2'] ) + ( 'yes' === get_option( Woo_Wallet_Security::ENABLED_OPTION ) ? $counts['plain'] : 0 );
			?>
			<h2><?php esc_html_e( 'Encryption key', 'woo-wallet' ); ?></h2>
			<table class="widefat striped" style="max-width:900px;">
				<tbody>
					<tr>
						<td style="width:280px;"><?php esc_html_e( 'Key used for new data', 'woo-wallet' ); ?></td>
						<td>
							<?php if ( 'v2' === $current ) : ?>
								<strong>WOO_WALLET_ENCRYPTION_KEY</strong>
							<?php else : ?>
								<?php esc_html_e( 'WordPress security keys (salts)', 'woo-wallet' ); ?>
								<p class="description"><?php esc_html_e( 'Hosts and security plugins regenerate these routinely — after a hack or a migration — which makes everything encrypted with them unreadable. Add a dedicated key to wp-config.php that nothing else will touch, keep a copy somewhere safe, then use "Re-encrypt" below:', 'woo-wallet' ); ?></p>
								<code>define( 'WOO_WALLET_ENCRYPTION_KEY', '<?php echo esc_html( wp_generate_password( 48, false, false ) ); ?>' );</code>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Stored bank details', 'woo-wallet' ); ?></td>
						<td>
							<?php
							/* translators: 1: count with dedicated key, 2: count with salts, 3: plaintext count */
							echo esc_html( sprintf( __( '%1$d with the dedicated key, %2$d with the WordPress salts, %3$d withdrawals not encrypted.', 'woo-wallet' ), $counts['v2'], $counts['v1'], $counts['plain'] ) );
							?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Key check', 'woo-wallet' ); ?></td>
						<td>
							<?php if ( $problems ) : ?>
								<strong style="color:#b32d2e;"><?php esc_html_e( 'The key has changed — stored bank details cannot be read.', 'woo-wallet' ); ?></strong>
								<p class="description"><?php esc_html_e( 'Put the original key back in wp-config.php. If it is truly lost, the data cannot be recovered; you can stop this warning, and those details will keep showing as unreadable.', 'woo-wallet' ); ?></p>
								<?php foreach ( $problems as $version ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="woo_wallet_acknowledge_lost_key" />
										<input type="hidden" name="version" value="<?php echo esc_attr( $version ); ?>" />
										<?php wp_nonce_field( 'woo_wallet_acknowledge_lost_key' ); ?>
										<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Only do this if the original key cannot be found. Data encrypted with it stays unreadable for good. Continue?', 'woo-wallet' ) ); ?>');">
											<?php echo 'v2' === $version ? esc_html__( 'The dedicated key is lost — stop warning', 'woo-wallet' ) : esc_html__( 'The old WordPress salts are lost — stop warning', 'woo-wallet' ); ?>
										</button>
									</form>
								<?php endforeach; ?>
							<?php else : ?>
								<span style="color:#008a20;"><?php esc_html_e( 'OK — the current key matches stored data.', 'woo-wallet' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php if ( ! $problems && $outdated ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="woo_wallet_reencrypt" />
					<?php wp_nonce_field( 'woo_wallet_reencrypt' ); ?>
					<button type="submit" class="button button-primary">
						<?php
						/* translators: %d: number of records */
						echo esc_html( sprintf( __( 'Re-encrypt %d records with the current key', 'woo-wallet' ), $outdated ) );
						?>
					</button>
				</form>
			<?php endif; ?>
			<?php
		}

		/**
		 * Save the safeguards tab.
		 */
		public function handle_save_safeguards() {
			if ( ! current_user_can( self::CAP_MANAGE_SETTINGS ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_safeguards' );
			update_option( self::LARGE_THRESHOLD_OPTION, max( 0.0, isset( $_POST['large_threshold'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['large_threshold'] ) ) : 0 ), false );
			$enable = ! empty( $_POST['encrypt_bank_details'] );
			if ( $enable && Woo_Wallet_Security::key_problems() ) {
				$this->redirect_with_notice( 'error', __( 'The encryption key has changed. Restore the original key before turning encryption on.', 'woo-wallet' ), 'safeguards' );
			}
			update_option( Woo_Wallet_Security::ENABLED_OPTION, $enable ? 'yes' : 'no', false );
			Woo_Wallet_Security::ensure_canaries();
			$this->redirect_with_notice( 'success', __( 'Safeguards saved.', 'woo-wallet' ), 'safeguards' );
		}

		/**
		 * Encrypt existing records / move them to the current key.
		 */
		public function handle_reencrypt() {
			if ( ! current_user_can( self::CAP_MANAGE_SETTINGS ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_reencrypt' );
			$result = Woo_Wallet_Security::reencrypt_stored();
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( 'error', $result->get_error_message(), 'safeguards' );
			}
			$this->redirect_with_notice(
				'success',
				$result['remaining']
					/* translators: 1: records updated, 2: records left */
					? sprintf( __( 'Updated %1$d records. %2$d left — click again to continue.', 'woo-wallet' ), $result['changed'], $result['remaining'] )
					/* translators: %d: records updated */
					: sprintf( __( 'Updated %d records. All stored bank details now use the current key.', 'woo-wallet' ), $result['changed'] ),
				'safeguards'
			);
		}

		/**
		 * Stop warning about a key that cannot be recovered.
		 */
		public function handle_acknowledge_lost_key() {
			if ( ! current_user_can( self::CAP_MANAGE_SETTINGS ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_acknowledge_lost_key' );
			$version = isset( $_POST['version'] ) && 'v2' === $_POST['version'] ? 'v2' : 'v1';
			Woo_Wallet_Security::acknowledge_lost_key( $version );
			if ( class_exists( 'Woo_Wallet_Audit' ) ) {
				Woo_Wallet_Audit::record( Woo_Wallet_Audit::EVENT_STAFF_CHANGED, 0, 0, 0, array( 'change' => 'encryption_key_loss_acknowledged', 'version' => $version ) );
			}
			$this->redirect_with_notice( 'success', __( 'Understood. Bank details encrypted with the old key will keep showing as unreadable.', 'woo-wallet' ), 'safeguards' );
		}

		/**
		 * Warn administrators, on every admin screen, that the encryption key
		 * no longer matches the one stored bank details were encrypted with.
		 */
		public function maybe_show_key_problem_notice() {
			if ( ! current_user_can( self::CAP_MANAGE_SETTINGS ) ) {
				return;
			}
			$problems = Woo_Wallet_Security::key_problems();
			if ( ! $problems ) {
				return;
			}
			$message = in_array( 'v2', $problems, true )
				? __( 'Axfit Wallet: WOO_WALLET_ENCRYPTION_KEY in wp-config.php is different from (or missing compared with) the key used to encrypt stored bank details, so they cannot be read. Put the original value back in wp-config.php.', 'woo-wallet' )
				: __( 'Axfit Wallet: the WordPress security keys (salts) in wp-config.php have changed since stored bank details were encrypted, so they cannot be read. Put the original SECURE_AUTH_KEY back in wp-config.php — this often happens after moving host or after a security plugin regenerates the keys.', 'woo-wallet' );
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong></p><p><a href="%2$s">%3$s</a></p></div>',
				esc_html( $message ),
				esc_url( admin_url( 'admin.php?page=woo-wallet-staff&tab=safeguards' ) ),
				esc_html__( 'Details and options', 'woo-wallet' )
			);
		}

		/**
		 * The accountants tab.
		 */
		private function render_accountants_tab() {
			$accountants = get_users(
				array(
					'role'    => self::ACCOUNTANT_ROLE,
					'orderby' => 'display_name',
				)
			);
			?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'An accountant reviews withdrawal requests with full bank details and receipts, marks them paid or rejects them, adds notes and exports. They cannot credit or debit wallets, send or approve requests, or see products, orders or settings.', 'woo-wallet' ); ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'woo-wallet-staff', 'tab' => 'levels' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Compare with the other roles', 'woo-wallet' ); ?></a>
			</p>
			<table class="widefat striped" style="max-width:820px;">
				<tbody>
					<?php if ( ! $accountants ) : ?>
						<tr><td><?php esc_html_e( 'No accountants yet.', 'woo-wallet' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $accountants as $accountant ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $accountant->display_name ); ?></strong>
								<span class="description"><?php echo esc_html( $accountant->user_email ); ?></span>
							</td>
							<td style="text-align:right;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="woo_wallet_accountant_remove" />
									<input type="hidden" name="staff_id" value="<?php echo esc_attr( $accountant->ID ); ?>" />
									<?php wp_nonce_field( 'woo_wallet_accountant_remove' ); ?>
									<button type="submit" class="button-link" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Remove accountant access from this user?', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Remove', 'woo-wallet' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h2 style="margin-top:28px;"><?php esc_html_e( 'Add an accountant', 'woo-wallet' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="woo_wallet_accountant_save" />
				<?php wp_nonce_field( 'woo_wallet_accountant_save' ); ?>
				<input type="text" name="staff_user" class="regular-text" placeholder="<?php esc_attr_e( 'Email or username', 'woo-wallet' ); ?>" required />
				<?php submit_button( __( 'Add accountant', 'woo-wallet' ), 'primary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'An existing user account. They keep their current role and gain accountant access on top of it.', 'woo-wallet' ); ?></p>
			</form>
			<?php
		}

		/**
		 * Make a user an accountant.
		 *
		 * @param int|string $user_ref User id, email or login.
		 * @return WP_User|WP_Error
		 */
		public static function save_accountant( $user_ref ) {
			$user = is_numeric( $user_ref ) ? get_userdata( (int) $user_ref ) : get_user_by( 'email', (string) $user_ref );
			if ( ! $user && ! is_numeric( $user_ref ) ) {
				$user = get_user_by( 'login', (string) $user_ref );
			}
			if ( ! $user ) {
				return new WP_Error( 'woo_wallet_staff_not_found', __( 'No user found with that email or username.', 'woo-wallet' ) );
			}
			if ( user_can( $user, self::CAP_ADJUST_BALANCE ) ) {
				return new WP_Error( 'woo_wallet_staff_already_manager', __( 'This user is already a shop manager or administrator and can do everything an accountant can.', 'woo-wallet' ) );
			}
			self::ensure_role();
			$user->add_role( self::ACCOUNTANT_ROLE );
			do_action( 'woo_wallet_staff_accountant_saved', $user->ID, get_current_user_id() );
			return $user;
		}

		/**
		 * Take accountant access away from a user.
		 *
		 * @param int $staff_id User id.
		 */
		public static function remove_accountant( $staff_id ) {
			$user = get_userdata( (int) $staff_id );
			if ( $user ) {
				$user->remove_role( self::ACCOUNTANT_ROLE );
				do_action( 'woo_wallet_staff_accountant_removed', $user->ID, get_current_user_id() );
			}
		}

		/**
		 * Handle the add-accountant form.
		 */
		public function handle_save_accountant() {
			if ( ! current_user_can( self::CAP_MANAGE_STAFF ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_accountant_save' );
			$result = self::save_accountant( isset( $_POST['staff_user'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_user'] ) ) : '' );
			$this->redirect_with_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				/* translators: %s: user display name */
				is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( '%s is now an accountant.', 'woo-wallet' ), $result->display_name ),
				'accountants'
			);
		}

		/**
		 * Handle the remove-accountant button.
		 */
		public function handle_remove_accountant() {
			if ( ! current_user_can( self::CAP_MANAGE_STAFF ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_accountant_remove' );
			self::remove_accountant( isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0 );
			$this->redirect_with_notice( 'success', __( 'Accountant access removed.', 'woo-wallet' ), 'accountants' );
		}

		/**
		 * The activity tab (administrators only): the same report as the
		 * daily digest, for a chosen period.
		 */
		private function render_activity_tab() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$days  = isset( $_GET['days'] ) ? max( 1, min( 90, absint( $_GET['days'] ) ) ) : 7;
			$until = current_time( 'mysql' );
			$since = gmdate( 'Y-m-d H:i:s', strtotime( $until ) - $days * DAY_IN_SECONDS );
			$data  = Woo_Wallet_Audit::digest_data( $since, $until );
			$name  = function ( $user_id ) {
				$user = $user_id ? get_userdata( (int) $user_id ) : false;
				return $user ? $user->display_name : ( $user_id ? '#' . (int) $user_id : __( 'System', 'woo-wallet' ) );
			};
			$labels = Woo_Wallet_Audit::event_labels();
			?>
			<form method="get" style="margin-bottom:12px;">
				<input type="hidden" name="page" value="woo-wallet-staff" />
				<input type="hidden" name="tab" value="activity" />
				<select name="days">
					<?php foreach ( array( 1 => __( 'Last 24 hours', 'woo-wallet' ), 7 => __( 'Last 7 days', 'woo-wallet' ), 30 => __( 'Last 30 days', 'woo-wallet' ), 90 => __( 'Last 90 days', 'woo-wallet' ) ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $days, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Show', 'woo-wallet' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Needs a second look', 'woo-wallet' ); ?></h2>
			<?php if ( $data['suspicious'] ) : ?>
				<table class="widefat striped" style="max-width:1100px;">
					<tbody>
						<?php foreach ( $data['suspicious'] as $flag ) : ?>
							<tr>
								<td style="width:200px;"><strong><?php echo esc_html( $name( $flag['staff_id'] ) ); ?></strong></td>
								<td>
									<span style="color:#b32d2e;"><?php echo esc_html( $flag['message'] ); ?></span>
									<?php if ( $flag['customer_id'] ) : ?>
										&middot; <a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-wallet-transactions&user_id=' . (int) $flag['customer_id'] ) ); ?>"><?php echo esc_html( $name( $flag['customer_id'] ) ); ?></a>
										<?php if ( $flag['transaction_id'] ) : ?>
											&middot; #<?php echo (int) $flag['transaction_id']; ?>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing suspicious.', 'woo-wallet' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Manual credits and debits by staff member', 'woo-wallet' ); ?></h2>
			<?php if ( $data['staff'] ) : ?>
				<table class="widefat striped" style="max-width:1100px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Staff', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Adjustments', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Credited', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Debited', 'woo-wallet' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $data['staff'] as $staff_id => $totals ) : ?>
							<tr>
								<td><?php echo esc_html( $name( $staff_id ) ); ?></td>
								<td><?php echo (int) $totals['count']; ?></td>
								<td><?php echo wp_kses_post( wc_price( $totals['credit'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $totals['debit'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Every adjustment', 'woo-wallet' ); ?></h3>
				<table class="widefat striped" style="max-width:1100px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Staff', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Wallet', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Description', 'woo-wallet' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $data['adjustments'] ) as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->date ); ?></td>
								<td><?php echo esc_html( $name( $row->created_by ) ); ?></td>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-wallet-transactions&user_id=' . (int) $row->user_id ) ); ?>"><?php echo esc_html( $name( $row->user_id ) ); ?></a></td>
								<td><?php echo esc_html( 'debit' === $row->type ? '−' : '+' ); ?><?php echo wp_kses_post( wc_price( (float) $row->amount, array( 'currency' => $row->currency ) ) ); ?></td>
								<td><?php echo esc_html( wp_strip_all_tags( (string) $row->details ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No manual credits or debits.', 'woo-wallet' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Sensitive actions', 'woo-wallet' ); ?></h2>
			<?php if ( $data['events'] ) : ?>
				<table class="widefat striped" style="max-width:1100px;">
					<tbody>
						<?php foreach ( $data['events'] as $event ) : ?>
							<tr>
								<td style="width:160px;"><?php echo esc_html( $event->date_created ); ?></td>
								<td style="width:180px;"><?php echo esc_html( $name( $event->actor_id ) ); ?></td>
								<td><?php echo esc_html( $labels[ $event->event ] ?? $event->event ); ?></td>
								<td><?php echo $event->customer_id ? esc_html( $name( $event->customer_id ) ) : ''; ?></td>
								<td class="description"><?php echo esc_html( wp_json_encode( $event->details ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'None.', 'woo-wallet' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
				<input type="hidden" name="action" value="woo_wallet_send_digest" />
				<?php wp_nonce_field( 'woo_wallet_send_digest' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Send the last 24 hours\' digest now', 'woo-wallet' ); ?></button>
				<span class="description"><?php esc_html_e( 'Goes to every administrator, as it does each morning. On a site with few visitors the morning email can arrive a little late — WordPress runs scheduled tasks when someone visits.', 'woo-wallet' ); ?></span>
			</form>
			<?php
		}

		/**
		 * Send the digest now, to the administrator who asked.
		 */
		public function handle_send_digest() {
			if ( ! current_user_can( self::CAP_MANAGE_SETTINGS ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_send_digest' );
			Woo_Wallet_Audit::send_digest();
			$this->redirect_with_notice( 'success', __( 'Digest sent.', 'woo-wallet' ), 'activity' );
		}

		/**
		 * The approval-emails tab (administrators only).
		 */
		private function render_emails_tab() {
			$chosen = Woo_Wallet_Approvals::chosen_recipient_ids();
			?>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Who gets an email when a support agent sends a request for approval. Every shop manager and administrator gets them, including anyone who becomes one later, unless you untick them here. Anyone ticked can pause their own emails from the Approvals screen, for example while on leave. Everyone listed can approve requests whether or not they get the email.', 'woo-wallet' ); ?>
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
