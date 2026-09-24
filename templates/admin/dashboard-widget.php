<?php
/**
 * Admin View: wallet overview panel — rendered at the top of the plugin's
 * own Reports page (`admin.php?page=woo-wallet`) via the
 * `woo_wallet_reports_page_top` action.
 *
 * Previously shown as a wp-admin Dashboard widget (wp-admin/index.php).
 * Moved here so the financial overview lives where operators already work.
 *
 * Renders the period tabs (Today / 7 Days / This Month), the initial
 * snapshot body (templates/admin/dashboard-widget-body.php),
 * the Growth Insights section, and the Quick Actions row. Clicking a tab
 * re-fetches the snapshot body via AJAX
 * (Woo_Wallet_Dashboard_Widget::ajax_refresh) and swaps it in — no full
 * page reload. Growth Insights renders once here rather than in
 * dashboard-widget-body.php (it isn't period-scoped).
 *
 * Expects `$data` (a Woo_Wallet_Dashboard_Widget_Data instance), `$snapshot`
 * (from $data->get_snapshot()), and `$growth` (from
 * $data->get_growth_insights()), all set by
 * Woo_Wallet_Dashboard_Widget::render() before including this file.
 *
 * @package StandaleneTech
 * @var Woo_Wallet_Dashboard_Widget_Data $data
 * @var array                            $snapshot
 * @var array                            $growth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( get_wallet_user_capability() ) ) {
	return;
}

$period_labels = $data->period_labels();
$nonce         = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
?>
<style>
	/* Overview panel wrapper — sits above the liability tabs on the Reports page */
	.woo-wallet-overview-panel {
		background: #fff;
		border: 1px solid #dcdcde;
		border-radius: 3px;
		padding: 16px 20px;
		margin: 0 0 20px;
	}
	.woo-wallet-overview-panel .woo-wallet-dashboard-widget {
		max-width: 100%;
	}
	.woo-wallet-dashboard-widget .twdw-tabs {
		display: flex;
		gap: 4px;
		margin: 0 0 10px;
		border-bottom: 1px solid #dcdcde;
	}
	.woo-wallet-dashboard-widget .twdw-tab {
		background: none;
		border: none;
		cursor: pointer;
		padding: 6px 10px;
		margin: 0 0 -1px;
		font-size: 12px;
		color: #646970;
		border-bottom: 2px solid transparent;
	}
	.woo-wallet-dashboard-widget .twdw-tab:hover {
		color: #1d2327;
	}
	.woo-wallet-dashboard-widget .twdw-tab.is-active {
		color: #1d2327;
		font-weight: 600;
		border-bottom-color: #2271b1;
	}
	.woo-wallet-dashboard-widget .twdw-body.is-loading {
		opacity: .5;
	}
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
		grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
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
	.woo-wallet-dashboard-widget .twdw-actions {
		display: flex;
		gap: 8px;
		margin: 12px 0 0;
		padding-top: 10px;
		border-top: 1px solid #dcdcde;
	}
	.woo-wallet-dashboard-widget .twdw-actions .notice {
		margin: 0 0 8px;
	}
	.woo-wallet-dashboard-widget .twdw-growth {
		margin: 12px 0 0;
		padding-top: 10px;
		border-top: 1px solid #dcdcde;
	}
	.woo-wallet-dashboard-widget .twdw-growth__title {
		margin: 0 0 8px;
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: .02em;
		color: #646970;
	}
	.woo-wallet-dashboard-widget .twdw-growth__list {
		margin: 0;
		font-size: 13px;
	}
	.woo-wallet-dashboard-widget .twdw-growth__list li {
		display: flex;
		justify-content: space-between;
		gap: 8px;
		padding: 4px 0;
	}
	.woo-wallet-dashboard-widget .twdw-growth__label {
		color: #646970;
	}
	.woo-wallet-dashboard-widget .twdw-growth__value {
		font-weight: 600;
		color: #1d2327;
		white-space: nowrap;
	}
