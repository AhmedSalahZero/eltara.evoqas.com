<?php

// Trip crew (second driver, assistant): what the server says, and why a rule refused something.
// Keep key-for-key in sync with lang/ar/crew.php.

return [
    'ok' => [
        'saved' => 'The crew member is saved.',
        'deleted' => 'The crew member is removed.',
    ],
    'roles' => ['second_driver' => 'Second driver', 'assistant' => 'Assistant'],
    'expense_note' => ':role allowance — :name',
    'bad_role' => 'Choose second driver or assistant.',
    'name_required' => 'Type the name.',
    'bad_allowance' => 'The allowance must be a number from 0 to 100,000.',
    'too_many' => 'A trip can have at most :max crew members.',
    'needs_permission' => 'Adding an allowance creates a trip cost, which needs the permission to add trip expenses.',
];
