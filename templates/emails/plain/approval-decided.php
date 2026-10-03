<?php
/**
 * Approval decided email (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/plain/approval-decided.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails\Plain
 * @version 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: 1: request id, 2: what was requested, 3: customer name, 4: outcome */
echo esc_html( sprintf( __( 'Your request #%1$d (%2$s for %3$s): %4$s.', 'woo-wallet' ), (int) $request->id, $type_label, $customer ? $customer->display_name : '#' . (int) $request->customer_id, $status_label ) ) . "\n";
echo esc_html( wp_strip_all_tags( wc_price( (float) $request->amount, array( 'currency' => $request->currency ) ) ) ) . "\n\n";
if ( $decider ) {
	/* translators: %s: approver name */
	echo esc_html( sprintf( __( 'Decided by %s.', 'woo-wallet' ), $decider->display_name ) ) . "\n";
}
if ( $request->decision_note ) {
	echo esc_html__( 'Note:', 'woo-wallet' ) . ' ' . esc_html( $request->decision_note ) . "\n";
}
if ( $request->failure_reason ) {
	echo esc_html__( 'Why it could not be carried out:', 'woo-wallet' ) . ' ' . esc_html( $request->failure_reason ) . "\n";
}
echo "\n" . esc_html__( 'View your requests', 'woo-wallet' ) . ': ' . esc_url( $requests_url ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
