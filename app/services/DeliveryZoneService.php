<?php
declare(strict_types=1);

/** Admin: delivery zones and fees (A-12). Zones used by bookings are hidden, never deleted. */
final class DeliveryZoneService
{
    /** @return array<int,array<string,mixed>> every zone with the number of bookings that used it */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT z.zone_id, z.city, z.area, z.fee, z.is_active, COUNT(b.booking_id) AS bookings
             FROM delivery_zones z LEFT JOIN bookings b ON b.delivery_zone_id = z.zone_id
             GROUP BY z.zone_id, z.city, z.area, z.fee, z.is_active
             ORDER BY z.city, z.area'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT zone_id, city, area, fee, is_active FROM delivery_zones WHERE zone_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values] */
    public static function validate(array $in, ?int $id): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];

        $city = preg_replace('/\s+/', ' ', $get('city')) ?? '';
        $area = preg_replace('/\s+/', ' ', $get('area')) ?? '';
        if ($city === '' || mb_strlen($city) > 80) {
            $errors['city'] = 'Enter a city (up to 80 characters).';
        }
        if ($area === '' || mb_strlen($area) > 100) {
            $errors['area'] = 'Enter an area (up to 100 characters).';
        }
        $fee = $get('fee');
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $fee)) {
            $errors['fee'] = 'Enter the delivery fee in PKR, e.g. 500. Use 0 for free delivery.';
        }
        if (!isset($errors['city']) && !isset($errors['area'])) {
            $st = Database::pdo()->prepare('SELECT 1 FROM delivery_zones WHERE LOWER(city) = LOWER(?) AND LOWER(area) = LOWER(?) AND zone_id <> ?');
            $st->execute([$city, $area, $id ?? 0]);
            if ($st->fetchColumn()) {
                $errors['area'] = 'This area already exists for ' . $city . '.';
            }
        }
        return [$errors, ['city' => $city, 'area' => $area, 'fee' => $fee === '' ? '0' : $fee]];
    }

    /** @return array{id:int,errors:array<string,string>} */
    public static function save(array $z, ?int $id): array
    {
        $pdo = Database::pdo();
        try {
            if ($id === null) {
                $pdo->prepare('INSERT INTO delivery_zones (city, area, fee) VALUES (?, ?, ?)')
                    ->execute([$z['city'], $z['area'], $z['fee']]);
                return ['id' => (int) $pdo->lastInsertId(), 'errors' => []];
            }
            $pdo->prepare('UPDATE delivery_zones SET city = ?, area = ?, fee = ? WHERE zone_id = ?')
                ->execute([$z['city'], $z['area'], $z['fee'], $id]);
            return ['id' => $id, 'errors' => []];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['id' => 0, 'errors' => ['area' => 'This area already exists for ' . $z['city'] . '.']];
            }
            throw $e;
        }
    }

    public static function setActive(int $id, bool $on): void
    {
        Database::pdo()->prepare('UPDATE delivery_zones SET is_active = ? WHERE zone_id = ?')->execute([$on ? 1 : 0, $id]);
    }

    /** Returns an error message, or null when the zone was deleted. */
    public static function delete(int $id): ?string
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE delivery_zone_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            return 'This zone is used by existing bookings, so it cannot be deleted. Hide it instead.';
        }
        $pdo->prepare('DELETE FROM delivery_zones WHERE zone_id = ?')->execute([$id]);
        return null;
    }
}