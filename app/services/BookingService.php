<?php
declare(strict_types=1);

/** Turns the session cart into a pending booking request. */
final class BookingService
{
    public const EVENT_TYPES = [
        'wedding' => 'Wedding', 'party' => 'Party', 'family' => 'Family gathering',
        'daawat' => 'Daawat', 'corporate' => 'Corporate event', 'other' => 'Other',
    ];

    /**
     * Check the checkout form. $method is the cart's 'pickup' or 'delivery'.
     * @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values]
     */
    public static function validate(array $in, string $method): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];

        $type = $get('event_type');
        if (!isset(self::EVENT_TYPES[$type])) {
            $errors['event_type'] = 'Choose the type of event.';
        }
        $name = preg_replace('/\s+/', ' ', $get('event_name')) ?? '';
        if (mb_strlen($name) > 150) {
            $errors['event_name'] = 'Event name can be at most 150 characters.';
        }
        $guests = null;
        $g = $get('guest_count');
        if ($g !== '') {
            if (!ctype_digit($g) || (int) $g < 1 || (int) $g > 100000) {
                $errors['guest_count'] = 'Enter the number of guests as a whole number.';
            } else {
                $guests = (int) $g;
            }
        }
        $address = preg_replace('/\s+/', ' ', $get('delivery_address')) ?? '';
        if ($method === 'delivery') {
            if (mb_strlen($address) < 5 || mb_strlen($address) > 255) {
                $errors['delivery_address'] = 'Enter the full delivery address (5 to 255 characters).';
            }
        } else {
            $address = '';
        }
        $notes = $get('customer_notes');
        if (mb_strlen($notes) > 1000) {
            $errors['customer_notes'] = 'Notes can be at most 1000 characters.';
        }

        return [$errors, [
            'event_type' => $type, 'event_name' => $name, 'guest_count' => $guests,
            'delivery_address' => $address, 'customer_notes' => $notes,
        ]];
    }

    /**
     * Save the cart as a pending booking (pending bookings do not block stock; the admin's confirmation does).
     * Prices are recalculated here from the database, never taken from the browser.
     * @return array{ok:bool,error?:string,notes?:string[],booking_no?:string}
     */
    public static function create(int $userId, array $d): array
    {
        if (CartService::dates() === null || !CartService::items()) {
            return ['ok' => false, 'error' => 'Your cart is empty.', 'notes' => []];
        }
        // Re-check live stock one last time. If anything changed, send the customer back to the cart.
        $notes = CartService::clamp();
        if ($notes) {
            return ['ok' => false, 'error' => 'Stock changed while you were checking out. Please review your cart.', 'notes' => $notes];
        }
        $dates = CartService::dates();
        $lines = CartService::lines();
        if ($dates === null || !$lines) {
            return ['ok' => false, 'error' => 'Your cart is empty.', 'notes' => []];
        }
        $days     = AvailabilityService::rentalDays($dates['start'], $dates['end']);
        $delivery = CartService::delivery();
        $q        = PricingService::quote($lines, $days, $delivery['method'], $delivery['zone']);
        if ($q['error'] !== null) {
            return ['ok' => false, 'error' => $q['error'], 'notes' => []];
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO bookings (booking_no, user_id, event_type, event_name, guest_count, start_date, end_date,
                    rental_days, delivery_method, delivery_address, delivery_zone_id, subtotal, discount, delivery_fee,
                    total_amount, security_deposit, customer_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'T' . bin2hex(random_bytes(8)),   // temporary; replaced below once the id is known
                $userId, $d['event_type'], $d['event_name'] !== '' ? $d['event_name'] : null, $d['guest_count'],
                $dates['start'], $dates['end'], $days, $delivery['method'],
                $d['delivery_address'] !== '' ? $d['delivery_address'] : null,
                $delivery['method'] === 'delivery' ? $delivery['zone'] : null,
                $q['subtotal'], $q['discount'], $q['delivery_fee'], $q['total'], $q['deposit'],
                $d['customer_notes'] !== '' ? $d['customer_notes'] : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $no = 'DK-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE bookings SET booking_no = ? WHERE booking_id = ?')->execute([$no, $id]);

            $item = $pdo->prepare(
                'INSERT INTO booking_items (booking_id, product_id, quantity, unit_price, replacement_cost, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $l) {
                $item->execute([$id, $l['product_id'], $l['quantity'], $l['rent_per_day'], $l['replacement_cost'], $l['line_total']]);
            }
            $pdo->prepare("INSERT INTO security_deposits (booking_id, status) VALUES (?, 'pending')")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Booking failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'We could not save your booking. Please try again.', 'notes' => []];
        }

        CartService::clear();
        return ['ok' => true, 'booking_no' => $no];
    }

    public const HISTORY_PER_PAGE = 10;

    /** The customer may open an invoice once the booking is confirmed (not for pending, rejected or cancelled). */
    public static function invoiceAvailable(string $status): bool
    {
        return in_array($status, ['confirmed', 'delivered', 'return_pending', 'returned', 'completed'], true);
    }

    /** @return array{rows:array,total:int,page:int,pages:int} this customer's bookings, newest first */
    public static function history(int $userId, int $page): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE user_id = ?');
        $st->execute([$userId]);
        $total  = (int) $st->fetchColumn();
        $pages  = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page   = max(1, min($page, $pages));
        $limit  = self::HISTORY_PER_PAGE;
        $offset = ($page - 1) * $limit;   // integers only, safe to inline
        $st = $pdo->prepare(
            "SELECT booking_no, event_type, start_date, end_date, total_amount, status, created_at
             FROM bookings WHERE user_id = ? ORDER BY booking_id DESC LIMIT $limit OFFSET $offset"
        );
        $st->execute([$userId]);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** One of this customer's bookings, with its items. Null if it does not exist or belongs to someone else. */
    public static function find(string $bookingNo, int $userId): ?array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT b.*, z.city AS zone_city, z.area AS zone_area
             FROM bookings b LEFT JOIN delivery_zones z ON z.zone_id = b.delivery_zone_id
             WHERE b.booking_no = ? AND b.user_id = ?'
        );
        $st->execute([$bookingNo, $userId]);
        $b = $st->fetch();
        if (!$b) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT bi.quantity, bi.unit_price, bi.line_total, p.name, p.sku
             FROM booking_items bi JOIN products p ON p.product_id = bi.product_id
             WHERE bi.booking_id = ? ORDER BY p.name'
        );
        $st->execute([$b['booking_id']]);
        $b['items'] = $st->fetchAll();
        return $b;
    }
}