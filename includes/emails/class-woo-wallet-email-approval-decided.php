<?php
/**
 * Email to the support agent: their request was approved, rejected, or
 * could not be carried out.
 *
 * @package    StandaloneTech\TeraWallet
 * @subpackage Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abstracts/abstract-woo-wallet-email.php';
require_once __DIR__ . '/class-woo-wallet-email-approval-requested.php';

if ( ! class_exists( 'Woo_Wallet_Email_Approval_Decided' ) ) {

	/**
	 * Approval decided email.
	 */
	class Woo_Wallet_Email_Approval_Decided extends Woo_Wallet_Email {

		/**
		 * Class constructor.
		 */
		public function __construct() {
			$this->id             = 'wallet_approval_decided';
			$this->customer_email = false;
			$this->title          = __( 'Wallet approval decided', 'woo-wallet' );
			$this->description    = __( 'Sent to the support agent who sent a request for approval once it is approved, rejected, or could not be carried out.', 'woo-wallet' );
			$this->template_html  = 'emails/approval-decided.php';
			$this->template_plain = 'emails/plain/approval-decided.php';
			$this->template_base  = WOO_WALLET_ABSPATH . 'templates/';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
				'{request_id}' => '',
				'{status}'     => '',
			);
			parent::__construct();
		}

		/**
		 * Default subject.
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return __( '[{site_title}] Your wallet request #{request_id}: {status}', 'woo-wallet' );
		}

		/**
		 * Default heading.
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Your wallet request: {status}', 'woo-wallet' );
		}

		/**
		 * Send it.
		 *
		 * @param int $request_id Request id.
		 */
		public function trigger( $request_id ) {
			$row       = Woo_Wallet_Approvals::get( $request_id );
			$requester = $row ? get_userdata( (int) $row->requested_by ) : false;
			if ( ! $row || ! $requester ) {
				return;
			}
			$statuses = Woo_Wallet_Approvals::statuses();
			$this->setup_locale();
			$this->object                       = $row;
			$this->placeholders['{request_id}'] = (int) $row->id;
			$this->placeholders['{status}']     = $statuses[ $row->status ] ?? $row->status;
			$this->recipient                    = $requester->user_email;
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
			$row      = $this->object;
			$types    = Woo_Wallet_Approvals::types();
			$statuses = Woo_Wallet_Approvals::statuses();
			return array(
				'request'            => $row,
				'type_label'         => $types[ $row->type ] ?? $row->type,
				'status_label'       => $statuses[ $row->status ] ?? $row->status,
				'customer'           => get_userdata( (int) $row->customer_id ),
				'decider'            => $row->decided_by ? get_userdata( (int) $row->decided_by ) : null,
				'requests_url'       => Woo_Wallet_Approvals::page_url(),
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
			$this->form_fields = Woo_Wallet_Email_Approval_Requested::approval_form_fields( $this, '<code>{site_title}</code>, <code>{request_id}</code>, <code>{status}</code>' );
		}
	}
}

return new Woo_Wallet_Email_Approval_Decided();
