<?php
ob_start();
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Gateway class
 */
class WcPayubiz extends WC_Payment_Gateway
{
	protected $msg = array();

	protected $logger;

	protected $checkout_express;

	protected $gateway_module;

	protected $redirect_page_id;

	protected $currency1;

	protected $currency1_payu_key;

	protected $currency1_payu_salt;

	protected $bypass_verify_payment;

	protected $site_url;
	protected $dynamic_charges_flag;

	protected $_skuWiseTotal = 0;



	public function __construct()
	{
		global $wpdb;
		// Go wild in here
		$this->id = 'payubiz';
		$this->method_title = __('PayUBiz', 'payubiz');
		$this->icon = plugins_url('images/payubizlogo.png', dirname(__FILE__));
		$this->has_fields = false;
		$this->init_form_fields();
		$this->init_settings();
		$this->title = __('Credit/Debit Card & NetBanking Payment', 'payubiz');
		$this->method_description = __('Enable secure payments through PayU using (Credit/Debit Cards, NetBanking, UPI, and Wallets).', 'payubiz');
		$this->supports = array('products', 'refunds');
		$this->description = sanitize_text_field($this->settings['description']);
		$this->checkout_express = sanitize_text_field($this->settings['checkout_express']);
		//$this->dynamic_charges_flag = sanitize_text_field($this->settings['dynamic_charges_flag']);
		$this->gateway_module = sanitize_text_field($this->settings['gateway_module']);
		$this->redirect_page_id = sanitize_text_field($this->settings['redirect_page_id']);

		//$this->currency1 = sanitize_text_field($this->settings['currency1']);
		$this->currency1_payu_key = sanitize_text_field($this->settings['currency1_payu_key']);
		$this->currency1_payu_salt = sanitize_text_field($this->settings['currency1_payu_salt']);

		$this->bypass_verify_payment = false;
		$this->site_url = get_site_url();

		if (sanitize_text_field($this->settings['verify_payment']) != "yes") {
			$this->bypass_verify_payment = true;
		}


		$this->msg['message'] = "";
		$this->msg['class'] = "";


		add_action('init', array(&$this, 'check_payubiz_response'));
		add_action('wp_head', array(&$this, 'payu_scripts'));
		//update for woocommerce >2.0
		add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_payubiz_response'));

		add_action('valid-payubiz-request', array(&$this, 'SUCCESS'));
		add_action('woocommerce_receipt_payubiz', array(&$this, 'receipt_page'));


		if (version_compare(WOOCOMMERCE_CURRENT_VERSION, '2.0.0', '>=')) {
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
		} else {
			add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
		}


		$this->logger = wc_get_logger();
	}

