<?php

// The "app" the webhook screenshots call back: answers every request with 200 or 204.
// Run by scripts/screenshots.ts with PHP's built-in server.

http_response_code(strpos((string) $_SERVER['REQUEST_URI'], '/twilio/') === 0 ? 204 : 200);
header('Content-Type: application/json');
echo strpos((string) $_SERVER['REQUEST_URI'], '/twilio/') === 0 ? '' : '{"received":true}';
