<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Step 6 messages: fuel, advances, invoices, month close (English)
//  Location: lang/en/finance.php
//
//  What the server says after a fuel / advance / invoice / month-close
//  action (ok.*) and why a rule refused one (the other keys). Shown
//  to people as written, so each says what happened and what to do.
//  Keep key-for-key in sync with lang/ar/finance.php.
// ══════════════════════════════════════════════════════════════════

return [
    'ok' => [
        'fuel_saved'        => 'The refuel is saved.',
        'fuel_deleted'      => 'The refuel is deleted.',
        'advance_saved'     => 'The advance is saved.',
        'repayment_saved'   => 'The repayment is recorded.',
        'advance_cancelled' => 'The advance is cancelled.',
        'payroll_applied'   => ':n deduction(s) recorded, :total EGP in total.',
        'invoice_linked'    => 'The invoice number is linked to the trips.',
        'invoice_saved'     => 'The invoice is saved.',
        'invoice_deleted'   => 'The invoice link is removed.',
        'ga_saved'          => 'The G&A line is saved.',
        'ga_deleted'        => 'The G&A line is deleted.',
        'ga_imported'       => ':n G&A line(s) imported.',
        'closed'            => ':month is closed. The rate is :rate EGP per km.',
        'reopened'          => ':month is open again. Close it once you have made your changes.',
    ],

    'fuel' => [
        'not_fuel_expense'    => 'That expense is not a fuel expense.',
        'expense_linked'      => 'That trip expense already has its litres recorded.',
        'bad_trip'            => 'Fuel can only be recorded on a trip that has not been cancelled or settled.',
        'trip_other_truck'    => 'That trip is not running on this truck.',
        'truck_required'      => 'Choose the truck.',
        'hired_no_fuel'       => 'Hired trucks do not have a fuel log — their fuel is part of the hire fee.',
        'paid_by_required'    => 'Choose who paid: the company card or the driver\'s custody.',
        'custody_needs_trip'  => 'Paying from custody needs a trip. Without a trip, use the company card.',
        'litres_or_amount'    => 'Type the litres, the amount, or both.',
        'too_many_litres'     => 'A refuel cannot be more than :max litres. Please check the number.',
    ],

    'adv' => [
        'cancel_trip_advance'  => 'This advance came from a trip. It is cancelled by correcting the trip expense.',
        'cancel_repaid'        => 'Part of this advance is already repaid, so it cannot be cancelled.',
        'amount_positive'      => 'The amount must be more than zero.',
        'amount_too_big'       => 'The amount cannot be more than :max.',
        'instalment_bad'       => 'The instalment must be zero or more.',
        'instalment_over_amount' => 'The instalment cannot be more than the advance.',
        'not_open'             => 'This advance is no longer open.',
        'more_than_remaining'  => 'The driver only owes :amount EGP on this advance.',
        'nothing_to_deduct'    => 'Nothing to deduct this month — every advance is already deducted or has no balance.',
        'ledger_payroll'       => 'Advance deducted from the :month payroll',
        'ledger_cash'          => 'Advance repaid in cash',
    ],

    'inv' => [
        'pick_trips'            => 'Choose at least one trip.',
        'customer_required'     => 'Choose the customer.',
        'number_other_customer' => 'That invoice number already belongs to another customer.',
        'number_taken'          => 'That invoice number is already used.',
        'number_required'       => 'Type the invoice number.',
        'needs_a_trip'          => 'An invoice needs at least one trip. To remove it completely, use the delete button.',
        'trip_not_found'        => 'One of the trips was not found.',
        'other_customer'        => 'Trip :trip belongs to a different customer.',
        'not_settled'           => 'Trip :trip is not settled yet. Only settled trips can be invoiced.',
        'already_linked'        => 'Trip :trip already has an invoice number.',
    ],

    'close' => [
        'import_bad_file'  => 'The file could not be read. Use the template: one column for the line, one for the amount.',
        'import_empty'     => 'The file has no lines with an amount.',
        'import_too_many'  => 'The file has too many lines.',
        'amount_positive'  => 'The amount must be more than zero.',
        'line_exists'      => 'This month already has that line. Edit the existing line instead.',
        'label_required'   => 'Type a name for the line, or choose a standard one.',
        'month_locked'     => 'This month is closed. Re-open it to change its G&A.',
        'already_closed'   => 'This month is already closed.',
        'not_ended'        => 'The month has not ended yet.',
        'no_ga'            => 'Add the G&A lines of the month first.',
        'no_km'            => 'No own-fleet km ran in this month, so there is nothing to divide the G&A by.',
        'reason_required'  => 'Type the reason for re-opening.',
        'not_closed'       => 'This month is not closed.',
        'status' => [
            'open'     => 'Open',
            'closed'   => 'Closed',
            'reopened' => 'Re-opened',
        ],
    ],
];
