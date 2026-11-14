<?php
declare(strict_types=1);

// Square webhook endpoint. Register this URL in the Square Developer Dashboard (Webhooks) and subscribe to:
// subscription.created, subscription.updated, payment.created, payment.updated, invoice.payment_made, invoice.scheduled_charge_failed
require dirname(__DIR__) . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$body = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';
if (!Square::validSignature($body, $sig, (string) cfg('square.webhook_url'), (string) cfg('square.webhook_signature_key'))) {
    http_response_code(403);
    exit('bad signature');
}
$event = json_decode($body, true);
if (!is_array($event)) {
    http_response_code(400);
    exit('bad json');
}
try {
    $result = Square::handleEvent($event);
    http_response_code(200);
    echo $result;
} catch (Throwable $e) {
    error_log('Square webhook error: ' . $e->getMessage());
    http_response_code(500); // Square retries
}
