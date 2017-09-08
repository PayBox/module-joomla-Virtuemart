<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentPlatron extends vmPSPlugin
{
    // instance of class
    public static $_this = false;
    
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        
        $this->_loggable   = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush        = array(
            'payment_logos' => array(
                '',
                'char'
            ),
			'testing_mode' => array(
                '',
                'char'
            ),
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'platron_id' => array(
                '',
                'int'
            ),
            'platron_secret' => array(
                '',
                'string'
            ),
			'platron_life_time' => array(
				'',
				'int'
			),
            'status_success' => array(
                '',
                'char'
            ),
            'status_pending' => array(
                '',
                'char'
            ),
            'status_canceled' => array(
                '',
                'char'
            )
        );
        
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
    
    
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Walletone Table');
    }
    
    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3) ',
            'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'smallint(11) DEFAULT NULL'
        );
        
        return $SQLfields;
    }
    
    function plgVmConfirmedOrder($cart, $order) // главный метод - построение формы
    {
		include("PG_Signature.php");
		
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        if (!$method->payment_currency)
            $this->getPaymentCurrency($method);

        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

        // END printing out HTML Form code (Payment Extra Info)
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db =& JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();

		$check_url = JURI::root() . "index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pelement=platron&format=raw&type=check&order_id=".$order['details']['BT']->virtuemart_order_id;
        $result_url = JURI::root() . "index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pelement=platron&format=raw&type=result&order_id=".$order['details']['BT']->virtuemart_order_id;
		
		$arrReq = array();
		/* Обязательные параметры */
		$arrReq['pg_merchant_id'] = $method->platron_id;// Идентификатор магазина
		$arrReq['pg_order_id']    = $order['details']['BT']->order_number;		// Идентификатор заказа в системе магазина
		$arrReq['pg_amount']      = sprintf("%01.2f",$order['details']['BT']->order_total);		// Сумма заказа
		$arrReq['pg_description'] = "Оплата заказа ".$_SERVER['HTTP_HOST']; // Описание заказа (показывается в Платёжной системе)
		$arrReq['pg_user_ip'] = $_SERVER['REMOTE_ADDR']; // Описание заказа (показывается в Платёжной системе)
		$arrReq['pg_site_url'] = $_SERVER['HTTP_HOST']; // Для возврата на сайт
		$arrReq['pg_lifetime'] = $method->platron_life_time; // Время жизни в секундах
		$arrReq['pg_user_ip'] = $_SERVER['REMOTE_ADDR'];
		$arrReq['pg_check_url'] = $check_url; // Проверка заказа
		$arrReq['pg_result_url'] = $result_url; // Оповещение о результатах

		if(isset($order->phone_1)){ // Телефон в 11 значном формате
			$strUserPhone = preg_replace('/\D+/','',$order['details']['BT']->phone_1);
			if(strlen($strUserPhone) == 10)			
				$strUserPhone .= "7";
			$arrReq['pg_user_phone'] = $strUserPhone;
			if(strlen($strUserPhone) < 10)
				unset($arrReq['pg_user_phone']);
		}
		
		if(isset($order->email)){
			$arrReq['pg_user_contact_email'] = $order['details']['BT']->email;
			$arrReq['pg_user_email'] = $order['details']['BT']->email; // Для ПС Деньги@Mail.ru
		}

		$jmlThisDocument = & JFactory::getDocument();
		switch ($jmlThisDocument->language) 
        {
            case 'en-gb': $language = 'EN'; break;
            case 'ru-ru': $language = 'RU'; break;
            default: $language = 'EN'; break;
        }
		
		$testing_mode = 0;
		if($method->testing_mode == "TEST")
			$testing_mode = 1;
		
		$arrReq['pg_language'] = $language;
		$arrReq['pg_testing_mode'] = $testing_mode;
		
		if($currency_code_3 == "RUR")
			$arrReq['pg_currency'] = "RUB";
		else
			$arrReq['pg_currency'] = $currency_code_3;
		
		$arrReq['pg_salt'] = rand(21,43433);
		$arrReq['pg_sig'] = PG_Signature::make('payment.php', $arrReq, $method->platron_secret);
		$query = http_build_query($arrReq);
//		var_dump($arrReq);
//		die();
		header("Location: https://paybox.kz/payment.php?$query");
		
        return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, $this->renderPluginName($method, $order), 'P');
    }
    
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null; // Another method was selected, do nothing
        }
        
        $db = JFactory::getDBO();
        $q  = 'SELECT * FROM `' . $this->_tablename . '` ' . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }
        $this->getPaymentCurrency($paymentTable);
        
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";
        return $html;
    }
    
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }
    
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }
    
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }
    
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }
    
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);
        
        $paymentCurrencyId = $method->payment_currency;
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
    
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }
    
    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }
    
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
    
    protected function displayLogos($logo_list)
    {
        $img = "";
        
        if (!(empty($logo_list))) {
            $url = JURI::root() . str_replace(JPATH_ROOT, '', dirname(__FILE__)) . '/';
            if (!is_array($logo_list))
                $logo_list = (array) $logo_list;
            foreach ($logo_list as $logo) {
                $alt_text = substr($logo, 0, strpos($logo, '.'));
                $img .= '<img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /> ';
            }
        }
        return $img;
    }
    
    public function plgVmOnPaymentNotification()
    {
		include("PG_Signature.php");
		unset($_GET['Itemid']);
		
        if (JRequest::getVar('pelement') != 'platron') {
            return null;
        }
		if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		
		$orderid      = JRequest::getInt('order_id', 0);
		$strTypeRequest = JRequest::getVar('type');
        $order_model  = new VirtueMartModelOrders();
        $order_info   = $order_model->getOrder($orderid);
		$method = $this->getVmPluginMethod($order_info['details']['BT']->virtuemart_paymentmethod_id);
		$order_number = $order_info['details']['BT']->order_number;

		switch ($strTypeRequest) {
			case 'check':
				$arrParams = $_GET;
				$thisScriptName = PG_Signature::getOurScriptName();
	
				if ( !PG_Signature::check($arrParams['pg_sig'], $thisScriptName, $arrParams, $method->platron_secret) )
					die("Bad signature");

				/*
				 * Проверка того, что заказ ожидает оплаты
				 */
				if($method->status_pending == $order_info['details']['BT']->order_status)
					$is_order_available = true;
				else{
					$is_order_available = false;
					$error_desc = "Товар не доступен";
				}

				$arrResp['pg_salt']              = $arrParams['pg_salt']; // в ответе необходимо указывать тот же pg_salt, что и в запросе
				$arrResp['pg_status']            = $is_order_available ? 'ok' : 'error';
				$arrResp['pg_error_description'] = $is_order_available ?  ""  : $error_desc;
				$arrResp['pg_sig'] = PG_Signature::make($thisScriptName, $arrResp, $method->platron_secret);

				$xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
				$xml->addChild('pg_salt', $arrResp['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
				$xml->addChild('pg_status', $arrResp['pg_status']);
				$xml->addChild('pg_error_description', htmlentities($arrResp['pg_error_description']));
				$xml->addChild('pg_sig', $arrResp['pg_sig']);
				echo $xml->asXML();
				die();
				break;
				
				
				case 'result':
					$arrParams = $_GET;
					$thisScriptName = PG_Signature::getOurScriptName();
//					if ( !PG_Signature::check($arrParams['pg_sig'], $thisScriptName, $arrParams, $method->platron_secret) )
//						die("Bad signature");

					$xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
					$js_result_status = 'ok';
					$pg_description = 'Оплата принята';
					$modelOrder = new VirtueMartModelOrders();

					if ( $arrParams['pg_result'] == 1 ) {
						if($method->status_pending == $order_info['details']['BT']->order_status){
	
							$order['order_status']        = $method->status_success;
							$order['virtuemart_order_id'] = $orderid;
							$order['customer_notified']   = 1;
							$order['comments'] = JTExt::sprintf('VMPAYMENT_PLATRON_PAYMENT_CONFIRMED', $order_number);
							ob_start();
							$modelOrder->updateStatusForOneOrder($orderid, $order, true);
							ob_end_clean();
						}
						else{
							$js_result_status = 'error';
							$pg_description = 'Оплата не может быть принята';
							$xml->addChild('pg_error_description', 'Оплата не может быть принята');
							if($arrParams['pg_can_reject']){
								$js_result_status = 'reject';
							}
						}
					}
					else {
							$order['order_status']        = $method->status_canceled;
							$order['virtuemart_order_id'] = $orderid;
							$order['customer_notified']   = 1;
							$order['comments'] = JTExt::sprintf('VMPAYMENT_PLATRON_PAYMENT_FAILED', $order_number);
							ob_start();
							$modelOrder->updateStatusForOneOrder($orderid, $order, true);
							ob_end_clean();
					}
					// обрабатываем случай успешной оплаты заказа с номером $order_id
					$xml->addChild('pg_salt', $arrParams['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
					$xml->addChild('pg_status', $js_result_status);
					$xml->addChild('pg_description', $pg_description);
					$xml->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $xml, $method->platron_secret));
					print $xml->asXML();
					die();
					break;
			
					
			default:
				break;
		}
    }
    
    function plgVmOnPaymentResponseReceived(&$html)
    {
        return true;
    }
    
    function plgVmOnUserPaymentCancel()
    {
        return false;
    }

    private function notifyCustomer($order, $order_info)
    {
        $lang     = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        if (!class_exists('VirtueMartControllerVirtuemart'))
            require(JPATH_VM_SITE . DS . 'controllers' . DS . 'virtuemart.php');
        
        if (!class_exists('shopFunctionsF'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
        $controller = new VirtueMartControllerVirtuemart();
        $controller->addViewPath(JPATH_VM_ADMINISTRATOR . DS . 'views');
        
        $view = $controller->getView('orders', 'html');
        if (!$controllerName)
            $controllerName = 'orders';
        $controllerClassName = 'VirtueMartController' . ucfirst($controllerName);
        if (!class_exists($controllerClassName))
            require(JPATH_VM_SITE . DS . 'controllers' . DS . $controllerName . '.php');
        
        $view->addTemplatePath(JPATH_COMPONENT_ADMINISTRATOR . '/views/orders/tmpl');
        
        $db = JFactory::getDBO();
        $q  = "SELECT CONCAT_WS(' ',first_name, middle_name , last_name) AS full_name, email, order_status_name
			FROM #__virtuemart_order_userinfos
			LEFT JOIN #__virtuemart_orders
			ON #__virtuemart_orders.virtuemart_user_id = #__virtuemart_order_userinfos.virtuemart_user_id
			LEFT JOIN #__virtuemart_orderstates
			ON #__virtuemart_orderstates.order_status_code = #__virtuemart_orders.order_status
			WHERE #__virtuemart_orders.virtuemart_order_id = '" . $order['virtuemart_order_id'] . "'
			AND #__virtuemart_orders.virtuemart_order_id = #__virtuemart_order_userinfos.virtuemart_order_id";
        $db->setQuery($q);
        $db->query();
        $view->user  = $db->loadObject();
        $view->order = $order;
        JRequest::setVar('view', 'orders');
        $user = $this->sendVmMail($view, $order_info['details']['BT']->email, false);
        if (isset($view->doVendor)) {
            $this->sendVmMail($view, $view->vendorEmail, true);
        }
    }

    private function sendVmMail(&$view, $recipient, $vendor = false)
    {
        ob_start();
        $view->renderMailLayout($vendor, $recipient);
        $body = ob_get_contents();
        ob_end_clean();
        
        $subject = (isset($view->subject)) ? $view->subject : JText::_('COM_VIRTUEMART_DEFAULT_MESSAGE_SUBJECT');
        $mailer  = JFactory::getMailer();
        $mailer->addRecipient($recipient);
        $mailer->setSubject($subject);
        $mailer->isHTML(VmConfig::get('order_mail_html', true));
        $mailer->setBody($body);
        
        if (!$vendor) {
            $replyto[0] = $view->vendorEmail;
            $replyto[1] = $view->vendor->vendor_name;
            $mailer->addReplyTo($replyto);
        }
        
        if (isset($view->mediaToSend)) {
            foreach ((array) $view->mediaToSend as $media) {
                $mailer->addAttachment($media);
            }
        }
        return $mailer->Send();
    }
    
}
