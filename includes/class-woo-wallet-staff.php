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

		/**
		 * User meta: the most a support agent may credit in one go.
		 */
		const META_CREDIT_LIMIT = '_woo_wallet_staff_credit_limit';

		/**
		 * User meta: the most a support agent may credit in total per day.
		 */
		const META_DAILY_LIMIT = '_woo_wallet_staff_daily_limit';

		/**
		 * Ledger category for a support agent's limited credit — what the
		 * daily limit is counted against.
		 */
		const GOODWILL_CATEGORY = 'goodwill';

		/**
		 * Hook up.
		 */
		public function __construct() {
			add_filter( 'user_has_cap', array( __CLASS__, 'grant_capabilities' ), 10, 4 );
			add_action( 'init', array( __CLASS__, 'ensure_role' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
			add_action( 'admin_post_woo_wallet_staff_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_woo_wallet_staff_remove', array( $this, 'handle_remove' ) );
		}

		/* ---------------- capabilities ---------------- */

		/**
		 * Capabilities a support agent holds.
		 *
		 * @return string[]
		 */
		public static function support_capabilities() {
			return array(
				self::CAP_VIEW,
				self::CAP_ADD_NOTES,
				self::CAP_CREATE_WITHDRAWALS,
				self::CAP_GOODWILL_CREDIT,
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
			);
		}

		/**
		 * Grant the manager tier to whoever holds the wallet capability, and
		 * settings on top to whoever can also manage the site's options.
		 * Support agents get theirs from the role itself.
		 *
		 * @param bool[]   $allcaps Capabilities the user has.
		 * @param string[] $caps    Primitive capabilities being checked.
		 * @param array    $args    Check arguments.
		 * @param WP_User  $user    The user.
		 * @return bool[]
		 */
		public static function grant_capabilities( $allcaps, $caps, $args, $user ) {
			$wallet_caps = array_merge( self::manager_capabilities(), array( self::CAP_MANAGE_SETTINGS ) );
			if ( ! array_intersect( (array) $caps, $wallet_caps ) ) {
				return $allcaps;
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
		 * Create the support agent role if it does not exist yet. Runs on
		 * `init` so an in-place update gets it without re-activation.
		 */
		public static function ensure_role() {
			if ( get_role( self::ROLE ) ) {
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

		/* ---------------- limits ---------------- */

		/**
		 * A support agent's credit limits.
		 *
		 * @param int $staff_id Staff user id.
		 * @return array {per_credit:float, daily:float}
		 */
		public static function get_limits( $staff_id ) {
			return array(
				'per_credit' => max( 0.0, (float) get_user_meta( $staff_id, self::META_CREDIT_LIMIT, true ) ),
				'daily'      => max( 0.0, (float) get_user_meta( $staff_id, self::META_DAILY_LIMIT, true ) ),
			);
		}

		/**
		 * Set a support agent's credit limits.
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
				return new WP_Error( 'woo_wallet_staff_forbidden', __( 'You may only add credit to a wallet, not debit it.', 'woo-wallet' ) );
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
					sprintf( __( 'This is more than you may credit at once (your limit is %s).', 'woo-wallet' ), wp_strip_all_tags( wc_price( $limits['per_credit'] ) ) )
				);
			}
			$remaining = $limits['daily'] - self::credited_today( $staff_id );
			if ( $amount > $remaining + 0.00001 ) {
				return new WP_Error(
					'woo_wallet_staff_over_daily_limit',
					/* translators: %s: what is left of the agent's daily limit */
					sprintf( __( 'This would exceed your daily credit limit (%s left today).', 'woo-wallet' ), wp_strip_all_tags( wc_price( max( 0, $remaining ) ) ) )
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
		 * Support agents, with their limits and today's usage.
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
		 * Render the staff screen.
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
			$agents = self::get_agents();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Wallet Staff', 'woo-wallet' ); ?></h1>
				<?php if ( $notice ) : ?>
					<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
				<?php endif; ?>
				<p class="description" style="max-width:780px;">
					<?php esc_html_e( 'Support agents can view wallets and withdrawal requests (bank details are masked), add notes, log a pending withdrawal for a customer, and give goodwill credit within the limits set here. Shop managers and administrators are not listed: they can adjust balances without a limit.', 'woo-wallet' ); ?>
				</p>

				<h2><?php esc_html_e( 'Support agents', 'woo-wallet' ); ?></h2>
				<table class="widefat striped" style="max-width:980px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Agent', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Max per credit', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Max per day', 'woo-wallet' ); ?></th>
							<th><?php esc_html_e( 'Credited today', 'woo-wallet' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $agents ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No support agents yet.', 'woo-wallet' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $agents as $agent ) : ?>
							<?php
							$limits  = self::get_limits( $agent->ID );
							$form_id = 'woo-wallet-staff-' . $agent->ID;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $agent->display_name ); ?></strong><br />
									<span class="description"><?php echo esc_html( $agent->user_email ); ?></span>
									<?php if ( user_can( $agent, self::CAP_ADJUST_BALANCE ) ) : ?>
										<br /><span class="description"><?php esc_html_e( 'Also a shop manager or administrator — these limits do not apply.', 'woo-wallet' ); ?></span>
									<?php endif; ?>
								</td>
								<td><input form="<?php echo esc_attr( $form_id ); ?>" type="number" name="per_credit" min="0" step="0.01" value="<?php echo esc_attr( $limits['per_credit'] ); ?>" style="width:120px;" /></td>
								<td><input form="<?php echo esc_attr( $form_id ); ?>" type="number" name="daily" min="0" step="0.01" value="<?php echo esc_attr( $limits['daily'] ); ?>" style="width:120px;" /></td>
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
										<button type="submit" class="button-link" style="color:#b32d2e;margin-left:8px;"><?php esc_html_e( 'Remove', 'woo-wallet' ); ?></button>
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
					<table class="form-table" role="presentation" style="max-width:780px;">
						<tr>
							<th><label for="ww-staff-user"><?php esc_html_e( 'Email or username', 'woo-wallet' ); ?></label></th>
							<td>
								<input type="text" id="ww-staff-user" name="staff_user" class="regular-text" required />
								<p class="description"><?php esc_html_e( 'An existing user account. They keep their current role and gain support agent access on top of it.', 'woo-wallet' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="ww-staff-per-credit"><?php esc_html_e( 'Max per credit', 'woo-wallet' ); ?></label></th>
							<td><input type="number" id="ww-staff-per-credit" name="per_credit" min="0" step="0.01" value="0" /></td>
						</tr>
						<tr>
							<th><label for="ww-staff-daily"><?php esc_html_e( 'Max per day', 'woo-wallet' ); ?></label></th>
							<td>
								<input type="number" id="ww-staff-daily" name="daily" min="0" step="0.01" value="0" />
								<p class="description"><?php esc_html_e( 'Leave both at 0 for an agent who should not be able to credit wallets at all.', 'woo-wallet' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Add agent', 'woo-wallet' ) ); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Add a support agent, or update an existing agent's limits.
		 *
		 * @param int|string $user_ref   User id, email or login.
		 * @param float      $per_credit Most they may credit in one go.
		 * @param float      $daily      Most they may credit per day.
		 * @return WP_User|WP_Error
		 */
		public static function save_agent( $user_ref, $per_credit, $daily ) {
			$user = is_numeric( $user_ref ) ? get_userdata( (int) $user_ref ) : get_user_by( 'email', (string) $user_ref );
			if ( ! $user && ! is_numeric( $user_ref ) ) {
				$user = get_user_by( 'login', (string) $user_ref );
			}
			if ( ! $user ) {
				return new WP_Error( 'woo_wallet_staff_not_found', __( 'No user found with that email or username.', 'woo-wallet' ) );
			}
			$per_credit = max( 0.0, (float) $per_credit );
			$daily      = max( 0.0, (float) $daily );
			if ( $per_credit > $daily ) {
				return new WP_Error( 'woo_wallet_staff_bad_limits', __( 'The per-credit limit cannot be higher than the daily limit.', 'woo-wallet' ) );
			}
			self::ensure_role();
			if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
				if ( user_can( $user, self::CAP_ADJUST_BALANCE ) ) {
					return new WP_Error( 'woo_wallet_staff_already_manager', __( 'This user is already a shop manager or administrator and can adjust balances without a limit.', 'woo-wallet' ) );
				}
				$user->add_role( self::ROLE );
			}
			self::set_limits( $user->ID, $per_credit, $daily );
			do_action( 'woo_wallet_staff_agent_saved', $user->ID, $per_credit, $daily, get_current_user_id() );
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
			delete_user_meta( $user->ID, self::META_CREDIT_LIMIT );
			delete_user_meta( $user->ID, self::META_DAILY_LIMIT );
			do_action( 'woo_wallet_staff_agent_removed', $user->ID, get_current_user_id() );
		}

		/**
		 * Handle the add/update form.
		 */
		public function handle_save() {
			if ( ! current_user_can( self::CAP_MANAGE_STAFF ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_staff_save' );

			$user_ref = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : ( isset( $_POST['staff_user'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_user'] ) ) : '' );
			$result   = self::save_agent(
				$user_ref,
				isset( $_POST['per_credit'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['per_credit'] ) ) : 0,
				isset( $_POST['daily'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['daily'] ) ) : 0
			);
			$this->redirect_with_notice(
				is_wp_error( $result ) ? 'error' : 'success',
				/* translators: %s: agent display name */
				is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Saved limits for %s.', 'woo-wallet' ), $result->display_name )
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
		 * Store a one-shot notice and go back to the staff screen.
		 *
		 * @param string $type    'success' or 'error'.
		 * @param string $message Message.
		 */
		private function redirect_with_notice( $type, $message ) {
			set_transient(
				'woo_wallet_staff_notice_' . get_current_user_id(),
				array(
					'type'    => $type,
					'message' => $message,
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=woo-wallet-staff' ) );
			exit();
		}
	}
}
