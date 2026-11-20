<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/harness/run.php';

// Returns [status, body].
function demoRequest(string $method, string $url, ?array $form = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = (string) curl_exec($ch);

    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
}

t('demo: admin saves a valid key, refuses a key with Transactions: write, and the invoice page renders', function () {
    $tree = harnessFreePort();
    $fake = harnessFreePort();
    $dir = harnessTempDir();
    $out = "$dir/out.log";
    $proc = proc_open([PHP_BINARY, __DIR__ . '/harness/demo.php', (string) $tree, (string) $fake], [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes);
    $GLOBALS['__t_cleanup'][] = function () use ($proc, $pipes, $dir) {
        is_resource($pipes[0]) && fclose($pipes[0]);
        is_resource($proc) && proc_close($proc);
        harnessRemoveTree($dir);
    };

    try {
        $base = "http://127.0.0.1:$tree";
        $ready = false;
        for ($i = 0; $i < 100 && !$ready; $i++) {
            $ready = @file_get_contents("$base/admin.php") !== false;
            $ready || usleep(100000);
        }
        ok($ready, 'demo did not start: ' . substr((string) @file_get_contents($out), 0, 300));

        [$status, $body] = demoRequest('GET', "$base/admin.php");
        eq($status, 200);
        ok(strpos($body, 'Payment Gateways — Paystack') !== false, 'title');
        ok(strpos($body, 'name="testSecretKey"') !== false, 'key field');

        [$status, $body] = demoRequest('GET', "$base/invoice.php?id=1");
        eq($status, 200);
        ok(strpos($body, 'payWithPaystack') !== false, 'pay button script');
        ok(strpos($body, 'Unpaid') !== false, 'status');

        $rand = bin2hex(random_bytes(12));
        $form = ['testMode' => 'on', 'testPublicKey' => 'pk_test_demo'];
        [, $body] = demoRequest('POST', "$base/admin.php", $form + ['testSecretKey' => "rk_test_{$rand}_ok"]);
        ok(strpos($body, 'Changes saved') !== false, 'save with _ok');

        [, $body] = demoRequest('POST', "$base/admin.php", $form + ['testSecretKey' => "rk_test_{$rand}_write"]);
        ok(strpos($body, 'alert-danger') !== false, 'red alert');
        ok(strpos($body, 'Transactions: write') !== false, 'names the scope');
        ok(strpos($body, 'Changes saved') === false, 'not saved');
    } finally {
        fclose($pipes[0]);
        $deadline = microtime(true) + 10;
        while (proc_get_status($proc)['running'] && microtime(true) < $deadline) {
            usleep(100000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc);
        }
    }
});
