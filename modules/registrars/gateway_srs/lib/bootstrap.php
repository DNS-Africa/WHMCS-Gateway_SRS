<?php
// The distributed module is self-contained and does not load WHMCS's Composer dependencies.
spl_autoload_register(static function ($class) {
    $prefix = 'WHMCS\\Module\\Registrar\\gateway_srs\\';
    if (strpos($class, $prefix) === 0) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) {
            $file = __DIR__ . '/' . $name . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});
