<?php
/**
 * Wallet transaction details WP_List_Table
 *
 * Store-wide (or single-customer, when linked to with `&user_id=`) ledger
 * browser: every credit/debit across every wallet, filterable by customer,
 * category and date range. This is what answers "who transferred to whom
 * and when" without having to open each customer's own statement.
 *
 * @package StandaleneTech
 */

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Woo_Wallet_Transaction_Details extends WP_List_Table {

	/**
	 * Total number of found transactions for the current query
	 *
	 * @since 3.1.0
	 * @var int
	 */
	private $total_count = 0;
	/**
	 * Class constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'transaction',
				'plural'   => 'transactions',
				'ajax'     => false,
				'screen'   => 'wc-wallet-transactions',
			)
		);
	}
	/**
	 * Get all columns.
	 */
	public function get_columns() {
		return apply_filters(
			'manage_woo_wallet_transactions_columns',
			array(
				'name'           => __( 'Name', 'woo-wallet' ),
				'type'           => __( 'Type', 'woo-wallet' ),
				'category'       => __( 'Category', 'woo-wallet' ),
				'amount'         => __( 'Amount', 'woo-wallet' ),
				'details'        => __( 'Details', 'woo-wallet' ),
				'created_by'     => __( 'Created By', 'woo-wallet' ),
				'date'           => __( 'Date', 'woo-wallet' ),
				'transaction_id' => __( 'ID', 'woo-wallet' ),
			)
		);
	}

	/**
	 * Translate the current $_GET filters into `get_wallet_transactions()` args.
	 *
	 * Shared by the list table and its count query. Returns false when a
	 * customer was searched for but not found, so the caller can render an
	 * empty result instead of silently falling back to "everyone".
	 *
	 * @return array|false
	 */
	public function get_filter_args() {
		// The single-customer deep link (from "View all transactions" elsewhere
		// in the admin) always wins over the browse-all filter row.
		$linked_user_id = filter_input( INPUT_GET, 'user_id' );
		if ( null !== $linked_user_id && '' !== $linked_user_id ) {
			return array( 'user_id' => absint( $linked_user_id ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$who      = isset( $_GET['transaction_user'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_user'] ) ) : '';
		$category = isset( $_GET['transaction_category'] ) ? sanitize_key( wp_unslash( $_GET['transaction_category'] ) ) : '';
		$after    = isset( $_GET['transaction_after'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_after'] ) ) : '';
		$before   = isset( $_GET['transaction_before'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_before'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array( 'user_id' => 0 );

		if ( '' !== $who ) {
			$user_id = self::resolve_user( $who );
			if ( ! $user_id ) {
				return false;
			}
			$args['user_id'] = $user_id;
		}
		if ( '' !== $category ) {
			$args['category'] = $category;
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
	 * Resolve a customer search string (id, login or email) to a user id.
	 *
	 * @param string $who Search string.
	 * @return int User id, or 0 when not found.
	 */
	private static function resolve_user( $who ) {
		if ( is_numeric( $who ) ) {
			$user = get_user_by( 'id', absint( $who ) );
			return $user ? (int) $user->ID : 0;
		}
		$user = get_user_by( 'login', $who );
		if ( ! $user ) {
			$user = get_user_by( 'email', $who );
		}
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Prepare the items for the table to process
	 */
	public function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = $this->get_hidden_columns();
		$sortable = $this->get_sortable_columns();

		$per_page     = $this->get_items_per_page( 'transactions_per_page', 10 );
		$current_page = $this->get_pagenum();

		$data                  = $this->table_data( ( $current_page - 1 ) * $per_page, $per_page );
		$this->_column_headers = array( $columns, $hidden, $sortable );
		$this->items           = $data;

		$this->set_pagination_args(
			array(
				'total_items' => $this->total_count,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Output 'no users' message.
	 *
	 * @since 3.1.0
	 */
	public function no_items() {
		esc_html_e( 'No transactions found.', 'woo-wallet' );
	}

	/**
	 * Define which columns are hidden
	 *
	 * @return Array
	 */
	public function get_hidden_columns() {
		return array( 'transaction_id' );
	}

	/**
	 * Define the sortable columns
	 *
	 * @return Array
	 */
	public function get_sortable_columns() {
		return array();
	}

	/**
	 * Get the table data
	 *
	 * @param int $lower lower.
	 * @param int $uper uper.
	 * @return Array
	 */
	private function table_data( $lower = 0, $uper = 10 ) {
		$data = array();
		$args = $this->get_filter_args();
		if ( false === $args ) {
			$this->total_count = 0;
			return $data;
		}

		$transactions       = get_wallet_transactions(
			array_merge(
				$args,
				array(
					'limit'   => $lower . ',' . $uper,
					'nocache' => true, // A store-wide monitoring screen must never show a stale cached page.
				)
			)
		);
		$this->total_count  = get_wallet_transactions_count( $args );

		if ( ! empty( $transactions ) && is_array( $transactions ) ) {
			foreach ( $transactions as $key => $transaction ) {
				$user   = get_user_by( 'ID', $transaction->user_id );
				$data[] = array(
					'transaction_id' => $transaction->transaction_id,
					'name'           => $user ? $user->display_name : sprintf( '#%d', $transaction->user_id ),
					'type'           => ( 'credit' === $transaction->type ) ? __( 'Credit', 'woo-wallet' ) : __( 'Debit', 'woo-wallet' ),
					'category'       => function_exists( 'woo_wallet_get_transaction_type_label' ) ? woo_wallet_get_transaction_type_label( $transaction->category ) : $transaction->category,
					'amount'         => wc_price( $transaction->amount, woo_wallet_wc_price_args( $transaction->user_id, array( 'currency' => $transaction->currency ) ) ),
					'details'        => $transaction->details,
					'created_by'     => $transaction->created_by,
					'date'           => wc_string_to_datetime( $transaction->date )->date_i18n( wc_date_format() . ' ' . wc_time_format() ),
				);
			}
		}
		return $data;
	}

	/**
	 * Define what data to show on each column of the table
	 *
	 * @param  Array  $item        Data.
	 * @param  String $column_name - Current column name.
	 *
	 * @return Mixed
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'transaction_id':
			case 'name':
			case 'type':
			case 'category':
			case 'date':
				return esc_html( $item[ $column_name ] );
			default:
				return apply_filters( 'woo_wallet_transaction_details_column_default', print_r( $item, true ), $column_name, $item ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
	}
	/**
	 * Render amount column.
	 *
	 * @param array $item item.
	 * @return void
	 */
	protected function column_amount( $item ): void {
		echo $item['amount'] ? wp_kses_post( $item['amount'] ) : '<span class="na">&ndash;</span>';
	}

	/**
	 * Render details column.
	 *
	 * @param array $item item.
	 * @return void
	 */
	protected function column_details( $item ): void {
		echo $item['details'] ? wp_kses_post( $item['details'] ) : '<span class="na">&ndash;</span>';
	}

	/**
	 * Render created_by column.
	 *
	 * @param array $item item.
	 * @return void
	 */
	protected function column_created_by( $item ): void {
		if ( $item['created_by'] ) {
			$user = get_user_by( 'ID', $item['created_by'] );
			echo '<a href="' . esc_url( add_query_arg( 'user_id', $item['created_by'], self_admin_url( 'user-edit.php' ) ) ) . '">' . esc_html( $user ? $user->display_name : '#' . $item['created_by'] ) . '</a>';
		} else {
			echo '-';
		}
	}

	/**
	 * Filter controls above the table — hidden on the single-customer deep
	 * link view (`&user_id=`), where the customer is already fixed.
	 *
	 * @param string $which 'top' | 'bottom'.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$linked_user_id = filter_input( INPUT_GET, 'user_id' );
		if ( null !== $linked_user_id && '' !== $linked_user_id ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$who      = isset( $_GET['transaction_user'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_user'] ) ) : '';
		$category = isset( $_GET['transaction_category'] ) ? sanitize_key( wp_unslash( $_GET['transaction_category'] ) ) : '';
		$after    = isset( $_GET['transaction_after'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_after'] ) ) : '';
		$before   = isset( $_GET['transaction_before'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_before'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<?php /* Relies on the enclosing <form id="posts-filter" method="get"> printed by Woo_Wallet_Admin::transaction_details_page() — no <form> here, to avoid nesting it. */ ?>
			<input type="search" name="transaction_user" value="<?php echo esc_attr( $who ); ?>" placeholder="<?php esc_attr_e( 'Customer ID, login or email', 'woo-wallet' ); ?>" />
			<select name="transaction_category">
				<option value=""><?php esc_html_e( 'All categories', 'woo-wallet' ); ?></option>
				<?php foreach ( (array) woo_wallet_get_transaction_types() as $slug => $cfg ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $category, $slug ); ?>><?php echo esc_html( $cfg['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="transaction_after" value="<?php echo esc_attr( $after ); ?>" title="<?php esc_attr_e( 'From date', 'woo-wallet' ); ?>" />
			<input type="date" name="transaction_before" value="<?php echo esc_attr( $before ); ?>" title="<?php esc_attr_e( 'To date', 'woo-wallet' ); ?>" />
			<?php submit_button( __( 'Filter', 'woo-wallet' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
