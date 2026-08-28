<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Generator;

use InvalidArgumentException;
use Sirix\ObjectMapper\Definition\MappingDefinition;
use Sirix\ObjectMapper\Exception\MappingCompilationFailed;
use Sirix\ObjectMapper\Metadata\MappingMetadataFactory;
use Throwable;

use function chmod;
use function class_exists;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function flock;
use function fopen;
use function function_exists;
use function hash_equals;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function is_writable;
use function mkdir;
use function opcache_invalidate;
use function rename;
use function sprintf;
use function tempnam;
use function unlink;

/** @internal */
final class MapperCache
{
    /** @var array<string, GeneratedMapperInterface> */
    private array $mappers = [];

    public function __construct(
        private readonly MappingMetadataFactory $mappingMetadataFactory,
        private readonly PhpMapperGenerator $phpMapperGenerator,
        private readonly string $cacheDirectory,
        private readonly bool $generateOnDemand = false,
    ) {
        if ('' === $cacheDirectory) {
            throw new InvalidArgumentException('The mapper cache directory cannot be empty.');
        }
    }

    public function get(MappingDefinition $mappingDefinition): GeneratedMapperInterface
    {
        return $this->resolve($mappingDefinition, $this->generateOnDemand);
    }

    public function warm(MappingDefinition $mappingDefinition): GeneratedMapperInterface
    {
        return $this->resolve($mappingDefinition, true);
    }

