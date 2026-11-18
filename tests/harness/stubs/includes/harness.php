<?php

$GLOBALS['__harness_calls'] = [];

function harnessRecordCall(string $fn, array $args): void
{
    $GLOBALS['__harness_calls'][] = ['fn' => $fn, 'args' => $args];
}

register_shutdown_function(function () {
    $path = getenv('HARNESS_RECORD');
    if (!$path) {
        return;
    }
    $json = json_encode($GLOBALS['__harness_calls']);
    // The record must never carry a key; a marker lets the runner fail loudly.
    // Keep in sync with HARNESS_LEAK_PATTERN in tests/harness/run.php.
    if (preg_match('/(rk|sk|whsec)_[A-Za-z0-9]/', (string) $json)) {
        $json = json_encode(['leak' => true]);
    }
    file_put_contents($path, $json);
    chmod($path, 0600);
});
