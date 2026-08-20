<?php

namespace justinholtweb\twinsies\helpers;

/**
 * Turning Commerce's floats into the decimals Twinfield accepts.
 *
 * Twinfield takes amounts as plain decimals with a dot separator and no thousands grouping. A
 * locale that formats `1234.5` as `1.234,50` produces a document that posts a five-figure invoice
 * without erroring, so nothing here ever goes through a locale-aware formatter.
 */
abstract class Amounts
{
    public const DEBIT = 'debit';
    public const CREDIT = 'credit';

    /**
     * Money, to the cent.
     */
    public static function money(float|int|string|null $value): string
    {
        return number_format((float)$value, 2, '.', '');
    }

    /**
     * A quantity or unit price that may legitimately need more than two decimals.
     *
     * Commerce happily sells at €0.335 each; rounding that to the cent before Twinfield multiplies
     * it by the quantity moves the invoice total away from what the customer was charged.
     */
    public static function precise(float|int|string|null $value, int $decimals = 4): string
    {
        $formatted = number_format((float)$value, $decimals, '.', '');

        // Trim pointless trailing zeros but always keep two decimals, so a price reads as
        // "49.50" rather than "49.5" — Twinfield accepts both, humans reading the log do not.
        if (str_contains($formatted, '.')) {
            $formatted = rtrim($formatted, '0');
            $formatted = rtrim($formatted, '.');
        }

        if (!str_contains($formatted, '.')) {
            return $formatted . '.00';
        }

        return str_pad($formatted, strpos($formatted, '.') + 3, '0');
    }

    /**
     * The absolute value, with the debit/credit side that carries its sign.
     *
     * Twinfield has no negative amounts on a transaction line: a refund is not `-100`, it is `100`
     * on the other side. Passing a negative `value` posts a transaction that will not balance.
     *
     * @return array{0: string, 1: string} `[value, debitcredit]`
     */
    public static function signed(float|int $value, string $positiveSide): array
    {
        $side = $value < 0
            ? self::opposite($positiveSide)
            : $positiveSide;

        return [self::money(abs($value)), $side];
    }

    public static function opposite(string $side): string
    {
        return $side === self::DEBIT ? self::CREDIT : self::DEBIT;
    }

    /**
     * Whether two amounts agree to the cent.
     *
     * Comparing Commerce totals against what Twinfield calculated has to tolerate float error, or
     * a correctly posted invoice reports a one-cent discrepancy on every other order.
     */
    public static function equal(float|int|string|null $a, float|int|string|null $b): bool
    {
        return abs((float)$a - (float)$b) < 0.005;
    }

    /**
     * Round to the cent as a float, for arithmetic that stays in PHP.
     */
    public static function round(float|int|string|null $value): float
    {
        return round((float)$value, 2);
    }
}