    private function resolve(MappingDefinition $mappingDefinition, bool $allowGeneration): GeneratedMapperInterface
    {
        $mappingMetadata = $this->mappingMetadataFactory->create($mappingDefinition);
        $key             = $this->phpMapperGenerator->cacheKey($mappingMetadata);
        if (isset($this->mappers[$key])) {
            return $this->mappers[$key];
        }

        $className = $this->phpMapperGenerator->className($key);
        $content   = $this->phpMapperGenerator->generate($mappingMetadata, $key);
        if ($allowGeneration) {
            $this->ensureCacheDirectory();
        } elseif (! is_dir($this->cacheDirectory)) {
            throw new MappingCompilationFailed(sprintf(
                'Generated mapper cache miss for %s. Warm the cache before production use.',
                $mappingDefinition->key(),
            ));
        }

        $this->assertSafeCacheDirectory();
        $path     = $this->cachePath($key);
        $lockPath = $this->lockPath($key);
        if (is_link($lockPath)) {
            throw new MappingCompilationFailed(sprintf('Mapper cache lock %s must not be a symbolic link.', $lockPath));
        }

        $lock = fopen($lockPath, $allowGeneration ? 'c' : 'r');
        if (false === $lock) {
            throw new MappingCompilationFailed(sprintf(
                'Could not open mapper cache lock for %s. Warm the cache before production use.',
                $mappingDefinition->key(),
            ));
        }

        try {
            if (! flock($lock, $allowGeneration ? LOCK_EX : LOCK_SH)) {
                throw new MappingCompilationFailed(sprintf('Could not lock mapper cache for %s.', $mappingDefinition->key()));
            }

            if (is_file($path)) {
                return $this->remember(
                    $key,
                    $this->load($path, $className, $content),
                );
            }

            if (! $allowGeneration) {
                throw new MappingCompilationFailed(sprintf(
                    'Generated mapper cache miss for %s. Warm the cache before production use.',
                    $mappingDefinition->key(),
                ));
            }

            if (! is_file($path)) {
                $this->write($path, $content, $mappingDefinition);
            }

            return $this->remember(
                $key,
                $this->load($path, $className, $content),
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function write(string $path, string $content, MappingDefinition $mappingDefinition): void
    {
        $temporaryPath = tempnam($this->cacheDirectory, '.mapper-');
        if (false === $temporaryPath) {
            throw new MappingCompilationFailed(sprintf('Could not create temporary mapper file for %s.', $mappingDefinition->key()));
        }

        try {
            if (false === file_put_contents($temporaryPath, $content, LOCK_EX)) {
                throw new MappingCompilationFailed(sprintf('Could not write generated mapper for %s.', $mappingDefinition->key()));
            }

            if (! chmod($temporaryPath, 0o600)) {
                throw new MappingCompilationFailed(sprintf('Could not secure generated mapper for %s.', $mappingDefinition->key()));
            }

            $this->lint($temporaryPath, $mappingDefinition);
            if (! rename($temporaryPath, $path)) {
                throw new MappingCompilationFailed(sprintf('Could not atomically publish generated mapper for %s.', $mappingDefinition->key()));
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function remember(
        string $cacheKey,
        GeneratedMapperInterface $generatedMapper,
    ): GeneratedMapperInterface {
        $this->mappers[$cacheKey] = $generatedMapper;

        return $generatedMapper;
    }

    private function lint(string $path, MappingDefinition $mappingDefinition): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path), $output, $status);
        if (0 !== $status) {
            throw new MappingCompilationFailed(sprintf(
                'Generated mapper for %s did not pass PHP lint: %s',
                $mappingDefinition->key(),
                implode("\n", $output),
            ));
        }
    }

    private function load(string $path, string $className, string $expectedContent): GeneratedMapperInterface
    {
        $this->assertSafeCacheFile($path);
        $actualContent = file_get_contents($path);
        if (false === $actualContent || ! hash_equals($expectedContent, $actualContent)) {
            throw new MappingCompilationFailed(sprintf('Generated mapper file %s is unreadable or has changed.', $path));
        }

        if (! class_exists($className, false)) {
            try {
                require_once $path;
            } catch (Throwable $exception) {
                throw new MappingCompilationFailed(sprintf('Could not load generated mapper file %s.', $path), $exception->getCode(), previous: $exception);
            }
        }

        if (! class_exists($className, false)) {
            throw new MappingCompilationFailed(sprintf('Generated mapper file %s did not define %s.', $path, $className));
        }

        $mapper = new $className();
        if (! $mapper instanceof GeneratedMapperInterface) {
            throw new MappingCompilationFailed(sprintf('Generated mapper class %s has an invalid type.', $className));
        }

        return $mapper;
    }

    private function ensureCacheDirectory(): void
    {
        if (! is_dir($this->cacheDirectory) && ! mkdir($concurrentDirectory = $this->cacheDirectory, 0o700, true) && ! is_dir($concurrentDirectory)) {
            throw new MappingCompilationFailed(sprintf('Could not create mapper cache directory %s.', $this->cacheDirectory));
        }

        if (! is_writable($this->cacheDirectory)) {
            throw new MappingCompilationFailed(sprintf('Mapper cache directory %s is not writable.', $this->cacheDirectory));
        }
    }

    private function assertSafeCacheDirectory(): void
    {
        if (is_link($this->cacheDirectory)) {
            throw new MappingCompilationFailed(sprintf('Mapper cache directory %s must not be a symbolic link.', $this->cacheDirectory));
        }

        $mode = fileperms($this->cacheDirectory);
        if (false === $mode || 0 !== ($mode & 0o077)) {
            throw new MappingCompilationFailed(sprintf(
                'Mapper cache directory %s must be accessible only by its owner (0700).',
                $this->cacheDirectory,
            ));
        }
    }

    private function assertSafeCacheFile(string $path): void
    {
        if (is_link($path)) {
            throw new MappingCompilationFailed(sprintf('Generated mapper file %s must not be a symbolic link.', $path));
        }

        $mode = fileperms($path);
        if (false === $mode || 0 !== ($mode & 0o077)) {
            throw new MappingCompilationFailed(sprintf(
                'Generated mapper file %s must be accessible only by its owner (0600).',
                $path,
            ));
        }
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . 'Mapper_' . $key . '.php';
    }

    private function lockPath(string $key): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . '.Mapper_' . $key . '.lock';
    }
}
