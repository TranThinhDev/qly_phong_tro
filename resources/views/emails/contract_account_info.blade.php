<x-mail::message>
# Xin chào {{ $user->name }},

Chủ trọ vừa khởi tạo một **Hợp đồng thuê phòng** mới dành cho bạn trên hệ thống Quản lý Phòng trọ. 
Để đảm bảo quyền lợi và tính pháp lý, bạn cần truy cập vào hệ thống để kiểm tra thông tin và tiến hành ký điện tử.

@if($plainPassword)
---
### 🔐 Thông tin tài khoản của bạn
Tài khoản của bạn đã được hệ thống tạo tự động để bạn có thể quản lý hợp đồng lâu dài.

- **Tên đăng nhập / Email:** {{ $user->email }}
- **Mật khẩu tạm thời:** `{{ $plainPassword }}`

*(Vui lòng đổi mật khẩu sau khi đăng nhập lần đầu tiên để đảm bảo an toàn).*
@else
---
### 🔐 Đăng nhập bằng tài khoản của bạn
Hệ thống nhận thấy bạn đã có tài khoản. Vui lòng đăng nhập bằng Email/SĐT cũ của bạn để tiếp tục.
@endif

<x-mail::panel>
**Thông tin tóm tắt hợp đồng:**
- **Mã hợp đồng:** {{ $contract->contract_code }}
- **Bắt đầu thuê:** {{ \Carbon\Carbon::parse($contract->start_date)->format('d/m/Y') }}
- **Giá thuê/tháng:** {{ number_format($contract->monthly_rent, 0, ',', '.') }} VNĐ
- **Tiền cọc giữ chỗ:** {{ number_format($contract->deposit_amount, 0, ',', '.') }} VNĐ
</x-mail::panel>

<x-mail::button :url="route('contracts.sign', $contract->id)" color="success">
Đăng nhập & Ký hợp đồng ngay
</x-mail::button>

Nếu bạn có bất kỳ thắc mắc nào, vui lòng liên hệ trực tiếp với chủ trọ.

Trân trọng,<br>
**{{ config('app.name') }}**
</x-mail::message>
