<?php declare(strict_types=1);

// Right now this only checks if the PHP version is sufficient to run the app, but in
// the future it could be expanded to check for required PHP extensions, permissions,
// or other environment factors. Without this check, users on unsupported PHP versions
// would see an unhelpful error.
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    header('Content-Type: application/json', true, 500);
    echo json_encode([
        'error' => 'PHP 8.3 or higher is required to run Quermy. Current version: ' . PHP_VERSION,
    ]);

    exit;
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
exit;
