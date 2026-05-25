@extends('layouts.dashboard')

@section('title')
    Quản lý khiếu nại & Hoàn tiền
@endsection

@section('style')
    {{-- DataTables --}}
    <link href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" rel="stylesheet">
    {{-- Magnific Popup CSS (dự án đã nhúng qua assets) --}}
    <link rel="stylesheet" href="{{ asset('assets/css/magnific-popup.css') }}">
    <style>
        /* ── Badge trạng thái hoàn tiền ── */
        .refund-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .75rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
        }
        .refund-badge.pending  { background:#fff3cd; color:#856404; border:1px solid #ffc107; }
        .refund-badge.approved { background:#d1e7dd; color:#0a3622; border:1px solid #198754; }
        .refund-badge.rejected { background:#f8d7da; color:#842029; border:1px solid #dc3545; }

        /* ── Nút thao tác inline ── */
        .action-cell form { display: inline; }
        .action-cell .btn { font-size: .78rem; padding: 4px 10px; }

        /* ── Thumbnail bằng chứng ── */
        .evidence-thumb {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            cursor: zoom-in;
            transition: transform .15s;
        }
        .evidence-thumb:hover { transform: scale(1.08); }

        /* ── Magnific override: đảm bảo ảnh không bị che bởi sidebar ── */
        .mfp-container { z-index: 9999; }

        /* ── Truncate lý do ── */
        .reason-cell {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
@endsection

@section('content')
    {{-- ── Page Header ── --}}
    <div class="container-fluid">
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <div class="page-header-left">
                        <h3>
                            Quản lý Khiếu nại & Hoàn tiền
                            <small>
                                <ol class="breadcrumb">
                                    <li class="breadcrumb-item">
                                        <a href="{{ route('home') }}"><i class="fa fa-home"></i></a>
                                    </li>
                                    <li class="breadcrumb-item">- Khiếu nại</li>
                                </ol>
                            </small>
                        </h3>
                    </div>
                </div>
                <div class="col-sm-6 text-end">
                    <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                        {{ $disputes->where('refund_status', 'requested')->count() }}
                        yêu cầu đang chờ xử lý
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Flash messages ── --}}
    <div class="container-fluid mb-2">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                <i class="fas fa-check-circle me-1"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                <i class="fas fa-exclamation-triangle me-1"></i> {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
    </div>

    {{-- ── Main Table Card ── --}}
    <div class="container-fluid">
        <div class="row report-summary">
            <div class="col-sm-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h5>
                            <i class="fas fa-hand-holding-usd me-2 text-danger"></i>
                            Danh sách yêu cầu hoàn tiền
                        </h5>
                    </div>
                    <div class="card-body report-table">
                        <div class="table-responsive transactions-table">
                            <table class="table table-bordernone m-0" id="disputes-table">
                                <thead>
                                    <tr>
                                        <th class="light-font">#</th>
                                        <th class="light-font">Mã GD</th>
                                        <th class="light-font">Khách</th>
                                        <th class="light-font">Phòng</th>
                                        <th class="light-font">Tiền cọc</th>
                                        <th class="light-font">Lý do</th>
                                        <th class="light-font">Bằng chứng</th>
                                        <th class="light-font">Trạng thái</th>
                                        <th class="light-font text-center">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($disputes as $index => $dispute)
                                        <tr>
                                            {{-- STT --}}
                                            <td class="light-font">{{ $index + 1 }}</td>

                                            {{-- ① Mã giao dịch --}}
                                            <td>
                                                <span class="fw-bold text-primary">
                                                    {{ $dispute->booking_code ?? '#' . $dispute->id }}
                                                </span>
                                                <div class="light-font" style="font-size:.72rem">
                                                    {{ $dispute->created_at->format('d/m/Y') }}
                                                </div>
                                            </td>

                                            {{-- ② Khách hàng --}}
                                            <td>
                                                <div class="fw-semibold">{{ $dispute->name }}</div>
                                                <div class="light-font" style="font-size:.75rem">
                                                    {{ $dispute->email }}
                                                </div>
                                            </td>

                                            {{-- ③ Phòng --}}
                                            <td>
                                                @if ($dispute->room)
                                                    <a href="{{ route('Room_show', $dispute->room->id) }}"
                                                       class="text-dark fw-semibold text-decoration-none"
                                                       target="_blank">
                                                        {{ Str::limit($dispute->room->name, 30) }}
                                                    </a>
                                                    <div class="light-font" style="font-size:.72rem">
                                                        <i class="fas fa-map-marker-alt me-1"></i>
                                                        {{ Str::limit($dispute->room->detail_address, 35) }}
                                                    </div>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>

                                            {{-- ④ Tiền cọc --}}
                                            <td class="fw-bold text-danger">
                                                {{ $dispute->deposit_amount
                                                    ? number_format($dispute->deposit_amount) . ' VNĐ'
                                                    : '—' }}
                                            </td>

                                            {{-- ⑤ Lý do --}}
                                            <td>
                                                <div class="reason-cell"
                                                     title="{{ $dispute->refund_reason }}"
                                                     data-bs-toggle="tooltip"
                                                     data-bs-placement="top">
                                                    {{ $dispute->refund_reason ?? '—' }}
                                                </div>
                                            </td>

                                            {{-- ⑥ Bằng chứng — Magnific Popup --}}
                                            <td class="text-center">
                                                @if ($dispute->evidence_image_path)
                                                    {{--
                                                        Magnific Popup: bọc <a> với class "mfp-image".
                                                        Khi click, thư viện sẽ hiện lightbox ảnh full.
                                                    --}}
                                                    <a href="{{ asset('storage/' . $dispute->evidence_image_path) }}"
                                                       class="mfp-image d-inline-block"
                                                       title="Bằng chứng - {{ $dispute->booking_code }}">
                                                        <img src="{{ asset('storage/' . $dispute->evidence_image_path) }}"
                                                             alt="Bằng chứng"
                                                             class="evidence-thumb">
                                                    </a>
                                                @else
                                                    <span class="text-muted light-font" style="font-size:.8rem">
                                                        Không có
                                                    </span>
                                                @endif
                                            </td>

                                            {{-- ⑦ Trạng thái --}}
                                            <td>
                                                @if ($dispute->refund_status === 'requested')
                                                    <span class="refund-badge pending">
                                                        <i class="fas fa-clock"></i> Đang xử lý
                                                    </span>
                                                @elseif ($dispute->refund_status === 'refunded')
                                                    <span class="refund-badge approved">
                                                        <i class="fas fa-check-circle"></i> Đã hoàn
                                                    </span>
                                                @elseif ($dispute->refund_status === 'rejected')
                                                    <span class="refund-badge rejected">
                                                        <i class="fas fa-times-circle"></i> Bị từ chối
                                                    </span>
                                                @else
                                                    <span class="label label-dark label-pill">—</span>
                                                @endif
                                            </td>

                                            {{-- ⑧ Thao tác --}}
                                            <td class="action-cell text-center">
                                                @if ($dispute->refund_status === 'requested')

                                                    {{-- Nút Duyệt hoàn tiền --}}
                                                    <form method="POST"
                                                          action="{{ route('admin.disputes.approve', $dispute->id) }}">
                                                        @csrf
                                                        <button type="submit"
                                                                class="btn btn-success btn-sm mb-1"
                                                                onclick="return confirm('Bạn chắc chắn muốn DUYỆT yêu cầu hoàn tiền này?');">
                                                            <i class="fas fa-check me-1"></i>Duyệt hoàn tiền
                                                        </button>
                                                    </form>

                                                    {{-- Nút Từ chối --}}
                                                    <form method="POST"
                                                          action="{{ route('admin.disputes.reject', $dispute->id) }}">
                                                        @csrf
                                                        <button type="submit"
                                                                class="btn btn-danger btn-sm"
                                                                onclick="return confirm('Bạn chắc chắn muốn TỪ CHỐI yêu cầu hoàn tiền này?');">
                                                            <i class="fas fa-times me-1"></i>Từ chối
                                                        </button>
                                                    </form>

                                                @elseif ($dispute->refund_status === 'refunded')
                                                    <span class="label label-light color-3 label-pill">
                                                        Đã xử lý
                                                    </span>

                                                @elseif ($dispute->refund_status === 'rejected')
                                                    <span class="label label-light color-2 label-pill">
                                                        Đã từ chối
                                                    </span>

                                                @else
                                                    <span class="text-muted light-font">—</span>

                                                @endif
                                            </td>

                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination --}}
                        @if (method_exists($disputes, 'hasPages') && $disputes->hasPages())
                            <div class="d-flex justify-content-center mt-3">
                                {{ $disputes->links() }}
                            </div>
                        @endif

                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js')
    {{-- Magnific Popup JS (dự án đã nhúng qua assets) --}}
    <script src="{{ asset('assets/js/jquery.magnific-popup.js') }}"></script>

    <script>
        $(document).ready(function () {

            // ── 1. Khởi tạo DataTable với cột Thao tác không sắp xếp ── //
            $('#disputes-table').DataTable({
                order: [[0, 'asc']],
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/vi.json',
                },
                columnDefs: [
                    // Tắt sort cho cột Bằng chứng (6) và Thao tác (8)
                    { orderable: false, targets: [6, 8] },
                ],
            });

            // ── 2. Magnific Popup — Lightbox ảnh bằng chứng ── //
            //    Dùng class "mfp-image" đặt trên thẻ <a> trong mỗi hàng.
            //    Gọi gallery trên toàn bảng để có thể next/prev nếu nhiều ảnh.
            $('#disputes-table').magnificPopup({
                delegate: 'a.mfp-image',   // chỉ bắt các link có class này
                type: 'image',
                gallery: {
                    enabled: true,          // cho phép điều hướng prev/next
                    navigateByImgClick: true,
                    preload: [0, 1],
                },
                image: {
                    titleSrc: 'title',      // lấy title từ attribute title của <a>
                    tError: '<a href="%url%">Ảnh</a> không tải được.',
                },
                zoom: {
                    enabled: true,
                    duration: 220,
                    easing: 'ease-in-out',
                },
                closeBtnInside: false,
            });

            // ── 3. Bootstrap Tooltip cho cột Lý do (truncated text) ── //
            $('[data-bs-toggle="tooltip"]').tooltip();

        });
    </script>
@endsection
