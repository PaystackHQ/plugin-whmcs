<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/harness/run.php';

// Runs tests/run.php against a temp dir holding one generated test file; returns its exit code.
function bootstrapRun(string $body): int
{
    $dir = harnessTempDir();
    file_put_contents("$dir/SampleTest.php", "<?php\nrequire_once " . var_export(__DIR__ . '/bootstrap.php', true) . ";\n$body\n");
    $env = getenv();
    $env['TESTS_DIR'] = $dir;
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/run.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        $env
    );
    $code = proc_close($proc);
    harnessRemoveTree($dir);

    return $code;
}

t('bootstrap: a passing file makes run.php succeed', function () {
    eq(bootstrapRun("t('ok', function () { ok(true); });"), 0);
});

t('bootstrap: a fatal after passing tests fails run.php', function () {
    eq(bootstrapRun("t('ok', function () { ok(true); });\nnot_a_function();"), 1);
});

t('bootstrap: a file that runs no tests fails run.php', function () {
    eq(bootstrapRun('// nothing'), 1);
});
