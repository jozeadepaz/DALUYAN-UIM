<?php
declare(strict_types=1);

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($uri === '/') {
    require __DIR__ . DIRECTORY_SEPARATOR . 'DALUYAN-UIM.html';
    return true;
}

$requestedPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($uri, '/\\'));
$rootPath = realpath(__DIR__);
if ($requestedPath === false || $rootPath === false || !str_starts_with($requestedPath, $rootPath . DIRECTORY_SEPARATOR) || is_dir($requestedPath) || strtolower(pathinfo($requestedPath, PATHINFO_EXTENSION)) === 'json') {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

return false;
