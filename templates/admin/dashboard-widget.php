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
	/*
	 * Overview panel — styled from the Reports page's own design tokens
	 * (--twr-*, defined on .woo-wallet-reports, which this panel renders
	 * inside) so it reads as part of that page rather than a separate
	 * box: same surface/border/radius cards, same accent, same label and
	 * figure typography, same button treatment as the top bar.
	 */
	.woo-wallet-overview-panel {
		margin: 0 0 28px;
	}
	.woo-wallet-dashboard-widget {
		display: flex;
		flex-direction: column;
		gap: 18px;
		max-width: 100%;
	}
	.woo-wallet-dashboard-widget .twdw-head {
		align-items: center;
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		justify-content: space-between;
	}
	.woo-wallet-dashboard-widget .twdw-title {
		color: var(--twr-ink, #181b27);
		font-size: 15px;
		font-weight: 600;
		letter-spacing: -.01em;
		margin: 0;
		padding: 0;
	}
	/* Period switcher: a segmented control, so it reads as a filter for the
	   figures below rather than a second row of page tabs above "Summary". */
	.woo-wallet-dashboard-widget .twdw-tabs {
		background: var(--twr-surface, #fff);
		border: 1px solid var(--twr-border, #e7e8ec);
		border-radius: 10px;
		display: inline-flex;
		gap: 2px;
		padding: 3px;
	}
	.woo-wallet-dashboard-widget .twdw-tab {
		background: transparent;
		border: 0;
		border-radius: 7px;
		color: var(--twr-muted, #6b7180);
		cursor: pointer;
		font: inherit;
		font-size: 13px;
		font-weight: 600;
		line-height: 1;
		padding: 8px 14px;
		transition: background .15s, color .15s;
	}
	.woo-wallet-dashboard-widget .twdw-tab:hover {
		color: var(--twr-text, #1c1f2a);
	}
	.woo-wallet-dashboard-widget .twdw-tab.is-active {
		background: var(--twr-accent, #5b5bd6);
		color: #fff;
	}
	.woo-wallet-dashboard-widget .twdw-body {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.woo-wallet-dashboard-widget .twdw-body.is-loading {
		opacity: .5;
	}
	.woo-wallet-dashboard-widget .twdw-alerts {
		margin: 0;
	}
	.woo-wallet-dashboard-widget .twdw-alert {
		align-items: flex-start;
		background: #fdf3f3;
		border: 1px solid #f4d4d4;
		border-radius: 12px;
		color: var(--twr-text, #1c1f2a);
		display: flex;
		font-size: 13px;
		gap: 10px;
		line-height: 1.5;
		margin: 0;
		padding: 12px 16px;
	}
	.woo-wallet-dashboard-widget .twdw-alert .dashicons {
		color: var(--twr-red, #cf4040);
		flex: none;
	}
	.woo-wallet-dashboard-widget .twdw-alert a {
		color: inherit;
		font-weight: 600;
	}
	.woo-wallet-dashboard-widget .twdw-grid {
		display: grid;
		gap: 18px;
		grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
		margin: 0;
	}
	/* Same card + figure treatment as the Reports page's own stat cards. */
	.woo-wallet-dashboard-widget .twdw-stat {
		background: var(--twr-surface, #fff);
		border: 1px solid var(--twr-border, #e7e8ec);
		border-radius: 16px;
		padding: 22px 22px 24px;
	}
	.woo-wallet-dashboard-widget .twdw-stat__label {
		color: var(--twr-faint, #9aa0ad);
		display: block;
		font-size: 11px;
		font-weight: 600;
		letter-spacing: .08em;
		text-transform: uppercase;
	}
	.woo-wallet-dashboard-widget .twdw-stat__value {
		color: var(--twr-ink, #181b27);
		display: block;
		font-family: "Space Grotesk", sans-serif;
		font-size: 34px;
		font-weight: 600;
		letter-spacing: -.02em;
		line-height: 1;
		margin-top: 16px;
	}
	.woo-wallet-dashboard-widget .twdw-stat__value.is-credit {
		color: var(--twr-green, #15976a);
	}
	.woo-wallet-dashboard-widget .twdw-stat__value.is-debit {
		color: var(--twr-red, #cf4040);
	}
	.woo-wallet-dashboard-widget .twdw-growth {
		background: var(--twr-surface, #fff);
		border: 1px solid var(--twr-border, #e7e8ec);
		border-radius: 18px;
		padding: 22px 24px 10px;
	}
	.woo-wallet-dashboard-widget .twdw-growth__title {
		color: var(--twr-faint, #9aa0ad);
		font-size: 11px;
		font-weight: 600;
		letter-spacing: .08em;
		margin: 0 0 6px;
		text-transform: uppercase;
	}
	.woo-wallet-dashboard-widget .twdw-growth__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}
	.woo-wallet-dashboard-widget .twdw-growth__list li {
		align-items: center;
		border-top: 1px solid var(--twr-border, #e7e8ec);
		display: flex;
		font-size: 13px;
		gap: 12px;
		justify-content: space-between;
		margin: 0;
		padding: 13px 0;
	}
	.woo-wallet-dashboard-widget .twdw-growth__list li:first-child {
		border-top: 0;
	}
	.woo-wallet-dashboard-widget .twdw-growth__label {
		color: var(--twr-muted, #6b7180);
	}
	.woo-wallet-dashboard-widget .twdw-growth__value {
		color: var(--twr-ink, #181b27);
		font-weight: 600;
		white-space: nowrap;
	}
	.woo-wallet-dashboard-widget .twdw-actions {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
	}
	/* Same button treatment as the Reports top bar (.twr-actions .button). */
	.woo-wallet-dashboard-widget .twdw-actions .button {
		align-items: center;
		background: #fff;
		border: 1px solid var(--twr-border, #e7e8ec);
		border-radius: 10px;
		box-shadow: none;
		color: var(--twr-accent, #5b5bd6);
		display: inline-flex;
		font-family: inherit;
		font-size: 13px;
		font-weight: 600;
		height: auto;
		line-height: 1;
		margin: 0;
		padding: 10px 16px;
		text-decoration: none;
	}
	.woo-wallet-dashboard-widget .twdw-actions .button:hover,
	.woo-wallet-dashboard-widget .twdw-actions .button:focus {
		border-color: var(--twr-accent-soft, #8487e0);
		color: var(--twr-accent, #5b5bd6);
	}
	.woo-wallet-dashboard-widget .twdw-actions .twdw-quick-credit {
		background: var(--twr-accent, #5b5bd6);
		border-color: var(--twr-accent, #5b5bd6);
		color: #fff;
	}
	.woo-wallet-dashboard-widget .twdw-actions .twdw-quick-credit:hover,
	.woo-wallet-dashboard-widget .twdw-actions .twdw-quick-credit:focus {
		background: #4d4dc4;
		border-color: #4d4dc4;
		color: #fff;
	}
	.woo-wallet-dashboard-widget .notice {
		border-radius: 10px;
		margin: 0;
	}
</style>
<div class="woo-wallet-overview-panel">
<div class="woo-wallet-dashboard-widget">
	<div class="twdw-head">
		<h2 class="twdw-title"><?php esc_html_e( 'Activity', 'woo-wallet' ); ?></h2>
		<nav class="twdw-tabs">
			<?php foreach ( $period_labels as $period => $label ) : ?>
				<button
					type="button"
					class="twdw-tab<?php echo $period === $snapshot['period'] ? ' is-active' : ''; ?>"
					data-period="<?php echo esc_attr( $period ); ?>"
				><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</nav>
	</div>

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
