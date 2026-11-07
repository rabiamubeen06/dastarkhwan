<?php
declare(strict_types=1);

/**
 * Cost calculator.
 *   subtotal = sum of line totals (quantity x rent per day x days)
 *   discount = package discount percent of the package items' rent (extra items outside the package are not discounted)
 *   total    = subtotal - discount + delivery fee
 *   deposit  = deposit_percent of the replacement value, at least min_deposit (held separately, not revenue)
 *   advance  = advance_percent of the total
 */
final class PricingService
{
    /** @return array<int,array<string,mixed>> active zones, e.g. Lahore / Gulberg / 500 */
    public static function zones(): array
    {
        return Database::pdo()
            ->query('SELECT zone_id, city, area, fee FROM delivery_zones WHERE is_active = 1 ORDER BY city, area')
            ->fetchAll();
    }

    /** One active zone, or null. */
    public static function zone(int $zoneId): ?array
    {
        if ($zoneId < 1) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT zone_id, city, area, fee FROM delivery_zones WHERE is_active = 1 AND zone_id = ?');
        $st->execute([$zoneId]);
        return $st->fetch() ?: null;
    }

    /**
     * @param array<int,array<string,mixed>> $lines CartService::lines() (needs quantity, line_total, replacement_cost)
     * @return array<string,mixed> numbers, plus 'error' (string|null) when delivery has no valid area yet
     */
    public static function quote(array $lines, int $days, string $method, int $zoneId, float $discountPercent = 0.0): array
    {
        $subtotal    = 0.0;
        $eligible    = 0.0;   // the part of the subtotal the discount applies to
        $replacement = 0.0;
        foreach ($lines as $l) {
            $subtotal    += (float) $l['line_total'];
            $eligible    += (float) ($l['discount_base'] ?? $l['line_total']);   // no 'discount_base' = whole line
            $replacement += (int) $l['quantity'] * (float) $l['replacement_cost'];
        }
        $subtotal = round($subtotal, 2);
        $discount = round(round($eligible, 2) * max(0.0, min(100.0, $discountPercent)) / 100, 2);

        $fee   = 0.0;
        $error = null;
        if ($method === 'delivery') {
            $zone = self::zone($zoneId);
            if ($zone === null) {
                $error = 'Choose your delivery area to add the delivery fee.';
            } else {
                $fee = (float) $zone['fee'];
            }
        }

        $total   = round($subtotal - $discount + $fee, 2);
        $deposit = 0.0;
        if ($lines) {
            $deposit = round(max((float) setting('min_deposit', '5000'),
                                 $replacement * (float) setting('deposit_percent', '25') / 100), 2);
        }
        $advance = round($total * (float) setting('advance_percent', '30') / 100, 2);

        return [
            'days' => $days, 'subtotal' => $subtotal, 'discount' => $discount, 'delivery_fee' => $fee,
            'total' => $total, 'deposit' => $deposit, 'advance' => $advance, 'error' => $error,
        ];
    }

    /** The same numbers as ready-to-print text (used by the cart page and the quote API). */
    public static function format(array $q): array
    {
        return [
            'subtotal'     => money($q['subtotal']),
            'discount'     => $q['discount'] > 0 ? '- ' . money($q['discount']) : money(0),
            'delivery_fee' => money($q['delivery_fee']),
            'total'        => money($q['total']),
            'advance'      => money($q['advance']),
            'deposit'      => money($q['deposit']),
            'error'        => $q['error'],
        ];
    }
}