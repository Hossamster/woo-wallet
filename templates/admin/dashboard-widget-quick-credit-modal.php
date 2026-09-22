<?php
/**
 * Admin View: dashboard widget Quick Credit modal.
 *
 * Same WCBackboneModal + `<script type="text/template">` pattern as
 * templates/admin/credit-debit-modal.php (the Wallet -> Users bulk
 * Credit/Debit modal) — printed by Woo_Wallet_Dashboard_Widget::render()
 * alongside the widget's own output, triggered by the "Quick Credit" button
 * in templates/admin/dashboard-widget.php.
 *
 * Unlike the bulk modal, there's no pre-selected user (a dashboard widget
 * has no list of checked rows), so this asks for the customer directly by
 * email or username as plain text, resolved server-side in
 * Woo_Wallet_Dashboard_Widget::ajax_quick_credit() — deliberately not a
 * select2 customer-search field, to avoid wiring select2 initialisation
 * into content a Backbone modal injects into the DOM on demand.
 *
 * @package StandaleneTech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<script type="text/template" id="tmpl-woo-wallet-modal-dashboard-quick-credit">
	<div class="wc-backbone-modal woo-wallet-dashboard-quick-credit">
		<div class="wc-backbone-modal-content">
			<section class="wc-backbone-modal-main" role="main">
				<header class="wc-backbone-modal-header">
					<h1><?php esc_html_e( 'Quick credit', 'woo-wallet' ); ?></h1>
					<button class="modal-close modal-close-link dashicons dashicons-no-alt">
						<span class="screen-reader-text"><?php esc_html_e( 'Close modal panel', 'woo-wallet' ); ?></span>
					</button>
				</header>
				<article>
					<div id="woo-wallet-quick-credit-error" class="notice notice-error inline" style="display:none;"><p></p></div>
					<div class="woo-wallet-modal-form">
						<div class="woo-wallet-modal-field">
							<label for="woo-wallet-quick-credit-user"><?php esc_html_e( 'Customer email or username', 'woo-wallet' ); ?></label>
							<input type="text" id="woo-wallet-quick-credit-user" autocomplete="off" required />
						</div>
						<div class="woo-wallet-modal-field">
							<label for="woo-wallet-quick-credit-amount">
								<?php
								printf(
									/* translators: %s: WooCommerce currency symbol */
									esc_html__( 'Amount (%s)', 'woo-wallet' ),
									esc_html( get_woocommerce_currency_symbol() )
								);
								?>
							</label>
							<input type="number" step="0.01" min="0.01" id="woo-wallet-quick-credit-amount" required />
						</div>
						<div class="woo-wallet-modal-field">
							<label for="woo-wallet-quick-credit-note"><?php esc_html_e( 'Note (optional)', 'woo-wallet' ); ?></label>
							<textarea id="woo-wallet-quick-credit-note" rows="2"></textarea>
							<p class="woo-wallet-modal-help"><?php esc_html_e( 'Shown on the transaction record. Leave empty to use the default note.', 'woo-wallet' ); ?></p>
						</div>
					</div>
				</article>
				<footer>
					<div class="inner">
						<button type="button" class="button button-primary" id="woo-wallet-quick-credit-confirm"><?php esc_html_e( 'Credit Wallet', 'woo-wallet' ); ?></button>
					</div>
				</footer>
			</section>
		</div>
		<div class="wc-backbone-modal-backdrop modal-close"></div>
	</div>
</script>
<style>
	.woo-wallet-dashboard-quick-credit .woo-wallet-modal-form {
		display: flex;
		flex-direction: column;
		gap: 16px;
	}
	.woo-wallet-dashboard-quick-credit .woo-wallet-modal-field {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.woo-wallet-dashboard-quick-credit .woo-wallet-modal-field label {
		font-weight: 600;
	}
	.woo-wallet-dashboard-quick-credit .woo-wallet-modal-field input,
	.woo-wallet-dashboard-quick-credit .woo-wallet-modal-field textarea {
		width: 100%;
		max-width: 100%;
		box-sizing: border-box;
		padding: 6px 10px;
		font-size: 14px;
		line-height: 1.4;
	}
	.woo-wallet-dashboard-quick-credit .woo-wallet-modal-field textarea {
		resize: vertical;
		min-height: 60px;
	}
	.woo-wallet-dashboard-quick-credit .woo-wallet-modal-help {
		margin: 4px 0 0;
		font-size: 12px;
		color: #646970;
	}
	@media screen and (max-width: 600px) {
		.woo-wallet-dashboard-quick-credit .wc-backbone-modal-content {
			width: 95vw;
			max-width: 95vw;
		}
		.woo-wallet-dashboard-quick-credit .wc-backbone-modal-main article {
			padding: 16px;
		}
	}
</style>
