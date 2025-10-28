<?php
declare(strict_types=1);

// Composer autoload
require dirname(__DIR__) . '/vendor/autoload.php';

// Define KERNEL constant for Symfony-friendly setups
if (!defined('KERNEL')) {
    define('KERNEL', 'App\Kernel');
}

// If you keep an App\Kernel around in this repo, it will be autoloadable.
// Not strictly required for these unit tests.
