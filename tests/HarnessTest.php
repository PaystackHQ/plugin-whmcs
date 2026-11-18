<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/harness/run.php';

const HARNESS_PATH = '/modules/gateways/callback/paystack.php';
const HARNESS_EVENT = '{"event":"charge.success","data":{"reference":"1_1"}}';
const HARNESS_GENERIC = "We couldn't confirm this payment. Please contact the merchant.";
const HARNESS_SCOPE_RAW = 'Paystack API said: This restricted key is not permitted to perform this action';

function harnessLogs(array $record, string $needle = ''): array
{
    $out = [];
    foreach ($record as $call) {
        if ($call['fn'] === 'logTransaction' && ($needle === '' || strpos($call['args'][1], $needle) !== false)) {
            $out[] = $call['args'][1];
        }
    }

    return $out;
}

function harnessCalls(array $record, string $fn): array
{
    return array_values(array_filter($record, static function ($c) use ($fn) {
        return $c['fn'] === $fn;
    }));
}

function harnessConfig(string $secretKey, array $extra = []): array
{
    static $pk = null;
    $pk = $pk ?? 'pk_test_' . bin2hex(random_bytes(8));

    return $extra + [
        'type' => 'gateway',
        'testMode' => 'on',
        'testSecretKey' => $secretKey,
        'testPublicKey' => $pk,
        'testWebhookSecret' => '',
        'gatewayLogs' => 'off',
        'convertto' => '',
    ];
}

function harnessGet(array $config, string $query, array $opts = []): array
{
    return harnessRequest('GET', HARNESS_PATH . $query, [], null, $config, $opts);
}

function harnessPost(array $config, array $headers, string $body = HARNESS_EVENT, array $opts = []): array
{
    return harnessRequest('POST', HARNESS_PATH, array_merge(['Content-Type: application/json'], $headers), $body, $config, $opts);
}

function harnessLegacyHeader(): string
{
    return 'X-Paystack-Signature: ' . str_repeat('ab', 64);
}

function harnessBadSignatureHeader(): string
{
    return 'Paystack-Signature: t=' . time() . ',v1=whk_00000000:' . str_repeat('0f', 64);
}

function harnessWhsec(): string
{
    return 'whsec_' . bin2hex(random_bytes(8));
}

t('harness: bare GET with rk and logs off logs nothing', function () {
    harnessReset();
    [, , $body, $rec] = harnessGet(harnessConfig(harnessKey('rk', '_scope403')), '?invoiceid=1');
    eq(harnessLogs($rec), []);
    eq($body, HARNESS_GENERIC);
});

t('harness: scope-denied rk on verify logs exactly one restricted-key line, logs off and on', function () {
    foreach (['off', 'on'] as $logs) {
        harnessReset();
        [, , $body, $rec] = harnessGet(harnessConfig(harnessKey('rk', '_scope403'), ['gatewayLogs' => $logs]), '?invoiceid=1&trxref=1_1');
        $lines = harnessLogs($rec);
        eq(count($lines), 1, "log lines with logs $logs");
        ok(strpos($lines[0], 'Restricted key problem on GET /transaction/verify/:ref') === 0, 'line prefix');
        ok(strpos($lines[0], 'Key type: rk') !== false, 'key type');
        ok(strpos($lines[0], 'give the key Transactions: read') !== false, 'reason');
        ok(strpos($lines[0], 'Invoice ID: 1') !== false, 'invoice');
        eq($body, HARNESS_GENERIC, "body with logs $logs");
    }
});

t('harness: 401 rk logs the key-invalid line and is throttled on the second hit with logs off', function () {
    harnessReset();
    $config = harnessConfig(harnessKey('rk', '_auth401'));
    [, , $body, $first] = harnessGet($config, '?invoiceid=1&trxref=1_1');
    [, , , $second] = harnessGet($config, '?invoiceid=1&trxref=1_1');
    $lines = harnessLogs($first);
    eq(count($lines), 1, 'first request');
    ok(strpos($lines[0], 'key invalid, revoked') !== false, 'reason');
    eq($body, HARNESS_GENERIC);
    eq(harnessLogs($second), [], 'second request');

    harnessReset();
    [, , , $logged] = harnessGet(harnessConfig(harnessKey('rk', '_auth401'), ['gatewayLogs' => 'on']), '?invoiceid=1&trxref=1_1');
    [, , , $again] = harnessGet(harnessConfig(harnessKey('rk', '_auth401'), ['gatewayLogs' => 'on']), '?invoiceid=1&trxref=1_1');
    eq(count(harnessLogs($logged)), 1);
    eq(count(harnessLogs($again)), 1, 'logs on is never throttled');
});

