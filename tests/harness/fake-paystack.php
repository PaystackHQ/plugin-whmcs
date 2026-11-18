<?php

// Router for `php -S`: a canned Paystack API keyed by the suffix of the Bearer token.

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'GET' && $path === '/__ready') {
    echo (string) getenv('HARNESS_NONCE');
    return;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = stripos($auth, 'Bearer ') === 0 ? substr($auth, 7) : '';

const FAKE_SCOPE = 'This restricted key is not permitted to perform this action';

// 'METHOD /path' => [status, message (string, an error) or data (array, a success)]; '*' answers anything else.
$sensitiveGets = [];
foreach ([
    '/refund', '/bulkcharge', '/directdebit/account', '/preauthorization', '/settlement', '/subaccount', '/transfer/config',
    '/pay-with-transfer/custom-accounts/status', '/integration/webhooks/events', '/connect/platforms', '/settlement/instant/profile',
] as $route) {
    $sensitiveGets["GET $route"] = [200, []];
}
$table = [
    '_auth401' => ['*' => [401, 'Invalid key']],
    '_scope403' => ['*' => [403, FAKE_SCOPE]],
    '_blocked' => ['*' => [403, 'IP address not whitelisted']],
    '_500' => ['*' => [500, 'Internal error']],
    '_sensitive' => ['GET /transaction' => [200, []]] + $sensitiveGets + ['*' => [403, FAKE_SCOPE]],
    '_missingsens' => ['GET /transaction' => [403, FAKE_SCOPE]] + $sensitiveGets + ['*' => [403, FAKE_SCOPE]],
    '_write' => ['GET /transaction' => [200, []], 'POST /transaction/check_authorization' => [404, 'Not found'], '*' => [403, FAKE_SCOPE]],
    '_ok' => [
        'GET /transaction' => [200, []],
        'GET /transaction/verify/:ref' => [200, ['status' => 'success', 'amount' => 10000, 'requested_amount' => 10000, 'fees' => 150, 'reference' => '']],
        '*' => [403, FAKE_SCOPE],
    ],
];

$routes = ['*' => [401, 'Invalid key']];
foreach ($table as $suffix => $entry) {
    if ($token !== '' && substr($token, -strlen($suffix)) === $suffix) {
        $routes = $entry;
        break;
    }
}

$reference = null;
if ($method === 'GET' && strpos($path, '/transaction/verify/') === 0) {
    $reference = rawurldecode(substr($path, strlen('/transaction/verify/')));
    $path = '/transaction/verify/:ref';
}
[$status, $payload] = $routes["$method $path"] ?? $routes['*'];
if (is_array($payload) && $reference !== null) {
    $payload['reference'] = $reference;
}

http_response_code($status);
header('Content-Type: application/json');
echo json_encode(is_array($payload) ? ['status' => true, 'data' => $payload] : ['status' => false, 'message' => $payload]);
