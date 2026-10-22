<?php

// Router script for PHP's built-in web server, started by FakeViewer.
// Records each request as one JSON line and answers with PEEP_FAKE_VIEWER_STATUS.

$logFile = getenv('PEEP_FAKE_VIEWER_LOG');
$status = (int) getenv('PEEP_FAKE_VIEWER_STATUS');

if (is_string($logFile) && $logFile !== '') {
    $record = [
        'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        'path' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        'content_type' => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
        'token' => isset($_SERVER['HTTP_X_PEEP_TOKEN']) ? $_SERVER['HTTP_X_PEEP_TOKEN'] : null,
        'body' => (string) file_get_contents('php://input'),
    ];

    file_put_contents($logFile, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
}

http_response_code($status > 0 ? $status : 202);
header('Content-Type: application/json');

echo json_encode(['accepted' => $status < 300]);
