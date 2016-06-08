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

    if (!(strtoupper($currency) == 'NGN')) {
        return ("Paystack only accepts NGN payments for now.");
    }
    
    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
    $fallbackUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/modules/gateways/callback/paystack.php?' . 
        http_build_query(array(
            'invoiceid'=>$invoiceId,
            'email'=>$email,
            'phone'=>$phone,
            'amountinkobo'=>$amountinkobo,
            'go'=>'standard'
        ));
    $callbackUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/modules/gateways/callback/paystack.php?' . 
        http_build_query(array(
            'invoiceid'=>$invoiceId
        ));

    $code = '
    <form target="hiddenIFrame" action="about:blank">
        <script src="https://js.paystack.co/v1/inline.js"></script>
        <div class="payment-btn-container2"></div>
        <script>
            // load jQuery 1.12.3 if not loaded
            (typeof $ === \'undefined\') && document.write("<scr" + "ipt type=\"text\/javascript\" '.
            'src=\"https:\/\/code.jquery.com\/jquery-1.12.3.min.js\"><\/scr" + "ipt>");
        </script>
        <script>
            $(function() {
                var paymentMethod = $(\'select[name="gateway"]\').val();
                if (paymentMethod === \'paystack\') {
                    $(\'.payment-btn-container2\').hide();
                    $(\'.payment-btn-container\').append(\'<button type="button"'. 
                   ' onclick="payWithPaystack()"> Pay with ATM Card</button>\');
                }
            });
        </script>
    </form>
    <div class="hidden" style="display:none"><iframe name="hiddenIFrame"></iframe></div>
    <script>
        var paystackIframeOpened = false;
        var paystackHandler = PaystackPop.setup({
          key: \''.addslashes(trim($publicKey)).'\',
          email: \''.addslashes(trim($email)).'\',
          phone: \''.addslashes(trim($phone)).'\',
          amount: '.$amountinkobo.',
          callback: function(response){
            window.location.href = \''.addslashes($callbackUrl).'&trxref=\' + response.trxref;
          },
          onClose: function(){
              paystackIframeOpened = false;
          }
        });
        function payWithPaystack(){
            if (paystackHandler.fallback || paystackIframeOpened) {
              // Handle non-support of iframes or
              // Being able to click PayWithPaystack even though iframe already open
              window.location.href = \''.addslashes($fallbackUrl).'\';
            } else {
              paystackHandler.openIframe();
              paystackIframeOpened = true;
              $(\'img[alt="Loading"]\').hide();
              $(\'div.alert.alert-info.text-center\').html(\'Click the button below to retry payment...\');
              $(\'.payment-btn-container2\').append(\'<button type="button"'. 
                ' onclick="payWithPaystack()">Pay with ATM Card</button>\');
            }
       }
       ' . ( $paynowload ? 'setTimeout("payWithPaystack()", 5100);' : '' ) . '
    </script>';

    return $code;
}
