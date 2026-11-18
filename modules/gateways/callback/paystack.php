<?php register_shutdown_function( 'paystackshutdownFunction'); 
        

/**
/ ********************************************************************* \
 *                                                                      *
 *   Paystack Payment Gateway                                           *
 *   Version: 2.4.0                                                     *
 *   Build Date: November 18, 2026                                         *
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

$paystackRkFile = __DIR__ . '/../paystack/RestrictedKey.php';
if (!defined('PAYSTACK_RK_VERSION') && is_file($paystackRkFile)) {
    require_once $paystackRkFile;
}
$rkLoaded = defined('PAYSTACK_RK_VERSION');

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

$trxref = filter_input(INPUT_GET, "trxref");

if ($gatewayParams['testMode'] == 'on') {
    $secretKey = trim((string) ($gatewayParams['testSecretKey'] ?? ''));
    $webhookSecret = trim((string) ($gatewayParams['testWebhookSecret'] ?? ''));
} else {
    $secretKey = trim((string) ($gatewayParams['liveSecretKey'] ?? ''));
    $webhookSecret = trim((string) ($gatewayParams['liveWebhookSecret'] ?? ''));
}
$keyType = $rkLoaded ? paystackRkKeyType($secretKey) : 'unknown';


// if ((strtoupper($_SERVER['REQUEST_METHOD']) != 'POST' ) || !array_key_exists('HTTP_X_PAYSTACK_SIGNATURE', $_SERVER) ) {
//     exit();
// }
$input = @file_get_contents("php://input");
$event = json_decode($input);
if (isset($event->event)) {
   // echo "<pre>";
    if (!isPaystackWebhookAuthentic($input, $webhookSecret, $secretKey, $_SERVER, time())) {
      if ($rkLoaded && ($keyType === 'rk' || $webhookSecret !== '') && paystackRkLooksLikePaystackDelivery($_SERVER)) {
          $dropReason = paystackRkWebhookDropReason($webhookSecret, $_SERVER, $keyType);
          if (paystackRkThrottle('webhook_' . $dropReason)) {
              logTransaction(
                  $gatewayModuleName,
                  "A request claiming to be a Paystack webhook was ignored."
                      . "\r\nKey type: " . $keyType
                      . "\r\nLikely cause: " . paystackRkWebhookDropMessage($dropReason),
                  "Unsuccessful"
              );
          }
      }
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
                $pk = (string) ($gatewayParams['testPublicKey'] ?? '');
            } else {
                $pk = (string) ($gatewayParams['livePublicKey'] ?? '');
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
$txStatus = verifyTransaction($trxref, $secretKey, $httpCode, $rawBody);

$rkProblem = ($keyType === 'rk' && !empty($txStatus->error) && (string) $trxref !== '')
    ? paystackRkVerifyProblem($httpCode, $rawBody)
    : '';

if ($rkProblem !== '') {
    $rkLine = "Restricted key problem on GET /transaction/verify/:ref"
        . "\r\nKey type: rk"
        . "\r\nReason: " . paystackRkVerifyProblemMessage($rkProblem)
        . "\r\nInvoice ID: " . (int) $invoiceId;
    if ($gatewayParams['gatewayLogs'] == 'on' || paystackRkThrottle('verify_' . $rkProblem)) {
        logTransaction($gatewayModuleName, $rkLine, "Unsuccessful");
    }
    $success = false;
} elseif (!empty($txStatus->error)) {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nKey type: " . $keyType
            . "\r\nStatus: failed"
            . "\r\nReason: {$txStatus->error}";
        logTransaction($gatewayModuleName, $output, "Unsuccessful");
    }
    $success = false;
} elseif (($txStatus->status ?? '') == 'success') {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ref: " . $trxref
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nKey type: " . $keyType
            . "\r\nStatus: succeeded";
        logTransaction($gatewayModuleName, $output, "Successful");

             
            //PSTK Logger
            
            if ($gatewayParams['testMode'] == 'on') {
                $pk = (string) ($gatewayParams['testPublicKey'] ?? '');
            } else {
                $pk = (string) ($gatewayParams['livePublicKey'] ?? '');
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
            . "\r\nKey type: " . $keyType
            . "\r\nStatus: " . ($txStatus->status ?? '');
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
        rawurlencode((string) $invoiceId);

    // Output (an error message, or the redirect page) may already have been sent
    if (!headers_sent()) {
        header('Location: '.$invoice_url);
    }
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

    $amount = floatval($txStatus->amount ?? 0)/100;
    $requested_amount = floatval($txStatus->requested_amount ?? 0)/100;
    if (isset($requested_amount) && $requested_amount > 0) 
    {
        $amount = $requested_amount;
    }
    $fees = floatval($txStatus->fees ?? 0)/100;
    if ($gatewayParams['convertto']) {
        // mysql_* functions were removed in PHP 7; use WHMCS's database layer instead
        $invoice_currency_id = \WHMCS\Database\Capsule::table('tblinvoices')
            ->join('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblinvoices.id', $invoiceId)
            ->value('tblclients.currency');

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
        rawurlencode((string) $invoiceId);

    header('Location: '.$invoice_url);
} elseif ($keyType === 'rk') {
    die("We couldn't confirm this payment. Please contact the merchant.");
} else {
    die(($txStatus->error ?? '') . ' ; ' . ($txStatus->status ?? ''));
}

// Test-only override, same rule as paystackRkApiBase(); duplicated so the callback works without the support file.
function paystackCbApiBase()
{
    if (defined('PAYSTACK_WHMCS_TEST_API_BASE')
        && in_array(PHP_SAPI, array('cli', 'cli-server'), true)
        && preg_match('~^http://127\.0\.0\.1:\d{1,5}(/|\z)~', (string) PAYSTACK_WHMCS_TEST_API_BASE) === 1
    ) {
        return rtrim((string) PAYSTACK_WHMCS_TEST_API_BASE, '/') . '/';
    }
    return 'https://api.paystack.co/';
}

function isPaystackWebhookAuthentic($input, $webhookSecret, $secretKey, $server, $now)
{
    if ($webhookSecret !== '' && isset($server['HTTP_PAYSTACK_SIGNATURE'])) {
        return verifyPaystackSignature($server['HTTP_PAYSTACK_SIGNATURE'], $input, $webhookSecret, $now);
    }
    $legacySignature = $server['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    if (strpos($secretKey, 'sk_') === 0 && $legacySignature !== '') {
        return hash_equals(hash_hmac('sha512', $input, $secretKey), $legacySignature);
    }
    return false;
}

function verifyPaystackSignature($header, $input, $webhookSecret, $now)
{
    $timestamp = null;
    $signatures = array();
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) {
            continue;
        }
        if ($pair[0] === 't') {
            $timestamp = $pair[1];
        } elseif ($pair[0] === 'v1' && strpos($pair[1], ':') !== false) {
            $signatures[] = substr($pair[1], strpos($pair[1], ':') + 1);
        }
    }
    if ($timestamp === null || !ctype_digit($timestamp) || abs($now - (int) $timestamp) > 300) {
        return false;
    }
    $expected = hash_hmac('sha512', $timestamp . '.' . $input, $webhookSecret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}

function verifyTransaction($trxref, $secretKey, &$httpCode = 0, &$rawBody = '')
{
    $ch = curl_init();
    $txStatus = new stdClass();

    // set url
    curl_setopt($ch, CURLOPT_URL, paystackCbApiBase() . "transaction/verify/" . rawurlencode((string) $trxref));

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
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $rawBody = is_string($response) ? $response : '';
    
    // should be 0
    if (curl_errno($ch)) {
        // curl ended with an error
        $txStatus->error = "cURL said:" . curl_error($ch);
    } else {

        // Then, after your curl_exec call:
        $body = json_decode($response);
        if (empty($body->status) || !is_object($body->data ?? null)) {
            // paystack has an error message for us
            $txStatus->error = "Paystack API said: " . ($body->message ?? 'invalid response');
        } else {
            // get body returned by Paystack API
            $txStatus = $body->data;
        }
    }

    return $txStatus;
}







