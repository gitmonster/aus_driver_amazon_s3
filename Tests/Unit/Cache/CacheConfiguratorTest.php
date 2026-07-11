<?php

/***
 *
 * This file is part of an extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 ***/

namespace AUS\AusDriverAmazonS3\Tests\Unit\Cache;

use AUS\AusDriverAmazonS3\Cache\CacheConfigurator;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;

/**
 * @package AUS\AusDriverAmazonS3\Tests\Unit\Cache
 */
class CacheConfiguratorTest extends TestCase
{
    private CacheConfigurator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CacheConfigurator();
    }

    /**
     * @test
     */
    public function defaultsToTransientMemoryBackendWhenUnconfigured()
    {
        $configuration = $this->subject->buildForMetaInfoCache([]);
        self::assertSame(TransientMemoryBackend::class, $configuration['backend']);
        self::assertArrayNotHasKey('options', $configuration);
    }

    /**
     * @test
     */
    public function explicitTransientBackendBuildsConfigurationWithoutOptions()
    {
        $configuration = $this->subject->buildForRequestCache(['cacheBackend' => CacheConfigurator::BACKEND_TRANSIENT]);
        self::assertSame(TransientMemoryBackend::class, $configuration['backend']);
        self::assertArrayNotHasKey('options', $configuration);
    }

    /**
     * @test
     */
    public function redisBackendUsesConfiguredDatabaseIndexLifetimeAndConnection()
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The redis PHP extension is not available.');
        }
        $configuration = [
            'cacheBackend' => CacheConfigurator::BACKEND_REDIS,
            'redisHost' => 'redis-host',
            'redisPort' => 6380,
            'redisPassword' => 'secret',
            'metaInfoCacheDatabase' => 7,
            'metaInfoCacheLifetime' => 3600,
            'requestCacheDatabase' => 8,
            'requestCacheLifetime' => 60,
        ];

        $metaInfo = $this->subject->buildForMetaInfoCache($configuration);
        self::assertSame(RedisBackend::class, $metaInfo['backend']);
        self::assertSame('redis-host', $metaInfo['options']['hostname']);
        self::assertSame(6380, $metaInfo['options']['port']);
        self::assertSame('secret', $metaInfo['options']['password']);
        self::assertSame(7, $metaInfo['options']['database']);
        self::assertSame(3600, $metaInfo['options']['defaultLifetime']);

        $request = $this->subject->buildForRequestCache($configuration);
        self::assertSame(RedisBackend::class, $request['backend']);
        self::assertSame(8, $request['options']['database']);
        self::assertSame(60, $request['options']['defaultLifetime']);
    }

    /**
     * @test
     */
    public function redisFallsBackToTransientWhenDatabaseIndexIsNotConfigured()
    {
        // No database index provided -> the extension must not guess one.
        $configuration = $this->subject->buildForMetaInfoCache(['cacheBackend' => CacheConfigurator::BACKEND_REDIS]);
        self::assertSame(TransientMemoryBackend::class, $configuration['backend']);
    }

    /**
     * @test
     */
    public function redisLifetimeDefaultsToZeroWhenNotConfigured()
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The redis PHP extension is not available.');
        }
        $configuration = $this->subject->buildForMetaInfoCache([
            'cacheBackend' => CacheConfigurator::BACKEND_REDIS,
            'metaInfoCacheDatabase' => 7,
        ]);
        self::assertSame(RedisBackend::class, $configuration['backend']);
        self::assertSame(0, $configuration['options']['defaultLifetime']);
    }

    /**
     * @test
     */
    public function redisConnectionFallsBackToDefaultHostAndPortWhenNotConfigured()
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The redis PHP extension is not available.');
        }
        $configuration = $this->subject->buildForMetaInfoCache([
            'cacheBackend' => CacheConfigurator::BACKEND_REDIS,
            'metaInfoCacheDatabase' => 7,
        ]);
        self::assertSame('127.0.0.1', $configuration['options']['hostname']);
        self::assertSame(6379, $configuration['options']['port']);
        self::assertArrayNotHasKey('password', $configuration['options']);
    }
}
