<?php
/**
 ** ***************************************************************** **\
 *                                                                      *
 *   Paystack Payment Gateway                                           *
 *   Version: 1.0.0                                                     *
 *   Build Date: 15 May 2016                                            *
 *                                                                      *
 ************************************************************************
 *                                                                      *
 *   Email: support@paystack.com                                        *
 *   Website: https://www.paystack.com                                  *
 *                                                                      *
\
************************************************************************/

if (!defined("WHMCS")) {
    die("<!-- Silence. SHHHHH!!!! -->");
}

/**
 * Define Paystack gateway configuration options.
 *
 * @return array
 */
function paystack_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Credit/Debit Cards (Powered by Paystack)'
        ),
        'gatewayLogs' => array(
            'FriendlyName' => 'Gateway logs',
            'Type' => 'yesno',
            'Description' => 'Tick to enable gateway logs',
            'Default' => '0'
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
            'Default' => '0'
        ),
        'liveSecretKey' => array(
            'FriendlyName' => 'Live Secret Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'sk_live_xxx'
        ),
        'livePublicKey' => array(
            'FriendlyName' => 'Live Public Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'pk_live_xxx'
        ),
        'testSecretKey' => array(
            'FriendlyName' => 'Test Secrect Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'sk_test_xxx'
        ),
        'testPublicKey' => array(
            'FriendlyName' => 'Test Public Key',
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'pk_test_xxx'
        )
    );
}

/**
 * Payment link.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return string
 */
function paystack_link($params)
{
    // Client
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];

    // Config Options
    if ($params['testMode'] == 'on') {
        $publicKey = $params['testPublicKey'];
        $secretKey = $params['testSecretKey'];
    } else {
        $publicKey = $params['livePublicKey'];
        $secretKey = $params['liveSecretKey'];
    }
    
    // check if there is an id in the GET meaning the invoice was loaded directly
    $paynowload = ( !array_key_exists('id', $_GET) );
    
    // Invoice
    $invoiceId = $params['invoiceid'];
    $amountinkobo = intval(floatval($params['amount'])*100);
    $currency = $params['currency'];

    $txStatus = new stdClass();
    if ($paynowload) {
        $ch = curl_init();

        $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
        
        $callback_url = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
            substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
            '/modules/gateways/callback/paystack.php?invoiceid='.
            rawurlencode($invoiceId);

        // set url
        curl_setopt($ch, CURLOPT_URL, "https://api.paystack.co/transaction/initialize/");

        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
            'Authorization: Bearer '. trim($secretKey)
            )
        );

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            http_build_query(
                array(
                "amount"=>$amountinkobo,
                "email"=>$email,
                "phone"=>$phone,
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
    }
    
    if (!(strtoupper($currency) == 'NGN')) {
        return ("Paystack only accepts NGN payments for now.");
    }

    if($paynowload && $txStatus->error){
        die($txStatus->error);
    }

    $code = '
    <form action="'.$txStatus->authorization_url.'" '.
        ($txStatus->authorization_url ? '' : 'onsubmit="payWithPaystack();"' ).'>
        <script src="https://js.paystack.co/v1/inline.js"></script>
        <input type="button" value="Pay Now" onclick="payWithPaystack()" />
        <script>
        // load jQuery 1.12.3 if not loaded
        (typeof $ === \'undefined\') && document.write("<scr" + "ipt type=\"text\/javascript\" '.
        'src=\"https:\/\/code.jquery.com\/jquery-1.12.3.min.js\"><\/scr" + "ipt>");
        </script>
        <script>
        $(function() {
            var paymentMethod = $(\'select[name="gateway"]\').val();
            if (paymentMethod === \'paystack\') {
                $(\'.payment-btn-container\').append(\'<button type="button"'. 
               ' onclick="payWithPaystack()"> Pay with Paystack</button>\');
            }
        });
        </script>
    </form>

    <script>
        function payWithPaystack(){
            var handler = PaystackPop.setup({
              key: \''.addslashes(trim($publicKey)).'\',
              email: \''.addslashes(trim($email)).'\',
              phone: \''.addslashes(trim($phone)).'\',
              amount: '.$amountinkobo.',
              callback: function(response){
                var url = document.URL;
                url = url.substring(0, url.lastIndexOf(\'/\') + 1);
                window.location.href = url + \'modules/gateways/callback/paystack.php?trxref=\'+
                        response.trxref+\'&invoiceid=\' + \''.$invoiceId.'\';
              },
              onClose: function(){
                  alert(\'Payment Canceled. Click on the "Pay" button to try again.\');
              }
            });
            handler.openIframe();
       }
    </script>';

    return $code;
}
