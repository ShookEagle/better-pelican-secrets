<?php

// Static assets use the native PHP development server; application paths use Panel.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
if ($path !== '/' && is_file('/workspace/.work/panel/public' . $path)) {
    return false;
}
require '/workspace/.work/panel/public/index.php';
