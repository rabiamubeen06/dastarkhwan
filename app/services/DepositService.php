<?php
declare(strict_types=1);

/**
 * Admin: security deposit tracking and settlement (A-06).
 * The deposit is held separately and is not revenue. After the return (A-07):
 *   charges (damage + late fee) come out of the deposit first,
 *   anything above the deposit is "extra owed" by the customer (collected as damage charge / late fee payments),
 *   whatever is left of the deposit is refunded and recorded as a refund payment.
 * A booking is settled once, and becomes "completed" when the balance, the extra charges and the deposit are all settled.
 */
final class DepositService
{
    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function find(int $bookingId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM security_deposits WHERE booking_id = ?');
        $st->execute([$bookingId]);
        return $st->fetch() ?: null;
    }

    /** Status of the deposit once settled, from how much was deducted and refunded. */
    public static function settledStatus(int $heldCents, int $deductCents): string
    {
        if ($heldCents <= 0) {
            return 'pending';                       // nothing was ever held
        }
        if ($deductCents <= 0) {
            return 'refunded';
        }
        return $deductCents >= $heldCents ? 'forfeited' : 'partially_refunded';
    }

    public static function statusLabel(string $status, bool $settled = true): string
    {
        return match ($status) {
            'pending' => $settled ? 'Nothing held' : 'Not received',
            'held' => 'Held',
            'partially_refunded' => 'Partly refunded',
            'refunded' => 'Refunded',
            'forfeited' => 'Used for charges',
            default => ucfirst($status),
        };
    }

    /**
     * Everything the deposit panel needs. $booking is a row from BookingAdminService::find().
     * All amounts are PKR floats; deduct_now / refund_now are what settling would do today.
     */
    public static function view(array $booking): array
    {
        $id = (int) $booking['booking_id'];
        $d = self::find($id) ?? ['amount_held' => 0, 'amount_deducted' => 0, 'amount_refunded' => 0, 'status' => 'pending', 'settled_at' => null];
        $ret = ReturnService::forBooking($id);
        $held = self::cents($d['amount_held']);
        $charges = $ret ? self::cents($ret['charges_total']) : 0;
        $settled = $d['settled_at'] !== null;

        $deduct = $settled ? self::cents($d['amount_deducted']) : min($charges, $held);
        $refund = $settled ? self::cents($d['amount_refunded']) : max(0, $held - $deduct);
        $extra = max(0, $charges - $held);
        $st = Database::pdo()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND type IN ('damage_charge','late_fee')");
        $st->execute([$id]);
        $extraPaid = self::cents($st->fetchColumn());

        return [
            'required'    => self::cents($booking['security_deposit']) / 100,
            'held'        => $held / 100,
            'status'      => $d['status'],
            'status_label' => self::statusLabel($d['status'], $settled),
            'has_return'  => $ret !== null,
            'charges'     => $charges / 100,
            'deduct'      => $deduct / 100,
            'refund'      => $refund / 100,
            'extra_owed'  => $extra / 100,
            'extra_paid'  => $extraPaid / 100,
            'extra_due'   => max(0, $extra - $extraPaid) / 100,
            'settled'     => $settled,
            'settled_at'  => $d['settled_at'],
            'can_settle'  => !$settled && $ret !== null && $booking['status'] === 'returned',
        ];
    }

