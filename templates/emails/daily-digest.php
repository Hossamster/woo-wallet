<?php
/**
 * Wallet daily staff digest (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/emails/daily-digest.php.
 *
 * @package StandaloneTech\TeraWallet\Templates\Emails
 * @version 1.11.0
 *
 * @var array    $data               From Woo_Wallet_Audit::digest_data().
 * @var string[] $event_labels       Event labels.
 * @var string   $activity_url       Staff activity screen.
 * @var string   $email_heading      Heading.
 * @var string   $additional_content Additional content.
 * @var WC_Email $email              Email object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ww_name = function ( $user_id ) {
	$user = $user_id ? get_userdata( (int) $user_id ) : false;
	return $user ? $user->display_name : ( $user_id ? '#' . (int) $user_id : __( 'System', 'woo-wallet' ) );
};

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	/* translators: 1: start, 2: end */
	echo esc_html( sprintf( __( 'From %1$s to %2$s.', 'woo-wallet' ), $data['since'], $data['until'] ) );
	?>
</p>

<?php if ( $data['suspicious'] ) : ?>
	<h2 style="color:#d63638;"><?php esc_html_e( 'Needs a second look', 'woo-wallet' ); ?></h2>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%; border-collapse:collapse; margin-bottom:16px;">
		<?php foreach ( $data['suspicious'] as $flag ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $ww_name( $flag['staff_id'] ) ); ?></strong></td>
				<td>
					<?php echo esc_html( $flag['message'] ); ?>
					<?php if ( $flag['customer_id'] ) : ?>
						<br />
						<?php
						/* translators: 1: customer, 2: transaction id */
						echo esc_html( sprintf( __( 'Wallet: %1$s — transaction #%2$d', 'woo-wallet' ), $ww_name( $flag['customer_id'] ), (int) $flag['transaction_id'] ) );
						?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php else : ?>
	<p><?php esc_html_e( 'Nothing suspicious.', 'woo-wallet' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( 'Manual credits and debits', 'woo-wallet' ); ?></h2>
<?php if ( $data['adjustments'] ) : ?>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%; border-collapse:collapse; margin-bottom:12px;">
		<tr>
			<th style="text-align:left;"><?php esc_html_e( 'Staff', 'woo-wallet' ); ?></th>
			<th style="text-align:left;"><?php esc_html_e( 'Adjustments', 'woo-wallet' ); ?></th>
			<th style="text-align:left;"><?php esc_html_e( 'Credited', 'woo-wallet' ); ?></th>
			<th style="text-align:left;"><?php esc_html_e( 'Debited', 'woo-wallet' ); ?></th>
		</tr>
		<?php foreach ( $data['staff'] as $staff_id => $totals ) : ?>
			<tr>
				<td><?php echo esc_html( $ww_name( $staff_id ) ); ?></td>
				<td><?php echo (int) $totals['count']; ?></td>
				<td><?php echo wp_kses_post( wc_price( $totals['credit'] ) ); ?></td>
				<td><?php echo wp_kses_post( wc_price( $totals['debit'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%; border-collapse:collapse; margin-bottom:16px; font-size:13px;">
		<tr>
			<th style="text-align:left;"><?php esc_html_e( 'Time', 'woo-wallet' ); ?></th>
			<th style="text-align:left;"><?php esc_html_e( 'Staff', 'woo-wallet' ); ?></th>
			<th style="text-align:left;"><?php esc_html_e( 'Wallet', 'woo-wallet' ); ?></th>
			<th style="text-align:left;"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></th>
			<th style="text-align:left;"><?php esc_html_e( 'Description', 'woo-wallet' ); ?></th>
		</tr>
		<?php foreach ( $data['adjustments'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row->date ); ?></td>
				<td><?php echo esc_html( $ww_name( $row->created_by ) ); ?></td>
				<td><?php echo esc_html( $ww_name( $row->user_id ) ); ?></td>
				<td><?php echo esc_html( 'debit' === $row->type ? '−' : '+' ); ?><?php echo wp_kses_post( wc_price( (float) $row->amount, array( 'currency' => $row->currency ) ) ); ?></td>
				<td><?php echo esc_html( wp_strip_all_tags( (string) $row->details ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php else : ?>
	<p><?php esc_html_e( 'No manual credits or debits.', 'woo-wallet' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( 'Sensitive actions', 'woo-wallet' ); ?></h2>
<?php if ( $data['events'] ) : ?>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%; border-collapse:collapse; margin-bottom:16px; font-size:13px;">
		<?php foreach ( $data['events'] as $event ) : ?>
			<tr>
				<td><?php echo esc_html( $event->date_created ); ?></td>
				<td><?php echo esc_html( $ww_name( $event->actor_id ) ); ?></td>
				<td><?php echo esc_html( $event_labels[ $event->event ] ?? $event->event ); ?></td>
				<td><?php echo $event->customer_id ? esc_html( $ww_name( $event->customer_id ) ) : ''; ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php else : ?>
	<p><?php esc_html_e( 'None.', 'woo-wallet' ); ?></p>
<?php endif; ?>

<p><a class="link" href="<?php echo esc_url( $activity_url ); ?>"><?php esc_html_e( 'View staff activity', 'woo-wallet' ); ?></a></p>

<?php
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
