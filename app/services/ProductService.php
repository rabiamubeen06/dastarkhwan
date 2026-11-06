<?php
declare(strict_types=1);

/** Admin: products (A-02). Products are hidden, never deleted, because past bookings refer to them. */
final class ProductService
{
    public const PER_PAGE = 20;

    /**
     * @param string $status '' (all), 'active' or 'inactive'
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int,page:int}
     */
    public static function search(string $q, int $categoryId, string $status, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($categoryId > 0) {
            $where[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 'p.is_active = ?';
            $params[] = $status === 'active' ? 1 : 0;
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::pdo();

        $st = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE $whereSql");
        $st->execute($params);
        $total  = (int) $st->fetchColumn();
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = max(1, min($page, $pages));
        $limit  = self::PER_PAGE;
        $offset = ($page - 1) * $limit;   // integers only, safe to inline

        $st = $pdo->prepare(
            "SELECT p.product_id, p.sku, p.name, p.rent_per_day, p.total_quantity, p.is_active, c.name AS category
             FROM products p JOIN categories c ON c.category_id = p.category_id
             WHERE $whereSql ORDER BY p.name LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM products WHERE product_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** "1,250.50" -> 1250.5; null when it is not a valid amount (up to 8 digits, 2 decimals). */
    private static function amount(string $s): ?float
    {
        $s = str_replace(',', '', trim($s));
        return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s) ? (float) $s : null;
    }

    /** @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values] */
    public static function validate(array $in, ?int $id): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $pdo = Database::pdo();
        $errors = [];

        $cat = (int) $get('category_id');
        $st = $pdo->prepare('SELECT 1 FROM categories WHERE category_id = ?');
        $st->execute([$cat]);
        if (!$st->fetchColumn()) {
            $errors['category_id'] = 'Choose a category.';
        }

        $sku = strtoupper($get('sku'));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $sku)) {
            $errors['sku'] = 'SKU can use letters, numbers, dash, dot and underscore (up to 40 characters).';
        } else {
            $st = $pdo->prepare('SELECT 1 FROM products WHERE sku = ? AND product_id <> ?');
            $st->execute([$sku, $id ?? 0]);
            if ($st->fetchColumn()) {
                $errors['sku'] = 'Another product already uses this SKU.';
            }
        }

        $name = preg_replace('/\s+/', ' ', $get('name')) ?? '';
        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = 'Enter a product name (up to 150 characters).';
        }
        $desc = $get('description');
        if (mb_strlen($desc) > 2000) {
            $errors['description'] = 'Description can be at most 2000 characters.';
        }
        $material = $get('material');
        if (mb_strlen($material) > 60) {
            $errors['material'] = 'Material can be at most 60 characters.';
        }

        // Same rule the catalog uses: an https link, or a path on this site that starts with a single slash.
        $image = $get('image_url');
        if ($image !== '') {
            $okHttps = str_starts_with($image, 'https://') && filter_var($image, FILTER_VALIDATE_URL) !== false;
            $okLocal = (bool) preg_match('#^/(?!/)\S*$#', $image);
            if (strlen($image) > 255 || (!$okHttps && !$okLocal)) {
                $errors['image_url'] = 'Use an https:// link, or a path starting with / (up to 255 characters).';
            }
        }

        $rent = self::amount($get('rent_per_day'));
        if ($rent === null) {
            $errors['rent_per_day'] = 'Enter an amount like 8 or 8.50.';
        }
        $repl = self::amount($get('replacement_cost'));
        if ($repl === null) {
            $errors['replacement_cost'] = 'Enter an amount like 450 or 450.50.';
        }
        $qty = $get('total_quantity');
        if (!ctype_digit($qty) || (int) $qty > 1000000) {
            $errors['total_quantity'] = 'Enter the number of units you own (0 or more).';
        }
        $min = $get('min_order_qty');
        if (!ctype_digit($min) || (int) $min < 1 || (int) $min > 100000) {
            $errors['min_order_qty'] = 'Enter a minimum order of 1 or more.';
        }

        return [$errors, [
            'category_id' => $cat, 'sku' => $sku, 'name' => $name, 'description' => $desc, 'material' => $material,
            'image_url' => $image, 'rent_per_day' => $rent ?? 0.0, 'replacement_cost' => $repl ?? 0.0,
            'total_quantity' => (int) $qty, 'min_order_qty' => max(1, (int) $min),
            'is_active' => ($in['is_active'] ?? '') === '1' ? 1 : 0,
        ]];
    }

    /** @return array{id:int,errors:array<string,string>} */
    public static function save(array $c, ?int $id): array
    {
        $pdo = Database::pdo();
        $nullable = fn(string $s): ?string => $s !== '' ? $s : null;
        $values = [
            $c['category_id'], $c['sku'], $c['name'], $nullable($c['description']), $nullable($c['material']),
            $nullable($c['image_url']), $c['rent_per_day'], $c['replacement_cost'],
            $c['total_quantity'], $c['min_order_qty'], $c['is_active'],
        ];
        try {
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO products (category_id, sku, name, description, material, image_url, rent_per_day,
                        replacement_cost, total_quantity, min_order_qty, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute($values);
                return ['id' => (int) $pdo->lastInsertId(), 'errors' => []];
            }
            $pdo->prepare(
                'UPDATE products SET category_id = ?, sku = ?, name = ?, description = ?, material = ?, image_url = ?,
                    rent_per_day = ?, replacement_cost = ?, total_quantity = ?, min_order_qty = ?, is_active = ?
                 WHERE product_id = ?'
            )->execute([...$values, $id]);
            return ['id' => $id, 'errors' => []];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {   // someone saved the same SKU at the same moment
                return ['id' => 0, 'errors' => ['sku' => 'Another product already uses this SKU.']];
            }
            throw $e;
        }
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::pdo()->prepare('UPDATE products SET is_active = ? WHERE product_id = ?')->execute([$active ? 1 : 0, $id]);
    }
}