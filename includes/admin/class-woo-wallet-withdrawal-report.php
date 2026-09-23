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
	 * Sortable columns. The array key is the column id (get_columns()); the
	 * `orderby` value it puts in the URL is mapped to a real, whitelisted
	 * DB column name in get_filter_args() — never used to build SQL
	 * directly from user input.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'id'     => array( 'id', false ),
			'amount' => array( 'amount', false ),
			'date'   => array( 'date', false ),
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
	public static function resolve_customers( $who ) {
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
	 * Status views links (tabs at the top of the table).
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$status_counts  = Woo_Wallet_Withdrawal::count_requests_by_status();
		$current_status = isset( $_GET['withdrawal_status'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Base url preserving current query parameters except 'withdrawal_status' and 'paged'.
		$base_url = remove_query_arg( array( 'withdrawal_status', 'paged' ) );

		$statuses = array(
			''           => array(
				'label' => __( 'All', 'woo-wallet' ),
				'count' => $status_counts['all'],
			),
			'pending'    => array(
				'label' => __( 'Pending', 'woo-wallet' ),
				'count' => $status_counts['pending'],
			),
			'processing' => array(
				'label' => __( 'Processing', 'woo-wallet' ),
				'count' => $status_counts['processing'],
			),
			'paid'       => array(
				'label' => __( 'Paid', 'woo-wallet' ),
				'count' => $status_counts['paid'],
			),
			'rejected'   => array(
				'label' => __( 'Rejected', 'woo-wallet' ),
				'count' => $status_counts['rejected'],
			),
		);

		$views = array();
		foreach ( $statuses as $status_key => $data ) {
			if ( 'processing' === $status_key && 0 === $data['count'] && 'processing' !== $current_status ) {
				continue;
			}

			$url   = '' === $status_key ? $base_url : add_query_arg( 'withdrawal_status', $status_key, $base_url );
			$class = ( $current_status === $status_key ) ? ' class="current"' : '';

			$label_html = esc_html( $data['label'] );
			if ( 'processing' === $status_key && $data['count'] > 0 ) {
				$label_html .= ' <span class="update-plugins count-' . (int) $data['count'] . '"><span class="processing-count" style="color:#d63638;font-weight:600;">(' . number_format_i18n( $data['count'] ) . ')</span></span>';
			} else {
				$label_html .= ' <span class="count">(' . number_format_i18n( $data['count'] ) . ')</span>';
			}

			$views[ $status_key ? $status_key : 'all' ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				$class,
				$label_html
			);
		}

		return $views;
	}

	/**
	 * Read and sanitize every filter field straight from $_GET, with no
	 * business validation (whitelisting, date-format checks, etc.) applied
	 * yet — that happens in get_filter_args(), the only consumer that needs
	 * validated args to build a DB query. extra_tablenav() needs the raw
	 * values too, just to redisplay whatever the user actually typed/picked
	 * (including something not yet valid, e.g. a half-typed date), so it
	 * calls this directly instead of get_filter_args(). Kept as the single
	 * place that reads these $_GET keys, so a new filter field is added in
	 * one place rather than two.
	 *
	 * @return array
	 */
	private static function get_raw_filter_values() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['withdrawal_search'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_search'] ) ) : '';
		if ( '' === $search && isset( $_GET['withdrawal_customer'] ) ) {
			$search = sanitize_text_field( wp_unslash( $_GET['withdrawal_customer'] ) );
		}

		return array(
			'status'       => isset( $_GET['withdrawal_status'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_status'] ) ) : '',
			'search'       => $search,
			'bank'         => isset( $_GET['withdrawal_bank'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_bank'] ) ) : '',
			'receipt'      => isset( $_GET['withdrawal_receipt'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_receipt'] ) ) : '',
			'requested_by' => isset( $_GET['withdrawal_requested_by'] ) ? sanitize_key( wp_unslash( $_GET['withdrawal_requested_by'] ) ) : '',
			'processed_by' => isset( $_GET['withdrawal_processed_by'] ) ? absint( $_GET['withdrawal_processed_by'] ) : 0,
			'min_amount'   => isset( $_GET['withdrawal_min_amount'] ) && '' !== trim( (string) $_GET['withdrawal_min_amount'] ) ? (float) $_GET['withdrawal_min_amount'] : null,
			'max_amount'   => isset( $_GET['withdrawal_max_amount'] ) && '' !== trim( (string) $_GET['withdrawal_max_amount'] ) ? (float) $_GET['withdrawal_max_amount'] : null,
			'after'        => isset( $_GET['withdrawal_after'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_after'] ) ) : '',
			'before'       => isset( $_GET['withdrawal_before'] ) ? sanitize_text_field( wp_unslash( $_GET['withdrawal_before'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Translate the current $_GET filters into Woo_Wallet_Withdrawal::get_requests()
	 * args. Shared by prepare_items() and extra_tablenav() (via
	 * get_raw_filter_values()) so the two can never disagree about what's
	 * currently filtered.
	 *
	 * @return array|false
	 */
	public static function get_filter_args() {
		$raw = self::get_raw_filter_values();
		$args = array();

		if ( in_array( $raw['status'], array( 'pending', 'processing', 'paid', 'rejected' ), true ) ) {
			$args['status'] = $raw['status'];
		}
		if ( '' !== $raw['search'] ) {
			$args['search'] = $raw['search'];
		}
		if ( '' !== $raw['bank'] && isset( Woo_Wallet_Withdrawal::get_configured_banks()[ $raw['bank'] ] ) ) {
			$args['bank_name'] = $raw['bank'];
		}
		if ( in_array( $raw['receipt'], array( 'has', 'missing' ), true ) ) {
			$args['receipt'] = $raw['receipt'];
		}
		if ( in_array( $raw['requested_by'], array( 'self', 'staff' ), true ) ) {
			$args['created_by'] = $raw['requested_by'];
		}
		if ( $raw['processed_by'] > 0 ) {
			$args['processed_by'] = $raw['processed_by'];
		}
		if ( null !== $raw['min_amount'] && $raw['min_amount'] >= 0 ) {
			$args['min_amount'] = $raw['min_amount'];
		}
		if ( null !== $raw['max_amount'] && $raw['max_amount'] >= 0 ) {
			$args['max_amount'] = $raw['max_amount'];
		}
		if ( '' !== $raw['after'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw['after'] ) ) {
			$args['after'] = $raw['after'] . ' 00:00:00';
		}
		if ( '' !== $raw['before'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw['before'] ) ) {
			$args['before'] = $raw['before'] . ' 23:59:59';
		}

		$orderby_map = array(
			'id'     => 'id',
			'amount' => 'amount',
			'date'   => 'date_created',
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( isset( $orderby_map[ $orderby ] ) ) {
			$args['orderby'] = $orderby_map[ $orderby ];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['order'] = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		}

		return $args;
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

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
				$out  = $user ? esc_html( $user->user_email ) : '#' . (int) $item->user_id;
				if ( ! empty( $item->phone ) ) {
					$out .= '<br /><span class="description" style="color:#50575e;font-size:12px;"><span class="dashicons dashicons-phone" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span> ' . esc_html( $item->phone ) . '</span>';
				}
				return $out;

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
	 * Filter controls above the table. Split into two tiers: search + bank
	 * + Filter/Export/Reset are always visible (the filters most people
	 * reach for); receipt, requested-by, processed-by, amount range and
	 * date range live behind an "Advanced filters" <details> toggle,
	 * auto-expanded whenever one of them is already active so a filter
	 * already in effect is never hidden from view. A native <details>
	 * element rather than custom JS: every field inside it is still part
	 * of the form and submits normally whether expanded or collapsed, and
	 * it needs no enqueued script on a plugin with no build pipeline for
	 * new admin JS (see the dashboard-widget class docblock for the same
	 * reasoning elsewhere in this plugin).
	 *
	 * @param string $which 'top' | 'bottom'.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$raw = self::get_raw_filter_values();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby_map = array( 'id', 'amount', 'date' );
		$orderby     = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order       = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$orderby = in_array( $orderby, $orderby_map, true ) ? $orderby : '';

		$has_advanced_filters = '' !== $raw['receipt'] || '' !== $raw['requested_by'] || $raw['processed_by'] > 0
			|| null !== $raw['min_amount'] || null !== $raw['max_amount'] || '' !== $raw['after'] || '' !== $raw['before'];
		$has_active_filters = $has_advanced_filters || '' !== $raw['status'] || '' !== $raw['search'] || '' !== $raw['bank'];
		$staff_members      = Woo_Wallet_Withdrawal::get_processing_staff();
		?>
		<style>
			.woo-wallet-withdrawal-filters {
				background: #fff;
				padding: 10px;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				margin-bottom: 8px;
			}
			.woo-wallet-withdrawal-filters form {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 8px;
			}
			.woo-wallet-withdrawal-filters__advanced {
				flex-basis: 100%;
				margin: 4px 0 0;
				padding-top: 8px;
				border-top: 1px solid #dcdcde;
			}
			.woo-wallet-withdrawal-filters__advanced summary {
				cursor: pointer;
				font-weight: 600;
				color: #2271b1;
			}
			.woo-wallet-withdrawal-filters__advanced-fields {
				display: flex;
				flex-wrap: wrap;
				align-items: center;
				gap: 8px;
				margin-top: 8px;
			}
			.woo-wallet-withdrawal-filters__date-label {
				color: #646970;
			}
		</style>
		<div class="alignleft actions woo-wallet-withdrawal-filters">
			<form method="get">
				<input type="hidden" name="page" value="woo-wallet-withdrawals" />
				<?php if ( '' !== $raw['status'] ) : ?>
					<input type="hidden" name="withdrawal_status" value="<?php echo esc_attr( $raw['status'] ); ?>" />
				<?php endif; ?>
				<?php if ( '' !== $orderby ) : ?>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
					<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
				<?php endif; ?>

				<input type="search" name="withdrawal_search" value="<?php echo esc_attr( $raw['search'] ); ?>" placeholder="<?php esc_attr_e( 'Customer, phone, account #, IBAN, ref...', 'woo-wallet' ); ?>" style="width: 240px;" />
				<select name="withdrawal_bank">
					<option value=""><?php esc_html_e( 'All banks', 'woo-wallet' ); ?></option>
					<?php foreach ( Woo_Wallet_Withdrawal::get_configured_banks() as $bank_value => $bank_label ) : ?>
						<option value="<?php echo esc_attr( $bank_value ); ?>" <?php selected( $raw['bank'], $bank_value ); ?>><?php echo esc_html( $bank_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'woo-wallet' ), '', 'filter_action', false ); ?>
				<?php submit_button( __( 'Export CSV', 'woo-wallet' ), 'secondary', 'export_action', false ); ?>
				<?php if ( $has_active_filters ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-wallet-withdrawals' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'woo-wallet' ); ?></a>
				<?php endif; ?>

				<details class="woo-wallet-withdrawal-filters__advanced" <?php echo $has_advanced_filters ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Advanced filters', 'woo-wallet' ); ?></summary>
					<div class="woo-wallet-withdrawal-filters__advanced-fields">
						<select name="withdrawal_receipt">
							<option value=""><?php esc_html_e( 'All receipts', 'woo-wallet' ); ?></option>
							<option value="has" <?php selected( $raw['receipt'], 'has' ); ?>><?php esc_html_e( 'With receipt', 'woo-wallet' ); ?></option>
							<option value="missing" <?php selected( $raw['receipt'], 'missing' ); ?>><?php esc_html_e( 'Missing receipt', 'woo-wallet' ); ?></option>
						</select>
						<select name="withdrawal_requested_by">
							<option value=""><?php esc_html_e( 'All creators', 'woo-wallet' ); ?></option>
							<option value="self" <?php selected( $raw['requested_by'], 'self' ); ?>><?php esc_html_e( 'Self-service only', 'woo-wallet' ); ?></option>
							<option value="staff" <?php selected( $raw['requested_by'], 'staff' ); ?>><?php esc_html_e( 'Staff-logged only', 'woo-wallet' ); ?></option>
						</select>
						<?php if ( ! empty( $staff_members ) ) : ?>
							<select name="withdrawal_processed_by">
								<option value=""><?php esc_html_e( 'All processors', 'woo-wallet' ); ?></option>
								<?php foreach ( $staff_members as $staff_id => $staff_name ) : ?>
									<option value="<?php echo esc_attr( $staff_id ); ?>" <?php selected( $raw['processed_by'], $staff_id ); ?>>
										<?php echo esc_html( sprintf( __( 'Processed by: %s', 'woo-wallet' ), $staff_name ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<input type="number" step="any" min="0" name="withdrawal_min_amount" value="<?php echo null !== $raw['min_amount'] ? esc_attr( $raw['min_amount'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'Min amount', 'woo-wallet' ); ?>" style="width: 100px;" />
						<input type="number" step="any" min="0" name="withdrawal_max_amount" value="<?php echo null !== $raw['max_amount'] ? esc_attr( $raw['max_amount'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'Max amount', 'woo-wallet' ); ?>" style="width: 100px;" />
						<span class="woo-wallet-withdrawal-filters__date-label"><?php esc_html_e( 'From:', 'woo-wallet' ); ?></span>
						<input type="date" name="withdrawal_after" value="<?php echo esc_attr( $raw['after'] ); ?>" title="<?php esc_attr_e( 'From date', 'woo-wallet' ); ?>" />
						<span class="woo-wallet-withdrawal-filters__date-label"><?php esc_html_e( 'To:', 'woo-wallet' ); ?></span>
						<input type="date" name="withdrawal_before" value="<?php echo esc_attr( $raw['before'] ); ?>" title="<?php esc_attr_e( 'To date', 'woo-wallet' ); ?>" />
					</div>
				</details>
			</form>
		</div>
		<?php
	}

	/**
	 * Summary strip: total amount + count for the current filter — the
	 * same filter shape prepare_items() already computed for the table
	 * itself, just summed instead of paginated. Rendered by the caller
	 * (Woo_Wallet_Withdrawal::render_admin_list()) between views() and
	 * display(), same page but outside this table's own markup, since
	 * WP_List_Table has no dedicated "above the table, below the tabs"
	 * hook of its own.
	 */
	public function render_summary_totals() {
		$args  = self::get_filter_args();
		$total = false === $args ? 0 : Woo_Wallet_Withdrawal::count_requests( $args );
		$sum   = false === $args ? 0.0 : Woo_Wallet_Withdrawal::sum_requests_amount( $args );
		?>
		<p class="woo-wallet-withdrawal-summary" style="margin: 8px 0; font-size: 14px;">
			<span class="dashicons dashicons-chart-bar" style="vertical-align: middle;"></span>
			<?php
			printf(
				/* translators: 1: total amount formatted as currency, 2: number of requests */
				esc_html(
					_n(
						'Total amount for %2$d displayed request: %1$s',
						'Total amount for %2$d displayed requests: %1$s',
						$total,
						'woo-wallet'
					)
				),
				wp_kses_post( wc_price( $sum ) ),
				(int) $total
			);
			?>
		</p>
		<?php
	}
}
