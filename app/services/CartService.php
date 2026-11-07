<?php
declare(strict_types=1);

/**
 * The cart lives in the session (guests can use it too).
 * $_SESSION['cart'] = ['start' => 'Y-m-d', 'end' => 'Y-m-d', 'items' => [product_id => quantity]]
 * Every quantity is checked against AvailabilityService for the cart's dates.
 */
final class CartService
{
    private static function cart(): array
    {
        return ($_SESSION['cart'] ?? []) + ['start' => '', 'end' => '', 'items' => [], 'method' => 'pickup', 'zone' => 0, 'package' => 0];
    }

    /** @return array{method:string,zone:int} 'pickup' or 'delivery', and the chosen delivery zone id (0 = none) */
    public static function delivery(): array
    {
        $c = self::cart();
        return ['method' => (string) $c['method'], 'zone' => (int) $c['zone']];
    }

    /** Save pickup or delivery and the delivery area. Returns an error message or null. */
    public static function setDelivery(string $method, int $zoneId): ?string
    {
        if (!in_array($method, ['pickup', 'delivery'], true)) {
            return 'Choose pickup or delivery.';
        }
        if ($zoneId !== 0 && PricingService::zone($zoneId) === null) {
            return 'That delivery area is not available.';
        }
        $c = self::cart();
        $c['method'] = $method;
        $c['zone']   = $zoneId;
        $_SESSION['cart'] = $c;
        return null;
    }

    /** @return array{start:string,end:string}|null */
    public static function dates(): ?array
    {
        $c = self::cart();
        return $c['start'] !== '' ? ['start' => $c['start'], 'end' => $c['end']] : null;
    }

    /** @return array<int,int> product_id => quantity */
    public static function items(): array
    {
        return self::cart()['items'];
    }

    /** Number of different products in the cart (used for the header badge). */
    public static function count(): int
    {
        return count(self::items());
    }

    public static function clear(): void
    {
        $_SESSION['cart'] = ['start' => '', 'end' => '', 'items' => [], 'method' => 'pickup', 'zone' => 0, 'package' => 0];
    }

    /**
     * The package applied to this cart (a template, or one of this customer's own), or null.
     * Read from the database every time, so a deleted package or a changed discount takes effect at once.
     */
    public static function package(): ?array
    {
        $id = (int) self::cart()['package'];
        return $id > 0 ? PackageService::find($id, (int) (Auth::user()['id'] ?? 0)) : null;
    }

    /** Discount percent of the applied package (0 when there is none). */
    public static function discountPercent(): float
    {
        return (float) (self::package()['discount_percent'] ?? 0);
    }

    /** Keep the items but drop the package, and with it the discount. */
    public static function removePackage(): void
    {
        $c = self::cart();
        $c['package'] = 0;
        $_SESSION['cart'] = $c;
    }

    /**
     * Replace the cart items with a package's items, limited to what is available for the cart dates.
     * @return array{0:?string,1:string[]} [error or null, notices for the customer]
     */
    public static function applyPackage(int $packageId): array
    {
        $dates = self::dates();
        if ($dates === null) {
            return ['Choose your event dates first.', []];
        }
        $pkg = PackageService::find($packageId, (int) (Auth::user()['id'] ?? 0));
        if ($pkg === null) {
            return ['That package was not found.', []];
        }
        $ids      = array_keys($pkg['qty']);
        $avail    = AvailabilityService::forProducts($ids, $dates['start'], $dates['end']);
        $products = self::products($ids);
        $new = [];
        $notes = [];
        foreach ($pkg['qty'] as $id => $want) {
            $name = $products[$id]['name'] ?? 'An item';
            $min  = max(1, (int) ($products[$id]['min_order_qty'] ?? 1));
            $a    = $avail[$id] ?? 0;
            if (!isset($products[$id]) || $a < $min) {
                $notes[] = $name . ' is not available for these dates and was left out.';
                continue;
            }
            $qty = max($want, $min);
            if ($qty > $a) {
                $qty = $a;
                $notes[] = $name . ' was reduced to ' . $a . ' (all that is available).';
            }
            $new[$id] = $qty;
        }
        if (!$new) {
            return ['None of the items in this package are available for your dates.', $notes];
        }
        $c = self::cart();
        $c['items']   = $new;
        $c['package'] = $packageId;
        $_SESSION['cart'] = $c;
        return [null, $notes];
    }

    public static function remove(int $productId): void
    {
        $c = self::cart();
        unset($c['items'][$productId]);
        $_SESSION['cart'] = $c;
    }

    /** Save the event dates. Returns an error message or null. Quantities are re-checked for the new dates. */
    public static function setDates(string $start, string $end): ?string
    {
        $error = AvailabilityService::dateError($start, $end);
        if ($error !== null) {
            return $error;
        }
        $c = self::cart();
        $c['start'] = $start;
        $c['end']   = $end;
        $_SESSION['cart'] = $c;
        self::clamp();
        return null;
    }

