<?php
declare(strict_types=1);

/**
 * Admin: record payments received from or returned to a customer (A-05).
 * Payments are manual entries made after the customer sends proof on WhatsApp.
 * They cannot be edited or deleted, so the money trail stays trustworthy.
 */
final class PaymentService
{
    /** Types the admin can record here. damage_charge and late_fee are added by the return feature (A-07). */
    public const TYPES = ['advance' => 'Advance', 'balance' => 'Balance', 'deposit' => 'Security deposit', 'refund' => 'Refund'];
    public const METHODS = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'easypaisa' => 'Easypaisa', 'jazzcash' => 'JazzCash', 'other' => 'Other'];
    private const ALL_LABELS = ['damage_charge' => 'Damage charge', 'late_fee' => 'Late fee'];

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? self::ALL_LABELS[$type] ?? ucfirst($type);
    }

    /** Which payment types make sense for a booking in this status. */
    public static function allowedTypes(string $status): array
    {
        return match ($status) {
            'pending'   => ['advance'],
            'cancelled' => ['refund'],
            'rejected'  => [],
            default     => array_keys(self::TYPES),
        };
    }

    /** @return array<int,array<string,mixed>> payments of one booking, oldest first */
    public static function forBooking(int $bookingId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.*, u.full_name AS received_by_name
             FROM payments p LEFT JOIN users u ON u.user_id = p.received_by
             WHERE p.booking_id = ? ORDER BY p.paid_at, p.payment_id'
        );
        $st->execute([$bookingId]);
        return $st->fetchAll();
    }

    /** Money amounts are compared in whole paisa (cents) to avoid floating point surprises. */
    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** @return array<string,int> type => total in cents (every type is present) */
    private static function totalsCents(int $bookingId): array
    {
        $out = array_fill_keys(['advance', 'balance', 'deposit', 'refund', 'damage_charge', 'late_fee'], 0);
        $st = Database::pdo()->prepare('SELECT type, SUM(amount) AS s FROM payments WHERE booking_id = ? GROUP BY type');
        $st->execute([$bookingId]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['type']] = self::cents($r['s']);
        }
        return $out;
    }

    /**
     * What has been paid and what is still due. All amounts are PKR as floats.
     * $booking needs total_amount and security_deposit.
     */
    public static function summary(array $booking): array
    {
        $t = self::totalsCents((int) $booking['booking_id']);
        $total = self::cents($booking['total_amount']);
        $advRequired = self::cents(BookingAdminService::advanceAmount($booking));
        $rentalPaid = $t['advance'] + $t['balance'];
        $deposit = self::cents($booking['security_deposit']);
        return [
            'total'            => $total / 100,
            'advance_required' => $advRequired / 100,
            'advance_paid'     => $t['advance'] / 100,
            'advance_ok'       => $t['advance'] >= $advRequired,
            'paid'             => $rentalPaid / 100,
            'balance_due'      => max(0, $total - $rentalPaid) / 100,
            'deposit_required' => $deposit / 100,
            'deposit_received' => $t['deposit'] / 100,
            'refunded'         => $t['refund'] / 100,
            'refundable_left'  => max(0, $rentalPaid + $t['deposit'] - $t['refund']) / 100,
        ];
    }

    /**
     * What the customer should pay next (shown on their booking page, C-13).
     * The advance comes first; once it is paid, the remaining balance.
     * @param array $summary result of summary()
     * @return array{label:string,amount:float} amount is 0 when nothing is due
     */
    public static function dueNow(array $summary, string $status): array
    {
        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return ['label' => '', 'amount' => 0.0];
        }
        $advance = round(max(0, $summary['advance_required'] - $summary['advance_paid']), 2);
        if ($advance > 0) {
            return ['label' => 'Advance due', 'amount' => $advance];
        }
        if ($summary['balance_due'] > 0) {
            return ['label' => 'Balance due', 'amount' => (float) $summary['balance_due']];
        }
        return ['label' => '', 'amount' => 0.0];
    }

    /**
     * Everything the customer's booking pages need about money: amounts, what is due, instructions, WhatsApp link.
     * $booking is a row from BookingService::find().
     */
    public static function customerView(array $booking): array
    {
        $summary = self::summary($booking);
        $due = self::dueNow($summary, $booking['status']);
        $shop = trim(setting('shop_whatsapp', ''));
        $text = 'Hello, I am sending payment proof for booking ' . $booking['booking_no']
              . ($due['amount'] > 0 ? ' (' . money($due['amount']) . ').' : '.');
        return [
            'summary' => $summary, 'due' => $due,
            'instructions' => trim(setting('payment_instructions', '')),
            'shop_whatsapp' => $shop, 'proof_link' => whatsapp_link($shop, $text),
        ];
    }

    /**
     * Check the payment form for this booking.
     * @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values]
     */
    public static function validate(array $in, array $booking): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];
        $s = self::summary($booking);

        $type = $get('type');
        $allowed = self::allowedTypes($booking['status']);
        if (!isset(self::TYPES[$type])) {
            $errors['type'] = 'Choose the payment type.';
        } elseif (!in_array($type, $allowed, true)) {
            $errors['type'] = $allowed
                ? 'A ' . BookingAdminService::statusLabel($booking['status']) . ' booking can only take: ' . implode(', ', array_map(fn($t) => strtolower(self::TYPES[$t]), $allowed)) . '.'
                : 'No payments can be recorded on a ' . strtolower(BookingAdminService::statusLabel($booking['status'])) . ' booking.';
        }

        $method = $get('method');
        if (!isset(self::METHODS[$method])) {
            $errors['method'] = 'Choose how the money was paid.';
        }

        $amountRaw = $get('amount');
        $cents = 0;
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amountRaw) || self::cents($amountRaw) <= 0) {
            $errors['amount'] = 'Enter the amount in PKR, e.g. 2500 (more than 0).';
        } else {
            $cents = self::cents($amountRaw);
            if (!isset($errors['type'])) {
                $due = self::cents($s['balance_due']);
                if (($type === 'advance' || $type === 'balance') && $cents > $due) {
                    $errors['amount'] = 'This is more than the balance due (' . money($s['balance_due']) . ').';
                } elseif ($type === 'deposit' && $cents > self::cents($s['deposit_required'] - $s['deposit_received'])) {
                    $errors['amount'] = 'This is more than the security deposit still due (' . money(max(0, $s['deposit_required'] - $s['deposit_received'])) . ').';
                } elseif ($type === 'refund' && $cents > self::cents($s['refundable_left'])) {
                    $errors['amount'] = 'You can refund at most ' . money($s['refundable_left']) . ' (what was received minus earlier refunds).';
                }
            }
        }

        $paidAt = date('Y-m-d H:i:s');
        $when = $get('paid_at');
        if ($when !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $when);
            if ($dt === false || $dt->format('Y-m-d\TH:i') !== $when) {
                $errors['paid_at'] = 'Enter a valid date and time.';
            } elseif ($dt > new DateTimeImmutable('+5 minutes')) {
                $errors['paid_at'] = 'The payment date cannot be in the future.';
            } else {
                $paidAt = $dt->format('Y-m-d H:i:s');
            }
        }

        $ref = preg_replace('/\s+/', ' ', $get('reference_no')) ?? '';
        if (mb_strlen($ref) > 80) {
            $errors['reference_no'] = 'The reference can be at most 80 characters.';
        }
        $notes = preg_replace('/\s+/', ' ', $get('notes')) ?? '';
        if (mb_strlen($notes) > 255) {
            $errors['notes'] = 'The note can be at most 255 characters.';
        }

        return [$errors, [
            'type' => $type, 'method' => $method, 'amount' => number_format($cents / 100, 2, '.', ''),
            'paid_at' => $paidAt, 'reference_no' => $ref, 'notes' => $notes,
        ]];
    }

    /**
     * Save one payment. The booking row is locked while the amounts are re-checked, so two admins
     * recording at once cannot overpay a booking.
     * @return array{ok:bool,errors:array<string,string>,id?:int}
     */
    public static function record(int $bookingId, int $adminId, array $in): array
    {
        $pdo = Database::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT booking_id, status, total_amount, security_deposit FROM bookings WHERE booking_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            $st->execute([$bookingId]);
            $b = $st->fetch();
            if (!$b) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['type' => 'That booking was not found.']];
            }
            [$errors, $c] = self::validate($in, $b);
            if ($errors) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => $errors];
            }
            $pdo->prepare(
                'INSERT INTO payments (booking_id, type, method, amount, reference_no, paid_at, received_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $bookingId, $c['type'], $c['method'], $c['amount'],
                $c['reference_no'] !== '' ? $c['reference_no'] : null, $c['paid_at'], $adminId,
                $c['notes'] !== '' ? $c['notes'] : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($c['type'] === 'deposit') {
                // The deposit is held separately and is not revenue. A-06 settles it later.
                $pdo->prepare("UPDATE security_deposits SET amount_held = amount_held + ?, status = 'held' WHERE booking_id = ? AND status IN ('pending','held')")
                    ->execute([$c['amount'], $bookingId]);
            }
            $pdo->commit();
            return ['ok' => true, 'errors' => [], 'id' => $id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Payment failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['type' => 'The payment could not be saved. Please try again.']];
        }
    }
}