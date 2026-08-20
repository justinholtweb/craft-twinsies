<?php

namespace justinholtweb\twinsies\helpers;

use DateTime;
use DateTimeInterface;
use DateTimeZone;

/**
 * Twinfield's date formats.
 *
 * Everything is `yyyyMMdd` with no separators, and periods are `yyyy/PP`. Both are formatted in
 * the *site's* time zone, not UTC: Craft stores order dates in UTC, and an order placed at 01:00
 * Amsterdam on the first of a month is 23:00 UTC on the last day of the previous one. Formatting
 * that in UTC books it into the wrong VAT period, which is the kind of error an accountant finds
 * a quarter later.
 */
abstract class Dates
{
    /**
     * `yyyyMMdd`.
     */
    public static function date(?DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return self::inSiteZone($date)->format('Ymd');
    }

    /**
     * `yyyy/PP` — the financial period an entry books into.
     *
     * This is the calendar month, which is what a Twinfield office is configured for by default.
     * Offices on a shifted book year need the period left off the document so Twinfield derives
     * it from the date itself; that is what an empty period setting does.
     */
    public static function period(?DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return self::inSiteZone($date)->format('Y/m');
    }

    /**
     * Add whole days to a date, for a due date derived from payment terms.
     */
    public static function addDays(DateTimeInterface $date, int $days): DateTime
    {
        $copy = self::inSiteZone($date);

        if ($days !== 0) {
            $copy->modify(sprintf('%+d days', $days));
        }

        return $copy;
    }

    /**
     * Parse `yyyyMMdd` back out of a Twinfield response.
     */
    public static function parse(?string $value): ?DateTime
    {
        $value = trim((string)$value);

        if ($value === '' || !preg_match('/^\d{8}$/', $value)) {
            return null;
        }

        $parsed = DateTime::createFromFormat('Ymd|', $value, self::siteZone());

        return $parsed ?: null;
    }

    private static function inSiteZone(DateTimeInterface $date): DateTime
    {
        return (new DateTime('@' . $date->getTimestamp()))->setTimezone(self::siteZone());
    }

    private static function siteZone(): DateTimeZone
    {
        return new DateTimeZone(\Craft::$app->getTimeZone());
    }
}
