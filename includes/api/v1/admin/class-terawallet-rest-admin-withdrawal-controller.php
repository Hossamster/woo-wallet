<?php
/**
 * REST API: terawallet/v1/admin/withdrawals
 *
 * Admin surface over the wallet withdrawal review queue. Mirrors every
 * action the "Axfit Wallet → Withdrawals" screens expose:
 *   - GET  /admin/withdrawals              paginated, filterable list
 *   - POST /admin/withdrawals              manually log a request (staff-attributed)
 *   - GET  /admin/withdrawals/{id}         single request + full notes thread
 *   - POST /admin/withdrawals/{id}/process mark paid | reject
 *   - POST /admin/withdrawals/{id}/recover resolve a request stuck 'processing'
 *   - POST /admin/withdrawals/{id}/notes   add a note (public or private)
 *
 * Every money-moving route (create, process, recover) delegates to the exact
 * same `Woo_Wallet_Withdrawal::admin_*()` methods the classic admin-post
 * handlers use, so there is exactly one implementation of the
 * concurrency-safe / idempotent-refund logic regardless of which surface
 * calls it — see class-woo-wallet-withdrawal.php for that logic itself.
 *
 * A receipt is attached by `receipt_id`: upload the file to `wp/v2/media`
 * first (standard WordPress media endpoint), then pass the resulting id
 * here. This controller does not accept a raw file upload itself.
 *
 * @package StandaleneTech
 * @since   1.7.4
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TeraWallet_REST_Admin_Withdrawal_Controller' ) ) {

	/**
	 * Admin withdrawal controller.
	 */
	class TeraWallet_REST_Admin_Withdrawal_Controller extends TeraWallet_REST_Admin_Controller_Base {

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'admin/withdrawals';

		/**
		 * Allowed receipt mime types — matches the admin UI's own restriction
		 * regardless of what `wp/v2/media` itself would otherwise accept.
		 *
		 * @var string[]
		 */
		const ALLOWED_RECEIPT_MIMES = array( 'application/pdf', 'image/png', 'image/jpeg' );

		/**
		 * Register routes.
		 */
		public function register_routes() {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_items' ),
						'permission_callback' => array( $this, 'permissions_read' ),
						'args'                => $this->get_collection_params(),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'create_item' ),
						'permission_callback' => array( $this, 'permissions_write' ),
						'args'                => $this->get_create_args(),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)',
				array(
					'args' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'Withdrawal request id.', 'woo-wallet' ),
						),
					),
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_item' ),
						'permission_callback' => array( $this, 'permissions_read' ),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/process',
				array(
					'args' => array(
						'id' => array( 'type' => 'integer' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'process_item' ),
						'permission_callback' => array( $this, 'permissions_write' ),
						'args'                => array(
							'action'        => array(
								'required' => true,
								'type'     => 'string',
								'enum'     => array( 'paid', 'reject' ),
							),
							'reference_no'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
							'receipt_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
							'note'          => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
							'note_visibility' => array( 'type' => 'string', 'enum' => array( 'public', 'private' ), 'default' => 'private' ),
						),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/recover',
				array(
					'args' => array(
						'id' => array( 'type' => 'integer' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'recover_item' ),
						'permission_callback' => array( $this, 'permissions_write' ),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/notes',
				array(
					'args' => array(
						'id' => array( 'type' => 'integer' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'add_note' ),
						'permission_callback' => array( $this, 'permissions_write' ),
						'args'                => array(
							'note'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
							'visibility' => array( 'type' => 'string', 'enum' => array( 'public', 'private' ), 'default' => 'private' ),
						),
					),
				)
			);
		}

		/* ---------------- permissions ---------------- */

		/**
		 * Withdrawal mutations are edit-context (harder than create).
		 *
		 * @param WP_REST_Request $request The request.
		 * @return true|WP_Error
		 */
		public function permissions_write( $request ) {
			return $this->check_capability( 'edit', $request );
		}

		/* ---------------- argument schemas ---------------- */

		/**
		 * Collection (list) params.
		 *
		 * @return array
		 */
		public function get_collection_params() {
			return array(
				'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint' ),
				'status'   => array( 'type' => 'string', 'enum' => array( 'pending', 'processing', 'paid', 'rejected' ) ),
				'user_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'search'   => array( 'type' => 'string', 'description' => __( 'Match a customer by login, email or display name.', 'woo-wallet' ), 'sanitize_callback' => 'sanitize_text_field' ),
			);
		}

		/**
		 * Manual-create args.
		 *
		 * @return array
		 */
		protected function get_create_args() {
			return array(
				'user_id'          => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'amount'           => array( 'required' => true, 'type' => 'number', 'minimum' => 0.01 ),
				'bank_name'        => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'beneficiary_name' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'account_number'   => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'iban'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'reference_no'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'status'           => array( 'type' => 'string', 'enum' => array( 'pending', 'paid' ), 'default' => 'pending' ),
				'receipt_id'       => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'note'             => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'note_visibility'  => array( 'type' => 'string', 'enum' => array( 'public', 'private' ), 'default' => 'private' ),
			);
		}

		/* ---------------- helpers ---------------- */

		/**
		 * Validate a `receipt_id` param: must be an existing attachment with
		 * an allowed mime type. Returns 0 (no receipt supplied — fine, it is
		 * optional everywhere) or the validated id, or a WP_Error.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return int|WP_Error
		 */
		protected function resolve_receipt_id( $request ) {
			$receipt_id = (int) $request->get_param( 'receipt_id' );
			if ( ! $receipt_id ) {
				return 0;
			}
			if ( 'attachment' !== get_post_type( $receipt_id ) ) {
				return $this->error( 'terawallet_rest_invalid_receipt', __( 'receipt_id is not a valid media attachment.', 'woo-wallet' ), 400 );
			}
			$mime = get_post_mime_type( $receipt_id );
			if ( ! in_array( $mime, self::ALLOWED_RECEIPT_MIMES, true ) ) {
				return $this->error( 'terawallet_rest_invalid_receipt_type', __( 'The receipt must be a PDF, PNG or JPG.', 'woo-wallet' ), 400 );
			}
			return $receipt_id;
		}

		/* ---------------- handlers ---------------- */

		/**
		 * List/filter withdrawal requests.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public function get_items( $request ) {
			$args = array();
			if ( $request->get_param( 'status' ) ) {
				$args['status'] = $request->get_param( 'status' );
			}
			if ( $request->get_param( 'user_id' ) ) {
				$args['user_id'] = (int) $request->get_param( 'user_id' );
			} elseif ( $request->get_param( 'search' ) ) {
				$user_query = new WP_User_Query(
					array(
						'search'         => '*' . $request->get_param( 'search' ) . '*',
						'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
						'fields'         => 'ID',
						'number'         => 50,
					)
				);
				$user_ids = array_map( 'absint', $user_query->get_results() );
				if ( empty( $user_ids ) ) {
					$response = new WP_REST_Response( array(), 200 );
					return $this->add_pagination_headers( $response, 0, 1, (int) $request->get_param( 'per_page' ) );
				}
				// Woo_Wallet_Withdrawal::get_requests() only filters on a single
				// user_id, not an array — narrow to the first match rather than
				// silently ignoring the rest of a multi-hit search.
				$args['user_id'] = $user_ids[0];
			}

			$page     = max( 1, (int) $request->get_param( 'page' ) );
			$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

			$total = Woo_Wallet_Withdrawal::count_requests( $args );
			$rows  = Woo_Wallet_Withdrawal::get_requests(
				array_merge(
					$args,
					array(
						'limit'  => $per_page,
						'offset' => ( $page - 1 ) * $per_page,
					)
				)
			);

			$items = array();
			foreach ( $rows as $row ) {
				$items[] = $this->prepare_item_for_response( $row, $request )->get_data();
			}

			$response = new WP_REST_Response( $items, 200 );
			return $this->add_pagination_headers( $response, $total, $page, $per_page );
		}

		/**
		 * Fetch a single withdrawal request.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_item( $request ) {
			$row = Woo_Wallet_Withdrawal::get_request( (int) $request['id'] );
			if ( ! $row ) {
				return $this->error( 'terawallet_rest_withdrawal_not_found', __( 'Withdrawal request not found.', 'woo-wallet' ), 404 );
			}
			return $this->prepare_item_for_response( $row, $request );
		}

		/**
		 * Manually log a withdrawal for a customer (idempotent).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function create_item( $request ) {
			if ( ! get_userdata( (int) $request->get_param( 'user_id' ) ) ) {
				return $this->error( 'terawallet_rest_invalid_user', __( 'Invalid user id.', 'woo-wallet' ), 404 );
			}
			$receipt_id = $this->resolve_receipt_id( $request );
			if ( is_wp_error( $receipt_id ) ) {
				return $receipt_id;
			}

			$idem_key = $this->require_idempotency_key( $request );
			if ( is_wp_error( $idem_key ) ) {
				return $idem_key;
			}
			$admin_id = get_current_user_id();
			return WooWallet_Idempotency::run(
				$admin_id,
				'admin_withdrawal_create:' . $idem_key,
				function () use ( $request, $receipt_id, $admin_id ) {
					$result = Woo_Wallet_Withdrawal::admin_create(
						(int) $request->get_param( 'user_id' ),
						(float) $request->get_param( 'amount' ),
						(string) $request->get_param( 'bank_name' ),
						(string) $request->get_param( 'beneficiary_name' ),
						(string) $request->get_param( 'account_number' ),
						(string) $request->get_param( 'iban' ),
						$admin_id,
						(string) $request->get_param( 'status' ),
						(string) $request->get_param( 'reference_no' ),
						$receipt_id,
						(string) $request->get_param( 'note' ),
						(string) $request->get_param( 'note_visibility' )
					);
					if ( empty( $result['is_valid'] ) ) {
						return $this->error( 'terawallet_rest_withdrawal_create_failed', $result['message'], 400 );
					}
					$row      = Woo_Wallet_Withdrawal::get_request( (int) $result['id'] );
					$response = $this->prepare_item_for_response( $row, $request );
					$response->set_status( 201 );
					return $response;
				}
			);
		}

		/**
		 * Mark a pending request paid or rejected (idempotent).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function process_item( $request ) {
			$id = (int) $request['id'];
			if ( ! Woo_Wallet_Withdrawal::get_request( $id ) ) {
				return $this->error( 'terawallet_rest_withdrawal_not_found', __( 'Withdrawal request not found.', 'woo-wallet' ), 404 );
			}
			$receipt_id = $this->resolve_receipt_id( $request );
			if ( is_wp_error( $receipt_id ) ) {
				return $receipt_id;
			}

			$idem_key = $this->require_idempotency_key( $request );
			if ( is_wp_error( $idem_key ) ) {
				return $idem_key;
			}
			$admin_id = get_current_user_id();
			return WooWallet_Idempotency::run(
				$admin_id,
				'admin_withdrawal_process:' . $id . ':' . $idem_key,
				function () use ( $request, $id, $receipt_id, $admin_id ) {
					$result = Woo_Wallet_Withdrawal::admin_process(
						$id,
						(string) $request->get_param( 'action' ),
						$admin_id,
						(string) $request->get_param( 'reference_no' ),
						$receipt_id,
						(string) $request->get_param( 'note' ),
						(string) $request->get_param( 'note_visibility' )
					);
					if ( empty( $result['is_valid'] ) ) {
						return $this->error( 'terawallet_rest_withdrawal_process_failed', $result['message'], 409 );
					}
					$row = Woo_Wallet_Withdrawal::get_request( $id );
					return $this->prepare_item_for_response( $row, $request );
				}
			);
		}

		/**
		 * Recover a request stuck on 'processing' (idempotent).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function recover_item( $request ) {
			$id = (int) $request['id'];
			if ( ! Woo_Wallet_Withdrawal::get_request( $id ) ) {
				return $this->error( 'terawallet_rest_withdrawal_not_found', __( 'Withdrawal request not found.', 'woo-wallet' ), 404 );
			}

			$idem_key = $this->require_idempotency_key( $request );
			if ( is_wp_error( $idem_key ) ) {
				return $idem_key;
			}
			$admin_id = get_current_user_id();
			return WooWallet_Idempotency::run(
				$admin_id,
				'admin_withdrawal_recover:' . $id . ':' . $idem_key,
				function () use ( $request, $id, $admin_id ) {
					$result = Woo_Wallet_Withdrawal::admin_recover( $id, $admin_id );
					if ( empty( $result['is_valid'] ) ) {
						return $this->error( 'terawallet_rest_withdrawal_recover_failed', $result['message'], 409 );
					}
					$row = Woo_Wallet_Withdrawal::get_request( $id );
					return $this->prepare_item_for_response( $row, $request );
				}
			);
		}

		/**
		 * Add a note to a request. Not idempotency-gated — it moves no money,
		 * so a duplicate on retry is a cosmetic annoyance, not a safety issue.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function add_note( $request ) {
			$id = (int) $request['id'];
			if ( ! Woo_Wallet_Withdrawal::get_request( $id ) ) {
				return $this->error( 'terawallet_rest_withdrawal_not_found', __( 'Withdrawal request not found.', 'woo-wallet' ), 404 );
			}
			Woo_Wallet_Withdrawal::add_note(
				$id,
				(string) $request->get_param( 'note' ),
				(string) $request->get_param( 'visibility' ),
				get_current_user_id()
			);
			$row = Woo_Wallet_Withdrawal::get_request( $id );
			return $this->prepare_item_for_response( $row, $request );
		}

		/* ---------------- projection ---------------- */

		/**
		 * Project a withdrawal row into the admin REST shape — every column,
		 * embedded user blocks, and the full notes thread (public + private).
		 *
		 * @param object          $row     Withdrawal row.
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response
		 */
		public function prepare_item_for_response( $row, $request ) {
			$currency   = $row->currency ? $row->currency : get_woocommerce_currency();
			$price_args = array( 'currency' => $currency );

			$notes = array();
			foreach ( Woo_Wallet_Withdrawal::get_notes( $row->id ) as $note_row ) {
				$author  = $note_row->created_by ? get_user_by( 'ID', $note_row->created_by ) : null;
				$notes[] = array(
					'id'         => (int) $note_row->id,
					'note'       => $note_row->note,
					'visibility' => $note_row->visibility,
					'created_by' => $note_row->created_by ? (int) $note_row->created_by : null,
					'author'     => $author ? $author->display_name : null,
					'date'       => mysql_to_rfc3339( $note_row->date_created ),
				);
			}

			$created_by   = (int) $row->created_by;
			$processed_by = (int) $row->processed_by;

			$data = array(
				'id'                    => (int) $row->id,
				'user_id'               => (int) $row->user_id,
				'user'                  => $this->resolve_user_block( $row->user_id ),
				'amount'                => (float) $row->amount,
				'charge'                => (float) $row->charge,
				'currency'              => $currency,
				'bank_name'             => $row->bank_name,
				'beneficiary_name'      => $row->beneficiary_name,
				'account_number'        => $row->account_number,
				'iban'                  => $row->iban ? $row->iban : null,
				'reference_no'          => $row->reference_no ? $row->reference_no : null,
				'receipt_id'            => $row->receipt_id ? (int) $row->receipt_id : null,
				'receipt_url'           => $row->receipt_id ? wp_get_attachment_url( $row->receipt_id ) : null,
				'status'                => $row->status,
				'transaction_id'        => (int) $row->transaction_id,
				'refund_transaction_id' => $row->refund_transaction_id ? (int) $row->refund_transaction_id : null,
				'created_by'            => $created_by ?: null,
				'created_by_user'       => $created_by ? $this->resolve_user_block( $created_by ) : null,
				'self_service'          => $created_by > 0 && $created_by === (int) $row->user_id,
				'processed_by'          => $processed_by ?: null,
				'processed_by_user'     => $processed_by ? $this->resolve_user_block( $processed_by ) : null,
				'notes'                 => $notes,
				'date_created'          => mysql_to_rfc3339( $row->date_created ),
				'date_updated'          => $row->date_updated ? mysql_to_rfc3339( $row->date_updated ) : null,
				'formatted'             => array(
					'amount' => wp_strip_all_tags( wc_price( (float) $row->amount, $price_args ) ),
					'charge' => (float) $row->charge > 0 ? wp_strip_all_tags( wc_price( (float) $row->charge, $price_args ) ) : null,
				),
			);

			$response = rest_ensure_response( $data );
			$response->add_links(
				array(
					'self'       => array( 'href' => rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $data['id'] ) ) ),
					'collection' => array( 'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) ),
					'user'       => array( 'href' => rest_url( sprintf( 'wp/v2/users/%d', (int) $row->user_id ) ), 'embeddable' => true ),
				)
			);
			return apply_filters( 'woo_wallet_rest_prepare_admin_withdrawal', $response, $row, $request );
		}

		/**
		 * Schema.
		 *
		 * @return array
		 */
		public function get_item_schema() {
			$schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'wallet_admin_withdrawal',
				'type'       => 'object',
				'properties' => array(
					'id'                    => array( 'type' => 'integer', 'context' => array( 'view' ), 'readonly' => true ),
					'user_id'               => array( 'type' => 'integer', 'context' => array( 'view' ) ),
					'user'                  => array( 'type' => array( 'object', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'amount'                => array( 'type' => 'number', 'context' => array( 'view' ) ),
					'charge'                => array( 'type' => 'number', 'context' => array( 'view' ), 'readonly' => true ),
					'currency'              => array( 'type' => 'string', 'context' => array( 'view' ), 'readonly' => true ),
					'bank_name'             => array( 'type' => 'string', 'context' => array( 'view' ) ),
					'beneficiary_name'      => array( 'type' => 'string', 'context' => array( 'view' ) ),
					'account_number'        => array( 'type' => 'string', 'context' => array( 'view' ) ),
					'iban'                  => array( 'type' => array( 'string', 'null' ), 'context' => array( 'view' ) ),
					'reference_no'          => array( 'type' => array( 'string', 'null' ), 'context' => array( 'view' ) ),
					'receipt_id'            => array( 'type' => array( 'integer', 'null' ), 'context' => array( 'view' ) ),
					'receipt_url'           => array( 'type' => array( 'string', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'status'                => array( 'type' => 'string', 'enum' => array( 'pending', 'processing', 'paid', 'rejected' ), 'context' => array( 'view' ), 'readonly' => true ),
					'transaction_id'        => array( 'type' => 'integer', 'context' => array( 'view' ), 'readonly' => true ),
					'refund_transaction_id' => array( 'type' => array( 'integer', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'created_by'            => array( 'type' => array( 'integer', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'created_by_user'       => array( 'type' => array( 'object', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'self_service'          => array( 'type' => 'boolean', 'context' => array( 'view' ), 'readonly' => true ),
					'processed_by'          => array( 'type' => array( 'integer', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'processed_by_user'     => array( 'type' => array( 'object', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'notes'                 => array( 'type' => 'array', 'context' => array( 'view' ), 'readonly' => true ),
					'date_created'          => array( 'type' => 'string', 'format' => 'date-time', 'context' => array( 'view' ), 'readonly' => true ),
					'date_updated'          => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time', 'context' => array( 'view' ), 'readonly' => true ),
					'formatted'             => array( 'type' => 'object', 'context' => array( 'view' ), 'readonly' => true ),
				),
			);
			return $this->add_additional_fields_schema( $schema );
		}
	}
}
