<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

const PAYSTACK_RK_VERSION = 1;
const PAYSTACK_RK_SCOPE_DENIED_MESSAGE = 'This restricted key is not permitted to perform this action';
const PAYSTACK_RK_PROBE_TIMEOUT_MS = 4000;
const PAYSTACK_RK_DEADLINE = 15;
const PAYSTACK_RK_THROTTLE_SECONDS = 3600;

/**
 * Keep in sync with the restricted-key probe tables in Paystack's other plugins; a drifted route makes saves fail closed.
 * Deliberate divergence: transactions:write is sensitive here, not required, because WHMCS never initializes
 * transactions and that scope also allows charging saved cards. There is no account:read/ident probe either.
 * Table order matters: offending scopes are reported in it.
 * Never add a write-capable route (POST settlement/set_schedule, any connect/* POST).
 */
function paystackRkProbes(): array
{
    $sensitive = static function (string $scope, string $path): array {
        return ['scope' => $scope, 'kind' => 'sensitive', 'method' => 'GET', 'path' => $path, 'body' => null, 'present' => '2xx_400'];
    };

    return [
        ['scope' => 'transactions:read', 'kind' => 'required', 'method' => 'GET', 'path' => 'transaction?perPage=1', 'body' => null, 'present' => '2xx'],
        ['scope' => 'transactions:write', 'kind' => 'sensitive', 'method' => 'POST', 'path' => 'transaction/check_authorization', 'body' => '{}', 'present' => '404'],
        $sensitive('disputes_refunds', 'refund?perPage=1'),
        $sensitive('bulk_charges', 'bulkcharge'),
        $sensitive('direct_debit', 'directdebit/account'),
        $sensitive('preauthorizations', 'preauthorization'),
        $sensitive('settlements', 'settlement?perPage=1'),
        $sensitive('subaccounts', 'subaccount'),
        $sensitive('transfers', 'transfer/config'),
        $sensitive('virtual_accounts', 'pay-with-transfer/custom-accounts/status'),
        $sensitive('webhooks', 'integration/webhooks/events'),
        $sensitive('connect', 'connect/platforms'),
        $sensitive('payouts', 'settlement/instant/profile'),
    ];
}

function paystackRkScopeLabel(string $scope): string
{
    static $labels = [
        'transactions:read' => 'Transactions: read',
        'transactions:write' => 'Transactions: write',
        'disputes_refunds' => 'Disputes and refunds',
        'bulk_charges' => 'Bulk charges',
        'direct_debit' => 'Direct debit',
        'preauthorizations' => 'Preauthorizations',
        'settlements' => 'Settlements',
        'subaccounts' => 'Subaccounts',
        'transfers' => 'Transfers',
        'virtual_accounts' => 'Virtual accounts',
        'webhooks' => 'Webhooks',
        'connect' => 'Connect',
        'payouts' => 'Payouts',
    ];

    return $labels[$scope] ?? $scope;
}

function paystackRkMessageOf($rawBody): ?string
{
    if (!is_string($rawBody)) {
        return null;
    }
    $decoded = json_decode($rawBody);
    if (is_object($decoded) && isset($decoded->message) && is_string($decoded->message)) {
        return $decoded->message;
    }
    if (is_string($decoded)) {
        return $decoded;
    }

    return $rawBody !== '' ? $rawBody : null;
}

function paystackRkIsScopeDenied(int $status, $rawBody): bool
{
    return $status === 403 && paystackRkMessageOf($rawBody) === PAYSTACK_RK_SCOPE_DENIED_MESSAGE;
}

function paystackRkIsPresentStatus(array $probe, int $status): bool
{
    switch ($probe['present']) {
        case '2xx':
            return $status >= 200 && $status <= 299;
        case '2xx_400':
            return ($status >= 200 && $status <= 299) || $status === 400;
        case '404':
            return $status === 404;
    }

    return false;
}

