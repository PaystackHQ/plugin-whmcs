<?php

namespace WHMCS\Config {
    // Backed by a file so throttling persists across the one-request-per-process server.
    class Setting
    {
        private static function load(): array
        {
            $path = getenv('HARNESS_SETTINGS');
            $data = $path && is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

            return is_array($data) ? $data : [];
        }

        public static function getValue($name)
        {
            return self::load()[$name] ?? null;
        }

        public static function setValue($name, $value)
        {
            $data = self::load();
            $data[$name] = $value;
            $path = getenv('HARNESS_SETTINGS');
            file_put_contents($path, json_encode($data), LOCK_EX);
            chmod($path, 0600);
        }
    }
}
