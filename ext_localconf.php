<?php

defined('TYPO3') or die();

$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Driver\DriverRegistry::class);
$driverRegistry->registerDriverClass(
    \AUS\AusDriverAmazonS3\Driver\AmazonS3Driver::class,
    \AUS\AusDriverAmazonS3\Driver\AmazonS3Driver::DRIVER_TYPE,
    'AWS S3',
    'FILE:EXT:' . \AUS\AusDriverAmazonS3\Driver\AmazonS3Driver::EXTENSION_KEY . '/Configuration/FlexForm/AmazonS3DriverFlexForm.xml'
);

// register extractor
\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Index\ExtractorRegistry::class)->registerExtractionService(\AUS\AusDriverAmazonS3\Index\Extractor::class);

// Configure the driver's internal caches. This MUST happen in ext_localconf
// (early bootstrap) because TYPO3 compiles cacheConfigurations at bootstrap
// time; configuring later (e.g. from per-storage FlexForm in the driver) is too
// late. The values come from the extension settings (ext_conf_template.txt and
// $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aus_driver_amazon_s3'] set via
// AdditionalConfiguration.php). The extension reads no environment variables.
$extensionSettings = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aus_driver_amazon_s3'] ?? [];
$cacheConfigurator = new \AUS\AusDriverAmazonS3\Cache\CacheConfigurator();
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ausdriveramazons3_metainfocache']
    = $cacheConfigurator->buildForMetaInfoCache($extensionSettings);
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ausdriveramazons3_requestcache']
    = $cacheConfigurator->buildForRequestCache($extensionSettings);
