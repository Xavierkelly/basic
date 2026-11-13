<?php
// Square webhook signature and status mapping (no network, no database).
require_once __DIR__ . '/../src/Square.php';

$key = 'test-signature-key';
$url = 'https://example.test/square-webhook.php';
$body = '{"type":"subscription.updated","event_id":"e1"}';
$sig = base64_encode(hash_hmac('sha256', $url . $body, $key, true));
check(Square::validSignature($body, $sig, $url, $key), 'valid Square signature accepted');
check(!Square::validSignature($body . ' ', $sig, $url, $key), 'tampered body rejected');
check(!Square::validSignature($body, $sig, $url . 'x', $key), 'wrong URL rejected');
check(!Square::validSignature($body, '', $url, $key), 'missing signature rejected');
check(!Square::validSignature($body, $sig, $url, ''), 'unconfigured key rejects everything');
check(Square::mapStatus('ACTIVE') === 'active', 'ACTIVE → active');
check(Square::mapStatus('PAUSED') === 'past_due', 'PAUSED → past_due');
check(Square::mapStatus('CANCELED') === 'canceled', 'CANCELED → canceled');
check(Square::mapStatus('DEACTIVATED') === 'canceled', 'DEACTIVATED → canceled');
