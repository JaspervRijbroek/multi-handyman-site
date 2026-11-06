<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Works out the first workday of a month: the day clients are charged.
 *
 * Workdays are Monday to Friday, minus the national holidays that can fall on
 * the first days of a month in Spain (1 January, 1 May, 1 November).
 */
final class WorkdayCalendar
{
    /** Fixed holidays as month-day, only those that can push the first workday back */
    private const array HOLIDAYS = ['01-01', '05-01', '11-01'];

    public static function isWorkday(\DateTimeImmutable $day): bool
    {
        return (int) $day->format('N') < 6 && !\in_array($day->format('m-d'), self::HOLIDAYS, true);
    }

    public static function firstWorkdayOfMonth(\DateTimeImmutable $anyDayInMonth): \DateTimeImmutable
    {
        $day = $anyDayInMonth->modify('first day of this month')->setTime(0, 0);
        while (!self::isWorkday($day)) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }

    /**
     * The first billing day for a client onboarded on $onboardedOn: the first
     * workday of the next month. Everything before that is free.
     */
    public static function firstBillingDay(\DateTimeImmutable $onboardedOn): \DateTimeImmutable
    {
        return self::firstWorkdayOfMonth($onboardedOn->modify('first day of next month'));
    }
}
