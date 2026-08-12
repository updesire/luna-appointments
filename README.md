# Luna Appointments

دامنه مستقل خدمات، متخصصان، رزروها، پلن‌های مراقبتی و PWA متخصص است.

## قرارداد عمومی

یکپارچه‌سازی‌ها فقط باید از `Luna_Appointments_API` استفاده کنند. کلاس‌های
`Bookings_Table`، `Specialists` و سایر کلاس‌های دامنه جزئیات داخلی هستند.

رویدادهای پایدار نسخه 1 API:

- `luna_appointments_booking_created`
- `luna_appointments_booking_updated`
- `luna_appointments_booking_status_transition`
- `luna_appointments_booking_finance_committed`
- `luna_appointments_release_booking_finance_commit`

فیلترهای پایدار:

- `luna_appointments_booking_frontend_config`
- `luna_appointments_booking_finance_quote`
- `luna_appointments_prepare_booking_finance_commit`
- `luna_appointments_settings`
- `luna_appointments_specialist_payload`

نام‌های قدیمی `Luna_Builder_*` و Hookهای `luna_builder_*` فقط در لایه
سازگاری قرار دارند و برای توسعه جدید نباید استفاده شوند.
