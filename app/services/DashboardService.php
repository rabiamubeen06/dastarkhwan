<?php
declare(strict_types=1);

/**
 * Admin dashboard (A-01): what needs attention today.
 *   handovers today  confirmed bookings whose rental starts today (or started and were never marked delivered)
 *   returns due      delivered / return_pending bookings whose rental end date is today or earlier
 *   pending requests bookings waiting for review, oldest first
 *   low stock        active products with 20% or fewer of their units free today (same rule as the inventory calendar)
 *   revenue          money received in a period; security deposits are held for customers and are not counted
 */
final class DashboardService
{
    public const LIST_LIMIT = 6;
    /** Low stock when free units x 5 <= owned units, the same rule the inventory calendar colours. */
    public const LOW_STOCK_RATIO = 5;

    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Oldest pending requests first. @return array{total:int,rows:array} */
    public static function pending(): array
    {
        $limit = self::LIST_LIMIT;
        $st = Database::pdo()->query(
            "SELECT b.booking_id, b.booking_no, b.start_date, b.end_date, b.total_amount, b.created_at, u.full_name
             FROM bookings b JOIN users u ON u.user_id = b.user_id
             WHERE b.status = 'pending' ORDER BY b.created_at ASC, b.booking_id ASC LIMIT $limit"
        );
        return ['total' => BookingAdminService::pendingCount(), 'rows' => $st->fetchAll()];
    }

    /**
     * Confirmed bookings that start today, plus earlier ones that were never marked delivered (overdue).
     * @return array{total:int,rows:array}
     */
    public static function handovers(string $today): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed' AND start_date <= ?");
        $st->execute([$today]);
        $total = (int) $st->fetchColumn();

        $limit = self::LIST_LIMIT;
        $st = $pdo->prepare(
            "SELECT b.booking_id, b.booking_no, b.start_date, b.end_date, b.delivery_method, b.delivery_address,
                    u.full_name, u.phone, z.city AS zone_city, z.area AS zone_area
             FROM bookings b JOIN users u ON u.user_id = b.user_id
             LEFT JOIN delivery_zones z ON z.zone_id = b.delivery_zone_id
             WHERE b.status = 'confirmed' AND b.start_date <= ?
             ORDER BY b.start_date ASC, b.booking_id ASC LIMIT $limit"
        );
        $st->execute([$today]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['overdue'] = $r['start_date'] < $today;
        }
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Items that should be back by today. days_over is 0 on the due day.
     * @return array{total:int,rows:array}
     */
    public static function returnsDue(string $today): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('delivered','return_pending') AND end_date <= ?");
        $st->execute([$today]);
        $total = (int) $st->fetchColumn();

        $limit = self::LIST_LIMIT;
        $st = $pdo->prepare(
            "SELECT b.booking_id, b.booking_no, b.end_date, b.status, u.full_name, u.phone
             FROM bookings b JOIN users u ON u.user_id = b.user_id
             WHERE b.status IN ('delivered','return_pending') AND b.end_date <= ?
             ORDER BY b.end_date ASC, b.booking_id ASC LIMIT $limit"
        );
        $st->execute([$today]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['days_over'] = ReturnService::lateDays($r['end_date'], $today);
        }
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Active products that are running out today, the emptiest first.
     * @return array{total:int,rows:array<int,array{product_id:int,name:string,sku:string,total:int,free:int}>}
     */
    public static function lowStock(string $today): array
    {
        $products = Database::pdo()->query('SELECT product_id, name, sku, total_quantity FROM products WHERE is_active = 1 AND total_quantity > 0')->fetchAll();
        if (!$products) {
            return ['total' => 0, 'rows' => []];
        }
        $free = AvailabilityService::forProducts(array_map(fn($p) => (int) $p['product_id'], $products), $today, $today);
        $low = [];
        foreach ($products as $p) {
            $n = (int) ($free[(int) $p['product_id']] ?? 0);
            $total = (int) $p['total_quantity'];
            if ($n * self::LOW_STOCK_RATIO <= $total) {
                $low[] = ['product_id' => (int) $p['product_id'], 'name' => $p['name'], 'sku' => $p['sku'], 'total' => $total, 'free' => $n];
            }
        }
        usort($low, fn($a, $b) => [$a['free'] / $a['total'], $a['name']] <=> [$b['free'] / $b['total'], $b['name']]);
        return ['total' => count($low), 'rows' => array_slice($low, 0, self::LIST_LIMIT)];
    }

    /**
     * Money received from $from to $to (both Y-m-d, inclusive), in PKR.
     *   rental        advance and balance payments
     *   charges       late fees and damage charges paid by the customer
     *   deposit_kept  security deposit kept to cover return charges, counted on the day the deposit was settled
     * Security deposits received or refunded are not income and are left out. Refunds are not subtracted here.
     * @return array{rental:float,charges:float,deposit_kept:float,total:float}
     */
    public static function revenue(string $from, string $to): array
    {
        $pdo = Database::pdo();
        $start = $from . ' 00:00:00';
        $end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

        $st = $pdo->prepare(
            "SELECT type, SUM(amount) FROM payments
             WHERE paid_at >= ? AND paid_at < ? AND type IN ('advance','balance','late_fee','damage_charge') GROUP BY type"
        );
        $st->execute([$start, $end]);
        $by = ['advance' => 0, 'balance' => 0, 'late_fee' => 0, 'damage_charge' => 0];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$type, $sum]) {
            $by[$type] = self::cents($sum);
        }
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount_deducted), 0) FROM security_deposits WHERE settled_at >= ? AND settled_at < ?');
        $st->execute([$start, $end]);
        $kept = self::cents($st->fetchColumn());

        $rental = $by['advance'] + $by['balance'];
        $charges = $by['late_fee'] + $by['damage_charge'];
        return [
            'rental' => $rental / 100.0, 'charges' => $charges / 100.0, 'deposit_kept' => $kept / 100.0,
            'total' => ($rental + $charges + $kept) / 100.0,
        ];
    }

    /** Everything the dashboard page shows. $today is a Y-m-d date. */
    public static function overview(string $today): array
    {
        return [
            'pending'   => self::pending(),
            'handovers' => self::handovers($today),
            'returns'   => self::returnsDue($today),
            'low'       => self::lowStock($today),
            'today'     => self::revenue($today, $today),
            'month'     => self::revenue(substr($today, 0, 8) . '01', $today),
        ];
    }
}