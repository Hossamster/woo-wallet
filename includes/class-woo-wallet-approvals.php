<?php
/**
 * Approval requests: a support agent asks, a shop manager or administrator
 * decides.
 *
 * Anything that takes money out of a customer's wallet (a debit, logging a
 * withdrawal, which reserves the amount) or goes beyond an agent's own
 * credit limit is never done by the agent directly. They file a request
 * instead; nothing moves until an approver accepts it, and the action then
 * runs in the approver's name through the same code a manager would use.
 *
 * A request stays pending until an approver approves or rejects it, or the
 * agent who filed it cancels it — it never expires on its own.
 *
 * @package StandaleneTech
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woo_Wallet_Approvals' ) ) {

	/**
	 * Approval requests.
	 */
	class Woo_Wallet_Approvals {

		const TYPE_WITHDRAWAL = 'withdrawal';
		const TYPE_CREDIT     = 'credit';
		const TYPE_DEBIT      = 'debit';

		const STATUS_PENDING    = 'pending';
		const STATUS_PROCESSING = 'processing';
		const STATUS_APPROVED   = 'approved';
		const STATUS_REJECTED   = 'rejected';
		const STATUS_CANCELLED  = 'cancelled';
		const STATUS_FAILED     = 'failed';

		/**
		 * Option: approvers an administrator took off the new-request emails.
		 * Stored as exclusions rather than a list of recipients, so anyone who
		 * becomes an approver later is included without someone having to
		 * remember to add them.
		 */
		const EXCLUDED_OPTION = 'woo_wallet_approval_email_excluded';

		/**
		 * Option written by earlier 1.10.0 builds: the recipients themselves.
		 * Converted to EXCLUDED_OPTION the first time it is read.
		 */
		const LEGACY_RECIPIENTS_OPTION = 'woo_wallet_approval_email_recipients';

		/**
		 * User meta: a recipient paused their own new-request emails.
		 */
		const PAUSE_META = '_woo_wallet_approval_emails_paused';

		/**
		 * Hook up.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 61 );
			add_action( 'admin_post_woo_wallet_approval_create', array( $this, 'handle_create' ) );
			add_action( 'admin_post_woo_wallet_approval_decide', array( $this, 'handle_decide' ) );
			add_action( 'admin_post_woo_wallet_approval_cancel', array( $this, 'handle_cancel' ) );
			add_action( 'admin_post_woo_wallet_approval_pause', array( $this, 'handle_pause' ) );
			add_action( 'admin_post_woo_wallet_approval_recipients', array( $this, 'handle_recipients' ) );
		}

		/**
		 * Table name.
		 *
		 * @return string
		 */
		public static function table() {
			global $wpdb;
			return $wpdb->base_prefix . 'woo_wallet_approval_requests';
		}

		/**
		 * Request types and their labels.
		 *
		 * @return string[]
		 */
		public static function types() {
			return array(
				self::TYPE_WITHDRAWAL => __( 'Withdrawal for a customer', 'woo-wallet' ),
				self::TYPE_CREDIT     => __( 'Credit', 'woo-wallet' ),
				self::TYPE_DEBIT      => __( 'Debit', 'woo-wallet' ),
			);
		}

		/**
		 * Status labels.
		 *
		 * @return string[]
		 */
		public static function statuses() {
			return array(
				self::STATUS_PENDING    => __( 'Waiting for approval', 'woo-wallet' ),
				self::STATUS_PROCESSING => __( 'Interrupted', 'woo-wallet' ),
				self::STATUS_APPROVED   => __( 'Approved', 'woo-wallet' ),
				self::STATUS_REJECTED   => __( 'Rejected', 'woo-wallet' ),
				self::STATUS_CANCELLED  => __( 'Cancelled', 'woo-wallet' ),
				self::STATUS_FAILED     => __( 'Failed', 'woo-wallet' ),
			);
		}

		/* ---------------- data ---------------- */

		/**
		 * File a request. Moves no money.
		 *
		 * @param string $type        One of the TYPE_* constants.
		 * @param int    $customer_id Customer whose wallet it concerns.
		 * @param float  $amount      Amount.
		 * @param array  $details     For a withdrawal: bank_name, beneficiary_name, account_number, phone, iban.
		 * @param string $reason      Why — shown to the approver, and used as the transaction description.
		 * @return int|WP_Error Request id.
		 */
		public static function create( $type, $customer_id, $amount, array $details, $reason ) {
			global $wpdb;
			$requester_id = get_current_user_id();
			$customer_id  = (int) $customer_id;
			$amount       = round( (float) $amount, wc_get_price_decimals() );
			$reason       = trim( (string) $reason );

			if ( ! current_user_can( Woo_Wallet_Staff::CAP_REQUEST_APPROVAL ) ) {
				return new WP_Error( 'woo_wallet_approval_forbidden', __( 'You do not have permission to send approval requests.', 'woo-wallet' ) );
			}
			if ( ! isset( self::types()[ $type ] ) ) {
				return new WP_Error( 'woo_wallet_approval_type', __( 'Choose what you are asking for.', 'woo-wallet' ) );
			}
			if ( ! $customer_id || ! get_userdata( $customer_id ) ) {
				return new WP_Error( 'woo_wallet_approval_customer', __( 'No customer found with that email or username.', 'woo-wallet' ) );
			}
			if ( $customer_id === $requester_id ) {
				return new WP_Error( 'woo_wallet_approval_self', __( 'You cannot send a request about your own wallet.', 'woo-wallet' ) );
			}
			if ( $amount <= 0 ) {
				return new WP_Error( 'woo_wallet_approval_amount', __( 'Enter an amount greater than zero.', 'woo-wallet' ) );
			}
			if ( self::TYPE_WITHDRAWAL !== $type && '' === $reason ) {
				return new WP_Error( 'woo_wallet_approval_reason', __( 'Explain why, so the approver can decide.', 'woo-wallet' ) );
			}

			$payload = array();
			if ( self::TYPE_WITHDRAWAL === $type ) {
				$payload = array(
					'bank_name'        => trim( (string) ( $details['bank_name'] ?? '' ) ),
					'beneficiary_name' => trim( (string) ( $details['beneficiary_name'] ?? '' ) ),
					'account_number'   => preg_replace( '/\s+/', '', (string) ( $details['account_number'] ?? '' ) ),
					'phone'            => preg_replace( '/[^0-9+]/', '', (string) ( $details['phone'] ?? '' ) ),
					'iban'             => strtoupper( preg_replace( '/\s+/', '', (string) ( $details['iban'] ?? '' ) ) ),
				);
				if ( '' === $payload['bank_name'] || '' === $payload['beneficiary_name'] || '' === $payload['account_number'] ) {
					return new WP_Error( 'woo_wallet_approval_bank', __( 'Bank, beneficiary name and account number are required.', 'woo-wallet' ) );
				}
				if ( strlen( $payload['phone'] ) < 8 ) {
					return new WP_Error( 'woo_wallet_approval_phone', __( 'Please enter a valid contact phone number.', 'woo-wallet' ) );
				}
				// A request can wait a long time for a decision — keep bank
				// details encrypted at rest regardless of the site-wide setting.
				$payload['account_number'] = Woo_Wallet_Security::encrypt( $payload['account_number'] );
				$payload['iban']           = Woo_Wallet_Security::encrypt( $payload['iban'] );
			}

			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'type'         => $type,
					'customer_id'  => $customer_id,
					'amount'       => $amount,
					'currency'     => get_woocommerce_currency(),
					'payload'      => wp_json_encode( $payload ),
					'reason'       => $reason,
					'status'       => self::STATUS_PENDING,
					'requested_by' => $requester_id,
					'date_created' => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			if ( ! $inserted ) {
				return new WP_Error( 'woo_wallet_approval_db', __( 'The request could not be saved. Please try again.', 'woo-wallet' ) );
			}
			$id = (int) $wpdb->insert_id;
			self::send_email( 'Woo_Wallet_Email_Approval_Requested', $id );
			do_action( 'woo_wallet_approval_requested', $id );
			return $id;
		}

		/**
		 * One request.
		 *
		 * @param int $id Request id.
		 * @return object|null
		 */
		public static function get( $id ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return $row ? $row : null;
		}

		/**
		 * A request's details (withdrawal bank details decrypted).
		 *
		 * @param object $row Request row.
		 * @return array
		 */
		public static function details( $row ) {
			$payload = json_decode( (string) $row->payload, true );
			$payload = is_array( $payload ) ? $payload : array();
			foreach ( array( 'account_number', 'iban' ) as $key ) {
				if ( isset( $payload[ $key ] ) ) {
					$payload[ $key ] = (string) Woo_Wallet_Security::decrypt( $payload[ $key ] );
				}
			}
			return $payload;
		}

		/**
		 * List requests, newest first.
		 *
		 * @param array $args {status?: string, requested_by?: int, limit?: int}.
		 * @return object[]
		 */
		public static function get_requests( array $args = array() ) {
			global $wpdb;
			$where  = array( '1=1' );
			$params = array();
			if ( ! empty( $args['status'] ) ) {
				$where[]  = 'status = %s';
				$params[] = $args['status'];
			}
			if ( ! empty( $args['requested_by'] ) ) {
				$where[]  = 'requested_by = %d';
				$params[] = (int) $args['requested_by'];
			}
			$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
			$sql      = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
			return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		/**
		 * Number of requests waiting for a decision.
		 *
		 * @return int
		 */
		public static function count_pending() {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', self::STATUS_PENDING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		/**
		 * Approve a request and carry it out in the approver's name.
		 *
		 * The row is claimed with a conditional update first, so two
		 * approvers clicking at the same moment cannot both carry it out. If
		 * carrying it out fails (e.g. the customer no longer has enough
		 * balance), the request ends as 'failed' with the reason and nothing
		 * is changed.
		 *
		 * @param int    $id   Request id.
		 * @param string $note Optional note to the agent.
		 * @return true|WP_Error
		 */
		public static function approve( $id, $note = '' ) {
			global $wpdb;
			if ( ! current_user_can( Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) ) {
				return new WP_Error( 'woo_wallet_approval_forbidden', __( 'You do not have permission to approve requests.', 'woo-wallet' ) );
			}
			$row = self::get( $id );
			if ( ! $row ) {
				return new WP_Error( 'woo_wallet_approval_not_found', __( 'Request not found.', 'woo-wallet' ) );
			}
			$approver_id = get_current_user_id();
			if ( (int) $row->requested_by === $approver_id ) {
				return new WP_Error( 'woo_wallet_approval_own', __( 'You cannot approve your own request.', 'woo-wallet' ) );
			}

			$claimed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'status'        => self::STATUS_PROCESSING,
					'decided_by'    => $approver_id,
					'decision_note' => trim( (string) $note ),
					'date_decided'  => current_time( 'mysql' ),
				),
				array(
					'id'     => $row->id,
					'status' => self::STATUS_PENDING,
				),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( ! $claimed ) {
				return new WP_Error( 'woo_wallet_approval_decided', __( 'This request has already been decided.', 'woo-wallet' ) );
			}

			$result = self::carry_out( $row );
			if ( is_wp_error( $result ) ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::table(),
					array(
						'status'         => self::STATUS_FAILED,
						'failure_reason' => $result->get_error_message(),
					),
					array( 'id' => $row->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				self::send_email( 'Woo_Wallet_Email_Approval_Decided', $row->id );
				do_action( 'woo_wallet_approval_failed', $row->id, $result );
				return $result;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'status'    => self::STATUS_APPROVED,
					'result_id' => (int) $result,
				),
				array( 'id' => $row->id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
			self::send_email( 'Woo_Wallet_Email_Approval_Decided', $row->id );
			do_action( 'woo_wallet_approval_approved', $row->id, (int) $result );
			return true;
		}

		/**
		 * Carry out an approved request as the current user (the approver).
		 *
		 * @param object $row Request row.
		 * @return int|WP_Error Withdrawal id or transaction id.
		 */
		private static function carry_out( $row ) {
			$requester = get_userdata( (int) $row->requested_by );
			$by        = $requester ? $requester->display_name : '#' . (int) $row->requested_by;
			/* translators: 1: request id, 2: agent name */
			$origin = sprintf( __( 'Approval request #%1$d from %2$s', 'woo-wallet' ), (int) $row->id, $by );

			if ( self::TYPE_WITHDRAWAL === $row->type ) {
				$details = self::details( $row );
				$note    = $row->reason ? $origin . ': ' . $row->reason : $origin;
				$result  = Woo_Wallet_Withdrawal::admin_create(
					(int) $row->customer_id,
					(float) $row->amount,
					$details['bank_name'] ?? '',
					$details['beneficiary_name'] ?? '',
					$details['account_number'] ?? '',
					$details['phone'] ?? '',
					$details['iban'] ?? '',
					get_current_user_id(),
					'pending',
					'',
					0,
					$note,
					'private'
				);
				return empty( $result['is_valid'] )
					? new WP_Error( 'woo_wallet_approval_withdrawal_failed', $result['message'] )
					: (int) $result['id'];
			}

			if ( self::TYPE_DEBIT === $row->type ) {
				$balance = (float) woo_wallet()->wallet->get_wallet_balance( (int) $row->customer_id, 'edit' );
				$amount  = (float) $row->amount;
				if ( apply_filters( 'woo_wallet_disallow_negative_transaction', ( $balance <= 0 || $amount > $balance ), $amount, $balance ) ) {
					return new WP_Error( 'woo_wallet_approval_insufficient', __( 'The customer does not have enough balance for this debit.', 'woo-wallet' ) );
				}
			}
			// The description is what the customer sees in their wallet history
			// and transaction email — only the agent's reason, never internal
			// details like the request number or the agent's name, which go in
			// the transaction meta instead.
			$transaction_id = Woo_Wallet_Staff::adjust( $row->type, (int) $row->customer_id, (float) $row->amount, $row->reason, array( 'category' => 'adjustment' ) );
			if ( ! is_wp_error( $transaction_id ) ) {
				update_wallet_transaction_meta( $transaction_id, '_woo_wallet_approval_request_id', (int) $row->id, (int) $row->customer_id );
				update_wallet_transaction_meta( $transaction_id, '_woo_wallet_requested_by', (int) $row->requested_by, (int) $row->customer_id );
			}
			return $transaction_id;
		}

		/**
		 * Reject a pending request.
		 *
		 * @param int    $id   Request id.
		 * @param string $note Why — shown to the agent.
		 * @return true|WP_Error
		 */
		public static function reject( $id, $note ) {
			if ( ! current_user_can( Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) ) {
				return new WP_Error( 'woo_wallet_approval_forbidden', __( 'You do not have permission to reject requests.', 'woo-wallet' ) );
			}
			if ( '' === trim( (string) $note ) ) {
				return new WP_Error( 'woo_wallet_approval_note', __( 'Tell the agent why the request was rejected.', 'woo-wallet' ) );
			}
			$closed = self::close( $id, self::STATUS_PENDING, self::STATUS_REJECTED, $note );
			if ( is_wp_error( $closed ) ) {
				return $closed;
			}
			self::send_email( 'Woo_Wallet_Email_Approval_Decided', $id );
			do_action( 'woo_wallet_approval_rejected', (int) $id );
			return true;
		}

		/**
		 * Close a request left 'processing' by an interrupted approval. The
		 * approver must first check whether the action actually happened.
		 *
		 * @param int    $id   Request id.
		 * @param string $note What the approver found.
		 * @return true|WP_Error
		 */
		public static function close_interrupted( $id, $note ) {
			if ( ! current_user_can( Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) ) {
				return new WP_Error( 'woo_wallet_approval_forbidden', __( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			return self::close( $id, self::STATUS_PROCESSING, self::STATUS_FAILED, $note );
		}

		/**
		 * Cancel a pending request — only the agent who filed it may.
		 *
		 * @param int $id Request id.
		 * @return true|WP_Error
		 */
		public static function cancel( $id ) {
			$row = self::get( $id );
			if ( ! $row || (int) $row->requested_by !== get_current_user_id() ) {
				return new WP_Error( 'woo_wallet_approval_not_found', __( 'Request not found.', 'woo-wallet' ) );
			}
			$closed = self::close( $id, self::STATUS_PENDING, self::STATUS_CANCELLED, '' );
			if ( ! is_wp_error( $closed ) ) {
				do_action( 'woo_wallet_approval_cancelled', (int) $id );
			}
			return $closed;
		}

		/**
		 * Move a request from one status to another, only if it is still in
		 * the first one.
		 *
		 * @param int    $id   Request id.
		 * @param string $from Expected current status.
		 * @param string $to   New status.
		 * @param string $note Decision note.
		 * @return true|WP_Error
		 */
		private static function close( $id, $from, $to, $note ) {
			global $wpdb;
			$changed = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'status'        => $to,
					'decided_by'    => get_current_user_id(),
					'decision_note' => trim( (string) $note ),
					'date_decided'  => current_time( 'mysql' ),
				),
				array(
					'id'     => (int) $id,
					'status' => $from,
				),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d', '%s' )
			);
			return $changed ? true : new WP_Error( 'woo_wallet_approval_decided', __( 'This request has already been decided.', 'woo-wallet' ) );
		}

		/* ---------------- emails ---------------- */

		/**
		 * Everyone who may approve requests.
		 *
		 * @return WP_User[]
		 */
		public static function approvers() {
			// Approval rights come from the wallet capability (see
			// Woo_Wallet_Staff::grant_capabilities()), so only users holding
			// it can qualify — never load every customer on the site.
			return array_values(
				array_filter(
					get_users( array( 'capability__in' => array( get_wallet_user_capability() ) ) ),
					function ( $user ) {
						return user_can( $user, Woo_Wallet_Staff::CAP_APPROVE_REQUESTS );
					}
				)
			);
		}

		/**
		 * Approvers an administrator took off the new-request emails.
		 *
		 * @return int[]
		 */
		public static function excluded_recipient_ids() {
			$legacy = get_option( self::LEGACY_RECIPIENTS_OPTION, null );
			if ( is_array( $legacy ) ) {
				$approver_ids = array_map( 'intval', wp_list_pluck( self::approvers(), 'ID' ) );
				update_option( self::EXCLUDED_OPTION, array_values( array_diff( $approver_ids, array_map( 'intval', $legacy ) ) ), false );
				delete_option( self::LEGACY_RECIPIENTS_OPTION );
			}
			return array_map( 'intval', (array) get_option( self::EXCLUDED_OPTION, array() ) );
		}

		/**
		 * The approvers chosen to get new-request emails: every approver,
		 * including ones added later, except those an administrator took off.
		 *
		 * @return int[]
		 */
		public static function chosen_recipient_ids() {
			return array_values( array_diff( array_map( 'intval', wp_list_pluck( self::approvers(), 'ID' ) ), self::excluded_recipient_ids() ) );
		}

		/**
		 * Whether a user paused their own new-request emails.
		 *
		 * @param int $user_id User id.
		 * @return bool
		 */
		public static function is_paused( $user_id ) {
			return (bool) get_user_meta( $user_id, self::PAUSE_META, true );
		}

		/**
		 * Who actually receives a new-request email: the chosen approvers
		 * who have not paused them. If that leaves nobody, every
		 * administrator gets it instead, so a request is never silently
		 * unannounced.
		 *
		 * @return array {emails: string[], fallback: bool}
		 */
		public static function email_recipients() {
			$emails = array();
			foreach ( self::chosen_recipient_ids() as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user && user_can( $user, Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) && ! self::is_paused( $user_id ) ) {
					$emails[] = $user->user_email;
				}
			}
			$fallback = false;
			if ( ! $emails ) {
				$fallback = true;
				foreach ( self::approvers() as $user ) {
					if ( user_can( $user, 'manage_options' ) ) {
						$emails[] = $user->user_email;
					}
				}
			}
			return array(
				'emails'   => array_values( array_unique( array_filter( $emails ) ) ),
				'fallback' => $fallback,
			);
		}

		/**
		 * Send one of the approval emails.
		 *
		 * @param string $class Email class key.
		 * @param int    $id    Request id.
		 */
		private static function send_email( $class, $id ) {
			if ( ! function_exists( 'WC' ) ) {
				return;
			}
			$emails = WC()->mailer()->get_emails();
			$email  = $emails[ $class ] ?? null;
			if ( ! $email ) {
				// The mailer was built before this plugin registered its email
				// classes (on `init`) — load the one needed directly.
				$files = array(
					'Woo_Wallet_Email_Approval_Requested' => 'class-woo-wallet-email-approval-requested.php',
					'Woo_Wallet_Email_Approval_Decided'   => 'class-woo-wallet-email-approval-decided.php',
				);
				if ( isset( $files[ $class ] ) ) {
					$email = include WOO_WALLET_ABSPATH . 'includes/emails/' . $files[ $class ];
				}
			}
			if ( $email instanceof WC_Email ) {
				$email->trigger( (int) $id );
			}
		}

		/* ---------------- admin screen ---------------- */

		/**
		 * The page URL.
		 *
		 * @param array $args Query args.
		 * @return string
		 */
		public static function page_url( array $args = array() ) {
			return add_query_arg( array_merge( array( 'page' => 'woo-wallet-approvals' ), $args ), admin_url( 'admin.php' ) );
		}

		/**
		 * Add the "Approvals" submenu, with a pending count for approvers.
		 */
		public function admin_menu() {
			if ( current_user_can( Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) ) {
				$pending = self::count_pending();
				$title   = __( 'Approvals', 'woo-wallet' );
				if ( $pending ) {
					$title .= ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . (int) $pending . '</span></span>';
				}
				add_submenu_page( 'woo-wallet', __( 'Approval Requests', 'woo-wallet' ), $title, Woo_Wallet_Staff::CAP_APPROVE_REQUESTS, 'woo-wallet-approvals', array( $this, 'render_page' ) );
			} else {
				add_submenu_page( 'woo-wallet', __( 'My Requests', 'woo-wallet' ), __( 'My Requests', 'woo-wallet' ), Woo_Wallet_Staff::CAP_REQUEST_APPROVAL, 'woo-wallet-approvals', array( $this, 'render_page' ) );
			}
		}

		/**
		 * Render the screen.
		 */
		public function render_page() {
			$can_approve = current_user_can( Woo_Wallet_Staff::CAP_APPROVE_REQUESTS );
			$can_request = current_user_can( Woo_Wallet_Staff::CAP_REQUEST_APPROVAL );
			if ( ! $can_approve && ! $can_request ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-wallet' ) );
			}
			$notice_key = 'woo_wallet_approval_notice_' . get_current_user_id();
			$notice     = get_transient( $notice_key );
			if ( $notice ) {
				delete_transient( $notice_key );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
			?>
			<div class="wrap">
				<?php if ( $notice ) : ?>
					<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
				<?php endif; ?>
				<?php
				if ( 'new' === $action && $can_request ) {
					$this->render_new_form();
				} else {
					$this->render_list( $can_approve );
				}
				?>
			</div>
			<?php
		}

		/**
		 * The request list: everything for approvers, own requests for agents.
		 *
		 * @param bool $can_approve Whether the viewer approves requests.
		 */
		private function render_list( $can_approve ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ( $can_approve ? self::STATUS_PENDING : '' );
			$statuses = self::statuses();
			$args     = array();
			if ( isset( $statuses[ $status ] ) ) {
				$args['status'] = $status;
			}
			if ( ! $can_approve ) {
				$args['requested_by'] = get_current_user_id();
			}
			$rows = self::get_requests( $args );
			?>
			<h1 class="wp-heading-inline"><?php echo $can_approve ? esc_html__( 'Approval Requests', 'woo-wallet' ) : esc_html__( 'My Requests', 'woo-wallet' ); ?></h1>
			<?php if ( current_user_can( Woo_Wallet_Staff::CAP_REQUEST_APPROVAL ) ) : ?>
				<a href="<?php echo esc_url( self::page_url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'New request', 'woo-wallet' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end" />

			<?php if ( $can_approve ) : ?>
				<?php $this->render_pause_toggle(); ?>
			<?php endif; ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( self::page_url( array( 'status' => 'all' ) ) ); ?>" class="<?php echo isset( $statuses[ $status ] ) ? '' : 'current'; ?>"><?php esc_html_e( 'All', 'woo-wallet' ); ?></a> |</li>
				<?php foreach ( $statuses as $key => $label ) : ?>
					<li><a href="<?php echo esc_url( self::page_url( array( 'status' => $key ) ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo self::STATUS_FAILED === $key ? '' : ' |'; ?></li>
				<?php endforeach; ?>
			</ul>
			<br class="clear" />

			<table class="widefat striped" style="margin-top:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Request', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Details', 'woo-wallet' ); ?></th>
						<th><?php esc_html_e( 'Status', 'woo-wallet' ); ?></th>
						<th style="width:300px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No requests.', 'woo-wallet' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $this->render_row( $row, $can_approve ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * One request row.
		 *
		 * @param object $row         Request row.
		 * @param bool   $can_approve Whether the viewer approves requests.
		 */
		private function render_row( $row, $can_approve ) {
			$customer  = get_userdata( (int) $row->customer_id );
			$requester = get_userdata( (int) $row->requested_by );
			$decider   = $row->decided_by ? get_userdata( (int) $row->decided_by ) : null;
			$details   = self::details( $row );
			$types     = self::types();
			$statuses  = self::statuses();
			$post_url  = admin_url( 'admin-post.php' );
			?>
			<tr>
				<td>
					<strong>#<?php echo (int) $row->id; ?> <?php echo esc_html( $types[ $row->type ] ?? $row->type ); ?></strong><br />
					<span class="description">
						<?php
						/* translators: 1: agent name, 2: date */
						echo esc_html( sprintf( __( 'by %1$s, %2$s', 'woo-wallet' ), $requester ? $requester->display_name : '#' . (int) $row->requested_by, wc_string_to_datetime( $row->date_created )->date_i18n( wc_date_format() . ' ' . wc_time_format() ) ) );
						?>
					</span>
				</td>
				<td>
					<?php if ( $customer ) : ?>
						<?php echo esc_html( $customer->display_name ); ?><br />
						<span class="description"><?php echo esc_html( $customer->user_email ); ?></span><br />
						<?php
						/* translators: %s: current wallet balance */
						echo wp_kses_post( sprintf( __( 'Balance now: %s', 'woo-wallet' ), wc_price( woo_wallet()->wallet->get_wallet_balance( $customer->ID, 'edit' ) ) ) );
						?>
					<?php else : ?>
						#<?php echo (int) $row->customer_id; ?>
					<?php endif; ?>
				</td>
				<td><?php echo wp_kses_post( wc_price( (float) $row->amount, array( 'currency' => $row->currency ) ) ); ?></td>
				<td>
					<?php if ( $row->reason ) : ?>
						<?php echo esc_html( $row->reason ); ?><br />
					<?php endif; ?>
					<?php if ( self::TYPE_WITHDRAWAL === $row->type ) : ?>
						<span class="description">
							<?php echo esc_html( $details['bank_name'] ?? '' ); ?> &middot; <?php echo esc_html( $details['beneficiary_name'] ?? '' ); ?><br />
							<?php echo esc_html( Woo_Wallet_Staff::bank_detail( $details['account_number'] ?? '' ) ); ?>
							<?php if ( ! empty( $details['iban'] ) ) : ?>
								&middot; IBAN <?php echo esc_html( Woo_Wallet_Staff::bank_detail( $details['iban'] ) ); ?>
							<?php endif; ?>
							<br /><?php echo esc_html( $details['phone'] ?? '' ); ?>
						</span>
					<?php endif; ?>
				</td>
				<td>
					<strong><?php echo esc_html( $statuses[ $row->status ] ?? $row->status ); ?></strong>
					<?php if ( $decider ) : ?>
						<br /><span class="description"><?php echo esc_html( $decider->display_name ); ?></span>
					<?php endif; ?>
					<?php if ( $row->decision_note ) : ?>
						<br /><em><?php echo esc_html( $row->decision_note ); ?></em>
					<?php endif; ?>
					<?php if ( $row->failure_reason ) : ?>
						<br /><span style="color:#b32d2e;"><?php echo esc_html( $row->failure_reason ); ?></span>
					<?php endif; ?>
					<?php if ( self::STATUS_APPROVED === $row->status && self::TYPE_WITHDRAWAL === $row->type && $row->result_id && current_user_can( Woo_Wallet_Staff::CAP_VIEW ) ) : ?>
						<br /><a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-wallet-withdrawals&action=view&id=' . (int) $row->result_id ) ); ?>">
							<?php
							/* translators: %d: withdrawal id */
							echo esc_html( sprintf( __( 'Withdrawal #%d', 'woo-wallet' ), (int) $row->result_id ) );
							?>
						</a>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( self::STATUS_PENDING === $row->status && $can_approve && (int) $row->requested_by !== get_current_user_id() ) : ?>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="woo_wallet_approval_decide" />
							<input type="hidden" name="request_id" value="<?php echo (int) $row->id; ?>" />
							<?php wp_nonce_field( 'woo_wallet_approval_decide' ); ?>
							<?php Woo_Wallet_Staff::form_token_field(); ?>
							<input type="text" name="note" class="widefat" placeholder="<?php esc_attr_e( 'Note to the agent (required to reject)', 'woo-wallet' ); ?>" style="margin-bottom:6px;" />
							<button type="submit" name="decision" value="approve" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Approve this request? It will be carried out now, in your name.', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Approve', 'woo-wallet' ); ?></button>
							<button type="submit" name="decision" value="reject" class="button"><?php esc_html_e( 'Reject', 'woo-wallet' ); ?></button>
						</form>
					<?php elseif ( self::STATUS_PENDING === $row->status && (int) $row->requested_by === get_current_user_id() ) : ?>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="woo_wallet_approval_cancel" />
							<input type="hidden" name="request_id" value="<?php echo (int) $row->id; ?>" />
							<?php wp_nonce_field( 'woo_wallet_approval_cancel' ); ?>
							<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Cancel this request?', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Cancel request', 'woo-wallet' ); ?></button>
						</form>
					<?php elseif ( self::STATUS_PROCESSING === $row->status && $can_approve ) : ?>
						<p class="description"><?php esc_html_e( 'This approval was interrupted before it finished. Check the customer\'s transactions and withdrawals to see whether it was carried out, then close it with what you found.', 'woo-wallet' ); ?></p>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="woo_wallet_approval_decide" />
							<input type="hidden" name="request_id" value="<?php echo (int) $row->id; ?>" />
							<?php wp_nonce_field( 'woo_wallet_approval_decide' ); ?>
							<?php Woo_Wallet_Staff::form_token_field(); ?>
							<input type="text" name="note" class="widefat" required placeholder="<?php esc_attr_e( 'What you found', 'woo-wallet' ); ?>" style="margin-bottom:6px;" />
							<button type="submit" name="decision" value="close" class="button"><?php esc_html_e( 'Close request', 'woo-wallet' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		/**
		 * "Pause my emails" for an approver who is a chosen recipient.
		 */
		private function render_pause_toggle() {
			$user_id = get_current_user_id();
			if ( ! in_array( $user_id, self::chosen_recipient_ids(), true ) ) {
				return;
			}
			$paused = self::is_paused( $user_id );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
				<input type="hidden" name="action" value="woo_wallet_approval_pause" />
				<input type="hidden" name="paused" value="<?php echo $paused ? '0' : '1'; ?>" />
				<?php wp_nonce_field( 'woo_wallet_approval_pause' ); ?>
				<span class="description">
					<?php echo $paused ? esc_html__( 'Your new-request emails are paused.', 'woo-wallet' ) : esc_html__( 'You get an email for every new request.', 'woo-wallet' ); ?>
				</span>
				<button type="submit" class="button-link" style="margin-left:6px;"><?php echo $paused ? esc_html__( 'Resume my emails', 'woo-wallet' ) : esc_html__( 'Pause my emails', 'woo-wallet' ); ?></button>
			</form>
			<?php
		}

		/**
		 * The agent's new-request form.
		 */
		private function render_new_form() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$type     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : self::TYPE_WITHDRAWAL;
			$customer = isset( $_GET['customer'] ) ? sanitize_text_field( wp_unslash( $_GET['customer'] ) ) : '';
			$amount   = isset( $_GET['amount'] ) ? (float) $_GET['amount'] : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( is_numeric( $customer ) ) {
				$user     = get_userdata( (int) $customer );
				$customer = $user ? $user->user_email : '';
			}
			$banks = Woo_Wallet_Withdrawal::get_configured_banks();
			?>
			<h1><?php esc_html_e( 'New approval request', 'woo-wallet' ); ?></h1>
			<p class="description" style="max-width:720px;"><?php esc_html_e( 'Nothing changes in the customer\'s wallet until a shop manager or administrator approves this request. You will see the decision under My Requests, and get an email.', 'woo-wallet' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px;">
				<input type="hidden" name="action" value="woo_wallet_approval_create" />
				<?php wp_nonce_field( 'woo_wallet_approval_create' ); ?>
				<?php Woo_Wallet_Staff::form_token_field(); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ww-ar-type"><?php esc_html_e( 'Request', 'woo-wallet' ); ?></label></th>
						<td>
							<select id="ww-ar-type" name="type">
								<?php foreach ( self::types() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ww-ar-customer"><?php esc_html_e( 'Customer email or username', 'woo-wallet' ); ?></label></th>
						<td><input type="text" id="ww-ar-customer" name="customer" class="regular-text" value="<?php echo esc_attr( $customer ); ?>" required /></td>
					</tr>
					<tr>
						<th><label for="ww-ar-amount"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></label></th>
						<td><input type="number" id="ww-ar-amount" name="amount" min="0.01" step="0.01" value="<?php echo esc_attr( $amount ); ?>" required /></td>
					</tr>
					<tr class="ww-ar-withdrawal">
						<th><label for="ww-ar-bank"><?php esc_html_e( 'Bank', 'woo-wallet' ); ?></label></th>
						<td>
							<?php if ( $banks ) : ?>
								<select id="ww-ar-bank" name="bank_name">
									<?php foreach ( $banks as $bank_key => $bank_label ) : ?>
										<option value="<?php echo esc_attr( is_string( $bank_key ) ? $bank_key : $bank_label ); ?>"><?php echo esc_html( $bank_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" id="ww-ar-bank" name="bank_name" class="regular-text" />
							<?php endif; ?>
						</td>
					</tr>
					<tr class="ww-ar-withdrawal">
						<th><label for="ww-ar-beneficiary"><?php esc_html_e( 'Beneficiary name', 'woo-wallet' ); ?></label></th>
						<td><input type="text" id="ww-ar-beneficiary" name="beneficiary_name" class="regular-text" /></td>
					</tr>
					<tr class="ww-ar-withdrawal">
						<th><label for="ww-ar-account"><?php esc_html_e( 'Account number', 'woo-wallet' ); ?></label></th>
						<td><input type="text" id="ww-ar-account" name="account_number" class="regular-text" /></td>
					</tr>
					<tr class="ww-ar-withdrawal">
						<th><label for="ww-ar-iban"><?php esc_html_e( 'IBAN (optional)', 'woo-wallet' ); ?></label></th>
						<td><input type="text" id="ww-ar-iban" name="iban" class="regular-text" /></td>
					</tr>
					<tr class="ww-ar-withdrawal">
						<th><label for="ww-ar-phone"><?php esc_html_e( 'Contact phone', 'woo-wallet' ); ?></label></th>
						<td><input type="text" id="ww-ar-phone" name="phone" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ww-ar-reason"><?php esc_html_e( 'Reason', 'woo-wallet' ); ?></label></th>
						<td>
							<textarea id="ww-ar-reason" name="reason" class="large-text" rows="3"></textarea>
							<p class="description"><?php esc_html_e( 'Required for a credit or debit. For a credit or debit it also becomes the transaction description the customer sees.', 'woo-wallet' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Send for approval', 'woo-wallet' ) ); ?>
			</form>
			<script>
				( function () {
					var type = document.getElementById( 'ww-ar-type' );
					function toggle() {
						var show = 'withdrawal' === type.value;
						document.querySelectorAll( '.ww-ar-withdrawal' ).forEach( function ( row ) {
							row.style.display = show ? '' : 'none';
						} );
					}
					type.addEventListener( 'change', toggle );
					toggle();
				} )();
			</script>
			<?php
		}

		/* ---------------- handlers ---------------- */

		/**
		 * Handle the new-request form.
		 */
		public function handle_create() {
			if ( ! current_user_can( Woo_Wallet_Staff::CAP_REQUEST_APPROVAL ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_approval_create' );
			if ( ! Woo_Wallet_Staff::claim_submitted_form_token() ) {
				$this->redirect( 'error', __( 'This request was already sent.', 'woo-wallet' ) );
			}

			$ref  = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
			$user = '' !== $ref ? get_user_by( 'email', $ref ) : false;
			if ( ! $user && '' !== $ref ) {
				$user = get_user_by( 'login', $ref );
			}
			$details = array();
			foreach ( array( 'bank_name', 'beneficiary_name', 'account_number', 'phone', 'iban' ) as $key ) {
				$details[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			}
			$result = self::create(
				isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '',
				$user ? $user->ID : 0,
				isset( $_POST['amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0,
				$details,
				isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : ''
			);
			if ( is_wp_error( $result ) ) {
				$this->redirect( 'error', $result->get_error_message(), array( 'action' => 'new' ) );
			}
			/* translators: %d: request id */
			$this->redirect( 'success', sprintf( __( 'Request #%d sent for approval.', 'woo-wallet' ), $result ) );
		}

		/**
		 * Handle approve / reject / close.
		 */
		public function handle_decide() {
			if ( ! current_user_can( Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_approval_decide' );
			if ( ! Woo_Wallet_Staff::claim_submitted_form_token() ) {
				$this->redirect( 'error', __( 'This decision was already submitted.', 'woo-wallet' ) );
			}
			$id       = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
			$note     = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
			$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

			if ( 'approve' === $decision ) {
				$result = self::approve( $id, $note );
				/* translators: %d: request id */
				$message = sprintf( __( 'Request #%d approved and carried out.', 'woo-wallet' ), $id );
			} elseif ( 'reject' === $decision ) {
				$result = self::reject( $id, $note );
				/* translators: %d: request id */
				$message = sprintf( __( 'Request #%d rejected.', 'woo-wallet' ), $id );
			} else {
				$result = self::close_interrupted( $id, $note );
				/* translators: %d: request id */
				$message = sprintf( __( 'Request #%d closed.', 'woo-wallet' ), $id );
			}
			if ( is_wp_error( $result ) ) {
				$this->redirect( 'error', $result->get_error_message() );
			}
			$this->redirect( 'success', $message );
		}

		/**
		 * Handle an agent cancelling their own request.
		 */
		public function handle_cancel() {
			check_admin_referer( 'woo_wallet_approval_cancel' );
			$result = self::cancel( isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0 );
			if ( is_wp_error( $result ) ) {
				$this->redirect( 'error', $result->get_error_message() );
			}
			$this->redirect( 'success', __( 'Request cancelled.', 'woo-wallet' ) );
		}

		/**
		 * Handle an approver pausing or resuming their own emails.
		 */
		public function handle_pause() {
			if ( ! current_user_can( Woo_Wallet_Staff::CAP_APPROVE_REQUESTS ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_approval_pause' );
			if ( ! empty( $_POST['paused'] ) ) {
				update_user_meta( get_current_user_id(), self::PAUSE_META, 1 );
				$this->redirect( 'success', __( 'Your new-request emails are paused.', 'woo-wallet' ) );
			}
			delete_user_meta( get_current_user_id(), self::PAUSE_META );
			$this->redirect( 'success', __( 'Your new-request emails are back on.', 'woo-wallet' ) );
		}

		/**
		 * Handle an administrator choosing who gets new-request emails.
		 */
		public function handle_recipients() {
			if ( ! current_user_can( Woo_Wallet_Staff::CAP_MANAGE_SETTINGS ) ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'woo-wallet' ) );
			}
			check_admin_referer( 'woo_wallet_approval_recipients' );
			$approver_ids = wp_list_pluck( self::approvers(), 'ID' );
			$chosen       = isset( $_POST['recipients'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['recipients'] ) ) : array();
			update_option( self::EXCLUDED_OPTION, array_values( array_diff( array_map( 'intval', $approver_ids ), $chosen ) ), false );
			set_transient(
				'woo_wallet_staff_notice_' . get_current_user_id(),
				array(
					'type'    => 'success',
					'message' => __( 'Approval email recipients saved.', 'woo-wallet' ),
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=woo-wallet-staff' ) );
			exit();
		}

		/**
		 * Store a one-shot notice and go back to the approvals screen.
		 *
		 * @param string $type    'success' or 'error'.
		 * @param string $message Message.
		 * @param array  $args    Extra query args.
		 */
		private function redirect( $type, $message, array $args = array() ) {
			set_transient(
				'woo_wallet_approval_notice_' . get_current_user_id(),
				array(
					'type'    => $type,
					'message' => $message,
				),
				MINUTE_IN_SECONDS
			);
			wp_safe_redirect( self::page_url( $args ) );
			exit();
		}
	}
}
