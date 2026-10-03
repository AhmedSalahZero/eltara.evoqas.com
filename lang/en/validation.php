<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Validation Strings (English)
//  Location: lang/en/validation.php
//
//  Deliberately thin: Laravel's own English messages are good. This
//  file adds the one message Laravel lacks (capital letter in a
//  password — App\Rules\ContainsUppercaseLetter) and, more
//  importantly, human field names in `attributes`, so a message
//  reads "The seat limit field is required" and not "The seat_limit
//  field is required". Keep attributes in step with lang/ar/validation.php.
// ══════════════════════════════════════════════════════════════════

return [

    'password' => [
        'uppercase' => 'The password must contain at least one capital letter (e.g. A).',
    ],

    'attributes' => [
        'name' => 'name',
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'email' => 'email',
        'phone' => 'mobile number',
        'mobile' => 'mobile number',
        'pin' => 'PIN',
        'login' => 'email or mobile',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password' => 'current password',
        'language' => 'language',
        'theme' => 'theme',
        'job_title' => 'job title',
        'status' => 'status',
        'contact_email' => 'contact email',
        'contact_phone' => 'contact phone',
        'office_users_limit' => 'office users limit',
        'driver_accounts_limit' => 'driver accounts limit',
        'subscription_starts_at' => 'subscription start date',
        'subscription_ends_at' => 'subscription end date',
        'default_language' => 'default language',
        'default_theme' => 'default theme',
        'admin_name' => 'admin name',
        'admin_email' => 'admin email',
        'admin_phone' => 'admin mobile',
        'approval_limit' => 'approval limit',
        'permissions' => 'permissions',
        'notes' => 'notes',
        'plate_number' => 'plate number',
        'plate_letters' => 'plate letters',
        'type' => 'type',
        'model' => 'model',
        'year' => 'year',
        'capacity_tons' => 'capacity',
        'ownership' => 'ownership',
        'owner_name' => 'owner name',
        'owner_phone' => 'owner phone',
        'driver_id' => 'driver',
        'odometer_km' => 'odometer',
        'std_km_per_litre' => 'standard km/L',
        'licence_number' => 'licence number',
        'licence_expires_at' => 'licence expiry',
        'insurance_company' => 'insurance company',
        'insurance_policy_number' => 'policy number',
        'insurance_expires_at' => 'insurance expiry',
        'inspection_expires_at' => 'inspection expiry',
        'license_number' => 'driving licence number',
        'license_expires_at' => 'driving licence expiry',
        'pay_basis' => 'pay basis',
        'base_salary' => 'base salary',
        'joined_at' => 'join date',
        'vehicle_id' => 'truck',
        'payment_terms_days' => 'payment terms',
        'may_pay_driver_cash' => 'pays driver cash',
        'contact_name' => 'contact person',
        'address' => 'address',
        'tax_number' => 'tax number',
        'trip_route_id' => 'route',
        'price' => 'price',
        'origin_ar' => 'origin (Arabic)',
        'origin_en' => 'origin (English)',
        'destination_ar' => 'destination (Arabic)',
        'destination_en' => 'destination (English)',
        'km_round_trip' => 'round-trip km',
        'usual_hours' => 'usual hours',
        'auto_transfer_limit' => 'automatic-transfer limit',
        'custody_buffer_percent' => 'custody buffer',
        'ga_rate_estimate' => 'G&A per km estimate',
        'max_hours_without_sync' => 'hours without sync',
        'diesel_price' => 'diesel price',
        'cash_percent' => 'cash share',
        'icon' => 'icon',
    ],

];
