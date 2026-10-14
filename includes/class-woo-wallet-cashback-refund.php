<?php
/**
 * Cashback on refunds: deduct it from the refund itself.
 *
 * Taking cashback back from the customer's wallet when an order is returned
 * depends on the customer still having that balance — and they may have spent
 * it, withdrawn it, or have it tied up in a pending withdrawal. Deducting it
 * from the refund instead does not depend on the wallet at all:
 *
 *   paid 100, earned 10 cashback, returns the order
 *   → 90 goes back to the customer, 10 is recorded as a separate
 *     "Cashback adjustment" refund that is not paid out.
 *
 * Whether or not they spent the 10, what they get out of the order is
 * exactly the 100 they paid.
 *
 * How it hooks into WooCommerce:
 *  - `woocommerce_create_refund` fires before a refund is saved and before the
 *    payment gateway is asked to pay it, so the amount can be reduced there —
 *    for the order screen, the REST API and this plugin's own refund-to-wallet
 *    alike. If the gateway then fails, WooCommerce discards the refund and
 *    nothing of ours had been recorded yet.
 *  - `woocommerce_order_refunded` fires once the refund succeeded; only then is
 *    the cashback adjustment recorded as its own refund, so the order's refunded
 *    total adds up to what was asked for and it cannot be refunded again.
 *
 * @package StandaleneTech
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woo_Wallet_Cashback_Refund' ) ) {

	/**
	 * Cashback deduction from refunds.
	 */
	class Woo_Wallet_Cashback_Refund {

		/**
		 * Order meta: total cashback deducted from refunds so far.
		 */
		const ORDER_RECOVERED_META = '_woo_wallet_cashback_recovered';

		/**
		 * Order meta: map of refund id => {adjustment refund id, amount}.
		 */
		const ORDER_PAIRS_META = '_woo_wallet_cashback_recovery_pairs';

		/**
		 * Refund meta: the cashback deducted from this refund.
		 */
		const REFUND_RECOVERY_META = '_woo_wallet_cashback_recovery';

		/**
		 * Refund meta: the amount that was asked to be refunded, before deduction.
		 */
		const REFUND_REQUESTED_META = '_woo_wallet_cashback_requested_amount';

		/**
		 * Refund meta (on a refund that paid out): the adjustment refund paired with it.
		 */
		const REFUND_ADJUSTMENT_META = '_woo_wallet_cashback_adjustment_refund_id';

		/**
		 * Refund meta (on the adjustment refund): the refund it belongs to.
		 */
		const ADJUSTMENT_FOR_META = '_woo_wallet_cashback_adjustment_for';

		/**
		 * True while the adjustment refund itself is being created, so it is
		 * neither reduced again nor treated as a customer refund.
		 *
		 * @var bool
		 */
		private static $creating_adjustment = false;

		/**
		 * Cashback held back from the refund being created, per order, until
		 * that refund has finished.
		 *
		 * @var array<int, float>
		 */
		private static $pending = array();

		/**
		 * Per order: `true` while WooCommerce's own "Order fully refunded"
		 * refund (see deduct_from_refund()) is being created, then that
		 * refund's id.
		 *
		 * @var array<int, true|int>
		 */
		private static $auto = array();

		/**
		 * The refund the cashback adjustment being created belongs to — set
		 * on the adjustment as it is created, so every other hook that runs
		 * for it already sees it as a bookkeeping refund.
		 *
		 * @var int
		 */
		private static $adjusting_for = 0;

		/**
		 * Per order: the refund the cashback was deducted from.
		 *
		 * @var array<int, int>
		 */
		private static $pending_refund = array();

		/**
		 * Hook up.
		 */
		public static function init() {
			add_action( 'woocommerce_create_refund', array( __CLASS__, 'deduct_from_refund' ), 10, 2 );
			add_action( 'woocommerce_order_refunded', array( __CLASS__, 'record_adjustment' ), 5, 2 );
			add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'refund_deleted' ), 10, 2 );
		}

		/**
		 * Whether refunds are deducted from.
		 *
		 * @return bool
		 */
		public static function enabled() {
			return 'on' === woo_wallet()->settings_api->get_option( 'cashback_deduct_from_refund', '_wallet_settings_credit', 'no' )
				&& 'on' === woo_wallet()->settings_api->get_option( 'is_enable_cashback_reward_program', '_wallet_settings_credit', 'off' );
		}

		/**
		 * Whether a refund is the cashback adjustment itself.
		 *
		 * @param WC_Order_Refund|false $refund Refund.
		 * @return bool
		 */
		public static function is_adjustment( $refund ) {
			return $refund instanceof WC_Order_Refund && '' !== (string) $refund->get_meta( self::ADJUSTMENT_FOR_META );
		}

		/**
		 * The smallest amount the store's price decimals can express.
		 *
		 * @return float
		 */
		private static function unit() {
			return 1 / pow( 10, wc_get_price_decimals() );
		}

		/**
		 * How much cashback to deduct from a refund of $requested.
		 *
		 * Prorated: cashback × (refunded part of the order). The refund that
		 * finishes refunding the order takes whatever cashback is left, so
		 * rounding never leaves a remainder behind. Never more than the
		 * cashback still outstanding on the order, and never so much that
		 * nothing is left to pay out.
		 *
		 * @param WC_Order        $order     Order.
		 * @param float           $requested Amount being refunded.
		 * @param WC_Order_Refund $refund    The refund being created.
		 * @return float
		 */
		public static function calculate_recovery( $order, $requested, $refund ) {
			$decimals = wc_get_price_decimals();
			$issued   = (float) get_total_order_cashback_amount( $order->get_id() );
			$total    = (float) $order->get_total( 'edit' );
			if ( $issued <= 0 || $total <= 0 || $requested <= 0 ) {
				return 0.0;
			}
			$left = round( $issued - (float) $order->get_meta( self::ORDER_RECOVERED_META ), $decimals );
			if ( $left <= 0 ) {
				return 0.0;
			}

			// What would still be refundable after this refund. By the time
			// woocommerce_create_refund fires, WooCommerce has already stored the
			// refund, so the order's remaining amount no longer includes it.
			$remaining_after = (float) $order->get_remaining_refund_amount();
			if ( ! $refund->get_id() ) {
				$remaining_after -= $requested;
			}
			$is_final = $remaining_after < ( self::unit() / 2 );
			$recovery = $is_final ? $left : round( $issued * min( 1.0, $requested / $total ), $decimals );

			/**
			 * Filter the cashback deducted from a refund.
			 *
			 * Shared with the older refund clawback so a marketplace that zeroes
			 * it for vendor-side refunds (platform-funded cashback) keeps doing so.
			 *
			 * @param float           $recovery Cashback to deduct.
			 * @param WC_Order        $order    Order.
			 * @param WC_Order_Refund $refund   The refund being created.
			 */
			$recovery = (float) apply_filters( 'woo_wallet_cashback_refund_clawback_amount', $recovery, $order, $refund );

			return max( 0.0, min( $recovery, $left, round( $requested - self::unit(), $decimals ) ) );
		}

		/**
		 * The order's refund => adjustment pairs.
		 *
		 * @param WC_Order $order Order.
		 * @return array
		 */
		private static function pairs( $order ) {
			$pairs = $order->get_meta( self::ORDER_PAIRS_META );
			return is_array( $pairs ) ? $pairs : array();
		}

		/**
		 * Reduce a refund by the cashback earned on the part being refunded.
		 *
		 * @param WC_Order_Refund $refund The refund, already stored by WooCommerce but not yet paid out.
		 * @param array           $args   wc_create_refund() arguments.
		 */
		public static function deduct_from_refund( $refund, $args ) {
			if ( self::$creating_adjustment ) {
				$refund->update_meta_data( self::ADJUSTMENT_FOR_META, self::$adjusting_for );
				return;
			}
			if ( ! self::enabled() ) {
				return;
			}
			$order_id = (int) ( $refund->get_parent_id() ? $refund->get_parent_id() : ( $args['order_id'] ?? 0 ) );
			$amount   = round( (float) $refund->get_amount(), wc_get_price_decimals() );

			// Reducing a refund that was meant to finish refunding the order makes
			// WooCommerce mark the order refunded and fill in what is left with a
			// refund of its own ("Order fully refunded"). That remainder is exactly
			// the cashback adjustment — adopt it rather than deduct from it, and
			// don't send the customer a second refund email for it.
			if ( isset( self::$pending[ $order_id ] ) && empty( $args['refund_payment'] ) && empty( $args['line_items'] ) && abs( $amount - self::$pending[ $order_id ] ) < self::unit() ) {
				self::$auto[ $order_id ] = true;
				$refund->update_meta_data( self::ADJUSTMENT_FOR_META, (int) ( self::$pending_refund[ $order_id ] ?? 0 ) );
				self::silence_refund_emails( true );
				return;
			}
			unset( self::$pending[ $order_id ], self::$auto[ $order_id ], self::$pending_refund[ $order_id ] );

			$order = wc_get_order( $order_id );
			if ( ! $order || ! $order->get_customer_id() ) {
				return;
			}
			$recovery = self::calculate_recovery( $order, $amount, $refund );
			if ( $recovery <= 0 ) {
				return;
			}
			$paid_out = round( $amount - $recovery, wc_get_price_decimals() );

			$refund->set_amount( $paid_out );
			$refund->set_total( -1 * $paid_out );
			$refund->update_meta_data( self::REFUND_RECOVERY_META, $recovery );
			$refund->update_meta_data( self::REFUND_REQUESTED_META, $amount );
			self::$pending[ $order_id ]        = $recovery;
			self::$pending_refund[ $order_id ] = (int) $refund->get_id();
		}

		/**
		 * Stop (or resume) WooCommerce's customer refund emails.
		 *
		 * @param bool $silence Whether to silence them.
		 */
		private static function silence_refund_emails( $silence ) {
			foreach ( array( 'customer_refunded_order', 'customer_partially_refunded_order' ) as $id ) {
				if ( $silence ) {
					add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 99 );
				} else {
					remove_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 99 );
				}
			}
		}

		/**
		 * Once a refund has gone through, record the cashback that was held
		 * back as its own refund (never paid out), so the order's refunded total
		 * matches what was asked for and the held-back amount cannot be refunded
		 * again.
		 *
		 * @param int $order_id  Order id.
		 * @param int $refund_id Refund id.
		 */
		public static function record_adjustment( $order_id, $refund_id ) {
			if ( self::$creating_adjustment ) {
				return;
			}
			// WooCommerce's own remainder refund has just finished (see
			// deduct_from_refund()): remember it and let the emails flow again.
			if ( true === ( self::$auto[ $order_id ] ?? null ) ) {
				self::$auto[ $order_id ] = (int) $refund_id;
				self::silence_refund_emails( false );
				return;
			}
			$refund = wc_get_order( $refund_id );
			if ( ! $refund instanceof WC_Order_Refund ) {
				return;
			}
			$recovery = (float) $refund->get_meta( self::REFUND_RECOVERY_META );
			if ( $recovery <= 0 || $refund->get_meta( self::REFUND_ADJUSTMENT_META ) ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$reason     = sprintf(
				/* translators: %d: refund id */
				__( 'Cashback adjustment for refund #%d — deducted from the refund, not paid out', 'woo-wallet' ),
				$refund_id
			);
			$adopted_id = isset( self::$auto[ $order_id ] ) && is_int( self::$auto[ $order_id ] ) ? self::$auto[ $order_id ] : 0;
			unset( self::$auto[ $order_id ], self::$pending[ $order_id ], self::$pending_refund[ $order_id ] );
			self::silence_refund_emails( false );

			if ( $adopted_id && wc_get_order( $adopted_id ) instanceof WC_Order_Refund ) {
				// WooCommerce already recorded the remainder; label it.
				$adjustment = wc_get_order( $adopted_id );
				$adjustment->set_reason( $reason );
				$adjustment->save();
			} else {
				// The customer already gets the refund email for the refund itself.
				self::silence_refund_emails( true );
				self::$creating_adjustment = true;
				self::$adjusting_for       = (int) $refund_id;
				try {
					$adjustment = wc_create_refund(
						array(
							'amount'         => $recovery,
							'reason'         => $reason,
							'order_id'       => $order_id,
							'refund_payment' => false,
							'restock_items'  => false,
						)
					);
				} finally {
					self::$creating_adjustment = false;
					self::$adjusting_for       = 0;
					self::silence_refund_emails( false );
				}
			}

			// Reload: creating the adjustment saved the order.
			$order     = wc_get_order( $order_id );
			$recovered = round( (float) $order->get_meta( self::ORDER_RECOVERED_META ) + $recovery, wc_get_price_decimals() );
			$order->update_meta_data( self::ORDER_RECOVERED_META, $recovered );

			$price_args = array( 'currency' => $order->get_currency( 'edit' ) );
			if ( is_wp_error( $adjustment ) ) {
				// The customer was paid less, as intended; only the bookkeeping
				// refund failed. Say so, so the order total is not misread.
				$order->add_order_note(
					sprintf(
						/* translators: 1: cashback amount, 2: refund id, 3: error */
						__( 'Cashback adjustment: %1$s was held back from refund #%2$d, but recording it as a refund failed (%3$s). The customer was paid correctly; the order may still show that amount as refundable — do not refund it.', 'woo-wallet' ),
						wc_price( $recovery, $price_args ),
						$refund_id,
						$adjustment->get_error_message()
					)
				);
				$order->update_meta_data( '_woo_wallet_cashback_adjustment_failed', 'yes' );
				$order->save();
				return;
			}

			$pairs               = self::pairs( $order );
			$pairs[ $refund_id ] = array(
				'adjustment' => (int) $adjustment->get_id(),
				'amount'     => $recovery,
			);
			$order->update_meta_data( self::ORDER_PAIRS_META, $pairs );
			$order->add_order_note(
				sprintf(
					/* translators: 1: cashback amount, 2: refund id, 3: amount paid out, 4: cashback earned on the order */
					__( 'Cashback adjustment: %1$s deducted from refund #%2$d (the cashback earned on the refunded part). %3$s was refunded to the customer. Cashback earned on this order: %4$s.', 'woo-wallet' ),
					wc_price( $recovery, $price_args ),
					$refund_id,
					wc_price( (float) $refund->get_amount(), $price_args ),
					wc_price( (float) get_total_order_cashback_amount( $order_id ), $price_args )
				)
			);
			$order->save();

			$refund->update_meta_data( self::REFUND_ADJUSTMENT_META, (int) $adjustment->get_id() );
			$refund->save();
			$adjustment->update_meta_data( self::ADJUSTMENT_FOR_META, (int) $refund_id );
			$adjustment->save();
		}

		/**
		 * A refund was deleted from the order screen: remove its cashback
		 * adjustment with it, and give the deducted cashback back to the
		 * order's tally. Deleting just the adjustment is allowed (WooCommerce
		 * offers it) but is logged — it makes that amount refundable again.
		 *
		 * @param int $refund_id Deleted refund id.
		 * @param int $order_id  Order id.
		 */
		public static function refund_deleted( $refund_id, $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			$pairs = self::pairs( $order );
			if ( isset( $pairs[ $refund_id ] ) ) {
				$pair       = $pairs[ $refund_id ];
				$adjustment = wc_get_order( (int) $pair['adjustment'] );
				if ( $adjustment instanceof WC_Order_Refund ) {
					$adjustment->delete( true );
				}
				$order->update_meta_data( self::ORDER_RECOVERED_META, max( 0.0, round( (float) $order->get_meta( self::ORDER_RECOVERED_META ) - (float) $pair['amount'], wc_get_price_decimals() ) ) );
				unset( $pairs[ $refund_id ] );
				$order->update_meta_data( self::ORDER_PAIRS_META, $pairs );
				$order->add_order_note(
					sprintf(
						/* translators: 1: refund id, 2: cashback amount */
						__( 'Refund #%1$d was deleted, and its cashback adjustment of %2$s with it.', 'woo-wallet' ),
						$refund_id,
						wc_price( (float) $pair['amount'], array( 'currency' => $order->get_currency( 'edit' ) ) )
					)
				);
				$order->save();
				return;
			}
			foreach ( $pairs as $paid_refund_id => $pair ) {
				if ( (int) $pair['adjustment'] !== (int) $refund_id ) {
					continue;
				}
				$order->add_order_note(
					sprintf(
						/* translators: 1: adjustment refund id, 2: cashback amount, 3: refund id */
						__( 'The cashback adjustment refund #%1$d (%2$s, held back from refund #%3$d) was deleted on its own. That amount can be refunded again.', 'woo-wallet' ),
						$refund_id,
						wc_price( (float) $pair['amount'], array( 'currency' => $order->get_currency( 'edit' ) ) ),
						(int) $paid_refund_id
					)
				);
				$order->save();
				if ( class_exists( 'Woo_Wallet_Audit' ) ) {
					Woo_Wallet_Audit::record(
						Woo_Wallet_Audit::EVENT_CASHBACK_ADJUSTMENT_DELETED,
						(int) $order->get_customer_id(),
						(int) $order_id,
						(float) $pair['amount'],
						array(
							'adjustment_refund' => (int) $refund_id,
							'refund'            => (int) $paid_refund_id,
						)
					);
				}
				return;
			}
		}
	}
}