t('harness: forged POST with no signature header logs nothing', function () {
    harnessReset();
    [, , , $rec] = harnessPost(harnessConfig(harnessKey('rk', '_ok')), []);
    eq(harnessLogs($rec), []);
    eq(harnessCalls($rec, 'addInvoicePayment'), []);
});

t('harness: rk with a real-shaped legacy header and no whsec logs the no_secret line', function () {
    harnessReset();
    [, , , $rec] = harnessPost(harnessConfig(harnessKey('rk', '_ok')), [harnessLegacyHeader()]);
    $lines = harnessLogs($rec, 'claiming to be a Paystack webhook');
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Key type: rk') !== false, 'key type');
    ok(strpos($lines[0], 'A request claiming to be a Paystack webhook was ignored.') === 0, 'lead');
    ok(strpos($lines[0], 'Likely cause: add your Webhook Signing Secret (whsec_) for this mode') !== false, 'no_secret copy');
});

t('harness: sk store with a wrong webhook signature logs the bad_signature line', function () {
    harnessReset();
    [, , , $rec] = harnessPost(
        harnessConfig(harnessKey('sk', '_ok'), ['testWebhookSecret' => harnessWhsec()]),
        [harnessBadSignatureHeader()]
    );
    $lines = harnessLogs($rec, 'claiming to be a Paystack webhook');
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Key type: sk') !== false, 'key type');
    ok(strpos($lines[0], "signature didn't match: check the Webhook Signing Secret") !== false, 'bad_signature copy');
    eq(harnessCalls($rec, 'addInvoicePayment'), []);
});

t('harness: degraded tree (paystack/ deleted) behaves like before, no restricted-key lines, no fatal', function () {
    $opts = ['withoutRkDir' => true];
    harnessReset();
    [$status, , $body, $rec] = harnessGet(harnessConfig(harnessKey('rk', '_scope403')), '?invoiceid=1&trxref=1_1', $opts);
    ok($status !== 500, "status $status");
    ok(stripos($body, 'Fatal') === false, 'fatal in body');
    ok(strpos($body, HARNESS_SCOPE_RAW) === 0, $body);
    eq(harnessLogs($rec), []);

    [, , $body, $rec] = harnessGet(harnessConfig(harnessKey('rk', '_scope403'), ['gatewayLogs' => 'on']), '?invoiceid=1&trxref=1_1', $opts);
    ok(strpos($body, HARNESS_SCOPE_RAW) === 0, $body);
    $lines = harnessLogs($rec);
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Transaction ref: 1_1') === 0, 'old-style line');
    ok(strpos($lines[0], 'Key type: unknown') !== false, 'key type');
    ok(strpos($lines[0], 'Restricted key problem') === false, 'no new line');

    [$status, , $body, $rec] = harnessPost(harnessConfig(harnessKey('rk', '_ok')), [harnessLegacyHeader()], HARNESS_EVENT, $opts);
    ok($status !== 500, "webhook status $status");
    ok(stripos($body, 'Fatal') === false, 'fatal in webhook body');
    eq(harnessLogs($rec), []);
});

t('harness: sk config with a valid key records the payment and adds no restricted-key line', function () {
    harnessReset();
    [$status, , , $rec] = harnessGet(harnessConfig(harnessKey('sk', '_ok')), '?invoiceid=1&trxref=1_1');
    eq($status, 302);
    $payments = harnessCalls($rec, 'addInvoicePayment');
    eq(count($payments), 1);
    eq([$payments[0]['args'][0], $payments[0]['args'][1], (float) $payments[0]['args'][2], (float) $payments[0]['args'][3]], [1, '1_1', 100.0, 1.5]);
    eq(harnessLogs($rec, 'Restricted key problem'), []);
});

t('harness: rk happy path records the payment', function () {
    harnessReset();
    [$status, , , $rec] = harnessGet(harnessConfig(harnessKey('rk', '_ok')), '?invoiceid=1&trxref=1_1');
    eq($status, 302);
    eq(count(harnessCalls($rec, 'addInvoicePayment')), 1);
    eq(harnessLogs($rec), []);
});

t('harness: validator against the fake API accepts a Transactions: read only key', function () {
    define('PAYSTACK_WHMCS_TEST_API_BASE', harnessFakeApi());
    $key = harnessKey('rk', '_ok');
    $v = paystackRkValidate($key);
    eq($v['code'], 'ok', $v['probes']);
    eq($v['scopes'], []);
    eq($v['sensitive_scopes'], []);
    ok(strpos(json_encode($v), 'rk_') === false, 'key in verdict');
    ok(strpos($v['probes'], 'transactions:read:200,') === 0, $v['probes']);

    $bad = paystackRkValidate(harnessKey('rk', '_auth401'));
    eq($bad['code'], 'invalid_key');
    $denied = paystackRkValidate(harnessKey('rk', '_scope403'));
    eq($denied['code'], 'missing_scope');
    eq($denied['scopes'], ['transactions:read']);
    $write = paystackRkValidate(harnessKey('rk', '_write'));
    eq($write['code'], 'sensitive_scope');
    eq($write['sensitive_scopes'], ['transactions:write']);
});

