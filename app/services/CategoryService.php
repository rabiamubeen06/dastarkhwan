<?php
declare(strict_types=1);

/** Admin: categories (A-02). */
final class CategoryService
{
    /** @return array<int,array<string,mixed>> every category with its number of products */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT c.category_id, c.name, c.description, c.sort_order, COUNT(p.product_id) AS products
             FROM categories c LEFT JOIN products p ON p.category_id = c.category_id
             GROUP BY c.category_id, c.name, c.description, c.sort_order
             ORDER BY c.sort_order, c.name'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT category_id, name, description, sort_order FROM categories WHERE category_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array<string,string>,1:array<string,mixed>} [errors, clean values] */
    public static function validate(array $in, ?int $id): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];

        $name = preg_replace('/\s+/', ' ', $get('name')) ?? '';
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Enter a category name (up to 80 characters).';
        } else {
            $st = Database::pdo()->prepare('SELECT 1 FROM categories WHERE LOWER(name) = LOWER(?) AND category_id <> ?');
            $st->execute([$name, $id ?? 0]);
            if ($st->fetchColumn()) {
                $errors['name'] = 'A category with this name already exists.';
            }
        }
        $desc = $get('description');
        if (mb_strlen($desc) > 255) {
            $errors['description'] = 'Description can be at most 255 characters.';
        }
        $order = $get('sort_order');
        if ($order === '') {
            $order = '0';
        }
        if (!preg_match('/^-?\d{1,4}$/', $order)) {
            $errors['sort_order'] = 'Enter a whole number, e.g. 1, 2, 3. Lower numbers come first.';
        }
        return [$errors, ['name' => $name, 'description' => $desc, 'sort_order' => (int) $order]];
    }

    /** @return array{id:int,errors:array<string,string>} */
    public static function save(array $c, ?int $id): array
    {
        $pdo = Database::pdo();
        try {
            if ($id === null) {
                $pdo->prepare('INSERT INTO categories (name, description, sort_order) VALUES (?, ?, ?)')
                    ->execute([$c['name'], $c['description'] !== '' ? $c['description'] : null, $c['sort_order']]);
                return ['id' => (int) $pdo->lastInsertId(), 'errors' => []];
            }
            $pdo->prepare('UPDATE categories SET name = ?, description = ?, sort_order = ? WHERE category_id = ?')
                ->execute([$c['name'], $c['description'] !== '' ? $c['description'] : null, $c['sort_order'], $id]);
            return ['id' => $id, 'errors' => []];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {   // someone saved the same name at the same moment
                return ['id' => 0, 'errors' => ['name' => 'A category with this name already exists.']];
            }
            throw $e;
        }
    }

    /** Returns an error message, or null when the category was deleted. */
    public static function delete(int $id): ?string
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
        $st->execute([$id]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            return 'This category still has ' . $n . ' product' . ($n === 1 ? '' : 's') . '. Move them to another category first.';
        }
        $pdo->prepare('DELETE FROM categories WHERE category_id = ?')->execute([$id]);
        return null;
    }
}