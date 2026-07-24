<?php
// IMPORTANT: Start output buffering immediately.
// This prevents "headers already sent" errors caused by session_start()
// or header() calls in required files, which is common in serverless environments.
ob_start();

// Set working directory to the root of the project so relative file references function correctly.
chdir(__DIR__ . '/..');

// Route request to the appropriate PHP file
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove leading slash for matching file path
$file = ltrim($uri, '/');

// Default to index.php if empty
if ($file === '') {
    $file = 'index.php';
}

// Security checks:
// 1. Prevent directory traversal attacks
if (strpos($file, '..') !== false) {
    http_response_code(403);
    ob_end_clean();
    die('Access Denied');
}

// 2. Block direct access to config/ or vendor/ files
if (strpos($file, 'config/') === 0 || strpos($file, 'vendor/') === 0) {
    http_response_code(403);
    ob_end_clean();
    die('Access Denied');
}

// Execute the requested PHP script if it exists
if (file_exists($file) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
    require $file;
} else {
    // If the file doesn't exist, search for it with .php extension (e.g. /login -> login.php)
    if (file_exists($file . '.php') && is_file($file . '.php')) {
        require $file . '.php';
    } else {
        http_response_code(404);
        echo "404 Not Found: " . htmlspecialchars($uri);
    }
}

// Flush the output buffer
ob_end_flush();

