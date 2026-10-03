<?php

namespace App\Services\Fuel;

// ══════════════════════════════════════════════════════════════════
//  El Tara — FuelMath (km per litre and the over-draw flag)
//  Location: app/Services/Fuel/FuelMath.php
//
//  Scope §6.10 and §10:  fuel economy = km ÷ litres, and a truck is
//  flagged for POSSIBLE OVER-DRAW when it does more than 7% worse
//  than its standard km/L (the % is a company setting).
//
//  How km are known: from the ODOMETER at two refuels. The litres put
//  in at a refuel are what the truck used since the refuel before
//  (full tank to full tank), so
//        km of a refuel   = its odometer − the previous refuel's odometer
//        economy          = km ÷ litres of that refuel
//  The first refuel of a truck has no previous one, so it shows no
//  economy. A refuel without an odometer reading shows none either.
//  An odometer that goes DOWN is a typing mistake: it is marked "bad"
//  and ignored.
//
//  Pure arithmetic, no database (tests/Unit/FuelMathTest.php).
// ══════════════════════════════════════════════════════════════════

final class FuelMath
{
    /**
     * @param  list<array{id:int, odometer:?int, litres:float}>  $fills  one truck's refuels, oldest first
     * @param  ?int  $previousOdometer  the odometer of the refuel just BEFORE the first one given (or null)
     * @return list<array{id:int, km:?int, kmpl:?float, flagged:bool, bad:bool}>
     */
    public static function perFill(array $fills, ?int $previousOdometer, ?float $standard, float $flagPercent): array
    {
        $last = $previousOdometer;
        $rows = [];

        foreach ($fills as $fill) {
            $km = null;
            $kmpl = null;
            $bad = false;
            $odo = $fill['odometer'];

            if ($odo !== null) {
                if ($last !== null) {
                    $delta = $odo - $last;
                    if ($delta > 0) {
                        $km = $delta;
                        $kmpl = $fill['litres'] > 0 ? round($delta / $fill['litres'], 2) : null;
                    } else {
                        $bad = true;
                    }
                }
                if (! $bad) {
                    $last = $odo;
                }
            }

            $rows[] = ['id' => $fill['id'], 'km' => $km, 'kmpl' => $kmpl, 'flagged' => self::isFlagged($kmpl, $standard, $flagPercent), 'bad' => $bad];
        }

        return $rows;
    }

    /** More than $flagPercent % below the standard? Nothing to judge without both numbers. */
    public static function isFlagged(?float $kmpl, ?float $standard, float $flagPercent): bool
    {
        if ($kmpl === null || $standard === null || $standard <= 0) {
            return false;
        }

        return $kmpl < $standard * (1 - $flagPercent / 100) - 0.00001;
    }

    /**
     * One truck over a period: total km and litres (only refuels whose km are known),
     * the average economy and how far it is from the standard (negative = worse).
     *
     * @param  list<array{id:int, km:?int, kmpl:?float, flagged:bool, bad:bool}>  $rows  from perFill()
     * @param  array<int,float>  $litresById  litres of each refuel, by id
     * @return array{km:int, litres:float, kmpl:?float, variance:?float, flagged:bool}
     */
    public static function summary(array $rows, array $litresById, ?float $standard, float $flagPercent): array
    {
        $km = 0;
        $litres = 0.0;

        foreach ($rows as $row) {
            if ($row['km'] !== null) {
                $km += $row['km'];
                $litres += $litresById[$row['id']] ?? 0.0;
            }
        }

        $kmpl = $litres > 0 ? round($km / $litres, 2) : null;

        return [
            'km'       => $km,
            'litres'   => round($litres, 2),
            'kmpl'     => $kmpl,
            'variance' => $kmpl !== null && $standard !== null && $standard > 0 ? round(($kmpl - $standard) / $standard * 100, 1) : null,
            'flagged'  => self::isFlagged($kmpl, $standard, $flagPercent),
        ];
    }
}
