<?php
declare(strict_types=1);

/**
 * Admin reports (A-11). Every report is an array that both the page and the CSV export use:
 *   title, intro, note, columns [key,label,type], rows [key => value], totals [key => value] or null,
 *   and for outstanding payments an extra "by_customer" summary.
 * Column types: text, int, money (PKR), percent.
 * "Billed" bookings are confirmed, delivered, return_pending, returned and completed ones: pending, rejected
 * and cancelled bookings are not income. Security deposits are never income.
 */
final class ReportService
{
    public const TYPES = [
        'revenue'     => 'Revenue',
        'popular'     => 'Popular items',
        'utilization' => 'Utilization',
        'outstanding' => 'Outstanding payments',
        'damage'      => 'Damage and loss',
        'customers'   => 'Customer history',
    ];
    /** Outstanding payments is a snapshot of today, so it has no date range. */
    public const UNDATED = ['outstanding'];
    public const MAX_DAYS = 1830;   // about 5 years
    public const GROUPS = ['day' => 'Day', 'month' => 'Month', 'year' => 'Year'];

    private const BILLED = "'confirmed','delivered','return_pending','returned','completed'";

    private static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    private static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /** Sum the given columns over all rows into a totals row. */
    private static function totals(array $rows, array $sumKeys): array
    {
        $t = [];
        foreach ($sumKeys as $k) {
            $t[$k] = round(array_sum(array_column($rows, $k)), 2);
        }
        return $t;
    }

    private static function nextDay(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    /* ================= date range ================= */

    private static function validDate(string $s): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }

    /**
     * Check the date range from the form. On any problem the current month is used and an error is returned.
     * @return array{from:string,to:string,error:?string}
     */
    public static function range(string $from, string $to, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $default = ['from' => substr($today, 0, 8) . '01', 'to' => $today, 'error' => null];
        if ($from === '' && $to === '') {
            return $default;
        }
        if (!self::validDate($from) || !self::validDate($to)) {
            return ['error' => 'Enter both dates, for example 2026-11-01.'] + $default;
        }
        if ($from > $to) {
            return ['error' => 'The start date must not be after the end date.'] + $default;
        }
        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a') + 1;
        if ($days > self::MAX_DAYS) {
            return ['error' => 'Choose a period of at most 5 years.'] + $default;
        }
        return ['from' => $from, 'to' => $to, 'error' => null];
    }

    /** Quick date ranges: label, from, to. */
    public static function presets(?string $today = null): array
    {
        $t = new DateTimeImmutable($today ?? date('Y-m-d'));
        $lastMonth = $t->modify('first day of last month');
        return [
            ['label' => 'This month', 'from' => $t->format('Y-m-01'), 'to' => $t->format('Y-m-d')],
            ['label' => 'Last month', 'from' => $lastMonth->format('Y-m-01'), 'to' => $lastMonth->format('Y-m-t')],
            ['label' => 'This year',  'from' => $t->format('Y-01-01'), 'to' => $t->format('Y-m-d')],
            ['label' => 'Last year',  'from' => ($t->format('Y') - 1) . '-01-01', 'to' => ($t->format('Y') - 1) . '-12-31'],
        ];
    }

    /* ================= running a report ================= */

    public static function run(string $type, string $from, string $to, string $group = 'month'): array
    {
        return match ($type) {
            'popular'     => self::popular($from, $to),
            'utilization' => self::utilization($from, $to),
            'outstanding' => self::outstanding(),
            'damage'      => self::damage($from, $to),
            'customers'   => self::customers($from, $to),
            default       => self::revenue($from, $to, $group),
        };
    }

    /**
     * Income by day, month or year.
     * Earned: rental (subtotal minus discount) and delivery fees on the event start date of billed bookings;
     * late fees and damage charges on the return date. Received: money collected in the period.
     */
    public static function revenue(string $from, string $to, string $group = 'month'): array
    {
        $fmt = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$group] ?? '%Y-%m';   // whitelist, safe to inline
        $pdo = Database::pdo();
        $start = $from . ' 00:00:00';
        $end = self::nextDay($to) . ' 00:00:00';
        $rows = [];
        $row = function (string $p) use (&$rows): void {
            $rows[$p] ??= ['period' => $p, 'rental' => 0.0, 'delivery' => 0.0, 'late' => 0.0, 'damage' => 0.0, 'earned' => 0.0, 'received' => 0.0];
        };

