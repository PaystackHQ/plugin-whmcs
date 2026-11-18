<?php

define('WHMCS', true);

if (getenv('HARNESS_API_BASE')) {
    define('PAYSTACK_WHMCS_TEST_API_BASE', getenv('HARNESS_API_BASE'));
}

require_once __DIR__ . '/includes/harness_setting.php';
require_once __DIR__ . '/includes/harness.php';

harnessRecordCall('boot', []);
