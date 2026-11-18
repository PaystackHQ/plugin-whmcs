<?php

namespace WHMCS\Config {
    class Setting
    {
        public static $store = [];
        public static $throw = false;

        public static function getValue($name)
        {
            if (self::$throw) {
                throw new \RuntimeException('db down');
            }
            return self::$store[$name] ?? null;
        }

        public static function setValue($name, $value)
        {
            if (self::$throw) {
                throw new \RuntimeException('db down');
            }
            self::$store[$name] = $value;
        }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';

    t('throttle: first logs, then suppressed within the hour, then logs again', function () {
        \WHMCS\Config\Setting::$store = [];
        eq(paystackRkThrottle('no_secret', 1000), true);
        eq(paystackRkThrottle('no_secret', 1001), false);
        eq(paystackRkThrottle('no_secret', 1000 + 3599), false);
        eq(paystackRkThrottle('no_secret', 1000 + 3600), true);
        eq(paystackRkThrottle('no_secret', 1000 + 3601), false);
    });

    t('throttle: reasons are independent and the key is sanitized', function () {
        \WHMCS\Config\Setting::$store = [];
        eq(paystackRkThrottle('no_secret', 1000), true);
        eq(paystackRkThrottle('bad_signature', 1000), true);
        eq(paystackRkThrottle('Bad Sig!1', 1000), true);
        ok(isset(\WHMCS\Config\Setting::$store['PaystackRkLog_no_secret']));
        eq(array_keys(\WHMCS\Config\Setting::$store), ['PaystackRkLog_no_secret', 'PaystackRkLog_bad_signature', 'PaystackRkLog_adig']);
        eq(\WHMCS\Config\Setting::$store['PaystackRkLog_no_secret'], '1000');
    });

    t('throttle: storage failure suppresses the line', function () {
        \WHMCS\Config\Setting::$throw = true;
        try {
            eq(paystackRkThrottle('no_secret', 5000), false);
            eq(paystackRkThrottle('no_secret', 5001), false);
        } finally {
            \WHMCS\Config\Setting::$throw = false;
        }
    });
}