        $st = $pdo->prepare("SELECT DATE_FORMAT(start_date, '$fmt') AS p, SUM(subtotal - discount) AS rental, SUM(delivery_fee) AS delivery
                             FROM bookings WHERE status IN (" . self::BILLED . ') AND start_date BETWEEN ? AND ? GROUP BY p');
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $row($r['p']);
            $rows[$r['p']]['rental'] = self::money($r['rental']);
            $rows[$r['p']]['delivery'] = self::money($r['delivery']);
        }

        $st = $pdo->prepare("SELECT DATE_FORMAT(r.returned_at, '$fmt') AS p, SUM(r.late_fee) AS late,
                                    SUM(COALESCE((SELECT SUM(ri.damage_charge) FROM return_items ri WHERE ri.return_id = r.return_id), 0)) AS damage
                             FROM returns r WHERE r.returned_at >= ? AND r.returned_at < ? GROUP BY p");
        $st->execute([$start, $end]);
        foreach ($st->fetchAll() as $r) {
            $row($r['p']);
            $rows[$r['p']]['late'] = self::money($r['late']);
            $rows[$r['p']]['damage'] = self::money($r['damage']);
        }

        $st = $pdo->prepare("SELECT DATE_FORMAT(paid_at, '$fmt') AS p, SUM(amount) AS s FROM payments
                             WHERE type IN ('advance','balance','late_fee','damage_charge') AND paid_at >= ? AND paid_at < ? GROUP BY p");
        $st->execute([$start, $end]);
        foreach ($st->fetchAll() as $r) {
            $row($r['p']);
            $rows[$r['p']]['received'] += self::money($r['s']);
        }
        // Deposit money kept to cover return charges is income too, on the day the deposit was settled.
        $st = $pdo->prepare("SELECT DATE_FORMAT(settled_at, '$fmt') AS p, SUM(amount_deducted) AS s FROM security_deposits
                             WHERE settled_at >= ? AND settled_at < ? GROUP BY p");
        $st->execute([$start, $end]);
        foreach ($st->fetchAll() as $r) {
            $row($r['p']);
            $rows[$r['p']]['received'] += self::money($r['s']);
        }

        ksort($rows);
        foreach ($rows as &$r) {
            $r['earned'] = round($r['rental'] + $r['delivery'] + $r['late'] + $r['damage'], 2);
            $r['received'] = round($r['received'], 2);
        }
        $rows = array_values($rows);
        return [
            'title' => 'Revenue by ' . strtolower(self::GROUPS[$group] ?? 'Month'),
            'intro' => 'Income earned and money received, with late fees, damage charges and delivery fees as separate lines.',
            'note'  => 'Earned: rental (after discount) and delivery fees are counted on the event start date of confirmed and later bookings; '
                     . 'late fees and damage charges on the return date. Received: advance, balance, late fee and damage payments plus deposit money '
                     . 'kept for charges, before refunds. Security deposits held for customers are never income.',
            'columns' => [
                self::col('period', 'Period'), self::col('rental', 'Rental', 'money'), self::col('delivery', 'Delivery fees', 'money'),
                self::col('late', 'Late fees', 'money'), self::col('damage', 'Damage charges', 'money'),
                self::col('earned', 'Total earned', 'money'), self::col('received', 'Money received', 'money'),
            ],
            'rows' => $rows,
            'totals' => self::totals($rows, ['rental', 'delivery', 'late', 'damage', 'earned', 'received']),
        ];
    }

    /** Most rented items by units, for bookings that start in the period. */
    public static function popular(string $from, string $to): array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.name, p.sku, c.name AS category, COUNT(DISTINCT b.booking_id) AS bookings,
                    SUM(bi.quantity) AS units, SUM(bi.line_total) AS revenue
             FROM booking_items bi
             JOIN bookings b ON b.booking_id = bi.booking_id
             JOIN products p ON p.product_id = bi.product_id
             JOIN categories c ON c.category_id = p.category_id
             WHERE b.status IN (' . self::BILLED . ') AND b.start_date BETWEEN ? AND ?
             GROUP BY p.product_id, p.name, p.sku, c.name
             ORDER BY units DESC, revenue DESC, p.name LIMIT 100'
        );
        $st->execute([$from, $to]);
        $rows = array_map(fn($r) => [
            'name' => $r['name'], 'sku' => $r['sku'], 'category' => $r['category'],
            'bookings' => (int) $r['bookings'], 'units' => (int) $r['units'], 'revenue' => self::money($r['revenue']),
        ], $st->fetchAll());
        return [
            'title' => 'Popular items',
            'intro' => 'The most rented items, by units, for bookings whose event starts in this period.',
            'note'  => 'Counts confirmed and later bookings only. Revenue is the item line totals (before any package discount). Shows the top 100.',
            'columns' => [
                self::col('name', 'Item'), self::col('sku', 'SKU'), self::col('category', 'Category'),
                self::col('bookings', 'Bookings', 'int'), self::col('units', 'Units rented', 'int'), self::col('revenue', 'Rental revenue', 'money'),
            ],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    /** How much of each item's capacity was out on rent, and which items sat idle. */
    public static function utilization(string $from, string $to): array
    {
        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a') + 1;
        $st = Database::pdo()->prepare(
            'SELECT p.name, p.sku, p.total_quantity,
                    COALESCE(SUM(bi.quantity * GREATEST(0, DATEDIFF(LEAST(b.end_date, ?), GREATEST(b.start_date, ?)) + 1)), 0) AS unit_days
             FROM products p
             LEFT JOIN booking_items bi ON bi.product_id = p.product_id
             LEFT JOIN bookings b ON b.booking_id = bi.booking_id AND b.status IN (' . self::BILLED . ') AND b.start_date <= ? AND b.end_date >= ?
             WHERE p.is_active = 1 AND p.total_quantity > 0
             GROUP BY p.product_id, p.name, p.sku, p.total_quantity'
        );
        $st->execute([$to, $from, $to, $from]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $unitDays = (int) $r['unit_days'];
            $capacity = (int) $r['total_quantity'] * $days;
            $rows[] = [
                'name' => $r['name'], 'sku' => $r['sku'], 'owned' => (int) $r['total_quantity'],
                'unit_days' => $unitDays, 'capacity' => $capacity,
                'percent' => $capacity > 0 ? round($unitDays * 100 / $capacity, 1) : 0.0,
                'idle' => $unitDays === 0 ? 'Idle' : '',
            ];
        }
        usort($rows, fn($a, $b) => [$b['percent'], $a['name']] <=> [$a['percent'], $b['name']]);
        return [
            'title' => 'Utilization',
            'intro' => 'Unit-days out on rent compared with unit-days available, over ' . $days . ' day' . ($days === 1 ? '' : 's') . '.',
            'note'  => 'Utilization = units rented x rental days that fall in the period, divided by units owned x days in the period. '
                     . 'Uses the stock you own today, and counts confirmed and later bookings. Idle means the item was never rented in the period.',
            'columns' => [
                self::col('name', 'Item'), self::col('sku', 'SKU'), self::col('owned', 'Units owned', 'int'),
                self::col('unit_days', 'Unit-days rented', 'int'), self::col('capacity', 'Unit-days available', 'int'),
                self::col('percent', 'Utilization', 'percent'), self::col('idle', 'Idle'),
            ],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    /**
     * Unpaid money on billed bookings, as of today: the rental balance, plus return charges (late fee and damage)
     * that were more than the deposit and have not been paid. By booking, with a summary by customer.
     */
    public static function outstanding(): array
    {
        $st = Database::pdo()->query(
            "SELECT b.booking_no, b.status, b.start_date, b.total_amount, u.user_id, u.full_name, u.phone,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.booking_id AND p.type IN ('advance','balance')), 0) AS paid,
                    COALESCE((SELECT SUM(r.late_fee) FROM returns r WHERE r.booking_id = b.booking_id), 0)
                      + COALESCE((SELECT SUM(ri.damage_charge) FROM return_items ri JOIN returns r ON r.return_id = ri.return_id WHERE r.booking_id = b.booking_id), 0) AS charges,
                    COALESCE((SELECT sd.amount_held FROM security_deposits sd WHERE sd.booking_id = b.booking_id), 0) AS held,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.booking_id AND p.type IN ('damage_charge','late_fee')), 0) AS extra_paid
             FROM bookings b JOIN users u ON u.user_id = b.user_id
             WHERE b.status IN (" . self::BILLED . ')
             ORDER BY u.full_name, b.start_date, b.booking_id'
        );
        $rows = [];
        $byCustomer = [];
        foreach ($st->fetchAll() as $r) {
            $total = self::money($r['total_amount']);
            $paid = self::money($r['paid']);
            $balance = max(0.0, round($total - $paid, 2));
            $extraDue = max(0.0, round(max(0.0, (float) $r['charges'] - (float) $r['held']) - (float) $r['extra_paid'], 2));
            $due = round($balance + $extraDue, 2);
            if ($due <= 0) {
                continue;
            }
            $rows[] = [
                'booking_no' => $r['booking_no'], 'customer' => $r['full_name'], 'phone' => $r['phone'],
                'status' => BookingAdminService::statusLabel($r['status']), 'start_date' => $r['start_date'],
                'total' => $total, 'paid' => $paid, 'balance' => $balance, 'extra' => $extraDue, 'due' => $due,
            ];
            $k = (int) $r['user_id'];
            $byCustomer[$k] ??= ['customer' => $r['full_name'], 'phone' => $r['phone'], 'bookings' => 0, 'due' => 0.0];
            $byCustomer[$k]['bookings']++;
            $byCustomer[$k]['due'] = round($byCustomer[$k]['due'] + $due, 2);
        }
        usort($byCustomer, fn($a, $b) => [$b['due'], $a['customer']] <=> [$a['due'], $b['customer']]);
        return [
            'title' => 'Outstanding payments',
            'intro' => 'Unpaid balances by booking and by customer, as of today.',
            'note'  => 'Balance due is the booking total minus advance and balance payments. Extra charges are late fees and damage charges '
                     . 'from the return that were more than the security deposit and are not yet paid. Pending, rejected and cancelled bookings are not listed.',
            'columns' => [
                self::col('booking_no', 'Booking'), self::col('customer', 'Customer'), self::col('phone', 'Phone'),
                self::col('status', 'Status'), self::col('start_date', 'Event date'), self::col('total', 'Total', 'money'),
                self::col('paid', 'Paid', 'money'), self::col('balance', 'Balance due', 'money'),
                self::col('extra', 'Extra charges due', 'money'), self::col('due', 'Outstanding', 'money'),
            ],
            'rows' => $rows,
            'totals' => self::totals($rows, ['total', 'paid', 'balance', 'extra', 'due']),
            'by_customer' => [
                'title' => 'By customer',
                'columns' => [self::col('customer', 'Customer'), self::col('phone', 'Phone'), self::col('bookings', 'Bookings', 'int'), self::col('due', 'Outstanding', 'money')],
                'rows' => $byCustomer,
            ],
        ];
    }

    /** Damaged and missing units recorded at returns in the period, with what was charged and what was written off. */
    public static function damage(string $from, string $to): array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.name, p.sku, SUM(ri.qty_damaged) AS damaged, SUM(ri.qty_missing) AS missing,
                    SUM((ri.qty_damaged + ri.qty_missing) * bi.replacement_cost) AS loss_value, SUM(ri.damage_charge) AS charged
             FROM return_items ri
             JOIN returns r ON r.return_id = ri.return_id
             JOIN booking_items bi ON bi.booking_item_id = ri.booking_item_id
             JOIN products p ON p.product_id = bi.product_id
             WHERE r.returned_at >= ? AND r.returned_at < ? AND (ri.qty_damaged > 0 OR ri.qty_missing > 0 OR ri.damage_charge > 0)
             GROUP BY p.product_id, p.name, p.sku
             ORDER BY loss_value DESC, p.name'
        );
        $st->execute([$from . ' 00:00:00', self::nextDay($to) . ' 00:00:00']);
        $rows = array_map(function ($r) {
            $loss = self::money($r['loss_value']);
            $charged = self::money($r['charged']);
            return [
                'name' => $r['name'], 'sku' => $r['sku'], 'damaged' => (int) $r['damaged'], 'missing' => (int) $r['missing'],
                'loss' => $loss, 'charged' => $charged, 'written_off' => max(0.0, round($loss - $charged, 2)),
            ];
        }, $st->fetchAll());
        return [
            'title' => 'Damage and loss',
            'intro' => 'Items damaged or missing when they came back in this period.',
            'note'  => 'Replacement value = damaged and missing units x the replacement cost saved on the booking. Charged is what was billed to customers '
                     . 'at the return (taken from the deposit or paid as extra). Written off = replacement value minus charged, never below zero. Late fees are not included.',
            'columns' => [
                self::col('name', 'Item'), self::col('sku', 'SKU'), self::col('damaged', 'Damaged', 'int'), self::col('missing', 'Missing', 'int'),
                self::col('loss', 'Replacement value', 'money'), self::col('charged', 'Charged to customers', 'money'), self::col('written_off', 'Written off', 'money'),
            ],
            'rows' => $rows,
            'totals' => self::totals($rows, ['damaged', 'missing', 'loss', 'charged', 'written_off']),
        ];
    }

    /** Each customer with bookings that start in the period: bookings, billed, paid, damage and late fees. */
    public static function customers(string $from, string $to): array
    {
        $st = Database::pdo()->prepare(
            "SELECT b.status, b.total_amount, u.user_id, u.full_name, u.phone,
                    COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.booking_id AND p.type IN ('advance','balance','late_fee','damage_charge')), 0) AS paid,
                    COALESCE((SELECT SUM(ri.damage_charge) FROM return_items ri JOIN returns r ON r.return_id = ri.return_id WHERE r.booking_id = b.booking_id), 0) AS damage,
                    COALESCE((SELECT SUM(r.late_fee) FROM returns r WHERE r.booking_id = b.booking_id), 0) AS late
             FROM bookings b JOIN users u ON u.user_id = b.user_id
             WHERE b.start_date BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        $billed = ['confirmed', 'delivered', 'return_pending', 'returned', 'completed'];
        $by = [];
        foreach ($st->fetchAll() as $r) {
            $k = (int) $r['user_id'];
            $by[$k] ??= ['customer' => $r['full_name'], 'phone' => $r['phone'], 'bookings' => 0, 'billed' => 0.0, 'paid' => 0.0, 'damage' => 0.0, 'late' => 0.0];
            $by[$k]['bookings']++;
            if (in_array($r['status'], $billed, true)) {
                $by[$k]['billed'] += (float) $r['total_amount'];
            }
            $by[$k]['paid'] += (float) $r['paid'];
            $by[$k]['damage'] += (float) $r['damage'];
            $by[$k]['late'] += (float) $r['late'];
        }
        $rows = array_values(array_map(fn($c) => array_merge($c, [
            'billed' => round($c['billed'], 2), 'paid' => round($c['paid'], 2), 'damage' => round($c['damage'], 2), 'late' => round($c['late'], 2),
        ]), $by));
        usort($rows, fn($a, $b) => [$b['billed'], $a['customer']] <=> [$a['billed'], $b['customer']]);
        return [
            'title' => 'Customer history',
            'intro' => 'Customers with bookings whose event starts in this period.',
            'note'  => 'Bookings counts every status. Billed is the total of confirmed and later bookings. Paid is advance, balance, late fee and damage payments '
                     . 'received, before refunds. Damage and late fees are those recorded at returns.',
            'columns' => [
                self::col('customer', 'Customer'), self::col('phone', 'Phone'), self::col('bookings', 'Bookings', 'int'),
                self::col('billed', 'Billed', 'money'), self::col('paid', 'Paid', 'money'),
                self::col('damage', 'Damage charges', 'money'), self::col('late', 'Late fees', 'money'),
            ],
            'rows' => $rows,
            'totals' => self::totals($rows, ['bookings', 'billed', 'paid', 'damage', 'late']),
        ];
    }

    /* ================= CSV ================= */

    /** One CSV cell. Text that starts with = + - @ is prefixed so spreadsheets do not run it as a formula. */
    private static function cell(mixed $v, string $type): string
    {
        return match ($type) {
            'money'   => number_format((float) $v, 2, '.', ''),
            'percent' => number_format((float) $v, 1, '.', ''),
            'int'     => (string) (int) $v,
            default   => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v,
        };
    }

    /** The report as CSV text: UTF-8 with a byte order mark (so Excel reads Urdu names), a header row, the rows and a totals row. */
    public static function toCsv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        $put = fn(array $cells) => fputcsv($out, $cells, ',', '"', '', "\r\n");
        $put(array_map(fn($c) => self::cell($c['label'], 'text'), $report['columns']));
        foreach ($report['rows'] as $row) {
            $put(array_map(fn($c) => self::cell($row[$c['key']] ?? '', $c['type']), $report['columns']));
        }
        if (!empty($report['totals']) && $report['rows']) {
            $cells = [];
            foreach ($report['columns'] as $i => $c) {
                $cells[] = $i === 0 ? 'Total' : (array_key_exists($c['key'], $report['totals']) ? self::cell($report['totals'][$c['key']], $c['type']) : '');
            }
            $put($cells);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return "\xEF\xBB\xBF" . $csv;
    }
}