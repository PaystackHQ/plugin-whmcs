<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// One subprocess per file keeps defines, stub classes and loaded modules from leaking between files.
$files = glob((getenv('TESTS_DIR') ?: __DIR__) . '/*Test.php');
sort($files);
$failed = 0;
foreach ($files as $file) {
    echo '== ' . basename($file) . "\n";
    $proc = proc_open([PHP_BINARY, $file], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, getenv());
    $code = is_resource($proc) ? proc_close($proc) : 1;
    if ($code !== 0) {
        $failed++;
        echo "== FAILED (exit $code): " . basename($file) . "\n";
    }
}

echo $failed === 0 ? "\nAll test files passed\n" : "\n$failed test file(s) failed\n";
exit($failed === 0 ? 0 : 1);
