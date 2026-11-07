<?php
declare(strict_types=1);

/** Admin: review, confirm, reject and cancel booking requests (A-04). */
final class BookingAdminService
{
    public const PER_PAGE = 15;
    /** Cancelling a confirmed booking this many days (or fewer) before the event makes the advance non-refundable. */
    public const NON_REFUNDABLE_DAYS = 7;

    public const STATUSES = [
        'pending' => 'Pending', 'confirmed' => 'Confirmed', 'delivered' => 'Delivered',
        'return_pending' => 'Return pending', 'returned' => 'Returned', 'completed' => 'Completed',
        'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst($status);
    }

    /** CSS class for the status pill: pending = amber, closed = red, everything else = green. */
    public static function statusClass(string $status): string
    {
        return match ($status) {
            'pending' => '',
            'rejected', 'cancelled' => 'bad',
            default => 'on',
        };
    }

    /** @return array<string,int> status => number of bookings (every status is present) */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        $rows = Database::pdo()->query('SELECT status, COUNT(*) AS n FROM bookings GROUP BY status')->fetchAll();
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public static function pendingCount(): int
    {
        return (int) Database::pdo()->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
    }

    /** @return array{rows:array,total:int,page:int,pages:int} */
    public static function search(string $status, string $q, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (isset(self::STATUSES[$status])) {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = '(b.booking_no LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::pdo();

        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings b JOIN users u ON u.user_id = b.user_id WHERE $whereSql");
        $st->execute($params);
        $total  = (int) $st->fetchColumn();
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = max(1, min($page, $pages));
        $limit  = self::PER_PAGE;
        $offset = ($page - 1) * $limit;   // integers only, safe to inline
        // The pending queue is worked oldest first; every other list shows the newest first.
        $order = $status === 'pending' ? 'b.created_at ASC, b.booking_id ASC' : 'b.created_at DESC, b.booking_id DESC';

        $st = $pdo->prepare(
            "SELECT b.booking_id, b.booking_no, b.event_type, b.start_date, b.end_date, b.total_amount, b.status,
                    b.created_at, u.full_name, u.phone
             FROM bookings b JOIN users u ON u.user_id = b.user_id
             WHERE $whereSql ORDER BY $order LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** One booking with customer, delivery zone, security deposit and items. Null if it does not exist. */
    public static function find(int $id): ?array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT b.*, u.full_name, u.email, u.phone, z.city AS zone_city, z.area AS zone_area,
                    c.full_name AS confirmed_by_name, pk.name AS package_name
             FROM bookings b
             LEFT JOIN packages pk ON pk.package_id = b.package_id
             JOIN users u ON u.user_id = b.user_id
             LEFT JOIN users c ON c.user_id = b.confirmed_by
             LEFT JOIN delivery_zones z ON z.zone_id = b.delivery_zone_id
             WHERE b.booking_id = ?'
        );
        $st->execute([$id]);
        $b = $st->fetch();
        if (!$b) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT bi.product_id, bi.quantity, bi.unit_price, bi.line_total, p.name, p.sku
             FROM booking_items bi JOIN products p ON p.product_id = bi.product_id
             WHERE bi.booking_id = ? ORDER BY p.name'
        );
        $st->execute([$id]);
        $b['items'] = $st->fetchAll();
        $st = $pdo->prepare('SELECT status FROM security_deposits WHERE booking_id = ?');
        $st->execute([$id]);
        $b['deposit_status'] = $st->fetchColumn() ?: 'pending';
        return $b;
    }

    /** Live availability of each item for the booking's dates, to show conflicts before confirming. */
    public static function itemAvailability(array $booking): array
    {
        $ids = array_map(fn($i) => (int) $i['product_id'], $booking['items']);
        return AvailabilityService::forProducts($ids, $booking['start_date'], $booking['end_date']);
    }

    public static function advanceAmount(array $booking): float
    {
        return round((float) $booking['total_amount'] * (float) setting('advance_percent', '30') / 100, 2);
    }

