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
$period_labels   = $data->period_labels();
$period_label    = isset( $period_labels[ $snapshot['period'] ] ) ? $period_labels[ $snapshot['period'] ] : '';
?>
<?php if ( ! empty( $snapshot['is_negative_net_flow_alert'] ) ) : ?>
	<div class="twdw-alerts">
		<p class="twdw-alert">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<span>
				<?php
				printf(
					/* translators: 1: outflow, 2: inflow, both formatted amounts, 3: period phrase e.g. "today" */
					esc_html__( 'Outflow (%1$s) is unusually high against inflow (%2$s) %3$s.', 'woo-wallet' ),
					'<strong>' . esc_html( $data->format_amount( $snapshot['outflow'] ) ) . '</strong>',
					'<strong>' . esc_html( $data->format_amount( $snapshot['inflow'] ) ) . '</strong>',
					esc_html( $period_phrase )
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
	<div class="twdw-stat">
		<span class="twdw-stat__label"><?php esc_html_e( 'Outstanding liability', 'woo-wallet' ); ?></span>
		<span class="twdw-stat__value"><?php echo esc_html( $data->format_amount( $snapshot['total_liability'] ) ); ?></span>
	</div>
	<div class="twdw-stat">
		<span class="twdw-stat__label"><?php esc_html_e( 'Pending withdrawals', 'woo-wallet' ); ?></span>
		<span class="twdw-stat__value"><?php echo esc_html( $data->format_amount( $snapshot['pending_withdrawals_amount'] ) ); ?></span>
	</div>
	<div class="twdw-stat">
		<span class="twdw-stat__label">
			<?php
			/* translators: %s: selected period label, e.g. Today, 7 Days, This Month */
			printf( esc_html__( 'Inflow (%s)', 'woo-wallet' ), esc_html( $period_label ) );
			?>
		</span>
		<span class="twdw-stat__value is-credit"><?php echo esc_html( $data->format_amount( $snapshot['inflow'] ) ); ?></span>
	</div>
	<div class="twdw-stat">
		<span class="twdw-stat__label">
			<?php
			/* translators: %s: selected period label, e.g. Today, 7 Days, This Month */
			printf( esc_html__( 'Outflow (%s)', 'woo-wallet' ), esc_html( $period_label ) );
			?>
		</span>
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