    /** Add $qty to what is already in the cart. Returns an error message or null. */
    public static function add(int $productId, int $qty): ?string
    {
        if ($qty < 1) {
            return 'Enter a quantity of at least 1.';
        }
        return self::store($productId, (self::items()[$productId] ?? 0) + $qty);
    }

    /** Set the quantity (0 removes the product). Returns an error message or null. */
    public static function setQty(int $productId, int $qty): ?string
    {
        if ($qty <= 0) {
            self::remove($productId);
            return null;
        }
        return self::store($productId, $qty);
    }

    private static function store(int $productId, int $newQty): ?string
    {
        $dates = self::dates();
        if ($dates === null) {
            return 'Choose your event dates first.';
        }
        $product = self::products([$productId])[$productId] ?? null;
        $avail   = AvailabilityService::forProduct($productId, $dates['start'], $dates['end']);
        if ($product === null || $avail === null) {
            return 'That product is not available.';
        }
        $min = max(1, (int) $product['min_order_qty']);
        if ($newQty < $min) {
            return $product['name'] . ': the minimum order is ' . $min . '.';
        }
        if ($newQty > $avail) {
            return $product['name'] . ': only ' . $avail . ' available for these dates.';
        }
        $c = self::cart();
        $c['items'][$productId] = $newQty;
        $_SESSION['cart'] = $c;
        return null;
    }

    /**
     * Re-check every cart line against live stock. Lowers quantities that are too high and
     * drops lines that are no longer available. Returns notices to show the customer.
     * @return string[]
     */
    public static function clamp(): array
    {
        $c = self::cart();
        if ($c['start'] === '' || !$c['items']) {
            return [];
        }
        $notes = [];
        if (AvailabilityService::dateError($c['start'], $c['end']) !== null) {
            // The start date has passed since the cart was made.
            self::clear();
            return ['Your cart dates have passed, so the cart was cleared. Please choose new dates.'];
        }
        $ids      = array_keys($c['items']);
        $avail    = AvailabilityService::forProducts($ids, $c['start'], $c['end']);
        $products = self::products($ids);
        foreach ($c['items'] as $id => $qty) {
            $name = $products[$id]['name'] ?? 'A product';
            $min  = max(1, (int) ($products[$id]['min_order_qty'] ?? 1));
            $a    = $avail[$id] ?? 0;
            if ($a < $min) {
                unset($c['items'][$id]);
                $notes[] = $name . ' is not available for these dates and was removed.';
            } elseif ($qty > $a) {
                $c['items'][$id] = $a;
                $notes[] = $name . ' was reduced to ' . $a . ' (all that is available).';
            }
        }
        $_SESSION['cart'] = $c;
        return $notes;
    }

    /**
     * Cart lines for display: product info, quantity, rent per day, line total, available stock.
     * Call clamp() first if the stock may have changed.
     * @return array<int,array<string,mixed>>
     */
    public static function lines(): array
    {
        $c = self::cart();
        if ($c['start'] === '' || !$c['items']) {
            return [];
        }
        $days     = AvailabilityService::rentalDays($c['start'], $c['end']);
        $ids      = array_keys($c['items']);
        $avail    = AvailabilityService::forProducts($ids, $c['start'], $c['end']);
        $products = self::products($ids);
        $covered  = self::package()['qty'] ?? [];   // product_id => quantity the package includes
        $lines = [];
        foreach ($c['items'] as $id => $qty) {
            if (!isset($products[$id])) {
                continue;
            }
            $rent = (float) $products[$id]['rent_per_day'];
            $cov  = min($qty, (int) ($covered[$id] ?? 0));
            $lines[] = [
                'product_id'    => $id,
                'name'          => $products[$id]['name'],
                'sku'           => $products[$id]['sku'],
                'rent_per_day'  => $rent,
                'replacement_cost' => (float) $products[$id]['replacement_cost'],
                'min_order_qty' => max(1, (int) $products[$id]['min_order_qty']),
                'quantity'      => $qty,
                'available'     => $avail[$id] ?? 0,
                'line_total'    => round($qty * $rent * $days, 2),   // quantity x rent per day x days
                'package_qty'   => $cov,                              // units covered by the applied package
                'discount_base' => round($cov * $rent * $days, 2),    // the part of the line the package discount applies to
            ];
        }
        return $lines;
    }

    /** @return array<int,array<string,mixed>> product_id => row */
    private static function products(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT product_id, sku, name, rent_per_day, replacement_cost, min_order_qty
             FROM products WHERE is_active = 1 AND product_id IN ($in)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int) $row['product_id']] = $row;
        }
        return $out;
    }
}