<?php
/*
Plugin Name: Maksukaista Payment Gateway
Plugin URI: http://www.maksukaista.fi
Description: Maksukaista Payment Gateway Integration for Woocommerce
Version: 2.2
Author: Paybyway Oy
Author URI: http://www.maksukaista.fi
*/
add_action('plugins_loaded', 'init_maksukaista_gateway', 0);

function woocommerce_add_WC_Gateway_Maksukaista($methods)
{
	$methods[] = 'WC_Gateway_Maksukaista';
	return $methods;
}
add_filter('woocommerce_payment_gateways', 'woocommerce_add_WC_Gateway_Maksukaista');

function init_maksukaista_gateway()
{
	load_plugin_textdomain('maksukaista', false, dirname(plugin_basename(__FILE__)));

	if(!class_exists('WC_Payment_Gateway'))
		return;

	class WC_Gateway_Maksukaista extends WC_Payment_Gateway
	{
		function __construct()
		{
			$this->id = 'maksukaista';
			$this->has_fields = false;
			$this->method_title = 'Maksukaista';
			$this->method_description = 'Maksukaista Payment Gateway integration for Woocommerce';

			$this->init_form_fields();
			$this->init_settings();

			$this->enabled = $this->settings['enabled'];
			$this->title = $this->get_option('title');
			$this->merchant_id = $this->get_option('merchant_id');

			$this->private_key = $this->get_option('private_key');
			$this->pay_url = $this->get_option('pay_url');
			$this->settle_url = $this->get_option('settle_url');
			$this->ordernumber_prefix = $this->get_option('ordernumber_prefix');
			$this->description = $this->get_option('description');

			$this->banks = $this->get_option('banks');
			$this->ccards = $this->get_option('ccards');
			$this->cinvoices = $this->get_option('cinvoices');
			$this->arvato = $this->get_option('arvato');

			$this->send_items = $this->get_option('send_items');
			$this->embed = $this->get_option('embed');

			add_action('wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options' ) );
			add_action('woocommerce_api_wc_gateway_maksukaista', array($this, 'check_maksukaista_response' ) );
			add_action('woocommerce_receipt_maksukaista', array($this, 'receipt_page'));
			add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'maksukaista_settle_payment'), 1, 1);

			if(!$this->is_valid_currency())
				$this->enabled = false;
		}

		function is_valid_currency()
		{
			return in_array(get_option('woocommerce_currency'), array('EUR'));
		}

		function payment_scripts() {
			if ( ! is_checkout() ) {
				return;
			}

			// CSS Styles
			wp_enqueue_style( 'woocommerce_maksukaista', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/css/maksukaista.css', '', '', 'all');
			// JS SCRIPTS
			wp_enqueue_script( 'woocommerce_maksukaista', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) . '/assets/js/maksukaista.js', array( 'jquery' ), '', true );
		}

		function init_form_fields()
		{
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'maksukaista' ),
					'type' => 'checkbox',
					'label' => __( 'Enable Maksukaista', 'maksukaista' ),					
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'maksukaista' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'maksukaista' ),
					'default' => __( 'Maksukaista', 'maksukaista' )
				),
				'description' => array(
					'title' => __( 'Customer Message', 'maksukaista' ),
					'type' => 'textarea',
					'default' => 'Maksukaista -palvelussa voit maksaa ostoksesi turvallisesti verkkopankin kautta, luottokortilla tai luottolaskulla.'
				),
				'merchant_id' => array(
					'title' => __( 'Sub-merchant id', 'maksukaista' ),
					'type' => 'text',
					'description' => __( "Your sub-merchant id found in Maksukaista merchant portal", 'maksukaista' ),
					'default' => ''
				),
				'private_key' => array(
					'title' => __( 'Private key', 'maksukaista' ),
					'type' => 'text',
					'description' => __( 'Private key of the the sub-merchant', 'maksukaista' ),
					'default' => ''
				),
				'ordernumber_prefix' => array(
					'title' => __( 'Order number prefix', 'maksukaista' ),
					'type' => 'text',
					'description' => __( 'Prefix to avoid order number duplication', 'maksukaista' ),
					'default' => ''
				),
				'pay_url' => array(
					'title' => __( 'Payment URL', 'maksukaista' ),
					'type' => 'text',
					'description' => __( 'URL of the Maksukaista payment page. Only change this if you want to use the test interface.', 'maksukaista' ),
					'default' => 'https://www.paybyway.com/e-payments/pay'
				),
				'settle_url' => array(
					'title' => __( 'Settle URL', 'maksukaista' ),
					'type' => 'text',
					'description' => __( 'URL used to settle previously authorized credit card payments. Only change this if you want to use the test interface.', 'maksukaista' ),
					'default' => 'https://www.paybyway.com/pbwapi/settle'
				),
				'banks' => array(
					'title' => __( 'Payment methods', 'maksukaista' ),
					'type' => 'checkbox',
					'label' => __( 'Enable bank payments in the Maksukaista payment page.', 'maksukaista' ),
					'default' => 'yes'
				),
				'ccards' => array(
					'type' => 'checkbox',
					'label' => __( 'Enable credit cards in the Maksukaista payment page.', 'maksukaista' ),
					'default' => 'yes'
				),
				'cinvoices' => array(
					'type' => 'checkbox',
					'label' => __( 'Enable credit invoices in the Maksukaista payment page.', 'maksukaista' ),
					'default' => 'yes'
				),
				'arvato' => array(
					'type' => 'checkbox',
					'label' => __( 'Enable Maksukaista Lasku in the Maksukaista payment page. (Only for Maksukaista Konversio customers)', 'maksukaista' ),
					'default' => 'no'
				),
				'send_items' => array(
					'title' => __( 'Send products', 'maksukaista' ),
					'type' => 'checkbox',
					'label' => __( "Send product breakdown to Maksukaista. \n(Supported on default Woocommerce installation.)", 'maksukaista' ),
					'default' => 'yes'
				),
				'embed' => array(
					'title' => __( 'Enable embedded payment', 'maksukaista' ),
					'type' => 'checkbox',
					'label' => __( "Enable this if you want to use Embedded-feature when customer chooses his payment method.", 'maksukaista' ),
					'default' => 'no'
				),
			);
		}

		function payment_fields()
		{
			global $woocommerce;

			$total = 0;

			if(get_query_var('order-pay') != ''){
				$order = new WC_Order(get_query_var('order-pay'));
				$total = $order->order_total;
			}

			if ($this->description)
				echo wpautop(wptexturize($this->description));
			if($this->embed == 'yes' )
			{
				echo wpautop(wptexturize(__( 'Choose your payment method and click Pay for Order', 'maksukaista' )));

				echo '<div id="maksukaista-bank-payments">';
				if($this->ccards == 'yes')
				{
					echo '<div>'.wpautop(wptexturize(__( 'Payment card', 'maksukaista' ))).'</div>';
					echo '<div id="maksukaista-button-visa" class="bank-button"></div>';
					echo '<div id="maksukaista-button-master" class="bank-button"></div>';					
				}			
				if($this->banks == 'yes')
				{
					echo '<div>'.wpautop(wptexturize(__( 'Internet banking', 'maksukaista' ))).'</div>';
					echo '<div id="maksukaista-button-nordea" class="bank-button"></div>';
					echo '<div id="maksukaista-button-op" class="bank-button"></div>';
					echo '<div id="maksukaista-button-danske" class="bank-button"></div>';
					echo '<div id="maksukaista-button-saastopankki" class="bank-button"></div>';
					echo '<div id="maksukaista-button-poppankki" class="bank-button"></div>';
					echo '<div id="maksukaista-button-aktia" class="bank-button"></div>';
					echo '<div id="maksukaista-button-handelsbanken" class="bank-button"></div>';
					echo '<div id="maksukaista-button-spankki" class="bank-button"></div>';
				}
				if($this->arvato == 'yes' || ($this->cinvoices == 'yes' && ((!isset($order) && $woocommerce->cart->total > 5 && $woocommerce->cart->total < 2000) || ($total > 5 && $total < 2000))))
				{
					echo '<div id="maksukaista-cinvoice-payments">'; //???????
					echo '<div>'.wpautop(wptexturize(__( 'Invoice or part payment', 'maksukaista' ))).'</div>';
					if($this->arvato == 'yes')
						echo '<div id="maksukaista-button-arvato" class="bank-button"></div>';
					if($this->cinvoices == 'yes')
					{
						if($this->cinvoices == 'yes' && ((!isset($order) && $woocommerce->cart->total > 5 && $woocommerce->cart->total < 2000) || ($total > 5 && $total < 2000)))
							echo '<div id="maksukaista-button-everyday" class="bank-button"></div>';
						if($this->cinvoices == 'yes' && ((!isset($order) && $woocommerce->cart->total > 20 && $woocommerce->cart->total < 2000) || ($total > 20 && $total < 2000)))
							echo '<div id="maksukaista-button-jousto" class="bank-button"></div>';				
					}
				}
	
				echo '</div>';

				echo '<div id="maksukaista_bank_checkout_fields" style="display: none;">';
				echo '<input id="maksukaista_selected_bank" class="input-hidden" type="hidden" name="maksukaista_selected_bank" />';
				echo '</div>';
			}
		}

		function receipt_page($order_id)
		{
			global $woocommerce;

			$order = new WC_Order($order_id);

			if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>='))
			{
				// >= 2.1.0
				$redirect_url = $this->get_return_url($order);
			}
			else
			{
				// < 2.1.0
				$redirect_url = (get_option('woocommerce_thanks_page_id') != '' ) ? get_permalink(get_option('woocommerce_thanks_page_id')): get_site_url().'/' ;
			}

			$return_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id), $redirect_url );

			$mk_selected = "";
			if($this->banks == 'yes')
				$mk_selected .= 'BANKS';
			if($this->ccards == 'yes')
				$mk_selected .= ',CREDITCARDS';
			if($this->cinvoices == 'yes')
				$mk_selected .= ',CREDITINVOICES';
			if($this->arvato == 'yes')
				$mk_selected .= ',ARVATO';

