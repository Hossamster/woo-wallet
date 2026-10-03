<?php
/**
 * Approval decided email (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/approval-decided.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails
 * @version 1.10.0
 *
 * @var object        $request            Approval request row.
 * @var string        $type_label         What was requested.
 * @var string        $status_label       Outcome.
 * @var WP_User|false $customer           Customer.
 * @var WP_User|null  $decider            Who decided.
 * @var string        $requests_url       My Requests screen.
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
			/* translators: 1: request id, 2: what was requested, 3: customer name, 4: outcome */
			__( 'Your request #%1$d (%2$s for %3$s): %4$s.', 'woo-wallet' ),
			(int) $request->id,
			$type_label,
			$customer ? $customer->display_name : '#' . (int) $request->customer_id,
			$status_label
		)
	);
	?>
</p>
<p><?php echo wp_kses_post( wc_price( (float) $request->amount, array( 'currency' => $request->currency ) ) ); ?></p>

<?php if ( $decider ) : ?>
	<?php /* translators: %s: approver name */ ?>
	<p><?php echo esc_html( sprintf( __( 'Decided by %s.', 'woo-wallet' ), $decider->display_name ) ); ?></p>
<?php endif; ?>
<?php if ( $request->decision_note ) : ?>
	<p><strong><?php esc_html_e( 'Note:', 'woo-wallet' ); ?></strong> <?php echo esc_html( $request->decision_note ); ?></p>
<?php endif; ?>
<?php if ( $request->failure_reason ) : ?>
	<p><strong><?php esc_html_e( 'Why it could not be carried out:', 'woo-wallet' ); ?></strong> <?php echo esc_html( $request->failure_reason ); ?></p>
<?php endif; ?>

<p><a class="link" href="<?php echo esc_url( $requests_url ); ?>"><?php esc_html_e( 'View your requests', 'woo-wallet' ); ?></a></p>

<?php
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
