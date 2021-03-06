<?php @session_start();
require_once "commonfunctions.php";

class WC_Gateway_Billmate_Cardpay extends WC_Gateway_Billmate {

	/**
     * Class for Billmate Faktura payment.
     *
     */
    private $prod_url = 'https://cardpay.billmate.se/pay';
    private $tst_url = 'https://cardpay.billmate.se/pay/test';
	public function __construct() {
		global $woocommerce;

		if( !empty($_SESSION['order_created']) ) $_SESSION['order_created'] = '';

		parent::__construct();

		$this->id			= 'billmate_cardpay';
		$this->method_title = __('Billmate Cardpay', 'billmate');
		$this->has_fields 	= true;

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->enabled				= ( isset( $this->settings['enabled'] ) ) ? $this->settings['enabled'] : '';
		$this->title 				= ( isset( $this->settings['title'] )  && $this->settings['title'] != '') ? $this->settings['title'] : __('Billmate Cardpay', 'billmate');
		$this->description  		= ( isset( $this->settings['description'] ) ) ? $this->settings['description'] : '';
		$this->secret				= get_option('billmate_common_secret');//( isset( $this->settings['secret'] ) ) ? $this->settings['secret'] : '';
		$this->eid					= get_option('billmate_common_eid');//( isset( $this->settings['eid'] ) ) ? $this->settings['eid'] : '';
		$this->lower_threshold		= ( isset( $this->settings['lower_threshold'] ) AND $this->settings['lower_threshold'] != '' ) ? floatval(str_replace(",",".",$this->settings['lower_threshold'])) : '';
		$this->upper_threshold		= ( isset( $this->settings['upper_threshold'] ) AND $this->settings['upper_threshold'] != '' ) ? floatval(str_replace(",",".",$this->settings['upper_threshold'])) : '';
		$this->invoice_fee_id		= ( isset( $this->settings['invoice_fee_id'] ) ) ? $this->settings['invoice_fee_id'] : '';
		$this->allowed_countries = (isset($this->settings['billmatecard_allowed_countries'])) ? $this->settings['billmatecard_allowed_countries'] : array();
		$this->testmode				= ( isset( $this->settings['testmode'] ) && $this->settings['testmode'] == 'yes' ) ? true : false;
		$this->logo 				= get_option('billmate_common_logo');
		$this->de_consent_terms		= ( isset( $this->settings['de_consent_terms'] ) ) ? $this->settings['de_consent_terms'] : '';
		$this->authentication_method= ( isset( $this->settings['authentication_method'] ) ) ? $this->settings['authentication_method'] : '';
		$this->order_status = (isset($this->settings['order_status'])) ? $this->settings['order_status'] : false;
		if ( $this->invoice_fee_id == "") $this->invoice_fee_id = 0;

		if ( $this->invoice_fee_id > 0 ) :

			// Version check - 1.6.6 or 2.0
			if ( function_exists( 'get_product' ) ) {
				$product = get_product($this->invoice_fee_id);
			} else {
				$product = new WC_Product( $this->invoice_fee_id );
			}

			if ( $product->exists() ) :

				// We manually calculate the tax percentage here
				$this->invoice_fee_tax_percentage = number_format( (( $product->get_price() / $product->get_price_excluding_tax() )-1)*100, 2, '.', '');

				// apply_filters to invoice fee price so we can filter this if needed
				$billmate_invoice_fee_price_including_tax = $product->get_price();
				$this->invoice_fee_price = apply_filters( 'billmate_invoice_fee_price_including_tax', $billmate_invoice_fee_price_including_tax );

			else :

				$this->invoice_fee_price = 0;

			endif;

		else :

		$this->invoice_fee_price = 0;

		endif;

		$billmate_country = 'SE';
		$billmate_language = 'SV';
		$billmate_currency = 'SEK';
		$billmate_invoice_terms = '';
		$billmate_invoice_icon = plugins_url( '/images/bm_kort_l.png', __FILE__ );

		// Apply filters to Country and language
		$this->billmate_country 		= apply_filters( 'billmate_country', $billmate_country );
		$this->billmate_language 		= apply_filters( 'billmate_language', $billmate_language );
		$this->billmate_currency 		= apply_filters( 'billmate_currency', $billmate_currency );
		$this->icon 				= apply_filters( 'billmate_cardpay_icon', $billmate_invoice_icon );

		$this->supports = array(
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_payment_method_change_admin',
			'subscription_date_changes'
		);

		// Actions
		add_action( 'valid-billmate-cardpay-standard-ipn-request', array( $this, 'successful_request' ) );
		add_action( 'woocommerce_api_wc_gateway_billmate_cardpay', array( $this, 'check_ipn_response' ) );
		/* 1.6.6 */
		add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );

		/* 2.0.0 */
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		add_action('woocommerce_receipt_billmate', array(&$this, 'receipt_page'));

		add_action('woocommerce_scheduled_subscription_payment_'.$this->id,array($this,'process_scheduled_payment'),10,3);
        add_action('admin_enqueue_scripts',array(&$this,'injectscripts'));
		$this->subscription_active = false;
		if(!is_admin()){
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		}
		if(is_plugin_active('woocommerce-subscriptions/woocommerce-subscriptions.php')){
			$this->subscription_active = true;
		}

