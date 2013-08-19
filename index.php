<?php
/*
Plugin Name: Maksukaista Payment Gateway
Plugin URI: http://www.maksukaista.fi
Description: Maksukaista Payment Gateway Integration for Woocommerce
Version: 1.0
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

function maksukaista_show_messages()
{
	if(isset($_GET['settlement_msg']))
	{
		$error = (isset($_GET['settlement_result']) && $_GET['settlement_result'] == '0');
		$message = sanitize_text_field(urldecode($_GET['settlement_msg']));

		if($error)
			$html = '<div id="message" class="error">';
		else
			$html = '<div id="message" class="updated fade">';

		$html .= "<p><strong>$message</strong></p></div>";
		echo $html;
	}
}

add_action('admin_notices', 'maksukaista_show_messages'); 

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

			add_action('init', array(&$this, 'check_maksukaista_response'));
			add_action('init', array(&$this, 'maksukaista_settle_payment'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options' ) );
	        add_action('woocommerce_api_wc_gateway_maksukaista', array(&$this, 'check_maksukaista_response' ) );
			add_action('woocommerce_receipt_maksukaista', array(&$this, 'receipt_page'));
			add_action('woocommerce_admin_order_data_after_order_details', array(&$this, 'maksukaista_settle_payment'));
			
			if(!$this->is_valid_currency()) 
				$this->enabled = false;
		}

		function is_valid_currency() 
		{
			return in_array(get_option('woocommerce_currency'), array('EUR'));
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
					'description' => __( 'Your sub-merchant id found in Maksukaista merchant portal', 'maksukaista' ),
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
			);
		}

		function payment_fields()	
		{
			if ($this->description) 
				echo wpautop(wptexturize($this->description));
		}

		function receipt_page($order_id)
		{		
			global $woocommerce;

			$order = new WC_Order($order_id);

			$redirect_url = (get_option('woocommerce_thanks_page_id') != '' ) ? get_permalink(get_option('woocommerce_thanks_page_id')): get_site_url().'/' ;
			$return_url = add_query_arg( array('wc-api' => get_class( $this ) ,'order_id' => $order_id), $redirect_url );

			$amount =  str_replace(',', '', number_format($order->order_total, 2)) * 100;
			$currency = get_woocommerce_currency();
			$order_number = (strlen($this->ordernumber_prefix)  > 0) ?  $this->get_option('ordernumber_prefix') . '_'  .$order_id : $order_id;
			$order_number .=  '-' . str_pad(time().rand(0,9999), 5, "1", STR_PAD_RIGHT);

			$order_number_text = 'Maksukaista ' . __('order number', 'maksukaista');
			$order->add_order_note("$order_number_text: $order_number");
			update_post_meta($order_id, 'maksukaista_order_number', $order_number);
			update_post_meta($order_id, 'maksukaista_is_settled', 1);
			
			$finn_langs = array('fi-FI', 'fi', 'fi_FI');
			$lang = in_array(get_bloginfo('language'), $finn_langs) ? 'FI' : 'EN';

			$data = array(
				'MERCHANT_ID' => $this->merchant_id,
				'AMOUNT' => $amount,
				'CURRENCY' => $currency,
				'ORDER_NUMBER' => $order_number,
				'LANG' => $lang,
				'RETURN_ADDRESS' => $return_url,
				'CANCEL_ADDRESS' => $return_url
			);

			$mac = 
				$this->private_key . '|' .
				$data['MERCHANT_ID'] . '|' .
				$data['AMOUNT'] . '|' .
				$data['CURRENCY'] . '|' .
				$data['ORDER_NUMBER'] . '|' .
				$data['LANG'] . '|' .
				$data['RETURN_ADDRESS'] . '|' .
				$data['CANCEL_ADDRESS'];

			$data['AUTHCODE'] = strtoupper(md5($mac));

			$html = '<form action=' . $this->pay_url . ' name="maksukaista_pay_form" method="POST">';

			foreach ($data as $key => $value)
				$html .= "<input type='hidden' name='$key' value='$value' />";

			$html .='</form>';
			$html .= '<script>document.maksukaista_pay_form.submit();</script>';
			echo $html;
		}

		function process_payment($order_id)
		{
			$order = new WC_Order($order_id);

			return array(
				'result'   => 'success',
				'redirect'  => add_query_arg('order',
				$order->id, 
				add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
			);
		}

		function get_order_by_id_and_order_number($order_id, $order_number)
		{
			if(($order = New WC_Order($order_id)) && ($order_number == $order->order_custom_fields['maksukaista_order_number'][0]))
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
				$order_number = isset($_POST['ORDER_NUMBER']) ? $_POST['ORDER_NUMBER'] : null;

				$authcode_confirm = $this->private_key .'|'. $return_code .'|'. $order_number;

				if("$return_code" === "0")
				{
					$authcode_confirm .=  '|'. $settled;
				}
				else if(isset($incident_id) && !empty($incident_id))
				{
					$authcode_confirm .=  '|'. $incident_id;
				}

				$authcode_confirm = strtoupper(md5($authcode_confirm));

				$order_id = isset($_GET['order_id']) ? $_GET['order_id'] : null;
				$order = $this->get_order_by_id_and_order_number($order_id, $order_number);

				if($authcode_confirm === $authcode && $order)
				{
					switch($return_code)
					{
						case 0:
							if($settled == 0)
							{
								$is_settled = 0;
								update_post_meta($order_id, 'maksukaista_is_settled', $is_settled);
								$order->add_order_note(__('Payment is authorized. Use settle option to capture funds.', 'maksukaista'));
							}
							else
							{
								$order->add_order_note(__('Payment accepted.', 'maksukaista'));
							}

							$order->payment_complete();
							$woocommerce->cart->empty_cart();
							break;

						case 1:
							$order->update_status('failed', __('Payment was not accepted.', 'maksukaista'));
							break;

						case 2:
							$order->update_status('failed', __('Duplicate order number.', 'maksukaista'));
							break;

						case 3:
							$note = __('User disabled. Either your Paybyway account has been temporarily disabled for security reasons, 
								or your sub-merchant is disabled. Visit merchant UI to verify that the sub-merchant is active and that your 
								Paybyway account has not been disabled. If account is disabled, contact support for assistance.', 'maksukaista');

							$order->update_status('failed', $note);
							break;

						case 4:
							$note = __('Transaction status could not be updated after customer returned from the 
								web page of a bank. Please use the merchant UI to resolve the payment status.', 'maksukaista');
							$order->update_status('failed', $note);
							break;

						case 10:
							$note = __('Maintenance break. The transaction is not created and the user has been 
							notified and transferred back to the cancel address.', 'maksukaista');
							$order->update_status('failed', $note);
							break;
					}
				}
				else
				{
					die ("MAC check failed");
				}

				wp_redirect($this->get_return_url($order));
				exit;
			}
		}

		function maksukaista_settle_payment($order)
		{
			global $woocommerce;
			
			$settle_check = isset($order->order_custom_fields['maksukaista_is_settled'][0]) && $order->order_custom_fields['maksukaista_is_settled'][0] == "0";
			if(!$settle_check)
				return;

			$url = admin_url('post.php?post=' . absint( $order->id ) . '&action=edit');

			if(isset($_GET['maksukaista_settle']))
			{
				$order_number = $order->order_custom_fields['maksukaista_order_number'][0];
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

				$redirect_url = add_query_arg(array('settlement_msg' => urlencode($settlement_msg), 'settlement_result' => $settlement_result), $url);
				wp_safe_redirect($redirect_url);
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
			curl_setopt($ch, CURLOPT_SSLVERSION, 3);
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
