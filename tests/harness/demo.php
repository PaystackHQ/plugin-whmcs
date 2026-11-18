<?php

/*
 * Browser walkthrough of the module against a fake Paystack. Test tooling only; offline, never reads real keys.
 *
 *   php tests/harness/demo.php [treePort=8710] [fakePort=8711]
 *
 * Open the printed admin.php URL, paste one of the printed fake keys into the Test Secret Key field with
 * Test Mode ticked, and save. Then open invoice.php?id=1 for the Pay button, or visit
 * /modules/gateways/callback/paystack.php?invoiceid=1&trxref=abc to simulate Paystack's redirect back.
 * Stop with Ctrl-C, or Ctrl-D / closing stdin (a clean stop that also removes the temp files). Needs an open stdin.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/run.php';

$treePort = (int) ($argv[1] ?? 8710);
$fakePort = (int) ($argv[2] ?? 8711);

register_shutdown_function(function () {
    foreach ($GLOBALS['__t_cleanup'] as $cleanup) {
        $cleanup();
    }
});
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, function () {
            exit(0);
        });
    }
}

$apiBase = harnessFakeApi($fakePort);
$dir = harnessTempDir();
$docroot = harnessBuildTree($dir);
foreach (['admin.php', 'invoice.php', 'viewinvoice.php'] as $page) {
    copy(__DIR__ . "/demo/$page", "$docroot/$page");
    chmod("$docroot/$page", 0600);
}

$pk = 'pk_test_demo';
file_put_contents("$dir/config.json", json_encode([
    'testMode' => 'on', 'gatewayLogs' => 'on', 'testSecretKey' => '', 'testPublicKey' => $pk,
    'liveSecretKey' => '', 'livePublicKey' => 'pk_live_demo', 'testWebhookSecret' => '', 'liveWebhookSecret' => '',
]));
file_put_contents("$dir/invoices.json", json_encode([
    '1' => ['amount' => '100.00', 'currency' => 'NGN', 'status' => 'Unpaid'],
    '2' => ['amount' => '250.50', 'currency' => 'GHS', 'status' => 'Unpaid'],
]));

$env = getenv();
$env['HARNESS_API_BASE'] = $apiBase;
$env['HARNESS_DEMO_CONFIG'] = "$dir/config.json";
$env['HARNESS_DEMO_INVOICES'] = "$dir/invoices.json";
$env['HARNESS_RECORD'] = "$dir/record.json";
$env['HARNESS_SETTINGS'] = "$dir/settings.json";
harnessSpawn($dir, ['-t', $docroot, __DIR__ . '/router.php'], $docroot, $env, $treePort);

$rand = bin2hex(random_bytes(12));
echo "Admin settings : http://127.0.0.1:$treePort/admin.php\n";
echo "Invoice 1      : http://127.0.0.1:$treePort/invoice.php?id=1\n";
echo "Invoice 2      : http://127.0.0.1:$treePort/invoice.php?id=2\n";
echo "Callback       : http://127.0.0.1:$treePort/modules/gateways/callback/paystack.php?invoiceid=1&trxref=abc\n";
echo "Fake Paystack  : $apiBase\n\n";
echo "Fake keys to paste into Test Secret Key (pk_test_demo is the Test Public Key; pk_live_demo the Live one):\n";
echo "  rk_test_{$rand}_ok         valid, Transactions: read only\n";
echo "  rk_test_{$rand}_write      also holds Transactions: write\n";
echo "  rk_test_{$rand}_scope403   missing Transactions: read\n";
echo "  rk_test_{$rand}_auth401    rejected as invalid\n";
echo "  rk_test_{$rand}_blocked    403 that is not a permission error (IP whitelist)\n";
echo "  pk_test_demo / pk_live_demo\n\n";
echo "Running; press Ctrl-C to stop.\n";

while (true) {
    foreach ($GLOBALS['__harness_servers'] as $srv) {
        if (!proc_get_status($srv['proc'])['running']) {
            fwrite(STDERR, "A server stopped; see the temp dir log.\n");
            exit(1);
        }
    }
    $read = [STDIN];
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) > 0 && fgets(STDIN) === false) {
        break;
    }
}