t('harness: rk with whsec but no Paystack-Signature header logs the secret_but_no_header line', function () {
    harnessReset();
    [, , , $rec] = harnessPost(
        harnessConfig(harnessKey('rk', '_ok'), ['testWebhookSecret' => harnessWhsec()]),
        [harnessLegacyHeader()]
    );
    $lines = harnessLogs($rec, 'claiming to be a Paystack webhook');
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Key type: rk') !== false, 'key type');
    ok(strpos($lines[0], 'Paystack sent no Paystack-Signature') !== false, $lines[0]);
    eq(harnessCalls($rec, 'addInvoicePayment'), []);
});

t('harness: rk with whsec and a wrong signature logs the bad_signature line', function () {
    harnessReset();
    [, , , $rec] = harnessPost(
        harnessConfig(harnessKey('rk', '_ok'), ['testWebhookSecret' => harnessWhsec()]),
        [harnessBadSignatureHeader()]
    );
    $lines = harnessLogs($rec, 'claiming to be a Paystack webhook');
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Key type: rk') !== false, 'key type');
    ok(strpos($lines[0], "signature didn't match") !== false, $lines[0]);
    eq(harnessCalls($rec, 'addInvoicePayment'), []);
});

t('harness: rk with a correct whsec signature is accepted and logs no drop line', function () {
    harnessReset();
    $secret = harnessWhsec();
    $body = HARNESS_EVENT;
    $ts = (string) time();
    $sig = hash_hmac('sha512', $ts . '.' . $body, $secret);
    [, , , $rec] = harnessPost(
        harnessConfig(harnessKey('rk', '_ok'), ['testWebhookSecret' => $secret]),
        ["Paystack-Signature: t=$ts,v1=whk_00000000:$sig"],
        $body
    );
    eq(harnessLogs($rec, 'claiming to be a Paystack webhook'), []);
});

t('harness: sk store without whsec and a legacy-shaped bad signature logs nothing', function () {
    harnessReset();
    [, , , $rec] = harnessPost(harnessConfig(harnessKey('sk', '_ok')), [harnessLegacyHeader()]);
    eq(harnessLogs($rec), []);
    eq(harnessCalls($rec, 'addInvoicePayment'), []);
});

t('harness: webhook drop lines are throttled per reason, independently', function () {
    harnessReset();
    $noSecret = harnessConfig(harnessKey('rk', '_ok'));
    $bad = harnessConfig(harnessKey('rk', '_ok'), ['testWebhookSecret' => harnessWhsec()]);
    $badHeader = harnessBadSignatureHeader();

    [, , , $a1] = harnessPost($noSecret, [harnessLegacyHeader()]);
    [, , , $a2] = harnessPost($noSecret, [harnessLegacyHeader()]);
    [, , , $b1] = harnessPost($bad, [$badHeader]);
    [, , , $b2] = harnessPost($bad, [$badHeader]);
    eq(count(harnessLogs($a1, 'claiming to be a Paystack webhook')), 1, 'no_secret first');
    eq(harnessLogs($a2, 'claiming to be a Paystack webhook'), [], 'no_secret second');
    eq(count(harnessLogs($b1, 'claiming to be a Paystack webhook')), 1, 'bad_signature first, other reason already throttled');
    eq(harnessLogs($b2, 'claiming to be a Paystack webhook'), [], 'bad_signature second');
});

t('harness: verify auth and scope lines are throttled independently', function () {
    harnessReset();
    $auth = harnessConfig(harnessKey('rk', '_auth401'));
    $scope = harnessConfig(harnessKey('rk', '_scope403'));
    [, , , $a1] = harnessGet($auth, '?invoiceid=1&trxref=1_1');
    [, , , $s1] = harnessGet($scope, '?invoiceid=1&trxref=1_1');
    [, , , $s2] = harnessGet($scope, '?invoiceid=1&trxref=1_1');
    eq(count(harnessLogs($a1)), 1, 'auth first');
    eq(count(harnessLogs($s1)), 1, 'scope first despite auth already logged');
    eq(harnessLogs($s2), [], 'scope second');
});

