<?php
/**
 * The Template for displaying the wallet withdrawal request form.
 *
 * This template can be overridden by copying it to yourtheme/woo-wallet/withdraw.php.
 *
 * HOWEVER, on occasion we will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @version 1.7.2
 * @package StandaleneTech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$ww_min_amount = woo_wallet()->settings_api->get_option( 'min_withdrawal_amount', '_wallet_settings_withdrawal', 0 );
$ww_max_amount = woo_wallet()->settings_api->get_option( 'max_withdrawal_amount', '_wallet_settings_withdrawal', 0 );
$ww_banks      = Woo_Wallet_Withdrawal::get_configured_banks();
$ww_user_id    = get_current_user_id();
$ww_history    = Woo_Wallet_Withdrawal::get_requests(
	array(
		'user_id' => $ww_user_id,
		'limit'   => 20,
	)
);
$ww_status_labels = array(
	'pending'    => __( 'Pending', 'woo-wallet' ),
	'processing' => __( 'Pending', 'woo-wallet' ), // Transient internal state; shown to the customer the same as pending.
	'paid'       => __( 'Paid', 'woo-wallet' ),
	'rejected'   => __( 'Rejected', 'woo-wallet' ),
);
$ww_retention_days = Woo_Wallet_Withdrawal::receipt_retention_days();
?>
<!-- Withdraw Form -->
<div class="woo-wallet-form-wrapper">
	<h3 class="woo-wallet-section-title"><?php esc_html_e( 'Withdraw to Bank Account', 'woo-wallet' ); ?></h3>
	<p><?php esc_html_e( 'Request a payout of your wallet balance. The amount is reserved from your wallet immediately and paid out by bank transfer once approved.', 'woo-wallet' ); ?></p>
	<form method="post" action="" id="woo_wallet_withdraw_form">
		<p class="woo-wallet-field-container form-row form-row-wide">
			<label for="woo_wallet_withdraw_amount"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></label>
			<input
				id="woo_wallet_withdraw_amount"
				type="number"
				step="0.01"
				<?php if ( $ww_min_amount > 0 ) : ?>min="<?php echo esc_attr( $ww_min_amount ); ?>"<?php endif; ?>
				<?php if ( $ww_max_amount > 0 ) : ?>max="<?php echo esc_attr( $ww_max_amount ); ?>"<?php endif; ?>
				name="woo_wallet_withdraw_amount"
				required
				placeholder="0.00"
			/>
		</p>
		<p class="woo-wallet-field-container form-row form-row-wide">
			<label for="woo_wallet_withdraw_bank"><?php esc_html_e( 'Bank', 'woo-wallet' ); ?></label>
			<select name="woo_wallet_withdraw_bank" id="woo_wallet_withdraw_bank" required>
				<option value=""><?php esc_html_e( 'Select your bank&hellip;', 'woo-wallet' ); ?></option>
				<?php foreach ( $ww_banks as $ww_bank_value => $ww_bank_label ) : ?>
					<option value="<?php echo esc_attr( $ww_bank_value ); ?>"><?php echo esc_html( $ww_bank_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="woo-wallet-field-container form-row form-row-wide">
			<label for="woo_wallet_withdraw_beneficiary"><?php esc_html_e( 'Beneficiary Name', 'woo-wallet' ); ?></label>
			<input id="woo_wallet_withdraw_beneficiary" type="text" name="woo_wallet_withdraw_beneficiary" required placeholder="<?php esc_attr_e( 'Full name on the bank account', 'woo-wallet' ); ?>" />
		</p>
		<p class="woo-wallet-field-container form-row form-row-wide">
			<label for="woo_wallet_withdraw_account_number"><?php esc_html_e( 'Account Number', 'woo-wallet' ); ?></label>
			<input id="woo_wallet_withdraw_account_number" type="text" name="woo_wallet_withdraw_account_number" required placeholder="<?php esc_attr_e( 'Bank account number', 'woo-wallet' ); ?>" />
		</p>
		<p class="woo-wallet-field-container form-row form-row-wide">
			<label for="woo_wallet_withdraw_iban"><?php esc_html_e( 'IBAN (optional)', 'woo-wallet' ); ?></label>
			<input id="woo_wallet_withdraw_iban" type="text" name="woo_wallet_withdraw_iban" placeholder="EG..." />
		</p>
		<p class="woo-wallet-field-container form-row">
			<?php wp_nonce_field( 'woo_wallet_withdraw', 'woo_wallet_withdraw' ); ?>
			<input type="submit" class="button" name="woo_wallet_withdraw_request" value="<?php esc_attr_e( 'Request Withdrawal', 'woo-wallet' ); ?>" />
		</p>
	</form>
</div>

<?php if ( $ww_history ) : ?>
<!-- Withdrawal history -->
<div class="woo-wallet-form-wrapper">
	<h3 class="woo-wallet-section-title"><?php esc_html_e( 'Your Withdrawal Requests', 'woo-wallet' ); ?></h3>
	<div class="ww-stmt-tablewrap">
		<table class="ww-stmt-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'woo-wallet' ); ?></th>
					<th scope="col" class="ww-stmt-num"><?php esc_html_e( 'Amount', 'woo-wallet' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Bank', 'woo-wallet' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reference', 'woo-wallet' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Receipt', 'woo-wallet' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'woo-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ww_history as $ww_row ) : ?>
					<?php
					$ww_public_notes = Woo_Wallet_Withdrawal::get_notes( $ww_row->id, 'public' );
					$ww_receipt_url  = $ww_row->receipt_id ? wp_get_attachment_url( $ww_row->receipt_id ) : false;
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Date', 'woo-wallet' ); ?>"><?php echo esc_html( wc_string_to_datetime( $ww_row->date_created )->date_i18n( wc_date_format() ) ); ?></td>
						<td class="ww-stmt-num" data-label="<?php esc_attr_e( 'Amount', 'woo-wallet' ); ?>"><?php echo wp_kses_post( wc_price( (float) $ww_row->amount, array( 'currency' => $ww_row->currency ? $ww_row->currency : get_option( 'woocommerce_currency' ) ) ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Bank', 'woo-wallet' ); ?>"><?php echo esc_html( $ww_row->bank_name ); ?></td>
						<td data-label="<?php esc_attr_e( 'Reference', 'woo-wallet' ); ?>"><?php echo $ww_row->reference_no ? esc_html( $ww_row->reference_no ) : '&ndash;'; ?></td>
						<td data-label="<?php esc_attr_e( 'Receipt', 'woo-wallet' ); ?>">
							<?php if ( $ww_receipt_url ) : ?>
								<a href="<?php echo esc_url( $ww_receipt_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'woo-wallet' ); ?></a>
							<?php else : ?>
								&ndash;
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Status', 'woo-wallet' ); ?>">
							<?php echo esc_html( isset( $ww_status_labels[ $ww_row->status ] ) ? $ww_status_labels[ $ww_row->status ] : $ww_row->status ); ?>
							<?php foreach ( $ww_public_notes as $ww_note ) : ?>
								<br /><small><?php echo esc_html( $ww_note->note ); ?></small>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php if ( $ww_retention_days > 0 ) : ?>
		<p class="ww-stmt-note" style="margin-top:8px;">
			<small>
				<?php
				if ( 0 === $ww_retention_days % 30 ) {
					printf(
						/* translators: %d: number of months */
						esc_html( _n( 'Receipts are automatically removed %d month after the request date to save storage. If you need a copy after that, please contact us before it is removed.', 'Receipts are automatically removed %d months after the request date to save storage. If you need a copy after that, please contact us before it is removed.', (int) ( $ww_retention_days / 30 ), 'woo-wallet' ) ),
						(int) ( $ww_retention_days / 30 )
					);
				} else {
					printf(
						/* translators: %d: number of days */
						esc_html( _n( 'Receipts are automatically removed %d day after the request date to save storage. If you need a copy after that, please contact us before it is removed.', 'Receipts are automatically removed %d days after the request date to save storage. If you need a copy after that, please contact us before it is removed.', $ww_retention_days, 'woo-wallet' ) ),
						$ww_retention_days
					);
				}
				?>
			</small>
		</p>
	<?php endif; ?>
</div>
<?php endif; ?>
