<?php

require_once __DIR__ . '/bootstrap.php';

const RK_TEST_DENIED = '{"status":false,"message":"This restricted key is not permitted to perform this action"}';
const RK_TEST_KEY = 'rk_test_abcdefghijklmnop1234';
const RK_TEST_PK = 'pk_test_abcdefghijklmnop1234';

// Builds a fake request: $map is scope-path prefix => [status, body]; unmatched paths return $default.
function rkFake(array $map, array $default = [200, '{}'], ?array &$calls = null): callable
{
    $calls = [];
    return function (string $method, string $url, array $headers, ?string $body, int $timeoutMs) use ($map, $default, &$calls): array {
        $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutMs];
        $path = substr($url, strlen(paystackRkApiBase()));
        foreach ($map as $prefix => $resp) {
            if (strpos($path, $prefix) === 0) {
                return ['status' => $resp[0], 'body' => $resp[1]];
            }
        }
        return ['status' => $default[0], 'body' => $default[1]];
    };
}

// A clean rk: read ok, every sensitive probe denied.
function rkCleanMap(): array
{
    $map = ['transaction?perPage' => [200, '{}']];
    foreach (paystackRkProbes() as $p) {
        if ($p['kind'] === 'sensitive') {
            $map[$p['path']] = [403, RK_TEST_DENIED];
        }
    }
    return $map;
}

function rkResult(string $scope, string $outcome, int $status = 200): array
{
    foreach (paystackRkProbes() as $p) {
        if ($p['scope'] === $scope) {
            return ['probe' => $p, 'outcome' => $outcome, 'status' => $status];
        }
    }
    throw new \RuntimeException("no probe $scope");
}

t('table: order, kinds, only POST is check_authorization with {}', function () {
    $probes = paystackRkProbes();
    eq(count($probes), 13);
    eq($probes[0]['scope'], 'transactions:read');
    eq($probes[0]['kind'], 'required');
    eq($probes[1]['scope'], 'transactions:write');
    eq($probes[1]['kind'], 'sensitive');
    $required = 0;
    $posts = [];
    foreach ($probes as $p) {
        ok(in_array($p['kind'], ['required', 'sensitive'], true));
        ok(strpos($p['path'], '/') !== 0, 'no leading slash');
        if ($p['kind'] === 'required') {
            $required++;
        }
        if ($p['method'] === 'POST') {
            $posts[] = $p;
        }
    }
    eq($required, 1);
    eq(count($posts), 1);
    eq($posts[0]['path'], 'transaction/check_authorization');
    eq($posts[0]['body'], '{}');
    eq($posts[0]['present'], '404');
});

t('messageOf: object, string, raw, empty, non-string message', function () {
    eq(paystackRkMessageOf('{"message":"hi"}'), 'hi');
    eq(paystackRkMessageOf('"just a string"'), 'just a string');
    eq(paystackRkMessageOf('plain text'), 'plain text');
    eq(paystackRkMessageOf(''), null);
    eq(paystackRkMessageOf(null), null);
    eq(paystackRkMessageOf('{"message":5}'), '{"message":5}');
    eq(paystackRkMessageOf('[1,2]'), '[1,2]');
});

t('isScopeDenied: exact only', function () {
    ok(paystackRkIsScopeDenied(403, RK_TEST_DENIED));
    ok(paystackRkIsScopeDenied(403, '"This restricted key is not permitted to perform this action"'));
    ok(paystackRkIsScopeDenied(403, 'This restricted key is not permitted to perform this action'));
    ok(!paystackRkIsScopeDenied(403, '{"message":"This restricted key is not permitted to perform this action."}'));
    ok(!paystackRkIsScopeDenied(403, ''));
    ok(!paystackRkIsScopeDenied(401, RK_TEST_DENIED));
});

