<?php
declare(strict_types=1);

/**
 * available = total quantity
 *           - quantity in blocking bookings (confirmed, delivered, return_pending)
 *             that overlap the requested window, buffer days included
 *           - units under repair, damaged or lost
 */
final class AvailabilityService
{
    public const BLOCKING = ['confirmed', 'delivered', 'return_pending'];
    public const MAX_DAYS = 30;

    public static function bufferDays(): int
    {
        return max(0, (int) setting('buffer_days', '1'));
    }

    private static function isDate(string $d): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }

    /** Rental days = DATEDIFF(end, start) + 1 */
    public static function rentalDays(string $start, string $end): int
    {
        return (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    }

    /** Returns an error message, or null when the dates are fine. */
    public static function dateError(string $start, string $end): ?string
    {
        if (!self::isDate($start) || !self::isDate($end)) {
            return 'Choose a valid start date and end date.';
        }
        if ($start < date('Y-m-d')) {
            return 'The start date cannot be in the past.';
        }
        if ($end < $start) {
            return 'The end date cannot be before the start date.';
        }
        if (self::rentalDays($start, $end) > self::MAX_DAYS) {
            return 'Rentals are limited to ' . self::MAX_DAYS . ' days.';
        }
        return null;
    }

    /** @return array<int,int> product_id => available quantity (dates must be validated first) */
    public static function forProducts(array $productIds, string $start, string $end): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) {
            return [];
        }
        $buffer = self::bufferDays();
        // An existing booking occupies [start, end + buffer]; so does the requested one.
        $latestStart = (new DateTimeImmutable($end))->modify("+$buffer days")->format('Y-m-d');
        $earliestEnd = (new DateTimeImmutable($start))->modify("-$buffer days")->format('Y-m-d');

        $in = implode(',', array_fill(0, count($ids), '?'));
        $blocking = "'" . implode("','", self::BLOCKING) . "'";
        $sql = "SELECT p.product_id,
                  p.total_quantity
                  - COALESCE((SELECT SUM(bi.quantity)
                              FROM booking_items bi JOIN bookings b ON b.booking_id = bi.booking_id
                              WHERE bi.product_id = p.product_id AND b.status IN ($blocking)
                                AND b.start_date <= ? AND b.end_date >= ?), 0)
                  - COALESCE((SELECT GREATEST(SUM(a.change_qty), 0)
                              FROM inventory_adjustments a
                              WHERE a.product_id = p.product_id
                                AND a.reason IN ('damaged','lost','repair')), 0) AS available
                FROM products p
                WHERE p.is_active = 1 AND p.product_id IN ($in)";
        $st = Database::pdo()->prepare($sql);
        $st->execute(array_merge([$latestStart, $earliestEnd], $ids));

        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int) $row['product_id']] = max(0, (int) $row['available']);
        }
        return $out;
    }

    /** Available quantity of one product, or null if the product does not exist or is inactive. */
    public static function forProduct(int $productId, string $start, string $end): ?int
    {
        return self::forProducts([$productId], $start, $end)[$productId] ?? null;
    }
}