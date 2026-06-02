<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Hóa Đơn {{ $invoice->invoice_code }}</title>
    <style>
        /*
         * QUAN TRỌNG: DomPDF không hỗ trợ Flexbox / Grid.
         * Dùng float + table layout cho mọi thứ.
         * Font DejaVu Sans là bắt buộc để hiển thị tiếng Việt.
         */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 13px;
            color: #1a202c;
            line-height: 1.6;
            background: #fff;
        }

        /* ── Page wrapper ── */
        .page {
            padding: 32px 40px;
        }

        /* ── Header ── */
        .header {
            width: 100%;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .header-left  { float: left;  width: 55%; }
        .header-right { float: right; width: 40%; text-align: right; }
        .clearfix::after { content: ''; display: table; clear: both; }

        .brand-name {
            font-size: 20px;
            font-weight: bold;
            color: #1e3a5f;
            letter-spacing: 0.5px;
        }
        .brand-sub {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        .invoice-title {
            font-size: 26px;
            font-weight: bold;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .invoice-code {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }
        .invoice-code strong { color: #1e3a5f; }

        /* ── Status badge ── */
        .badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-unpaid  { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
        .badge-paid    { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
        .badge-overdue { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        /* ── Info section: 2 cột ── */
        .info-section {
            width: 100%;
            margin-bottom: 24px;
        }
        .info-col {
            float: left;
            width: 48%;
        }
        .info-col-right { float: right; width: 48%; }

        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
        }

        .info-card-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-row { margin-bottom: 4px; }
        .info-label { color: #64748b; font-size: 11.5px; }
        .info-value { color: #1a202c; font-weight: 600; font-size: 12px; }

        /* ── Invoice items table ── */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table thead tr {
            background: #1e3a5f;
            color: #fff;
        }

        .items-table th {
            padding: 10px 12px;
            text-align: left;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .items-table th.text-right { text-align: right; }

        .items-table tbody tr { border-bottom: 1px solid #e2e8f0; }
        .items-table tbody tr:nth-child(even) { background: #f8fafc; }

        .items-table td {
            padding: 10px 12px;
            font-size: 12.5px;
        }

        .items-table td.text-right { text-align: right; }

        /* Type badge trong table */
        .type-tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
        }
        .type-rent        { background: #dbeafe; color: #1d4ed8; }
        .type-electricity { background: #fef9c3; color: #854d0e; }
        .type-water       { background: #cffafe; color: #164e63; }
        .type-service     { background: #f3e8ff; color: #6b21a8; }
        .type-late_fee    { background: #fee2e2; color: #991b1b; }

        /* ── Totals ── */
        .totals-wrapper {
            float: right;
            width: 320px;
            margin-bottom: 24px;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td { padding: 7px 10px; font-size: 12.5px; }
        .totals-table .subtotal-label { color: #64748b; }
        .totals-table .subtotal-value { text-align: right; font-weight: 600; }
        .totals-table .divider td {
            border-top: 2px solid #e2e8f0;
            padding-top: 10px;
        }
        .totals-table .total-label {
            font-size: 14px;
            font-weight: bold;
            color: #1e3a5f;
        }
        .totals-table .total-value {
            text-align: right;
            font-size: 16px;
            font-weight: bold;
            color: #2563eb;
        }
        .totals-table .late-fee-label { color: #dc2626; font-size: 12px; }
        .totals-table .late-fee-value { text-align: right; color: #dc2626; font-weight: 600; }

        /* ── Utility evidence ── */
        .evidence-section {
            margin-top: 8px;
            margin-bottom: 24px;
        }
        .evidence-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            margin-bottom: 8px;
        }
        .evidence-img {
            max-width: 180px;
            max-height: 120px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }

        /* ── Warning note ── */
        .note-warning {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 11.5px;
            color: #92400e;
            margin-bottom: 16px;
        }

        /* ── Footer ── */
        .footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
            margin-top: 24px;
            font-size: 11px;
            color: #94a3b8;
            text-align: center;
        }

        .signature-section {
            width: 100%;
            margin-top: 32px;
        }
        .sig-col {
            float: left;
            width: 33%;
            text-align: center;
            font-size: 11.5px;
        }
        .sig-title { font-weight: bold; color: #374151; margin-bottom: 40px; }
        .sig-name  { color: #64748b; border-top: 1px solid #d1d5db; padding-top: 6px; display: inline-block; min-width: 120px; }
    </style>
</head>
<body>
<div class="page">

    {{-- ══ HEADER ══ --}}
    <div class="header clearfix">
        <div class="header-left">
            <div class="brand-name">{{ config('app.name', 'Quản Lý Phòng Trọ') }}</div>
            <div class="brand-sub">Hệ thống quản lý phòng trọ thông minh</div>
            @if($invoice->contract?->landlord)
            <div style="margin-top:8px; font-size:12px; color:#374151;">
                Chủ trọ: <strong>{{ $invoice->contract->landlord->name }}</strong>
                @if($invoice->contract->landlord->PhoneNumber)
                    · {{ $invoice->contract->landlord->PhoneNumber }}
                @endif
            </div>
            @endif
        </div>
        <div class="header-right">
            <div class="invoice-title">Hóa Đơn</div>
            <div class="invoice-code">
                Mã HD: <strong>{{ $invoice->invoice_code }}</strong>
            </div>
            <div style="margin-top:6px;">
                @php
                    $badgeClass = match($invoice->status) {
                        'paid'    => 'badge-paid',
                        'overdue' => 'badge-overdue',
                        default   => 'badge-unpaid',
                    };
                    $badgeText = match($invoice->status) {
                        'paid'    => 'ĐÃ THANH TOÁN',
                        'overdue' => 'QUÁ HẠN',
                        'partial' => 'THANH TOÁN MỘT PHẦN',
                        'cancelled' => 'ĐÃ HỦY',
                        default   => 'CHƯA THANH TOÁN',
                    };
                @endphp
                <span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>
            </div>
        </div>
    </div>

    {{-- ══ INFO CARDS ══ --}}
    <div class="info-section clearfix">
        {{-- Thông tin khách thuê --}}
        <div class="info-col">
            <div class="info-card">
                <div class="info-card-title">Thông Tin Khách Thuê</div>
                <div class="info-row">
                    <span class="info-label">Họ tên:</span>
                    <span class="info-value"> {{ $invoice->tenant?->name ?? 'N/A' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"> {{ $invoice->tenant?->email ?? 'N/A' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">SĐT:</span>
                    <span class="info-value"> {{ $invoice->tenant?->PhoneNumber ?? 'N/A' }}</span>
                </div>
                @if($invoice->contract?->room)
                <div class="info-row">
                    <span class="info-label">Phòng:</span>
                    <span class="info-value"> {{ $invoice->contract->room->name }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Thông tin hóa đơn --}}
        <div class="info-col-right">
            <div class="info-card">
                <div class="info-card-title">Thông Tin Thanh Toán</div>
                <div class="info-row">
                    <span class="info-label">Kỳ thanh toán:</span>
                    <span class="info-value"> Tháng {{ $invoice->billing_month }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Ngày lập:</span>
                    <span class="info-value"> {{ $invoice->created_at->format('d/m/Y') }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Hạn thanh toán:</span>
                    <span class="info-value" style="color:#dc2626;"> {{ $invoice->due_date->format('d/m/Y') }}</span>
                </div>
                @if($invoice->contract)
                <div class="info-row">
                    <span class="info-label">Hợp đồng:</span>
                    <span class="info-value"> {{ $invoice->contract->contract_code }}</span>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ══ WARNING NOTE (nếu thiếu utility) ══ --}}
    @if($invoice->notes && str_contains($invoice->notes, '[!]'))
    <div class="note-warning">
        ⚠ {{ $invoice->notes }}
    </div>
    @endif

    {{-- ══ ITEMS TABLE ══ --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:5%">#</th>
                <th style="width:10%">Loại</th>
                <th style="width:40%">Mô tả</th>
                <th style="width:15%" class="text-right">Số lượng</th>
                <th style="width:15%" class="text-right">Đơn giá</th>
                <th style="width:15%" class="text-right">Thành tiền</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                    @php
                        $tagClass = 'type-' . $item->type;
                        $tagLabel = match($item->type) {
                            'rent'        => 'Thuê',
                            'electricity' => 'Điện',
                            'water'       => 'Nước',
                            'service'     => 'DV',
                            'late_fee'    => 'Phạt',
                            default       => $item->type,
                        };
                    @endphp
                    <span class="type-tag {{ $tagClass }}">{{ $tagLabel }}</span>
                </td>
                <td>{{ $item->description }}</td>
                <td class="text-right">
                    {{ $item->type === 'rent' || $item->type === 'service' || $item->type === 'late_fee'
                        ? '1'
                        : number_format($item->quantity, 2) }}
                </td>
                <td class="text-right">{{ number_format($item->unit_price, 0, ',', '.') }}đ</td>
                <td class="text-right">{{ number_format($item->total, 0, ',', '.') }}đ</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ══ TOTALS ══ --}}
    <div class="clearfix">
        {{-- Evidence image (nếu có) --}}
        @if($evidenceImagePath)
        <div class="evidence-section" style="float:left; width:50%;">
            <div class="evidence-title">Ảnh chụp đồng hồ điện/nước</div>
            <img src="{{ $evidenceImagePath }}" class="evidence-img" alt="Ảnh đồng hồ"/>
        </div>
        @endif

        <div class="totals-wrapper">
            <table class="totals-table">
                @php
                    $subtotal = $invoice->items->where('type', '!=', 'late_fee')->sum('total');
                @endphp
                <tr>
                    <td class="subtotal-label">Tổng phí dịch vụ:</td>
                    <td class="subtotal-value">{{ number_format($subtotal, 0, ',', '.') }}đ</td>
                </tr>
                @if((float)$invoice->late_fee > 0)
                <tr>
                    <td class="late-fee-label">Phí phạt trễ hạn (5%):</td>
                    <td class="late-fee-value">+ {{ number_format($invoice->late_fee, 0, ',', '.') }}đ</td>
                </tr>
                @endif
                <tr class="divider">
                    <td class="total-label">TỔNG CỘNG:</td>
                    <td class="total-value">{{ number_format($invoice->total_amount, 0, ',', '.') }}đ</td>
                </tr>
            </table>
        </div>
    </div>
    <div class="clearfix"></div>

    {{-- ══ SIGNATURE ══ --}}
    <div class="signature-section clearfix" style="margin-top:40px;">
        <div class="sig-col">
            <div class="sig-title">Khách thuê</div>
            <span class="sig-name">{{ $invoice->tenant?->name ?? '...' }}</span>
        </div>
        <div class="sig-col">
            <div class="sig-title">Chủ trọ</div>
            <span class="sig-name">{{ $invoice->contract?->landlord?->name ?? '...' }}</span>
        </div>
        <div class="sig-col">
            <div class="sig-title">Xác nhận hệ thống</div>
            <span class="sig-name">{{ config('app.name') }}</span>
        </div>
    </div>

    {{-- ══ FOOTER ══ --}}
    <div class="footer">
        Hóa đơn được tạo tự động bởi {{ config('app.name') }} lúc {{ now()->format('H:i d/m/Y') }} ·
        Mọi thắc mắc vui lòng liên hệ chủ trọ.
    </div>

</div>
</body>
</html>
