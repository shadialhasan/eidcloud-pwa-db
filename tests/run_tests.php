<?php

declare(strict_types=1);

// Zero-dependency test runner
spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\PwaDb\\Tests\\';
    $baseDir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $file = $baseDir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }

    $srcPrefix = 'EidCloud\\PwaDb\\';
    $srcDir = __DIR__ . '/../src/';
    $srcLen = strlen($srcPrefix);
    if (strncmp($srcPrefix, $class, $srcLen) === 0) {
        $file = $srcDir . str_replace('\\', '/', substr($class, $srcLen)) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

echo "========================================\n";
echo "Running eidcloud-pwa-db Test Suite...\n";
echo "========================================\n\n";

try {
    $suite = new \EidCloud\PwaDb\Tests\PwaDbTest();
    $suite->runAll();
    echo "\n[RESULT] 100% Tests Passed Successfully.\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n[FAIL] Test suite encountered an error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
