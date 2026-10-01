<?php

declare(strict_types=1);

namespace Sirix\ObjectMapperTest\Integration;

use ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Generator\MapperCache;
use Sirix\ObjectMapper\Generator\PhpMapperGenerator;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Sirix\ObjectMapper\Runtime\MappingRegistry;
use Sirix\ObjectMapper\Runtime\ObjectMapper;
use Sirix\ObjectMapper\Runtime\ValueTransformerRegistry;
use Sirix\ObjectMapperTest\Support\ConventionalSource;
use Sirix\ObjectMapperTest\Support\ConventionalTarget;

use function bin2hex;
use function chmod;
use function clearstatcache;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function function_exists;
use function glob;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_decode;
use function microtime;
use function mkdir;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function stream_set_blocking;
use function strrpos;
use function substr;
use function substr_count;
use function symlink;
use function sys_get_temp_dir;
use function token_get_all;
use function trim;
use function unlink;
use function usleep;
use function var_export;

#[CoversClass(MapperCache::class)]
final class MapperCacheSecurityTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/object-mapper-security-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_link($this->cacheDirectory)) {
            unlink($this->cacheDirectory);

            return;
        }

        $this->removeDirectory($this->cacheDirectory);
    }

    public function testItRejectsTamperedGeneratedCodeWithoutExecutingIt(): void
    {
        $sentinel = sys_get_temp_dir() . '/object-mapper-sentinel-' . bin2hex(random_bytes(8)) . '.txt';

        $this->mapper(true)->map(new ConventionalSource(1, 'tampered', true), ConventionalTarget::class);

        $generatedPath = $this->singleGeneratedMapperPath();
        $this->tamperWithSentinel($generatedPath, $sentinel);

        try {
            $this->mapper(true)->map(new ConventionalSource(1, 'tampered', true), ConventionalTarget::class);
            self::fail('Expected tampered generated code to be rejected even with the class already declared.');
        } catch (MappingCompilationFailed $exception) {
            self::assertStringContainsString('is unreadable or has changed', $exception->getMessage());
        }

        self::assertFileDoesNotExist($sentinel);
    }

    public function testItRejectsTamperedGeneratedCodeInAFreshProcess(): void
    {
        self::assertTrue(function_exists('proc_open'));

        mkdir($this->cacheDirectory, 0o700, true);

        [$exitCode] = $this->runWarmupProcess($this->cacheDirectory, '');
        self::assertSame(0, $exitCode);

        $sentinel = sys_get_temp_dir() . '/object-mapper-sentinel-' . bin2hex(random_bytes(8)) . '.txt';
        $this->tamperWithSentinel($this->singleGeneratedMapperPath(), $sentinel);

        [$exitCode, , $stderr] = $this->runWarmupProcess($this->cacheDirectory, '');

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('MappingCompilationFailed', $stderr);
        self::assertStringContainsString('Could not warm mapping', $stderr);
        self::assertFileDoesNotExist($sentinel);
    }

    #[DataProvider('symlinkCacheEntries')]
    public function testItRejectsSymlinkCacheEntries(string $case): void
    {
        self::assertTrue(function_exists('symlink'), 'Symlink support is required for this security case.');

        $externalDirectory = sys_get_temp_dir() . '/object-mapper-external-' . bin2hex(random_bytes(8));
        mkdir($externalDirectory, 0o700, true);
        $externalSentinel = $externalDirectory . '/sentinel.txt';
        $externalFile     = $externalDirectory . '/external.php';
        $externalContent  = '<?php file_put_contents(' . var_export($externalSentinel, true) . ", 'executed');\n";
        file_put_contents($externalFile, $externalContent);
        chmod($externalFile, 0o600);

        try {
            if ('cache-directory-symlink' === $case) {
                symlink($externalDirectory, $this->cacheDirectory);

                $this->assertMapIsRejected();
                self::assertFileDoesNotExist($externalSentinel);
                self::assertSame($externalContent, file_get_contents($externalFile));

                return;
            }

            $this->mapper(true)->map(new ConventionalSource(1, 'symlink', true), ConventionalTarget::class);
            $generatedPath = $this->singleGeneratedMapperPath();
            $key           = substr(
                substr($generatedPath, 0, -4),
                strrpos($generatedPath, 'Mapper_') + 7,
            );

            if ('generated-file-symlink' === $case) {
                unlink($generatedPath);
                symlink($externalFile, $generatedPath);
            } elseif ('dangling-generated-symlink' === $case) {
                unlink($generatedPath);
                symlink($generatedPath . '.missing', $generatedPath);
            } else {
                $lockPath = $this->cacheDirectory . '/.Mapper_' . $key . '.lock';
                if (is_file($lockPath)) {
                    unlink($lockPath);
                }

                symlink($externalFile, $lockPath);
            }

            $this->assertMapIsRejected();

            self::assertFileDoesNotExist($externalSentinel);
            self::assertSame($externalContent, file_get_contents($externalFile));
        } finally {
            $this->removeDirectory($externalDirectory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function symlinkCacheEntries(): iterable
    {
        yield 'generated file symlink'     => ['generated-file-symlink'];

        yield 'dangling generated symlink' => ['dangling-generated-symlink'];

        yield 'lock symlink'               => ['lock-symlink'];

        yield 'cache directory symlink'    => ['cache-directory-symlink'];
    }

    public function testItRejectsUnsafeGeneratedFilePermissions(): void
    {
        $this->mapper(true)->map(new ConventionalSource(1, 'permissions', true), ConventionalTarget::class);

        $generatedPath = $this->singleGeneratedMapperPath();
        self::assertSame(0o600, fileperms($generatedPath) & 0o777);

        chmod($generatedPath, 0o644);
        clearstatcache(true, $generatedPath);

        $this->assertMapIsRejected();

        chmod($generatedPath, 0o600);
        clearstatcache(true, $generatedPath);

        self::assertSame(1, $this->mapper(true)->map(new ConventionalSource(1, 'permissions', true), ConventionalTarget::class)->id);
    }

    public function testConcurrentWarmupPublishesOneCompleteMapper(): void
    {
        self::assertTrue(function_exists('proc_open'));

        mkdir($this->cacheDirectory, 0o700, true);
        $barrierPath = sys_get_temp_dir() . '/object-mapper-barrier-' . bin2hex(random_bytes(8));

        $processes   = [];
        $pipeGroups  = [];

        try {
            for ($index = 0; $index < 2; ++$index) {
                $pipes   = [];
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__) . '/Support/CacheWarmupProcess.php', $this->cacheDirectory, $barrierPath],
                    [
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2),
                );
                self::assertIsResource($process);
                $processes[$index]  = $process;
                $pipeGroups[$index] = $pipes;
            }

            $readyPath     = $barrierPath . '.ready';
            $readyDeadline = microtime(true) + 10.0;
            while (true) {
                $readyContent = is_file($readyPath) ? (file_get_contents($readyPath) ?: '') : '';
                if (substr_count($readyContent, 'ready') >= 2) {
                    break;
                }

                if (microtime(true) > $readyDeadline) {
                    self::fail('Both warmup processes must reach the start barrier before release.');
                }

                usleep(1_000);
            }

            file_put_contents($barrierPath, 'go');

            $deadline = microtime(true) + 15.0;
            $outputs  = [];
            foreach ($processes as $index => $process) {
                [$exitCode, $stdout, $stderr] = $this->collectProcess($process, $pipeGroups[$index], $deadline);
                self::assertSame(0, $exitCode, $stderr);
                $outputs[$index] = trim($stdout);
            }

            self::assertSame($outputs[0], $outputs[1]);
            self::assertSame([
                'id' => 7,
            ], json_decode($outputs[0], true, flags: JSON_THROW_ON_ERROR));

            $generatedFiles = glob($this->cacheDirectory . '/Mapper_*.php') ?: [];
            self::assertCount(1, $generatedFiles);
            self::assertSame(0o600, fileperms($generatedFiles[0]) & 0o777);
            self::assertStringContainsString('final class Mapper_', file_get_contents($generatedFiles[0]) ?: '');
            self::assertSame([], glob($this->cacheDirectory . '/.mapper-*') ?: []);
        } finally {
            foreach ($processes as $index => $process) {
                if (is_resource($process)) {
                    foreach ($pipeGroups[$index] as $pipe) {
                        if (is_resource($pipe)) {
                            @fclose($pipe);
                        }
                    }

                    if ($this->terminateProcess($process)) {
                        proc_close($process);
                    }
                }
            }

            if (is_file($barrierPath)) {
                unlink($barrierPath);
            }

            if (is_file($barrierPath . '.ready')) {
                unlink($barrierPath . '.ready');
            }
        }
    }

    public function testProcessHelperHonoursDeadlineForSilentChild(): void
    {
        self::assertTrue(function_exists('proc_open'));

        $pipes   = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/SilentChildProcess.php', '1200'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );
        self::assertIsResource($process);

        $startedAt  = microtime(true);
        [$exitCode] = $this->collectProcess($process, $pipes, $startedAt + 0.1);
        $elapsed    = microtime(true) - $startedAt;

        self::assertSame(-1, $exitCode);
        self::assertLessThan(0.9, $elapsed);
    }

    public function testProcessHelperForceKillsChildThatIgnoresTermination(): void
    {
        self::assertTrue(function_exists('proc_open'));
        if (! function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is required to make the child fixture ignore SIGTERM.');
        }

        $readyPath = sys_get_temp_dir() . '/object-mapper-stubborn-ready-' . bin2hex(random_bytes(8));
        $pipes     = [];
        $process   = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/StubbornChildProcess.php', $readyPath],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );
        self::assertIsResource($process);

        try {
            $readyDeadline = microtime(true) + 5.0;
            while (! is_file($readyPath)) {
                if (microtime(true) > $readyDeadline) {
                    self::fail('The stubborn child did not confirm it installed its SIGTERM handler.');
                }

                usleep(1_000);
            }

            $startedAt  = microtime(true);
            [$exitCode] = $this->collectProcess($process, $pipes, $startedAt + 0.1);
            $elapsed    = microtime(true) - $startedAt;

            self::assertSame(-1, $exitCode);
            self::assertLessThan(2.0, $elapsed);
        } finally {
            if (is_file($readyPath)) {
                unlink($readyPath);
            }
        }
    }

    private function mapper(bool $generateOnDemand): ObjectMapper
    {
        $mappingRegistry          = new MappingRegistry([new MappingDefinition(ConventionalSource::class, ConventionalTarget::class)]);
        $valueTransformerRegistry = new ValueTransformerRegistry();

        return new ObjectMapper(
            $mappingRegistry,
            new MapperCache(
                new MappingMetadataFactory($valueTransformerRegistry, mappingRegistry: $mappingRegistry),
                new PhpMapperGenerator(),
                $this->cacheDirectory,
                $valueTransformerRegistry,
                $generateOnDemand,
                $mappingRegistry,
            ),
        );
    }

    private function assertMapIsRejected(): void
    {
        try {
            $this->mapper(true)->map(new ConventionalSource(1, 'symlink', true), ConventionalTarget::class);
            self::fail('Expected the unsafe cache entry to be rejected.');
        } catch (MappingCompilationFailed) {
            self::addToAssertionCount(1);
        }
    }

    private function singleGeneratedMapperPath(): string
    {
        $files = glob($this->cacheDirectory . '/Mapper_*.php') ?: [];
        self::assertCount(1, $files);

        return $files[0];
    }

    private function tamperWithSentinel(string $generatedPath, string $sentinel): void
    {
        $tampered = (file_get_contents($generatedPath) ?: '')
            . "\nfile_put_contents(" . var_export($sentinel, true) . ", 'executed');\n";

        try {
            self::assertNotEmpty(token_get_all($tampered, TOKEN_PARSE));
        } catch (ParseError $parseError) {
            self::fail('Tampered payload must remain valid PHP: ' . $parseError->getMessage());
        }

        file_put_contents($generatedPath, $tampered);
        chmod($generatedPath, 0o600);
    }

    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     *
     * @return array{int, string, string}
     */
    private function collectProcess(mixed $process, array $pipes, float $deadline): array
    {
        $stdout = '';
        $stderr = '';

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';

                $status = proc_get_status($process);
                if (! $status['running']) {
                    $stdout .= $this->drain($pipes[1]);
                    $stderr .= $this->drain($pipes[2]);

                    return [(int) $status['exitcode'], $stdout, $stderr];
                }

                if (microtime(true) >= $deadline) {
                    $this->terminateProcess($process);

                    return [-1, $stdout, $stderr . "\nprocess deadline exceeded\n"];
                }

                usleep(1_000);
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                if ($this->terminateProcess($process)) {
                    proc_close($process);
                }
            }
        }
    }

    /** @param resource $pipe */
    private function drain(mixed $pipe): string
    {
        $output = '';
        while (true) {
            $chunk = stream_get_contents($pipe);
            if (false === $chunk || '' === $chunk) {
                return $output;
            }

            $output .= $chunk;
        }
    }

    /**
     * @param resource $process
     *
     * @return bool true when the child is confirmed stopped
     */
    private function terminateProcess(mixed $process): bool
    {
        if (! is_resource($process)) {
            return true;
        }

        if ($this->isRunning($process)) {
            proc_terminate($process, 15);
            $graceDeadline = microtime(true) + 1.0;
            while ($this->isRunning($process) && microtime(true) < $graceDeadline) {
                usleep(1_000);
            }
        }

        if ($this->isRunning($process)) {
            proc_terminate($process, 9);
            $killDeadline = microtime(true) + 1.0;
            while ($this->isRunning($process) && microtime(true) < $killDeadline) {
                usleep(1_000);
            }
        }

        return ! $this->isRunning($process);
    }

    /**
     * @param resource $process
     *
     * @phpstan-impure
     */
    private function isRunning(mixed $process): bool
    {
        return (bool) proc_get_status($process)['running'];
    }

    /** @return array{int, string, string} */
    private function runWarmupProcess(string $cacheDirectory, string $barrierPath): array
    {
        $pipes   = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/CacheWarmupProcess.php', $cacheDirectory, $barrierPath],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );
        self::assertIsResource($process);

        return $this->collectProcess($process, $pipes, microtime(true) + 15.0);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