t('verifyProblem: 401 auth, exact scope message scope, any other 403 blocked, else none', function () {
    eq(paystackRkVerifyProblem(401, ''), 'auth');
    eq(paystackRkVerifyProblem(401, RK_TEST_DENIED), 'auth');
    eq(paystackRkVerifyProblem(403, RK_TEST_DENIED), 'scope');
    eq(paystackRkVerifyProblem(403, ''), 'blocked');
    eq(paystackRkVerifyProblem(403, '{"message":"IP not allowed"}'), 'blocked');
    eq(paystackRkVerifyProblem(500, ''), '');
    eq(paystackRkVerifyProblem(0, ''), '');
    eq(paystackRkVerifyProblem(404, RK_TEST_DENIED), '');
});

t('classify: baseline 401 invalid', function () {
    $p = paystackRkProbes()[0];
    eq(paystackRkClassify($p, 401, '', null), 'invalid');
});

t('classify: 401 rules after baseline', function () {
    $probes = paystackRkProbes();
    eq(paystackRkClassify($probes[2], 401, '', 'present'), 'present');
    eq(paystackRkClassify($probes[2], 401, '', 'absent'), 'indeterminate');
    eq(paystackRkClassify($probes[0], 401, '', 'present'), 'indeterminate');
});

t('classify: scope 403 absent, other 403 indeterminate', function () {
    $p = paystackRkProbes()[2];
    eq(paystackRkClassify($p, 403, RK_TEST_DENIED, 'present'), 'absent');
    eq(paystackRkClassify($p, 403, '{"message":"IP not allowed"}', 'present'), 'indeterminate');
    eq(paystackRkClassify($p, 403, '', 'present'), 'indeterminate');
    eq(paystackRkClassify($p, 403, '{"message":"This restricted key is not permitted to perform this actions"}', 'present'), 'indeterminate');
    eq(paystackRkClassify($p, 403, 'This restricted key is not permitted to perform this action', 'present'), 'absent');
});

t('classify: present sets, 3xx, 404 GET, 429, 5xx, 0', function () {
    $probes = paystackRkProbes();
    $read = $probes[0];
    $write = $probes[1];
    $refund = $probes[2];
    eq(paystackRkClassify($read, 200, '', null), 'present');
    eq(paystackRkClassify($read, 400, '', null), 'indeterminate');
    eq(paystackRkClassify($write, 404, '', 'present'), 'present');
    eq(paystackRkClassify($write, 200, '', 'present'), 'indeterminate');
    eq(paystackRkClassify($refund, 200, '', 'present'), 'present');
    eq(paystackRkClassify($refund, 400, '', 'present'), 'present');
    eq(paystackRkClassify($refund, 404, '', 'present'), 'indeterminate');
    eq(paystackRkClassify($refund, 302, '', 'present'), 'indeterminate');
    eq(paystackRkClassify($refund, 429, '', 'present'), 'indeterminate');
    eq(paystackRkClassify($refund, 503, '', 'present'), 'indeterminate');
    eq(paystackRkClassify($refund, 0, '', 'present'), 'indeterminate');
    eq(paystackRkClassify($read, 0, '', null), 'indeterminate');
});

t('decide: ok', function () {
    $v = paystackRkDecide([rkResult('transactions:read', 'present'), rkResult('transactions:write', 'absent', 403)]);
    eq($v, ['ok' => true, 'code' => 'ok', 'scopes' => [], 'sensitive_scopes' => [], 'reason' => null]);
});

t('decide: empty list is unverifiable/transient', function () {
    $v = paystackRkDecide([]);
    eq($v['code'], 'unverifiable');
    eq($v['reason'], 'transient');
});

t('decide: invalid_key beats everything', function () {
    $v = paystackRkDecide([rkResult('transactions:read', 'invalid', 401), rkResult('settlements', 'present')]);
    eq($v['code'], 'invalid_key');
    eq($v['ok'], false);
});

