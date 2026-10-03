<?php
/**
 * Email to approvers: a support agent sent a request for approval.
 *
 * @package    StandaloneTech\TeraWallet
 * @subpackage Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abstracts/abstract-woo-wallet-email.php';

if ( ! class_exists( 'Woo_Wallet_Email_Approval_Requested' ) ) {

	/**
	 * Approval requested email.
	 */
	class Woo_Wallet_Email_Approval_Requested extends Woo_Wallet_Email {

		/**
		 * Class constructor.
		 */
		public function __construct() {
			$this->id             = 'wallet_approval_requested';
			$this->customer_email = false;
			$this->title          = __( 'Wallet approval requested', 'woo-wallet' );
			$this->description    = __( 'Sent to the shop managers and administrators chosen under Axfit Wallet → Staff when a support agent sends a request for approval.', 'woo-wallet' );
			$this->template_html  = 'emails/approval-requested.php';
			$this->template_plain = 'emails/plain/approval-requested.php';
			$this->template_base  = WOO_WALLET_ABSPATH . 'templates/';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
				'{request_id}' => '',
			);
			parent::__construct();
		}

		/**
		 * Default subject.
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return __( '[{site_title}] Wallet request #{request_id} needs your approval', 'woo-wallet' );
		}

		/**
		 * Default heading.
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'A wallet request needs your approval', 'woo-wallet' );
		}

		/**
		 * Send it.
		 *
		 * @param int $request_id Request id.
		 */
		public function trigger( $request_id ) {
			$row = Woo_Wallet_Approvals::get( $request_id );
			if ( ! $row ) {
				return;
			}
			$this->setup_locale();
			$this->object                       = $row;
			$this->placeholders['{request_id}'] = (int) $row->id;
			$recipients                         = Woo_Wallet_Approvals::email_recipients();
			$this->recipient                    = implode( ',', $recipients['emails'] );
			if ( $this->is_enabled() && $this->get_recipient() ) {
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
			$this->restore_locale();
		}

		/**
		 * Template arguments.
		 *
		 * @param bool $plain_text Plain text.
		 * @return array
		 */
		protected function get_template_args( $plain_text ) {
			$row     = $this->object;
			$details = Woo_Wallet_Approvals::details( $row );
			foreach ( array( 'account_number', 'iban' ) as $key ) {
				if ( ! empty( $details[ $key ] ) ) {
					$details[ $key ] = Woo_Wallet_Security::mask( $details[ $key ], 4 );
				}
			}
			$types = Woo_Wallet_Approvals::types();
			return array(
				'request'            => $row,
				'type_label'         => $types[ $row->type ] ?? $row->type,
				'details'            => $details,
				'customer'           => get_userdata( (int) $row->customer_id ),
				'requester'          => get_userdata( (int) $row->requested_by ),
				'review_url'         => Woo_Wallet_Approvals::page_url(),
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => $plain_text,
				'email'              => $this,
			);
		}

		/**
		 * HTML content.
		 *
		 * @return string
		 */
		public function get_content_html() {
			return wc_get_template_html( $this->template_html, $this->get_template_args( false ), 'woo-wallet', $this->template_base );
		}

		/**
		 * Plain content.
		 *
		 * @return string
		 */
		public function get_content_plain() {
			return wc_get_template_html( $this->template_plain, $this->get_template_args( true ), 'woo-wallet', $this->template_base );
		}

		/**
		 * Settings fields.
		 */
		public function init_form_fields() {
			$this->form_fields = Woo_Wallet_Email_Approval_Requested::approval_form_fields( $this, '<code>{site_title}</code>, <code>{request_id}</code>' );
		}

		/**
		 * Settings fields shared by both approval emails.
		 *
		 * @param Woo_Wallet_Email $email        Email.
		 * @param string           $placeholders Placeholder list for the help text.
		 * @return array
		 */
		public static function approval_form_fields( $email, $placeholders ) {
			return array(
				'enabled'            => array(
					'title'   => __( 'Enable/Disable', 'woo-wallet' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'woo-wallet' ),
					'default' => 'yes',
				),
				'subject'            => array(
					'title'       => __( 'Subject', 'woo-wallet' ),
					'type'        => 'text',
					'desc_tip'    => true,
					/* translators: %s: list of available placeholders */
					'description' => sprintf( __( 'Available placeholders: %s', 'woo-wallet' ), $placeholders ),
					'placeholder' => $email->get_default_subject(),
					'default'     => '',
				),
				'heading'            => array(
					'title'       => __( 'Email heading', 'woo-wallet' ),
					'type'        => 'text',
					'desc_tip'    => true,
					/* translators: %s: list of available placeholders */
					'description' => sprintf( __( 'Available placeholders: %s', 'woo-wallet' ), $placeholders ),
					'placeholder' => $email->get_default_heading(),
					'default'     => '',
				),
				'additional_content' => array(
					'title'       => __( 'Additional content', 'woo-wallet' ),
					'description' => __( 'Text to appear below the main email content.', 'woo-wallet' ),
					'css'         => 'width:400px; height: 75px;',
					'placeholder' => __( 'N/A', 'woo-wallet' ),
					'type'        => 'textarea',
					'default'     => '',
					'desc_tip'    => true,
				),
				'email_type'         => array(
					'title'       => __( 'Email type', 'woo-wallet' ),
					'type'        => 'select',
					'description' => __( 'Choose which format of email to send.', 'woo-wallet' ),
					'default'     => 'html',
					'class'       => 'email_type wc-enhanced-select',
					'options'     => $email->get_email_type_options(),
					'desc_tip'    => true,
				),
			);
		}
	}
}

return new Woo_Wallet_Email_Approval_Requested();
