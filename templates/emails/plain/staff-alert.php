<?php
/**
 * Wallet staff alert (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/plain/staff-alert.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails\Plain
 * @version 1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: 1: staff name, 2: what happened */
echo esc_html( sprintf( __( '%1$s: %2$s', 'woo-wallet' ), $actor ? $actor->display_name . ' (' . $actor->user_email . ')' : __( 'System', 'woo-wallet' ), $event_label ) ) . "\n\n";
if ( $customer ) {
	echo esc_html__( 'Wallet', 'woo-wallet' ) . ': ' . esc_html( $customer->display_name . ' (' . $customer->user_email . ')' ) . "\n";
}
if ( (float) $event->amount ) {
	echo esc_html__( 'Amount', 'woo-wallet' ) . ': ' . esc_html( wp_strip_all_tags( wc_price( (float) $event->amount ) ) ) . "\n";
}
if ( $event->object_id ) {
	echo esc_html__( 'Transaction', 'woo-wallet' ) . ': #' . (int) $event->object_id . "\n";
}
foreach ( (array) $event->details as $key => $value ) {
	echo esc_html( ucfirst( str_replace( '_', ' ', (string) $key ) ) ) . ': ' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) . "\n";
}
echo esc_html__( 'When', 'woo-wallet' ) . ': ' . esc_html( $event->date_created ) . "\n\n";
echo esc_html__( 'View staff activity', 'woo-wallet' ) . ': ' . esc_url( $activity_url ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
