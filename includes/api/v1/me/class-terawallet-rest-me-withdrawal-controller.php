<?php
/**
 * GET  /terawallet/v1/me/withdrawals
 * POST /terawallet/v1/me/withdrawals
 * GET  /terawallet/v1/me/withdrawals/{id}
 *
 * Customer self-service wallet withdrawal requests. Routes through
 * `Woo_Wallet_Withdrawal::submit_request()` — the same validation the
 * frontend "Withdraw" tab form uses — so the two surfaces can never drift.
 *
 * Idempotency: the SPA generates a UUID per submission, sends it as
 * `Idempotency-Key`. Replays return the original response verbatim.
 *
 * @package StandaleneTech
 * @since   1.7.4
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TeraWallet_REST_Me_Withdrawal_Controller' ) ) {

	/**
	 * Customer withdrawal controller.
	 */
	class TeraWallet_REST_Me_Withdrawal_Controller extends TeraWallet_REST_Me_Controller_Base {

		/**
		 * REST base.
		 *
		 * @var string
		 */
		protected $rest_base = 'me/withdrawals';

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
						'permission_callback' => array( $this, 'check_me_permissions' ),
						'args'                => array(
							'page'     => array(
								'type'              => 'integer',
								'default'           => 1,
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page' => array(
								'type'              => 'integer',
								'default'           => 20,
								'minimum'           => 1,
								'maximum'           => 100,
								'sanitize_callback' => 'absint',
							),
						),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'create_item' ),
						'permission_callback' => array( $this, 'check_withdraw_permissions' ),
						'args'                => array(
							'amount'           => array(
								'required'          => true,
								'type'              => 'number',
								'minimum'           => 0.01,
								'description'       => __( 'Requested payout amount.', 'woo-wallet' ),
								'sanitize_callback' => function ( $v ) {
									return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $v ) : (float) $v;
								},
								'validate_callback' => 'rest_validate_request_arg',
							),
							'bank_name'        => array(
								'required'          => true,
								'type'              => 'string',
								'description'       => __( 'Must exactly match one of the bank names returned by /terawallet/v1/settings/public (withdrawal.banks).', 'woo-wallet' ),
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'beneficiary_name' => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'account_number'   => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => 'rest_validate_request_arg',
							),
							'iban'             => array(
								'type'              => 'string',
								'description'       => __( 'Optional. Egyptian IBAN: EG followed by 27 digits.', 'woo-wallet' ),
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => 'rest_validate_request_arg',
							),
						),
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
						'permission_callback' => array( $this, 'check_me_permissions' ),
					),
				)
			);
		}

		/**
		 * Permission gate for the create route: logged-in + withdrawal feature enabled.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public function check_withdraw_permissions( $request ) {
			$base = $this->check_me_permissions( $request );
			if ( is_wp_error( $base ) ) {
				return $base;
			}
			if ( ! class_exists( 'Woo_Wallet_Withdrawal' ) || ! Woo_Wallet_Withdrawal::is_enabled_static() ) {
				return $this->error( 'rest_withdrawal_disabled', __( 'Wallet withdrawals are disabled on this site.', 'woo-wallet' ), 403 );
			}
			return true;
		}

		/**
		 * List the current user's own withdrawal requests, newest first.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public function get_items( $request ) {
			$user_id  = $this->current_user_id();
			$page     = max( 1, (int) $request->get_param( 'page' ) );
			$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

			$total = Woo_Wallet_Withdrawal::count_requests( array( 'user_id' => $user_id ) );
			$rows  = Woo_Wallet_Withdrawal::get_requests(
				array(
					'user_id' => $user_id,
					'limit'   => $per_page,
					'offset'  => ( $page - 1 ) * $per_page,
				)
			);

			$items = array();
			foreach ( $rows as $row ) {
				$items[] = $this->prepare_item_for_response( $row, $request )->get_data();
			}

			$response = new WP_REST_Response( $items, 200 );
			$response = $this->add_pagination_headers( $response, $total, $page, $per_page );
			return $this->private_no_store( $response );
		}

		/**
		 * Fetch a single request owned by the current user.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_item( $request ) {
			$row = Woo_Wallet_Withdrawal::get_request( (int) $request['id'] );
			if ( ! $row ) {
				return $this->error( 'rest_withdrawal_not_found', __( 'Withdrawal request not found.', 'woo-wallet' ), 404 );
			}
			$owned = $this->confirm_owner( $row->user_id, 'withdrawal' );
			if ( is_wp_error( $owned ) ) {
				return $owned;
			}
			return $this->private_no_store( $this->prepare_item_for_response( $row, $request ) );
		}

		/**
		 * Submit a new withdrawal request (idempotent on `Idempotency-Key`).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function create_item( $request ) {
			return $this->idempotent(
				$request,
				function () use ( $request ) {
					$user_id = $this->current_user_id();
					$result  = Woo_Wallet_Withdrawal::submit_request(
						$user_id,
						(float) $request->get_param( 'amount' ),
						(string) $request->get_param( 'bank_name' ),
						(string) $request->get_param( 'beneficiary_name' ),
						(string) $request->get_param( 'account_number' ),
						(string) $request->get_param( 'iban' )
					);

					if ( empty( $result['is_valid'] ) ) {
						return $this->error( 'rest_withdrawal_request_failed', $result['message'], 400 );
					}

					$row      = Woo_Wallet_Withdrawal::get_request( (int) $result['id'] );
					$response = $this->prepare_item_for_response( $row, $request );
					$response->set_status( 201 );
					return $this->private_no_store( $response );
				}
			);
		}

		/**
		 * Project a withdrawal row into the REST-facing shape. Only PUBLIC
		 * notes are included — private (staff-only) notes never leave the
		 * admin namespace.
		 *
		 * @param object          $row     Withdrawal row.
		 * @param WP_REST_Request $request The request.
		 * @return WP_REST_Response
		 */
		public function prepare_item_for_response( $row, $request ) {
			$currency    = $row->currency ? $row->currency : get_woocommerce_currency();
			$price_args  = array( 'currency' => $currency );
			$notes       = array();
			foreach ( Woo_Wallet_Withdrawal::get_notes( $row->id, 'public' ) as $note_row ) {
				$notes[] = array(
					'note' => $note_row->note,
					'date' => mysql_to_rfc3339( $note_row->date_created ),
				);
			}

			$data = array(
				'id'               => (int) $row->id,
				'amount'           => (float) $row->amount,
				'charge'           => (float) $row->charge,
				'currency'         => $currency,
				'bank_name'        => $row->bank_name,
				'beneficiary_name' => $row->beneficiary_name,
				'account_number'   => $row->account_number,
				'iban'             => $row->iban ? $row->iban : null,
				'reference_no'     => $row->reference_no ? $row->reference_no : null,
				'status'           => 'processing' === $row->status ? 'pending' : $row->status, // internal transient state reads as 'pending' to customers.
				'notes'            => $notes,
				'date_created'     => mysql_to_rfc3339( $row->date_created ),
				'date_updated'     => $row->date_updated ? mysql_to_rfc3339( $row->date_updated ) : null,
				'formatted'        => array(
					'amount' => wp_strip_all_tags( wc_price( (float) $row->amount, $price_args ) ),
					'charge' => (float) $row->charge > 0 ? wp_strip_all_tags( wc_price( (float) $row->charge, $price_args ) ) : null,
				),
			);

			$response = rest_ensure_response( $data );
			$response->add_links(
				array(
					'self'       => array( 'href' => rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $data['id'] ) ) ),
					'collection' => array( 'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) ),
				)
			);
			return apply_filters( 'woo_wallet_rest_prepare_me_withdrawal', $response, $row, $request );
		}

		/**
		 * Schema.
		 *
		 * @return array
		 */
		public function get_item_schema() {
			$schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'wallet_me_withdrawal',
				'type'       => 'object',
				'properties' => array(
					'id'               => array( 'type' => 'integer', 'context' => array( 'view' ), 'readonly' => true ),
					'amount'           => array( 'type' => 'number', 'context' => array( 'view' ) ),
					'charge'           => array( 'type' => 'number', 'context' => array( 'view' ), 'readonly' => true ),
					'currency'         => array( 'type' => 'string', 'context' => array( 'view' ), 'readonly' => true ),
					'bank_name'        => array( 'type' => 'string', 'context' => array( 'view' ) ),
					'beneficiary_name' => array( 'type' => 'string', 'context' => array( 'view' ) ),
					'account_number'   => array( 'type' => 'string', 'context' => array( 'view' ) ),
					'iban'             => array( 'type' => array( 'string', 'null' ), 'context' => array( 'view' ) ),
					'reference_no'     => array( 'type' => array( 'string', 'null' ), 'context' => array( 'view' ), 'readonly' => true ),
					'status'           => array( 'type' => 'string', 'enum' => array( 'pending', 'paid', 'rejected' ), 'context' => array( 'view' ), 'readonly' => true ),
					'notes'            => array( 'type' => 'array', 'context' => array( 'view' ), 'readonly' => true ),
					'date_created'     => array( 'type' => 'string', 'format' => 'date-time', 'context' => array( 'view' ), 'readonly' => true ),
					'date_updated'     => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time', 'context' => array( 'view' ), 'readonly' => true ),
					'formatted'        => array( 'type' => 'object', 'context' => array( 'view' ), 'readonly' => true ),
				),
			);
			return $this->add_additional_fields_schema( $schema );
		}
	}
}
