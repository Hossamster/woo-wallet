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
			'id'           => __( 'ID', 'woo-wallet' ),
			'customer'     => __( 'Customer', 'woo-wallet' ),
			'amount'       => __( 'Amount', 'woo-wallet' ),
			'bank'         => __( 'Bank details', 'woo-wallet' ),
			'requested_by' => __( 'Requested by', 'woo-wallet' ),
			'status'       => __( 'Status', 'woo-wallet' ),
			'date'         => __( 'Requested', 'woo-wallet' ),
		);
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		esc_html_e( 'No withdrawal requests found.', 'woo-wallet' );
	}

	/**
	 * Resolve a customer search string (id, login or email) to a list of user
	 * ids. A plain-text search against display name can match more than one
	 * account, so this — unlike an exact id/login/email hit — returns every
	 * match rather than assuming the first one is what was meant.
	 *
	 * @param string $who Search string.
	 * @return int[] User ids (empty when nothing matches).
	 */
	private static function resolve_customers( $who ) {
		if ( is_numeric( $who ) ) {
			$user = get_user_by( 'id', absint( $who ) );
			return $user ? array( (int) $user->ID ) : array();
		}
		$user = get_user_by( 'login', $who );
		if ( ! $user ) {
			$user = get_user_by( 'email', $who );
		}
		if ( $user ) {
			return array( (int) $user->ID );
		}
		$query = new WP_User_Query(
			array(
				'search'         => '*' . $who . '*',
				'search_columns' => array( 'display_name' ),
				'fields'         => 'ID',
				'number'         => 50,
			)
		);
		return array_map( 'absint', (array) $query->get_results() );
	}

	/**
	 * Translate the current $_GET filters into Woo_Wallet_Withdrawal::get_requests()
	 * args. Shared by prepare_items() and extra_tablenav() so the two can never
	 * disagree about what's currently filtered.
	 *
	 * Returns false when a customer was searched for but not found, so the
	 * caller renders an empty result instead of silently falling back to
	 * "every customer".
	 *
	 * @return array|false
	 */
	public static function get_filter_args() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status       = isset( $_GET['withdrawal_status'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_status'] ) ) : '';
		$customer     = isset( $_GET['withdrawal_customer'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_customer'] ) ) : '';
		$bank         = isset( $_GET['withdrawal_bank'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_bank'] ) ) : '';
		$requested_by = isset( $_GET['withdrawal_requested_by'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_requested_by'] ) ) : '';
		$after        = isset( $_GET['withdrawal_after'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_after'] ) ) : '';
		$before       = isset( $_GET['withdrawal_before'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_before'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array();

		if ( in_array( $status, array( 'pending', 'processing', 'paid', 'rejected' ), true ) ) {
			$args['status'] = $status;
		}
		if ( '' !== $customer ) {
			$ids = self::resolve_customers( $customer );
			if ( ! $ids ) {
				return false;
			}
			$args['user_ids'] = $ids;
		}
		if ( '' !== $bank && isset( Woo_Wallet_Withdrawal::get_configured_banks()[ $bank ] ) ) {
			$args['bank_name'] = $bank;
		}
		if ( in_array( $requested_by, array( 'self', 'staff' ), true ) ) {
			$args['created_by'] = $requested_by;
		}
		if ( '' !== $after && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ) {
			$args['after'] = $after . ' 00:00:00';
		}
		if ( '' !== $before && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ) {
			$args['before'] = $before . ' 23:59:59';
		}

		return $args;
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$per_page = 20;
		$current  = $this->get_pagenum();
		$args     = self::get_filter_args();

		if ( false === $args ) {
			$this->items = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => $per_page,
				)
			);
			return;
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
		if ( 'pending' === $item->status ) {
			$label = __( 'Review', 'woo-wallet' );
		} elseif ( 'processing' === $item->status ) {
			$label = __( 'Recover', 'woo-wallet' );
		} else {
			$label = __( 'View', 'woo-wallet' );
		}
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->detail_url( $item->id ) ),
				esc_html( $label )
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
				if ( ! empty( $item->phone ) ) {
					$lines[] = 'Tel: ' . esc_html( $item->phone );
				}
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
	 * Filter controls above the table: customer search, status, bank,
	 * requested-by, and a date range.
	 *
	 * @param string $which 'top' | 'bottom'.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status       = isset( $_GET['withdrawal_status'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_status'] ) ) : '';
		$customer     = isset( $_GET['withdrawal_customer'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_customer'] ) ) : '';
		$bank         = isset( $_GET['withdrawal_bank'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_bank'] ) ) : '';
		$requested_by = isset( $_GET['withdrawal_requested_by'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_requested_by'] ) ) : '';
		$after        = isset( $_GET['withdrawal_after'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_after'] ) ) : '';
		$before       = isset( $_GET['withdrawal_before'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_before'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<form method="get">
				<input type="hidden" name="page" value="woo-wallet-withdrawals" />
				<input type="search" name="withdrawal_customer" value="<?php echo esc_attr( $customer ); ?>" placeholder="<?php esc_attr_e( 'Customer ID, login, email or name', 'woo-wallet' ); ?>" />
				<select name="withdrawal_status">
					<option value=""><?php esc_html_e( 'All statuses', 'woo-wallet' ); ?></option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'woo-wallet' ); ?></option>
					<option value="processing" <?php selected( $status, 'processing' ); ?>><?php esc_html_e( 'Processing (needs recovery)', 'woo-wallet' ); ?></option>
					<option value="paid" <?php selected( $status, 'paid' ); ?>><?php esc_html_e( 'Paid', 'woo-wallet' ); ?></option>
					<option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Rejected', 'woo-wallet' ); ?></option>
				</select>
				<select name="withdrawal_bank">
					<option value=""><?php esc_html_e( 'All banks', 'woo-wallet' ); ?></option>
					<?php foreach ( Woo_Wallet_Withdrawal::get_configured_banks() as $bank_value => $bank_label ) : ?>
						<option value="<?php echo esc_attr( $bank_value ); ?>" <?php selected( $bank, $bank_value ); ?>><?php echo esc_html( $bank_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="withdrawal_requested_by">
					<option value=""><?php esc_html_e( 'Self-service & staff', 'woo-wallet' ); ?></option>
					<option value="self" <?php selected( $requested_by, 'self' ); ?>><?php esc_html_e( 'Self-service only', 'woo-wallet' ); ?></option>
					<option value="staff" <?php selected( $requested_by, 'staff' ); ?>><?php esc_html_e( 'Staff-logged only', 'woo-wallet' ); ?></option>
				</select>
				<input type="date" name="withdrawal_after" value="<?php echo esc_attr( $after ); ?>" title="<?php esc_attr_e( 'From date', 'woo-wallet' ); ?>" />
				<input type="date" name="withdrawal_before" value="<?php echo esc_attr( $before ); ?>" title="<?php esc_attr_e( 'To date', 'woo-wallet' ); ?>" />
				<?php submit_button( __( 'Filter', 'woo-wallet' ), '', 'filter_action', false ); ?>
			</form>
		</div>
		<?php
	}
}
