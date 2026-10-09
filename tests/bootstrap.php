<?php

declare(strict_types=1);

$autoload = is_file(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/../../../vendor/autoload.php';
$loader = require $autoload;
$loader->addPsr4('Survos\\StateBundle\\', __DIR__ . '/../src/');
$loader->addPsr4('Survos\\StateBundle\\Tests\\', __DIR__ . '/');
