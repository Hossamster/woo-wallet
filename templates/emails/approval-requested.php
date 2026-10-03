<?php
/**
 * Approval requested email (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/approval-requested.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails
 * @version 1.10.0
 *
 * @var object        $request            Approval request row.
 * @var string        $type_label         What is being requested.
 * @var array         $details            Withdrawal bank details (account number and IBAN masked).
 * @var WP_User|false $customer           Customer.
 * @var WP_User|false $requester          Support agent.
 * @var string        $review_url         Approvals screen.
 * @var string        $email_heading      Heading.
 * @var string        $additional_content Additional content.
 * @var WC_Email      $email              Email object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: agent name, 2: request id */
			__( '%1$s sent request #%2$d, which needs a decision from a shop manager or administrator.', 'woo-wallet' ),
			$requester ? $requester->display_name : __( 'A support agent', 'woo-wallet' ),
			(int) $request->id
		)
	);
	?>
</p>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%; border-collapse:collapse; margin-bottom:16px;">
	<tr><th style="text-align:left;"><?php esc_html_e( 'Request', 'woo-wallet' ); ?></th><td><?php echo esc_html( $type_label ); ?></td></tr>
	<tr><th style="text-align:left;"><?php esc_html_e( 'Customer', 'woo-wallet' ); ?></th><td><?php echo $customer ? esc_html( $customer->display_name . ' (' . $customer->user_email . ')' ) : '#' . (int) $request->customer_id; ?></td></tr>
	<tr><th style="text-align:left;"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></th><td><?php echo wp_kses_post( wc_price( (float) $request->amount, array( 'currency' => $request->currency ) ) ); ?></td></tr>
	<?php if ( 'withdrawal' === $request->type ) : ?>
		<tr><th style="text-align:left;"><?php esc_html_e( 'Bank', 'woo-wallet' ); ?></th><td><?php echo esc_html( ( $details['bank_name'] ?? '' ) . ' · ' . ( $details['beneficiary_name'] ?? '' ) . ' · ' . ( $details['account_number'] ?? '' ) ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $request->reason ) : ?>
		<tr><th style="text-align:left;"><?php esc_html_e( 'Reason', 'woo-wallet' ); ?></th><td><?php echo esc_html( $request->reason ); ?></td></tr>
	<?php endif; ?>
</table>

<p><?php esc_html_e( 'Nothing has changed in the customer\'s wallet yet.', 'woo-wallet' ); ?></p>
<p><a class="link" href="<?php echo esc_url( $review_url ); ?>"><?php esc_html_e( 'Review the request', 'woo-wallet' ); ?></a></p>

<?php
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
