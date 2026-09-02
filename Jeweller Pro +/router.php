<?php
// Lightweight Router for Local PHP Built-in Development Server
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// 1. Redirect legacy /nongst to /geomorphology
if ($uri === '/nongst' || $uri === '/nongst/') {
    header("Location: /geomorphology", true, 301);
    exit();
}
if (strpos($uri, '/nongst/') === 0) {
    $geo_target = '/geomorphology/' . substr($uri, 8);
    header("Location: " . $geo_target, true, 301);
    exit();
}

// 2. If static file exists (and is not a directory), serve it directly
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// 3. Geomorphology subroutes (e.g. /geomorphology/reports -> geomorphology/reports.php)
if (strpos($uri, '/geomorphology/') === 0) {
    $sub = substr($uri, 15);
    $sub = rtrim($sub, '/');
    if ($sub !== '' && file_exists(__DIR__ . '/geomorphology/' . $sub . '.php')) {
        require __DIR__ . '/geomorphology/' . $sub . '.php';
        return true;
    }
}

// 4. If directory index exists (e.g. /geomorphology or /geomorphology/)
$trimmed_uri = rtrim($uri, '/');
if ($trimmed_uri !== '' && is_dir(__DIR__ . $trimmed_uri) && file_exists(__DIR__ . $trimmed_uri . '/index.php')) {
    require __DIR__ . $trimmed_uri . '/index.php';
    return true;
}

// 5. If extensionless PHP file exists, require it
if ($uri !== '/' && file_exists(__DIR__ . $uri . '.php')) {
    require __DIR__ . $uri . '.php';
    return true;
}

// 6. Default index route
if (($uri === '/' || $uri === '') && file_exists(__DIR__ . '/index.php')) {
    require __DIR__ . '/index.php';
    return true;
}

return false;

