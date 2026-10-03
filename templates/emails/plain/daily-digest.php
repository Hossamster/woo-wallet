<?php
/**
 * Wallet daily staff digest (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/plain/daily-digest.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails\Plain
 * @version 1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ww_name = function ( $user_id ) {
	$user = $user_id ? get_userdata( (int) $user_id ) : false;
	return $user ? $user->display_name : ( $user_id ? '#' . (int) $user_id : __( 'System', 'woo-wallet' ) );
};

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: 1: start, 2: end */
echo esc_html( sprintf( __( 'From %1$s to %2$s.', 'woo-wallet' ), $data['since'], $data['until'] ) ) . "\n\n";

echo '== ' . esc_html__( 'Needs a second look', 'woo-wallet' ) . " ==\n";
if ( $data['suspicious'] ) {
	foreach ( $data['suspicious'] as $flag ) {
		echo '- ' . esc_html( $ww_name( $flag['staff_id'] ) ) . ': ' . esc_html( $flag['message'] );
		if ( $flag['customer_id'] ) {
			echo ' (' . esc_html( $ww_name( $flag['customer_id'] ) ) . ', #' . (int) $flag['transaction_id'] . ')';
		}
		echo "\n";
	}
} else {
	echo esc_html__( 'Nothing suspicious.', 'woo-wallet' ) . "\n";
}

echo "\n== " . esc_html__( 'Manual credits and debits', 'woo-wallet' ) . " ==\n";
if ( $data['adjustments'] ) {
	foreach ( $data['staff'] as $staff_id => $totals ) {
		echo '- ' . esc_html( $ww_name( $staff_id ) ) . ': ' . (int) $totals['count'] . ' / +' . esc_html( wp_strip_all_tags( wc_price( $totals['credit'] ) ) ) . ' / -' . esc_html( wp_strip_all_tags( wc_price( $totals['debit'] ) ) ) . "\n";
	}
	echo "\n";
	foreach ( $data['adjustments'] as $row ) {
		echo esc_html( $row->date ) . ' ' . esc_html( $ww_name( $row->created_by ) ) . ' -> ' . esc_html( $ww_name( $row->user_id ) ) . ' ' . ( 'debit' === $row->type ? '-' : '+' ) . esc_html( wp_strip_all_tags( wc_price( (float) $row->amount, array( 'currency' => $row->currency ) ) ) ) . ' ' . esc_html( wp_strip_all_tags( (string) $row->details ) ) . "\n";
	}
} else {
	echo esc_html__( 'No manual credits or debits.', 'woo-wallet' ) . "\n";
}

echo "\n== " . esc_html__( 'Sensitive actions', 'woo-wallet' ) . " ==\n";
if ( $data['events'] ) {
	foreach ( $data['events'] as $event ) {
		echo esc_html( $event->date_created ) . ' ' . esc_html( $ww_name( $event->actor_id ) ) . ': ' . esc_html( $event_labels[ $event->event ] ?? $event->event ) . "\n";
	}
} else {
	echo esc_html__( 'None.', 'woo-wallet' ) . "\n";
}

echo "\n" . esc_html__( 'View staff activity', 'woo-wallet' ) . ': ' . esc_url( $activity_url ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
