<?php
/**
 * QRaft — start a Stripe Checkout for a plan.
 *
 * Uses Stripe's HTTP API directly (no SDK). Creates a one-time Checkout
 * Session; the webhook activates the plan for its period on payment. For true
 * auto-renewing subscriptions, create Stripe Prices and switch mode to
 * "subscription" — see docs/payments.md.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require_login();

$uid  = (int) current_user_id();
$pid  = (int) ($_GET['plan'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM plans WHERE id = ? AND is_active = 1');
$stmt->execute([$pid]);
$plan = $stmt->fetch();

function checkout_message(string $heading, string $body): never
{
    global $pdo, $title;
    $title = t('co.title');
    ob_start(); ?>
    <section class="section" style="max-width:480px;margin-inline:auto">
        <div class="card center stack">
            <h1 style="margin:0"><?= e($heading) ?></h1>
            <p class="muted"><?= $body ?></p>
            <a class="btn btn-ghost" href="<?= e(url('pricing.php')) ?>"><?= e(t('co.back')) ?></a>
        </div>
    </section>
    <?php $content = ob_get_clean(); require __DIR__ . '/../src/layout.php'; exit;
}

if (!$plan || (int) $plan['price'] === 0) {
    checkout_message(t('co.unavail'), e(t('co.unavail_text')));
}

$secret = trim((string) setting('stripe_secret', ''));
if ($secret === '') {
    checkout_message(t('co.nopay'), e(t('co.nopay_text')));
}
if (!function_exists('curl_init')) {
    checkout_message(t('co.nocurl'), e(t('co.nocurl_text')));
}

// Create a Checkout Session via the Stripe API.
$user = current_user($pdo);
$fields = [
    'mode' => 'payment',
    'success_url' => url('billing.php?paid=1'),
    'cancel_url'  => url('pricing.php'),
    'customer_email' => $user['email'],
    'client_reference_id' => (string) $uid,
    'metadata[user_id]' => (string) $uid,
    'metadata[plan_id]' => (string) $plan['id'],
    'line_items[0][quantity]' => '1',
    'line_items[0][price_data][currency]' => (string) setting('currency', 'usd'),
    'line_items[0][price_data][unit_amount]' => (string) (int) $plan['price'],
    'line_items[0][price_data][product_data][name]' => app_name() . ' — ' . $plan['name'],
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_USERPWD => $secret . ':',
    CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode((string) $resp, true);
if ($code === 200 && !empty($data['url'])) {
    header('Location: ' . $data['url']);
    exit;
}
checkout_message(t('co.failed'), e(t('co.failed_text', (string) ($data['error']['message'] ?? t('co.unknown')))));
