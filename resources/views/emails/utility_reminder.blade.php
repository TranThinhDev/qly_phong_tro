<x-mail::message>
# Xin chào {{ $landlord->name }},

Hệ thống ghi nhận bạn còn **{{ $missingRoomsCount }} phòng** chưa được cập nhật chỉ số điện/nước cho kỳ thanh toán **Tháng {{ $month }}/{{ $year }}**.

Để hóa đơn tự động được sinh ra một cách chính xác vào ngày 01 đầu tháng tới, vui lòng đăng nhập vào hệ thống và cập nhật số liệu trước hạn.

<x-mail::button :url="url('/utility-readings')" color="primary">
Cập nhật chỉ số ngay
</x-mail::button>

Nếu bạn đã cập nhật đầy đủ, xin vui lòng bỏ qua email này.

Trân trọng,<br>
**{{ config('app.name') }}**
</x-mail::message>
