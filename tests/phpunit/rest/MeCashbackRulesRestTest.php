<?php
/**
 * REST coverage for GET terawallet/v1/me/cashback-rules — a read-only
 * summary of the cashback program's public-facing settings. No admin-only
 * fields (role exclusions, per-product overrides) are exposed.
 */
class Me_Cashback_Rules_Rest_Test extends WP_Test_REST_TestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->user_id = self::factory()->user->create();

		$this->set_credit_option( 'is_enable_cashback_reward_program', 'on' );
		$this->set_credit_option( 'cashback_rule', 'cart' );
		$this->set_credit_option( 'cashback_type', 'percent' );
		$this->set_credit_option( 'cashback_amount', 10 );
		$this->set_credit_option( 'max_cashback_amount', 50 );
		$this->set_credit_option( 'min_cart_amount', 20 );
	}

	private function set_credit_option( $key, $value ) {
		$options         = get_option( '_wallet_settings_credit', array() );
		$options[ $key ] = $value;
		update_option( '_wallet_settings_credit', $options );
	}

	private function dispatch() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/terawallet/v1/me/cashback-rules' ) );
	}

	public function test_anonymous_cannot_view_the_summary() {
		wp_set_current_user( 0 );
		$response = $this->dispatch();
		$this->assertErrorResponse( 'rest_not_logged_in', $response, 401 );
	}

	public function test_logged_in_user_gets_the_current_settings() {
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 'cart', $data['scope'] );
		$this->assertSame( 'percent', $data['type'] );
		$this->assertSame( 10.0, $data['amount'] );
		$this->assertSame( 50.0, $data['max_amount'] );
		$this->assertSame( 20.0, $data['min_cart'] );
	}

	public function test_percent_amount_is_formatted_with_a_percent_sign() {
		wp_set_current_user( $this->user_id );
		$data = $this->dispatch()->get_data();
		$this->assertSame( '10%', $data['formatted']['amount'] );
	}

	public function test_fixed_amount_is_formatted_as_a_price() {
		$this->set_credit_option( 'cashback_type', 'fixed' );
		$this->set_credit_option( 'cashback_amount', 15 );

		wp_set_current_user( $this->user_id );
		$data = $this->dispatch()->get_data();

		$this->assertStringNotContainsString( '%', $data['formatted']['amount'] );
		$this->assertStringContainsString( '15', $data['formatted']['amount'] );
	}

	public function test_disabled_program_is_reported_as_such() {
		$this->set_credit_option( 'is_enable_cashback_reward_program', 'off' );

		wp_set_current_user( $this->user_id );
		$data = $this->dispatch()->get_data();
		$this->assertFalse( $data['enabled'] );
	}

	public function test_terawallet_rest_me_cashback_rules_filter_can_extend_the_response() {
		add_filter(
			'terawallet_rest_me_cashback_rules',
			function ( $data ) {
				$data['custom_field'] = 'extended';
				return $data;
			}
		);

		wp_set_current_user( $this->user_id );
		$data = $this->dispatch()->get_data();
		$this->assertSame( 'extended', $data['custom_field'] );
	}

	public function test_response_is_marked_private_and_not_cached() {
		wp_set_current_user( $this->user_id );
		$response = $this->dispatch();
		$headers  = $response->get_headers();
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}
}
