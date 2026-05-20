<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Biên Nhận Đặt Phòng</title>
    <style>
        /* Bắt buộc phải dùng font DejaVu Sans để không bị lỗi tiếng Việt */
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 14px;
            color: #333;
            line-height: 1.5;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }

        .sys-name {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .title {
            font-size: 24px;
            font-weight: bold;
            color: #e74c3c;
            text-transform: uppercase;
            margin: 10px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        /* Bảng thông tin khách hàng */
        .info-table td {
            padding: 5px 0;
            vertical-align: top;
        }

        /* Bảng chi tiết tiền bạc */
        .item-table th, .item-table td {
            border: 1px solid #bdc3c7;
            padding: 10px;
            text-align: left;
        }

        .item-table th {
            background-color: #ecf0f1;
            font-weight: bold;
        }

        .total-row td {
            font-size: 16px;
            font-weight: bold;
            color: #c0392b;
        }

        .footer {
            margin-top: 40px;
            width: 100%;
        }

        .signature-box {
            width: 50%;
            float: left;
            text-align: center;
        }

        .italic {
            font-style: italic;
            font-size: 12px;
            color: #7f8c8d;
        }
    </style>
</head>
<body>

    <div class="header">
        <div class="sys-name">HỆ THỐNG QUẢN LÝ PHÒNG TRỌ THÔNG MINH</div>
        <div class="title">Biên Nhận Đặt Cọc</div>
        <div class="italic">Ngày lập: {{ \Carbon\Carbon::now()->format('d/m/Y H:i') }}</div>
    </div>

    <table class="info-table">
        <tr>
            <td width="20%"><strong>Mã giao dịch:</strong></td>
            <td width="30%">#BK-{{ str_pad($booking->id, 5, '0', STR_PAD_LEFT) }}</td>
            <td width="20%"><strong>Trạng thái:</strong></td>
            <td width="30%">
                @if($booking->status == 1)
                    Đã xác nhận
                @else
                    Chờ xử lý
                @endif
            </td>
        </tr>
        <tr>
            <td><strong>Khách hàng:</strong></td>
            <td>{{ $booking->name }}</td>
            <td><strong>Số điện thoại:</strong></td>
            <td>{{ $booking->phone }}</td>
        </tr>
        <tr>
            <td><strong>Email:</strong></td>
            <td colspan="3">{{ $booking->email ?? 'Không có' }}</td>
        </tr>
    </table>

    <br>

    <table class="item-table">
        <thead>
            <tr>
                <th>Nội dung thanh toán</th>
                <th>Ngày dự kiến đến</th>
                <th>Số tiền cọc</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>Đặt cọc giữ chỗ: Phòng {{ $booking->room->name ?? 'N/A' }}</strong><br>
                    <span style="font-size: 12px; color: #555;">Ghi chú: {{ $booking->message ?? 'Không có ghi chú' }}</span>
                </td>
                <td>{{ \Carbon\Carbon::parse($booking->date)->format('d/m/Y') }}</td>
                <td>{{ number_format($booking->price, 0, ',', '.') }} VNĐ</td>
            </tr>
            <tr class="total-row">
                <td colspan="2" style="text-align: right;">TỔNG TIỀN ĐÃ CỌC:</td>
                <td>{{ number_format($booking->price, 0, ',', '.') }} VNĐ</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <div class="signature-box">
            <strong>Khách hàng</strong><br>
            <span class="italic">(Ký và ghi rõ họ tên)</span>
            <br><br><br><br><br>
            <span>{{ $booking->name }}</span>
        </div>
        <div class="signature-box">
            <strong>Đại diện Chủ trọ / Admin</strong><br>
            <span class="italic">(Ký và ghi rõ họ tên)</span>
            <br><br><br><br><br>
        </div>
    </div>

</body>
</html>