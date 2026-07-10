<?php

/** @var string $_EXTKEY */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Amazon AWS S3 FAL driver (CDN)',
    'description' => 'Provides a FAL driver for the Amazon Web Service S3.',
    'category' => 'be',
    'version' => '2.0.0',
    'state' => 'stable',
    'uploadfolder' => false,
    'createDirs' => '',
    'clearcacheonload' => false,
    'author' => 'Markus Hölzle',
    'author_email' => 'typo3@markus-hoelzle.de',
    'author_company' => 'different.technology',
    'constraints' =>
        [
            'depends' =>
                [
                    'typo3' => '13.4.0-14.99.99',
                    'aws_sdk_php' => '3.356.0-3.999.999',
                ],
            'conflicts' => [],
            'suggests' => [],
        ],
];
