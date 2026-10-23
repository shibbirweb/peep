<?php

// Writes the log fixtures with a real Monolog, formatted the way Laravel formats storage/logs
// (LineFormatter with line breaks allowed and stack traces), so LogParserTest reads what each
// Laravel version writes: Monolog 1 (Laravel 5.5 to 5.8), 2 (6 to 9) and 3 (10 and later).
//
//     php make.php /path/to/monolog-1/vendor/autoload.php > monolog-1.log
//
// Times are fixed and this folder is shown as /app, so the files only change with Monolog.

require $argv[1];

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$stream = fopen('php://memory', 'w+');
$handler = new StreamHandler($stream);
$formatter = new LineFormatter(null, 'Y-m-d H:i:s', true, true);
$formatter->includeStacktraces();
$handler->setFormatter($formatter);

$second = 0;
$monolog3 = class_exists('Monolog\LogRecord');
$fixTime = function ($record) use (&$second, $monolog3) {
    // Monolog 1 formats only DateTime; 2 and 3 any DateTimeInterface.
    $time = $monolog3 ? new DateTimeImmutable('2026-10-20 10:15:0' . $second++, new DateTimeZone('UTC')) : new DateTime('2026-10-20 10:15:0' . $second++, new DateTimeZone('UTC'));

    // Named arguments for LogRecord::with(), in a form PHP 7 (Monolog 1) can still parse.
    return $monolog3 ? call_user_func_array([$record, 'with'], ['datetime' => $time]) : array_merge($record, ['datetime' => $time]);
};
$addExtra = function ($record) use ($monolog3) {
    return $monolog3 ? call_user_func_array([$record, 'with'], ['extra' => ['request_id' => 'req-7']]) : array_merge($record, ['extra' => ['request_id' => 'req-7']]);
};

$log = new Logger('local', [$handler], [$fixTime]);
$tagged = new Logger('local', [$handler], [$fixTime, $addExtra]);

function charge()
{
    throw new RuntimeException('Card declined', 402, new LogicException('Card expired'));
}

$log->info('User signed in', ['user_id' => 18, 'roles' => ['admin', 'editor']]);
$log->debug('Cache miss {key}');
try {
    charge();
} catch (Throwable $exception) {
    $log->error('Payment failed', ['order' => 18, 'exception' => $exception]);
}
$log->warning("Multi\nline message", ['nested' => ['a' => [1, 2, ['deep' => true]]]]);
$tagged->info('Queued job', ['job' => 'SendInvoice']);
$log->critical('Résumé upload failed ✓', ['size' => 1.5]);

rewind($stream);
echo str_replace(__DIR__, '/app', (string) stream_get_contents($stream));
