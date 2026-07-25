<?php
// Lightweight Router for Local PHP Built-in Development Server
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// 1. If static file exists, serve it directly
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// 2. If extensionless PHP file exists, require it
if ($uri !== '/' && file_exists(__DIR__ . $uri . '.php')) {
    require __DIR__ . $uri . '.php';
    return true;
}

// 3. Default index route
if (($uri === '/' || $uri === '') && file_exists(__DIR__ . '/index.php')) {
    require __DIR__ . '/index.php';
    return true;
}

return false;
