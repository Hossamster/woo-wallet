<?php
/**
 * Approval requested email (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/plain/approval-requested.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails\Plain
 * @version 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: 1: agent name, 2: request id */
echo esc_html( sprintf( __( '%1$s sent request #%2$d, which needs a decision from a shop manager or administrator.', 'woo-wallet' ), $requester ? $requester->display_name : __( 'A support agent', 'woo-wallet' ), (int) $request->id ) ) . "\n\n";
echo esc_html__( 'Request', 'woo-wallet' ) . ': ' . esc_html( $type_label ) . "\n";
echo esc_html__( 'Customer', 'woo-wallet' ) . ': ' . ( $customer ? esc_html( $customer->display_name . ' (' . $customer->user_email . ')' ) : '#' . (int) $request->customer_id ) . "\n";
echo esc_html__( 'Amount', 'woo-wallet' ) . ': ' . esc_html( wp_strip_all_tags( wc_price( (float) $request->amount, array( 'currency' => $request->currency ) ) ) ) . "\n";
if ( 'withdrawal' === $request->type ) {
	echo esc_html__( 'Bank', 'woo-wallet' ) . ': ' . esc_html( ( $details['bank_name'] ?? '' ) . ' / ' . ( $details['beneficiary_name'] ?? '' ) . ' / ' . ( $details['account_number'] ?? '' ) ) . "\n";
}
if ( $request->reason ) {
	echo esc_html__( 'Reason', 'woo-wallet' ) . ': ' . esc_html( $request->reason ) . "\n";
}
echo "\n" . esc_html__( 'Nothing has changed in the customer\'s wallet yet.', 'woo-wallet' ) . "\n";
echo esc_html__( 'Review the request', 'woo-wallet' ) . ': ' . esc_url( $review_url ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
