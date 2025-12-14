<?php

class PayuWebhookCalls
{

	protected $scope = '';

	protected $currency1_payu_salt;

	public function __construct()
	{

		add_action('rest_api_init', array(&$this, 'getPaymentSuccessUpdate'));

		add_action('rest_api_init', array(&$this, 'getPaymentFailedUpdate'));

		// $plugin_data = get_option('woocommerce_payubiz_settings');
		// $this->currency1_payu_salt = sanitize_text_field($plugin_data['currency1_payu_salt']);
		   $plugin_data = get_option('woocommerce_payubiz_settings');
		   
			if ( is_array( $plugin_data ) && isset( $plugin_data['currency1_payu_salt'] ) ) {
				$this->currency1_payu_salt = sanitize_text_field( $plugin_data['currency1_payu_salt'] );
			} else {
				error_log( 'Error: currency1_payu_salt not found in the plugin settings.' );
				$this->currency1_payu_salt = '';
			}
	}


	public function getPaymentSuccessUpdate()
	{
		register_rest_route('payu/v1', '/get-payment-success-update', array(
			'methods' => ['POST'],
			'callback' => array($this, 'payuGetPaymentSuccessUpdateCallback'),
			'permission_callback' => '__return_true'
		));
	}

	public function payuGetPaymentSuccessUpdateCallback(WP_REST_Request $request)
	{
		$parameters = $request->get_body();
		error_log("Success payment webhook ran");
		parse_str($parameters, $response_data);
		$this->payuOrderStatusUpdate($response_data);
	}

	public function getPaymentFailedUpdate()
	{
		register_rest_route('payu/v1', '/get-payment-failed-update', array(
			'methods' => ['POST'],
			'callback' => array($this, 'payuGetPaymentFailedUpdateCallback'),
			'permission_callback' => '__return_true'
		));
	}

	public function payuGetPaymentFailedUpdateCallback(WP_REST_Request $request)
	{
		$parameters = $request->get_body();
		error_log("Failed payment webhook ran");
		parse_str($parameters, $response_data);
		$this->payuOrderStatusUpdate($response_data);
	}

	private function payuOrderStatusUpdate($response)
	{
		global $table_prefix, $wpdb;
		if ($response) {
			if(!is_array($response)){
				$response = json_decode($response, true);
			}
			$decoded_data = [];
			foreach ($response as $key => $value) {
				// Check if the value is a string and if it's valid JSON
				if (is_string($value) && (json_decode($value) !== null)) {
					$decoded_data[$key] = json_decode($value, true); // Decode as associative array
				} else {
					// If not JSON, keep the original value
					$decoded_data[$key] = $value;
				}
			}
			$payuPaymentValidation = new PayuPaymentValidation();
			sleep(5);
			$order = $payuPaymentValidation->payuPaymentValidationAndRedirect($decoded_data);
			if ($order) {
				$payu_transactions_tblname = "payu_transactions";
				$payu_id = $decoded_data['mihpayid'];
				$wp_track_payu_transactions_tblname = $table_prefix . "$payu_transactions_tblname";
				$wpdb->update(
					$wp_track_payu_transactions_tblname,
					array(
						'transaction_id' => $payu_id
					),
					array(
						'order_id' => $order->id
					)
				);
			}
		}
	}
}
$payu_webhook_calls = new PayuWebhookCalls();