        //add_action('wp_footer', array(&$this, 'billmate_invoice_terms_js'));

	}
    public function injectscripts(){
        if( is_admin()){
            wp_enqueue_script( 'jquery' );
            wp_register_script('billmateadmin.js',plugins_url('/js/billmateadmin.js',__FILE__),array('jquery'),'1.0',true);
            wp_enqueue_script('billmateadmin.js');
        }
    }

	function check_ipn_response() {
		global $woocommerce;
		//header( 'HTTP/1.1 200 OK' );
        $checkoutMessageCancel = __('The card payment has been canceled before it was processed. Please try again or choose a different payment method.','billmate');
        $checkoutMessageFail = __('Unfortunately your card payment was not processed with the provided card details. Please try again or choose another payment method.','billmate');
		$recurring = false;
		$cancel_url_hit = false;
		$accept_url_hit = false;
		$checkout = false;
		$k = new Billmate($this->eid,$this->secret,true,$this->testmode,false);
		if(!empty($_GET['method']) && $_GET['method'] == 'checkout')
			$checkout = true;
		if( !empty($_GET['payment']) && $_GET['payment'] == 'success' ) {
			if(!empty($_GET['recurring']) && $_GET['recurring'] == 1){
				$recurring = true;
			}
			if( empty( $_POST ) ){
				$_POST = $_GET;
			}
			$input = file_get_contents('php://input');
			if(is_array($input))
				$_POST = array_merge($_POST, $input);

			$accept_url_hit = true;
			$payment_note = 'Note: Payment Completed Accept Url.';
		} elseif (!empty($_GET['payment']) && $_GET['payment'] == 'cancel'){
			if( empty( $_POST ) ){
				$_POST = $_GET;
			}
			$input = file_get_contents('php://input');
			if(is_array($input))
				$_POST = array_merge($_POST, $input);

			$cancel_url_hit = true;
			$payment_note = 'Note: Payment Cancelled.';
		} else {
            $_POST = (is_array($_GET) && isset($_GET['data'])) ? $_GET : file_get_contents("php://input");
            $accept_url_hit = false;
			$payment_note = 'Note: Payment Completed (callback success).';
		}
		if(is_array($_POST))
		{
			foreach($_POST as $key => $value)
				$_POST[$key] = stripslashes($value);
		}
		$data = $k->verify_hash($_POST);
		$order_id = $data['orderid'];

		if(function_exists('wc_seq_order_number_pro')){
			$order_id = wc_seq_order_number_pro()->find_order_by_order_number( $data['orderid'] );

		}
		if(isset($GLOBALS['wc_seq_order_number'])){
			$order_id = $GLOBALS['wc_seq_order_number']->find_order_by_order_number($order_id);
		}
		$order = new WC_Order( $order_id );

		if($recurring) {
			//Todo: Verify it is saved
			update_post_meta($order_id, '_billmate_card_token', $data['number']);
			update_post_meta($order_id, 'billmate_card_token', $data['number']);
			if($order->get_total() == 0) {
				$result = $k->creditPayment(array('PaymentData' => array('number' => $data['number'], 'partcredit' => false)));
				$activateResult = $k->activatePayment(array('PaymentData' => array('number' => $result['number'])));
			}
		}
		if(false !== get_transient('billmate_cardpay_order_id_'.$order_id)){
			if(version_compare(WC_VERSION, '2.0.0', '<')) {
				$redirect = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_thanks_page_id'))));
			} else {
				$redirect = $this->get_return_url($order);
			}
			if($accept_url_hit) {
				WC()->session->__unset( 'billmate_checkout_hash' );
				WC()->session->__unset( 'billmate_checkout_order' );
				wp_safe_redirect($redirect);
				exit;
			} elseif($cancel_url_hit) {
				if(isset($data['status']) AND $data['status'] == 'Failed') {
					wc_bm_errors($checkoutMessageFail);
				} elseif(isset($data['status']) AND $data['status'] == 'Cancelled') {
					wc_bm_errors($checkoutMessageCancel);
				}

				wp_safe_redirect($woocommerce->cart->get_checkout_url());
				exit;

			}
			else
				wp_die('OK','ok',array('response' => 200));
		}
		// Set Transient if not exists to prevent multiple callbacks
		set_transient('billmate_cardpay_order_id_'.$order_id,true,3600);
		if(isset($data['code']) || isset($data['error']) || ($cancel_url_hit) || $data['status'] == 'Failed'){
			if($data['status'] == 'Failed') {
                $error_message = $checkoutMessageFail;
			} else {
				$error_message = $checkoutMessageCancel;
			}

			$order->add_order_note( __($error_message, 'billmate') );

			if($accept_url_hit) {
				delete_transient('billmate_cardpay_order_id_'.$order_id);

				wp_safe_redirect(add_query_arg('key', $order->order_key,
						add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_checkout_page_id')))));
				exit;
				return false;
			} elseif($cancel_url_hit){
				if($data['status'] == 'Failed') {
					wc_bm_errors($checkoutMessageFail);
				} else {
					wc_bm_errors($checkoutMessageCancel);
				}
				delete_transient('billmate_cardpay_order_id_'.$order_id);

				wp_safe_redirect($woocommerce->cart->get_checkout_url());
				exit;
				return false;
			} else {
				wp_die('OK','ok',array('response' => 200));
			}
		}
		if( method_exists($order, 'get_status') ) {
			$order_status = $order->get_status();
		} else {
			$order_status_terms = wp_get_object_terms( $order_id, 'shop_order_status', array('fields' => 'slugs') ); $order_status = $order_status_terms[0];
		}
		if( in_array($order_status, array('pending','cancelled','bm-incomplete')) ){
			//$order->update_status('completed', $payment_note);
			if($data['status'] == 'Paid' || $data['status'] == 'Created') {

                if(version_compare(WC_VERSION, '3.0.0', '>=')) {
                    $orderId = $order->get_id();
                } else {
                    $orderId = $order->id;
                }

				add_post_meta($orderId, 'billmate_invoice_id', $data['number']);
				$order->add_order_note(sprintf(__('Billmate Invoice id: %s','billmate'),$data['number']));
				if ($this->order_status == 'default') {
					if($checkout)
						$order->update_status('pending');
					$order->payment_complete();
				} else {
					if($checkout)
						$order->update_status('pending');
					$order->update_status($this->order_status);
				}
			}
			if($data['status'] == 'Failed'){
				$order->cancel_order('Failed payment');
				if($cancel_url_hit) {
					delete_transient('billmate_cardpay_order_id_'.$order_id);

					wc_bm_errors($checkoutMessageFail);
					wp_safe_redirect($woocommerce->cart->get_checkout_url());
					exit;
				}
				else
					wp_die('OK','ok',array('response' => 200));
			}
			if($data['status'] == 'Cancelled'){
				$order->cancel_order('Cancelled Order');
				if($cancel_url_hit) {
					wc_bm_errors($checkoutMessageCancel);
					delete_transient('billmate_cardpay_order_id_'.$order_id);

					wp_safe_redirect($woocommerce->cart->get_checkout_url());
					exit;
				}
				else
					wp_die('OK','ok',array('response' => 200));
			}
			if( $accept_url_hit ){
				$redirect = '';
				$woocommerce->cart->empty_cart();
				delete_transient('billmate_cardpay_order_id_'.$order_id);
				if(version_compare(WC_VERSION, '2.0.0', '<')){
					$redirect = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_thanks_page_id'))));
				} else {
					$redirect = $this->get_return_url($order);
				}
				WC()->session->__unset( 'billmate_checkout_hash' );
				WC()->session->__unset( 'billmate_checkout_order' );
				wp_safe_redirect($redirect);
				exit;
			}
			wp_die('OK','ok',array('response' => 200));

		}
		if( $accept_url_hit ) {
			// Remove cart
			$woocommerce->cart->empty_cart();
			if(version_compare(WC_VERSION, '2.0.0', '<')){
				$redirect = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_thanks_page_id'))));
			} else {
				$redirect = $this->get_return_url($order);
			}
			WC()->session->__unset( 'billmate_checkout_hash' );
			WC()->session->__unset( 'billmate_checkout_order' );
			wp_safe_redirect($redirect);
			exit;
		}
		wp_die('OK','ok',array('response' => 200));
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	function init_form_fields() {
		$countries = new WC_Countries();
		if(version_compare(WC_VERSION, '2.2.0', '<')){
			$available = $countries->countries;
		}else {
			$available = $countries->get_countries();
		}

		if(version_compare(WC_VERSION, '2.2.0', '<')){
			$order_statuses['default'] = __('Default','billmate');

			foreach(get_terms('shop_order_status',array( 'hide_empty' => 0 ) ) as $status ){
				if(is_object($status)) {
					$order_statuses[$status->slug] = $status->name;
				}
			}
		} else {
			$order_status = wc_get_order_statuses();
			$order_statuses['default'] = __('Default', 'billmate');
			foreach ($order_status as $key => $value) {
				$order_statuses[$key] = $value;
			}
		}
	   	$this->form_fields = apply_filters('billmate_invoice_form_fields', array(
			'enabled' => array(
							'title' => __( 'Enable/Disable', 'billmate' ),
							'type' => 'checkbox',
							'label' => __( 'Enable Billmate Cardpay', 'billmate' ),
							'default' => 'no'
						),
			'title' => array(
							'title' => __( 'Title', 'billmate' ),
							'type' => 'text',
							'description' => __( 'This controls the title which the user sees during checkout.', 'billmate' ),
							'default' => __( 'Billmate Cardpay', 'billmate' )
						),
			'description' => array(
							'title' => __( 'Description', 'billmate' ),
							'type' => 'textarea',
							'description' => __( 'This controls the description which the user sees during checkout.', 'billmate' ),
							'default' => ''
						),
			'lower_threshold' => array(
							'title' => __( 'Lower threshold', 'billmate' ),
							'type' => 'text',
							'description' => __( 'Disable Billmate Cardpay if Cart Total is lower than the specified value. Leave blank to disable this feature.', 'billmate' ),
							'default' => ''
						),
			'upper_threshold' => array(
							'title' => __( 'Upper threshold', 'billmate' ),
							'type' => 'text',
							'description' => __( 'Disable Billmate Cardpay if Cart Total is higher than the specified value. Leave blank to disable this feature.', 'billmate' ),
							'default' => ''
						),
			'authentication_method' => array(
				'title' => __( 'Authentication Method', 'billmate' ),
				'type' => 'select',
				'options' => array(
					'authentication'  =>__( 'Authentication', 'billmate' ),
					'sales' => __('Sales','billmate'),
				),
				'label' => __( 'Authentication Method', 'billmate' ),
				'default' => ''
			),
			'testmode' => array(
							'title' => __( 'Test Mode', 'billmate' ),
							'type' => 'checkbox',
							'label' => __( 'Enable Billmate Test Mode.', 'billmate' ),
							'default' => 'no'
						),
			'billmatecard_allowed_countries' => array(
				'title' 		=> __( 'Allowed Countries', 'billmate' ),
				'type' 			=> 'multiselect',
				'description' 	=> __( 'Billmate Card activated for customers in these countries, Leave blank to allow all.', 'billmate' ),
				'class'			=> 'chosen_select',
				'css' 			=> 'min-width:350px;',
				'options'		=> $available,
				'default' => ''
			),
			'order_status' => array(
				'title' => __('Custom approved order status','billmate'),
				'type' => 'select',
				'description' => __('Choose a special order status for Billmate Cardpay, if you want to use a own status and not WooCommerce built in.','billmate'),
				'default' => 'default',
				'options' => $order_statuses
			)
		) );

	} // End init_form_fields()



	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
    	?>
    	<h3><?php _e('Billmate Cardpay', 'billmate'); ?></h3>

	    	<p><?php _e('With Billmate your customers can pay by cardpay. Billmate works by adding extra personal information fields and then sending the details to Billmate for verification.', 'billmate');?></p>
            <p>
                <a href="https://billmate.se/plugins/manual/Installationsmanual_Woocommerce_Billmate.pdf" target="_blank">Installationsmanual Billmate Modul ( Manual Svenska )</a><br />
                <a href="https://billmate.se/plugins/manual/Installation_Manual_Woocommerce_Billmate.pdf" target="_blank">Installation Manual Billmate ( Manual English )</a>
            </p>

    	<table class="form-table">
    	<?php
    		// Generate the HTML For the settings form.
    		$this->generate_settings_html();
    	?>
		</table><!--/.form-table-->
    	<?php
    } // End admin_options()


	/**
	 * Check if this gateway is enabled and available in the user's country
	 */

	function is_available() {
		global $woocommerce;
		
		if ($this->enabled=="yes") :

            if(is_checkout() == false && is_checkout_pay_page() == false) {
                // Not on store checkout page
                return true;
            }

			// if (!is_ssl()) return false;

			// Currency check
			// if (!in_array(get_option('woocommerce_currency'), array('DKK', 'EUR', 'NOK', 'SEK'))) return false;

			// Base country check
			//if (!in_array(get_option('woocommerce_default_country'), array('DK', 'DE', 'FI', 'NL', 'NO', 'SE'))) return false;

			// Required fields check
			if (!$this->eid || !$this->secret) return false;

			// Cart totals check - Lower threshold
			if ( $this->lower_threshold !== '' ) {
				if ( WC_Payment_Gateway::get_order_total() < $this->lower_threshold ) return false;
			}

			// Cart totals check - Upper threshold
			if ( $this->upper_threshold !== '' ) {
				if ( WC_Payment_Gateway::get_order_total() > $this->upper_threshold ) return false;
			}
			if(!empty($this->allowed_countries)){

				$order_id = absint( get_query_var( 'order-pay' ) );
				if(0 < $order_id){
					$order = wc_get_order( $order_id );
					$address = $order->get_address();
					$country = $address['country'];
				} else {
					$country = "";
                    if(isset($woocommerce) &&
                        is_object($woocommerce) &&
                        isset($woocommerce->customer) &&
                        is_object($woocommerce->customer)
                    ) {
                        if(version_compare(WC_VERSION, '3.0.0', '>=') AND method_exists($woocommerce->customer, "get_billing_country")) {
                            $country = $woocommerce->customer->get_billing_country();
                        } elseif(method_exists($woocommerce->customer, "get_country")) {
                            $country = $woocommerce->customer->get_country();
                        }
                    }
				}
				if(!in_array($country,$this->allowed_countries))
					return false;
			}
			// Only activate the payment gateway if the customers country is the same as the filtered shop country ($this->billmate_country)
	   		//if ( $woocommerce->customer->get_country() == true && $woocommerce->customer->get_country() != $this->billmate_country ) return false;

			return true;

		endif;

		return false;
	}
	/**
	 * Payment form on checkout page
	 */

	function payment_fields() {
	   	global $woocommerce;
	   	?>
        <?php if ($this->testmode=='yes') : ?><p><?php _e('TEST MODE ENABLED', 'billmate'); ?></p><?php endif; ?>
        <p><?php echo strlen($this->description)? $this->description: __('Visa & Mastercard','billmate'); ?></p><?php
	}

	/**
	 * Process scheduled payment
	 */
	function process_scheduled_payment($amount_to_charge,$order){
		global $woocommerce;


		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

		$subscription = end($subscriptions);
		$parent_id = $subscription->order->id;

		$billmateToken = get_post_meta($parent_id,'_billmate_card_token',true);
		if(empty($billmateToken))
			$billmateToken = get_post_meta($parent_id,'billmate_card_token',true);

		$total = 0;
		$totalTax = 0;
		$prepareDiscount = array();
		//$cancel_url = html_entity_decode(get_site_url());
		$accept_url= trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&payment=success&recurring=1';

		$callback_url = trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&recurring=1';
		$cancel_url = trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&payment=cancel';

		$url = parse_url($accept_url);
		$language = explode('_',get_locale());
		$orderValues['PaymentData'] = array(
			'method' => 8,
			'currency' => get_woocommerce_currency(),
			'language' => strtolower($language[0]),
			'country' => $this->billmate_country,
			'autoactivate' => 1,
			'orderid' => preg_replace('/#/','',$order->get_order_number()),
			'logo' => (strlen($this->logo)> 0) ? $this->logo : ''

		);
		$orderValues['PaymentInfo'] = array(
			'paymentdate' => (string)date('Y-m-d'),
			'yourreference' => $order->billing_first_name.' '.$order->billing_last_name
		);

		$orderValues['Customer'] = array(
			'nr' => empty($order->user_id ) || $order->user_id<= 0 ? time(): $order->user_id
		);
		if ( $this->shop_country == 'NL' || $this->shop_country == 'DE' ) :

			require_once('split-address.php');

			$billmate_billing_address				= $order->billing_address_1;
			$splitted_address 					= splitAddress($billmate_billing_address);

			$billmate_billing_address				= $splitted_address[0];
			$billmate_billing_house_number		= $splitted_address[1];
			$billmate_billing_house_extension		= $splitted_address[2];

			$billmate_shipping_address			= $order->shipping_address_1;
			$splitted_address 					= splitAddress($billmate_shipping_address);

			$billmate_shipping_address			= $splitted_address[0];
			$billmate_shipping_house_number		= $splitted_address[1];
			$billmate_shipping_house_extension	= $splitted_address[2];

		else :

			$billmate_billing_address				= $order->billing_address_1;
			$billmate_billing_house_number		= '';
			$billmate_billing_house_extension		= '';

			$billmate_shipping_address			= $order->shipping_address_1;
			$billmate_shipping_house_number		= '';
			$billmate_shipping_house_extension	= '';

		endif;
		$countries = $woocommerce->countries->get_allowed_countries();
		$orderValues['Customer']['Billing'] = array(
			'firstname' => mb_convert_encoding($order->billing_first_name,'UTF-8','auto'),
			'lastname' => mb_convert_encoding($order->billing_last_name,'UTF-8','auto'),
			'company' => mb_convert_encoding($order->billing_company,'UTF-8','auto'),
			'street' => mb_convert_encoding($billmate_billing_address,'UTF-8','auto'),
			'street2' => mb_convert_encoding($order->billing_address_2,'UTF-8','auto'),
			'zip' => $order->billing_postcode,
			'city' => mb_convert_encoding($order->billing_city,'UTF-8','auto'),
			'country' => $countries[$order->billing_country],
			'phone' => $order->billing_phone,
			'email' => $order->billing_email
		);
		if ( $order->get_shipping_method() == '' ) {

			$email = $order->billing_email;
			$telno = ''; //We skip the normal land line phone, only one is needed.
			$cellno = $order->billing_phone;
			$company = mb_convert_encoding( $order->billing_company,'UTF-8','auto');
			$fname = mb_convert_encoding( $order->billing_first_name,'UTF-8','auto');
			$lname = mb_convert_encoding( $order->billing_last_name,'UTF-8','auto');
			$careof = mb_convert_encoding( $order->billing_address_2,'UTF-8','auto');  //No care of; C/O.
			$street = mb_convert_encoding( $billmate_billing_address,'UTF-8','auto'); //For DE and NL specify street number in houseNo.
			$zip = mb_convert_encoding( $order->billing_postcode,'UTF-8','auto');
			$city = mb_convert_encoding( $order->billing_city,'UTF-8','auto');

		} else {
			$email = $order->billing_email;
			$telno = ''; //We skip the normal land line phone; only one is needed.
			$cellno = $order->billing_phone;
			$company = mb_convert_encoding( $order->shipping_company,'UTF-8','auto');
			$fname = mb_convert_encoding( $order->shipping_first_name,'UTF-8','auto');
			$lname = mb_convert_encoding( $order->shipping_last_name,'UTF-8','auto');
			$careof = mb_convert_encoding( $order->shipping_address_2,'UTF-8','auto');  //No care of; C/O.
			$street = mb_convert_encoding( $billmate_shipping_address,'UTF-8','auto'); //For DE and NL specify street number in houseNo.
			$zip = mb_convert_encoding( $order->shipping_postcode,'UTF-8','auto');
			$city = mb_convert_encoding( $order->shipping_city,'UTF-8','auto');


		}
		$orderValues['Customer']['Shipping'] = array(
			'firstname' => $fname,
			'lastname' => $lname,
			'company' => $company,
			'street' => $street,
			'zip' => $zip,
			'city' => $city,
			'country' => $countries[$order->billing_country],
			'phone' => $cellno
		);
		$orderValues['Card'] = array(
			'accepturl' => $accept_url,
			'callbackurl' => $callback_url,
			'cancelurl' => $cancel_url,
			'3dsecure' => ($this->do_3dsecure != 'NO') ? 1 : 0,
			'promptname' => ($this->prompt_name_entry == 'YES') ? 1 : 0,
			'recurringnr' => $billmateToken,
			'returnmethod' => ($url['scheme'] == 'https') ? 'POST' : 'GET'
		);
		if (sizeof($order->get_items())>0) : foreach ($order->get_items() as $item) :
			$_product = $order->get_product_from_item( $item );
			if ($_product->exists() && $item['qty']) :
				// is product taxable?
				if ($_product->is_taxable())
				{
					$taxClass = $_product->get_tax_class();
					$tax = new WC_Tax();
					$rates = $tax->get_rates($taxClass);
					$item_tax_percentage = 0;
					foreach($rates as $row){
						// Is it Compound Tax?
						if(isset($row['compund']) && $row['compound'] == 'yes')
							$item_tax_percentage += $row['rate'];
						else
							$item_tax_percentage = $row['rate'];
					}
				} else
					$item_tax_percentage = 0;

				// apply_filters to item price so we can filter this if needed
				$billmate_item_price_including_tax = round($order->get_item_total( $item, true )*100);
				$billmate_item_standard_price = round($order->get_item_subtotal($item,true)*100);
				$discount = false;
				if($billmate_item_price_including_tax != $billmate_item_standard_price){
					$discount = true;
				}
				$item_price = apply_filters( 'billmate_item_price_including_tax', $billmate_item_price_including_tax);

				if ( $_product->get_sku() ) {
					$sku = $_product->get_sku();
				} else {
					$sku = $_product->id;
				}

				$priceExcl = round($item_price - (100 * $order->get_item_tax($item,false)));

				$orderValues['Articles'][] = array(
					'quantity'   => (int)$item['qty'],
					'artnr'    => $sku,
					'title'    => $item['name'],
					'aprice'    =>  ($discount) ? ($billmate_item_standard_price) : ($priceExcl), //+$item->unittax
					'taxrate'      => (int)$item_tax_percentage,
					'discount' => ($discount) ? round((1 - ($billmate_item_price_including_tax/$billmate_item_standard_price)) * 100 ,0) : 0,
					'withouttax' => $item['qty'] * ($priceExcl)
				);
				$totalTemp = ($item['qty'] * ($priceExcl));
				$total += $totalTemp;
				$totalTax += ($totalTemp * $item_tax_percentage/100);
				if(isset($prepareDiscount[$item_tax_percentage])){
					$prepareDiscount[$item_tax_percentage] += $totalTemp;
				} else {
					$prepareDiscount[$item_tax_percentage] = $totalTemp;
				}

			endif;
		endforeach; endif;
		if ($order->order_discount>0) :

			// apply_filters to order discount so we can filter this if needed
			$billmate_order_discount = $order->order_discount;
			$order_discount = apply_filters( 'billmate_order_discount', $billmate_order_discount );
			$total_value = $total;
			foreach($prepareDiscount as $key => $value){
				$percent = $value/$total_value;

				$discountAmount = ($percent * $order_discount) * (1-($key/100)/(1+($key/100)));

				$orderValues['Articles'][] = array(
					'quantity'   => (int)1,
					'artnr'    => "",
					'title'    => sprintf(__('Discount %s%% tax', 'billmate'),round($key,0)),
					'aprice'    => -($discountAmount*100), //+$item->unittax
					'taxrate'      => (int)$key,
					'discount' => (float)0,
					'withouttax' => -($discountAmount*100)

				);
				$total -= ($discountAmount * 100);
				$totalTax -= ($discountAmount * ($key/100))*100;

			}

		endif;
		if ($order->order_shipping>0) :

			// We manually calculate the shipping taxrate percentage here
			$calculated_shipping_tax_percentage = ($order->order_shipping_tax/$order->order_shipping)*100; //25.00
			$calculated_shipping_tax_decimal = ($order->order_shipping_tax/$order->order_shipping)+1; //0.25

			// apply_filters to Shipping so we can filter this if needed
			$billmate_shipping_price_including_tax = $order->order_shipping*$calculated_shipping_tax_decimal;
			$shipping_price = apply_filters( 'billmate_shipping_price_including_tax', $billmate_shipping_price_including_tax );

			$orderValues['Cart']['Shipping'] = array(
				'withouttax'    => ($shipping_price -$order->order_shipping_tax)*100,
				'taxrate'      => round($calculated_shipping_tax_percentage),

			);
			$total += ($shipping_price-$order->order_shipping_tax) * 100;
			$totalTax += (($shipping_price-$order->order_shipping_tax) * ($calculated_shipping_tax_percentage/100))*100;
		endif;

		$round = 0;//(round($order->order_total,2)*100) - round($total + $totalTax,0);


		$orderValues['Cart']['Total'] = array(
			'withouttax' => round($total),
			'tax' => round($totalTax,0),
			'rounding' => $round,
			'withtax' => round($total) + round($totalTax,0) + $round
		);

		$k = new Billmate($this->eid,$this->secret,true,$this->testmode,false);
		$result = $k->addPayment($orderValues);
		if(isset($result['code'])){
			wc_bm_errors(__($result['message'],'billmate'));
			$order->update_status('failed',sprintf(__("Subscription Payment Failed: Invoice ID: None" , 'billmate'),$result['message']));
			$order->add_order_note(sprintf(__("Subscription Payment Failed: Invoice ID: None" , 'billmate'),$result['message']));
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
			return;
		}else{
			if($result['status'] == 'Paid'){
				add_post_meta($order->id,'billmate_invoice_id',$result['number']);

				$order->add_order_note(sprintf(__("Subscription Payment Successful. Invoice ID: %s ",'billmate'), $result['number']));
				$order->payment_complete($result['number']);
				WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
				return array(
					'result' => 'success'
				);
			} else {
				wc_bm_errors(__($result['message'],'billmate'));
				$order->update_status('failed',sprintf(__("Subscription Payment Failed: Invoice ID: None" , 'billmate'),$result['message']));
				$order->add_order_note(sprintf(__("Subscription Payment Failed: Invoice ID: None" , 'billmate'),$result['message']));
				WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
				return;
			}
		}



	}
	/**
	 * Process the payment and return the result
	 **/
	function process_payment( $order_id ) {
		global $woocommerce;
		$order = new WC_order( $order_id );

		if($this->subscription_active && class_exists('WC_Subscriptions_Order') && WC_Subscriptions_Order::order_contains_subscription($order_id)){

				$total = 0;
				$totalTax = 0;
				$prepareDiscount = array();
				$productTax = 0;
				//$cancel_url = html_entity_decode($woocommerce->cart->get_checkout_url());
				$accept_url= trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&payment=success&recurring=1';

				$callback_url = trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&recurring=1';
				$cancel_url = trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&payment=cancel';

				$url = parse_url($accept_url);
				$language = explode('_',get_locale());
				$orderValues['PaymentData'] = array(
					'method' => 8,
					'currency' => get_woocommerce_currency(),
					'language' => strtolower($language[0]),
					'country' => $this->billmate_country,
					'autoactivate' => 1,
					'orderid' => preg_replace('/#/','',$order->get_order_number()),
					'logo' => (strlen($this->logo)> 0) ? $this->logo : ''

				);
				$orderValues['PaymentInfo'] = array(
					'paymentdate' => (string)date('Y-m-d'),
					'yourreference' => $order->billing_first_name.' '.$order->billing_last_name
				);

				$orderValues['Customer'] = array(
					'nr' => empty($order->user_id ) || $order->user_id<= 0 ? time(): $order->user_id
				);
				if ( $this->shop_country == 'NL' || $this->shop_country == 'DE' ) :

					require_once('split-address.php');

					$billmate_billing_address				= $order->billing_address_1;
					$splitted_address 					= splitAddress($billmate_billing_address);

					$billmate_billing_address				= $splitted_address[0];
					$billmate_billing_house_number		= $splitted_address[1];
					$billmate_billing_house_extension		= $splitted_address[2];

					$billmate_shipping_address			= $order->shipping_address_1;
					$splitted_address 					= splitAddress($billmate_shipping_address);

					$billmate_shipping_address			= $splitted_address[0];
					$billmate_shipping_house_number		= $splitted_address[1];
					$billmate_shipping_house_extension	= $splitted_address[2];

				else :

					$billmate_billing_address				= $order->billing_address_1;
					$billmate_billing_house_number		= '';
					$billmate_billing_house_extension		= '';

					$billmate_shipping_address			= $order->shipping_address_1;
					$billmate_shipping_house_number		= '';
					$billmate_shipping_house_extension	= '';

				endif;
				$countries = $woocommerce->countries->get_allowed_countries();
				$orderValues['Customer']['Billing'] = array(
					'firstname' => mb_convert_encoding($order->billing_first_name,'UTF-8','auto'),
					'lastname' => mb_convert_encoding($order->billing_last_name,'UTF-8','auto'),
					'company' => mb_convert_encoding($order->billing_company,'UTF-8','auto'),
					'street' => mb_convert_encoding($billmate_billing_address,'UTF-8','auto'),
					'street2' => mb_convert_encoding($order->billing_address_2,'UTF-8','auto'),
					'zip' => $order->billing_postcode,
					'city' => mb_convert_encoding($order->billing_city,'UTF-8','auto'),
					'country' => $countries[$order->billing_country],
					'phone' => $order->billing_phone,
					'email' => $order->billing_email
				);
				if ( $order->get_shipping_method() == '' ) {

					$email = $order->billing_email;
					$telno = ''; //We skip the normal land line phone, only one is needed.
					$cellno = $order->billing_phone;
					$company = mb_convert_encoding( $order->billing_company,'UTF-8','auto');
					$fname = mb_convert_encoding( $order->billing_first_name,'UTF-8','auto');
					$lname = mb_convert_encoding( $order->billing_last_name,'UTF-8','auto');
					$careof = mb_convert_encoding( $order->billing_address_2,'UTF-8','auto');  //No care of; C/O.
					$street = mb_convert_encoding( $billmate_billing_address,'UTF-8','auto'); //For DE and NL specify street number in houseNo.
					$zip = mb_convert_encoding( $order->billing_postcode,'UTF-8','auto');
					$city = mb_convert_encoding( $order->billing_city,'UTF-8','auto');

				} else {
					$email = $order->billing_email;
					$telno = ''; //We skip the normal land line phone; only one is needed.
					$cellno = $order->billing_phone;
					$company = mb_convert_encoding( $order->shipping_company,'UTF-8','auto');
					$fname = mb_convert_encoding( $order->shipping_first_name,'UTF-8','auto');
					$lname = mb_convert_encoding( $order->shipping_last_name,'UTF-8','auto');
					$careof = mb_convert_encoding( $order->shipping_address_2,'UTF-8','auto');  //No care of; C/O.
					$street = mb_convert_encoding( $billmate_shipping_address,'UTF-8','auto'); //For DE and NL specify street number in houseNo.
					$zip = mb_convert_encoding( $order->shipping_postcode,'UTF-8','auto');
					$city = mb_convert_encoding( $order->shipping_city,'UTF-8','auto');


				}
				$orderValues['Customer']['Shipping'] = array(
					'firstname' => $fname,
					'lastname' => $lname,
					'company' => $company,
					'street' => $street,
					'zip' => $zip,
					'city' => $city,
					'country' => $countries[$order->billing_country],
					'phone' => $cellno
				);
				$orderValues['Card'] = array(
					'accepturl' => $accept_url,
					'callbackurl' => $callback_url,
					'cancelurl' => $cancel_url,
					'recurring' => 1,
					'returnmethod' => ($url['scheme'] == 'https') ? 'POST' : 'GET'
				);

                $total = 0;
                $totalTax = 0;

                /* Articles, fees, discount */
                $orderValues['Articles'] = $billmateOrder->getArticlesData();
                $total += $billmateOrder->getArticlesTotal();
                $totalTax += $billmateOrder->getArticlesTotalTax();

                // Shipping
                if(version_compare(WC_VERSION, '3.0.0', '>=')) {
                    $order_shipping_total = $order->get_shipping_total();
                    $order_shipping_tax = $order->get_shipping_tax();
                } else {
                    $order_shipping_total = $order->order_shipping;
                    $order_shipping_tax = $order->order_shipping_tax;
                }
				if ($order_shipping_total > 0) :

					// We manually calculate the shipping taxrate percentage here
					$calculated_shipping_tax_percentage = ($order_shipping_tax / $order_shipping_total) * 100; //25.00
					$calculated_shipping_tax_decimal = ($order_shipping_tax / $order_shipping_total) + 1; //0.25

					// apply_filters to Shipping so we can filter this if needed
					$billmate_shipping_price_including_tax = $order_shipping_total * $calculated_shipping_tax_decimal;
					$shipping_price = apply_filters( 'billmate_shipping_price_including_tax', $billmate_shipping_price_including_tax );

					$orderValues['Cart']['Shipping'] = array(
						'withouttax'    => ($shipping_price - $order_shipping_tax) * 100,
						'taxrate'      => round($calculated_shipping_tax_percentage),

					);
					$total += ($shipping_price - $order_shipping_tax) * 100;
					$totalTax += (($shipping_price - $order_shipping_tax) * ($calculated_shipping_tax_percentage / 100)) * 100;
				endif;


				if($order->get_total() == 0){
					$orderValues['Articles'][] = array(
						'quantity'   => (int)1,
						'artnr'    => "",
						'title'    => __('Transaction to be credited', 'billmate'),
						'aprice'    => 100, //+$item->unittax
						'taxrate'      => 0,
						'discount' => (float)0,
						'withouttax' => 100

					);
					$total += 100;
				}

				$checkoutTotal = WC_Payment_Gateway::get_order_total();
				$round = (round($checkoutTotal * 100)) - round($total + $totalTax,0);
				$round += ($checkoutTotal == 0) ? 100 : 0;

				$orderValues['Cart']['Total'] = array(
					'withouttax' => round($total),
					'tax' => round($totalTax,0),
					'rounding' => round($round),
					'withtax' => round($total) + round($totalTax,0) + $round
				);
				$k = new Billmate($this->eid,$this->secret,true,$this->testmode,false);
				$result = $k->addPayment($orderValues);
				if(isset($result['code'])){
					wc_bm_errors(__($result['message']));
					return;
				}

				return array(
					'result' => 'success',
					'redirect' => $result['url']
				);


			// Reqular payment
		} else {

            $billmateOrder = new BillmateOrder($order);
            $billmateOrder->setAllowedCountries($woocommerce->countries->get_allowed_countries());

			$language = explode('_',get_locale());
			if(!defined('BILLMATE_LANGUAGE')) define('BILLMATE_LANGUAGE',strtolower($language[0]));


			$orderValues = array();
			$orderValues['PaymentData'] = array(
				'method' => 8,
				'currency' => get_woocommerce_currency(),
				'language' => strtolower($language[0]),
				'country' => $this->billmate_country,
				'autoactivate' => ( $this->authentication_method == 'sales') ? 1 : 0,
				'orderid' => preg_replace('/#/','',$order->get_order_number()),
				'logo' => (strlen($this->logo)> 0) ? $this->logo : ''
			);


            $orderValues['PaymentInfo'] = $billmateOrder->getPaymentInfoData();

			$languageCode = $language[0];
			$languageCode = $languageCode == 'DA' ? 'DK' : $languageCode;
			$languageCode = $languageCode == 'SV' ? 'SE' : $languageCode;
			$languageCode = $languageCode == 'EN' ? 'GB' : $languageCode;



			//$cancel_url = html_entity_decode($woocommerce->cart->get_checkout_url());
			$accept_url= trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&payment=success';

			$callback_url = trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay';
			$cancel_url = trailingslashit (home_url()) . '?wc-api=WC_Gateway_Billmate_Cardpay&payment=cancel';

			$url = parse_url($accept_url);

			$orderValues['Card'] = array(
				'accepturl' => $accept_url,
				'callbackurl' => $callback_url,
				'cancelurl' => $cancel_url,
				'returnmethod' => ($url['scheme'] == 'https') ? 'POST' : 'GET'
			);


            $orderValues['Customer']['nr'] = $billmateOrder->getCustomerNrData();
            $orderValues['Customer']['Billing'] = $billmateOrder->getCustomerBillingData();
            $orderValues['Customer']['Shipping'] = $billmateOrder->getCustomerShippingData();

            if ( $this->shop_country == 'NL' || $this->shop_country == 'DE' ) :

				require_once('split-address.php');

				$billmate_billing_address				= $order->billing_address_1;
				$splitted_address 					= splitAddress($billmate_billing_address);

				$billmate_billing_address				= $splitted_address[0];
				$billmate_billing_house_number		= $splitted_address[1];
				$billmate_billing_house_extension		= $splitted_address[2];

				$billmate_shipping_address			= $order->shipping_address_1;
				$splitted_address 					= splitAddress($billmate_shipping_address);

				$billmate_shipping_address			= $splitted_address[0];
				$billmate_shipping_house_number		= $splitted_address[1];
				$billmate_shipping_house_extension	= $splitted_address[2];


                $orderValues['Customer']['Billing']['street'] = $billmate_billing_address;
                $orderValues['Customer']['Shipping']['street'] = $billmate_shipping_address;



			endif;


            $total = 0;
            $totalTax = 0;

            /* Articles, fees, discount */
            $orderValues['Articles'] = $billmateOrder->getArticlesData();
            $total += $billmateOrder->getArticlesTotal();
            $totalTax += $billmateOrder->getArticlesTotalTax();

            // Shipping
            if(version_compare(WC_VERSION, '3.0.0', '>=')) {
                $order_shipping_total = $order->get_shipping_total();
                $order_shipping_tax = $order->get_shipping_tax();
            } else {
                $order_shipping_total = $order->order_shipping;
                $order_shipping_tax = $order->order_shipping_tax;
            }
			if ($order_shipping_total > 0) :

				            // We manually calculate the shipping taxrate percentage here
            $calculated_shipping_tax_percentage = ($order_shipping_tax / $order_shipping_total) * 100; //25.00
            $calculated_shipping_tax_decimal = ($order_shipping_tax / $order_shipping_total) + 1; //0.25

            // apply_filters to Shipping so we can filter this if needed
            $billmate_shipping_price_including_tax = $order_shipping_total * $calculated_shipping_tax_decimal;
            $shipping_price = apply_filters( 'billmate_shipping_price_including_tax', $billmate_shipping_price_including_tax );

            $orderValues['Cart']['Shipping'] = array(
                'withouttax'    => ($shipping_price - $order_shipping_tax) * 100,
                'taxrate'      => round($calculated_shipping_tax_percentage),

            );
            $total += ($shipping_price - $order_shipping_tax) * 100;
            $totalTax += (($shipping_price - $order_shipping_tax) * ($calculated_shipping_tax_percentage / 100)) * 100;
		endif;
		$round = round(WC_Payment_Gateway::get_order_total()*100) - round($total + $totalTax,0);


		$orderValues['Cart']['Total'] = array(
			'withouttax' => round($total),
			'tax' => round($totalTax,0),
			'rounding' => round($round),
			'withtax' => round($total + $totalTax + $round)
		);
		$k = new Billmate($this->eid,$this->secret,true,$this->testmode,false);
		$result = $k->addPayment($orderValues);
		if(isset($result['code'])){
			wc_bm_errors(__($result['message']));
			return;
		}
		return array(
			'result' => 'success',
			'redirect' => $result['url']
		);
		}

	}

	/**
	 * receipt_page
	 **/
	function receipt_page( $order ) {
		echo '<p>'.__('Thank you for your order.', 'billmate').'</p>';

	}


	/**
	 * Javascript for Invoice terms popup on checkout page
	 **/
	function billmate_invoice_terms_js() {
		if ( is_checkout() && $this->enabled=="yes" ) {
			?>
			<script type="text/javascript">
				var billmate_eid = "<?php echo $this->eid; ?>";
				var billmate_country = "<?php echo strtolower($this->billmate_country); ?>";
				var billmate_invoice_fee_price = "<?php echo $this->invoice_fee_price; ?>";
				//addBillmateInvoiceEvent(function(){InitBillmateInvoiceElements('billmate_invoice', billmate_eid, billmate_country, billmate_invoice_fee_price); });
			</script>
			<?php
		}
	}


	/**
	 * get_terms_invoice_link_text function.
	 * Helperfunction - Get Invoice Terms link text based on selected Billing Country in the Ceckout form
	 * Defaults to $this->billmate_country
	 * At the moment $this->billmate_country is allways returned. This will change in the next update.
	 **/

	function get_invoice_terms_link_text($country) {

		switch ( $country )
		{
		case 'SE':
			$term_link_account = 'Villkor f&ouml;r faktura';
			break;
		case 'NO':
			$term_link_account = 'Vilk&aring;r for faktura';
			break;
		case 'DK':
			$term_link_account = 'Vilk&aring;r for faktura';
			break;
		case 'DE':
			$term_link_account = 'Rechnungsbedingungen';
			break;
		case 'FI':
			$term_link_account = 'Laskuehdot';
			break;
		case 'NL':
			$term_link_account = 'Factuurvoorwaarden';
			break;
		default:
			$term_link_account = __('Terms for Invoice', 'billmate');
		}

		return $term_link_account;
	} // end function get_account_terms_link_text()


	// Helper function - get Invoice fee id
	function get_billmate_invoice_fee_product() {
		return $this->invoice_fee_id;
	}

	// Helper function - get Shop Country
	function get_billmate_shop_country() {
		return $this->shop_country;
	}
} // End class WC_Gateway_Billmate_Invoice
