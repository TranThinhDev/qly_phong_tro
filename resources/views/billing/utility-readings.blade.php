@extends('layouts.app')

@section('style')
<style>
    /* ── Page wrapper ── */
    .billing-page {
        padding: 32px 0 60px;
        min-height: 80vh;
        background: #f8f9fc;
    }

    /* ── Header card ── */
    .billing-header {
        background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
        border-radius: 16px;
        padding: 28px 32px;
        color: #fff;
        margin-bottom: 28px;
        display: flex;
        align-items: center;
        gap: 20px;
        box-shadow: 0 8px 32px rgba(37, 99, 235, .25);
    }

    .billing-header .icon-wrap {
        width: 56px; height: 56px;
        background: rgba(255,255,255,.18);
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 26px; flex-shrink: 0;
    }

    .billing-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
    .billing-header p  { font-size: .9rem; opacity: .85; margin: 4px 0 0; }

    /* ── Month picker card ── */
    .month-picker-card {
        background: #fff;
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 12px rgba(0,0,0,.06);
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }

    .month-picker-card label {
        font-weight: 600; font-size: .9rem; color: #374151;
        white-space: nowrap;
    }

    .month-picker-card select,
    .month-picker-card input[type="number"] {
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        padding: 8px 14px;
        font-size: .9rem;
        outline: none;
        transition: border-color .2s;
        background: #f9fafb;
    }

    .month-picker-card select:focus,
    .month-picker-card input:focus { border-color: #2563eb; background: #fff; }

    .btn-load {
        background: #2563eb; color: #fff;
        border: none; border-radius: 8px;
        padding: 9px 22px; font-weight: 600; font-size: .88rem;
        cursor: pointer; transition: background .2s, transform .15s;
        display: flex; align-items: center; gap: 6px;
    }
    .btn-load:hover { background: #1d4ed8; transform: translateY(-1px); }

    /* ── Room table ── */
    .rooms-grid { display: flex; flex-direction: column; gap: 16px; }

    .room-card {
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 2px 12px rgba(0,0,0,.06);
        overflow: hidden;
        border: 1.5px solid #f1f5f9;
        transition: box-shadow .2s;
    }
    .room-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.10); }

    .room-card-header {
        background: linear-gradient(90deg, #f0f7ff 0%, #e8f4fd 100%);
        padding: 14px 20px;
        display: flex; align-items: center; justify-content: space-between;
        border-bottom: 1px solid #e2e8f0;
    }

    .room-name {
        font-weight: 700; font-size: 1rem; color: #1e3a5f;
        display: flex; align-items: center; gap: 8px;
    }

    .room-tenant { font-size: .82rem; color: #64748b; margin-top: 2px; }

    .badge-status {
        font-size: .75rem; font-weight: 600;
        padding: 3px 10px; border-radius: 20px;
        display: inline-block;
    }
    .badge-draft     { background: #fef3c7; color: #92400e; }
    .badge-finalized { background: #d1fae5; color: #065f46; }
    .badge-empty     { background: #f1f5f9; color: #64748b; }

    .room-card-body {
        padding: 20px;
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 20px;
        align-items: start;
    }

    @media (max-width: 768px) {
        .room-card-body { grid-template-columns: 1fr; }
    }

    /* ── Input groups ── */
    .input-group-billing { display: flex; flex-direction: column; gap: 6px; }

    .input-group-billing label {
        font-size: .8rem; font-weight: 600; color: #374151;
        display: flex; align-items: center; gap: 5px;
    }

    .meter-icon { font-size: 1.1rem; }

    .input-with-ref {
        position: relative;
    }

    .meter-input {
        width: 100%; padding: 10px 14px;
        border: 1.5px solid #e5e7eb; border-radius: 9px;
        font-size: .95rem; font-weight: 600; color: #1e293b;
        background: #f9fafb; outline: none;
        transition: border-color .2s, background .2s;
    }
    .meter-input:focus { border-color: #2563eb; background: #fff; }

    .ref-hint {
        font-size: .74rem; color: #64748b;
        margin-top: 4px;
        display: flex; align-items: center; gap: 4px;
    }
    .ref-hint .consumption {
        font-weight: 700; color: #2563eb;
    }

    .field-error { color: #ef4444; font-size: .76rem; margin-top: 3px; }

    /* ── Evidence image upload ── */
    .upload-zone {
        border: 2px dashed #cbd5e1;
        border-radius: 10px;
        padding: 14px;
        text-align: center;
        cursor: pointer;
        transition: border-color .2s, background .2s;
        position: relative;
        min-height: 80px;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        gap: 6px;
    }
    .upload-zone:hover { border-color: #2563eb; background: #f0f7ff; }
    .upload-zone input[type="file"] {
        position: absolute; inset: 0;
        opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }

    .preview-img {
        width: 100%; max-height: 110px;
        object-fit: cover; border-radius: 7px;
        margin-bottom: 6px;
    }

    .upload-hint { font-size: .78rem; color: #94a3b8; }

    /* ── Save button ── */
    .btn-save-row {
        display: flex; align-items: center; justify-content: flex-end;
        padding: 14px 20px;
        border-top: 1px solid #f1f5f9;
        gap: 10px;
    }

    .btn-save {
        background: #2563eb; color: #fff;
        border: none; border-radius: 8px;
        padding: 9px 24px; font-weight: 600; font-size: .88rem;
        cursor: pointer; transition: background .2s, transform .15s;
        display: flex; align-items: center; gap: 6px;
    }
    .btn-save:hover { background: #1d4ed8; transform: translateY(-1px); }

    /* ── Empty state ── */
    .empty-state {
        text-align: center; padding: 60px 24px;
        background: #fff; border-radius: 14px;
        box-shadow: 0 2px 12px rgba(0,0,0,.06);
    }
    .empty-state .empty-icon { font-size: 3rem; margin-bottom: 12px; }
    .empty-state p { color: #64748b; font-size: .95rem; margin: 0; }
</style>
@endsection

@section('content')
<div class="billing-page">
    <div class="container">

        {{-- Page Header --}}
        <div class="billing-header">
            <div class="icon-wrap">⚡</div>
            <div>
                <h1>Nhập Chỉ Số Điện / Nước</h1>
                <p>Ghi nhận chỉ số công-tơ hàng tháng cho các phòng đang cho thuê</p>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success mb-4">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger mb-4">
                {{ session('error') }}
            </div>
        @endif

        {{-- Lọc tháng/năm --}}
        <div class="month-picker-card">
            <form action="{{ route('billing.utility-readings') }}" method="GET" class="d-flex align-items-center" style="gap: 16px; flex-wrap: wrap; margin: 0; width: 100%;">
                <div class="d-flex align-items-center" style="gap: 10px;">
                    <label for="month-select">Kỳ hóa đơn:</label>
                    <select id="month-select" name="month">
                        @for ($m = 1; $m <= 12; $m++)
                            <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>Tháng {{ $m }}</option>
                        @endfor
                    </select>
                </div>
                
                <div class="d-flex align-items-center" style="gap: 10px;">
                    <label for="year-input">Năm:</label>
                    <input type="number" id="year-input" name="year" value="{{ $year }}" min="2000" max="2100">
                </div>

                <button class="btn-load" type="submit">
                    <i class="fas fa-search"></i> Xem danh sách
                </button>
            </form>
        </div>

        <div class="rooms-grid">
            @if(count($rooms) === 0)
                <div class="empty-state">
                    <div class="empty-icon">🏠</div>
                    <p>Không có phòng nào đang cho thuê (active) trong tháng này.</p>
                </div>
            @else
                @foreach($rooms as $room)
                    @php
                        $reading = $room->reading;
                        $prevReading = $room->previous_reading;
                        
                        $isFinalized = $reading && $reading->status === 'finalized';
                    @endphp
                    
                    <div class="room-card">
                        <form action="{{ route('billing.utility-readings.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="room_id" value="{{ $room->id }}">
                            <input type="hidden" name="month" value="{{ $month }}">
                            <input type="hidden" name="year" value="{{ $year }}">

                            <div class="room-card-header">
                                <div>
                                    <div class="room-name">
                                        <i class="fas fa-door-open"></i> {{ $room->name }}
                                    </div>
                                    <div class="room-tenant">
                                        <i class="fas fa-user text-muted"></i> 
                                        Khách: <strong>{{ $room->activeContract->tenant->name }}</strong>
                                        - ĐT: {{ $room->activeContract->tenant->PhoneNumber }}
                                    </div>
                                </div>
                                <div>
                                    @if(!$reading)
                                        <span class="badge-status badge-empty">Chưa nhập</span>
                                    @elseif($reading->status === 'finalized')
                                        <span class="badge-status badge-finalized">Đã chốt (Tạo hóa đơn)</span>
                                    @else
                                        <span class="badge-status badge-draft">Đã lưu nháp</span>
                                    @endif
                                </div>
                            </div>

                            <div class="room-card-body">
                                {{-- Số Điện --}}
                                <div class="input-group-billing">
                                    <label>
                                        <span class="meter-icon">⚡</span> Điện (kWh)
                                    </label>
                                    <div class="input-with-ref">
                                        <input type="number" name="electricity_index" class="meter-input" 
                                            value="{{ old('electricity_index', $reading->electricity_index ?? '') }}"
                                            placeholder="Nhập chỉ số điện..."
                                            min="{{ $prevReading ? $prevReading->electricity_index : 0 }}"
                                            {{ $isFinalized ? 'disabled' : 'required' }}>
                                    </div>
                                    @if($prevReading)
                                        <div class="ref-hint">
                                            <i class="fas fa-history"></i> Tháng trước: <strong>{{ $prevReading->electricity_index }}</strong>
                                        </div>
                                    @else
                                        <div class="ref-hint"><i class="fas fa-info-circle"></i> Chưa có chỉ số tháng trước</div>
                                    @endif
                                </div>

                                {{-- Số Nước --}}
                                <div class="input-group-billing">
                                    <label>
                                        <span class="meter-icon">💧</span> Nước (m³)
                                    </label>
                                    <div class="input-with-ref">
                                        <input type="number" name="water_index" class="meter-input" 
                                            value="{{ old('water_index', $reading->water_index ?? '') }}"
                                            placeholder="Nhập chỉ số nước..."
                                            min="{{ $prevReading ? $prevReading->water_index : 0 }}"
                                            {{ $isFinalized ? 'disabled' : 'required' }}>
                                    </div>
                                    @if($prevReading)
                                        <div class="ref-hint">
                                            <i class="fas fa-history"></i> Tháng trước: <strong>{{ $prevReading->water_index }}</strong>
                                        </div>
                                    @else
                                        <div class="ref-hint"><i class="fas fa-info-circle"></i> Chưa có chỉ số tháng trước</div>
                                    @endif
                                </div>

                                {{-- Ảnh minh chứng Điện --}}
                                <div class="input-group-billing">
                                    <label><i class="fas fa-bolt meter-icon" style="color:#eab308"></i> Ảnh đồng hồ Điện</label>
                                    
                                    @if($reading && $reading->electricity_evidence_image_url)
                                        <img src="{{ Storage::url($reading->electricity_evidence_image_url) }}" class="preview-img" alt="Ảnh điện">
                                    @endif

                                    @if(!$isFinalized)
                                        <div class="upload-zone" onclick="document.getElementById('elec_evidence_{{ $room->id }}').click()">
                                            <input type="file" id="elec_evidence_{{ $room->id }}" name="electricity_evidence_image" accept="image/*" 
                                                onchange="previewImage(this, 'elec_preview_{{ $room->id }}')" 
                                                {{ (!$reading || !$reading->electricity_evidence_image_url) ? 'required' : '' }}>
                                            <i class="fas fa-cloud-upload-alt" style="font-size: 20px; color: #94a3b8"></i>
                                            <div class="upload-hint">Tải ảnh điện lên</div>
                                        </div>
                                        <img id="elec_preview_{{ $room->id }}" class="preview-img mt-2" style="display:none" alt="Preview điện">
                                    @endif
                                </div>

                                {{-- Ảnh minh chứng Nước --}}
                                <div class="input-group-billing">
                                    <label><i class="fas fa-tint meter-icon" style="color:#0ea5e9"></i> Ảnh đồng hồ Nước</label>
                                    
                                    @if($reading && $reading->water_evidence_image_url)
                                        <img src="{{ Storage::url($reading->water_evidence_image_url) }}" class="preview-img" alt="Ảnh nước">
                                    @endif

                                    @if(!$isFinalized)
                                        <div class="upload-zone" onclick="document.getElementById('water_evidence_{{ $room->id }}').click()">
                                            <input type="file" id="water_evidence_{{ $room->id }}" name="water_evidence_image" accept="image/*" 
                                                onchange="previewImage(this, 'water_preview_{{ $room->id }}')" 
                                                {{ (!$reading || !$reading->water_evidence_image_url) ? 'required' : '' }}>
                                            <i class="fas fa-cloud-upload-alt" style="font-size: 20px; color: #94a3b8"></i>
                                            <div class="upload-hint">Tải ảnh nước lên</div>
                                        </div>
                                        <img id="water_preview_{{ $room->id }}" class="preview-img mt-2" style="display:none" alt="Preview nước">
                                    @endif
                                </div>
                            </div>

                            @if(!$isFinalized)
                                <div class="btn-save-row">
                                    <button type="submit" class="btn-save">
                                        <i class="fas fa-save"></i> Lưu Số Liệu
                                    </button>
                                </div>
                            @endif
                        </form>
                    </div>
                @endforeach
            @endif
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
    function previewImage(input, previewId) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var preview = document.getElementById(previewId);
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>
@endpush
