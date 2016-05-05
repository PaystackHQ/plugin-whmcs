<?php
// *************************************************************************
// *                                                                       *
// * Paystack Payment Gateway                                              *
// * Copyright 2016 Paystack Ltd. All rights reserved.                     *
// * Version: 1.0.4                                                        *
// * Build Date: 9 Mar 2016                                                *
// *                                                                       *
// *************************************************************************
// *                                                                       *
// * Email: support@paystack.com                                           *
// * Website: https://www.paystack.com                                     *
// *                                                                       *
// *************************************************************************

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
            'Value' => 'Credit/Debit Cards'
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
    // Invoice
    $invoiceId = $params['invoiceid'];
    $amountinkobo = intval(floatval($params['amount'])*100);
    $currency = $params['currency'];

    // Client
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];

    // Config Options
    if ($params['testMode'] == 'on') {
        $publicKey = $params['testPublicKey'];
    } else {
        $publicKey = $params['livePublicKey'];
    }

    $code = '
    <form >
        <script src="https://js.paystack.co/v1/inline.js"></script>
        <script>
        // load jQuery 1.12.3 if not loaded
        !window.jQuery && document.write("<scr" + "ipt type="text/javascript" src="https://code.jquery.com/jquery-1.12.3.min.js"></scr" + "ipt>");
        $(function() {
            var paymentMethod = $(\'select[name="gateway"]\').val();
            if (paymentMethod === \'paystack\') {
                $(\'.payment-btn-container\').append(\'<button type="button" onclick="payWithPaystack()"> Pay with Paystack</button>\');
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

                $.ajax({
                    method: \'POST\',
                    url: url + \'modules/gateways/callback/paystack.php\',
                    data: {
                        invoiceId: \''.$invoiceId.'\',
                        trxref: response.trxref
                    }
                }).success(function (data) {
                    if (data === \'success\') {
                        location.reload();
                    } else {
                        console.log(data);
                    }
                });
              },
              onClose: function(){
                  alert(\'window closed\');
              }
            });
            handler.openIframe();
        }
    </script>';

    return $code;
}