    /**
     * Settle the deposit: deduct the return charges, refund the rest and record the refund as a payment.
     * @param array $in method, reference_no, paid_at, notes (only needed when something is refunded)
     * @return array{ok:bool,errors:array<string,string>,refund?:float,deducted?:float,extra_owed?:float,completed?:bool}
     */
    public static function settle(int $bookingId, int $adminId, array $in): array
    {
        $pdo = Database::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $lock = $mysql ? ' FOR UPDATE' : '';
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT booking_id, booking_no, status, total_amount, security_deposit FROM bookings WHERE booking_id = ?' . $lock);
            $st->execute([$bookingId]);
            $b = $st->fetch();
            if (!$b) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['form' => 'That booking was not found.']];
            }
            if ($b['status'] !== 'returned') {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['form' => 'The deposit can be settled once the items are received back. This booking is ' . strtolower(BookingAdminService::statusLabel($b['status'])) . '.']];
            }
            $st = $pdo->prepare('SELECT * FROM security_deposits WHERE booking_id = ?' . $lock);
            $st->execute([$bookingId]);
            $d = $st->fetch();
            if (!$d) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['form' => 'This booking has no security deposit record.']];
            }
            if ($d['settled_at'] !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['form' => 'The deposit has already been settled.']];
            }
            $ret = ReturnService::forBooking($bookingId);
            if ($ret === null) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['form' => 'Record the return first.']];
            }

            $held = self::cents($d['amount_held']);
            $charges = self::cents($ret['charges_total']);
            $deduct = min($charges, $held);
            $refund = $held - $deduct;
            $extra = max(0, $charges - $held);
            $now = date('Y-m-d H:i:s');

            if ($refund > 0) {
                // The refund goes through the normal payment checks (method, date, never more than was received).
                [$errors, $c] = PaymentService::validate([
                    'type' => 'refund', 'method' => $in['method'] ?? '', 'amount' => number_format($refund / 100, 2, '.', ''),
                    'paid_at' => $in['paid_at'] ?? '', 'reference_no' => $in['reference_no'] ?? '', 'notes' => $in['notes'] ?? '',
                ], $b);
                if ($errors) {
                    $pdo->rollBack();
                    return ['ok' => false, 'errors' => $errors];
                }
                $pdo->prepare(
                    'INSERT INTO payments (booking_id, type, method, amount, reference_no, paid_at, received_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $bookingId, 'refund', $c['method'], $c['amount'], $c['reference_no'] !== '' ? $c['reference_no'] : null,
                    $c['paid_at'], $adminId, $c['notes'] !== '' ? $c['notes'] : 'Security deposit refund',
                ]);
            }

            $pdo->prepare('UPDATE security_deposits SET amount_deducted = ?, amount_refunded = ?, status = ?, settled_at = ? WHERE booking_id = ? AND settled_at IS NULL')
                ->execute([
                    number_format($deduct / 100, 2, '.', ''), number_format($refund / 100, 2, '.', ''),
                    self::settledStatus($held, $deduct), $now, $bookingId,
                ]);

            $line = 'Deposit settled: ' . money($deduct / 100) . ' deducted, ' . money($refund / 100) . ' refunded'
                  . ($extra > 0 ? ', ' . money($extra / 100) . ' extra owed by the customer' : '');
            $pdo->prepare('UPDATE bookings SET admin_notes = ? WHERE booking_id = ?')
                ->execute([BookingAdminService::appendNote($bookingId, $adminId, $line, ''), $bookingId]);

            $completed = self::completeIfSettled($bookingId, $adminId);
            $pdo->commit();
            return ['ok' => true, 'errors' => [], 'refund' => $refund / 100, 'deducted' => $deduct / 100, 'extra_owed' => $extra / 100, 'completed' => $completed];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Deposit settlement failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['form' => 'The deposit could not be settled. Please try again.']];
        }
    }

    /**
     * returned -> completed once the deposit is settled and nothing is owed (balance and extra charges are paid).
     * Call inside the caller's transaction, with the booking row already locked.
     */
    public static function completeIfSettled(int $bookingId, int $adminId): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM bookings WHERE booking_id = ?');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b || $b['status'] !== 'returned') {
            return false;
        }
        $v = self::view($b);
        if (!$v['settled'] || $v['extra_due'] > 0 || PaymentService::summary($b)['balance_due'] > 0) {
            return false;
        }
        $pdo->prepare("UPDATE bookings SET status = 'completed', admin_notes = ? WHERE booking_id = ? AND status = 'returned'")
            ->execute([BookingAdminService::appendNote($bookingId, $adminId, 'Completed: payments and deposit settled', ''), $bookingId]);
        return true;
    }
}