</style>
<div class="woo-wallet-overview-panel">
<div class="woo-wallet-dashboard-widget">
	<nav class="twdw-tabs">
		<?php foreach ( $period_labels as $period => $label ) : ?>
			<button
				type="button"
				class="twdw-tab<?php echo $period === $snapshot['period'] ? ' is-active' : ''; ?>"
				data-period="<?php echo esc_attr( $period ); ?>"
			><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</nav>

	<div class="twdw-body" data-security="<?php echo esc_attr( $nonce ); ?>">
		<?php include WOO_WALLET_ABSPATH . 'templates/admin/dashboard-widget-body.php'; ?>
	</div>

	<div class="twdw-growth">
		<p class="twdw-growth__title"><?php esc_html_e( 'Growth & Customer Insights', 'woo-wallet' ); ?></p>
		<ul class="twdw-growth__list">
			<li>
				<span class="twdw-growth__label">
					<?php
					printf(
						/* translators: %d: number of days with no order */
						esc_html__( 'Dormant balances (%d+ days no order)', 'woo-wallet' ),
						(int) $growth['dormant_threshold_days']
					);
					?>
				</span>
				<span class="twdw-growth__value">
					<?php
					printf(
						/* translators: 1: number of dormant customers, 2: total dormant balance amount */
						esc_html__( '%1$d (%2$s)', 'woo-wallet' ),
						(int) $growth['dormant_count'],
						esc_html( $data->format_amount( $growth['dormant_amount'] ) )
					);
					?>
				</span>
			</li>
			<li>
				<span class="twdw-growth__label"><?php esc_html_e( 'P2P transfers today', 'woo-wallet' ); ?></span>
				<span class="twdw-growth__value">
					<?php
					printf(
						/* translators: 1: number of transfers today, 2: total transfer amount */
						esc_html__( '%1$d (%2$s)', 'woo-wallet' ),
						(int) $growth['transfer_count'],
						esc_html( $data->format_amount( $growth['transfer_amount'] ) )
					);
					?>
				</span>
			</li>
			<li>
				<span class="twdw-growth__label"><?php esc_html_e( 'Cashback credited today', 'woo-wallet' ); ?></span>
				<span class="twdw-growth__value"><?php echo esc_html( $data->format_amount( $growth['cashback_credited_today'] ) ); ?></span>
			</li>
			<li>
				<span class="twdw-growth__label"><?php esc_html_e( "Wallet share of today's checkout", 'woo-wallet' ); ?></span>
				<span class="twdw-growth__value">
					<?php
					if ( $growth['checkout_total_today'] > 0 ) {
						printf(
							/* translators: %s: wallet share of today's checkout revenue, as a percentage */
							esc_html__( '%s%%', 'woo-wallet' ),
							esc_html( number_format_i18n( $growth['wallet_share_percent'], 1 ) )
						);
					} else {
						esc_html_e( '—', 'woo-wallet' );
					}
					?>
				</span>
			</li>
		</ul>
	</div>

	<div id="woo-wallet-quick-action-notice" class="notice notice-success inline" style="display:none;"><p></p></div>

	<div class="twdw-actions">
		<button type="button" class="button twdw-quick-credit"><?php esc_html_e( 'Quick Credit', 'woo-wallet' ); ?></button>
		<a
			class="button"
			href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=woo_wallet_dashboard_export_today' ), 'woo-wallet-dashboard-export-today' ) ); ?>"
		><?php esc_html_e( "Export Today's Statement", 'woo-wallet' ); ?></a>
	</div>
</div><!-- .woo-wallet-dashboard-widget -->
</div><!-- .woo-wallet-overview-panel -->
<script type="text/javascript">
	jQuery(function ($) {
		// Scoped to the overview panel CSS class rather than a postbox ID
		// (the panel now lives inside the Reports page, not a wp-admin widget).
		var $widget = $('.woo-wallet-dashboard-widget');

		function refreshPeriod(period) {
			var $body = $widget.find('.twdw-body');
			if ($body.hasClass('is-loading')) {
				return;
			}
			$body.addClass('is-loading');

			$.post(ajaxurl, {
				action: 'woo_wallet_dashboard_widget_refresh',
				period: period,
				security: $body.data('security')
			}).done(function (response) {
				if (response && response.success) {
					$widget.find('.twdw-tab').removeClass('is-active');
					$widget.find('.twdw-tab[data-period="' + period + '"]').addClass('is-active');
					$body.html(response.data.html);
				}
			}).always(function () {
				$body.removeClass('is-loading');
			});
		}

		$widget.on('click', '.twdw-tab', function () {
			var period = $(this).data('period');
			if ($(this).hasClass('is-active')) {
				return;
			}
			refreshPeriod(period);
		});

		$widget.on('click', '.twdw-quick-credit', function (e) {
			e.preventDefault();
			$widget.find('#woo-wallet-quick-action-notice').hide();
			$(this).WCBackboneModal({ template: 'woo-wallet-modal-dashboard-quick-credit' });
		});

		$(document).on('click', '#woo-wallet-quick-credit-confirm', function (e) {
			e.preventDefault();
			var $btn    = $(this);
			var $modal  = $btn.closest('.wc-backbone-modal');
			var $error  = $modal.find('#woo-wallet-quick-credit-error');
			var user    = $.trim($modal.find('#woo-wallet-quick-credit-user').val());
			var amount  = parseFloat($modal.find('#woo-wallet-quick-credit-amount').val());
			var note    = $modal.find('#woo-wallet-quick-credit-note').val() || '';

			$error.hide().find('p').text('');

			if (!user || isNaN(amount) || amount <= 0) {
				$error.find('p').text('<?php echo esc_js( __( 'Enter a customer and an amount greater than zero.', 'woo-wallet' ) ); ?>');
				$error.show();
				return;
			}

			$btn.prop('disabled', true);

			$.post(ajaxurl, {
				action: 'woo_wallet_dashboard_widget_quick_credit',
				user: user,
				amount: amount,
				note: note,
				security: $widget.find('.twdw-body').data('security')
			}).done(function (response) {
				if (response && response.success) {
					$('.wc-backbone-modal-backdrop.modal-close').trigger('click');
					var $notice = $widget.find('#woo-wallet-quick-action-notice');
					$notice.find('p').text(response.data.message);
					$notice.show();
					var $active = $widget.find('.twdw-tab.is-active');
					refreshPeriod($active.length ? $active.data('period') : 'today');
				} else {
					$error.find('p').text((response && response.data && response.data.message) || '<?php echo esc_js( __( 'Something went wrong.', 'woo-wallet' ) ); ?>');
					$error.show();
				}
			}).fail(function () {
				$error.find('p').text('<?php echo esc_js( __( 'Something went wrong.', 'woo-wallet' ) ); ?>');
				$error.show();
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});
	});
</script>
