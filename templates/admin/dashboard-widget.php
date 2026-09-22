<?php
/**
 * Admin View: wp-admin dashboard widget — wallet financial snapshot.
 *
 * Phase 1: Financial Health Snapshot + Alerts. Expects `$data` (a
 * Woo_Wallet_Dashboard_Widget_Data instance) and `$snapshot` (from
 * $data->get_snapshot()), both set by Woo_Wallet_Dashboard_Widget::render()
 * before including this file. Date-range tabs and Quick Actions/Growth
 * Insights land in later phases of the same feature — see the
 * dashboard-widget feature plan.
 *
 * @package StandaleneTech
 * @var Woo_Wallet_Dashboard_Widget_Data $data
 * @var array                            $snapshot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$withdrawals_url = admin_url( 'admin.php?page=woo-wallet-withdrawals' );
$pending_url     = add_query_arg( 'withdrawal_status', 'pending', $withdrawals_url );
?>
<style>
	.woo-wallet-dashboard-widget .twdw-alerts {
		margin: 0 0 12px;
	}
	.woo-wallet-dashboard-widget .twdw-alert {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		padding: 8px 10px;
		margin: 0 0 8px;
		border-left: 4px solid #d63638;
		background: #fcf0f1;
		font-size: 13px;
	}
	.woo-wallet-dashboard-widget .twdw-alert .dashicons {
		color: #d63638;
	}
	.woo-wallet-dashboard-widget .twdw-alert a {
		font-weight: 600;
	}
	.woo-wallet-dashboard-widget .twdw-grid {
		display: grid;
		grid-template-columns: repeat(2, 1fr);
		gap: 10px;
		margin: 0;
	}
	.woo-wallet-dashboard-widget .twdw-stat {
		background: #f6f7f7;
		border-radius: 3px;
		padding: 8px 10px;
	}
	.woo-wallet-dashboard-widget .twdw-stat__label {
		display: block;
		font-size: 11px;
		color: #646970;
		text-transform: uppercase;
		letter-spacing: .02em;
		margin-bottom: 2px;
	}
	.woo-wallet-dashboard-widget .twdw-stat__value {
		display: block;
		font-size: 16px;
		font-weight: 600;
		color: #1d2327;
	}
	.woo-wallet-dashboard-widget .twdw-stat__value.is-credit {
		color: #007017;
	}
	.woo-wallet-dashboard-widget .twdw-stat__value.is-debit {
		color: #d63638;
	}
	.woo-wallet-dashboard-widget .twdw-footer {
		margin: 10px 0 0;
		font-size: 12px;
		color: #646970;
	}
</style>
<div class="woo-wallet-dashboard-widget">
	<?php if ( ! empty( $snapshot['is_negative_net_flow_alert'] ) ) : ?>
		<div class="twdw-alerts">
			<p class="twdw-alert">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<span>
					<?php
					printf(
						/* translators: 1: today's outflow, 2: today's inflow, both formatted amounts */
						esc_html__( "Today's outflow (%1\$s) is unusually high against inflow (%2\$s).", 'woo-wallet' ),
						'<strong>' . esc_html( $data->format_amount( $snapshot['today_outflow'] ) ) . '</strong>',
						'<strong>' . esc_html( $data->format_amount( $snapshot['today_inflow'] ) ) . '</strong>'
					);
					?>
				</span>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $snapshot['high_value_count'] ) ) : ?>
		<div class="twdw-alerts">
			<p class="twdw-alert">
				<span class="dashicons dashicons-flag" aria-hidden="true"></span>
				<span>
					<?php
					printf(
						/* translators: 1: number of high-value transactions today, 2: the configured threshold amount */
						esc_html(
							_n(
								'%1$d transaction today was at or above %2$s.',
								'%1$d transactions today were at or above %2$s.',
								$snapshot['high_value_count'],
								'woo-wallet'
							)
						),
						(int) $snapshot['high_value_count'],
						'<strong>' . esc_html( $data->format_amount( $snapshot['high_value_threshold'] ) ) . '</strong>'
					);
					?>
				</span>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $snapshot['pending_withdrawals_count'] ) ) : ?>
		<div class="twdw-alerts">
			<p class="twdw-alert">
				<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
				<span>
					<a href="<?php echo esc_url( $pending_url ); ?>">
						<?php
						printf(
							/* translators: 1: number of pending withdrawal requests, 2: total pending amount */
							esc_html(
								_n(
									'%1$d withdrawal request (%2$s) is waiting for review.',
									'%1$d withdrawal requests (%2$s) are waiting for review.',
									$snapshot['pending_withdrawals_count'],
									'woo-wallet'
								)
							),
							(int) $snapshot['pending_withdrawals_count'],
							esc_html( $data->format_amount( $snapshot['pending_withdrawals_amount'] ) )
						);
						?>
					</a>
				</span>
			</p>
		</div>
	<?php endif; ?>

	<div class="twdw-grid">
		<div class="twdw-stat">
			<span class="twdw-stat__label"><?php esc_html_e( 'Outstanding liability', 'woo-wallet' ); ?></span>
			<span class="twdw-stat__value"><?php echo esc_html( $data->format_amount( $snapshot['total_liability'] ) ); ?></span>
		</div>
		<div class="twdw-stat">
			<span class="twdw-stat__label"><?php esc_html_e( 'Pending withdrawals', 'woo-wallet' ); ?></span>
			<span class="twdw-stat__value"><?php echo esc_html( $data->format_amount( $snapshot['pending_withdrawals_amount'] ) ); ?></span>
		</div>
		<div class="twdw-stat">
			<span class="twdw-stat__label"><?php esc_html_e( "Today's inflow", 'woo-wallet' ); ?></span>
			<span class="twdw-stat__value is-credit"><?php echo esc_html( $data->format_amount( $snapshot['today_inflow'] ) ); ?></span>
		</div>
		<div class="twdw-stat">
			<span class="twdw-stat__label"><?php esc_html_e( "Today's outflow", 'woo-wallet' ); ?></span>
			<span class="twdw-stat__value is-debit"><?php echo esc_html( $data->format_amount( $snapshot['today_outflow'] ) ); ?></span>
		</div>
	</div>

	<p class="twdw-footer">
		<?php
		printf(
			/* translators: %s: last-generated timestamp */
			esc_html__( 'Updated %s', 'woo-wallet' ),
			esc_html( $snapshot['generated_at'] )
		);
		?>
	</p>
</div>
