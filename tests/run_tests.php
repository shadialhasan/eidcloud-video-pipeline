<?php

declare(strict_types=1);

// Zero-dependency test runner for EidCloud Video Pipeline

spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\VideoPipeline\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/VideoPipelineTest.php';

use EidCloud\VideoPipeline\Tests\VideoPipelineTest;

echo "\033[1;36m=========================================================================\033[0m\n";
echo "\033[1;35m 🎥 EidCloud Video Pipeline - Automated Test Runner \033[0m\n";
echo "\033[1;36m=========================================================================\033[0m\n\n";

$testSuite = new VideoPipelineTest();
$reflection = new ReflectionClass($testSuite);
$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

$passed = 0;
$failed = 0;
$startTime = microtime(true);

foreach ($methods as $method) {
    if (!str_starts_with($method->getName(), 'test')) {
        continue;
    }

    $name = $method->getName();
    echo sprintf("  %-50s ", $name . '...');

    try {
        $method->invoke($testSuite);
        echo "\033[1;32m[PASS]\033[0m\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "\033[1;31m[FAIL]\033[0m\n";
        echo "    \033[0;31mError: " . $e->getMessage() . "\033[0m\n";
        echo "    \033[0;33mAt: " . $e->getFile() . ":" . $e->getLine() . "\033[0m\n\n";
        $failed++;
    }
}

$duration = round(microtime(true) - $startTime, 2);

echo "\n\033[1;36m-------------------------------------------------------------------------\033[0m\n";
echo sprintf("  Results: \033[1;32m%d passed\033[0m, \033[1;%sm%d failed\033[0m in %.2f seconds\n",
    $passed,
    $failed > 0 ? '31' : '32',
    $failed,
    $duration
);
echo "\033[1;36m=========================================================================\033[0m\n";

exit($failed > 0 ? 1 : 0);
