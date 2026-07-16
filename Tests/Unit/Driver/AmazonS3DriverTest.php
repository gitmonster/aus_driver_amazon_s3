<?php

/***
 *
 * This file is part of an extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2020 Markus Hölzle <typo3@markus-hoelzle.de>
 *
 ***/

namespace AUS\AusDriverAmazonS3\Tests\Unit\Driver;

use AUS\AusDriverAmazonS3\Driver\AmazonS3Driver;
use Aws\Api\DateTimeResult;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AmazonS3DriverTest
 *
 * @author Markus Hölzle <typo3@markus-hoelzle.de>
 * @package AUS\AusDriverAmazonS3\Tests\Unit\Driver
 */
class AmazonS3DriverTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var AmazonS3Driver
     */
    protected $driver = null;

    /**
     * @var ObjectProphecy
     */
    protected $s3Client = null;

    /**
     * @var ObjectProphecy
     */
    protected $eventDispatcher = null;

    /**
     * @var string[]
     */
    protected array $tempCacheDirs = [];

    /**
     * @var string[]
     */
    protected $testConfiguration = [
        'protocol' => 'https://',
        'publicBaseUrl' => 'www.example.com',
        'bucket' => 'test-bucket',
        'region' => 'test-region',
        'key' => 'test-key',
        'secretKey' => 'test-secretKey',
    ];

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][AmazonS3Driver::EXTENSION_KEY] = [];
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [];
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['FileInfo']['fileExtensionToMimeType']['youtube'] = 'video/youtube';

        Environment::initialize(
            $this->prophesize(ApplicationContext::class)->reveal(),
            false,
            true,
            '',
            '',
            rtrim(sys_get_temp_dir(), '/') . '/aus-driver-var',
            '',
            '',
            ''
        );
        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getAttribute('applicationType')->willReturn(SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $request->reveal();
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cacheManager->setCacheConfigurations([
            'ausdriveramazons3_metainfocache' => [
                'backend' => \TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend::class,
                'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            ],
            'ausdriveramazons3_requestcache' => [
                'backend' => \TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend::class,
                'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            ]
        ]);
        $this->s3Client = $this->prophesize(S3Client::class);
        $eventDispatcher = $this->prophesize(EventDispatcher::class);
        $this->eventDispatcher = $eventDispatcher;
        $pageRenderer = $this->prophesize(PageRenderer::class);
        GeneralUtility::setSingletonInstance(PageRenderer::class, $pageRenderer->reveal());
        $this->driver = new AmazonS3Driver($this->testConfiguration, $this->s3Client->reveal(), $eventDispatcher->reveal());
        $this->driver->setStorageUid(42);
        $this->driver->initialize();
    }

    public function tearDown(): void
    {
        foreach ($this->tempCacheDirs as $dir) {
            if (is_dir($dir)) {
                GeneralUtility::rmdir($dir, true);
            }
        }
        $this->tempCacheDirs = [];
        unset($GLOBALS['TYPO3_REQUEST']);
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function testPublicUrlGetter()
    {
        $assertedMappings = [
            '/foo/bar/test.file' => 'https://www.example.com/foo/bar/test.file', // start with slash
            'foo/bar/test.file' => 'https://www.example.com/foo/bar/test.file', // start without slash
            '/foo//bar/test.file' => 'https://www.example.com/foo/bar/test.file', // duplicated slash in path
            '/test.file' => 'https://www.example.com/test.file', // file in root, starts with slash
            'test.file' => 'https://www.example.com/test.file', // file in root, starts without slash
        ];
        foreach ($assertedMappings as $input => $expectedOutput) {
            $this->assertEquals($expectedOutput, $this->driver->getPublicUrl($input));
        }
    }

    /**
     * @test
     */
    public function testDefaultFolderGetter()
    {
        $this->assertEquals('/', $this->driver->getDefaultFolder());
    }

    /**
     * @test
     */
    public function testRootLevelFolderGetter()
    {
        $this->assertEquals('/', $this->driver->getRootLevelFolder());
    }

    /**
     * @test
     */
    public function testGetFileInfoByIdentifier()
    {
        $fileIdentifier = 'foo/bar/test.file';
        $lastModifiedDateTime = new DateTimeResult();
        $result = new Result([
            'LastModified' => $lastModifiedDateTime,
            'ContentType' => 'image/png',
            'ContentLength' => 123,
        ]);
        $expectedInfoKeys = ['name', 'identifier', 'ctime', 'mtime', 'extension', 'mimetype', 'size', 'identifier_hash', 'folder_hash', 'storage'];
        $this->s3Client->headObject([
            'Bucket' => $this->testConfiguration['bucket'],
            'Key' => $fileIdentifier
        ])->willReturn($result);
        $info = $this->driver->getFileInfoByIdentifier($fileIdentifier);
        $infoKeys = array_keys($info);

        sort($infoKeys);
        sort($expectedInfoKeys);
        $this->assertEquals($expectedInfoKeys, $infoKeys);
        $this->assertEquals(basename($fileIdentifier), $info['name']);
        $this->assertEquals($fileIdentifier, $info['identifier']);
        $this->assertEquals($lastModifiedDateTime->getTimestamp(), $info['ctime']);
        $this->assertEquals($lastModifiedDateTime->getTimestamp(), $info['mtime']);
        $this->assertEquals(sha1('/' . $fileIdentifier), $info['identifier_hash']);
        $this->assertEquals(sha1('/' . dirname($fileIdentifier)), $info['folder_hash']);
        $this->assertEquals('file', $info['extension']);
        $this->assertEquals('image/png', $info['mimetype']);
        $this->assertEquals(123, $info['size']);
        $this->assertEquals($this->driver->getStorageUid(), $info['storage']);
    }

    /**
     * @test
     */
    public function testGetFileInfoByIdentifierWithLimitedProperties()
    {
        $fileIdentifier = 'foo/bar/test.file';
        $properties = ['name', 'identifier', 'size', 'storage'];
        $result = new Result([
            'LastModified' => new DateTimeResult(),
            'ContentType' => 'image/png',
            'ContentLength' => 123,
        ]);
        $this->s3Client->headObject([
            'Bucket' => $this->testConfiguration['bucket'],
            'Key' => $fileIdentifier
        ])->willReturn($result);
        $info = $this->driver->getFileInfoByIdentifier($fileIdentifier, $properties);
        $infoKeys = array_keys($info);
        sort($infoKeys);
        sort($properties);
        $this->assertEquals($properties, $infoKeys);
    }


    /**
     * @test
     */
    public function testGetFileInfoByIdentifierWithPseudoMimeType()
    {
        $fileIdentifier = 'foo/bar/test.youtube';
        $lastModifiedDateTime = new DateTimeResult();
        $result = new Result([
            'LastModified' => $lastModifiedDateTime,
            'ContentType' => 'text/plain',
            'ContentLength' => 12345,
        ]);
        $expectedInfoKeys = ['name', 'identifier', 'ctime', 'mtime', 'extension', 'mimetype', 'size', 'identifier_hash', 'folder_hash', 'storage'];
        $this->s3Client->headObject([
            'Bucket' => $this->testConfiguration['bucket'],
            'Key' => $fileIdentifier
        ])->willReturn($result);
        $info = $this->driver->getFileInfoByIdentifier($fileIdentifier);
        $infoKeys = array_keys($info);

        sort($infoKeys);
        sort($expectedInfoKeys);
        $this->assertEquals($expectedInfoKeys, $infoKeys);
        $this->assertEquals(basename($fileIdentifier), $info['name']);
        $this->assertEquals($fileIdentifier, $info['identifier']);
        $this->assertEquals($lastModifiedDateTime->getTimestamp(), $info['ctime']);
        $this->assertEquals($lastModifiedDateTime->getTimestamp(), $info['mtime']);
        $this->assertEquals(sha1('/' . $fileIdentifier), $info['identifier_hash']);
        $this->assertEquals(sha1('/' . dirname($fileIdentifier)), $info['folder_hash']);
        $this->assertEquals('youtube', $info['extension']);
        $this->assertEquals('video/youtube', $info['mimetype']);
        $this->assertEquals(12345, $info['size']);
        $this->assertEquals($this->driver->getStorageUid(), $info['storage']);
    }

    /**
     * Read-only local processing: helpers for the two-layer strategy tests below.
     */
    protected function setDriverConfiguration(string $key, mixed $value): void
    {
        $reflection = new \ReflectionProperty(AmazonS3Driver::class, 'configuration');
        $reflection->setAccessible(true);
        $configuration = $reflection->getValue($this->driver);
        $configuration[$key] = $value;
        $reflection->setValue($this->driver, $configuration);
    }

    protected function useLocalProcessingCacheDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/') . '/aus_driver_test_' . bin2hex(random_bytes(4));
        $this->tempCacheDirs[] = $directory;
        $this->setDriverConfiguration('localProcessingCacheDirectory', $directory);
        return $directory . '/';
    }

    /**
     * @test
     */
    public function readOnlyProcessedFileIsSkippedAndServedFromPublicUrl(): void
    {
        $calls = 0;
        $this->s3Client->getObject(Argument::cetera())->will(function ($args) use (&$calls): Result {
            $calls++;
            return new Result([]);
        });

        // The skip prefix defaults to "_processed_/" when the setting is absent.
        $result = $this->driver->getFileForLocalProcessing('/_processed_/csm_image_abc.jpg', false);

        $this->assertSame('https://www.example.com/_processed_/csm_image_abc.jpg', $result);
        $this->assertSame(0, $calls, 'Skip layer must not trigger an S3 download');
    }

    /**
     * @test
     */
    public function readOnlyOriginalFileIsCachedOnColdMiss(): void
    {
        $cacheDirectory = $this->useLocalProcessingCacheDirectory();
        $identifier = '/originals/photo.jpg';
        $expectedPath = $cacheDirectory . hash('sha256', ltrim($identifier, '/'));

        $this->s3Client->getObject(Argument::cetera())->will(function ($args): Result {
            $params = $args[0];
            file_put_contents($params['SaveAs'], 'original-bytes');
            return new Result(['ETag' => '"etag-1"', 'LastModified' => new DateTimeResult('2024-01-01T00:00:00Z')]);
        });

        $result = $this->driver->getFileForLocalProcessing($identifier, false);

        $this->assertSame($expectedPath, $result);
        $this->assertFileExists($expectedPath);
        $this->assertSame('original-bytes', file_get_contents($expectedPath));
    }

    /**
     * @test
     */
    public function strictRevalidationServesCacheOnNotModified(): void
    {
        $this->useLocalProcessingCacheDirectory();
        $identifier = '/originals/photo.jpg';

        $this->s3Client->getObject(Argument::cetera())->will(function ($args): Result {
            $params = $args[0];
            if (isset($params['IfNoneMatch'])) {
                // S3 returns 304 when the object is unchanged; the AWS SDK raises it as an exception.
                throw new \Exception('Not Modified', 304);
            }
            file_put_contents($params['SaveAs'], 'original-bytes');
            return new Result(['ETag' => '"etag-1"', 'LastModified' => new DateTimeResult('2024-01-01T00:00:00Z')]);
        });

        $first = $this->driver->getFileForLocalProcessing($identifier, false);
        $second = $this->driver->getFileForLocalProcessing($identifier, false);

        $this->assertSame($first, $second);
        $this->assertFileExists($second);
        $this->assertSame('original-bytes', file_get_contents($second), 'Cached file must not be overwritten on a 304 response');
    }

    /**
     * @test
     */
    public function strictRevalidationOverwritesOnModified(): void
    {
        $this->useLocalProcessingCacheDirectory();
        $identifier = '/originals/photo.jpg';

        $this->s3Client->getObject(Argument::cetera())->will(function ($args): Result {
            $params = $args[0];
            if (isset($params['IfNoneMatch'])) {
                file_put_contents($params['SaveAs'], 'updated-bytes');
                return new Result(['ETag' => '"etag-2"', 'LastModified' => new DateTimeResult('2024-02-01T00:00:00Z')]);
            }
            file_put_contents($params['SaveAs'], 'original-bytes');
            return new Result(['ETag' => '"etag-1"', 'LastModified' => new DateTimeResult('2024-01-01T00:00:00Z')]);
        });

        $first = $this->driver->getFileForLocalProcessing($identifier, false);
        $second = $this->driver->getFileForLocalProcessing($identifier, false);

        $this->assertSame($first, $second);
        $this->assertSame('updated-bytes', file_get_contents($second), 'Cached file must be overwritten when S3 reports a change');
    }

    /**
     * @test
     */
    public function eventualModeServesCacheWithoutRevalidation(): void
    {
        $this->useLocalProcessingCacheDirectory();
        $this->setDriverConfiguration('localProcessingCacheRevalidation', 'eventual');
        $identifier = '/originals/photo.jpg';

        $calls = 0;
        $this->s3Client->getObject(Argument::cetera())->will(function ($args) use (&$calls): Result {
            $calls++;
            $params = $args[0];
            file_put_contents($params['SaveAs'], 'original-bytes');
            return new Result(['ETag' => '"etag-1"', 'LastModified' => new DateTimeResult('2024-01-01T00:00:00Z')]);
        });

        $first = $this->driver->getFileForLocalProcessing($identifier, false);
        $second = $this->driver->getFileForLocalProcessing($identifier, false);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls, 'Eventual mode must not revalidate an existing cache entry');
    }

    /**
     * @test
     */
    public function writableAccessAlwaysDownloadsFreshTempFile(): void
    {
        $cacheDirectory = $this->useLocalProcessingCacheDirectory();
        $identifier = '/originals/photo.jpg';
        $cachePath = $cacheDirectory . hash('sha256', ltrim($identifier, '/'));

        $this->eventDispatcher->dispatch(Argument::cetera())->will(function ($args) {
            return $args[0];
        });
        $this->s3Client->getObject(Argument::cetera())->will(function ($args): Result {
            $params = $args[0];
            file_put_contents($params['SaveAs'], 'writable-bytes');
            return new Result(['ETag' => '"etag-1"', 'LastModified' => new DateTimeResult('2024-01-01T00:00:00Z')]);
        });

        $result = $this->driver->getFileForLocalProcessing($identifier, true);

        $this->assertNotSame($cachePath, $result, 'Writable access must not return the persistent cache path');
        $this->assertFileExists($result);
        $this->assertSame('writable-bytes', file_get_contents($result));
    }
}
