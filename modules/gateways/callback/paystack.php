<?php register_shutdown_function( 'paystackshutdownFunction'); 
        

/**
/ ********************************************************************* \
 *                                                                      *
 *   Paystack Payment Gateway                                           *
 *   Version: 2.3.0                                                     *
 *   Build Date: April 25, 2024                                           *
 *                                                                      *
 ************************************************************************
 *                                                                      *
 *   Email: support@paystack.com                                        *
 *   Website: https://www.paystack.com                                  *
 *                                                                      *
\ ********************************************************************* /
**/


class whmcs_paystack_plugin_tracker {
    var $public_key;
    var $plugin_name;
    function __construct($plugin, $pk){
        //configure plugin name
        //configure public key
        $this->plugin_name = $plugin;
        $this->public_key = $pk;
    }

   

    function log_transaction_success($trx_ref){
        //send reference to logger along with plugin name and public key
        $url = "https://plugin-tracker.paystackintegrations.com/log/charge_success";

        $fields = [
            'plugin_name'  => $this->plugin_name,
            'transaction_reference' => $trx_ref,
            'public_key' => $this->public_key
        ];

        $fields_string = http_build_query($fields);

        $ch = curl_init();

        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);

        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 

        //execute post
        $result = curl_exec($ch);
        //  echo $result;
    }
}


// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve data returned in payment gateway callback
$invoiceId = filter_input(INPUT_GET, "invoiceid");
$txnref         = $invoiceId . '_' .time();

$trxref = filter_input(INPUT_GET, "trxref");

if ($gatewayParams['testMode'] == 'on') {
    $secretKey = $gatewayParams['testSecretKey'];
} else {
    $secretKey = $gatewayParams['liveSecretKey'];
}


if(strtolower(filter_input(INPUT_GET, 'go'))==='standard'){
    // falling back to standard
    $ch = curl_init();

    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
    
    $amountinkobo = filter_input(INPUT_GET, 'amountinkobo');
    $email = filter_input(INPUT_GET, 'email');
    $phone = filter_input(INPUT_GET, 'phone');

    $callback_url = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        $_SERVER['SCRIPT_NAME'] . '?invoiceid=' . rawurlencode($invoiceId);

    $txStatus = new stdClass();
    // set url
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/initialize/");

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
        'Authorization: Bearer '. trim($secretKey),
        'Content-Type: application/json'
        )
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        json_encode(
            array(
            "amount"=>$amountinkobo,
            "email"=>$email,
            "phone"=>$phone,
            "reference" => $txnref,
            "callback_url"=>$callback_url
            )
        )
    );
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);

    // exec the cURL
    $response = curl_exec($ch);

    // should be 0
    if (curl_errno($ch)) {
        // curl ended with an error
        $txStatus->error = "cURL said:" . curl_error($ch);
        curl_close($ch);
    } else {
        //close connection
        curl_close($ch);

        // Then, after your curl_exec call:
        $body = json_decode($response);
        if (!$body->status) {
            // paystack has an error message for us
            $txStatus->error = "Paystack API said: " . $body->message;
        } else {
            // get body returned by Paystack API
            $txStatus = $body->data;
        }
    }
    if(!$txStatus->error){
        header('Location: ' . $txStatus->authorization_url);
        die('<meta http-equiv="refresh" content="0;url='.$txStatus->authorization_url.'" />
        Redirecting to <a href=\''.$txStatus->authorization_url.'\'>'.$txStatus->authorization_url.'</a>...');
    } else {
        if ($gatewayParams['gatewayLogs'] == 'on') {
            $output = "Transaction Initialize failed"
                . "\r\nReason: {$txStatus->error}";
            logTransaction($gatewayModuleName, $output, "Unsuccessful");
        }
        die($txStatus->error);
    }
}
// if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST' ) || !array_key_exists('HTTP_X_PAYSTACK_SIGNATURE', $_SERVER) ) {
//     exit();
// }
$input = @file_get_contents("php://input");
$event = json_decode($input);
if (isset($event->event)) {
   // echo "<pre>";
    if(!$_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] || ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $input, $secretKey))){
      exit();
    }

    switch($event->event){
        case 'subscription.create':

            break;
        case 'subscription.disable':
            break;
        case 'charge.success':

            $trxref = $event->data->reference;
            
            //PSTK Logger

            if ($gatewayParams['testMode'] == 'on') {
                $pk = $gatewayParams['testPublicKey'];
            } else {
                $pk = $gatewayParams['livePublicKey'];
            }
            $pstk_logger = new whmcs_paystack_plugin_tracker('whmcs',$pk );
            $pstk_logger->log_transaction_success($trxref);


            //-------------------------------------


            $order_details  = explode( '_', $trxref);
            $invoiceId       = (int) $order_details[0];

            break;
        case 'invoice.create':
           // Recurring payments
        case 'invoice.update':
           // Recurring payments
            
            break;
    }
    http_response_code(200);

    
    // exit();
}

