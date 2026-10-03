<?php
/**
 * Immediate email to administrators about a high-risk staff action.
 *
 * @package    StandaloneTech\TeraWallet
 * @subpackage Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abstracts/abstract-woo-wallet-email.php';
require_once __DIR__ . '/class-woo-wallet-email-approval-requested.php';

if ( ! class_exists( 'Woo_Wallet_Email_Staff_Alert' ) ) {

	/**
	 * Staff alert email.
	 */
	class Woo_Wallet_Email_Staff_Alert extends Woo_Wallet_Email {

		/**
		 * Class constructor.
		 */
		public function __construct() {
			$this->id             = 'wallet_staff_alert';
			$this->customer_email = false;
			$this->title          = __( 'Wallet staff alert', 'woo-wallet' );
			$this->description    = __( 'Sent to administrators the moment a staff member adjusts their own wallet, or deletes or edits wallet transaction history. The administrator who did it is not emailed about their own action.', 'woo-wallet' );
			$this->template_html  = 'emails/staff-alert.php';
			$this->template_plain = 'emails/plain/staff-alert.php';
			$this->template_base  = WOO_WALLET_ABSPATH . 'templates/';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
				'{event}'      => '',
			);
			parent::__construct();
		}

		/**
		 * Default subject.
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return __( '[{site_title}] Wallet alert: {event}', 'woo-wallet' );
		}

		/**
		 * Default heading.
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Wallet staff alert', 'woo-wallet' );
		}

		/**
		 * Send it.
		 *
		 * @param int $audit_id Audit row id.
		 */
		public function trigger( $audit_id ) {
			$row = Woo_Wallet_Audit::get( $audit_id );
			if ( ! $row ) {
				return;
			}
			$labels = Woo_Wallet_Audit::event_labels();
			$this->setup_locale();
			$this->object                  = $row;
			$this->placeholders['{event}'] = $labels[ $row->event ] ?? $row->event;
			$recipients                    = Woo_Wallet_Audit::administrator_emails( (int) $row->actor_id );
			if ( ! $recipients ) {
				// The only administrator did it themselves: still leave a
				// trace somewhere other than the database they control.
				$recipients = array( get_option( 'admin_email' ) );
			}
			$this->recipient = implode( ',', $recipients );
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
			$labels = Woo_Wallet_Audit::event_labels();
			return array(
				'event'              => $this->object,
				'event_label'        => $labels[ $this->object->event ] ?? $this->object->event,
				'actor'              => get_userdata( (int) $this->object->actor_id ),
				'customer'           => $this->object->customer_id ? get_userdata( (int) $this->object->customer_id ) : null,
				'activity_url'       => admin_url( 'admin.php?page=woo-wallet-staff&tab=activity' ),
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
			$this->form_fields = Woo_Wallet_Email_Approval_Requested::approval_form_fields( $this, '<code>{site_title}</code>, <code>{event}</code>' );
		}
	}
}

return new Woo_Wallet_Email_Staff_Alert();
