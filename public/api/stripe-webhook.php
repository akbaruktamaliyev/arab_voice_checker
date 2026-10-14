<?php
/**
 * QRaft — Stripe webhook. Activates a plan when a Checkout payment completes.
 *
 * Point a Stripe webhook at this URL for the event checkout.session.completed
 * and set the signing secret in the admin settings (stripe_webhook_secret).
 */

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

$payload = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret = trim((string) setting('stripe_webhook_secret', ''));

http_response_code(400);

// Verify the signature (t=timestamp,v1=hmac) when a secret is configured.
if ($secret !== '') {
    $parts = [];
    foreach (explode(',', $sig) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[$k] = $v;
    }
    $expected = hash_hmac('sha256', ($parts['t'] ?? '') . '.' . $payload, $secret);
    if (empty($parts['v1']) || !hash_equals($expected, $parts['v1'])) {
        exit('Invalid signature');
    }
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    exit('Bad payload');
}

if (($event['type'] ?? '') === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $uid = (int) ($session['metadata']['user_id'] ?? 0);
    $pid = (int) ($session['metadata']['plan_id'] ?? 0);

    if ($uid > 0 && $pid > 0) {
        $plan = $pdo->prepare('SELECT period FROM plans WHERE id = ?');
        $plan->execute([$pid]);
        $period = $plan->fetchColumn();
        if ($period !== false) {
            $expires = match ($period) {
                'year'     => '(NOW() + INTERVAL 1 YEAR)',
                'lifetime' => 'NULL',
                default    => '(NOW() + INTERVAL 1 MONTH)',
            };
            $pdo->prepare("UPDATE users SET plan_id = ?, plan_expires = $expires WHERE id = ?")
                ->execute([$pid, $uid]);
        }
    }
}

http_response_code(200);
echo 'ok';
