<?php
/**
 * Staff oversight: an audit log of sensitive actions, an immediate alert
 * for the riskiest ones, and a daily digest emailed to administrators.
 *
 * The point is push, not pull: a log nobody opens catches nobody. Every day
 * administrators get the manual balance changes of the last 24 hours and
 * anything that looks off, and a handful of actions (a staff member
 * adjusting their own wallet, deleting or editing transaction history) are
 * reported the moment they happen.
 *
 * @package StandaleneTech
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woo_Wallet_Audit' ) ) {

	/**
	 * Audit log, alerts and digest.
	 */
	class Woo_Wallet_Audit {

		const DIGEST_HOOK = 'woo_wallet_daily_digest_cron';

		const EVENT_SELF_ADJUSTMENT     = 'self_adjustment';
		const EVENT_TRANSACTION_DELETED = 'transaction_deleted';
		const EVENT_TRANSACTION_EDITED  = 'transaction_edited';
		const EVENT_TRANSACTIONS_PURGED = 'transactions_purged';
		const EVENT_CASHBACK_CHANGED    = 'cashback_changed';
		const EVENT_STAFF_CHANGED       = 'staff_changed';
		const EVENT_LARGE_CONFIRMED     = 'large_adjustment_confirmed';

		/**
		 * Ledger categories that are a person changing a balance by hand.
		 */
		const MANUAL_CATEGORIES = array( 'adjustment', 'goodwill' );

		/**
		 * Hook up.
		 */
		public function __construct() {
			add_action( 'woo_wallet_transaction_deleted', array( __CLASS__, 'on_transaction_deleted' ), 10, 3 );
			add_action( 'woo_wallet_transaction_details_updated', array( __CLASS__, 'on_transaction_edited' ), 10, 3 );
			add_action( 'woo_wallet_user_transactions_purged', array( __CLASS__, 'on_transactions_purged' ), 10, 5 );
			add_action( 'woo_wallet_staff_self_adjustment', array( __CLASS__, 'on_self_adjustment' ), 10, 5 );
			add_action( 'woo_wallet_staff_agent_saved', array( __CLASS__, 'on_agent_saved' ), 10, 4 );
			add_action( 'woo_wallet_staff_agent_removed', array( __CLASS__, 'on_agent_removed' ), 10, 2 );
			add_action( 'woo_wallet_staff_level_saved', array( __CLASS__, 'on_level_saved' ), 10, 3 );
			add_action( 'woo_wallet_staff_accountant_saved', array( __CLASS__, 'on_accountant_changed' ), 10, 1 );
			add_action( 'woo_wallet_staff_accountant_removed', array( __CLASS__, 'on_accountant_changed' ), 10, 1 );

			add_action( 'init', array( __CLASS__, 'maybe_schedule_digest' ) );
			add_action( self::DIGEST_HOOK, array( __CLASS__, 'send_digest' ) );
			add_action( 'woo_wallet_deactivated', array( __CLASS__, 'unschedule_digest' ) );
		}

		/**
		 * Table name.
		 *
		 * @return string
		 */
		public static function table() {
			global $wpdb;
			return $wpdb->base_prefix . 'woo_wallet_audit_log';
		}

		/**
		 * Events that are emailed to administrators the moment they happen.
		 *
		 * @return string[]
		 */
		public static function alert_events() {
			return (array) apply_filters(
				'woo_wallet_audit_alert_events',
				array( self::EVENT_SELF_ADJUSTMENT, self::EVENT_TRANSACTION_DELETED, self::EVENT_TRANSACTION_EDITED, self::EVENT_TRANSACTIONS_PURGED )
			);
		}

		/**
		 * Human labels for events.
		 *
		 * @return string[]
		 */
		public static function event_labels() {
			return array(
				self::EVENT_SELF_ADJUSTMENT     => __( 'Adjusted their own wallet', 'woo-wallet' ),
				self::EVENT_TRANSACTION_DELETED => __( 'Deleted a transaction', 'woo-wallet' ),
				self::EVENT_TRANSACTION_EDITED  => __( 'Edited a transaction description', 'woo-wallet' ),
				self::EVENT_TRANSACTIONS_PURGED => __( 'Deleted a customer\'s transaction log', 'woo-wallet' ),
				self::EVENT_CASHBACK_CHANGED    => __( 'Changed a cashback rule', 'woo-wallet' ),
				self::EVENT_STAFF_CHANGED       => __( 'Changed staff access', 'woo-wallet' ),
				self::EVENT_LARGE_CONFIRMED     => __( 'Confirmed a large adjustment', 'woo-wallet' ),
			);
		}

		/**
		 * Record an event, and alert administrators if it is one of the
		 * alert_events().
		 *
		 * @param string $event       One of the EVENT_* constants.
		 * @param int    $customer_id Wallet owner it concerns, if any.
		 * @param int    $object_id   Transaction / product / term / user id it concerns, if any.
		 * @param float  $amount      Amount involved, if any.
		 * @param array  $details     Anything else worth keeping.
		 * @return int Audit row id.
		 */
		public static function record( $event, $customer_id = 0, $object_id = 0, $amount = 0.0, array $details = array() ) {
			global $wpdb;
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'event'        => $event,
					'actor_id'     => get_current_user_id(),
					'customer_id'  => (int) $customer_id,
					'object_id'    => (int) $object_id,
					'amount'       => (float) $amount,
					'details'      => wp_json_encode( $details ),
					'date_created' => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%d', '%d', '%f', '%s', '%s' )
			);
			$id = (int) $wpdb->insert_id;
			if ( $id && in_array( $event, self::alert_events(), true ) ) {
				self::send_email( 'Woo_Wallet_Email_Staff_Alert', 'class-woo-wallet-email-staff-alert.php', $id );
			}
			do_action( 'woo_wallet_audit_recorded', $id, $event );
			return $id;
		}

		/**
		 * One audit row, details decoded.
		 *
		 * @param int $id Row id.
		 * @return object|null
		 */
		public static function get( $id ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			if ( $row ) {
				$row->details = (array) json_decode( (string) $row->details, true );
			}
			return $row ? $row : null;
		}

		/**
		 * Audit rows in a time range, newest first.
		 *
		 * @param string $since MySQL datetime (site time).
		 * @param string $until MySQL datetime (site time).
		 * @return object[]
		 */
		public static function get_events( $since, $until ) {
			global $wpdb;
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE date_created >= %s AND date_created <= %s ORDER BY id DESC LIMIT 500', $since, $until ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $rows as $row ) {
				$row->details = (array) json_decode( (string) $row->details, true );
			}
			return $rows;
		}

		/* ---------------- event listeners ---------------- */

		/**
		 * A transaction was deleted.
		 *
		 * @param int  $transaction_id Transaction id.
		 * @param int  $user_id        Wallet owner.
		 * @param bool $hard           Removed for good rather than hidden.
		 */
		public static function on_transaction_deleted( $transaction_id, $user_id, $hard ) {
			self::record( self::EVENT_TRANSACTION_DELETED, $user_id, $transaction_id, 0, array( 'hard' => (bool) $hard ) );
		}

		/**
		 * A transaction's description was rewritten.
		 *
		 * @param int    $transaction_id Transaction id.
		 * @param int    $user_id        Wallet owner.
		 * @param string $details        New description.
		 */
		public static function on_transaction_edited( $transaction_id, $user_id, $details ) {
			self::record( self::EVENT_TRANSACTION_EDITED, $user_id, $transaction_id, 0, array( 'new_details' => wp_strip_all_tags( (string) $details ) ) );
		}

		/**
		 * A customer's whole transaction log was deleted.
		 *
		 * @param int    $user_id          Wallet owner.
		 * @param string $delete_mode      'soft' or 'hard'.
		 * @param string $balance_handling 'keep' or 'wipe'.
		 * @param float  $pre_balance      Balance before.
		 */
		public static function on_transactions_purged( $user_id, $delete_mode, $balance_handling, $pre_balance ) {
			self::record(
				self::EVENT_TRANSACTIONS_PURGED,
				$user_id,
				0,
				(float) $pre_balance,
				array(
					'mode'             => $delete_mode,
					'balance_handling' => $balance_handling,
				)
			);
		}

		/**
		 * A staff member adjusted their own wallet (administrators only —
		 * everyone else is refused).
		 *
		 * @param int    $transaction_id Transaction id.
		 * @param int    $staff_id       Staff member (and wallet owner).
		 * @param string $type           'credit' or 'debit'.
		 * @param float  $amount         Amount.
		 * @param string $reason         The reason they had to give.
		 */
		public static function on_self_adjustment( $transaction_id, $staff_id, $type, $amount, $reason ) {
			self::record(
				self::EVENT_SELF_ADJUSTMENT,
				$staff_id,
				$transaction_id,
				$amount,
				array(
					'type'   => $type,
					'reason' => wp_strip_all_tags( (string) $reason ),
				)
			);
		}

		/**
		 * A support agent was added or changed.
		 *
		 * @param int        $user_id Agent.
		 * @param string     $level   Level key.
		 * @param array|null $limits  Personal limits.
		 */
		public static function on_agent_saved( $user_id, $level, $limits ) {
			self::record( self::EVENT_STAFF_CHANGED, 0, $user_id, 0, array( 'change' => 'agent_saved', 'level' => $level, 'limits' => $limits ) );
		}

		/**
		 * A support agent was removed.
		 *
		 * @param int $user_id Agent.
		 */
		public static function on_agent_removed( $user_id ) {
			self::record( self::EVENT_STAFF_CHANGED, 0, $user_id, 0, array( 'change' => 'agent_removed' ) );
		}

		/**
		 * A level was changed.
		 *
		 * @param string $key   Level key.
		 * @param array  $level New level.
		 */
		public static function on_level_saved( $key, $level ) {
			self::record( self::EVENT_STAFF_CHANGED, 0, 0, 0, array( 'change' => 'level_saved', 'level' => $key, 'settings' => $level ) );
		}

		/**
		 * An accountant was added or removed.
		 *
		 * @param int $user_id User.
		 */
		public static function on_accountant_changed( $user_id ) {
			self::record( self::EVENT_STAFF_CHANGED, 0, $user_id, 0, array( 'change' => 'woo_wallet_staff_accountant_saved' === current_action() ? 'accountant_added' : 'accountant_removed' ) );
		}

				/* ---------------- manual adjustments ---------------- */

		/**
		 * Balance changes a person made by hand in a time range.
		 *
		 * @param string $since MySQL datetime (site time).
		 * @param string $until MySQL datetime (site time).
		 * @return object[] transaction_id, user_id, type, amount, currency, details, created_by, date, category.
		 */
		public static function manual_adjustments( $since, $until ) {
			global $wpdb;
			$in = implode( ',', array_fill( 0, count( self::MANUAL_CATEGORIES ), '%s' ) );
			return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT transaction_id, user_id, type, amount, currency, details, created_by, date, category FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE category IN ($in) AND date >= %s AND date <= %s ORDER BY date ASC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( self::MANUAL_CATEGORIES, array( $since, $until ) )
				)
			);
		}

		/**
		 * Normalise an email so aliases of the same mailbox compare equal:
		 * lower case, "+tag" dropped, and dots dropped for Gmail.
		 *
		 * @param string $email Email.
		 * @return string
		 */
		public static function normalize_email( $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( false === strpos( $email, '@' ) ) {
				return $email;
			}
			list( $local, $domain ) = explode( '@', $email, 2 );
			$local                  = explode( '+', $local )[0];
			if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
				$local  = str_replace( '.', '', $local );
				$domain = 'gmail.com';
			}
			return $local . '@' . $domain;
		}

		/**
		 * Normalise a phone number to its last 10 digits, so 010…, +2010…
		 * and 002010… compare equal.
		 *
		 * @param string $phone Phone.
		 * @return string
		 */
		public static function normalize_phone( $phone ) {
			$digits = preg_replace( '/\D+/', '', (string) $phone );
			return strlen( $digits ) > 10 ? substr( $digits, -10 ) : $digits;
		}

		/**
		 * Emails and phone numbers that identify a user.
		 *
		 * @param int $user_id User id.
		 * @return array {emails: string[], phones: string[]}
		 */
		private static function identity( $user_id ) {
			$user   = get_userdata( $user_id );
			$emails = array_filter( array( $user ? $user->user_email : '', get_user_meta( $user_id, 'billing_email', true ) ) );
			$phones = array_filter( array( get_user_meta( $user_id, 'billing_phone', true ), get_user_meta( $user_id, 'shipping_phone', true ) ) );
			return array(
				'emails' => array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_email' ), $emails ) ) ) ),
				'phones' => array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_phone' ), $phones ) ) ) ),
			);
		}

		/**
		 * Things in a set of manual adjustments that deserve a second look.
		 *
		 * @param object[] $adjustments From manual_adjustments().
		 * @return array[] {rule, staff_id, customer_id, transaction_id, message}
		 */
		public static function suspicious( array $adjustments ) {
			$flags      = array();
			$burst_size = (int) apply_filters( 'woo_wallet_audit_burst_size', 10 );
			$by_staff   = array();
			$identities = array();

			foreach ( $adjustments as $row ) {
				$staff_id    = (int) $row->created_by;
				$customer_id = (int) $row->user_id;
				if ( ! $staff_id ) {
					continue;
				}
				$by_staff[ $staff_id ][] = strtotime( $row->date );

				if ( $staff_id === $customer_id ) {
					$flags[] = array(
						'rule'           => 'self',
						'staff_id'       => $staff_id,
						'customer_id'    => $customer_id,
						'transaction_id' => (int) $row->transaction_id,
						'message'        => __( 'Adjusted their own wallet.', 'woo-wallet' ),
					);
					continue;
				}

				$customer = get_userdata( $customer_id );
				if ( $customer && strtotime( $customer->user_registered ) > strtotime( $row->date ) - DAY_IN_SECONDS - ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) {
					$flags[] = array(
						'rule'           => 'new_account',
						'staff_id'       => $staff_id,
						'customer_id'    => $customer_id,
						'transaction_id' => (int) $row->transaction_id,
						'message'        => __( 'Credited or debited an account created less than a day before.', 'woo-wallet' ),
					);
				}

				$identities[ $staff_id ]    = $identities[ $staff_id ] ?? self::identity( $staff_id );
				$identities[ $customer_id ] = $identities[ $customer_id ] ?? self::identity( $customer_id );
				$same_email                 = array_intersect( $identities[ $staff_id ]['emails'], $identities[ $customer_id ]['emails'] );
				$same_phone                 = array_intersect( $identities[ $staff_id ]['phones'], $identities[ $customer_id ]['phones'] );
				if ( $same_email || $same_phone ) {
					$flags[] = array(
						'rule'           => 'identity_match',
						'staff_id'       => $staff_id,
						'customer_id'    => $customer_id,
						'transaction_id' => (int) $row->transaction_id,
						'message'        => $same_email
							? __( 'The customer\'s email matches the staff member\'s own.', 'woo-wallet' )
							: __( 'The customer\'s phone number matches the staff member\'s own.', 'woo-wallet' ),
					);
				}
			}

			foreach ( $by_staff as $staff_id => $times ) {
				sort( $times );
				$count = count( $times );
				for ( $start = 0, $end = 0; $end < $count; $end++ ) {
					while ( $times[ $end ] - $times[ $start ] > HOUR_IN_SECONDS ) {
						++$start;
					}
					if ( $end - $start + 1 >= $burst_size ) {
						$flags[] = array(
							'rule'           => 'burst',
							'staff_id'       => $staff_id,
							'customer_id'    => 0,
							'transaction_id' => 0,
							/* translators: %d: number of adjustments */
							'message'        => sprintf( __( 'Made %d or more manual adjustments within an hour.', 'woo-wallet' ), $burst_size ),
						);
						break;
					}
				}
			}
			return $flags;
		}

		/**
		 * Everything the digest reports, for a time range.
		 *
		 * @param string $since MySQL datetime (site time).
		 * @param string $until MySQL datetime (site time).
		 * @return array
		 */
		public static function digest_data( $since, $until ) {
			$adjustments = self::manual_adjustments( $since, $until );
			$staff       = array();
			foreach ( $adjustments as $row ) {
				$id = (int) $row->created_by;
				if ( ! isset( $staff[ $id ] ) ) {
					$staff[ $id ] = array(
						'count'  => 0,
						'credit' => 0.0,
						'debit'  => 0.0,
					);
				}
				++$staff[ $id ]['count'];
				$staff[ $id ][ 'debit' === $row->type ? 'debit' : 'credit' ] += (float) $row->amount;
			}
			return array(
				'since'       => $since,
				'until'       => $until,
				'adjustments' => $adjustments,
				'staff'       => $staff,
				'events'      => self::get_events( $since, $until ),
				'suspicious'  => self::suspicious( $adjustments ),
			);
		}

		/* ---------------- digest schedule ---------------- */

		/**
		 * Schedule the digest for 9:00 site time if it isn't scheduled.
		 */
		public static function maybe_schedule_digest() {
			if ( wp_next_scheduled( self::DIGEST_HOOK ) ) {
				return;
			}
			$hour = (int) apply_filters( 'woo_wallet_digest_hour', 9 );
			$next = new DateTime( 'today', wp_timezone() );
			$next->setTime( $hour, 0 );
			if ( $next->getTimestamp() <= time() ) {
				$next->modify( '+1 day' );
			}
			wp_schedule_event( $next->getTimestamp(), 'daily', self::DIGEST_HOOK );
		}

		/**
		 * Unschedule on deactivation.
		 */
		public static function unschedule_digest() {
			wp_clear_scheduled_hook( self::DIGEST_HOOK );
		}

		/**
		 * Send the digest for the last 24 hours. Sent every day, even when
		 * nothing happened, so that its absence is itself noticeable.
		 */
		public static function send_digest() {
			$until = current_time( 'mysql' );
			$since = gmdate( 'Y-m-d H:i:s', strtotime( $until ) - DAY_IN_SECONDS );
			self::send_email( 'Woo_Wallet_Email_Daily_Digest', 'class-woo-wallet-email-daily-digest.php', self::digest_data( $since, $until ) );
		}

		/* ---------------- recipients & sending ---------------- */

		/**
		 * Email addresses of every administrator who can see the wallet,
		 * optionally leaving one user out.
		 *
		 * @param int $except User id to leave out.
		 * @return string[]
		 */
		public static function administrator_emails( $except = 0 ) {
			$emails = array();
			foreach ( get_users( array( 'capability__in' => array( 'manage_options' ) ) ) as $user ) {
				if ( (int) $user->ID !== (int) $except && user_can( $user, Woo_Wallet_Staff::CAP_MANAGE_SETTINGS ) ) {
					$emails[] = $user->user_email;
				}
			}
			return array_values( array_unique( array_filter( $emails ) ) );
		}

		/**
		 * Trigger one of the wallet's emails, loading it directly if the
		 * WooCommerce mailer was built before the plugin registered its
		 * email classes.
		 *
		 * @param string $class Email class.
		 * @param string $file  File under includes/emails/.
		 * @param mixed  $arg   Argument for trigger().
		 */
		public static function send_email( $class, $file, $arg ) {
			if ( ! function_exists( 'WC' ) ) {
				return;
			}
			$emails = WC()->mailer()->get_emails();
			$email  = $emails[ $class ] ?? null;
			if ( ! $email ) {
				$email = include WOO_WALLET_ABSPATH . 'includes/emails/' . $file;
			}
			if ( $email instanceof WC_Email ) {
				$email->trigger( $arg );
			}
		}

		/* ---------------- where a balance came from ---------------- */

		/**
		 * How much of a customer's balance came from manual credits by staff
		 * in the last few days — shown when reviewing their withdrawal,
		 * because that is where credit added to an accomplice's wallet
		 * actually leaves the store.
		 *
		 * @param int   $customer_id Customer.
		 * @param int   $days        How far back to look.
		 * @param float $reserved    Amount already taken out of the balance
		 *                           for the withdrawal under review, added
		 *                           back so the share is of what the customer
		 *                           had before requesting it.
		 * @return array {total: float, share: float (0-100), balance: float, by_staff: array<int, float>, days: int}
		 */
		public static function manual_credit_sources( $customer_id, $days = 7, $reserved = 0.0 ) {
			$days  = (int) apply_filters( 'woo_wallet_balance_source_days', $days );
			$until = current_time( 'mysql' );
			$since = gmdate( 'Y-m-d H:i:s', strtotime( $until ) - $days * DAY_IN_SECONDS );
			global $wpdb;
			$in   = implode( ',', array_fill( 0, count( self::MANUAL_CATEGORIES ), '%s' ) );
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT created_by, SUM( amount ) AS total FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE user_id = %d AND type = 'credit' AND deleted = 0 AND created_by <> user_id AND category IN ($in) AND date >= %s GROUP BY created_by", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( (int) $customer_id ), self::MANUAL_CATEGORIES, array( $since ) )
				)
			);
			$by_staff = array();
			$total    = 0.0;
			foreach ( $rows as $row ) {
				$by_staff[ (int) $row->created_by ] = (float) $row->total;
				$total                             += (float) $row->total;
			}
			$balance = (float) woo_wallet()->wallet->get_wallet_balance( $customer_id, 'edit' ) + (float) $reserved;
			return array(
				'total'    => $total,
				'share'    => $balance > 0 ? min( 100.0, round( $total / $balance * 100, 1 ) ) : ( $total > 0 ? 100.0 : 0.0 ),
				'balance'  => $balance,
				'by_staff' => $by_staff,
				'days'     => $days,
			);
		}
	}
}
