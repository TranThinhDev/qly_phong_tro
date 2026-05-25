@extends('layouts.app')

@section('style')
    <style>
        .table-booking th, .table-booking td {
            vertical-align: middle;
        }
        .truncate-text {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
        }
    </style>
@endsection

@section('content')
    <!-- agent grid section start -->
    <section class="agent-section property-section">
        <div class="container">
            <div class="row">
                <div class="col-xl-12 col-lg-12">
                    <div class="filter-panel">
                        <div class="top-panel">
                            <div>
                                <h2>Danh sách yêu cầu của phòng</h2>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-booking">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Khách hàng</th>
                                            <th>Loại Booking</th>
                                            <th>Thời gian hẹn</th>
                                            <th>Lời nhắn</th>
                                            <th>Trạng thái</th>
                                            <th class="text-center">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($BookingList as $index => $item)
                                            <tr>
                                                <td>{{ $index + 1 }}</td>
                                                
                                                {{-- Thông tin khách --}}
                                                <td>
                                                    <div class="fw-bold">{{ $item->name }}</div>
                                                    <div class="text-muted" style="font-size: 0.85rem;"><i class="fas fa-phone-alt me-1"></i> {{ $item->phone ?? 'Chưa cập nhật' }}</div>
                                                    <div class="text-muted" style="font-size: 0.85rem;"><i class="fas fa-envelope me-1"></i> {{ $item->email }}</div>
                                                </td>
                                                
                                                {{-- Loại Booking --}}
                                                <td>
                                                    @if ($item->booking_type == 'deposit')
                                                        <span class="badge bg-primary">Đặt cọc giữ chỗ</span>
                                                    @elseif ($item->booking_type == 'appointment')
                                                        <span class="badge bg-info text-dark">Hẹn xem phòng</span>
                                                    @else
                                                        <span class="badge bg-secondary">{{ $item->booking_type }}</span>
                                                    @endif
                                                </td>

                                                {{-- Thời gian hẹn --}}
                                                <td>
                                                    @if ($item->booking_type == 'appointment' && $item->appointment_date)
                                                        <span class="fw-semibold text-dark"><i class="fas fa-clock me-1"></i> {{ \Carbon\Carbon::parse($item->appointment_date)->format('d/m/Y H:i') }}</span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>

                                                {{-- Lời nhắn --}}
                                                <td>
                                                    <span class="truncate-text" title="{{ $item->message }}" data-bs-toggle="tooltip">
                                                        {{ $item->message ?? 'Không có' }}
                                                    </span>
                                                </td>

                                                {{-- Trạng thái --}}
                                                <td>
                                                    @if (in_array($item->status, ['pending']))
                                                        <span class="badge bg-warning text-dark">Chờ duyệt</span>
                                                    @elseif (in_array($item->status, ['paid', 'confirmed', 'approved']))
                                                        <span class="badge bg-success">Đã xác nhận</span>
                                                    @elseif (in_array($item->status, ['cancelled', 'rejected']))
                                                        <span class="badge bg-danger">Đã hủy/Từ chối</span>
                                                    @else
                                                        <span class="badge bg-secondary">{{ $item->status }}</span>
                                                    @endif
                                                </td>

                                                {{-- Thao tác --}}
                                                <td class="text-center">
                                                    @if ($item->booking_type == 'appointment' && $item->status == 'pending')
                                                        <div class="d-flex justify-content-center gap-2">
                                                            <form action="{{ route('booking.update', $item->id) }}" method="POST" style="display:inline-block;">
                                                                @csrf
                                                                @method('PUT')
                                                                <input type="hidden" name="status" value="confirmed">
                                                                <button type="submit" class="btn btn-sm btn-success px-2 py-1" title="Duyệt lịch hẹn" onclick="return confirm('Xác nhận duyệt lịch hẹn này?');">
                                                                    <i class="fas fa-check"></i> Duyệt
                                                                </button>
                                                            </form>
                                                            <form action="{{ route('booking.update', $item->id) }}" method="POST" style="display:inline-block;">
                                                                @csrf
                                                                @method('PUT')
                                                                <input type="hidden" name="status" value="rejected">
                                                                <button type="submit" class="btn btn-sm btn-danger px-2 py-1" title="Từ chối lịch hẹn" onclick="return confirm('Bạn muốn từ chối lịch hẹn này?');">
                                                                    <i class="fas fa-times"></i> Từ chối
                                                                </button>
                                                            </form>
                                                        </div>
                                                    @else
                                                        <span class="text-muted" style="font-size: 0.85rem;">Không có thao tác</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7" class="text-center py-5">
                                                    <div class="text-muted">
                                                        <i class="fas fa-calendar-times fa-3x mb-3 text-light"></i>
                                                        <p class="mb-0 fs-5">Chưa có yêu cầu đặt phòng/lịch hẹn nào.</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>
    <!-- agent grid section end -->
@endsection

@section('js')
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Khởi tạo bootstrap tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
@endsection
