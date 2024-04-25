<?php
/**
 * ****************************************************************** **\
 *                                                                      *
 *   Paystack Payment Gateway                                           *
 *   Version: 2.3.0                                                     *
 *   Build Date: April 25, 2024                                            *
 *                                                                      *
 * **********************************************************************
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
    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
    $callbackUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        //substr($_SERVER['SERVER_NAME'], 0, strrpos($_SERVER['SERVER_NAME'], '/')) . '/'
        substr(str_replace('/admin/', '/', $_SERVER['REQUEST_URI']), 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/modules/gateways/callback/paystack.php';

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Paystack (Debit/Credit Cards)'
        ),
        'webhook' => array(
            'FriendlyName' => 'Webhook URL',
            'Type' => 'yesno',
            'Description' => 'Copy and paste this URL on your Webhook URL settings <code>' . $callbackUrl . '</code>',
            'Default' => "'" . $callbackUrl . "'",
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
    $params['langpaynow']
        = array_key_exists('langpaynow', $params) ?
        $params['langpaynow'] : 'Pay with Paystack';

    // Config Options
    if ($params['testMode'] == 'on') {
        $publicKey = $params['testPublicKey'];
        $secretKey = $params['testSecretKey'];
    } else {
        $publicKey = $params['livePublicKey'];
        $secretKey = $params['liveSecretKey'];
    }

    // check if there is an id in the GET meaning the invoice was loaded directly
    $paynowload = (!array_key_exists('id', $_GET));

    // Invoice
    $invoiceId = $params['invoiceid'];
    $amountinkobo = round(floatval($params['amount']) * 100);
    $currency = $params['currency'];
    ///Transaction_reference
    $txnref = $invoiceId . '_' . time();


    if (!in_array(strtoupper($currency), ['NGN', 'USD', 'GHS', 'ZAR', 'EGP', 'XOF', 'KES', 'RWF'])) {
        return ("<b style='color:red;margin:2px;padding:2px;border:1px dotted;display: block;border-radius: 10px;font-size: 13px;'>Sorry, this version of the Paystack WHMCS plugin only accepts NGN, USD, GHS, ZAR, EGP, XOF, KES, and RWF payments. <i>$currency</i> not yet supported.</b>");
    }

    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
    $fallbackUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/modules/gateways/callback/paystack.php?' .
        http_build_query(
            array(
                'invoiceid' => $invoiceId,
                'email' => $email,
                'phone' => $phone,
                'reference' => $txnref,
                'amountinkobo' => $amountinkobo,
                'go' => 'standard'
            )
        );
    $callbackUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
        substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
        '/modules/gateways/callback/paystack.php?' .
        http_build_query(
            array(
                'invoiceid' => $invoiceId
            )
        );

    $code = '
    <form target="hiddenIFrame" action="about:blank">
        <script src="https://js.paystack.co/v2/inline.js"></script>
        <div class="payment-btn-container2"></div>
        <script>
            // load jQuery 1.12.3 if not loaded
            (typeof $ === \'undefined\') && document.write("<scr" + "ipt type=\"text\/javascript\" ' .
        'src=\"https:\/\/code.jquery.com\/jquery-1.12.3.min.js\"><\/scr" + "ipt>");
        </script>
        <script>
            $(function() {
                var paymentMethod = $(\'select[name="gateway"]\').val();
                if (paymentMethod === \'paystack\') {
                    $(\'.payment-btn-container2\').hide();
                    var toAppend = \'<button type="button"' .
        ' onclick="payWithPaystack()"' .
        ' style="padding: 10px 25px; margin: 10px;border-radius: 5px;background: #021C32; color:#fff">' .
        addslashes($params['langpaynow']) . '</button>' .
        '<img style="width: 150px; display: block; margin: 0 auto;"' .
        ' src="https://cdn-assets-cloud.frontify.com/s3/frontify-cloud-files-us/eyJwYXRoIjoiZnJvbnRpZnlcL2FjY291bnRzXC8yYVwvMTQxNzczXC9wcm9qZWN0c1wvMTc4NjE0XC9hc3NldHNcLzdmXC8yNzQ2ODcxXC83NDY5OGViODMzMzhlMWJiNjVhMDk4MTYwNjkzY2FlOC0xNTQwMDM5NjA0LnBuZyJ9:cloud:YYqwtVK3Tb8KMGeFiXCl_w9flKcsEY9D022GMOK9oFc"/>\';

                    $(\'.payment-btn-container\').append(toAppend);
                    if($(\'.payment-btn-container\').length===0){
                        $(\'select[name="gateway"]\').after(toAppend);
                   }
                }
            });
        </script>
    </form>
    <div class="hidden" style="display:none"><iframe name="hiddenIFrame"></iframe></div>
    <script>
        var button_created = false;
        var paystackPop  = new PaystackPop()

        function payWithPaystack(){
            paystackPop.checkout({
                key: \'' . addslashes(trim($publicKey)) . '\',
                email: \'' . addslashes(trim($email)) . '\',
                phone: \'' . addslashes(trim($phone)) . '\',
                amount: ' . $amountinkobo . ',
                currency: \'' . addslashes(trim($currency)) . '\',
                ref:\'' . $txnref . '\',
                metadata:{
                    "custom_fields":[
                      {
                        "display_name":"Plugin",
                        "variable_name":"plugin",
                        "value":"whmcs"
                      }
                    ]
                  },
                onSuccess: function(response){
                    $(\'div.alert.alert-info.text-center\').hide();
                    $(\'.payment-btn-container2\').hide();
                    
                    window.location.href = \'' . addslashes($callbackUrl) . '&trxref=\' + response.trxref;
                },
                onCancel: function(){
                }
            });
            $(\'img[alt="Loading"]\').hide();
            $(\'div.alert.alert-info.text-center\').html(\'Click the button below to retry payment...\');
            create_button();
        }

       function create_button(){
        if(!button_created){
            button_created = true;
            $(\'.payment-btn-container2\').append(\'<button type="button"' .
        ' onClick="window.location.reload()"' .
        ' style="padding: 10px 25px; margin: 10px;border-radius: 5px;background: #021C32; color:#fff">' .
        addslashes($params['langpaynow']) . '</button>' .
        '<img style="width: 150px; display: block; margin: 0 auto;"' .
        ' src="https://cdn-assets-cloud.frontify.com/s3/frontify-cloud-files-us/eyJwYXRoIjoiZnJvbnRpZnlcL2FjY291bnRzXC8yYVwvMTQxNzczXC9wcm9qZWN0c1wvMTc4NjE0XC9hc3NldHNcLzdmXC8yNzQ2ODcxXC83NDY5OGViODMzMzhlMWJiNjVhMDk4MTYwNjkzY2FlOC0xNTQwMDM5NjA0LnBuZyJ9:cloud:YYqwtVK3Tb8KMGeFiXCl_w9flKcsEY9D022GMOK9oFc"/>\');
        }
       }     
       ' . ($paynowload ? 'setTimeout("payWithPaystack()", 5100);' : '') . '
    </script>';

    return $code;
}
