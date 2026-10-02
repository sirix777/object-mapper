<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$rootDirectory    = __DIR__ . '/../..';
$testDirectory    = $rootDirectory . '/test';
$sourceDirectory  = $rootDirectory . '/src';

/*
 * Test fixtures declare several classes per file, so most fixture classes are
 * not PSR-4 autoloadable and show up as unknown classes. That is expected for
 * tests; only this category is ignored globally.
 */
$configuration = new Configuration();
$configuration->disableReportingUnmatchedIgnores();
$configuration->ignoreErrorsOnPath($testDirectory, [
    ErrorType::UNKNOWN_CLASS,
]);

/*
 * Optional test-only extensions are used by specific fixtures. Keep them
 * optional (never production dependencies) and ignore them only where used, so
 * a future real unknown function or shadow dependency in tests is still
 * reported.
 */
$configuration->ignoreUnknownFunctions(['xdebug_info']);
$configuration->ignoreErrorsOnExtensionAndPath('ext-pcntl', $testDirectory . '/Support/StubbornChildProcess.php', [
    ErrorType::SHADOW_DEPENDENCY,
]);
$configuration->ignoreErrorsOnExtensionAndPath('ext-tokenizer', $testDirectory . '/Integration/MapperCacheSecurityTest.php', [
    ErrorType::SHADOW_DEPENDENCY,
]);

/*
 * opcache_invalidate is guarded with function_exists() and remains optional in
 * production; it is not a required extension.
 */
$configuration->ignoreErrorsOnExtensionAndPath('ext-zend-opcache', $sourceDirectory, [
    ErrorType::SHADOW_DEPENDENCY,
]);

return $configuration;
