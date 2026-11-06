<?php
declare(strict_types=1);

/** Admin: shop settings (A-12). Values live in the settings table as key / value text. */
final class SettingsService
{
    public const DEFAULTS = [
        'buffer_days'          => '1',
        'late_fee_per_day'     => '500',
        'advance_percent'      => '30',
        'deposit_percent'      => '25',
        'min_deposit'          => '5000',
        'shop_whatsapp'        => '',
        'payment_instructions' => '',
    ];

    /** @return array<string,string> every setting, with the PRD default where nothing is saved yet */
    public static function all(): array
    {
        $rows = Database::pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge(self::DEFAULTS, array_intersect_key($rows, self::DEFAULTS));
    }

    private static function number(string $s, int $maxInt): ?string
    {
        if (!preg_match('/^\d{1,' . $maxInt . '}(\.\d{1,2})?$/', $s)) {
            return null;
        }
        $n = rtrim(rtrim(number_format((float) $s, 2, '.', ''), '0'), '.');
        return $n === '' ? '0' : $n;
    }

    /** @return array{0:array<string,string>,1:array<string,string>} [errors, clean values] */
    public static function validate(array $in): array
    {
        $get = fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];
        $clean = [];

        $buffer = $get('buffer_days');
        if (!preg_match('/^\d{1,2}$/', $buffer) || (int) $buffer > 14) {
            $errors['buffer_days'] = 'Enter a whole number of days from 0 to 14.';
        }
        $clean['buffer_days'] = (string) (int) $buffer;

        foreach (['advance_percent' => 'Advance', 'deposit_percent' => 'Deposit'] as $key => $label) {
            $n = self::number($get($key), 3);
            if ($n === null || (float) $n > 100) {
                $errors[$key] = $label . ' must be a percentage from 0 to 100.';
            }
            $clean[$key] = $n ?? $get($key);
        }
        foreach (['late_fee_per_day' => 'Late fee', 'min_deposit' => 'Minimum deposit'] as $key => $label) {
            $n = self::number($get($key), 7);
            if ($n === null) {
                $errors[$key] = $label . ' must be an amount in PKR, e.g. 500.';
            }
            $clean[$key] = $n ?? $get($key);
        }

        $wa = $get('shop_whatsapp');
        if ($wa === '') {
            $clean['shop_whatsapp'] = '';
        } else {
            $norm = Auth::normalizePhone($wa);
            if ($norm === null) {
                $errors['shop_whatsapp'] = 'Enter a valid Pakistani mobile number, e.g. 03001234567.';
            }
            $clean['shop_whatsapp'] = $norm ?? $wa;
        }

        $pay = str_replace("\r\n", "\n", $get('payment_instructions'));
        if (mb_strlen($pay) > 1000) {
            $errors['payment_instructions'] = 'Payment instructions can be at most 1000 characters.';
        }
        $clean['payment_instructions'] = $pay;

        return [$errors, $clean];
    }

    /** Save validated values (only known keys). */
    public static function save(array $clean): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $exists = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
            $update = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
            $insert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
            foreach (array_intersect_key($clean, self::DEFAULTS) as $key => $value) {
                $exists->execute([$key]);
                if ($exists->fetchColumn()) {
                    $update->execute([(string) $value, $key]);
                } else {
                    $insert->execute([$key, (string) $value]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}