t('decide: missing_scope carries held sensitive scopes', function () {
    $v = paystackRkDecide([
        rkResult('transactions:read', 'absent', 403),
        rkResult('transactions:write', 'present', 404),
        rkResult('payouts', 'present'),
    ]);
    eq($v['code'], 'missing_scope');
    eq($v['scopes'], ['transactions:read']);
    eq($v['sensitive_scopes'], ['transactions:write', 'payouts']);
});

t('decide: sensitive_scope lists in both fields, table order', function () {
    $v = paystackRkDecide([
        rkResult('transactions:read', 'present'),
        rkResult('transactions:write', 'present', 404),
        rkResult('payouts', 'present'),
    ]);
    eq($v['code'], 'sensitive_scope');
    eq($v['scopes'], ['transactions:write', 'payouts']);
    eq($v['sensitive_scopes'], ['transactions:write', 'payouts']);
});

t('decide: only last sensitive present', function () {
    $r = [rkResult('transactions:read', 'present')];
    foreach (paystackRkProbes() as $p) {
        if ($p['kind'] === 'sensitive') {
            $r[] = rkResult($p['scope'], $p['scope'] === 'payouts' ? 'present' : 'absent');
        }
    }
    $v = paystackRkDecide($r);
    eq($v['code'], 'sensitive_scope');
    eq($v['scopes'], ['payouts']);
});

t('decide: sensitive beats indeterminate; unverifiable reasons', function () {
    $v = paystackRkDecide([rkResult('transactions:read', 'present'), rkResult('settlements', 'present'), rkResult('payouts', 'indeterminate', 500)]);
    eq($v['code'], 'sensitive_scope');
    $v = paystackRkDecide([rkResult('transactions:read', 'present'), rkResult('payouts', 'indeterminate', 500)]);
    eq([$v['code'], $v['reason']], ['unverifiable', 'transient']);
    $v = paystackRkDecide([rkResult('transactions:read', 'present'), rkResult('payouts', 'indeterminate', 403)]);
    eq([$v['code'], $v['reason']], ['unverifiable', 'blocked']);
    $v = paystackRkDecide([rkResult('transactions:read', 'present'), rkResult('payouts', 'indeterminate', 0)]);
    eq($v['reason'], 'transient');
});

t('validate: clean rk is ok, sequential, baseline first, no key in output', function () {
    $v = paystackRkValidate("  " . RK_TEST_KEY . "\n", rkFake(rkCleanMap(), [403, RK_TEST_DENIED], $calls));
    eq($v['code'], 'ok');
    eq(count($calls), 13);
    eq($calls[0]['url'], 'https://api.paystack.co/transaction?perPage=1');
    eq($calls[0]['headers'], ['Authorization: Bearer ' . RK_TEST_KEY]);
    eq($calls[0]['timeout'], 4000);
    eq($calls[1]['method'], 'POST');
    eq($calls[1]['body'], '{}');
    ok(in_array('Content-Type: application/json', $calls[1]['headers'], true));
    ok(strpos($v['probes'], 'transactions:read:200,transactions:write:403,') === 0, $v['probes']);
    ok(strpos(json_encode($v), 'rk_') === false);
});

t('validate: write probe 404 -> sensitive_scope', function () {
    $map = rkCleanMap();
    $map['transaction/check_authorization'] = [404, '{}'];
    $v = paystackRkValidate(RK_TEST_KEY, rkFake($map, [403, RK_TEST_DENIED]));
    eq($v['code'], 'sensitive_scope');
    eq($v['scopes'], ['transactions:write']);
});

t('validate: baseline 401 -> invalid_key and stops', function () {
    $v = paystackRkValidate(RK_TEST_KEY, rkFake([], [401, '{"message":"Invalid key"}'], $calls));
    eq($v['code'], 'invalid_key');
    eq(count($calls), 1);
});

