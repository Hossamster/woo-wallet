<?php
/**
 * Admin View: wp-admin dashboard widget — wallet financial snapshot shell.
 *
 * Renders the widget's styles, period tabs (Today / 7 Days / This Month),
 * and the initial snapshot body (templates/admin/dashboard-widget-body.php).
 * Clicking a tab re-fetches that body via AJAX
 * (Woo_Wallet_Dashboard_Widget::ajax_refresh) and swaps it in — no full
 * wp-admin page reload. Quick Actions and Growth Insights land in later
 * phases of the same feature — see the dashboard-widget feature plan.
 *
 * Expects `$data` (a Woo_Wallet_Dashboard_Widget_Data instance) and
 * `$snapshot` (from $data->get_snapshot()), both set by
 * Woo_Wallet_Dashboard_Widget::render() before including this file.
 *
 * @package StandaleneTech
 * @var Woo_Wallet_Dashboard_Widget_Data $data
 * @var array                            $snapshot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$period_labels = $data->period_labels();
$nonce         = wp_create_nonce( Woo_Wallet_Dashboard_Widget::AJAX_NONCE_ACTION );
?>
<style>
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
</style>
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

	<div id="woo-wallet-quick-action-notice" class="notice notice-success inline" style="display:none;"><p></p></div>

	<div class="twdw-actions">
		<button type="button" class="button twdw-quick-credit"><?php esc_html_e( 'Quick Credit', 'woo-wallet' ); ?></button>
		<a
			class="button"
			href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=woo_wallet_dashboard_export_today' ), 'woo-wallet-dashboard-export-today' ) ); ?>"
		><?php esc_html_e( "Export Today's Statement", 'woo-wallet' ); ?></a>
	</div>
</div>
<script type="text/javascript">
	jQuery(function ($) {
		// Scoped to this specific widget box (WordPress renders the widget
		// id as the postbox id), so multiple wp_add_dashboard_widget() boxes
		// on the same screen never cross-wire their tab clicks.
		var $widget = $('#<?php echo esc_js( Woo_Wallet_Dashboard_Widget::WIDGET_ID ); ?> .woo-wallet-dashboard-widget');

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
