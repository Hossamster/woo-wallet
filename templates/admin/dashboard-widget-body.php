<?php
/**
 * Admin View: wp-admin dashboard widget — snapshot body (alerts + stat grid).
 *
 * Rendered for the widget's initial paint (included from
 * templates/admin/dashboard-widget.php) AND re-rendered wholesale by
 * Woo_Wallet_Dashboard_Widget::ajax_refresh() when a period tab is clicked
 * — kept as its own file, rather than inlined in the outer template, so
 * both call sites include the exact same markup instead of two copies
 * drifting apart.
 *
 * Expects `$data` (a Woo_Wallet_Dashboard_Widget_Data instance) and
 * `$snapshot` (from $data->get_snapshot()), set by whichever of the two
 * call sites above included this file.
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
$period_phrase   = $data->period_phrase( $snapshot['period'] );
?>
<?php if ( ! empty( $snapshot['is_negative_net_flow_alert'] ) ) : ?>
	<div class="twdw-alerts">
		<p class="twdw-alert">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<span>
				<?php
				// The Inflow/Outflow tiles below already show both amounts —
				// say something they don't: how far out of balance the two are.
				if ( $snapshot['inflow'] > 0 ) {
					printf(
						/* translators: 1: outflow as a percentage of inflow, 2: period phrase e.g. "today", 3: the configured alert threshold percentage */
						esc_html__( 'Outflow is %1$s%% of inflow %2$s — above your %3$s%% alert threshold.', 'woo-wallet' ),
						'<strong>' . esc_html( number_format_i18n( ( $snapshot['outflow'] / $snapshot['inflow'] ) * 100, 0 ) ) . '</strong>',
						esc_html( $period_phrase ),
						esc_html( number_format_i18n( $data->net_outflow_alert_percent(), 0 ) )
					);
				} else {
					printf(
						/* translators: %s: period phrase e.g. "today" */
						esc_html__( 'Money went out %s with nothing coming in.', 'woo-wallet' ),
						esc_html( $period_phrase )
					);
				}
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
					/* translators: 1: number of high-value transactions, 2: the configured threshold amount, 3: period phrase e.g. "today" */
					esc_html(
						_n(
							'%1$d transaction %3$s was at or above %2$s.',
							'%1$d transactions %3$s were at or above %2$s.',
							$snapshot['high_value_count'],
							'woo-wallet'
						)
					),
					(int) $snapshot['high_value_count'],
					'<strong>' . esc_html( $data->format_amount( $snapshot['high_value_threshold'] ) ) . '</strong>',
					esc_html( $period_phrase )
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
	<div class="twdw-stat twdw-stat--wide">
		<span class="twdw-stat__label"><?php esc_html_e( 'Outstanding liability', 'woo-wallet' ); ?></span>
		<span class="twdw-stat__value"><?php echo esc_html( $data->format_amount( $snapshot['total_liability'] ) ); ?></span>
	</div>
	<?php // The selected period is already shown by the active tab above — no need to repeat it in every label. ?>
	<div class="twdw-stat">
		<span class="twdw-stat__label"><?php esc_html_e( 'Inflow', 'woo-wallet' ); ?></span>
		<span class="twdw-stat__value is-credit"><?php echo esc_html( $data->format_amount( $snapshot['inflow'] ) ); ?></span>
	</div>
	<div class="twdw-stat">
		<span class="twdw-stat__label"><?php esc_html_e( 'Outflow', 'woo-wallet' ); ?></span>
		<span class="twdw-stat__value is-debit"><?php echo esc_html( $data->format_amount( $snapshot['outflow'] ) ); ?></span>
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
