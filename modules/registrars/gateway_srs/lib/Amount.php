<?php
namespace WHMCS\Module\Registrar\gateway_srs;

/** Exact decimal comparisons; never compare registry prices using binary floats. */
final class Amount
{
    public static function units($value, bool $signed = false): int
    {
        if (is_float($value) && is_finite($value)) { $value = sprintf('%.6F', $value); }
        if (!is_string($value) && !is_int($value)) { throw new \InvalidArgumentException('Invalid monetary amount.'); }
        if (!preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,6})0*)?$/D', (string) $value, $m)
            || (!$signed && $m[1] === '-')) {
            throw new \InvalidArgumentException('Invalid monetary amount. Use up to six decimal places.');
        }
        $amount = (int) $m[2] * 1000000 + (int) str_pad($m[3] ?? '', 6, '0');
        return $m[1] === '-' ? -$amount : $amount;
    }

    public static function text($value, bool $signed = false): string
    {
        return self::fromUnits(self::units($value, $signed));
    }

    public static function fromUnits(int $units): string
    {
        return ($units < 0 ? '-' : '') . intdiv(abs($units), 1000000) . '.'
            . str_pad((string) (abs($units) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function currency($value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new \InvalidArgumentException('Gateway SRS returned no valid currency.');
        }
        return $value;
    }
}
