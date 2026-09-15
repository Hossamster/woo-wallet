<?php
/**
 * Wallet withdrawal requests WP_List_Table.
 *
 * Admin review queue over the `woo_wallet_withdrawals` table. Each row links
 * to the request's detail screen (`Woo_Wallet_Withdrawal::render_admin_detail()`),
 * which carries the notes thread and, while pending, the mark-paid/reject form.
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
			'id'          => __( 'ID', 'woo-wallet' ),
			'customer'    => __( 'Customer', 'woo-wallet' ),
			'amount'      => __( 'Amount', 'woo-wallet' ),
			'bank'        => __( 'Bank details', 'woo-wallet' ),
			'requested_by' => __( 'Requested by', 'woo-wallet' ),
			'status'      => __( 'Status', 'woo-wallet' ),
			'date'        => __( 'Requested', 'woo-wallet' ),
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
	 * The URL to a request's detail screen.
	 *
	 * @param int $id Request id.
	 * @return string
	 */
	private function detail_url( $id ) {
		return add_query_arg(
			array(
				'page'   => 'woo-wallet-withdrawals',
				'action' => 'view',
				'id'     => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Row actions under the ID column (the primary column).
	 *
	 * @param object $item        Withdrawal row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->detail_url( $item->id ) ),
				'pending' === $item->status ? esc_html__( 'Review', 'woo-wallet' ) : esc_html__( 'View', 'woo-wallet' )
			),
		);
		return $this->row_actions( $actions );
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
			case 'id':
				return '<a href="' . esc_url( $this->detail_url( $item->id ) ) . '"><strong>#' . (int) $item->id . '</strong></a>';

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

			case 'requested_by':
				$created_by = (int) $item->created_by;
				if ( $created_by && $created_by === (int) $item->user_id ) {
					return esc_html__( 'Self-service', 'woo-wallet' );
				}
				if ( $created_by ) {
					$staff = get_userdata( $created_by );
					return esc_html( sprintf(
						/* translators: %s: staff member name */
						__( 'Staff: %s', 'woo-wallet' ),
						$staff ? $staff->display_name : '#' . $created_by
					) );
				}
				return '&ndash;';

			case 'status':
				$labels = array(
					'pending'    => __( 'Pending', 'woo-wallet' ),
					// Transient: only set while a Reject is actively being
					// processed (between claiming the row and the refund
					// resolving). Should never be visible for more than an
					// instant — see handle_admin_process_request().
					'processing' => __( 'Processing', 'woo-wallet' ),
					'paid'       => __( 'Paid', 'woo-wallet' ),
					'rejected'   => __( 'Rejected', 'woo-wallet' ),
				);
				$label = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;
				return '<span class="woo-wallet-withdrawal-status woo-wallet-withdrawal-status--' . esc_attr( $item->status ) . '">' . esc_html( $label ) . '</span>';

			case 'date':
				return esc_html( wc_string_to_datetime( $item->date_created )->date_i18n( wc_date_format() . ' ' . wc_time_format() ) );
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
