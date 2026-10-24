<?php

namespace App\Support;

/**
 * Resolves the company a payroll calculation belongs to.
 *
 * Web requests use the signed-in user's company. Console commands and queued
 * jobs have no user, so they must run their work inside CompanyContext::run()
 * to make every settings/tax lookup use the right company.
 */
final class CompanyContext
{
    private static bool $overrideSet = false;
    private static ?int $override = null;

    public static function id(): ?int
    {
        if (self::$overrideSet) {
            return self::$override;
        }

        try {
            $id = auth()->user()?->company_id;
        } catch (\Throwable) {
            $id = null;
        }

        return $id ? (int) $id : null;
    }

    /** Run $callback with an explicit company context and restore the previous one afterwards. */
    public static function run(?int $companyId, callable $callback): mixed
    {
        $previousSet = self::$overrideSet;
        $previous = self::$override;
        self::$overrideSet = true;
        self::$override = $companyId;

        try {
            return $callback();
        } finally {
            self::$overrideSet = $previousSet;
            self::$override = $previous;
        }
    }
}