t('validate: absent baseline still probes the rest', function () {
    $map = rkCleanMap();
    $map['transaction?perPage'] = [403, RK_TEST_DENIED];
    $map['settlement?perPage=1'] = [200, '{}'];
    $v = paystackRkValidate(RK_TEST_KEY, rkFake($map, [403, RK_TEST_DENIED], $calls));
    eq($v['code'], 'missing_scope');
    eq($v['scopes'], ['transactions:read']);
    eq($v['sensitive_scopes'], ['settlements']);
    eq(count($calls), 13);
});

t('validate: indeterminate baseline stops', function () {
    foreach ([[500, ''], [302, ''], [0, ''], [403, ''], [429, '']] as $resp) {
        $v = paystackRkValidate(RK_TEST_KEY, rkFake([], $resp, $calls));
        eq($v['code'], 'unverifiable');
        eq(count($calls), 1);
    }
    $v = paystackRkValidate(RK_TEST_KEY, rkFake([], [403, '{"message":"IP"}']));
    eq($v['reason'], 'blocked');
});

t('validate: 401 on sensitive after 2xx baseline counts as present', function () {
    $map = rkCleanMap();
    $map['payout'] = [401, ''];
    $map['settlement/instant/profile'] = [401, ''];
    $v = paystackRkValidate(RK_TEST_KEY, rkFake($map, [403, RK_TEST_DENIED]));
    eq($v['code'], 'sensitive_scope');
    eq($v['scopes'], ['payouts']);
});

t('validate: 401 on sensitive after absent baseline is indeterminate', function () {
    $map = rkCleanMap();
    $map['transaction?perPage'] = [403, RK_TEST_DENIED];
    $map['settlement/instant/profile'] = [401, ''];
    $v = paystackRkValidate(RK_TEST_KEY, rkFake($map, [403, RK_TEST_DENIED]));
    eq($v['code'], 'missing_scope');
    eq($v['sensitive_scopes'], []);
});

t('validate: request throwing -> unverifiable/transient, no leak', function () {
    $v = paystackRkValidate(RK_TEST_KEY, function () {
        throw new \RuntimeException('boom ' . RK_TEST_KEY);
    });
    eq([$v['code'], $v['reason']], ['unverifiable', 'transient']);
    ok(strpos(json_encode($v), 'rk_') === false);
});

t('validate: now() throwing -> unverifiable/transient', function () {
    $v = paystackRkValidate(RK_TEST_KEY, rkFake([]), function () {
        throw new \RuntimeException('clock');
    });
    eq([$v['code'], $v['reason']], ['unverifiable', 'transient']);
    eq($v['probes'], '-');
});

t('validate: deadline skips remaining probes as status 0', function () {
    $clock = 1000.0;
    $now = function () use (&$clock) {
        return $clock;
    };
    $count = 0;
    $request = function () use (&$clock, &$count) {
        $count++;
        $clock += 6.0;
        return ['status' => $count === 1 ? 200 : 403, 'body' => $count === 1 ? '{}' : RK_TEST_DENIED];
    };
    $v = paystackRkValidate(RK_TEST_KEY, $request, $now);
    eq($count, 3, 'probes run before the 15 s deadline');
    eq([$v['code'], $v['reason']], ['unverifiable', 'transient']);
    ok(strpos($v['probes'], 'payouts:0') !== false, $v['probes']);
});

t('validate: probe near the deadline gets the clipped timeout, none after it', function () {
    $clock = 1000.0;
    $now = function () use (&$clock) {
        return $clock;
    };
    $timeouts = [];
    $request = function ($m, $u, $h, $b, $timeoutMs) use (&$clock, &$timeouts) {
        $timeouts[] = $timeoutMs;
        $clock += count($timeouts) <= 4 ? 3.5 : 0.5;
        return ['status' => count($timeouts) === 1 ? 200 : 403, 'body' => count($timeouts) === 1 ? '{}' : RK_TEST_DENIED];
    };
    $v = paystackRkValidate(RK_TEST_KEY, $request, $now);
    eq(array_slice($timeouts, 0, 4), [4000, 4000, 4000, 4000]);
    eq($timeouts[4], 1000, 'started with 1 s left');
    eq($timeouts[5], 500, 'started with 0.5 s left');
    eq(count($timeouts), 6);
    ok(strpos($v['probes'], 'payouts:0') !== false, $v['probes']);
});

