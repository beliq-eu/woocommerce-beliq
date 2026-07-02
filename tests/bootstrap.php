<?php declare(strict_types=1);

// PSR-4 autoloader for local runs without Composer. CI uses the Composer
// autoloader; both resolve the framework-agnostic core (Beliq\Core\) to
// src/Core/, the plugin classes (Beliq\WooCommerce\) to src/, and the test
// namespace to tests/. WordPress/WooCommerce runtime classes are not mapped
// here: the mapper and client tests never touch them, so no WordPress install
// is needed to run them.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Beliq\\WooCommerce\\Tests\\' => __DIR__ . '/',
        'Beliq\\Core\\' => __DIR__ . '/../src/Core/',
        'Beliq\\WooCommerce\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
