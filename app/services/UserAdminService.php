<?php
declare(strict_types=1);

/**
 * Admin: customer management and admin accounts (A-09).
 * Blocking sets users.status = 'blocked'. Auth already refuses blocked users at login and signs them out on
 * their next page, so no other check is needed. Existing bookings of a blocked customer are not changed.
 */
final class UserAdminService
{
    public const PER_PAGE = 15;

    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** @return array{all:int,active:int,blocked:int} number of accounts of this role ('customer' or 'admin') */
    public static function counts(string $role): array
    {
        $st = Database::pdo()->prepare('SELECT status, COUNT(*) FROM users WHERE role = ? GROUP BY status');
        $st->execute([$role]);
        $out = ['active' => 0, 'blocked' => 0];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$status, $n]) {
            $out[$status] = (int) $n;
        }
        return ['all' => $out['active'] + $out['blocked']] + $out;
    }

    /** @return array{rows:array,total:int,page:int,pages:int} accounts of one role, newest first, with their booking count */
    public static function search(string $role, string $status, string $q, int $page): array
    {
        $where = ['u.role = ?'];
        $params = [$role];
        if (in_array($status, ['active', 'blocked'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::pdo();

        $st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
        $st->execute($params);
        $total  = (int) $st->fetchColumn();
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = max(1, min($page, $pages));
        $limit  = self::PER_PAGE;
        $offset = ($page - 1) * $limit;   // integers only, safe to inline

        $st = $pdo->prepare(
            "SELECT u.user_id, u.full_name, u.email, u.phone, u.status, u.created_at,
                    (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.user_id) AS bookings
             FROM users u WHERE $whereSql ORDER BY u.created_at DESC, u.user_id DESC LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Every admin account, newest first. A shop has only a handful. */
    public static function allAdmins(): array
    {
        return Database::pdo()->query(
            "SELECT user_id, full_name, email, phone, status, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC, user_id DESC"
        )->fetchAll();
    }

    /** One account without its password hash, or null. */
    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT user_id, full_name, email, phone, role, address, city, status, created_at FROM users WHERE user_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * A customer's history in numbers.
     *   paid       rental payments (advance, balance) plus late fees and damage charges they paid, in PKR
     *   damage     damage charges recorded at returns, late_fees the late fees recorded at returns
     * @return array{bookings:int,paid:float,damage:float,late_fees:float,last_booking:?string}
     */
    public static function stats(int $userId): array
    {
        $pdo = Database::pdo();
        $one = function (string $sql) use ($pdo, $userId) {
            $st = $pdo->prepare($sql);
            $st->execute([$userId]);
            return $st->fetchColumn();
        };
        $paid = $one("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN bookings b ON b.booking_id = p.booking_id
                      WHERE b.user_id = ? AND p.type IN ('advance','balance','late_fee','damage_charge')");
        $damage = $one('SELECT COALESCE(SUM(ri.damage_charge), 0) FROM return_items ri JOIN returns r ON r.return_id = ri.return_id
                        JOIN bookings b ON b.booking_id = r.booking_id WHERE b.user_id = ?');
        $late = $one('SELECT COALESCE(SUM(r.late_fee), 0) FROM returns r JOIN bookings b ON b.booking_id = r.booking_id WHERE b.user_id = ?');
        $last = $one('SELECT MAX(created_at) FROM bookings WHERE user_id = ?');
        return [
            'bookings'     => (int) $one('SELECT COUNT(*) FROM bookings WHERE user_id = ?'),
            'paid'         => self::cents($paid) / 100.0,
            'damage'       => self::cents($damage) / 100.0,
            'late_fees'    => self::cents($late) / 100.0,
            'last_booking' => $last !== false && $last !== null ? (string) $last : null,
        ];
    }

    /** The customer's bookings, newest first. */
    public static function bookings(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));   // integer, safe to inline
        $st = Database::pdo()->prepare(
            "SELECT booking_id, booking_no, event_type, start_date, end_date, total_amount, status, created_at
             FROM bookings WHERE user_id = ? ORDER BY booking_id DESC LIMIT $limit"
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /**
     * Block or unblock an account of $role ('customer' on the customers page, 'admin' on the admin accounts page).
     * @return string|null an error message, or null when the status was changed
     */
    public static function setStatus(int $targetId, string $status, int $actingAdminId, string $role): ?string
    {
        if (!in_array($status, ['active', 'blocked'], true)) {
            return 'Unknown status.';
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT role, status FROM users WHERE user_id = ?');
        $st->execute([$actingAdminId]);
        $acting = $st->fetch();
        if (!$acting || $acting['role'] !== 'admin' || $acting['status'] !== 'active') {
            return 'Only an active admin can do this.';
        }
        $st->execute([$targetId]);
        $target = $st->fetch();
        if (!$target || $target['role'] !== $role) {
            return 'That account was not found.';
        }
        if ($targetId === $actingAdminId) {
            return 'You cannot block your own account.';
        }
        if ($target['status'] === $status) {
            return $status === 'blocked' ? 'This account is already blocked.' : 'This account is already active.';
        }
        $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ? AND role = ?')->execute([$status, $targetId, $role]);
        return null;
    }
}