t('validate: under 1 ms left means the probe is not sent', function () {
    $calls = 0;
    $times = [0.0, 14.9995];
    $now = function () use (&$times) {
        return count($times) > 1 ? array_shift($times) : $times[0];
    };
    $v = paystackRkValidate(RK_TEST_KEY, function () use (&$calls) {
        $calls++;
        return ['status' => 200, 'body' => '{}'];
    }, $now);
    eq($calls, 0);
    eq($v['probes'], 'transactions:read:0');
});

t('apiBase: default and test override are slash-normalised', function () {
    eq(paystackRkApiBase(), 'https://api.paystack.co/');
    define('PAYSTACK_WHMCS_TEST_API_BASE', 'http://127.0.0.1:9//');
    eq(paystackRkApiBase(), 'http://127.0.0.1:9/');
});

t('keyType: prefixes, trim, never a slice of the key', function () {
    eq(paystackRkKeyType(' rk_live_abc '), 'rk');
    eq(paystackRkKeyType('rk_test_abc'), 'rk');
    eq(paystackRkKeyType("\nsk_live_abc"), 'sk');
    eq(paystackRkKeyType('sk_test_abc'), 'sk');
    eq(paystackRkKeyType('rk_prod_abc'), 'other');
    eq(paystackRkKeyType('pk_test_abc'), 'other');
    eq(paystackRkKeyType(''), 'other');
    eq(paystackRkKeyType(null), 'other');
    eq(paystackRkKeyType(['rk_test_x']), 'other');
    ok(in_array(paystackRkKeyType(RK_TEST_KEY), ['rk', 'sk', 'other'], true));
});

t('shapeError: matrix', function () {
    $rk = RK_TEST_KEY;
    $pk = RK_TEST_PK;
    eq(paystackRkShapeError('test', 'sk_test_whatever', ''), null, 'sk untouched');
    eq(paystackRkShapeError('test', '', ''), null);
    eq(paystackRkShapeError('test', $rk, $pk), null);
    eq(paystackRkShapeError('test', " $rk\n", " $pk "), null, 'trimmed');
    eq(paystackRkShapeError('live', 'rk_live_abcdefghijklmnop1234', 'pk_live_x'), null);
    ok(paystackRkShapeError('test', 'rk_test_short', $pk) !== null, 'too short');
    ok(paystackRkShapeError('test', 'rk_test_' . str_repeat('a', 257), $pk) !== null, 'too long');
    eq(paystackRkShapeError('test', 'rk_test_' . str_repeat('a', 256), $pk), null);
    ok(paystackRkShapeError('test', "rk_test_abcdefgh\nijklmnopqrst", $pk) !== null, 'inner newline');
    ok(paystackRkShapeError('test', 'rk_test_abcdefgh ijklmnopqrst', $pk) !== null, 'inner space');
    ok(paystackRkShapeError('test', 'rk_test_abcdefghijklmnop12é4', $pk) !== null, 'non-ascii');
    ok(paystackRkShapeError('live', $rk, $pk) !== null, 'mode mismatch test key in live slot');
    ok(paystackRkShapeError('test', 'rk_live_abcdefghijklmnop1234', $pk) !== null, 'mode mismatch live key in test slot');
    ok(paystackRkShapeError('test', $rk, 'pk_live_abcdefghijklmnop1234') !== null, 'pk wrong mode');
    ok(paystackRkShapeError('test', $rk, '') !== null, 'pk missing');
    ok(paystackRkShapeError('test', $rk, null) !== null, 'pk null');
    ok(paystackRkShapeError('test', $rk, 'sk_test_abcdefghijklmnop1234') !== null, 'pk not pk');
});

