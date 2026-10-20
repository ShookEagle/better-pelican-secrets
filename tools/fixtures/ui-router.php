<?php

use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Http\Request;

// Static assets use the native PHP development server; application paths use Panel.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
if ($path !== '/' && is_file('/workspace/.work/panel/public' . $path)) {
    return false;
}
define('LARAVEL_START', microtime(true));
require '/workspace/.work/panel/vendor/autoload.php';
$app = require '/workspace/.work/panel/bootstrap/app.php';
$app->afterBootstrapping(BootProviders::class, function () {
    require '/workspace/tools/fixtures/ui-node.php';
});
$app->handleRequest(Request::capture());
