<?php
/**
 * Admin View: Edit wallet balance popup.
 *
 * @package StandaloneTech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<script type="text/template" id="tmpl-woo-wallet-modal-edit-balance">
	<div class="wc-backbone-modal woo-wallet-edit-balance">
		<div class="wc-backbone-modal-content">
			<form method="post">
				<section class="wc-backbone-modal-main" role="main">
					<header class="wc-backbone-modal-header">
						<h1><?php esc_html_e( 'Edit balance', 'woo-wallet' ); ?></h1>
						<button class="modal-close modal-close-link dashicons dashicons-no-alt">
							<span class="screen-reader-text"><?php esc_html_e( 'Close modal panel', 'woo-wallet' ); ?></span>
						</button>
					</header>
					<article>
						<table class="form-table">
							<tbody>
								<tr>
									<th scope="row"><label for="balance_amount"><?php /* translators: 1: WooCommerce currency symbol. */ echo esc_html( sprintf( __( 'Amount (%s)', 'woo-wallet' ), get_woocommerce_currency_symbol() ) ); ?></label></th>
									<td>
										<input type="number" step="any" name="balance_amount" class="regular-text" placeholder="<?php esc_html_e( 'Enter amount', 'woo-wallet' ); ?>" required />
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="payment_type"><?php esc_html_e( 'Type', 'woo-wallet' ); ?></label></th>
									<td>
										<?php
										$payment_types = apply_filters(
											'woo_wallet_adjust_balance_payment_type',
											array(
												'credit' => __( 'Credit', 'woo-wallet' ),
												'debit'  => __( 'Debit', 'woo-wallet' ),
											)
										);
										if ( Woo_Wallet_Staff::is_limited() ) {
											unset( $payment_types['debit'] );
										}
										?>
										<select class="regular-text" name="payment_type" id="payment_type">
											<?php foreach ( $payment_types as $key => $value ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $value ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<?php do_action( 'woo_wallet_after_payment_type_field' ); ?>
								<?php if ( Woo_Wallet_Staff::large_threshold() > 0 ) : ?>
								<tr class="woo-wallet-large-confirm" style="display:none;">
									<th scope="row"><label for="woo-wallet-confirm-amount"><?php esc_html_e( 'Confirm amount', 'woo-wallet' ); ?></label></th>
									<td>
										<p style="margin:0 0 6px;padding:6px 10px;background:#fcf0f1;border-left:4px solid #d63638;color:#8a1f1f;font-weight:600;">
											<?php
											/* translators: 1: threshold, 2: customer name */
											echo esc_html( sprintf( __( 'Large amount (over %1$s) for %2$s. Type it again to continue.', 'woo-wallet' ), wp_strip_all_tags( wc_price( Woo_Wallet_Staff::large_threshold() ) ), '{{ data.display_name }}' ) );
											?>
										</p>
										<input type="number" step="any" id="woo-wallet-confirm-amount" name="<?php echo esc_attr( Woo_Wallet_Staff::CONFIRM_FIELD ); ?>" class="regular-text" autocomplete="off" />
									</td>
								</tr>
								<?php endif; ?>
								<tr>
									<th scope="row"><label for="payment_description"><?php esc_html_e( 'Description', 'woo-wallet' ); ?></label></th>
									<td>
										<textarea name="payment_description" class="regular-text" placeholder="<?php esc_html_e( 'Enter description', 'woo-wallet' ); ?>"></textarea>
									</td>
								</tr>
							</tbody>
						</table>
					</article>
					<footer>
						<div class="inner">
							<input type="hidden" name="user_id" value="{{ data.user_id }}" />
							<?php wp_nonce_field( 'woo-wallet-admin-adjust-balance', 'woo-wallet-admin-adjust-balance' ); ?>
							<?php Woo_Wallet_Staff::form_token_field(); ?>
							<?php submit_button( __( 'Update balance', 'woo-wallet' ), 'primary', 'submit', false, array( 'style' => 'float:left;' ) ); ?>
							<strong class="current-balance"><?php esc_html_e( 'Current balance: ', 'woo-wallet' ); ?>{{{data.current_balance}}}</strong>
						</div>
					</footer>
				</section>
			</form>
		</div>
	</div>
	<div class="wc-backbone-modal-backdrop modal-close"></div>
</script>