t('shapeError: messages never include the key', function () {
    foreach ([['test', 'rk_test_short', RK_TEST_PK], ['live', RK_TEST_KEY, RK_TEST_PK], ['test', RK_TEST_KEY, 'bad']] as [$mode, $k, $pk]) {
        $m = paystackRkShapeError($mode, $k, $pk);
        ok($m !== null && strpos($m, $k) === false);
        ok(!preg_match('/(rk|sk)_(test|live)_[A-Za-z0-9]/', $m));
    }
});

t('message: ok is empty, every code names its fix', function () {
    eq(paystackRkMessage(['code' => 'ok']), '');
    $m = paystackRkMessage(['code' => 'invalid_key']);
    ok(strpos($m, 'invalid, revoked, or restricted keys not yet enabled for this integration') !== false);

    $m = paystackRkMessage(['code' => 'missing_scope', 'scopes' => ['transactions:read'], 'sensitive_scopes' => ['payouts', 'transactions:write']]);
    ok(strpos($m, 'Transactions: read') !== false);
    ok(strpos($m, 'Payouts') !== false && strpos($m, 'Transactions: write') !== false);
    $m = paystackRkMessage(['code' => 'missing_scope', 'scopes' => ['transactions:read'], 'sensitive_scopes' => []]);
    ok(strpos($m, 'remove') === false);

    $m = paystackRkMessage(['code' => 'sensitive_scope', 'scopes' => ['transactions:write', 'payouts'], 'sensitive_scopes' => ['transactions:write', 'payouts']]);
    ok(strpos($m, 'Transactions: write, Payouts') !== false);
    ok(strpos($m, 'only needs Transactions: read') !== false);

    $blocked = paystackRkMessage(['code' => 'unverifiable', 'reason' => 'blocked']);
    ok(stripos($blocked, 'IP') !== false && strpos($blocked, 'sk_ secret key') !== false);
    $transient = paystackRkMessage(['code' => 'unverifiable', 'reason' => 'transient']);
    ok(strpos($transient, "couldn't confirm this key with Paystack") !== false && strpos($transient, 'sk_ secret key') !== false);
});

t('message: never contains key material patterns', function () {
    $verdicts = [
        ['code' => 'invalid_key'],
        ['code' => 'missing_scope', 'scopes' => ['transactions:read'], 'sensitive_scopes' => ['payouts']],
        ['code' => 'sensitive_scope', 'scopes' => ['payouts'], 'sensitive_scopes' => ['payouts']],
        ['code' => 'unverifiable', 'reason' => 'blocked'],
        ['code' => 'unverifiable', 'reason' => 'transient'],
    ];
    foreach ($verdicts as $v) {
        $m = paystackRkMessage($v);
        ok(!preg_match('/(rk|sk)_[A-Za-z0-9]/', $m), $m);
    }
});

function rkCfg(array $o = []): array
{
    return $o + [
        'testMode' => 'on',
        'testSecretKey' => RK_TEST_KEY, 'testPublicKey' => RK_TEST_PK,
        'liveSecretKey' => 'sk_live_abcdefghijklmnop1234', 'livePublicKey' => 'pk_live_abcdefghijklmnop1234',
    ];
}

function rkValidator(array $verdict, ?int &$n = null): callable
{
    $n = 0;
    return function ($key) use ($verdict, &$n) {
        $n++;
        return $verdict;
    };
}