// Any 403 that isn't the exact scope message may be another gate (IP, feature), so it fails closed.
function paystackRkClassify(array $probe, int $status, $rawBody, ?string $baselineOutcome): string
{
    if ($status === 401) {
        if ($baselineOutcome === null) {
            return 'invalid';
        }
        // Only a 2xx baseline proves the key is valid, so a 401 elsewhere is not proof of a scope.
        return ($baselineOutcome === 'present' && $probe['kind'] === 'sensitive') ? 'present' : 'indeterminate';
    }
    if (paystackRkIsScopeDenied($status, $rawBody)) {
        return 'absent';
    }

    return paystackRkIsPresentStatus($probe, $status) ? 'present' : 'indeterminate';
}

function paystackRkDecide(array $results): array
{
    $verdict = static function (string $code, array $scopes = [], array $sensitive = [], ?string $reason = null): array {
        return ['ok' => $code === 'ok', 'code' => $code, 'scopes' => $scopes, 'sensitive_scopes' => $sensitive, 'reason' => $reason];
    };

    if (!$results) {
        return $verdict('unverifiable', [], [], 'transient');
    }
    if ($results[0]['outcome'] === 'invalid') {
        return $verdict('invalid_key');
    }

    $missing = [];
    $sensitive = [];
    $indeterminate = false;
    $blocked = false;
    foreach ($results as $r) {
        if ($r['probe']['kind'] === 'required' && $r['outcome'] === 'absent') {
            $missing[] = $r['probe']['scope'];
        } elseif ($r['probe']['kind'] === 'sensitive' && $r['outcome'] === 'present') {
            $sensitive[] = $r['probe']['scope'];
        } elseif ($r['outcome'] === 'indeterminate') {
            $indeterminate = true;
            $blocked = $blocked || $r['status'] === 403;
        }
    }
    if ($missing) {
        return $verdict('missing_scope', $missing, $sensitive);
    }
    if ($sensitive) {
        return $verdict('sensitive_scope', $sensitive, $sensitive);
    }
    if ($indeterminate) {
        return $verdict('unverifiable', [], [], $blocked ? 'blocked' : 'transient');
    }

    return $verdict('ok');
}

// Never keeps the curl handle's request details: they carry the Authorization header.
function paystackRkCurlRequest(
    string $method,
    string $url,
    #[\SensitiveParameter]
    array $headers,
    ?string $body,
    int $timeoutMs
): array {
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    if ($response === false || curl_errno($ch) !== 0) {
        return ['status' => 0, 'body' => ''];
    }

    return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string) $response];
}

// The override is test-only: honoured on the CLI and the built-in server, and only for a loopback URL.
function paystackRkApiBase(): string
{
    if (defined('PAYSTACK_WHMCS_TEST_API_BASE')
        && in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        && preg_match('~^http://127\.0\.0\.1:\d{1,5}(/|\z)~', (string) PAYSTACK_WHMCS_TEST_API_BASE) === 1
    ) {
        return rtrim((string) PAYSTACK_WHMCS_TEST_API_BASE, '/') . '/';
    }

    return 'https://api.paystack.co/';
}

