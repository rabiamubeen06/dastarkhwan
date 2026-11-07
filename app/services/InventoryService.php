<?php
declare(strict_types=1);

/**
 * Inventory (A-03): the availability calendar and manual stock adjustments.
 *
 * inventory_adjustments is a log. How each reason is used:
 *   restock, correction  change the number of units owned (products.total_quantity) and are logged here.
 *   damaged, lost, repair  take units out of stock while the rows add up above zero (AvailabilityService reads them).
 *                          A positive row takes units out; a NEGATIVE row puts them back.
 * The return checklist (A-07) writes damaged and lost rows; this screen is where they are put back.
 */
final class InventoryService
{
    public const DAYS = 14;   // columns on the calendar

    public const REASONS = [
        'restock'    => 'Restock (new units bought)',
        'correction' => 'Stock count correction',
        'damaged'    => 'Damaged',
        'lost'       => 'Lost',
        'repair'     => 'Under repair',
    ];
    public const OUT = ['damaged', 'lost', 'repair'];

    public static function reasonLabel(string $r): string
    {
        return self::REASONS[$r] ?? ucfirst($r);
    }

    // ---------- calendar ----------

    /** A valid Y-m-d, or today. */
    public static function startDate(string $raw): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return ($d !== false && $d->format('Y-m-d') === $raw) ? $raw : date('Y-m-d');
    }

    /** @return string[] the calendar's dates */
    public static function dates(string $from): array
    {
        $out = [];
        $d = new DateTimeImmutable($from);
        for ($i = 0; $i < self::DAYS; $i++) {
            $out[] = $d->modify("+$i days")->format('Y-m-d');
        }
        return $out;
    }

    /**
     * Units a customer could rent for each date (the same numbers the catalog shows for a one-day rental).
     * @param int[] $productIds
     * @return array<int,array<string,int>> product_id => [date => available]
     */
    public static function calendar(array $productIds, string $from): array
    {
        $out = [];
        foreach (self::dates($from) as $date) {
            foreach (AvailabilityService::forProducts($productIds, $date, $date) as $pid => $n) {
                $out[$pid][$date] = $n;
            }
        }
        return $out;
    }

    /** Confirmed, delivered and return-pending bookings that still hold this product, earliest first. */
    public static function holds(int $productId): array
    {
        $in = "'" . implode("','", AvailabilityService::BLOCKING) . "'";
        $st = Database::pdo()->prepare(
            "SELECT b.booking_id, b.booking_no, b.status, b.start_date, b.end_date, bi.quantity
             FROM booking_items bi JOIN bookings b ON b.booking_id = bi.booking_id
             WHERE bi.product_id = ? AND b.status IN ($in) AND b.end_date >= ?
             ORDER BY b.start_date, b.booking_id"
        );
        $st->execute([$productId, date('Y-m-d')]);
        return $st->fetchAll();
    }

    // ---------- stock position ----------

    /** @return array{total:int,damaged:int,lost:int,repair:int,out:int,usable:int} */
    public static function summary(int $productId): ?array
    {
        $st = Database::pdo()->prepare('SELECT total_quantity FROM products WHERE product_id = ?');
        $st->execute([$productId]);
        $total = $st->fetchColumn();
        if ($total === false) {
            return null;
        }
        return self::position((int) $total, $productId);
    }

    private static function position(int $total, int $productId): array
    {
        $st = Database::pdo()->prepare(
            "SELECT reason, SUM(change_qty) FROM inventory_adjustments
             WHERE product_id = ? AND reason IN ('damaged','lost','repair') GROUP BY reason"
        );
        $st->execute([$productId]);
        $net = ['damaged' => 0, 'lost' => 0, 'repair' => 0];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$reason, $sum]) {
            $net[$reason] = (int) $sum;
        }
        $out = max(0, array_sum($net));   // the same rule AvailabilityService uses
        return $net + ['total' => $total, 'out' => $out, 'usable' => max(0, $total - $out)];
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function history(int $productId, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));   // integer, safe to inline
        $st = Database::pdo()->prepare(
            "SELECT a.adjustment_id, a.change_qty, a.reason, a.booking_id, a.created_at, u.full_name, b.booking_no
             FROM inventory_adjustments a
             LEFT JOIN users u ON u.user_id = a.created_by
             LEFT JOIN bookings b ON b.booking_id = a.booking_id
             WHERE a.product_id = ? ORDER BY a.adjustment_id DESC LIMIT $limit"
        );
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    // ---------- adjusting stock ----------

    /** @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values] */
    public static function validate(array $in): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];
        $reason = $get('reason');
        if (!isset(self::REASONS[$reason])) {
            $errors['reason'] = 'Choose a reason.';
        }
        $raw = str_replace(' ', '', $get('change'));
        $change = 0;
        if (!preg_match('/^[+-]?\d{1,6}$/', $raw) || (int) $raw === 0) {
            $errors['change'] = 'Enter a whole number, not zero. Use a minus sign to remove units, like -5.';
        } else {
            $change = (int) $raw;
            if ($reason === 'restock' && $change < 0) {
                $errors['change'] = 'A restock adds units. Use a stock count correction to remove units.';
            }
        }
        return [$errors, ['reason' => $reason, 'change' => $change]];
    }

    /** Apply one adjustment. Returns an error message, or null when it was saved. */
    public static function adjust(int $productId, string $reason, int $change, int $adminId): ?string
    {
        if (!isset(self::REASONS[$reason]) || $change === 0) {
            return 'Enter a valid adjustment.';
        }
        $pdo = Database::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT total_quantity FROM products WHERE product_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            $st->execute([$productId]);
            $total = $st->fetchColumn();
            if ($total === false) {
                $pdo->rollBack();
                return 'That product was not found.';
            }
            $total = (int) $total;
            $pos   = self::position($total, $productId);

            $error = null;
            $newTotal = $total;
            if ($reason === 'restock' || $reason === 'correction') {
                $newTotal = $total + $change;
                if ($reason === 'restock' && $change < 0) {
                    $error = 'A restock adds units.';
                } elseif ($newTotal < 0) {
                    $error = 'That would leave less than 0 units. You own ' . $total . '.';
                } elseif ($newTotal > 1000000) {
                    $error = 'That is more than the system allows.';
                } elseif ($newTotal < $pos['out']) {
                    $error = $pos['out'] . ' unit' . ($pos['out'] === 1 ? ' is' : 's are') . ' damaged, lost or under repair. Put them back in stock first, or lower the correction.';
                }
            } elseif ($change > 0) {
                if ($change > $pos['usable']) {
                    $error = 'Only ' . $pos['usable'] . ' unit' . ($pos['usable'] === 1 ? ' is' : 's are') . ' in stock to mark as ' . strtolower(self::reasonLabel($reason)) . '.';
                }
            } elseif (-$change > $pos[$reason]) {
                $error = 'Only ' . max(0, $pos[$reason]) . ' unit' . ($pos[$reason] === 1 ? ' is' : 's are') . ' recorded as ' . strtolower(self::reasonLabel($reason)) . ', so you cannot put back ' . (-$change) . '.';
            }
            if ($error !== null) {
                $pdo->rollBack();
                return $error;
            }

            $pdo->prepare('INSERT INTO inventory_adjustments (product_id, change_qty, reason, booking_id, created_by) VALUES (?, ?, ?, NULL, ?)')
                ->execute([$productId, $change, $reason, $adminId]);
            if ($newTotal !== $total) {
                $pdo->prepare('UPDATE products SET total_quantity = ? WHERE product_id = ?')->execute([$newTotal, $productId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Stock adjustment failed: ' . $e->getMessage());
            return 'We could not save the adjustment. Please try again.';
        }
        return null;
    }
}