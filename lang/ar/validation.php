<?php

// ══════════════════════════════════════════════════════════════════
//  El Tara — Validation Strings (Arabic)
//  Location: lang/ar/validation.php
//
//  Without this file EVERY validation failure in the application
//  falls back to Laravel's English defaults — so an Arabic customer
//  filling an Arabic form is told "The password field must be at
//  least 8 characters." That was reported to us as "no message
//  appeared", which is a fair description of a message you cannot
//  read.
//
//  This has now regressed once. If it goes missing again the symptom
//  is silent: nothing errors, nothing logs, the app simply answers
//  half its users in the wrong language. tests/Unit/TranslationsTest
//  exists to catch that.
//
//  Only the rules this app actually uses are translated. Laravel
//  falls back to English for anything absent, so an untranslated
//  rule degrades rather than breaking.
// ══════════════════════════════════════════════════════════════════

return [

    'required'      => 'حقل :attribute مطلوب.',
    'required_if'   => 'حقل :attribute مطلوب في هذه الحالة.',
    'required_with' => 'حقل :attribute مطلوب.',
    'present'       => 'حقل :attribute مطلوب.',
    'confirmed'     => 'تأكيد :attribute غير مطابق.',
    'email'         => 'يرجى إدخال بريد إلكتروني صحيح.',
    'unique'        => 'هذا الـ:attribute مستخدم بالفعل.',
    'exists'        => 'الـ:attribute المختار غير صالح.',
    'integer'       => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'numeric'       => 'يجب أن يكون :attribute رقماً.',
    'date'          => 'يجب أن يكون :attribute تاريخاً صحيحاً.',
    'string'        => 'يجب أن يكون :attribute نصاً.',
    'array'         => 'يجب أن يكون :attribute قائمة.',
    'in'            => 'الـ:attribute المختار غير صالح.',
    'boolean'       => 'يجب أن يكون :attribute صح أو خطأ.',
    'current_password' => 'كلمة المرور الحالية غير صحيحة.',
    'digits'        => 'يجب أن يكون :attribute :digits أرقام.',
    'regex'         => 'صيغة :attribute غير صحيحة.',
    'decimal'       => 'يجب أن يكون :attribute رقمًا.',
    'gte'           => ['numeric' => 'يجب أن يكون :attribute :value أو أكثر.'],
    'required_unless' => 'حقل :attribute مطلوب.',

    'min' => [
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
        'string'  => 'يجب ألا يقل :attribute عن :min حرفاً.',
        'array'   => 'يجب ألا يقل :attribute عن :min عنصراً.',
    ],

    'max' => [
        'numeric' => 'يجب ألا يزيد :attribute عن :max.',
        'string'  => 'يجب ألا يزيد :attribute عن :max حرفاً.',
        'array'   => 'يجب ألا يزيد :attribute عن :max عنصراً.',
    ],

    'after'           => 'يجب أن يكون :attribute بعد :date.',
    'after_or_equal'  => 'يجب أن يكون :attribute في :date أو بعده.',
    'before_or_equal' => 'يجب أن يكون :attribute في :date أو قبله.',

    // The password rules the project applies — see
    // App\Support\PasswordRules.
    'password' => [
        'letters'       => 'يجب أن تحتوي كلمة المرور على حرف واحد على الأقل.',
        'mixed'         => 'يجب أن تحتوي كلمة المرور على حرف كبير وحرف صغير.',
        'uppercase'     => 'يجب أن تحتوي كلمة المرور على حرف إنجليزي كبير واحد على الأقل (مثل A).',
        'numbers'       => 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.',
        'symbols'       => 'يجب أن تحتوي كلمة المرور على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت كلمة المرور هذه في تسريب بيانات. يرجى اختيار كلمة أخرى.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Field names
    |--------------------------------------------------------------------------
    |
    | Without these a message reads "حقل company_name مطلوب" — half
    | Arabic, half a database column name.
    |
    */

    'attributes' => [
        'name' => 'الاسم',
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الموبايل',
        'mobile' => 'رقم الموبايل',
        'pin' => 'الرقم السري',
        'login' => 'البريد أو الموبايل',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'language' => 'اللغة',
        'theme' => 'المظهر',
        'job_title' => 'الوظيفة',
        'status' => 'الحالة',
        'contact_email' => 'بريد التواصل',
        'contact_phone' => 'هاتف التواصل',
        'office_users_limit' => 'عدد مستخدمي المكتب',
        'driver_accounts_limit' => 'عدد حسابات السائقين',
        'subscription_starts_at' => 'تاريخ بداية الاشتراك',
        'subscription_ends_at' => 'تاريخ نهاية الاشتراك',
        'default_language' => 'اللغة الافتراضية',
        'default_theme' => 'المظهر الافتراضي',
        'admin_name' => 'اسم المدير',
        'admin_email' => 'بريد المدير',
        'admin_phone' => 'موبايل المدير',
        'approval_limit' => 'حد الموافقة',
        'permissions' => 'الصلاحيات',
        'notes' => 'ملاحظات',
        'plate_number' => 'رقم اللوحة',
        'plate_letters' => 'حروف اللوحة',
        'type' => 'النوع',
        'model' => 'الموديل',
        'year' => 'سنة الصنع',
        'capacity_tons' => 'الحمولة',
        'ownership' => 'الملكية',
        'owner_name' => 'اسم المالك',
        'owner_phone' => 'هاتف المالك',
        'driver_id' => 'السائق',
        'odometer_km' => 'العداد',
        'std_km_per_litre' => 'المعيار كم/لتر',
        'licence_number' => 'رقم الرخصة',
        'licence_expires_at' => 'انتهاء الرخصة',
        'insurance_company' => 'شركة التأمين',
        'insurance_policy_number' => 'رقم الوثيقة',
        'insurance_expires_at' => 'انتهاء التأمين',
        'inspection_expires_at' => 'انتهاء الفحص',
        'license_number' => 'رقم رخصة القيادة',
        'license_expires_at' => 'انتهاء رخصة القيادة',
        'pay_basis' => 'نظام الأجر',
        'base_salary' => 'الراتب الأساسي',
        'joined_at' => 'تاريخ الالتحاق',
        'vehicle_id' => 'الشاحنة',
        'payment_terms_days' => 'مدة السداد',
        'may_pay_driver_cash' => 'يدفع نقدًا للسائق',
        'contact_name' => 'مسؤول التواصل',
        'address' => 'العنوان',
        'tax_number' => 'الرقم الضريبي',
        'trip_route_id' => 'المسار',
        'price' => 'السعر',
        'origin_ar' => 'من (عربي)',
        'origin_en' => 'من (إنجليزي)',
        'destination_ar' => 'إلى (عربي)',
        'destination_en' => 'إلى (إنجليزي)',
        'km_round_trip' => 'كم ذهاب وعودة',
        'usual_hours' => 'الساعات المعتادة',
        'auto_transfer_limit' => 'حد التحويل التلقائي',
        'custody_buffer_percent' => 'هامش العهدة',
        'ga_rate_estimate' => 'تقدير المصروفات العامة لكل كم',
        'max_hours_without_sync' => 'ساعات بدون مزامنة',
        'diesel_price' => 'سعر السولار',
        'cash_percent' => 'النسبة النقدية',
        'icon' => 'الأيقونة',
    ],

];