/**
 * Verify Paystack transaction.
 */
$txStatus = verifyTransaction($trxref, $secretKey);
 
if ($txStatus->error) {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nStatus: failed"
            . "\r\nReason: {$txStatus->error}";
        logTransaction($gatewayModuleName, $output, "Unsuccessful");
    }
    $success = false;
} elseif ($txStatus->status == 'success') {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nStatus: succeeded";
        logTransaction($gatewayModuleName, $output, "Successful");

             
            //PSTK Logger
            
            if ($gatewayParams['testMode'] == 'on') {
                $pk = $gatewayParams['testPublicKey'];
            } else {
                $pk = $gatewayParams['livePublicKey'];
            }
            $pstk_logger_ = new whmcs_paystack_plugin_tracker('whmcs',$pk );
            $pstk_logger_->log_transaction_success($trxref);


            //-------------------------------------

    }
    $success = true;
} else {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nStatus: {$txStatus->status}";
        logTransaction($gatewayModuleName, $output, "Unsuccessful");
    }
    $success = false;
}
function paystackshutdownFunction(){
    $invoiceId = filter_input(INPUT_GET, "invoiceid");
    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
        
    $invoice_url = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/../../../viewinvoice.php?id='.
        rawurlencode($invoiceId);

    header('Location: '.$invoice_url);
}
if ($success) {

    // print_r($txStatus);
    // die();
    /**
     * Validate Callback Invoice ID.
     *
     * Checks invoice ID is a valid invoice number.
     *
     * Performs a die upon encountering an invalid Invoice ID.
     *
     * Returns a normalised invoice ID.
     */
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

    /**
     * Check Callback Transaction ID.
     *
     * Performs a check for any existing transactions with the same given
     * transaction number.
     *
     * Performs a die upon encountering a duplicate.
     */
    checkCbTransID($trxref);

    $amount = floatval($txStatus->amount)/100;
    $requested_amount = floatval($txStatus->requested_amount)/100;
    if (isset($requested_amount) && $requested_amount > 0) 
    {
        $amount = $requested_amount;
    }
    $fees = floatval($txStatus->fees)/100;
    if ($gatewayParams['convertto']) {
        $result = select_query("tblclients", "tblinvoices.invoicenum,tblclients.currency,tblcurrencies.code", array("tblinvoices.id" => $invoiceId), "", "", "", "tblinvoices ON tblinvoices.userid=tblclients.id INNER JOIN tblcurrencies ON tblcurrencies.id=tblclients.currency");
        $data = mysql_fetch_array($result);
        $invoice_currency_id = $data['currency'];

        $converto_amount = convertCurrency($amount, $gatewayParams['convertto'], $invoice_currency_id);
        $converto_fees = convertCurrency($fees, $gatewayParams['convertto'], $invoice_currency_id);

        $amount = format_as_currency($converto_amount);
        $fees = format_as_currency($converto_fees);
    }

    /**
     * Add Invoice Payment.
     *
     * Applies a payment transaction entry to the given invoice ID.
     *
     * @param int $invoiceId         Invoice ID
     * @param string $transactionId  Transaction ID
     * @param float $paymentAmount   Amount paid (defaults to full balance)
     * @param float $paymentFee      Payment fee (optional)
     * @param string $gatewayModule  Gateway module name
     */
    addInvoicePayment($invoiceId, $trxref, $amount, $fees, $gatewayModuleName);

    // load invoice
    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
        
    $invoice_url = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/../../../viewinvoice.php?id='.
        rawurlencode($invoiceId);

    header('Location: '.$invoice_url);
} else {
    die($txStatus->error . ' ; ' . $txStatus->status);
}

function verifyTransaction($trxref, $secretKey)
{
    $ch = curl_init();
    $txStatus = new stdClass();

    // set url
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/" . rawurlencode($trxref));

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
        'Authorization: Bearer '. trim($secretKey)
        )
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    
    // exec the cURL
    $response = curl_exec($ch);
    
    // should be 0
    if (curl_errno($ch)) {
        // curl ended with an error
        $txStatus->error = "cURL said:" . curl_error($ch);
        curl_close($ch);
    } else {
        //close connection
        curl_close($ch);

        // Then, after your curl_exec call:
        $body = json_decode($response);
        if (!$body->status) {
            // paystack has an error message for us
            $txStatus->error = "Paystack API said: " . $body->message;
        } else {
            // get body returned by Paystack API
            $txStatus = $body->data;
        }
    }

    return $txStatus;
}







