<?php
/**
 * Wallet withdrawal requests WP_List_Table.
 *
 * Admin review queue over the `woo_wallet_withdrawals` table. Each pending
 * row carries an inline "Mark paid" / "Reject" form (both submit to the same
 * `admin_post_woo_wallet_withdrawal_process` handler in
 * Woo_Wallet_Withdrawal::handle_admin_process_request()).
 *
 * @package StandaleneTech
 * @since   1.7.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Withdrawal requests list table.
 */
class Woo_Wallet_Withdrawal_Report extends WP_List_Table {

	/**
	 * Class constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'withdrawal',
				'plural'   => 'withdrawals',
				'ajax'     => false,
				'screen'   => 'woo-wallet-withdrawals',
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'customer'    => __( 'Customer', 'woo-wallet' ),
			'amount'      => __( 'Amount', 'woo-wallet' ),
			'bank'        => __( 'Bank details', 'woo-wallet' ),
			'status'      => __( 'Status', 'woo-wallet' ),
			'date'        => __( 'Requested', 'woo-wallet' ),
			'actions'     => __( 'Actions', 'woo-wallet' ),
		);
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		esc_html_e( 'No withdrawal requests found.', 'woo-wallet' );
	}

	/**
	 * Status filter currently applied via $_GET.
	 *
	 * @return string
	 */
	private function current_status_filter() {
		$status = isset( $_GET['withdrawal_status'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $status, array( 'pending', 'paid', 'rejected' ), true ) ? $status : '';
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$per_page = 20;
		$current  = $this->get_pagenum();
		$args     = array();
		$status   = $this->current_status_filter();
		if ( $status ) {
			$args['status'] = $status;
		}

		$total_items = Woo_Wallet_Withdrawal::count_requests( $args );
		$this->items = Woo_Wallet_Withdrawal::get_requests(
			array_merge(
				$args,
				array(
					'limit'  => $per_page,
					'offset' => ( $current - 1 ) * $per_page,
				)
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Default column rendering.
	 *
	 * @param object $item        Withdrawal row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'customer':
				$user = get_userdata( $item->user_id );
				return $user ? esc_html( $user->user_email ) : '#' . (int) $item->user_id;

			case 'amount':
				$net    = wc_price( (float) $item->amount, array( 'currency' => $item->currency ? $item->currency : get_option( 'woocommerce_currency' ) ) );
				$charge = (float) $item->charge;
				$out    = wp_kses_post( $net );
				if ( $charge > 0 ) {
					$out .= '<br /><small>' . sprintf(
						/* translators: %s: charge amount */
						esc_html__( '+ %s charge', 'woo-wallet' ),
						wp_kses_post( wc_price( $charge, array( 'currency' => $item->currency ? $item->currency : get_option( 'woocommerce_currency' ) ) ) )
					) . '</small>';
				}
				return $out;

			case 'bank':
				$lines   = array();
				$lines[] = '<strong>' . esc_html( $item->bank_name ) . '</strong>';
				$lines[] = esc_html( $item->beneficiary_name );
				$lines[] = esc_html( $item->account_number );
				if ( ! empty( $item->iban ) ) {
					$lines[] = 'IBAN: ' . esc_html( $item->iban );
				}
				return implode( '<br />', $lines );

			case 'status':
				$labels = array(
					'pending'  => __( 'Pending', 'woo-wallet' ),
					'paid'     => __( 'Paid', 'woo-wallet' ),
					'rejected' => __( 'Rejected', 'woo-wallet' ),
				);
				$label = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;
				return '<span class="woo-wallet-withdrawal-status woo-wallet-withdrawal-status--' . esc_attr( $item->status ) . '">' . esc_html( $label ) . '</span>';

			case 'date':
				return esc_html( wc_string_to_datetime( $item->date_created )->date_i18n( wc_date_format() . ' ' . wc_time_format() ) );

			case 'actions':
				if ( 'pending' !== $item->status ) {
					return ! empty( $item->admin_note ) ? esc_html( $item->admin_note ) : '&ndash;';
				}
				ob_start();
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="woo-wallet-withdrawal-action-form">
					<input type="hidden" name="action" value="woo_wallet_withdrawal_process" />
					<input type="hidden" name="withdrawal_id" value="<?php echo esc_attr( $item->id ); ?>" />
					<?php wp_nonce_field( 'woo_wallet_withdrawal_process' ); ?>
					<input type="text" name="admin_note" placeholder="<?php esc_attr_e( 'Note (optional)', 'woo-wallet' ); ?>" style="width:100%;margin-bottom:4px;" />
					<button type="submit" name="ww_action" value="paid" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Mark this withdrawal as paid? Make sure you have already sent the bank transfer.', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Mark paid', 'woo-wallet' ); ?></button>
					<button type="submit" name="ww_action" value="reject" class="button" onclick="return confirm('<?php echo esc_js( __( 'Reject this request? The reserved amount will be returned to the customer wallet.', 'woo-wallet' ) ); ?>');"><?php esc_html_e( 'Reject', 'woo-wallet' ); ?></button>
				</form>
				<?php
				return ob_get_clean();
		}
		return '';
	}

	/**
	 * Filter controls above the table.
	 *
	 * @param string $which 'top' | 'bottom'.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$status = $this->current_status_filter();
		?>
		<div class="alignleft actions">
			<form method="get">
				<input type="hidden" name="page" value="woo-wallet-withdrawals" />
				<select name="withdrawal_status">
					<option value=""><?php esc_html_e( 'All statuses', 'woo-wallet' ); ?></option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'woo-wallet' ); ?></option>
					<option value="paid" <?php selected( $status, 'paid' ); ?>><?php esc_html_e( 'Paid', 'woo-wallet' ); ?></option>
					<option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Rejected', 'woo-wallet' ); ?></option>
				</select>
				<?php submit_button( __( 'Filter', 'woo-wallet' ), '', 'filter_action', false ); ?>
			</form>
		</div>
		<?php
	}
}
