<?php

$root = dirname(__DIR__, 2);
$loader = require $root . '/.work/panel/vendor/autoload.php';
$loader->addPsr4('ShookEagle\\BetterPelicanSecrets\\', $root . '/plugins/better-pelican-secrets/src/');