	/**
	 * Session patch CSRF Samesite=None; Secure
	 **/
	public function manage_session()
	{
		$context = array('source' => $this->id);
		try {
			if (PHP_VERSION_ID >= 70300) {
				$options = session_get_cookie_params();
				$domain = $options['domain'] ?? '';
				$path = $options['path'] ?? '';
				$expire = 0;
				$cookies = $_COOKIE;
				foreach ($cookies as $key => $value) {
					if (!preg_match('/cart/', sanitize_key($key))) {
						setcookie(sanitize_key($key), sanitize_text_field($value), $expire, $path, $domain, true, true);
					}
				}
			} else {
				$this->logger->error("PayU payment plugin does not support this PHP version for cookie management. 
				Required PHP v7.3 or higher.", $context);
			}
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), $context);
		}
	}


	public function init_form_fields()
	{
		require_once dirname(__FILE__) . '/admin/payu-admin-settings.php';
		$this->form_fields = payuAdminFields();
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 **/
	public function admin_options()
	{
		echo '<h3>' . esc_html__('PayU India', 'payubiz') . '</h3>';
		echo '<p>' . sprintf(__('<a target="_blank" href="https://onboarding.payu.in/app/account/signup?partner_name=WooCommerce&partner_source=Affiliate+Links&partner_uuid=11eb-3a29-70592552-8c2b-0a696b110fde&source=Partner">Sign up</a> for a PayU merchant account to get started or <a target="_blank" href="https://onboarding.payu.in/app/account/login?partner_name=WooCommerce&partner_source=Affiliate+Links&partner_uuid=11eb-3a29-70592552-8c2b-0a696b110fde&source=Partner">login</a> to your existing account.', 'payubiz')) . '</p>';
		if (PHP_VERSION_ID < 70300) {
			echo "<h1 style=\"color:red;\">" . esc_html__('**Notice: PayU payment plugin requires PHP v7.3 or higher.<br />
			Plugin will not work properly below PHP v7.3 due to SameSite cookie restriction.', 'payubiz') . "</h1>";
		}
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 *  There are no payment fields for Citrus, but we want to show the description if set.
	 **/
	function payment_fields()
	{
		if ($this->description) {
			echo wpautop(wptexturize($this->description));
		}
	}

	/**
	 * Receipt Page
	 **/
	public function receipt_page($order)
	{
		$this->manage_session(); //Update cookies with samesite
		$thankyou_msg = 'Thank you for your order, please wait as you will be automatically redirected to PayUBiz.';
		echo '<p>' . esc_html__($thankyou_msg, 'payubiz') . '</p>';
		echo $this->generatePayubizForm($order);
	}

	/**
	 * Process the payment and return the result
	 **/

	public function payment_scripts()
	{

		$result = true;

		// we need JavaScript to process a token only on cart/checkout pages, right?
		if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
			$result = false;
		}

		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ('no' === $this->enabled) {
			$result = false;
		}

		// no reason to enqueue JavaScript if API keys are not set
		if (empty($this->currency1_payu_key) || empty($this->currency1_payu_salt)) {
			$result = false;
		}

		return $result;
	}
	public function process_payment($order_id)
	{
		$order = new WC_Order($order_id);

		if (version_compare(WOOCOMMERCE_CURRENT_VERSION, '2.0.0', '>=')) {
			return array(
				'result' => 'success',
				'redirect' => add_query_arg(
					'order',
					$order->id,
					add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true))
				)
			);
		} else {
			return array(
				'result' => 'success',
				'redirect' => add_query_arg(
					'order',
					$order->id,
					add_query_arg('key', $order->get_order_key(), get_permalink(get_option('woocommerce_pay_page_id')))
				)
			);
		}
	}


	/**
	 * Check for valid PayU server callback
	 **/
	public function check_payubiz_response()
	{
		if (!$this->isWcApi()) {
			return;
		}

		$postdata = $this->preparePostdata();
		$payuPaymentValidation = new PayuPaymentValidation();
		$payuPaymentValidation->payuPaymentValidationAndRedirect($postdata);
	}

	private function isWcApi()
	{
		return isset($_GET['wc-api']) && sanitize_text_field($_GET['wc-api']) == get_class($this);
	}

	private function preparePostdata()
	{
		$postdata = array();
		if (isset($_POST['payu_resp'])) {
			$payu_request = json_decode(stripslashes($_POST['payu_request']), true);
			$_POST = json_decode(stripslashes($_POST['payu_resp']), true);
			error_log("call by submit form");
		} else {
			error_log("call by payu");
		}

		if (empty($_POST)) {
			$bolt_url = ('sandbox' == $this->gateway_module) ?
				MERCHANT_HOSTED_PAYMENT_JS_LINK_UAT :
				MERCHANT_HOSTED_PAYMENT_JS_LINK_PRODUCTION;
			$args_log = array(
				'request_type' => 'outgoing',
				'method' => 'post',
				'url' => $bolt_url,
				'request_headers' => 'null',
				'request_data' => $payu_request ?? '',
				'status' => 200,
				'response_headers' => $_POST,
				'response_data' => 'null'
			);
			payu_insert_event_logs($args_log);
		}

		foreach ($_POST as $key => $val) {
			$postdata[$key] = in_array(
				$key,
				['transaction_offer', 'cart_details', 'shipping_address', 'extra_charges']
			) ?
				$val : sanitize_text_field($val);
		}
		return $postdata;
	}

	/**
	 * Generate PayUBiz button link
	 **/
	public function generatePayubizForm($order_id)
	{

		$sku_details_array = array();
		$order = new WC_Order($order_id);
		$session_cookie_data = WC()->session->get_session_cookie();
		$udf4 = $session_cookie_data[0];
		$order->update_meta_data('udf4', $udf4);
		$payu_key = $this->currency1_payu_key;
		$site_link = get_site_url();
		$redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0)
			? $site_link . "/"
			: get_permalink($this->redirect_page_id);
		//For wooCoomerce 2.0
		$redirect_url = add_query_arg('wc-api', get_class($this), $redirect_url);
		WC()->session->set('orderid_awaiting_payubiz', $order_id);
		$txnid = $order_id . '_' . date("ymd") . '_' . random_int(1, 100);
		update_post_meta($order_id, 'order_txnid', $txnid);


		$order->calculate_totals();
		//do we have a phone number?
		//get currency
		// $address = sanitize_text_field($order->billing_address_1); {
		// $address = $address . ' ' . sanitize_text_field($order->billing_address_2);
		// }
		$address = sanitize_text_field($order->get_billing_address_1()); {
			$address = $address . ' ' . sanitize_text_field($order->get_billing_address_2());
		}

		$sku_details = $this->payuGetOrderSkuDetails($order);
		$sku_details_array = $sku_details['sku_details_array'];
		$productInfo = $sku_details['product_info'];

		$item_count = $order->get_item_count();


		$action = esc_url(PAYU_HOSTED_PAYMENT_URL_PRODUCTION);

		if ('sandbox' == $this->gateway_module) {
			$action = esc_url(PAYU_HOSTED_PAYMENT_URL_UAT);
		}
		/**Disable & enable shipping charges.**/
		$plugin_data = get_option('woocommerce_payubiz_settings');
		$payu_dynamic_charges_flag = $plugin_data['dynamic_charges_flag'];
		$order_subtotal = sanitize_text_field($order->get_subtotal());
		$order_total_tax = sanitize_text_field($order->order_total);
		if ($payu_dynamic_charges_flag == "no") {
			$amount = $this->checkout_express == 'checkout_express' ? $order_total_tax : sanitize_text_field($order->order_total);
		} else {
			$amount = $this->checkout_express == 'checkout_express' ? $order_subtotal : sanitize_text_field($order->order_total);
		}
		$amount = number_format($amount, 2);
		$amount = str_replace(",", "", $amount);
		//echo $amount; exit;
		$firstname = sanitize_text_field($order->billing_first_name);
		$lastname = sanitize_text_field($order->billing_last_name);
		$zipcode = sanitize_text_field($order->billing_postcode);
		$edit_email_allowed = true;
		if (is_user_logged_in()) {
			$edit_email_allowed = false;
		}

		$user_id = get_current_user_id();
		if ($user_id && $this->checkout_express == 'checkout_express') {
			$current_user_data = get_userdata($user_id);
			$user_email = $current_user_data->user_email;
			$payu_phone = get_user_meta($user_id, 'payu_phone', true);
		}
		if ($this->checkout_express == 'checkout_express') {
			allow_to_checkout_from_cart('revert');
		}

		$email_required = true;
		$guest_checkout_enabled = get_option('woocommerce_enable_guest_checkout');
		if ($guest_checkout_enabled == 'yes') {
			$email_required = false;
		}
		$billing_email = $order->billing_email ? sanitize_email($order->billing_email) : '';
		//$email = $user_email ? $user_email : $billing_email;
		$email = isset($user_email) ? $user_email : $billing_email;
		$phone = isset($payu_phone) ? $payu_phone : sanitize_text_field($order->billing_phone);
		//$phone = $payu_phone ? $payu_phone : sanitize_text_field($order->billing_phone);
		if (strlen($phone) > 10) {
			$phone = substr($phone, -10);
		}
		// $get_state_list = get_state_list();
		// $state = $get_state_list[sanitize_text_field($order->billing_state)];
		$get_state_list = get_state_list();
		$billing_state_key = sanitize_text_field($order->billing_state);
		$state = isset($get_state_list[$billing_state_key]) ? $get_state_list[$billing_state_key] : '';
		$city = $order->billing_city ? sanitize_email($order->billing_city) : '';
		// $city = sanitize_text_field($order->billing_city);
		// $country = sanitize_text_field($order->billing_country);
		$country = $order->billing_country ? sanitize_email($order->billing_country) : '';
		$pG = '';
		// Include a all plugins file through includes
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if (! function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Set default values
		$plugin_version = '3.8.8';
		$folder_name    = 'payu-india';
		$max_version    = '0.0.0';

		// Loop through all installed plugins
		foreach (get_plugins() as $file => $data) {
			if (stripos($data['Name'], 'PayU') !== false) {

				// If plugin is active OR has higher version than current max
				if (is_plugin_active($file) || version_compare($data['Version'], $max_version, '>')) {
					$plugin_version = $data['Version'];
					$folder_name    = dirname($file);
					$max_version    = $data['Version'];
				}
			}
		}

		// Build UDF5 string
		$udf5 = 'WooCommerce_version_' . $plugin_version;
		// $udf5 = 'WooCommerce';
		$hash = $this->generateHashToken($txnid, $amount, $productInfo, $firstname, $email, $udf4, $udf5);

		$payu_payment_nonce = wp_nonce_field('payu_payment_nonce', 'payu_payment_nonce', true, false);

		$requestArr = [
			'key' => $payu_key,
			'Hash' => $hash,
			'txnid' => $txnid,
			'amount' => $amount,
			'firstname' => $firstname,
			'Lastname' => $lastname,
			'email' => $email,
			'phone' => $phone,
			'productinfo' => $productInfo,
			'udf4' => $udf4,
			'udf5' => $udf5,
			'surl' => $site_link,
			'furl' => $site_link,
			'enforce_paymethod' => ''
		];
		if ($this->checkout_express == 'redirect') {
			$requestArr['action'] = $action;
			$requestArr['payu_payment_nonce'] = $payu_payment_nonce;
			$requestArr['zipcode'] = $zipcode;
			$requestArr['redirect_url'] = $redirect_url;
			$requestArr['pG'] = $pG;
			$requestArr['address'] = $address;
			$requestArr['city'] = $city;
			$requestArr['country'] = $country;
			$requestArr['state'] = $state;

			$html = $this->payuRedirectMethod($requestArr);
		} elseif ($this->checkout_express == 'bolt') {

			$html = $this->payuBoltPayment($requestArr, $redirect_url);
		} elseif ($this->checkout_express == 'checkout_express') {
			$random_bytes = random_bytes(5);
			$ramdom_str = bin2hex($random_bytes);
			$c_date = gmdate('D, d M Y H:i:s T');
			//$tax_info_data=$order->order_total-$order->get_subtotal();
			$tax_info_data = WC()->cart->get_total_tax();
			//$tax_info_data=round($tax_info_data, 2);            
			$taxinfo = array(
				'breakup' => array(
					'Standard' => "$tax_info_data"
				),
				'total' => "$tax_info_data"
			);

			$order_amount = 0;
			if ($payu_dynamic_charges_flag == "no") {
				if (wc_prices_include_tax()) {
					$taxinfo = NULL;
					$order_amount = $order->order_total;

				} else {
					$taxinfo = $taxinfo;
					$order_amount = $order->get_subtotal();
				}

			} else {
				$taxinfo = NULL;
				$order_amount = $order->get_subtotal();
			}
			// echo "team working please wait";
			// echo wc_prices_include_tax();
			// echo  $payu_dynamic_charges_flag;

			// exit;

			if ($payu_dynamic_charges_flag == "yes" && wc_prices_include_tax()) {
				$order_amount = $order->order_total;
				$amount = $order->order_total;
			}
			$amount = str_replace(",", "", $amount);

			if (empty($email)) {
				$email = 'guest_' . uniqid() . '@payu.in';
				error_log('Email not found, setting default email: ' . $email);
			}

			$data_array = array(
				'key' => $payu_key,
				'hash' => $hash,
				'txnid' => $txnid,
				'amount' => $amount,
				'phone' => $phone,
				'firstname' => $firstname,
				'lastname' => $lastname,
				'email' => $email,
				'udf1' => '',
				'udf2' => '',
				'udf3' => '',
				'udf4' => $udf4,
				'udf5' => $udf5,
				'app_version' => COMMERCEPRO_APP_VERSION,
				'drop_category' => '',
				'enforce_paymethod' => '',
				'isCheckoutExpress' => true,
				'icp_source' => 'express',
				'platform' => 'woocommerce',
				'productinfo' => $productInfo,
				'email_required' => $email_required,
				'edit_email_allowed' => $edit_email_allowed,
				'edit_phone_allowed' => true,
				'surl' => $site_link,
				'furl' => $site_link,
				'orderid' => $ramdom_str,
				'extra_charges' => array(
						'totalAmount' => NULL, // this amount adding extra charges + cart Amount
						'shipping_charges' => NULL, // static shipping charges
						'cod_fee' => 0, // cash on delivery fee.
						'other_charges' => NULL,
						'tax_info' => $taxinfo,

					),
				'cart_details' => array(
					'amount' => $order_amount,
					'items' => (string) $item_count,
					'sku_details' => $sku_details_array,
				)

			);
			$data_array = payuEndPointData($data_array);


			$args_ec = $this->payuExpressCheckoutScriptGenerate($data_array, $c_date, $redirect_url, $payu_payment_nonce);
			$html = $this->payuExpressCheckoutPayment($args_ec);
		}


		return $html;
	}

	private function payuGetOrderSkuDetails($order)
	{

		$productInfo = '';

		$default_Payu_logo = 'https://devguide.payu.in/website-assets/uploads/2021/12/new-payu-logo.svg';

		foreach ($order->get_items() as $item) {
			$variation_id = $item->get_variation_id();
			$_product = new WC_Product_Variation($variation_id);
			$single_sku_price = (float) $_product->get_price();
			$single_sku_name = $_product->get_name();
			$single_sku = $_product->get_sku();
			$single_sku = ($single_sku != "") ? $single_sku : $variation_id;
			$single_sku_price = str_replace(",", "", $single_sku_price);
			$product = wc_get_product($item->get_product_id());
			$productInfo .= $product->get_sku() . ':';
			// $amount_per_sku= number_format($product->get_price(), 2);
			$amount_per_sku = (float) $product->get_price();

			$amount_per_sku = str_replace(",", "", $amount_per_sku);

			$product_image = wp_get_attachment_url($_product->get_image_id());
			$logo = $product_image ? $product_image : $default_Payu_logo;
			if ($variation_id == 0) {
				$sku_id = ($product->get_sku() != "") ? $product->get_sku() : $product->get_id();
				$amount_per_sku = $amount_per_sku;
				$product_name = $product->get_name();
			} else {
				$sku_id = $single_sku;
				$amount_per_sku = $single_sku_price;
				$product_name = $single_sku_name;
			}

			$sku_details_array[] = array(
				'offer_key' => array(),
				'amount_per_sku' => $amount_per_sku,
				'quantity' => (string) $item->get_quantity(),
				'sku_id' => $sku_id,
				'sku_name' => $product_name,
				'logo' => $logo
			);
		}


		$productInfo = rtrim($productInfo, ':');
		if ('' == $productInfo) {
			$productInfo = "Product Information";
		} elseif (100 < strlen($productInfo)) {
			$productInfo = substr($productInfo, 0, 100);
		}
		return array('sku_details_array' => $sku_details_array, 'product_info' => $productInfo);
	}



	private function payuRedirectMethod($args_redirect)
	{
		return '<form action="' . esc_url($args_redirect['action']) . '" method="post" id="payu_form" name="payu_form">
				' . wp_nonce_field('payu_payment_nonce', 'payu_payment_nonce', true, false) . '
				<input type="hidden" name="key" value="' . esc_attr($args_redirect['key']) . '" />
				<input type="hidden" name="txnid" value="' . esc_attr($args_redirect['txnid']) . '" />
				<input type="hidden" name="amount" value="' . esc_attr($args_redirect['amount']) . '" />
				<input type="hidden" name="productinfo" value="' . esc_attr($args_redirect['productinfo']) . '" />
				<input type="hidden" name="firstname" value="' . esc_attr($args_redirect['firstname']) . '" />
				<input type="hidden" name="Lastname" value="' . esc_attr($args_redirect['Lastname']) . '" />
				<input type="hidden" name="Zipcode" value="' . esc_attr($args_redirect['zipcode']) . '" />
				<input type="hidden" name="email" value="' . esc_attr($args_redirect['email']) . '" />
				<input type="hidden" name="phone" value="' . esc_attr($args_redirect['phone']) . '" />
				<input type="hidden" name="surl" value="' . esc_url($args_redirect['redirect_url']) . '" />
				<input type="hidden" name="furl" value="' . esc_url($args_redirect['redirect_url']) . '" />
				<input type="hidden" name="curl" value="' . esc_url($args_redirect['redirect_url']) . '" />
				<input type="hidden" name="Hash" value="' . esc_attr($args_redirect['Hash']) . '" />
				<input type="hidden" name="Pg" value="' . esc_attr($args_redirect['pG']) . '" />
				<input type="hidden" name="address1" value="' . esc_attr($args_redirect['address']) . '" />
		        <input type="hidden" name="address2" value="" />
			    <input type="hidden" name="city" value="' . esc_attr($args_redirect['city']) . '" />
		        <input type="hidden" name="country" value="' . esc_attr($args_redirect['country']) . '" />
		        <input type="hidden" name="state" value="' . esc_attr($args_redirect['state']) . '" />
				<input type="hidden" name="udf3" value="" />
				<input type="hidden" name="udf4" value="' . esc_attr($args_redirect['udf4']) . '" />
				<input type="hidden" name="udf5" value="' . esc_attr($args_redirect['udf5']) . '" />
		        <button style="display:none"
				id="submit_payubiz_payment_form" name="submit_payubiz_payment_form">Pay Now</button>
				</form>
				<script type="text/javascript">document.getElementById("payu_form").submit();</script>';
	}

	private function payuBoltPayment($requestArr, $redirect_url)
	{
		$html = "<form method='post' action='$redirect_url' id='payu_bolt_form'>
			<input type='hidden' name='payu_resp'>
			</form>
			";
		$data = json_encode($requestArr, JSON_UNESCAPED_SLASHES);
		$javascriptCode = <<<EOD
			<script type='text/javascript'>
				function boltSubmit() {
					var data = $data;
					var handlers = {
						responseHandler: function(BOLT) {
							if (BOLT.response.txnStatus == "FAILED") {
								console.log('Payment failed. Please try again.');
							}
							if (BOLT.response.txnStatus == "CANCEL") {
								console.log('Payment failed. Please try again.');
							}
							var payu_frm = document.getElementById('payu_bolt_form');
							payu_frm.elements.namedItem('payu_resp').value = JSON.stringify(BOLT.response);
							payu_frm.submit();
						},
						catchException: function(BOLT) {
							console.log('Payment failed. Please try again.');
						}
					};
					bolt.launch(data, handlers);
				}
				boltSubmit();
			</script>
			EOD;
		return $html . $javascriptCode;
	}

	private function payuExpressCheckoutScriptGenerate($data_array, $c_date, $redirect_url, $payu_payment_nonce)
	{
		$payu_key = $this->currency1_payu_key;
		$payu_salt = $this->currency1_payu_salt;
		$data_array_json = json_encode($data_array, JSON_UNESCAPED_SLASHES);
		$v2hash = hash('sha512', $data_array_json . "|" . $c_date . "|" . $payu_salt);
		$username = "hmac username=\"$payu_key\"";
		$algorithm = "algorithm=\"sha512\"";
		$headers = "headers=\"$c_date\"";
		$signature = "signature=\"$v2hash\"";

		// Concatenating the parts together
		$auth_header_string = $username . ', ' . $algorithm . ', ' . $headers . ', ' . $signature;
		return array(
			'redirect_url' => $redirect_url,
			'payu_payment_nonce' => $payu_payment_nonce,
			'data_array_json' => $data_array_json,
			'c_date' => $c_date,
			'auth_header_string' => $auth_header_string
		);
	}

	private function payuExpressCheckoutPayment($args_express_checkout)
	{

		$cart_url = wc_get_cart_url();
		$redirect_url = $args_express_checkout['redirect_url'];
		$payu_payment_nonce = $args_express_checkout['payu_payment_nonce'];
		// $args_express_checkout['data_array_json']['app_version'] = '3.8.7';
		$data_array_json = json_encode($args_express_checkout['data_array_json']);
		// $data_array = $args_express_checkout['data_array_json'];
		// // $data_array['app_version'] = '3.8.7';
		// $data_array['app_version'] = COMMERCEPRO_APP_VERSION;
		// $data_array_json = json_encode($data_array);
		$c_date = $args_express_checkout['c_date'];
		$auth_header_string = $args_express_checkout['auth_header_string'];

		$html = "<form method='post' action='$redirect_url' id='payu_express_checkout_form'>
		<input type='hidden' name='payu_resp'>$payu_payment_nonce
		<input type='hidden' name='payu_request'>
		</form>
		";

		$html .= "<script>
		function handleSubmit() {
			const  expressRequestObj = {
				data: $data_array_json,
				date: '$c_date',
				isCheckoutExpress: true,
				v2Hash: '$auth_header_string'
			}
			var handlers = {
				responseHandler: function (BOLT) {
                    console.log('response from payu');
                    console.log(BOLT.response.amount);
					console.log(JSON.stringify(BOLT.response));
                    
						if (BOLT.response.txnStatus == 'FAILED') {
						alert('Payment failed. Please try again.');
						}
						if(BOLT.response.txnStatus == 'CANCEL'){
						alert('Payment cancelled. Please try again.');
						}
						var payu_frm = document.getElementById('payu_express_checkout_form');
						payu_frm.elements.namedItem('payu_resp').value = JSON.stringify(BOLT.response);
						payu_frm.elements.namedItem('payu_request').value = JSON.stringify(expressRequestObj);
						payu_frm.submit();
					},
				catchException: function (BOLT) {
					console.log(BOLT);
					if(typeof BOLT.message !== 'undefined')
					{
						alert('Payment failed. Please try again. (' + BOLT.message +')');
					}
					window.location = '$cart_url';
			}};
			bolt.launch( expressRequestObj , handlers);
		}
		handleSubmit();
		</script>";
		return $html;
	}


	public function payu_scripts()
	{

		if ('sandbox' == $this->gateway_module) {
			echo '<script src="' . esc_js(MERCHANT_HOSTED_PAYMENT_JS_LINK_UAT) . '"></script>';
		} else {
			echo '<script src="' . esc_js(MERCHANT_HOSTED_PAYMENT_JS_LINK_PRODUCTION) . '"></script>';
		}
	}

	private function generateHashToken($txnid, $amount, $productInfo, $firstname, $email, $udf4, $udf5)
	{
		$payu_salt = $this->currency1_payu_salt;
		return hash(
			'sha512',
			$this->currency1_payu_key . '|' .
			$txnid . '|' .
			$amount . '|' .
			$productInfo . '|' .
			$firstname . '|' .
			$email . '||||' .
			$udf4 . '|' .
			$udf5 . '||||||' .
			$payu_salt
		);
	}

	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$refund = new PayuRefundProcess(false);
		global $refund_args;
		$order = new WC_Order($order_id);
		$refund_type = 'partial';
		if ($order->get_total() == $amount) {
			$refund_type = 'full';
		}
		$refund_result = $refund->process_custom_refund_backend($order, $amount);
		error_log('refund call : ' . serialize($refund_result));

		error_log('amount :' . $amount);
		if ($refund_result && $refund_result['status'] == 1) {
			$refund->payu_refund_data_insert($refund_result, $order_id, $refund_type, $refund_args);
			return true;
		}
		return false;
	}
}
