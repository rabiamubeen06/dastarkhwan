<?php
// POST /api/quote.php   fields: _csrf, method (pickup|delivery), zone_id
// Saves the pickup/delivery choice in the cart and returns the live cost breakdown for the cart in the session.
require __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function quote_fail(int $code, string $message): never
{
    http_response_code($code);
    exit(json_encode(['error' => $message]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    quote_fail(405, 'Use POST.');
}
if (!Csrf::verify()) {
    quote_fail(419, 'Your session expired. Refresh the page and try again.');
}

$method = is_string($_POST['method'] ?? null) ? $_POST['method'] : '';
$zone   = is_string($_POST['zone_id'] ?? null) ? (int) $_POST['zone_id'] : 0;

$err = CartService::setDelivery($method, $zone);
if ($err !== null) {
    quote_fail(422, $err);
}

$dates = CartService::dates();
$days  = $dates ? AvailabilityService::rentalDays($dates['start'], $dates['end']) : 0;
$d     = CartService::delivery();
$q     = PricingService::quote(CartService::lines(), $days, $d['method'], $d['zone'], CartService::discountPercent());
echo json_encode(PricingService::format($q));