t('checkConfig: active rk is always probed, whatever was stored', function () {
    $ok = ['ok' => true, 'code' => 'ok'];
    eq(paystackRkCheckConfig(rkCfg(), rkValidator($ok, $n)), null);
    eq($n, 1);

    $live = rkCfg(['testMode' => 'off', 'liveSecretKey' => 'rk_live_abcdefghijklmnop1234']);
    paystackRkCheckConfig($live, rkValidator($ok, $n));
    eq($n, 1, 'live slot');

    $msg = paystackRkCheckConfig(rkCfg(), rkValidator(['ok' => false, 'code' => 'invalid_key'], $n));
    eq($msg, paystackRkMessage(['code' => 'invalid_key']));
    eq($n, 1, 'unchanged-looking config still probed');
});

t('checkConfig: probed key is trimmed and passed to validator', function () {
    $seen = null;
    paystackRkCheckConfig(rkCfg(['testSecretKey' => ' ' . RK_TEST_KEY . "\n"]), function ($k) use (&$seen) {
        $seen = $k;
        return ['ok' => true, 'code' => 'ok'];
    });
    eq($seen, RK_TEST_KEY);
});

t('checkConfig: inactive slot gets shape only, never probed', function () {
    $v = rkValidator(['ok' => false, 'code' => 'invalid_key'], $n);
    $cfg = rkCfg(['testMode' => 'off', 'testSecretKey' => RK_TEST_KEY, 'liveSecretKey' => 'sk_live_abcdefghijklmnop1234']);
    eq(paystackRkCheckConfig($cfg, $v), null);
    eq($n, 0);

    $bad = rkCfg(['testMode' => 'off', 'testSecretKey' => 'rk_test_short']);
    $msg = paystackRkCheckConfig($bad, $v);
    ok($msg !== null && strpos($msg, 'Test') === 0, (string) $msg);
    eq($n, 0);

    $badLive = rkCfg(['liveSecretKey' => 'rk_live_short']);
    $msg = paystackRkCheckConfig($badLive, $v);
    ok($msg !== null && strpos($msg, 'Live') === 0, (string) $msg);
    eq($n, 0);

    $wrongMode = rkCfg(['liveSecretKey' => RK_TEST_KEY]);
    ok(paystackRkCheckConfig($wrongMode, $v) !== null);
});

t('checkConfig: sk keys untouched', function () {
    $cfg = rkCfg(['testSecretKey' => 'sk_test_abcdefghijklmnop1234', 'testPublicKey' => '']);
    eq(paystackRkCheckConfig($cfg, rkValidator(['ok' => false, 'code' => 'invalid_key'], $n)), null);
    eq($n, 0);
    eq(paystackRkCheckConfig(rkCfg(['testSecretKey' => '', 'testPublicKey' => '']), rkValidator(['ok' => false, 'code' => 'invalid_key'], $n)), null);
    eq($n, 0);
});

t('checkConfig: every verdict maps to its message', function () {
    $verdicts = [
        ['ok' => false, 'code' => 'invalid_key'],
        ['ok' => false, 'code' => 'missing_scope', 'scopes' => ['transactions:read'], 'sensitive_scopes' => []],
        ['ok' => false, 'code' => 'sensitive_scope', 'scopes' => ['payouts'], 'sensitive_scopes' => ['payouts']],
        ['ok' => false, 'code' => 'unverifiable', 'reason' => 'blocked'],
        ['ok' => false, 'code' => 'unverifiable', 'reason' => 'transient'],
    ];
    foreach ($verdicts as $v) {
        $msg = paystackRkCheckConfig(rkCfg(), rkValidator($v));
        ok($msg !== null && $msg !== '');
        eq($msg, paystackRkMessage($v));
    }
    eq(paystackRkCheckConfig(rkCfg(), rkValidator(['ok' => true, 'code' => 'ok'])), null);
});