t('harness: rk failing with a 500 gets the generic body and, with logs on, the plain failure line', function () {
    harnessReset();
    [, , $body, $rec] = harnessGet(harnessConfig(harnessKey('rk', '_500')), '?invoiceid=1&trxref=1_1');
    eq($body, HARNESS_GENERIC, 'body');
    eq(harnessLogs($rec), [], 'logs off');

    [, , $body, $rec] = harnessGet(harnessConfig(harnessKey('rk', '_500'), ['gatewayLogs' => 'on']), '?invoiceid=1&trxref=1_1');
    eq($body, HARNESS_GENERIC, 'body logs on');
    $lines = harnessLogs($rec);
    eq(count($lines), 1, 'lines');
    ok(strpos($lines[0], 'Transaction ref: 1_1') === 0, 'plain line');
    ok(strpos($lines[0], 'Key type: rk') !== false, 'key type');
    ok(strpos($lines[0], 'Restricted key problem') === false, 'not the rk line');
    eq(harnessCalls($rec, 'addInvoicePayment'), []);
});

t('harness: rk with a non-scope 403 logs the IP-whitelist line, throttled unless logs are on', function () {
    harnessReset();
    $config = harnessConfig(harnessKey('rk', '_blocked'));
    [, , $body, $first] = harnessGet($config, '?invoiceid=1&trxref=1_1');
    [, , , $second] = harnessGet($config, '?invoiceid=1&trxref=1_1');
    $lines = harnessLogs($first);
    eq(count($lines), 1, 'first request');
    ok(strpos($lines[0], 'Restricted key problem on GET /transaction/verify/:ref') === 0, 'line prefix');
    ok(strpos($lines[0], 'IP whitelist') !== false, $lines[0]);
    eq($body, HARNESS_GENERIC);
    eq(harnessLogs($second), [], 'second request');
    eq(harnessCalls($first, 'addInvoicePayment'), []);

    harnessReset();
    $on = harnessConfig(harnessKey('rk', '_blocked'), ['gatewayLogs' => 'on']);
    harnessGet($on, '?invoiceid=1&trxref=1_1');
    [, , , $again] = harnessGet($on, '?invoiceid=1&trxref=1_1');
    eq(count(harnessLogs($again)), 1, 'logs on is never throttled');
});

t('harness: sk failures keep the raw error body and log Key type: sk', function () {
    harnessReset();
    [, , $body, $rec] = harnessGet(harnessConfig(harnessKey('sk', '_auth401'), ['gatewayLogs' => 'on']), '?invoiceid=1&trxref=1_1');
    ok(strpos($body, 'Paystack API said: Invalid key') === 0, $body);
    $lines = harnessLogs($rec);
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Key type: sk') !== false, $lines[0]);
    ok(strpos($lines[0], 'Restricted key problem') === false, 'not the rk line');
});

t('harness: sk success logs Key type: sk when logs are on', function () {
    harnessReset();
    [, , , $rec] = harnessGet(harnessConfig(harnessKey('sk', '_ok'), ['gatewayLogs' => 'on']), '?invoiceid=1&trxref=1_1');
    $lines = harnessLogs($rec, 'Status: succeeded');
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Key type: sk') !== false, $lines[0]);
});

t('harness: a padded rk is trimmed and classified as rk', function () {
    harnessReset();
    [, , $body, $rec] = harnessGet(harnessConfig('  ' . harnessKey('rk', '_scope403') . ' '), '?invoiceid=1&trxref=1_1');
    eq($body, HARNESS_GENERIC);
    eq(count(harnessLogs($rec, 'Key type: rk')), 1);
});

t('harness: a non-sk/rk key reports Key type: other and keeps the raw error body', function () {
    harnessReset();
    [, , $body, $rec] = harnessGet(harnessConfig('xx_test_' . bin2hex(random_bytes(8)) . '_auth401', ['gatewayLogs' => 'on']), '?invoiceid=1&trxref=1_1');
    ok(strpos($body, 'Paystack API said: Invalid key') === 0, $body);
    $lines = harnessLogs($rec);
    eq(count($lines), 1);
    ok(strpos($lines[0], 'Key type: other') !== false, $lines[0]);
});

if (getenv('HARNESS_ONLINE') === '1') {
    t('harness (online): made-up rk against the real API logs one invalid-key line, logs off and on', function () {
        foreach (['off', 'on'] as $logs) {
            harnessReset();
            [, , $body, $rec] = harnessGet(harnessConfig(harnessKey('rk'), ['gatewayLogs' => $logs]), '?invoiceid=1&trxref=1_1', ['live' => true]);
            $lines = harnessLogs($rec);
            eq(count($lines), 1, "log lines with logs $logs");
            ok(strpos($lines[0], 'key invalid, revoked') !== false, 'reason');
            eq($body, HARNESS_GENERIC);
        }
    });
}
