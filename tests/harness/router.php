<?php

// Router for the module tree's `php -S`: answers the readiness probe, serves everything else normally.

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/__ready') {
    echo (string) getenv('HARNESS_NONCE');
    return true;
}

return false;
