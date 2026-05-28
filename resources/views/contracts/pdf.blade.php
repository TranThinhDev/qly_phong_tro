<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hợp Đồng Thuê Phòng</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif; /* Font hỗ trợ tiếng Việt tốt trong DOMPDF */
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 24px;
            text-transform: uppercase;
            margin: 0;
        }
        .header h2 {
            font-size: 18px;
            font-weight: normal;
            margin: 5px 0 0;
        }
        .section {
            margin-bottom: 20px;
        }
        .section h3 {
            font-size: 16px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 5px 0;
            vertical-align: top;
        }
        .info-table td.label {
            font-weight: bold;
            width: 30%;
        }
        .terms {
            text-align: justify;
        }
        .footer {
            margin-top: 50px;
            width: 100%;
        }
        .signature-box {
            width: 50%;
            float: left;
            text-align: center;
        }
        .signature-box strong {
            display: block;
            margin-bottom: 80px; /* Chỗ trống để ký */
        }
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</h1>
        <h2>Độc lập - Tự do - Hạnh phúc</h2>
        <br>
        <h1>HỢP ĐỒNG THUÊ PHÒNG TRỌ</h1>
        <p>Mã hợp đồng: {{ $contract->contract_code }}</p>
        <p>Hôm nay, ngày {{ $date }}, chúng tôi gồm có:</p>
    </div>

    <div class="section">
        <h3>BÊN CHO THUÊ (BÊN A)</h3>
        <table class="info-table">
            <tr>
                <td class="label">Họ và tên:</td>
                <td>{{ $landlord->name }}</td>
            </tr>
            <tr>
                <td class="label">Số điện thoại:</td>
                <td>{{ $landlord->PhoneNumber ?? 'Chưa cập nhật' }}</td>
            </tr>
            <tr>
                <td class="label">Email:</td>
                <td>{{ $landlord->email }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h3>BÊN THUÊ (BÊN B)</h3>
        <table class="info-table">
            <tr>
                <td class="label">Họ và tên:</td>
                <td>{{ $tenant->name }}</td>
            </tr>
            <tr>
                <td class="label">Số điện thoại:</td>
                <td>{{ $tenant->PhoneNumber ?? 'Chưa cập nhật' }}</td>
            </tr>
            <tr>
                <td class="label">Email:</td>
                <td>{{ $tenant->email }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h3>ĐIỀU KHOẢN HỢP ĐỒNG</h3>
        <p><strong>Điều 1: Thông tin phòng thuê</strong></p>
        <p>Bên A đồng ý cho bên B thuê phòng tại địa chỉ: {{ $room->detail_address }}, thuộc khu vực {{ $room->getWard->name ?? 'Chưa cập nhật' }}. Tên phòng: {{ $room->name }}.</p>
        
        <p><strong>Điều 2: Thời hạn thuê</strong></p>
        <p>Thời hạn hợp đồng tính từ ngày {{ \Carbon\Carbon::parse($contract->start_date)->format('d/m/Y') }} đến {{ $contract->end_date ? \Carbon\Carbon::parse($contract->end_date)->format('d/m/Y') : 'khi có thông báo chấm dứt' }}.</p>

        <p><strong>Điều 3: Giá thuê và tiền cọc</strong></p>
        <ul>
            <li>Giá thuê hàng tháng: {{ number_format($contract->monthly_rent, 0, ',', '.') }} VNĐ.</li>
            <li>Tiền đặt cọc: {{ number_format($contract->deposit_amount, 0, ',', '.') }} VNĐ.</li>
        </ul>

        <p><strong>Điều 4: Quy định bổ sung</strong></p>
        <div class="terms">
            {!! nl2br(e($contract->terms_content ?? 'Hai bên tự thỏa thuận tuân thủ các quy định chung của pháp luật và nội quy khu trọ.')) !!}
        </div>
    </div>

    <div class="footer clearfix">
        <div class="signature-box">
            <strong>ĐẠI DIỆN BÊN B</strong>
            <p>(Ký, ghi rõ họ tên)</p>
            @if($contract->isSigned())
                <p style="color: green; font-style: italic;">Đã ký điện tử lúc:<br>{{ $contract->signed_at->format('d/m/Y H:i:s') }}</p>
            @endif
        </div>
        <div class="signature-box">
            <strong>ĐẠI DIỆN BÊN A</strong>
            <p>(Ký, ghi rõ họ tên)</p>
        </div>
    </div>

</body>
</html>
