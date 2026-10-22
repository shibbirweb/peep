<?php

// Demo data for the wiki screenshots: what a small shop app sends during a checkout.
// Run by scripts/screenshots.ts with PEEP_PORT set; not part of the package.

require __DIR__ . '/../../vendor/autoload.php';

use ShibbirWeb\Peep\PeepFactory;

$port = (int) getenv('PEEP_PORT');
putenv('PEEP_ENABLED=true');
putenv('PEEP_PROJECT=shop');

// The cards point at the demo app's files, shown as if the app lived in /Users/you/code/shop.
putenv('PEEP_REMOTE_PATH=' . __DIR__ . '/app');
putenv('PEEP_LOCAL_PATH=/Users/you/code/shop');

foreach (glob(__DIR__ . '/app/app/{Http/Controllers,Jobs,Console/Commands}/*.php', GLOB_BRACE) ?: [] as $file) {
    require $file;
}

$request = static function (string $kind, string $label): void {
    PeepFactory::instance()->context()->begin($kind, $label);
};

$request('http', 'GET /products/espresso-beans');
(new App\Http\Controllers\ProductController())->show('espresso-beans');

$request('http', 'POST /checkout');
(new App\Http\Controllers\CheckoutController())->store(['total' => 27.5]);

$request('job', 'App\\Jobs\\SendInvoice');
(new App\Jobs\SendInvoice())->handle();

// Another project, to show the project tabs.
putenv('PEEP_PROJECT=blog');
PeepFactory::setInstance(null);
$request('cli', 'artisan posts:publish');
(new App\Console\Commands\PublishPosts())->handle();

// Mail and SMS.
$post = static function (string $path, array $body, array $headers = []) use ($port): void {
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => array_merge(['Content-Type: application/json'], $headers),
        'content' => json_encode($body),
        'ignore_errors' => true,
    ]]);
    file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
};

$html = '<!doctype html><html><body style="font-family:system-ui,sans-serif;color:#1c2024;margin:0;padding:24px;background:#f6f7f9">'
    . '<div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;padding:24px;border:1px solid #e2e5ea">'
    . '<h1 style="color:#5b5bd6;font-size:22px;margin:0 0 8px">Thanks for your order!</h1>'
    . '<p>Order <b>#1042</b> is confirmed and on its way.</p>'
    . '<table style="width:100%;border-collapse:collapse;margin:16px 0"><tr><td style="padding:6px 0">Espresso beans</td><td style="text-align:right">$18.00</td></tr>'
    . '<tr><td style="padding:6px 0">Mug</td><td style="text-align:right">$9.50</td></tr>'
    . '<tr><td style="padding:6px 0;border-top:1px solid #e2e5ea"><b>Total</b></td><td style="text-align:right;border-top:1px solid #e2e5ea"><b>$27.50</b></td></tr></table>'
    . '<p><a href="https://shop.test/orders/1042" style="background:#5b5bd6;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">View order</a></p>'
    . '</div></body></html>';

$post('/_peep/mail', ['from' => 'Shop <orders@shop.test>', 'to' => 'Ada Lovelace <ada@example.test>', 'subject' => 'Order #1042 confirmed', 'html' => $html, 'text' => 'Thanks for your order! Order #1042 is confirmed.', 'project' => 'shop']);
$post('/_peep/mail', ['from' => 'Shop <news@shop.test>', 'to' => 'grace@example.test', 'subject' => 'Weekly deals: 20% off mugs', 'text' => 'Deals this week: 20% off every mug.', 'project' => 'shop']);
$post('/_peep/sms', ['to' => '+15551230001', 'from' => 'Shop', 'body' => 'Your verification code is 482913', 'project' => 'shop', 'status_callback' => 'http://127.0.0.1:' . getenv('APP_PORT') . '/twilio/status']);
$post('/_peep/sms', ['to' => '+15551230001', 'from' => 'Shop', 'body' => 'Order #1042 has shipped 📦 Track it at https://shop.test/t/1042', 'project' => 'shop']);
$post('/_peep/sms', ['to' => '+447700900123', 'from' => 'Shop', 'body' => str_repeat('Your order is on its way. ', 8), 'project' => 'shop']);
