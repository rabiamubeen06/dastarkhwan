<?php
declare(strict_types=1);

/**
 * Admin: return checklist after an event (A-07).
 * Every booked unit is accounted for as returned (good), damaged or missing. Damaged and missing units
 * are charged at the replacement cost (or an amount the admin types), and a late fee is added for each day
 * after the rental end date. Damaged and missing units also leave the available stock.
 * The charges are only recorded here; A-06 settles them against the security deposit.
 * An inspection cannot be edited afterwards.
 */
final class ReturnService
{
    /** Statuses from which the items can be received back. */
    public const RECEIVABLE = ['delivered', 'return_pending'];

    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Items of a booking with their replacement cost (the price at the time of booking). */
    public static function items(int $bookingId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT bi.booking_item_id, bi.product_id, bi.quantity, bi.replacement_cost, p.name, p.sku
             FROM booking_items bi JOIN products p ON p.product_id = bi.product_id
             WHERE bi.booking_id = ? ORDER BY p.name'
        );
        $st->execute([$bookingId]);
        return $st->fetchAll();
    }

    /** Whole days after the rental end date that the items came back (0 when on time or early). */
    public static function lateDays(string $endDate, string $returnedAt): int
    {
        $end = new DateTimeImmutable($endDate);
        $back = new DateTimeImmutable(substr($returnedAt, 0, 10));
        return max(0, (int) $end->diff($back)->format('%r%a'));
    }

    /** Charge for damaged and missing units when the admin does not type one: units x replacement cost. */
    public static function autoCharge(int $units, float|string $replacementCost): string
    {
        return number_format(($units * self::cents($replacementCost)) / 100, 2, '.', '');
    }

    /**
     * The recorded return of a booking with its lines and totals, or null if the items are not back yet.
     * Totals: damage = sum of the damage charges, late = the late fee, charges = damage + late.
     */
    public static function forBooking(int $bookingId): ?array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT r.*, u.full_name AS received_by_name FROM returns r
             LEFT JOIN users u ON u.user_id = r.received_by
             WHERE r.booking_id = ? ORDER BY r.return_id DESC LIMIT 1'
        );
        $st->execute([$bookingId]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT ri.*, p.name, p.sku, bi.quantity
             FROM return_items ri
             JOIN booking_items bi ON bi.booking_item_id = ri.booking_item_id
             JOIN products p ON p.product_id = bi.product_id
             WHERE ri.return_id = ? ORDER BY p.name'
        );
        $st->execute([$r['return_id']]);
        $r['lines'] = $st->fetchAll();
        $damage = 0;
        foreach ($r['lines'] as $l) {
            $damage += self::cents($l['damage_charge']);
        }
        $late = self::cents($r['late_fee']);
        $r['damage_total']  = $damage / 100;
        $r['charges_total'] = ($damage + $late) / 100;
        return $r;
    }

    /** The steps of the rental as the customer sees them, once the booking is confirmed. */
    public const TRACK = ['confirmed', 'delivered', 'return_pending', 'returned', 'completed'];

    /**
     * What a customer may see about the return (C-10): where the rental is, when the items are due back,
     * late fee so far, and after the return the counts and charges. Internal remarks, notes and the name of the
     * admin who received the items are left out on purpose. $booking is a row from BookingService::find().
     */
    public static function customerView(array $booking, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $status = (string) $booking['status'];
        $at = array_search($status, self::TRACK, true);
        $steps = [];
        if ($at !== false) {
            foreach (self::TRACK as $i => $s) {
                $steps[] = ['label' => BookingAdminService::statusLabel($s), 'state' => $i < $at ? 'done' : ($i === $at ? 'current' : 'todo')];
            }
        }

        $due = in_array($status, ['confirmed', 'delivered', 'return_pending'], true) ? $booking['end_date'] : null;
        $overdueDays = 0;
        if ($due !== null && in_array($status, ['delivered', 'return_pending'], true)) {
            $overdueDays = self::lateDays($booking['end_date'], $today);
        }
        $rate = self::cents(setting('late_fee_per_day', '500'));

        $return = null;
        $ret = self::forBooking((int) $booking['booking_id']);
        if ($ret !== null) {
            $lines = [];
            foreach ($ret['lines'] as $l) {
                $lines[] = [
                    'name' => $l['name'], 'sku' => $l['sku'], 'booked' => (int) $l['quantity'], 'returned' => (int) $l['qty_returned'],
                    'damaged' => (int) $l['qty_damaged'], 'missing' => (int) $l['qty_missing'], 'charge' => (float) $l['damage_charge'],
                ];
            }
            $return = [
                'returned_at' => $ret['returned_at'], 'late_days' => (int) $ret['late_days'], 'late_fee' => (float) $ret['late_fee'],
                'damage_total' => (float) $ret['damage_total'], 'charges_total' => (float) $ret['charges_total'], 'lines' => $lines,
            ];
        }

        return [
            'steps' => $steps, 'due_back' => $due, 'overdue_days' => $overdueDays,
            'overdue_fee' => ($overdueDays * $rate) / 100.0, 'late_rate' => $rate / 100.0, 'return' => $return,
        ];
    }

    /**
     * Check the checklist form. Per item: ret[ID], dmg[ID], mis[ID], chg[ID] (blank = automatic), rem[ID].
     * @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values]
     */
    public static function validate(array $in, array $booking, array $items): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $cell = function (string $k, int $id) use ($in): string {
            $v = is_array($in[$k] ?? null) ? ($in[$k][$id] ?? '') : '';
            return is_string($v) ? trim($v) : '';
        };
        $errors = [];

        $returnedAt = date('Y-m-d H:i:s');
        $when = $get('returned_at');
        if ($when !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $when);
            if ($dt === false || $dt->format('Y-m-d\TH:i') !== $when) {
                $errors['returned_at'] = 'Enter a valid date and time.';
            } elseif ($dt > new DateTimeImmutable('+5 minutes')) {
                $errors['returned_at'] = 'The return date cannot be in the future.';
            } elseif ($dt->format('Y-m-d') < $booking['start_date']) {
                $errors['returned_at'] = 'The return date cannot be before the rental start date.';
            } else {
                $returnedAt = $dt->format('Y-m-d H:i:s');
            }
        }

        $lateDays = self::lateDays($booking['end_date'], $returnedAt);
        $lateRaw = $get('late_days');
        if ($lateRaw !== '') {
            if (!preg_match('/^\d{1,3}$/', $lateRaw)) {
                $errors['late_days'] = 'Enter late days as a whole number (0 waives the late fee), or leave blank for automatic.';
            } else {
                $lateDays = (int) $lateRaw;
            }
        }
        $lateFee = number_format(($lateDays * self::cents(setting('late_fee_per_day', '500'))) / 100, 2, '.', '');

        $notes = preg_replace('/\s+/', ' ', $get('notes')) ?? '';
        if (mb_strlen($notes) > 255) {
            $errors['notes'] = 'The note can be at most 255 characters.';
        }

        $lines = [];
        foreach ($items as $it) {
            $id = (int) $it['booking_item_id'];
            $key = 'item_' . $id;
            $qty = (int) $it['quantity'];
            $nums = [];
            foreach (['ret' => 'returned', 'dmg' => 'damaged', 'mis' => 'missing'] as $k => $word) {
                $raw = $cell($k, $id);
                if (!preg_match('/^\d{1,6}$/', $raw)) {
                    $errors[$key] = 'Enter whole numbers for returned, damaged and missing (use 0 if none).';
                    break;
                }
                $nums[$k] = (int) $raw;
            }
            $charge = '0.00';
            $remarks = preg_replace('/\s+/', ' ', $cell('rem', $id)) ?? '';
            if (!isset($errors[$key])) {
                if ($nums['ret'] + $nums['dmg'] + $nums['mis'] !== $qty) {
                    $errors[$key] = 'Returned + damaged + missing must add up to ' . $qty . ' (you entered ' . ($nums['ret'] + $nums['dmg'] + $nums['mis']) . ').';
                } else {
                    $bad = $nums['dmg'] + $nums['mis'];
                    $chgRaw = $cell('chg', $id);
                    if ($chgRaw === '') {
                        $charge = self::autoCharge($bad, $it['replacement_cost']);
                    } elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $chgRaw)) {
                        $errors[$key] = 'Enter the damage charge in PKR, e.g. 1200 (or leave blank for the automatic charge).';
                    } elseif ($bad === 0 && self::cents($chgRaw) > 0) {
                        $errors[$key] = 'A charge needs at least one damaged or missing unit.';
                    } else {
                        $charge = number_format(self::cents($chgRaw) / 100, 2, '.', '');
                    }
                }
            }
            if (mb_strlen($remarks) > 255 && !isset($errors[$key])) {
                $errors[$key] = 'Remarks can be at most 255 characters.';
            }
            $lines[$id] = ['returned' => $nums['ret'] ?? 0, 'damaged' => $nums['dmg'] ?? 0, 'missing' => $nums['mis'] ?? 0,
                           'charge' => $charge, 'remarks' => $remarks];
        }

        return [$errors, ['returned_at' => $returnedAt, 'late_days' => $lateDays, 'late_fee' => $lateFee, 'notes' => $notes, 'lines' => $lines]];
    }

    /**
     * Save the checklist and move the booking to "returned". The booking row is locked while its status is
     * re-checked, so the same return cannot be saved twice.
     * @return array{ok:bool,errors:array<string,string>}
     */
    public static function save(int $bookingId, int $adminId, array $in): array
    {
        $pdo = Database::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT booking_id, status, start_date, end_date FROM bookings WHERE booking_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            $st->execute([$bookingId]);
            $b = $st->fetch();
            if (!$b) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['form' => 'That booking was not found.']];
            }
            if (!in_array($b['status'], self::RECEIVABLE, true)) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['form' => 'Items can only be received for a delivered or return-pending booking. This one is ' . strtolower(BookingAdminService::statusLabel($b['status'])) . '.']];
            }
            $items = self::items($bookingId);
            [$errors, $c] = self::validate($in, $b, $items);
            if ($errors) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => $errors];
            }

            $pdo->prepare('INSERT INTO returns (booking_id, returned_at, received_by, late_days, late_fee, notes) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$bookingId, $c['returned_at'], $adminId, $c['late_days'], $c['late_fee'], $c['notes'] !== '' ? $c['notes'] : null]);
            $returnId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare('INSERT INTO return_items (return_id, booking_item_id, qty_returned, qty_damaged, qty_missing, damage_charge, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insAdj  = $pdo->prepare('INSERT INTO inventory_adjustments (product_id, change_qty, reason, booking_id, created_by) VALUES (?, ?, ?, ?, ?)');
            $damageCents = 0;
            $units = ['damaged' => 0, 'missing' => 0];
            foreach ($items as $it) {
                $l = $c['lines'][(int) $it['booking_item_id']];
                $insItem->execute([$returnId, $it['booking_item_id'], $l['returned'], $l['damaged'], $l['missing'], $l['charge'], $l['remarks'] !== '' ? $l['remarks'] : null]);
                $damageCents += self::cents($l['charge']);
                // Damaged and missing units leave the available stock until the admin puts them back (A-03).
                if ($l['damaged'] > 0) {
                    $insAdj->execute([$it['product_id'], $l['damaged'], 'damaged', $bookingId, $adminId]);
                }
                if ($l['missing'] > 0) {
                    $insAdj->execute([$it['product_id'], $l['missing'], 'lost', $bookingId, $adminId]);
                }
                $units['damaged'] += $l['damaged'];
                $units['missing'] += $l['missing'];
            }

            $summary = 'Return inspected: ' . $units['damaged'] . ' damaged, ' . $units['missing'] . ' missing, damage charges ' . money($damageCents / 100)
                     . ', late fee ' . money($c['late_fee']) . ' (' . $c['late_days'] . ' late day' . ($c['late_days'] === 1 ? '' : 's') . ')';
            $pdo->prepare("UPDATE bookings SET status = 'returned', admin_notes = ? WHERE booking_id = ? AND status = ?")
                ->execute([BookingAdminService::appendNote($bookingId, $adminId, $summary, ''), $bookingId, $b['status']]);
            $pdo->commit();
            return ['ok' => true, 'errors' => []];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Return failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['form' => 'The return could not be saved. Please try again.']];
        }
    }
}