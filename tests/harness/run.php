<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Runs the real module files in a WHMCS-shaped temp tree behind `php -S`; stubs live in tests/harness/stubs.
// Offline by default: the callback talks to fake-paystack.php. HARNESS_ONLINE=1 only gates the cases that
// opt into the real api.paystack.co.

// Keep in sync with the leak check in stubs/includes/harness.php (a separate process tree).
const HARNESS_LEAK_PATTERN = '/(rk|sk|whsec)_[A-Za-z0-9]/';

function harnessCopyTree(string $from, string $to): void
{
    mkdir($to, 0700, true);
    foreach (scandir($from) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir("$from/$entry")) {
            harnessCopyTree("$from/$entry", "$to/$entry");
        } else {
            copy("$from/$entry", "$to/$entry");
            chmod("$to/$entry", 0600);
        }
    }
}

function harnessRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            is_dir("$dir/$entry") ? harnessRemoveTree("$dir/$entry") : unlink("$dir/$entry");
        }
    }
    rmdir($dir);
}

function harnessTempDir(): string
{
    $dir = sys_get_temp_dir() . '/paystack-harness-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);

    return $dir;
}

function harnessFreePort(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($probe, false);
    fclose($probe);

    return (int) substr($name, strrpos($name, ':') + 1);
}

// A fixed $port is for the demo; tests take a free one.
function harnessSpawn(string $dir, array $args, string $cwd, array $env, int $port = 0): array
{
    if ($port === 0) {
        $port = harnessFreePort();
    }
    // Another process may own the port by now; only our own server echoes this nonce.
    $nonce = bin2hex(random_bytes(8));
    $env['HARNESS_NONCE'] = $nonce;

    touch("$dir/server.log");
    chmod("$dir/server.log", 0600);
    $proc = proc_open(
        array_merge([PHP_BINARY, '-S', "127.0.0.1:$port"], $args),
        [0 => ['pipe', 'r'], 1 => ['file', "$dir/server.log", 'a'], 2 => ['file', "$dir/server.log", 'a']],
        $pipes,
        $cwd,
        $env
    );
    $srv = ['dir' => $dir, 'port' => $port, 'proc' => $proc, 'pipes' => $pipes];

    for ($i = 0; $i < 100; $i++) {
        if (!is_resource($proc) || !proc_get_status($proc)['running']) {
            break;
        }
        $ready = @file_get_contents("http://127.0.0.1:$port/__ready", false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]));
        if ($ready === $nonce) {
            $GLOBALS['__harness_servers'][] = $srv;

            return $srv;
        }
        usleep(50000);
    }
    $log = (string) @file_get_contents("$dir/server.log");
    harnessStop($srv);
    throw new \RuntimeException('harness server did not start: ' . substr($log, 0, 300));
}

function harnessStop(array $srv): void
{
    if (is_resource($srv['proc'])) {
        foreach ($srv['pipes'] as $pipe) {
            is_resource($pipe) && fclose($pipe);
        }
        proc_terminate($srv['proc']);
        proc_close($srv['proc']);
    }
    harnessRemoveTree($srv['dir']);
}

// The fake Paystack API, started on first use; returns its base URL.
function harnessFakeApi(int $port = 0): string
{
    if (!isset($GLOBALS['__harness_fake'])) {
        $GLOBALS['__harness_fake'] = harnessSpawn(harnessTempDir(), [__DIR__ . '/fake-paystack.php'], __DIR__, getenv(), $port);
    }

    return 'http://127.0.0.1:' . $GLOBALS['__harness_fake']['port'];
}

// Copies the stubs and the real module files into a WHMCS-shaped docroot under $dir; returns the docroot.
function harnessBuildTree(string $dir): string
{
    $docroot = "$dir/www";
    harnessCopyTree(__DIR__ . '/stubs', $docroot);
    harnessCopyTree(dirname(__DIR__, 2) . '/modules', "$docroot/modules");

    return $docroot;
}

