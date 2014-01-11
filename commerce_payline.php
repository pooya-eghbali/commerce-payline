<?php

/**
 * @file
 * Implements payline payment services to be used with Drupal Commerce.
 * @author
 * Pooya Eghbali
 * @license
 * GPLv2
 */

/**
 * Implements hook_commerce_payment_method_info().
 *
 * This hook will define the payline payment method
 */
function commerce_payline_commerce_payment_method_info() {
  $payment_methods = array();

  $payment_methods['payline'] = array(
    'base' => 'commerce_payline',
    'title' => t('payline'),
    'short_title' => t('payline'),
    'display_title' => t('payline'),
    'description' => t('Integrates payline payment system'),
    'terminal' => FALSE,
    'offsite' => TRUE,
    'offsite_autoredirect' => TRUE,

  );

  return $payment_methods;
}

/**
 * Payment method callback: settings form.
 *
 * Returns form elements for the payment methods settings form included
 * as part of the payment methods enabling action in Rules
 */
function commerce_payline_settings_form($settings = NULL) {
  $form = array();

  $settings = (array) $settings + array(
    'api_key' => '',
    'currency' => variable_get('commerce_default_currency', 'IRR'),
  );

  $form['api_key'] = array(
    '#type' => 'textfield',
    '#title' => t('API Key'),
    '#description' => t('Your payline API Key'),
    '#default_value' => isset($settings['api_key']) ? $settings['api_key'] : "adxcv-zzadq-polkjsad-opp13opoz-1sdf455aadzmck1244567",
  );
  $form['gateway_send'] = array(
    '#type' => 'textfield',
    '#title' => t('Gateway-send address'),
    '#description' => t('Payline gateway-send address'),
    '#default_value' => isset($settings['gateway_send']) ? $settings['gateway_send'] : 'http://payline.ir/payment-test/gateway-send',
  );
  $form['gateway_addr'] = array(
    '#type' => 'textfield',
    '#title' => t('Gateway address'),
    '#description' => t('Payline gateway address'),
    '#default_value' => isset($settings['gateway_addr']) ? $settings['gateway_addr'] : 'http://payline.ir/payment-test/gateway',
  );
  $form['gateway_result'] = array(
    '#type' => 'textfield',
    '#title' => t('Gateway-result address'),
    '#description' => t('Payline gateway-result address'),
    '#default_value' => isset($settings['gateway_result']) ? $settings['gateway_result'] : 'http://payline.ir/payment-test/gateway-result-second',
  );
  
  $form['#submit'][] = 'commerce_payline_settings_form_submit';

  return $form;
}

/**
 * Payment method callback: redirect form
 *
 * returns form elements that should be submitted to the redirected
 * payment service
 */
function commerce_payline_redirect_form($form, &$form_state, $order, $payment_method) {

  if (empty($payment_method['settings']['api_key']) ||
      empty($payment_method['settings']['gateway_send']) ||
	  empty($payment_method['settings']['gateway_addr']) ||
	  empty($payment_method['settings']['gateway_result'])) {
    drupal_set_message(t('Payline is not configured for use.'), 'error');
    return array();
  }

  $settings = array(
    'cancel_return' => url('checkout/' . $order->order_id . '/payment/back/' . $order->data['payment_redirect_key'], array('absolute' => TRUE)),
    'return' => url('checkout/' . $order->order_id . '/payment/return/' . $order->data['payment_redirect_key'], array('absolute' => TRUE)),
    'payment_method' => $payment_method['instance_id'],
  );

  return commerce_payline_build_redirect_form($form, $form_state, $order, $payment_method['settings'] + $settings);
}

/**
 * Helper function for the redirect_form callback.
 * Builds an payline payment form from an order object.
 */
function commerce_payline_build_redirect_form($form, &$form_state, $order, $settings) {
  global $user;
  $wrapper = entity_metadata_wrapper('commerce_order', $order);

  $currency_code = $wrapper->commerce_order_total->currency_code->value();
  $amount = (int)$wrapper->commerce_order_total->amount->value()/100;
  
  $api      = $settings['api_key'];
  $redirect = $settings['return'];
  $url      = $settings['gateway_send'];
  
  $ch = curl_init();
  curl_setopt($ch,CURLOPT_URL,$url);
  curl_setopt($ch,CURLOPT_POSTFIELDS,"api=$api&amount=$amount&redirect=$redirect"); 
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
  curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
  $result = curl_exec($ch); 
  curl_close($ch);
  
  if($result > 0 && is_numeric($result))
  {
	  $url = $settings['gateway_addr'] . "-$result";
	  $form['#action'] = $url;
  }else{
	  $form['#action'] = $result;
  }
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Proceed with payment'),
  );

  return $form;
}

