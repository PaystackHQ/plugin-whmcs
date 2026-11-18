<?php

require_once __DIR__ . '/harness.php';

function getGatewayVariables($name)
{
    // WHMCS loads the module file itself, so the callback sees its functions and constants.
    $module = dirname(__DIR__) . '/modules/gateways/' . $name . '.php';
    if (file_exists($module)) {
        require_once $module;
    }
    // The demo saves its settings to a file; the test servers get theirs through the environment.
    $demoConfig = getenv('HARNESS_DEMO_CONFIG');
    if ($demoConfig && is_file($demoConfig)) {
        $config = json_decode((string) file_get_contents($demoConfig), true);

        return is_array($config) ? ['type' => 'gateway', 'convertto' => ''] + $config : [];
    }
    $config = json_decode((string) getenv('HARNESS_CONFIG_JSON'), true);

    return is_array($config) ? $config : [];
}

function logTransaction($gateway, $data, $result)
{
    harnessRecordCall('logTransaction', [$gateway, $data, $result]);
}

function checkCbInvoiceID($invoiceId, $gateway)
{
    harnessRecordCall('checkCbInvoiceID', [$invoiceId]);

    return (int) $invoiceId;
}

function checkCbTransID($transId)
{
    harnessRecordCall('checkCbTransID', [$transId]);
}

function convertCurrency($amount, $to, $from)
{
    return $amount;
}

function format_as_currency($amount)
{
    return number_format((float) $amount, 2, '.', '');
}
