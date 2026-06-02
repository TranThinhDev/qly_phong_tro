<x-mail::message>
# Xin chào {{ $invoice->tenant?->name }},

Hóa đơn tiền thuê phòng **tháng {{ $invoice->billing_month }}** của bạn đã được phát hành.
Vui lòng thanh toán trước ngày **{{ $paymentDeadline }}** để tránh phí phạt trễ hạn.

---

<x-mail::panel>
**📋 Thông tin hóa đơn**

- **Mã hóa đơn:** {{ $invoice->invoice_code }}
- **Kỳ thanh toán:** Tháng {{ $invoice->billing_month }}
- **Phòng:** {{ $invoice->contract?->room?->name ?? 'N/A' }}
- **Hạn thanh toán:** {{ $paymentDeadline }}
- **Tổng cộng:** **{{ number_format($invoice->total_amount, 0, ',', '.') }} VNĐ**
</x-mail::panel>

**📊 Chi tiết các khoản phí:**

| Khoản mục | Mô tả | Thành tiền |
|-----------|-------|-----------|
@foreach($invoice->items as $item)
| {{ match($item->type) { 'rent' => '🏠 Tiền thuê', 'electricity' => '⚡ Điện', 'water' => '💧 Nước', 'service' => '🔧 Dịch vụ', 'late_fee' => '⚠ Phí phạt', default => $item->type } }} | {{ $item->description }} | **{{ number_format($item->total, 0, ',', '.') }}đ** |
@endforeach
@if((float)$invoice->late_fee > 0)
| ⚠ **Phí phạt trễ hạn** | Phạt chậm thanh toán | **{{ number_format($invoice->late_fee, 0, ',', '.') }}đ** |
@endif
| | **TỔNG CỘNG** | **{{ number_format($invoice->total_amount, 0, ',', '.') }}đ** |

@if($invoice->notes && str_contains($invoice->notes, '[!]'))
<x-mail::panel>
⚠ **Lưu ý:** {{ $invoice->notes }}
</x-mail::panel>
@endif

@if($evidenceImageUrl)
**📷 Ảnh chụp đồng hồ điện/nước tháng {{ $invoice->billing_month }}:**

![Ảnh đồng hồ]({{ $evidenceImageUrl }})
@endif

---

**💳 Hình thức thanh toán được chấp nhận:**
- Chuyển khoản ngân hàng trực tiếp cho chủ trọ
- Thanh toán tiền mặt trực tiếp

**File PDF hóa đơn đính kèm** trong email này để bạn lưu trữ.

Nếu bạn có bất kỳ thắc mắc nào về hóa đơn, vui lòng liên hệ trực tiếp với chủ trọ.

Trân trọng,<br>
**{{ config('app.name') }}**
</x-mail::message>