t('looksLikePaystackDelivery', function () {
    ok(paystackRkLooksLikePaystackDelivery(['HTTP_X_PAYSTACK_SIGNATURE' => str_repeat('a0', 64)]));
    ok(!paystackRkLooksLikePaystackDelivery(['HTTP_X_PAYSTACK_SIGNATURE' => str_repeat('A0', 64)]));
    ok(!paystackRkLooksLikePaystackDelivery(['HTTP_X_PAYSTACK_SIGNATURE' => str_repeat('a', 127)]));
    ok(!paystackRkLooksLikePaystackDelivery(['HTTP_X_PAYSTACK_SIGNATURE' => str_repeat('a', 128) . "\n"]));
    ok(paystackRkLooksLikePaystackDelivery(['HTTP_PAYSTACK_SIGNATURE' => 't=1700000000,v1=abc']));
    ok(!paystackRkLooksLikePaystackDelivery(['HTTP_PAYSTACK_SIGNATURE' => 't=x,v1=abc']));
    ok(!paystackRkLooksLikePaystackDelivery(['HTTP_PAYSTACK_SIGNATURE' => 'junk']));
    ok(!paystackRkLooksLikePaystackDelivery([]));
});

t('webhookDropReason', function () {
    eq(paystackRkWebhookDropReason('', [], 'rk'), 'no_secret');
    eq(paystackRkWebhookDropReason('', ['HTTP_PAYSTACK_SIGNATURE' => 't=1,v1=a'], 'rk'), 'no_secret');
    eq(paystackRkWebhookDropReason('whsec_x', [], 'rk'), 'secret_but_no_header');
    eq(paystackRkWebhookDropReason('whsec_x', ['HTTP_X_PAYSTACK_SIGNATURE' => 'a'], 'rk'), 'secret_but_no_header');
    eq(paystackRkWebhookDropReason('whsec_x', ['HTTP_PAYSTACK_SIGNATURE' => 't=1,v1=a'], 'rk'), 'bad_signature');
    eq(paystackRkWebhookDropReason('', [], 'sk'), 'no_secret');
    eq(paystackRkWebhookDropReason('whsec_x', [], 'sk'), 'bad_signature');
    eq(paystackRkWebhookDropReason('whsec_x', ['HTTP_X_PAYSTACK_SIGNATURE' => 'a'], 'sk'), 'bad_signature');
    eq(paystackRkWebhookDropReason('whsec_x', [], 'other'), 'secret_but_no_header');
});

t('log copy: webhook drop and verify problem messages', function () {
    ok(strpos(paystackRkWebhookDropMessage('no_secret'), 'whsec_') !== false);
    ok(strpos(paystackRkWebhookDropMessage('secret_but_no_header'), 'Paystack-Signature') !== false);
    ok(strpos(paystackRkWebhookDropMessage('bad_signature'), "didn't match") !== false);
    eq(paystackRkWebhookDropMessage('other'), 'other');
    ok(strpos(paystackRkVerifyProblemMessage('scope'), 'Transactions: read') !== false);
    ok(strpos(paystackRkVerifyProblemMessage('auth'), 'invalid, revoked') !== false);
    ok(strpos(paystackRkVerifyProblemMessage('blocked'), 'IP whitelist') !== false);
});

t('support file: version sentinel is defined', function () {
    ok(defined('PAYSTACK_RK_VERSION'));
});

t('apiBase: a non-loopback override is ignored', function () {
    foreach (['https://evil.example/', 'http://127.0.0.1.evil.example/', 'http://localhost:9/', 'http://127.0.0.1:80@evil.example/'] as $base) {
        $code = 'define("WHMCS", true); define("PAYSTACK_WHMCS_TEST_API_BASE", ' . var_export($base, true) . ');'
            . 'require ' . var_export(__DIR__ . '/../modules/gateways/paystack/RestrictedKey.php', true) . '; echo paystackRkApiBase();';
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $out);
        eq(implode('', $out), 'https://api.paystack.co/', $base);
    }
});

t('throttle: never logs when WHMCS Setting class is absent', function () {
    ok(!class_exists('\WHMCS\Config\Setting', false), 'Setting must not be defined in this file');
    eq(paystackRkThrottle('no_secret', 100), false);
    eq(paystackRkThrottle('no_secret', 101), false);
});
