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

// Fail closed: without a configured signing secret we cannot trust any event,
// so never process unsigned webhooks (prevents forged plan activation).
if ($secret === '') {
    exit('Webhook secret not configured');
}

// Verify the signature (t=timestamp,v1=hmac).
$parts = [];
foreach (explode(',', $sig) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    $parts[$k] = $v;
}
$expected = hash_hmac('sha256', ($parts['t'] ?? '') . '.' . $payload, $secret);
if (empty($parts['v1']) || !hash_equals($expected, $parts['v1'])) {
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    exit('Bad payload');
}

if (($event['type'] ?? '') === 'checkout.session.completed') {
    $session = $event['data']['object'] ?? [];
    $uid = (int) ($session['metadata']['user_id'] ?? 0);
    $pid = (int) ($session['metadata']['plan_id'] ?? 0);
    $eventId = (string) ($event['id'] ?? '');

    // Idempotency: Stripe delivers events at least once, so the same event can
    // arrive again. Process each event id only once, or a retry would extend the
    // customer's plan a second time.
    $firstTime = true;
    if ($eventId !== '') {
        try {
            $pdo->prepare('INSERT INTO stripe_events (id) VALUES (?)')->execute([$eventId]);
        } catch (PDOException $e) {
            $firstTime = $e->getCode() !== '23000'; // duplicate key = already handled
            if ($firstTime) {
                throw $e;
            }
        }
    }

    if ($firstTime && $uid > 0 && $pid > 0) {
        $plan = $pdo->prepare('SELECT period FROM plans WHERE id = ?');
        $plan->execute([$pid]);
        $period = $plan->fetchColumn();
        if ($period !== false) {
            // Buying the plan the customer already has (and that is still
            // active) extends from its current end date instead of discarding
            // the days they have left.
            $cur = $pdo->prepare('SELECT plan_id, plan_expires FROM users WHERE id = ?');
            $cur->execute([$uid]);
            $u = $cur->fetch();
            $stillActive = $u && (int) $u['plan_id'] === $pid
                && $u['plan_expires'] !== null && strtotime((string) $u['plan_expires']) > time();
            $base = $stillActive ? 'plan_expires' : 'NOW()'; // fixed literals, never user input

            $expires = match ($period) {
                'year'     => "($base + INTERVAL 1 YEAR)",
                'lifetime' => 'NULL',
                default    => "($base + INTERVAL 1 MONTH)",
            };
            $pdo->prepare("UPDATE users SET plan_id = ?, plan_expires = $expires WHERE id = ?")
                ->execute([$pid, $uid]);
        }
    }
}

http_response_code(200);
echo 'ok';