    /** True when cancelling today would make the advance non-refundable (event starts within 7 days). */
    public static function advanceNonRefundable(string $startDate, ?string $today = null): bool
    {
        $today = $today ?? date('Y-m-d');
        $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($startDate))->format('%r%a');
        return $days <= self::NON_REFUNDABLE_DAYS;
    }

    /** @return array{0:?string,1:string} [error, clean note] */
    public static function validateNote(string $raw, bool $required): array
    {
        $note = trim(str_replace("\r\n", "\n", $raw));
        if ($required && mb_strlen($note) < 3) {
            return ['Please write a short reason (at least 3 characters).', $note];
        }
        if (mb_strlen($note) > 500) {
            return ['The note can be at most 500 characters.', $note];
        }
        return [null, $note];
    }

    /**
     * Confirm a pending booking once its advance payment is recorded (A-05). Stock is re-checked inside a transaction while the booking and
     * product rows are locked (SELECT ... FOR UPDATE), so two admins cannot double-book the same items.
     * @return array{ok:bool,error?:string,conflicts?:array<int,array{name:string,wanted:int,available:int}>}
     */
    public static function confirm(int $id, int $adminId, string $note): array
    {
        [$noteErr, $note] = self::validateNote($note, false);
        if ($noteErr !== null) {
            return ['ok' => false, 'error' => $noteErr];
        }

        $pdo = Database::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            if ($mysql) {
                // Read the latest committed bookings after the locks are taken, not an older snapshot.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            $pdo->beginTransaction();
            $lock = $mysql ? ' FOR UPDATE' : '';

            $st = $pdo->prepare('SELECT status, start_date, end_date, total_amount FROM bookings WHERE booking_id = ?' . $lock);
            $st->execute([$id]);
            $b = $st->fetch();
            if (!$b) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'That booking was not found.'];
            }
            if ($b['status'] !== 'pending') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Only pending bookings can be confirmed. This one is ' . self::statusLabel($b['status']) . '.'];
            }
            if ($b['start_date'] < date('Y-m-d')) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'The start date has already passed, so this booking cannot be confirmed. Reject it instead.'];
            }

            // Stock is locked only after the advance is received: it must be recorded as a payment first.
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND type = 'advance'");
            $st->execute([$id]);
            $paid = (int) round(((float) $st->fetchColumn()) * 100);
            $need = (int) round(self::advanceAmount($b) * 100);
            if ($paid < $need) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Record the advance payment first: ' . money($need / 100) . ' is required, ' . money($paid / 100) . ' recorded. Stock is locked only after the advance.'];
            }

            // Lock the product rows in a fixed order (prevents deadlocks between two confirmations).
            $st = $pdo->prepare(
                'SELECT bi.product_id, bi.quantity, p.name
                 FROM booking_items bi JOIN products p ON p.product_id = bi.product_id
                 WHERE bi.booking_id = ? ORDER BY bi.product_id'
            );
            $st->execute([$id]);
            $items = $st->fetchAll();
            if (!$items) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'This booking has no items.'];
            }
            $ids = array_map(fn($i) => (int) $i['product_id'], $items);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT product_id FROM products WHERE product_id IN ($in) ORDER BY product_id" . $lock);
            $st->execute($ids);
            $st->fetchAll();

            // Conflict check: same availability rule the customer sees (buffer days, repairs, other confirmed bookings).
            $avail = AvailabilityService::forProducts($ids, $b['start_date'], $b['end_date']);
            $conflicts = [];
            foreach ($items as $it) {
                $have = $avail[(int) $it['product_id']] ?? 0;
                if ((int) $it['quantity'] > $have) {
                    $conflicts[] = ['name' => $it['name'], 'wanted' => (int) $it['quantity'], 'available' => $have];
                }
            }
            if ($conflicts) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Not enough stock for these dates. The booking was not confirmed.', 'conflicts' => $conflicts];
            }

            $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_by = ?, admin_notes = ? WHERE booking_id = ? AND status = 'pending'")
                ->execute([$adminId, self::appendNote($id, $adminId, 'Confirmed, advance received', $note), $id]);
            $pdo->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Confirm failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Something went wrong and the booking was not confirmed. Please try again.'];
        }
    }

    /** pending -> rejected (a reason is required). @return array{ok:bool,error?:string} */
    public static function reject(int $id, int $adminId, string $note): array
    {
        return self::close($id, $adminId, $note, 'rejected', ['pending'], 'Rejected');
    }

    /** pending or confirmed -> cancelled (a reason is required). @return array{ok:bool,error?:string,non_refundable?:bool} */
    public static function cancel(int $id, int $adminId, string $note): array
    {
        return self::close($id, $adminId, $note, 'cancelled', ['pending', 'confirmed'], 'Cancelled');
    }

    /** confirmed -> delivered (items handed over or picked up). @return array{ok:bool,error?:string} */
    public static function markDelivered(int $id, int $adminId, string $note): array
    {
        return self::move($id, $adminId, $note, 'delivered', ['confirmed'], 'Marked as delivered');
    }

    /** delivered -> return_pending (event over, waiting for the items). @return array{ok:bool,error?:string} */
    public static function markReturnPending(int $id, int $adminId, string $note): array
    {
        return self::move($id, $adminId, $note, 'return_pending', ['delivered'], 'Event over, awaiting return');
    }

    /** One step forward along the main status path. The optional note goes into the internal notes only. */
    private static function move(int $id, int $adminId, string $note, string $to, array $from, string $line): array
    {
        [$err, $note] = self::validateNote($note, false);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }
        $pdo = Database::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT status FROM bookings WHERE booking_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            $st->execute([$id]);
            $status = $st->fetchColumn();
            if ($status === false) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'That booking was not found.'];
            }
            if (!in_array($status, $from, true)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'This booking is ' . strtolower(self::statusLabel((string) $status)) . ', so this step is not available.'];
            }
            $pdo->prepare('UPDATE bookings SET status = ?, admin_notes = ? WHERE booking_id = ? AND status = ?')
                ->execute([$to, self::appendNote($id, $adminId, $line, $note), $id, $status]);
            $pdo->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Status change failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Something went wrong. Please try again.'];
        }
    }

    private static function close(int $id, int $adminId, string $note, string $to, array $from, string $label): array
    {
        [$err, $note] = self::validateNote($note, true);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }
        $pdo = Database::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT status, start_date FROM bookings WHERE booking_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            $st->execute([$id]);
            $b = $st->fetch();
            if (!$b) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'That booking was not found.'];
            }
            if (!in_array($b['status'], $from, true)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'This booking is ' . self::statusLabel($b['status']) . ' and cannot be ' . strtolower($label) . '.'];
            }
            // The advance only exists once a booking is confirmed, so the 7-day rule applies to confirmed ones.
            $nonRefundable = $to === 'cancelled' && $b['status'] === 'confirmed' && self::advanceNonRefundable($b['start_date']);
            $line = $label . ($nonRefundable ? ' within ' . self::NON_REFUNDABLE_DAYS . ' days of the event: advance is NON-REFUNDABLE' : '');

            // The reason is also saved on its own so the customer can see it (admin_notes stays internal).
            $pdo->prepare('UPDATE bookings SET status = ?, status_reason = ?, admin_notes = ? WHERE booking_id = ? AND status = ?')
                ->execute([$to, $note, self::appendNote($id, $adminId, $line, $note), $id, $b['status']]);
            $pdo->commit();
            return ['ok' => true, 'non_refundable' => $nonRefundable];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Close failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Something went wrong. Please try again.'];
        }
    }

    /** Adds a dated line to the booking's notes: "[2026-11-06 14:30 Admin Name] Rejected: reason". */
    public static function appendNote(int $id, int $adminId, string $action, string $note): string
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT admin_notes FROM bookings WHERE booking_id = ?');
        $st->execute([$id]);
        $old = (string) $st->fetchColumn();
        $st = $pdo->prepare('SELECT full_name FROM users WHERE user_id = ?');
        $st->execute([$adminId]);
        $who = (string) ($st->fetchColumn() ?: 'Admin');
        $line = '[' . date('Y-m-d H:i') . ' ' . $who . '] ' . $action . ($note !== '' ? ': ' . $note : '');
        return $old === '' ? $line : $old . "\n" . $line;
    }
}