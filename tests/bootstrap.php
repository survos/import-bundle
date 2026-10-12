<?php
declare(strict_types=1);

// Support both a standalone bundle install and the monorepo test environment.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
}
$loader = require $autoload;
$loader->addPsr4('Survos\\ImportBundle\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('Survos\\ImportBundle\\Tests\\', __DIR__);
