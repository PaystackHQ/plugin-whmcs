<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('WHMCS', true);

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];

function t(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__t']['pass']++;
        echo "PASS  $name\n";
    } catch (\Throwable $e) {
        $GLOBALS['__t']['fail']++;
        echo "FAIL  $name\n      " . $e->getMessage() . "\n";
    }
}

function eq($actual, $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        throw new \RuntimeException(($msg !== '' ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(bool $cond, string $msg = ''): void
{
    if (!$cond) {
        throw new \RuntimeException($msg !== '' ? $msg : 'assertion failed');
    }
}

require_once __DIR__ . '/../modules/gateways/paystack/RestrictedKey.php';

// exit() below stops later shutdown functions, so cleanups register here instead.
$GLOBALS['__t_cleanup'] = [];

register_shutdown_function(function () {
    foreach ($GLOBALS['__t_cleanup'] as $cleanup) {
        $cleanup();
    }
    printf("\n%d passed, %d failed\n", $GLOBALS['__t']['pass'], $GLOBALS['__t']['fail']);
    // A fatal skips the rest of the file, and exit(0) here would otherwise hide it.
    $error = error_get_last();
    if ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        exit(255);
    }
    if ($GLOBALS['__t']['pass'] + $GLOBALS['__t']['fail'] === 0) {
        exit(1);
    }
    exit($GLOBALS['__t']['fail'] === 0 ? 0 : 1);
});