function paystackRkValidate(
    #[\SensitiveParameter]
    $key,
    ?callable $request = null,
    ?callable $now = null
): array {
    try {
        $key = trim((string) $key);
        $request = $request ?? 'paystackRkCurlRequest';
        $now = $now ?? static function (): float {
            return microtime(true);
        };
        $deadline = $now() + PAYSTACK_RK_DEADLINE;

        $run = static function (array $probe, ?string $baselineOutcome) use ($key, $request, $now, $deadline): array {
            $status = 0;
            $body = '';
            $remainingMs = (int) floor(($deadline - $now()) * 1000);
            if ($remainingMs >= 1) {
                $headers = ['Authorization: Bearer ' . $key];
                if ($probe['method'] === 'POST') {
                    $headers[] = 'Content-Type: application/json';
                }
                try {
                    $response = $request(
                        $probe['method'],
                        paystackRkApiBase() . $probe['path'],
                        $headers,
                        $probe['body'],
                        min(PAYSTACK_RK_PROBE_TIMEOUT_MS, $remainingMs)
                    );
                    if (is_array($response)) {
                        $status = (int) ($response['status'] ?? 0);
                        $body = (string) ($response['body'] ?? '');
                    }
                } catch (\Throwable $e) {
                }
            }

            return [
                'probe' => $probe,
                'outcome' => paystackRkClassify($probe, $status, $body, $baselineOutcome),
                'status' => $status,
            ];
        };

        $probes = paystackRkProbes();
        $baselineProbe = array_shift($probes);
        $baseline = $run($baselineProbe, null);
        $results = [$baseline];
        // A scope refusal still proves the key is valid, so the other probes can be checked.
        if ($baseline['outcome'] === 'present' || $baseline['outcome'] === 'absent') {
            foreach ($probes as $probe) {
                $results[] = $run($probe, $baseline['outcome']);
            }
        }

        $verdict = paystackRkDecide($results);
        $parts = [];
        foreach ($results as $r) {
            $parts[] = $r['probe']['scope'] . ':' . $r['status'];
        }
        $verdict['probes'] = implode(',', $parts);

        return $verdict;
    } catch (\Throwable $e) {
        return [
            'ok' => false, 'code' => 'unverifiable', 'scopes' => [], 'sensitive_scopes' => [],
            'reason' => 'transient', 'probes' => '-',
        ];
    }
}

function paystackRkKeyType($key): string
{
    $key = is_string($key) ? trim($key) : '';
    if (preg_match('/^rk_(test|live)_/', $key)) {
        return 'rk';
    }
    if (preg_match('/^sk_(test|live)_/', $key)) {
        return 'sk';
    }

    return 'other';
}

function paystackRkShapeError(
    string $slotMode,
    #[\SensitiveParameter]
    $key,
    $pk
): ?string {
    $key = is_string($key) ? trim($key) : '';
    if (paystackRkKeyType($key) !== 'rk') {
        return null;
    }
    if (!preg_match('/^rk_(test|live)_[\x21-\x7e]{16,256}\z/', $key, $m)) {
        return 'is not a valid restricted key. Copy the whole key from the Paystack Dashboard.';
    }
    if ($m[1] !== $slotMode) {
        return 'is a ' . $m[1] . ' restricted key but is in the ' . $slotMode . ' field. Use a ' . $slotMode . ' key here.';
    }
    $pk = is_string($pk) ? trim($pk) : '';
    if (strpos($pk, 'pk_' . $slotMode . '_') !== 0) {
        return 'needs the matching ' . $slotMode . ' public key (pk_' . $slotMode . '_...) from the same integration.';
    }

    return null;
}

function paystackRkMessage(array $verdict): string
{
    $labels = static function (array $scopes): string {
        return implode(', ', array_map('paystackRkScopeLabel', $scopes));
    };

    switch ($verdict['code'] ?? '') {
        case 'ok':
            return '';
        case 'invalid_key':
            return 'Paystack rejected this key: it is invalid, revoked, or restricted keys not yet enabled for this integration. '
                . 'Check the key in the Paystack Dashboard, or paste the sk_ secret key instead.';
        case 'missing_scope':
            $msg = 'This restricted key is missing the permission: ' . $labels($verdict['scopes']) . '. Turn it on in the Paystack Dashboard.';
            if (!empty($verdict['sensitive_scopes'])) {
                $msg .= ' It also holds permissions the plugin does not need, so remove: ' . $labels($verdict['sensitive_scopes']) . '.';
            }
            return $msg;
        case 'sensitive_scope':
            return 'This restricted key can do more than the plugin needs. Turn off these permissions in the Paystack Dashboard: '
                . $labels($verdict['scopes']) . '. The plugin only needs Transactions: read.';
        case 'unverifiable':
            if (($verdict['reason'] ?? null) === 'blocked') {
                return 'Paystack refused the key check. If your Paystack integration has an IP whitelist, add this server\'s IP address and save again. '
                    . 'You can paste the sk_ secret key instead.';
            }
            return 'We couldn\'t confirm this key with Paystack. Try again in a moment; if it keeps failing, you can paste the sk_ secret key instead.';
    }

    return 'This key could not be verified.';
}

