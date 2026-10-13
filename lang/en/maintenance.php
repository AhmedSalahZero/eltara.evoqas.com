<?php

// Maintenance log: what the server says after a job is saved, and why a rule refused one.
// Keep key-for-key in sync with lang/ar/maintenance.php.

return [
    'ok' => [
        'saved' => 'The maintenance job is saved.',
        'deleted' => 'The maintenance job is deleted.',
    ],

    'truck_required' => 'Choose the truck.',
    'bad_choice' => 'Choose the kind of job, the item, and whether it is still open or done.',
    'opened_required' => 'Type when the job was opened.',
    'opened_in_future' => 'The opening date cannot be in the future.',
    'odometer_for_interval' => 'A kilometre interval needs the odometer reading at this service.',
    'note_required' => 'A breakdown, and any job marked Other, needs a note saying what happened.',
    'completed_required' => 'Type when the job was finished.',
    'completed_before_open' => 'The job cannot be finished before it was opened.',
    'cost_required' => 'Type the cost of a finished job. Use 0 if there was no charge.',
    'interval_required' => 'A finished periodic service needs a kilometre interval, a day interval, or both.',
    'open_blocks_available' => 'This truck has an open maintenance job, so it stays in maintenance until that job is finished or deleted.',
    'own_needs_workshop' => 'This company has no workshop of its own. Record the job as an outside workshop.',
    'lines_required' => 'A company-workshop job needs at least one item from the store.',
    'trip_share' => 'Maintenance carried by this truck',
];
