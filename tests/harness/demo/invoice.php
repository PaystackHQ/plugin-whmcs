<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';

$id = (string) (int) ($_GET['id'] ?? 0);
$invoices = json_decode((string) @file_get_contents((string) getenv('HARNESS_DEMO_INVOICES')), true);
$invoice = is_array($invoices) ? ($invoices[$id] ?? null) : null;
if ($invoice === null) {
    http_response_code(404);
    echo 'Invoice not found';
    return;
}

$paid = $invoice['status'] === 'Paid';
$button = '';
if (!$paid) {
    $params = getGatewayVariables('paystack') + [
        'clientdetails' => ['email' => 'client@example.com', 'phonenumber' => ''],
        'invoiceid' => (int) $id,
        'amount' => $invoice['amount'],
        'currency' => $invoice['currency'],
        'langpaynow' => 'Pay Now',
    ];
    $button = paystack_link($params);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice #<?php echo htmlspecialchars($id); ?></title>
<style>
body { font-family: Arial, sans-serif; font-size: 14px; margin: 40px; }
.status { font-weight: bold; color: <?php echo $paid ? '#3c763d' : '#a94442'; ?>; }
</style>
</head>
<body>
<h1>Invoice #<?php echo htmlspecialchars($id); ?></h1>
<p>Amount: <?php echo htmlspecialchars($invoice['amount'] . ' ' . $invoice['currency']); ?></p>
<p>Status: <span class="status"><?php echo $paid ? 'Paid' : 'Unpaid'; ?></span></p>
<?php if (!$paid) { ?>
<?php // paystack_link's script only adds its button next to WHMCS's gateway picker and into .payment-btn-container. ?>
<p>Payment method: <select name="gateway"><option value="paystack" selected>Paystack</option></select></p>
<div class="payment-btn-container"></div>
<?php } ?>
<?php echo $button; ?>
</body>
</html>