function paystackRkCheckConfig(
    #[\SensitiveParameter]
    array $submitted,
    callable $validate
): ?string {
    $get = static function (array $params, string $name): string {
        return isset($params[$name]) && is_string($params[$name]) ? trim($params[$name]) : '';
    };

    $slots = [
        'test' => ['Test', 'testSecretKey', 'testPublicKey'],
        'live' => ['Live', 'liveSecretKey', 'livePublicKey'],
    ];
    foreach ($slots as $mode => [$label, $keyField, $pkField]) {
        $error = paystackRkShapeError($mode, $get($submitted, $keyField), $get($submitted, $pkField));
        if ($error !== null) {
            return $label . ' secret key ' . $error;
        }
    }

    $testMode = ($submitted['testMode'] ?? '') === 'on';
    $key = $get($submitted, $slots[$testMode ? 'test' : 'live'][1]);
    if (paystackRkKeyType($key) !== 'rk') {
        return null;
    }

    $verdict = $validate($key);

    return !empty($verdict['ok']) ? null : paystackRkMessage($verdict);
}

function paystackRkLooksLikePaystackDelivery(array $server): bool
{
    $legacy = $server['HTTP_X_PAYSTACK_SIGNATURE'] ?? null;
    $current = $server['HTTP_PAYSTACK_SIGNATURE'] ?? null;

    return (is_string($legacy) && preg_match('/^[0-9a-f]{128}\z/', $legacy) === 1)
        || (is_string($current) && preg_match('/^t=\d+,v1=/', $current) === 1);
}

function paystackRkWebhookDropReason(string $webhookSecret, array $server, string $keyType): string
{
    if ($webhookSecret === '') {
        return 'no_secret';
    }
    // An sk store can still be signed with the legacy header, so a miss there is a bad signature.
    if (!isset($server['HTTP_PAYSTACK_SIGNATURE']) && $keyType !== 'sk') {
        return 'secret_but_no_header';
    }

    return 'bad_signature';
}

function paystackRkWebhookDropMessage(string $reason): string
{
    $copy = [
        'no_secret' => 'add your Webhook Signing Secret (whsec_) for this mode',
        'secret_but_no_header' => 'Paystack sent no Paystack-Signature: webhook signing may not be enabled for this integration',
        'bad_signature' => "signature didn't match: check the Webhook Signing Secret",
    ];

    return $copy[$reason] ?? $reason;
}

function paystackRkVerifyProblemMessage(string $problem): string
{
    $copy = [
        'scope' => 'missing permission: give the key Transactions: read',
        'auth' => 'key invalid, revoked, or restricted keys not enabled for this integration',
        'blocked' => 'Paystack refused the request: if your Paystack integration has an IP whitelist, add this server\'s IP address',
    ];

    return $copy[$problem] ?? $problem;
}

function paystackRkVerifyProblem(int $httpCode, string $rawBody): string
{
    if ($httpCode === 401) {
        return 'auth';
    }
    if (paystackRkIsScopeDenied($httpCode, $rawBody)) {
        return 'scope';
    }

    return $httpCode === 403 ? 'blocked' : '';
}

function paystackRkThrottle(string $reason, ?int $now = null): bool
{
    try {
        if (!class_exists('\WHMCS\Config\Setting')) {
            return false;
        }
        $now = $now ?? time();
        $name = 'PaystackRkLog_' . preg_replace('/[^a-z_]/', '', $reason);
        $last = (int) \WHMCS\Config\Setting::getValue($name);
        if ($last > 0 && ($now - $last) < PAYSTACK_RK_THROTTLE_SECONDS) {
            return false;
        }
        \WHMCS\Config\Setting::setValue($name, (string) $now);

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
