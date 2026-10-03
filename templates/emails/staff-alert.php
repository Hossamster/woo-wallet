<?php
/**
 * Wallet staff alert (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/staff-alert.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails
 * @version 1.11.0
 *
 * @var object        $event              Audit row.
 * @var string        $event_label        What happened.
 * @var WP_User|false $actor              Who did it.
 * @var WP_User|null  $customer           Wallet it concerns.
 * @var string        $activity_url       Staff activity screen.
 * @var string        $email_heading      Heading.
 * @var string        $additional_content Additional content.
 * @var WC_Email      $email              Email object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p style="padding:10px 12px;background:#fcf0f1;border-left:4px solid #d63638;">
	<strong>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: staff name, 2: what happened */
				__( '%1$s: %2$s', 'woo-wallet' ),
				$actor ? $actor->display_name . ' (' . $actor->user_email . ')' : __( 'System', 'woo-wallet' ),
				$event_label
			)
		);
		?>
	</strong>
</p>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%; border-collapse:collapse; margin-bottom:16px;">
	<?php if ( $customer ) : ?>
		<tr><th style="text-align:left;"><?php esc_html_e( 'Wallet', 'woo-wallet' ); ?></th><td><?php echo esc_html( $customer->display_name . ' (' . $customer->user_email . ')' ); ?></td></tr>
	<?php endif; ?>
	<?php if ( (float) $event->amount ) : ?>
		<tr><th style="text-align:left;"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></th><td><?php echo wp_kses_post( wc_price( (float) $event->amount ) ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $event->object_id ) : ?>
		<tr><th style="text-align:left;"><?php esc_html_e( 'Transaction', 'woo-wallet' ); ?></th><td>#<?php echo (int) $event->object_id; ?></td></tr>
	<?php endif; ?>
	<?php foreach ( (array) $event->details as $key => $value ) : ?>
		<tr><th style="text-align:left;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $key ) ) ); ?></th><td><?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?></td></tr>
	<?php endforeach; ?>
	<tr><th style="text-align:left;"><?php esc_html_e( 'When', 'woo-wallet' ); ?></th><td><?php echo esc_html( $event->date_created ); ?></td></tr>
</table>

<p><a class="link" href="<?php echo esc_url( $activity_url ); ?>"><?php esc_html_e( 'View staff activity', 'woo-wallet' ); ?></a></p>

<?php
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