// One server per (config, options): the config reaches it through the environment.
// Options: 'live' (use the real API instead of the fake), 'withoutRkDir' (delete modules/gateways/paystack/).
function harnessServer(array $config, array $opts = []): array
{
    $id = md5(json_encode([$config, $opts]));
    if (isset($GLOBALS['__harness_cache'][$id])) {
        return $GLOBALS['__harness_cache'][$id];
    }

    $dir = harnessTempDir();
    $docroot = harnessBuildTree($dir);
    if (!empty($opts['withoutRkDir'])) {
        harnessRemoveTree("$docroot/modules/gateways/paystack");
    }

    $env = getenv();
    $env['HARNESS_CONFIG_JSON'] = json_encode($config);
    $env['HARNESS_RECORD'] = "$dir/record.json";
    $env['HARNESS_SETTINGS'] = "$dir/settings.json";
    if (empty($opts['live'])) {
        $env['HARNESS_API_BASE'] = harnessFakeApi();
    } else {
        unset($env['HARNESS_API_BASE']);
    }
    $srv = harnessSpawn($dir, ['-t', $docroot, __DIR__ . '/router.php'], $docroot, $env);
    $GLOBALS['__harness_cache'][$id] = $srv;

    return $srv;
}

// Clears throttle state on every callback server.
function harnessReset(): void
{
    foreach ($GLOBALS['__harness_cache'] ?? [] as $srv) {
        @unlink($srv['dir'] . '/settings.json');
    }
}

// Key bodies generated by the tests, so a leak is caught even without an rk_/sk_ prefix around it.
function harnessTrackSecret(string $literal): void
{
    $GLOBALS['__harness_secrets'][] = $literal;
}

// One generated key body per prefix, tracked so a leak is caught wherever it surfaces.
function harnessKey(string $prefix, string $suffix = '', string $mode = 'test'): string
{
    static $body = [];
    if (!isset($body[$prefix])) {
        $body[$prefix] = substr(bin2hex(random_bytes(20)), 0, 40);
        harnessTrackSecret($body[$prefix]);
    }

    return $prefix . '_' . $mode . '_' . $body[$prefix] . $suffix;
}

function harnessAssertNoLeak(string $what, string $text): void
{
    $leaked = preg_match(HARNESS_LEAK_PATTERN, $text) === 1;
    foreach ($GLOBALS['__harness_secrets'] ?? [] as $literal) {
        $leaked = $leaked || strpos($text, $literal) !== false;
    }
    if ($leaked) {
        throw new \RuntimeException("harness: key material found in $what");
    }
}

// Returns [status, headers, body, record]; throws if a key leaked or the stubs never booted.
function harnessRequest(string $method, string $pathAndQuery, array $headers, ?string $body, array $config, array $opts = []): array
{
    $srv = harnessServer($config, $opts);
    @unlink($srv['dir'] . '/record.json');

    $ch = curl_init("http://127.0.0.1:{$srv['port']}$pathAndQuery");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $respHeaders = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);

    $record = [];
    for ($i = 0; $i < 40; $i++) {
        if (is_file($srv['dir'] . '/record.json')) {
            $decoded = json_decode((string) file_get_contents($srv['dir'] . '/record.json'), true);
            if (is_array($decoded)) {
                $record = $decoded;
                break;
            }
        }
        usleep(50000);
    }

    if (isset($record['leak'])) {
        throw new \RuntimeException('harness: key material found in the stub record');
    }
    harnessAssertNoLeak('a response body', $respBody);
    harnessAssertNoLeak('response headers', $respHeaders);
    harnessAssertNoLeak('the stub record', (string) @file_get_contents($srv['dir'] . '/record.json'));
    harnessAssertNoLeak('server.log', (string) @file_get_contents($srv['dir'] . '/server.log'));
    if (isset($GLOBALS['__harness_fake'])) {
        harnessAssertNoLeak('the fake API log', (string) @file_get_contents($GLOBALS['__harness_fake']['dir'] . '/server.log'));
    }
    if (($record[0]['fn'] ?? '') !== 'boot') {
        throw new \RuntimeException("harness: stubs did not boot (status $status)");
    }

    return [$status, $respHeaders, $respBody, $record];
}

$GLOBALS['__t_cleanup'][] = function () {
    foreach ($GLOBALS['__harness_servers'] ?? [] as $srv) {
        harnessStop($srv);
    }
};
