<?php
/**
 * Admin View: wp-admin dashboard widget — wallet financial snapshot.
 *
 * Phase 0 shell only: proves the widget registers, renders, and is scoped
 * to users who can manage the wallet. Real figures (liability, today's
 * in/out, pending withdrawals, alerts, quick actions) land in later phases
 * of the same feature, each extending this template rather than replacing
 * it — see the dashboard-widget feature plan.
 *
 * @package StandaleneTech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<style>
	.woo-wallet-dashboard-widget__placeholder {
		color: #646970;
		font-style: italic;
		margin: 0;
	}
</style>
<div class="woo-wallet-dashboard-widget">
	<p class="woo-wallet-dashboard-widget__placeholder">
		<?php esc_html_e( 'Wallet snapshot is on its way — pending withdrawals, today\'s activity, and quick actions will appear here.', 'woo-wallet' ); ?>
	</p>
</div>