/*
BANKS - enables all accessible bank payments
CREDITCARDS - enables all accessible card payments
CREDITINVOICES - enables all accessible credit invoice payments
NORDEA, HANDELSBANKEN, OSUUSPANKKI, DANSKEBANK, SPANKKI, SAASTOPANKKI, PAIKALLISOSUUSPANKKI, AKTIA
EVERYDAY, JOUSTORAHA - Everyday allows payments between 5.01€ and 2000€, Jousto between 20€ and 
*/
			if($this->embed == 'yes' )
			{
				$bank_array = array(
					'nordea' => 'NORDEA',
					'op' => 'OSUUSPANKKI',
					'danske' => 'DANSKEBANK',
					'saastopankki' => 'SAASTOPANKKI',
					'poppankki' => 'PAIKALLISOSUUSPANKKI',
					'aktia' => 'AKTIA',
					'handelsbanken' => 'HANDELSBANKEN',
					'spankki' => 'SPANKKI',
					'arvato' => $mk_selected, //Use all enabled and arvato url
					'everyday' => 'EVERYDAY',
					'jousto' => 'JOUSTORAHA',
					'master' => 'CREDITCARDS',
					'visa' => 'CREDITCARDS'
				);
				$maksukaista_selected_bank = get_post_meta( $order_id, '_maksukaista_selected_bank_', true);
				if ($maksukaista_selected_bank!='') {
					if (array_key_exists($maksukaista_selected_bank, $bank_array)) {
						$mk_selected = $bank_array[$maksukaista_selected_bank];
					}
				}
			}
			$email = $order->billing_email;
			$contact_id_post = "";
			$contact_firstname = $order->billing_first_name;
			$contact_lastname = $order->billing_last_name;
			$contact_ssn = '';
			$contact_email = $order->billing_email;
			$contact_addr_street = $order->billing_address_1.' '.$order->billing_address_2;
			$contact_addr_city = $order->billing_city;
			$contact_addr_zip = $order->billing_postcode;

			$amount =  (int)(round($order->order_total*100, 0));
			$currency = get_woocommerce_currency();

			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->get_option('ordernumber_prefix') . '_'  .$order_id : $order_id;
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);
			$order_number_text =  __('CREATED: ', 'maksukaista').'Maksukaista ' . __('order number', 'maksukaista');
			$order->add_order_note("$order_number_text: $order_number");
			update_post_meta($order_id, 'maksukaista_order_number', $order_number);
			update_post_meta($order_id, 'maksukaista_is_settled', 1);

			$finn_langs = array('fi-FI', 'fi', 'fi_FI');
			$lang = in_array(get_bloginfo('language'), $finn_langs) ? 'FI' : 'EN';

			$products = array();
			$order_items = $order->get_items();
			foreach($order_items as $item) {
				$product = array(
					'ITEM_TITLE' => $item['name'],
					'ITEM_NO' => $item['product_id'],
					'ITEM_COUNT' => $item['qty'],
					'ITEM_PRETAX_PRICE' => (int)(round(($item['line_total']/$item['qty'])*100, 0)),
					'ITEM_PRICE' => (int)(round((($item['line_total'] + $item['line_tax'] ) / $item['qty'])*100, 0)),
					'ITEM_TAX' => round($item['line_tax']/$item['line_total']*100,0),
					'ITEM_TYPE' => 1
				);
				array_push($products, $product);
		 	}

		 	//Shipping costs as item_type 2
		 	$shipping_items = $order->get_items( 'shipping' );
		 	foreach($shipping_items as $s_method){
				$shipping_method_id = $s_method['method_id'] ;
			}
		 	if($order->order_shipping > 0){
			 	$product = array(
					'ITEM_TITLE' => $order->get_shipping_method(),
					'ITEM_NO' => $shipping_method_id,
					'ITEM_COUNT' => 1,
					'ITEM_PRETAX_PRICE' => (int)(round($order->order_shipping*100, 0)),
					'ITEM_PRICE' => (int)(round(($order->order_shipping_tax+$order->order_shipping)*100, 0)),
					'ITEM_TAX' => round($order->order_shipping_tax/$order->order_shipping*100,0),
					'ITEM_TYPE' => 2
				);
				array_push($products, $product);
			}

			if($order->order_discount > 0){
			 	$product = array(
					'ITEM_TITLE' => __( 'Order discount', 'maksukaista' ),
					'ITEM_NO' => '',
					'ITEM_COUNT' => 1,
					'ITEM_PRETAX_PRICE' => -(int)(round($order->order_discount*100, 0)),
					'ITEM_PRICE' => -(int)(round($order->order_discount*100, 0)),
					'ITEM_TAX' => '0',
					'ITEM_TYPE' => 4
				);
				array_push($products, $product);
			}

		 	if(count($products) > 0 && $this->send_items == 'yes')
				$items = count($products);
			else
				$items = '';

			$authcode =
				'2.1|'.
				$this->merchant_id.'|'.
				$amount.'|'.
				$currency.'|'.
				$order_number.'|'.
				$lang.'|'.
				$return_url.'|'.
				$return_url.'|'.
				$return_url.'|'.
				$mk_selected.'|'.
				$email.'|'.
				$contact_id_post.'|'.
				$contact_firstname.'|'.
				$contact_lastname.'|'.
				$contact_ssn.'|'.
				$contact_email.'|'.
				$contact_addr_street.'|'.
				$contact_addr_city.'|'.
				$contact_addr_zip.'|'.
				$items;
			if($items != ''){
				foreach ($products as $product) {
					$authcode .=
					'|' . $product['ITEM_TITLE'] .
					'|' . $product['ITEM_NO'] .
					'|' . $product['ITEM_COUNT'] .
					'|' . $product['ITEM_PRETAX_PRICE'] .
					'|' . $product['ITEM_PRICE'] .
					'|' . $product['ITEM_TAX'] .
					'|' . $product['ITEM_TYPE'];
				}
			}

			$authcode = strtoupper(hash_hmac('sha256', $authcode, $this->private_key));

			$data = array(
				'VERSION' => '2.1',
				'MERCHANT_ID' => $this->merchant_id,
				'AMOUNT' => $amount,
				'CURRENCY' => $currency,
				'ORDER_NUMBER' => $order_number,
				'LANG' => $lang,
				'RETURN_ADDRESS' => $return_url,
				'CANCEL_ADDRESS' => $return_url,
				'NOTIFY_ADDRESS' => $return_url,
				'SELECTED' => $mk_selected,
				'EMAIL' => $email,
				'CONTACT_FIRSTNAME' => $contact_firstname,
				'CONTACT_LASTNAME' => $contact_lastname,
				'CONTACT_SSN' => $contact_ssn,
				'CONTACT_EMAIL' => $contact_email,
				'CONTACT_ADDR_STREET' => $contact_addr_street,
				'CONTACT_ADDR_CITY' => $contact_addr_city,
				'CONTACT_ADDR_ZIP' => $contact_addr_zip,
				'CONTACT_ID' => $contact_id_post,
				'ITEMS' => $items,
				'AUTHCODE' => $authcode
			);

			if($this->arvato == 'yes' && strpos($mk_selected,'ARVATO') !== false) //If arvato enabled and selected includes ARVATO
				$html = '<form action="' . $this->pay_url . '?arvato" name="maksukaista_pay_form" method="POST">';
			else
				$html = '<form action="' . $this->pay_url . '" name="maksukaista_pay_form" method="POST">';

			foreach ($data as $key => $value)
				$html .= '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars($value).'" />';

			if($items != ""){
				foreach ($products as $product) {
					foreach ($product as $key => $value) {
						$html .= '<input name="'.$key.'[]" value="'.htmlspecialchars($value).'"  type="hidden" />';
					}
				}
			}

			$html .='</form>';
			$html .= '<script> document.maksukaista_pay_form.submit();</script>';
			echo $html;
		}

		function process_payment($order_id)
		{
			global $woocommerce;

			$order = new WC_Order($order_id);			

			$maksukaista_selected_bank = isset( $_POST['maksukaista_selected_bank'] ) ? wc_clean( $_POST['maksukaista_selected_bank'] ) : '';
			update_post_meta( $order->id, '_maksukaista_selected_bank_', $maksukaista_selected_bank );




			$redirect = $order->get_checkout_payment_url(true);
			//Empty cart when redirecting to Maksukaista, so new orders won't override this order.
			$woocommerce->cart->empty_cart();
			return array(
				'result'   => 'success',
				'redirect'  => $redirect
			);
		}

		function get_order_by_id_and_order_number($order_id, $order_number)
		{
			$order = New WC_Order($order_id);
			if($order_number == get_post_meta( $order->id, 'maksukaista_order_number', true ))
				return $order;

			return null;
		}

		function check_maksukaista_response()
		{
			global $woocommerce;

			if(count($_POST))
			{
				$return_code = isset($_POST['RETURN_CODE']) ? $_POST['RETURN_CODE'] : -999;
				$incident_id = isset($_POST['INCIDENT_ID']) ? $_POST['INCIDENT_ID'] : null;
				$settled = isset($_POST['SETTLED']) ? $_POST['SETTLED'] : null;
				$authcode = isset($_POST['AUTHCODE']) ? $_POST['AUTHCODE'] : null;
				$contact_id = isset($_POST['CONTACT_ID']) ? $_POST['CONTACT_ID'] : null;
				$order_number = isset($_POST['ORDER_NUMBER']) ? $_POST['ORDER_NUMBER'] : null;

				$authcode_confirm = $return_code .'|'. $order_number;

				if(isset($return_code) && $return_code == 0){
					$authcode_confirm .= '|' . $settled;
					if(isset($contact_id) && !empty($contact_id)){
						$authcode_confirm .= '|' . $contact_id;
					}
				}
				else if(isset($incident_id) && !empty($incident_id)){
					$authcode_confirm .= '|' . $incident_id;
				}

				$authcode_confirm = strtoupper(hash_hmac('sha256', $authcode_confirm, $this->private_key));

				$order_id = isset($_GET['order_id']) ? $_GET['order_id'] : null;
				$order = $this->get_order_by_id_and_order_number($order_id, $order_number);
				$mk_on = get_post_meta($order_id, 'maksukaista_order_number', true );

				if($authcode_confirm === $authcode && $order)
				{
					switch($return_code)
					{
						case 0:
							if($settled == 0)
							{
								$is_settled = 0;
								update_post_meta($order_id, 'maksukaista_is_settled', $is_settled);
								if($order->status != 'processing')
									$order->add_order_note( __('Maksukaista: ', 'maksukaista').$mk_on."\n".__('Payment is authorized. Use settle option to capture funds.', 'maksukaista'));

							}
							else
							{
								if($order->status != 'processing')
									$order->add_order_note(__('Maksukaista: ', 'maksukaista').$mk_on."\n".__('Payment accepted.', 'maksukaista'));
							}
							$order->payment_complete();
							$woocommerce->cart->empty_cart();
							break;

						case 1:
							if($order->status != 'processing')
								$order->update_status('failed', __('Payment was not accepted.', 'maksukaista'));
							break;

						case 2:
							if($order->status != 'processing')
								$order->update_status('failed', __('Duplicate order number.', 'maksukaista'));
							break;

						case 3:
							$note = __('User disabled. Either your Paybyway account has been temporarily disabled for security reasons,
								or your sub-merchant is disabled. Visit merchant UI to verify that the sub-merchant is active and that your
								Paybyway account has not been disabled. If account is disabled, contact support for assistance.', 'maksukaista');
							if($order->status != 'processing')
								$order->update_status('failed', $note);
							break;

						case 4:
							$note = __('Transaction status could not be updated after customer returned from the
								web page of a bank. Please use the merchant UI to resolve the payment status.', 'maksukaista');
							if($order->status != 'processing')
								$order->update_status('failed', $note);
							break;

						case 10:
							$note = __('Maintenance break. The transaction is not created and the user has been
							notified and transferred back to the cancel address.', 'maksukaista');
							if($order->status != 'processing')
								$order->update_status('failed', $note);
							break;
					}
				}
				else
				{
					die ("MAC check failed");
				}

				wp_redirect($this->get_return_url($order));
				exit('Ok');
			}
		}

		function maksukaista_settle_payment($order)
		{
			global $woocommerce;
			$settle_field = get_post_meta( $order->id, 'maksukaista_is_settled', true );
			$settle_check = empty($settle_field) && $settle_field == "0";
			if(!$settle_check)
				return;

			$url = admin_url('post.php?post=' . absint( $order->id ) . '&action=edit');

			if(isset($_GET['maksukaista_settle']))
			{
				$order_number = get_post_meta( $order->id, 'maksukaista_order_number', true );
				$settlement_msg = '';

				if($this->process_settlement($order_number, $settlement_msg))
				{
					update_post_meta($order->id, 'maksukaista_is_settled', 1);
					$order->add_order_note(__('Payment settled.', 'maksukaista'));
					$settlement_result = '1';
				}
				else
				{
					$settlement_result = '0';
				}

				if(!$settlement_result)
					echo '<div id="message" class="error">'.$settlement_msg.' <p class="form-field"><a href="'.$url.'" class="button button-primary">OK</a></p></div>';
				else
				{
					echo '<div id="message" class="updated fade">'.$settlement_msg.' <p class="form-field"><a href="'.$url.'" class="button button-primary">OK</a></p></div>';
					return;
				}
			}


			$text = __('Settle payment', 'maksukaista');
			$url .= '&maksukaista_settle';
			$html = "
				<p class='form-field'>
					<a href='$url' class='button button-primary'>$text</a>
				</p>";

			echo $html;

		}

		function process_settlement($order_number, &$settlement_msg)
		{
			$ctype = 'application/json';
			$posts = array(
				'MERCHANT_ID' => $this->merchant_id,
				'ORDER_NUMBER' => $order_number,
				'AUTHCODE' => $this->private_key
			);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $this->settle_url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array($ctype));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $posts);

			if(!$curl_response = curl_exec($ch))
			{
				$settlement_msg = "Curl error: ". curl_error($ch) . ", Error code: " . curl_errno($ch);
				curl_close($ch);
				return false;
			}

			curl_close($ch);
			$successful = false;
			if($settlement = json_decode($curl_response))
			{
				$return_code = isset($settlement->RETURN_CODE) ? $settlement->RETURN_CODE : -999;

				switch ($return_code)
				{
					case 0:
						$successful = true;
						$settlement_msg = __('Settlement was successful.', 'maksukaista');
						break;

					case 1:
						$settlement_msg = __('Settlement failed. Private key and merchant id did not match with order number.', 'maksukaista');
						break;

					case 2:
						$settlement_msg = __('Settlement failed. Either the payment has already been settled or the payment gateway refused to settle payment for given transaction.', 'maksukaista');
						break;

					case 3:
						$settlement_msg = __('Settlement failed. The settlement request did not contain all the needed fields', 'maksukaista');
						break;

					default:
						$settlement_msg = __('Settlement failed. Unkown error.', 'maksukaista');
						break;
				}
			}
			else
			{
				$settlement_msg = __('Settlement failed. Failed to parse JSON response.', 'maksukaista');
			}

			return $successful;
		}
	}
}
