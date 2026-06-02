<x-mail::message>
# Xin chào {{ $invoice->tenant->name ?? 'bạn' }},

Hệ thống thông báo hóa đơn tiền thuê phòng **Tháng {{ $invoice->billing_month }}** của bạn đã quá hạn thanh toán.

Theo như chính sách của hợp đồng, hệ thống đã tự động tính thêm phí phạt trễ hạn vào hóa đơn của bạn.

<x-mail::panel>
**📋 Thông tin cập nhật**

- **Hạn chót thanh toán:** {{ $invoice->due_date->format('d/m/Y') }}
- **Phí phạt trễ hạn:** {{ number_format($invoice->late_fee, 0, ',', '.') }} VNĐ
- **Số tiền CÒN LẠI cần thanh toán:** **{{ number_format($invoice->remainingAmount(), 0, ',', '.') }} VNĐ**
</x-mail::panel>

Vui lòng tiến hành thanh toán trong thời gian sớm nhất để tránh ảnh hưởng đến hợp đồng thuê phòng của bạn.

Trân trọng,<br>
**{{ config('app.name') }}**
</x-mail::message>
