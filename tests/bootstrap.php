<?php

$vito = getenv('VITO_PATH');

if (! is_string($vito) || ! is_file($vito.'/vendor/autoload.php')) {
    throw new RuntimeException('Set VITO_PATH to a VitoDeploy 4.x checkout with Composer dependencies installed.');
}

$loader = require $vito.'/vendor/autoload.php';
$loader->addPsr4('App\\Vito\\Plugins\\Dyanakiev\\VitoVpsbgPlugin\\', dirname(__DIR__).'/');
$loader->addPsr4('Vpsbg\\Tests\\', __DIR__.'/');

require_once __DIR__.'/Pest.php';