/**
 * Implements hook_redirect_form_validate().
 */
function commerce_payline_redirect_form_validate($order, $payment_method) {

	$wrapper = entity_metadata_wrapper('commerce_order', $order);
	$amount = (int)$wrapper->commerce_order_total->amount->value()/100;
  
  if (isset($_POST['trans_id']) && is_numeric($_POST['trans_id']) &&
      isset($_POST['id_get'])   && is_numeric($_POST['id_get'])) {
  
	$api      = $payment_method['settings']['api_key'];
	$trans_id = $_POST['trans_id'];
	$id_get   = $_POST['id_get'];
	$url      = $payment_method['settings']['gateway_result'];
  
	$ch = curl_init();
	curl_setopt($ch,CURLOPT_URL,$url);
	curl_setopt($ch,CURLOPT_POSTFIELDS,"api=$api&id_get=$id_get&trans_id=$trans_id"); 
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
	$result = curl_exec($ch); 
	curl_close($ch);
  
    if ($result == "1") {
      watchdog('commerce_payline', "payline payment with ID GET " . $id_get .
	  " with Trans ID " . $trans_id . " verification succeeded, payline returned " + $result, array(), WATCHDOG_INFO);
	  
	  $feedback = array(
			'result' => $result,
			'trans_id' => $trans_id,
			'id_get' => $id_get
	  );
	  
      commerce_payline_process_transaction($order, $payment_method, $feedback);
      return TRUE;
    }
    else {
      watchdog('commerce_payline', "payline payment with ID GET " . $id_get .
	  " with Trans ID " . $trans_id . " verification failed, payline returned " + $result, array(), WATCHDOG_ERROR);
      return FALSE;
    }
  }
  else {
    watchdog('commerce_payline', 'No valid post info found', array(), WATCHDOG_ERROR);
    return FALSE;
  }
}

/**
 * Process the payment transaction with the info received from payline
 *
 * @param $order
 *   The loaded order that is being processed
 * @param $payment_method
 *   The payment method settings
 * @param $feedback
 *   The parameters received from payline regarding the payment
 */
function commerce_payline_process_transaction($order, $payment_method, $feedback) {
  $transaction = commerce_payment_transaction_new('payline', $order->order_id);
  $payment_status = commerce_payline_feedback_status($feedback['result']);
  
	  $transaction->instance_id   = $payment_method['instance_id'];
	  $transaction->remote_id     = $feedback['id_get'];
	  $transaction->remote_status = $feedback['trans_id'];
	  $transaction->amount        = $order->commerce_order_total[LANGUAGE_NONE][0]['amount'];
	  $transaction->currency_code = $order->commerce_order_total[LANGUAGE_NONE][0]['currency_code'];
	  $transaction->status        = $payment_status['code'];
	  $transaction->message       = $payment_status['message'];
	  $transaction->payload       = $feedback;
	  commerce_payment_transaction_save($transaction);
  
  if ($payment_status['code'] == COMMERCE_PAYMENT_STATUS_FAILURE) {
    commerce_payment_redirect_pane_previous_page($order);
  }
  else {
    commerce_payment_redirect_pane_next_page($order);
  }
}

function commerce_payline_feedback_status($status) {
  switch ($status) {
    /** SUCCESS **/
    case 1:  // Order stored
      $st = COMMERCE_PAYMENT_STATUS_SUCCESS;
      $msg = t('Payment processed by merchant');
      break;
    
    /** FAILURE **/
    case -1:
      $st = COMMERCE_PAYMENT_STATUS_FAILURE;
      $msg = t('Wrong API Key provided');
      break;
    case -2:
      $st = COMMERCE_PAYMENT_STATUS_FAILURE;
      $msg = t('Trans ID is not valid');
      break;
    case -3:
	  $st = COMMERCE_PAYMENT_STATUS_FAILURE;
      $msg = t('ID Get is not valid');
      break;
	case -3:
	  $st = COMMERCE_PAYMENT_STATUS_FAILURE;
      $msg = t('Transactions failed or does not exist');
      break;
    default:
      $st = COMMERCE_PAYMENT_STATUS_FAILURE;
      $msg = t('Unknown feedback status');
      break;
  }
  return array(
    'code' => $st,
    'message' => $msg,
  );
}