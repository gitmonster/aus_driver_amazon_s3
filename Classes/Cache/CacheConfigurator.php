<?php

declare(strict_types=1);

namespace AUS\AusDriverAmazonS3\Cache;

use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Builds the cacheConfigurations for the driver's two internal caches:
 *
 *  - ausdriveramazons3_metainfocache: object metadata gathered via headObject()
 *  - ausdriveramazons3_requestcache:  S3 API responses (listObjectsV2)
 *
 * Both default to TransientMemoryBackend (per-request only) for backward
 * compatibility. Set "cacheBackend = redis" to back them with Redis so metadata
 * and folder listings persist across requests - the main lever to reduce S3 API
 * calls on read-heavy sites.
 *
 * The configuration is read from the array passed to build*() - typically the
 * driver's merged storage configuration (FlexForm values overridden by
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aus_driver_amazon_s3']['storage_X']
 * from AdditionalConfiguration.php). The extension deliberately does NOT read
 * any environment variables - Redis connection details belong to the consuming
 * site and are injected via the storage configuration.
 *
 * Recognized keys:
 *
 *  - cacheBackend:           "transient" (default) | "redis"
 *  - redisHost:              Redis hostname (default 127.0.0.1)
 *  - redisPort:              Redis port (default 6379)
 *  - redisPassword:          Redis password (optional)
 *  - metaInfoCacheDatabase:  Redis DB index for the metadata cache (required for redis)
 *  - requestCacheDatabase:   Redis DB index for the request cache (required for redis)
 *  - metaInfoCacheLifetime:  metadata cache TTL in seconds (default 0 = until flushed)
 *  - requestCacheLifetime:   request cache TTL in seconds (default 0 = until flushed)
 *
 * If "redis" is selected but the redis PHP extension is missing or a database
 * index is not configured, the cache falls back to TransientMemoryBackend rather
 * than guessing a database - the Redis layout stays the site's decision. Writes
 * (addFile/move/delete/...) always invalidate affected entries via
 * AmazonS3Driver::flushMetaInfoCache(), so the persistent cache stays consistent.
 */
final class CacheConfigurator
{
    public const BACKEND_TRANSIENT = 'transient';
    public const BACKEND_REDIS = 'redis';

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public function buildForMetaInfoCache(array $configuration): array
    {
        return $this->build($configuration, 'metaInfoCacheDatabase', 'metaInfoCacheLifetime');
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public function buildForRequestCache(array $configuration): array
    {
        return $this->build($configuration, 'requestCacheDatabase', 'requestCacheLifetime');
    }

    /**
     * @param array<string, mixed> $configuration
     * @param string $databaseKey configuration key holding the Redis DB index
     * @param string $lifetimeKey configuration key holding the TTL in seconds
     * @return array<string, mixed>
     */
    private function build(array $configuration, string $databaseKey, string $lifetimeKey): array
    {
        $cacheBackend = $configuration['cacheBackend'] ?? self::BACKEND_TRANSIENT;
        $wantsRedis = is_string($cacheBackend)
            && $cacheBackend === self::BACKEND_REDIS
            && extension_loaded('redis');

        if (!$wantsRedis) {
            return $this->transientConfiguration();
        }

        $database = $configuration[$databaseKey] ?? null;
        if (!is_numeric($database)) {
            // No database index configured -> do not pick one. Fall back to the
            // per-request cache so the Redis layout stays the site's decision.
            return $this->transientConfiguration();
        }

        $options = [
            'hostname' => $this->stringOption($configuration, 'redisHost', '127.0.0.1'),
            'port' => $this->intOption($configuration, 'redisPort', 6379),
            'database' => (int)$database,
            'defaultLifetime' => is_numeric($configuration[$lifetimeKey] ?? null)
                ? (int)$configuration[$lifetimeKey]
                : 0,
        ];
        $password = $configuration['redisPassword'] ?? null;
        if (is_string($password) && $password !== '') {
            $options['password'] = $password;
        }

        return [
            'backend' => RedisBackend::class,
            'frontend' => VariableFrontend::class,
            'options' => $options,
            'groups' => ['all'],
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function stringOption(array $configuration, string $key, string $default): string
    {
        $value = $configuration[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function intOption(array $configuration, string $key, int $default): int
    {
        $value = $configuration[$key] ?? null;
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function transientConfiguration(): array
    {
        return [
            'backend' => TransientMemoryBackend::class,
            'frontend' => VariableFrontend::class,
        ];
    }
}
