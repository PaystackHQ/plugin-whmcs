<?php

require_once __DIR__ . '/harness.php';

function addInvoicePayment($invoiceId, $transId, $amount, $fees, $gateway)
{
    harnessRecordCall('addInvoicePayment', [$invoiceId, $transId, $amount, $fees, $gateway]);

    $file = getenv('HARNESS_DEMO_INVOICES');
    $invoices = $file && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (is_array($invoices) && isset($invoices[$invoiceId])) {
        $invoices[$invoiceId]['status'] = 'Paid';
        file_put_contents($file, json_encode($invoices), LOCK_EX);
    }
}
