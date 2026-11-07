<?php
declare(strict_types=1);

/**
 * Packages (C-05).
 *  - Template packages are made by the admin (is_template = 1), can carry a discount, and everybody can use them.
 *  - Saved packages belong to one customer (is_template = 0), have no discount, and only that customer can see them.
 * A package is a list of products with quantities (package_items). Applying one fills the cart (see CartService).
 */
final class PackageService
{
    public const MAX_SAVED    = 20;   // saved packages per customer
    public const MAX_DISCOUNT = 50;   // highest discount percent the admin can enter

    // ---------- reading ----------

    /** @return array<int,array<string,mixed>> template packages with their items */
    public static function templates(): array
    {
        $rows = Database::pdo()->query('SELECT * FROM packages WHERE is_template = 1 ORDER BY name, package_id')->fetchAll();
        return self::decorate($rows);
    }

    /** @return array<int,array<string,mixed>> this customer's saved packages, newest first */
    public static function mine(int $userId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM packages WHERE is_template = 0 AND user_id = ? ORDER BY package_id DESC');
        $st->execute([$userId]);
        return self::decorate($st->fetchAll());
    }

    /** A package this person may use: any template, or one of their own. Null otherwise. */
    public static function find(int $id, int $userId = 0): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM packages WHERE package_id = ? AND (is_template = 1 OR user_id = ?)');
        $st->execute([$id, $userId]);
        $row = $st->fetch();
        return $row ? self::decorate([$row])[0] : null;
    }

    public static function findTemplate(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM packages WHERE package_id = ? AND is_template = 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? self::decorate([$row])[0] : null;
    }

    /**
     * Adds 'items' (every line, with product info), 'qty' (product_id => quantity, active products only),
     * 'per_day' (rent for one day, before any discount) and 'units' (total pieces).
     * @param array<int,array<string,mixed>> $pkgs
     * @return array<int,array<string,mixed>>
     */
    private static function decorate(array $pkgs): array
    {
        if (!$pkgs) {
            return [];
        }
        $ids = array_map(fn($p) => (int) $p['package_id'], $pkgs);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = Database::pdo()->prepare(
            "SELECT pi.package_id, pi.product_id, pi.quantity, p.name, p.sku, p.rent_per_day, p.is_active
             FROM package_items pi JOIN products p ON p.product_id = pi.product_id
             WHERE pi.package_id IN ($in) ORDER BY p.name"
        );
        $st->execute($ids);
        $by = [];
        foreach ($st->fetchAll() as $r) {
            $by[(int) $r['package_id']][] = $r;
        }
        foreach ($pkgs as &$p) {
            $p['items'] = $by[(int) $p['package_id']] ?? [];
            $p['qty'] = [];
            $p['per_day'] = 0.0;
            $p['units'] = 0;
            foreach ($p['items'] as $r) {
                if ((int) $r['is_active'] === 1) {
                    $p['qty'][(int) $r['product_id']] = (int) $r['quantity'];
                    $p['per_day'] += (int) $r['quantity'] * (float) $r['rent_per_day'];
                    $p['units'] += (int) $r['quantity'];
                }
            }
            $p['per_day'] = round($p['per_day'], 2);
        }
        unset($p);
        return $pkgs;
    }

    // ---------- customer: save the cart as a package ----------

    /** @return array{error:?string,id:int} */
    public static function saveFromCart(int $userId, string $name): array
    {
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return ['error' => 'Give your package a name (2 to 120 characters).', 'id' => 0];
        }
        $items = CartService::items();
        if (!$items) {
            return ['error' => 'Your cart is empty, so there is nothing to save.', 'id' => 0];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM packages WHERE is_template = 0 AND user_id = ?');
        $st->execute([$userId]);
        if ((int) $st->fetchColumn() >= self::MAX_SAVED) {
            return ['error' => 'You can keep up to ' . self::MAX_SAVED . ' saved packages. Delete one first.', 'id' => 0];
        }
        $st = $pdo->prepare('SELECT 1 FROM packages WHERE is_template = 0 AND user_id = ? AND name = ?');
        $st->execute([$userId, $name]);
        if ($st->fetchColumn()) {
            return ['error' => 'You already have a package with that name.', 'id' => 0];
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO packages (user_id, name, is_template, discount_percent) VALUES (?, ?, 0, 0)')
                ->execute([$userId, $name]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO package_items (package_id, product_id, quantity) VALUES (?, ?, ?)');
            foreach ($items as $pid => $qty) {
                $ins->execute([$id, $pid, $qty]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Save package failed: ' . $e->getMessage());
            return ['error' => 'We could not save your package. Please try again.', 'id' => 0];
        }
        return ['error' => null, 'id' => $id];
    }

    /** Delete one of this customer's own saved packages. False if it is not theirs. */
    public static function deleteMine(int $userId, int $id): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT 1 FROM packages WHERE package_id = ? AND is_template = 0 AND user_id = ?');
        $st->execute([$id, $userId]);
        if (!$st->fetchColumn()) {
            return false;
        }
        return self::remove($id);
    }

    // ---------- admin: template packages ----------

    /**
     * Products the template form lists: every visible product, plus any hidden product the package already contains.
     * @return array<int,array<string,mixed>>
     */
    public static function formProducts(?int $packageId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.product_id, p.sku, p.name, p.rent_per_day, p.total_quantity, p.min_order_qty, p.is_active, c.name AS category
             FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE p.is_active = 1 OR p.product_id IN (SELECT product_id FROM package_items WHERE package_id = ?)
             ORDER BY c.name, p.name'
        );
        $st->execute([$packageId ?? 0]);
        return $st->fetchAll();
    }

    /** @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values] */
    public static function validate(array $in, ?int $id): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];

        $name = preg_replace('/\s+/', ' ', $get('name')) ?? '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a package name (2 to 120 characters).';
        } else {
            $st = Database::pdo()->prepare('SELECT 1 FROM packages WHERE is_template = 1 AND name = ? AND package_id <> ?');
            $st->execute([$name, $id ?? 0]);
            if ($st->fetchColumn()) {
                $errors['name'] = 'Another template already has this name.';
            }
        }
        $desc = preg_replace('/\s+/', ' ', $get('description')) ?? '';
        if (mb_strlen($desc) > 255) {
            $errors['description'] = 'Description can be at most 255 characters.';
        }
        $guests = null;
        if ($get('guest_count') !== '') {
            if (!ctype_digit($get('guest_count')) || (int) $get('guest_count') < 1 || (int) $get('guest_count') > 100000) {
                $errors['guest_count'] = 'Enter the number of guests as a whole number.';
            } else {
                $guests = (int) $get('guest_count');
            }
        }
        $discount = 0.0;
        $d = str_replace(',', '', $get('discount_percent'));
        if ($d !== '') {
            if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $d) || (float) $d > self::MAX_DISCOUNT) {
                $errors['discount_percent'] = 'Enter a discount from 0 to ' . self::MAX_DISCOUNT . ' percent.';
            } else {
                $discount = (float) $d;
            }
        }

        $posted = is_array($in['qty'] ?? null) ? $in['qty'] : [];
        $items = [];
        foreach (self::formProducts($id) as $p) {
            $raw = $posted[$p['product_id']] ?? '';
            $raw = is_string($raw) ? trim($raw) : '';
            if ($raw === '' || $raw === '0') {
                continue;
            }
            $pid = (int) $p['product_id'];
            if (!ctype_digit($raw)) {
                $errors['items'] = $p['name'] . ': enter a whole number.';
            } elseif ((int) $raw > (int) $p['total_quantity']) {
                $errors['items'] = $p['name'] . ': you only own ' . (int) $p['total_quantity'] . '.';
            } elseif ((int) $raw < (int) $p['min_order_qty']) {
                $errors['items'] = $p['name'] . ': the minimum order is ' . (int) $p['min_order_qty'] . '.';
            } else {
                $items[$pid] = (int) $raw;
            }
        }
        if (!$items && !isset($errors['items'])) {
            $errors['items'] = 'Add at least one product to the package.';
        }

        return [$errors, [
            'name' => $name, 'description' => $desc, 'guest_count' => $guests,
            'discount_percent' => $discount, 'items' => $items,
        ]];
    }

    /** Create ($id null) or update a template. Returns the package id. */
    public static function saveTemplate(array $c, ?int $id, int $adminId): int
    {
        $pdo = Database::pdo();
        $desc = $c['description'] !== '' ? $c['description'] : null;
        try {
            $pdo->beginTransaction();
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO packages (user_id, name, description, guest_count, is_template, discount_percent)
                     VALUES (?, ?, ?, ?, 1, ?)'
                )->execute([$adminId, $c['name'], $desc, $c['guest_count'], $c['discount_percent']]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $pdo->prepare(
                    'UPDATE packages SET name = ?, description = ?, guest_count = ?, discount_percent = ?
                     WHERE package_id = ? AND is_template = 1'
                )->execute([$c['name'], $desc, $c['guest_count'], $c['discount_percent'], $id]);
                $pdo->prepare('DELETE FROM package_items WHERE package_id = ?')->execute([$id]);
            }
            $ins = $pdo->prepare('INSERT INTO package_items (package_id, product_id, quantity) VALUES (?, ?, ?)');
            foreach ($c['items'] as $pid => $qty) {
                $ins->execute([$id, $pid, $qty]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $id;
    }

    /** Delete a template. Past bookings keep their price; their package link is cleared by the database. */
    public static function deleteTemplate(int $id): bool
    {
        return self::findTemplate($id) !== null && self::remove($id);
    }

    private static function remove(int $id): bool
    {
        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM package_items WHERE package_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM packages WHERE package_id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return true;
    }
}