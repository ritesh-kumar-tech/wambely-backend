<?php
// No Composer in this project — a minimal PSR-4-ish autoloader for the
// `Nium\` namespace (lib/nium/*.php) is enough for what's here.

spl_autoload_register(function (string $class): void {
    $map = ['Nium\\' => __DIR__ . '/nium/', 'Provider\\' => __DIR__ . '/provider/'];
    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $relative = substr($class, strlen($prefix));
        $path = $dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) require $path;
        return;
    }
});

require __DIR__ . '/provider/provider.php';
