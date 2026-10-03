<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

// ══════════════════════════════════════════════════════════════════
//  El Tara — SafeSpreadsheetBinder (audit Q25: no spreadsheet formula injection)
//  Location: app/Support/SafeSpreadsheetBinder.php
//
//  A spreadsheet cell whose text starts with "=" (for example a
//  customer called  =HYPERLINK("http://evil",…)  or a note written
//  by a driver) is turned into a FORMULA by the spreadsheet library,
//  and Excel would run it when the office opens the export.
//  This binder makes every such text a plain TEXT cell instead. The
//  words look the same on screen; they just can never calculate.
//
//  Registered once in AppServiceProvider, so every export (reports,
//  audit log, trips, advances, month close, client statement) is
//  covered without each one remembering to do it.
//  Real numbers and dates are not affected.
// ══════════════════════════════════════════════════════════════════

class SafeSpreadsheetBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '@', "\t", "\r"], true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
