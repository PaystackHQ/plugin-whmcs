<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/harness/run.php';

// Nothing listens here, so an rk probe fails closed without touching the network.
define('PAYSTACK_WHMCS_TEST_API_BASE', 'http://127.0.0.1:1');

require_once __DIR__ . '/../modules/gateways/paystack.php';

$rkKey = 'rk_live_' . str_repeat('a', 24);
$rkParams = ['testMode' => '', 'liveSecretKey' => $rkKey, 'livePublicKey' => 'pk_live_abc'];

t('save hook: shape error throws without leaking key material', function () {
    $params = ['testMode' => 'on', 'testSecretKey' => 'rk_test_' . str_repeat('z', 24), 'testPublicKey' => 'pk_live_abc'];
    try {
        paystack_config_validate($params);
    } catch (\Exception $e) {
        ok(strpos($e->getMessage(), 'rk_test_') === false, 'message leaks key');
        ok(strpos($e->getMessage(), 'zzzz') === false, 'message leaks key body');
        return;
    }
    throw new \RuntimeException('expected exception');
});

t('save hook: sk_ config passes', function () {
    paystack_config_validate(['testMode' => '', 'liveSecretKey' => 'sk_live_abc', 'livePublicKey' => 'pk_live_abc']);
    ok(true);
});

t('save hook: active rk is probed on every save and fails closed when Paystack is unreachable', function () use ($rkParams) {
    try {
        paystack_config_validate($rkParams);
    } catch (\Exception $e) {
        ok(strpos($e->getMessage(), "couldn't confirm this key with Paystack") !== false, $e->getMessage());
        ok(strpos($e->getMessage(), 'rk_live_') === false, 'message leaks key');
        return;
    }
    throw new \RuntimeException('expected exception');
});

t('save hook: paystack.php loads and is a no-op when support file is absent', function () {
    $dir = harnessTempDir();
    $copy = $dir . '/paystack.php';
    try {
        copy(__DIR__ . '/../modules/gateways/paystack.php', $copy);
        $code = 'define("WHMCS", true); $_SERVER["HTTP_HOST"]="x"; $_SERVER["SERVER_PORT"]=80; $_SERVER["REQUEST_URI"]="/admin/a";'
            . ' require ' . var_export($copy, true) . ';'
            . ' paystack_config_validate(["testMode"=>"","liveSecretKey"=>"rk_live_' . str_repeat('a', 24) . '","livePublicKey"=>"pk_live_abc"]);'
            . ' echo "OK";';
        $out = [];
        $status = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $status);
        eq(implode("\n", $out), 'OK');
        eq($status, 0);
    } finally {
        harnessRemoveTree($dir);
    }
});
