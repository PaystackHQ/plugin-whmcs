<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/harness/run.php';

define('PAYSTACK_WHMCS_TEST_API_BASE', harnessFakeApi());

require_once __DIR__ . '/../modules/gateways/paystack.php';

// Returns the thrown exception or null.
function saveHookThrown(string $suffix): ?\Exception
{
    try {
        paystack_config_validate(['testMode' => '', 'liveSecretKey' => harnessKey('rk', $suffix, 'live'), 'livePublicKey' => 'pk_live_abc']);
    } catch (\Exception $e) {
        ok(strpos($e->getMessage(), 'rk_live_') === false, 'message leaks key');
        return $e;
    }
    return null;
}

function saveHookMessage(string $suffix): string
{
    $e = saveHookThrown($suffix);
    ok($e !== null, 'expected exception');

    return $e->getMessage();
}

t('save hook (fake API): valid Transactions: read key saves without error', function () {
    eq(saveHookThrown('_ok'), null);
});

t('save hook (fake API): 401 throws the invalid_key message', function () {
    $m = saveHookMessage('_auth401');
    ok(strpos($m, 'Paystack rejected this key') !== false, $m);
});

t('save hook (fake API): missing Transactions: read throws missing_scope message', function () {
    $m = saveHookMessage('_scope403');
    ok(strpos($m, 'missing the permission: Transactions: read') !== false, $m);
    ok(strpos($m, 'also holds permissions') === false, 'no sensitive tail');
});

t('save hook (fake API): extra permissions throw sensitive_scope message naming them', function () {
    $m = saveHookMessage('_sensitive');
    ok(strpos($m, 'can do more than the plugin needs') !== false, $m);
    ok(strpos($m, 'Disputes and refunds') !== false, $m);
    ok(strpos($m, 'Payouts') !== false, $m);
    ok(strpos($m, 'Transactions: write') === false, 'POST probe is absent on this fake');
});

t('save hook (fake API): missing read plus extra permissions reports both', function () {
    $m = saveHookMessage('_missingsens');
    ok(strpos($m, 'missing the permission: Transactions: read') !== false, $m);
    ok(strpos($m, 'so remove: ') !== false && strpos($m, 'Subaccounts') !== false, $m);
});

t('save hook (fake API): non-scope 403 throws the IP-whitelist unverifiable message', function () {
    $m = saveHookMessage('_blocked');
    ok(strpos($m, 'IP whitelist') !== false, $m);
});

t('save hook (fake API): 500 throws the transient unverifiable message', function () {
    $m = saveHookMessage('_500');
    ok(strpos($m, "couldn't confirm this key with Paystack") !== false, $m);
});

t('save hook (fake API): inactive rk slot is never probed even if bad', function () {
    paystack_config_validate([
        'testMode' => 'on',
        'testSecretKey' => 'sk_test_abc',
        'testPublicKey' => 'pk_test_abc',
        'liveSecretKey' => harnessKey('rk', '_auth401', 'live'),
        'livePublicKey' => 'pk_live_abc',
    ]);
    ok(true);
});

t('save hook: plain Exception without WHMCS class, InvalidConfiguration when present', function () {
    $thrown = saveHookThrown('_auth401');
    eq(get_class($thrown), 'Exception');
    eval('namespace WHMCS\Exception\Module { class InvalidConfiguration extends \Exception {} }');
    $thrown = saveHookThrown('_auth401');
    eq(get_class($thrown), 'WHMCS\Exception\Module\InvalidConfiguration');
});
