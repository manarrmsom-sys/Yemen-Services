<?php

// Partial Arabic validation messages. Run `php artisan lang:publish` to get the
// full English baseline; this file overrides the common keys for Arabic users.

return [
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'integer' => 'يجب أن يكون :attribute رقمًا صحيحًا.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'email' => 'صيغة :attribute غير صحيحة.',
    'in' => 'قيمة :attribute غير صالحة.',
    'exists' => ':attribute المحدد غير موجود.',
    'unique' => ':attribute مستخدم من قبل.',
    'max' => [
        'string' => 'يجب ألا يزيد :attribute عن :max حرفًا.',
        'numeric' => 'يجب ألا يزيد :attribute عن :max.',
        'file' => 'يجب ألا يزيد حجم :attribute عن :max كيلوبايت.',
    ],
    'min' => [
        'string' => 'يجب ألا يقل :attribute عن :min حرفًا.',
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
    ],
    'regex' => 'صيغة :attribute غير صحيحة.',
    'date' => 'ليس :attribute تاريخًا صحيحًا.',
    'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
    'mimes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',

    'attributes' => [
        'phone' => 'رقم الجوال',
        'code' => 'رمز التحقق',
        'contact_name' => 'الاسم',
        'contact_phone' => 'رقم التواصل',
        'service_id' => 'الخدمة',
        'governorate_id' => 'المحافظة',
        'urgency' => 'موعد الخدمة',
        'payment_method' => 'طريقة الدفع',
        'amount' => 'المبلغ',
        'rating' => 'التقييم',
    ],
];
