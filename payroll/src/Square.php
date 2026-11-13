<?php
declare(strict_types=1);

/**
 * Square subscription billing using Square's hosted checkout (no card data touches this server).
 * Flow: owner clicks Subscribe → we create a Payment Link tied to the subscription plan → Square
 * collects the card and starts the subscription → Square webhooks update our subscriptions table.
 */
final class Square
{
    public const API_VERSION = '2025-10-16';

    /** @var callable|null fn(string $method, string $path, ?array $body): array — swapped in tests */
    public static $transport = null;

    public static function configured(): bool
    {
        return (bool) (cfg('square.access_token') && cfg('square.location_id') && cfg('square.plan_variation_id'));
    }

    private static function base(): string
    {
        return cfg('square.environment') === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    }

    public static function request(string $method, string $path, ?array $body = null): array
    {
        if (self::$transport) {
            return (self::$transport)($method, $path, $body);
        }
        $ch = curl_init(self::base() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . cfg('square.access_token'),
                'Square-Version: ' . self::API_VERSION,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Could not reach Square: ' . $err);
        }
        $json = json_decode($raw, true) ?: [];
        if ($code >= 400) {
            $detail = $json['errors'][0]['detail'] ?? ('HTTP ' . $code);
            throw new RuntimeException('Square said: ' . $detail);
        }
        return $json;
    }

    /** Create a hosted checkout link that starts the subscription for this company. Returns the URL. */
    public static function checkoutUrl(array $company, array $owner): string
    {
        $res = self::request('POST', '/v2/online-checkout/payment-links', [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'quick_pay' => [
                'name' => (string) cfg('square.plan_name', 'Payroll subscription'),
                'price_money' => ['amount' => (int) cfg('square.plan_price_cents'), 'currency' => 'USD'],
                'location_id' => (string) cfg('square.location_id'),
            ],
            'checkout_options' => [
                'subscription_plan_id' => (string) cfg('square.plan_variation_id'),
                'redirect_url' => rtrim((string) cfg('base_url'), '/') . '/index.php?p=billing&done=1',
            ],
            'pre_populated_data' => ['buyer_email' => $owner['email']],
            'payment_note' => 'Company #' . $company['id'],
        ]);
        $link = $res['payment_link'] ?? null;
        if (!$link || empty($link['url'])) {
            throw new RuntimeException('Square did not return a checkout link.');
        }
        Db::exec(
            'UPDATE subscriptions SET square_payment_link_id = ?, square_order_id = ? WHERE company_id = ?',
            [$link['id'] ?? null, $link['order_id'] ?? null, $company['id']]
        );
        return $link['url'];
    }

    public static function cancel(array $sub): void
    {
        if (!$sub['square_subscription_id']) {
            throw new RuntimeException('There is no Square subscription to cancel.');
        }
        $res = self::request('POST', '/v2/subscriptions/' . rawurlencode($sub['square_subscription_id']) . '/cancel');
        if (isset($res['subscription'])) {
            self::applySubscription((int) $sub['company_id'], $res['subscription']);
        }
    }

    /** Square signs webhooks with HMAC-SHA256 over (notification URL + raw body), base64 encoded. */
    public static function validSignature(string $body, string $signature, string $url, string $key): bool
    {
        if ($key === '' || $signature === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $url . $body, $key, true));
        return hash_equals($expected, $signature);
    }

    public static function mapStatus(string $squareStatus): string
    {
        return match (strtoupper($squareStatus)) {
            'ACTIVE' => 'active',
            'PENDING' => 'trialing',
            'PAUSED' => 'past_due',
            default => 'canceled', // CANCELED, DEACTIVATED
        };
    }

    public static function applySubscription(int $companyId, array $s): void
    {
        Db::exec(
            'UPDATE subscriptions SET square_subscription_id = ?, square_customer_id = COALESCE(?, square_customer_id),
                    status = ?, current_period_end = ? WHERE company_id = ?',
            [
                $s['id'] ?? null,
                $s['customer_id'] ?? null,
                self::mapStatus((string) ($s['status'] ?? '')),
                !empty($s['charged_through_date']) ? $s['charged_through_date'] . ' 23:59:59' : null,
                $companyId,
            ]
        );
    }

    /** Handle one verified webhook event. Returns a short description for logging. */
    public static function handleEvent(array $event): string
    {
        $id = (string) ($event['event_id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if ($id !== '' && Db::value('SELECT event_id FROM square_events WHERE event_id = ?', [$id])) {
            return 'duplicate';
        }
        $obj = $event['data']['object'] ?? [];
        $result = 'ignored';
        if (str_starts_with($type, 'payment.') && isset($obj['payment'])) {
            // Ties the checkout's order to the company so later subscription events can be matched by customer.
            $p = $obj['payment'];
            $cid = $p['order_id'] ?? null ? Db::value('SELECT company_id FROM subscriptions WHERE square_order_id = ?', [$p['order_id']]) : null;
            if ($cid && !empty($p['customer_id'])) {
                Db::exec('UPDATE subscriptions SET square_customer_id = ? WHERE company_id = ?', [$p['customer_id'], $cid]);
                $result = "linked customer to company $cid";
            }
        } elseif (str_starts_with($type, 'subscription.') && isset($obj['subscription'])) {
            $s = $obj['subscription'];
            $cid = self::companyForSubscription($s);
            if ($cid) {
                self::applySubscription($cid, $s);
                $result = "company $cid is " . self::mapStatus((string) ($s['status'] ?? ''));
            } else {
                $result = 'no matching company';
            }
        } elseif ($type === 'invoice.payment_made' || $type === 'invoice.scheduled_charge_failed') {
            $inv = $obj['invoice'] ?? [];
            $cid = !empty($inv['subscription_id']) ? Db::value('SELECT company_id FROM subscriptions WHERE square_subscription_id = ?', [$inv['subscription_id']]) : null;
            if ($cid) {
                Db::exec('UPDATE subscriptions SET status = ? WHERE company_id = ?', [$type === 'invoice.payment_made' ? 'active' : 'past_due', $cid]);
                $result = "company $cid invoice update";
            }
        }
        if ($id !== '') {
            Db::insert('square_events', ['event_id' => $id, 'type' => mb_substr($type, 0, 80)]);
        }
        return $result;
    }

    private static function companyForSubscription(array $s): ?int
    {
        foreach ([['square_subscription_id', $s['id'] ?? null], ['square_customer_id', $s['customer_id'] ?? null]] as [$col, $val]) {
            if ($val) {
                $cid = Db::value("SELECT company_id FROM subscriptions WHERE $col = ?", [$val]);
                if ($cid) {
                    return (int) $cid;
                }
            }
        }
        // Fallback: look up the Square customer's email and match the company owner's login.
        if (!empty($s['customer_id'])) {
            try {
                $c = self::request('GET', '/v2/customers/' . rawurlencode($s['customer_id']));
                $email = strtolower((string) ($c['customer']['email_address'] ?? ''));
                if ($email !== '') {
                    $cid = Db::value("SELECT company_id FROM users WHERE email = ? AND role = 'owner'", [$email]);
                    return $cid ? (int) $cid : null;
                }
            } catch (RuntimeException) {
                return null;
            }
        }
        return null;
    }
}
