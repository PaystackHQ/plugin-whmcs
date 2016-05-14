<?php
// *************************************************************************
// *                                                                       *
// * Paystack Payment Gateway                                                *
// * Version: 1.0.4                                                        *
// * Build Date: 9 Mar 2016                                                *
// *                                                                       *
// *************************************************************************
// *                                                                       *
// * Email: support@paystack.com                                           *
// * Website: https://www.paystack.com                                      *
// *                                                                       *
// *************************************************************************

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
$invoiceId = filter_input(INPUT_GET, "invoiceId");
$trxref = filter_input(INPUT_GET, "trxref");

if ($gatewayParams['testMode'] == 'on') {
    $secretKey = $gatewayParams['testSecretKey'];
} else {
    $secretKey = $gatewayParams['liveSecretKey'];
}

/**
 * Verify Paystack transaction.
 */
$txStatus = verifyTransaction($trxref, $secretKey);

if ($txStatus) {
    $success = true;
} else {
    if ($gatewayParams['gatewayLogs'] == 'on') {
        $output = "Transaction ID: " . $jsonResponse['customer_reference']
            . "\r\nInvoice ID: " . $invoiceId
            . "\r\nStatus: failed";
        logTransaction($gatewayModuleName, $output, "Unsuccessful");
    }
    $success = false;
}

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

if ($success) {
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
    addInvoicePayment($invoiceId, $trxref, floatval($txStatus->amount)/100, 0, $gatewayModuleName);

    // load invoice
    echo 'Paid. load invoice.';
} else {
    die($txStatus->error);
}

function verifyTransaction($trxref, $secretKey)
{
    $ch = curl_init();
    $txStatus = new stdClass();

    // set url
    curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/verify/" . rawurlencode($trxref));

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer '. trim($secretKey)
    ));

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
