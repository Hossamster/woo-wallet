<?php
/**
 * Daily email to administrators: every manual balance change of the last
 * 24 hours, sensitive staff actions, and anything that looks off.
 *
 * @package    StandaloneTech\TeraWallet
 * @subpackage Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abstracts/abstract-woo-wallet-email.php';
require_once __DIR__ . '/class-woo-wallet-email-approval-requested.php';

if ( ! class_exists( 'Woo_Wallet_Email_Daily_Digest' ) ) {

	/**
	 * Daily digest email.
	 */
	class Woo_Wallet_Email_Daily_Digest extends Woo_Wallet_Email {

		/**
		 * Class constructor.
		 */
		public function __construct() {
			$this->id             = 'wallet_daily_digest';
			$this->customer_email = false;
			$this->title          = __( 'Wallet daily staff digest', 'woo-wallet' );
			$this->description    = __( 'Sent to administrators every morning: every manual wallet credit and debit of the last 24 hours, sensitive staff actions, and anything that looks suspicious. Sent even when nothing happened, so a missing email is noticeable.', 'woo-wallet' );
			$this->template_html  = 'emails/daily-digest.php';
			$this->template_plain = 'emails/plain/daily-digest.php';
			$this->template_base  = WOO_WALLET_ABSPATH . 'templates/';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
				'{summary}'    => '',
			);
			parent::__construct();
		}

		/**
		 * Default subject.
		 *
		 * @return string
		 */
		public function get_default_subject() {
			return __( '[{site_title}] Wallet daily digest: {summary}', 'woo-wallet' );
		}

		/**
		 * Default heading.
		 *
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Wallet activity in the last 24 hours', 'woo-wallet' );
		}

		/**
		 * Send it.
		 *
		 * @param array $data From Woo_Wallet_Audit::digest_data().
		 */
		public function trigger( $data ) {
			$this->setup_locale();
			$this->object = $data;
			$suspicious   = count( $data['suspicious'] );
			if ( $suspicious ) {
				/* translators: %d: number of suspicious items */
				$this->placeholders['{summary}'] = sprintf( _n( '%d item to check', '%d items to check', $suspicious, 'woo-wallet' ), $suspicious );
			} else {
				/* translators: %d: number of manual adjustments */
				$this->placeholders['{summary}'] = sprintf( _n( '%d manual adjustment', '%d manual adjustments', count( $data['adjustments'] ), 'woo-wallet' ), count( $data['adjustments'] ) );
			}
			$recipients      = Woo_Wallet_Audit::administrator_emails();
			$this->recipient = implode( ',', $recipients ? $recipients : array( get_option( 'admin_email' ) ) );
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
			return array(
				'data'               => $this->object,
				'event_labels'       => Woo_Wallet_Audit::event_labels(),
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
			$this->form_fields = Woo_Wallet_Email_Approval_Requested::approval_form_fields( $this, '<code>{site_title}</code>, <code>{summary}</code>' );
		}
	}
}

return new Woo_Wallet_Email_Daily_Digest();
