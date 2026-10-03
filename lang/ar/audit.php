<?php

// الخطوة 7 — شاشة سجل المراجعة (الأسس §12): كيف يُقرأ كود الإجراء. "المجال.الفعل" → "<الفعل> <المجال>".
return [
    'template' => ':verb :area',
    'areas' => [
        'auth' => 'بالحساب',
        'advance' => 'سلفة', 'client_request' => 'طلب عميل', 'client_user' => 'مستخدم عميل', 'collection' => 'تحصيل', 'company' => 'شركة',
        'complaint' => 'شكوى', 'custody' => 'عهدة', 'customer' => 'عميل', 'driver' => 'سائق', 'expense' => 'مصروف', 'expense_category' => 'بند مصروف',
        'fuel' => 'تموين وقود', 'ga' => 'بند مصروفات عامة', 'invoice' => 'ربط فاتورة', 'month' => 'شهر', 'permissions' => 'صلاحيات', 'rate_card' => 'قائمة أسعار',
        'report' => 'تقرير', 'route' => 'مسار', 'settings' => 'إعدادات الشركة', 'transfer' => 'تحويل محافظ', 'trip' => 'رحلة', 'user' => 'مستخدم', 'vehicle' => 'شاحنة',
        'vehicle_type' => 'نوع شاحنة', 'cargo_type' => 'نوع حمولة',
    ],
    'verbs' => [
        'login' => 'دخول ناجح', 'login_failed' => 'دخول فاشل', 'login_locked' => 'دخول محظور (محاولات كثيرة)',
        'created' => 'إضافة', 'updated' => 'تعديل', 'deleted' => 'حذف', 'cancelled' => 'إلغاء', 'accepted' => 'قبول', 'assigned' => 'تخصيص شاحنات لـ',
        'declined' => 'رفض', 'submitted' => 'تقديم', 'invited' => 'دعوة', 'reactivated' => 'إعادة تفعيل', 'suspended' => 'إيقاف', 'recorded' => 'تسجيل',
        'disputed' => 'اعتراض على', 'answered' => 'الرد على', 'issued' => 'صرف', 'pin_reset' => 'إعادة تعيين الرمز السري لـ', 'imported' => 'استيراد', 'linked' => 'إضافة',
        'closed' => 'إقفال', 'reopened' => 'إعادة فتح', 'changed' => 'تغيير', 'price_changed' => 'تغيير سعر', 'exported' => 'تصدير', 'approved' => 'موافقة على',
        'auto_approved' => 'موافقة تلقائية على', 'rejected' => 'رفض', 'requested' => 'طلب', 'reviewed' => 'مراجعة', 'settled' => 'تسوية', 'charge_added' => 'إضافة رسوم على',
        'charge_removed' => 'حذف رسوم من', 'policy_changed' => 'تغيير سياسة التحويل في', 'email_changed' => 'تغيير بريد', 'repaid' => 'سداد', 'payroll_applied' => 'خصم من الراتب',
        'loading' => 'بدء تحميل', 'departed' => 'انطلاق', 'delivered' => 'تسليم', 'confirmed' => 'تأكيد', 'resolved' => 'حل', 'custody' => 'صرف عهدة',
    ],
    'actors' => ['user' => 'مستخدم مكتب', 'driver' => 'سائق', 'client' => 'عميل', 'system' => 'النظام'],
    'subjects' => ['Trip' => 'رحلة', 'Vehicle' => 'شاحنة', 'Driver' => 'سائق', 'Customer' => 'عميل', 'User' => 'مستخدم', 'WalletTransfer' => 'تحويل', 'MonthClose' => 'إقفال شهر', 'Invoice' => 'فاتورة', 'FuelEntry' => 'تموين', 'DriverAdvance' => 'سلفة'],
    'columns' => ['when' => 'الوقت', 'who' => 'من', 'action' => 'الإجراء', 'subject' => 'على', 'details' => 'التفاصيل', 'ip' => 'عنوان IP'],
    'title' => 'سجل المراجعة',
];
