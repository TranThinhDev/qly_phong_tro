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
    .btn-load:disabled { background: #9ca3af; cursor: not-allowed; transform: none; }

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
    .meter-input.is-error { border-color: #ef4444; background: #fff8f8; }
    .meter-input.is-saved { border-color: #10b981; background: #f0fdf4; }

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
    .btn-save:hover:not(:disabled) { background: #1d4ed8; transform: translateY(-1px); }
    .btn-save:disabled { background: #93c5fd; cursor: not-allowed; transform: none; }

    .save-success-msg {
        font-size: .82rem; color: #10b981; font-weight: 600;
        display: flex; align-items: center; gap: 4px;
        animation: fadeInUp .3s ease;
    }

    /* ── Spinner ── */
    .spinner {
        width: 18px; height: 18px;
        border: 2.5px solid rgba(255,255,255,.4);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin .7s linear infinite;
        display: inline-block;
    }

    /* ── Empty state ── */
    .empty-state {
        text-align: center; padding: 60px 24px;
        background: #fff; border-radius: 14px;
        box-shadow: 0 2px 12px rgba(0,0,0,.06);
    }
    .empty-state .empty-icon { font-size: 3rem; margin-bottom: 12px; }
    .empty-state p { color: #64748b; font-size: .95rem; margin: 0; }

    /* ── Skeleton loader ── */
    .skeleton-card {
        background: #fff; border-radius: 14px;
        padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,.06);
        animation: pulse 1.4s ease infinite;
    }
    .skeleton-line {
        height: 14px; background: #e2e8f0;
        border-radius: 6px; margin-bottom: 10px;
    }
    .skeleton-line.w-60 { width: 60%; }
    .skeleton-line.w-40 { width: 40%; }
    .skeleton-line.h-32 { height: 32px; margin: 0; }

    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50%       { opacity: .55; }
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(5px); }
        to   { opacity: 1; transform: translateY(0); }
    }
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

        {{-- Vue 3 App Mount Point --}}
        <div id="utility-reading-app">
            {{-- Rendered by Vue --}}
        </div>

    </div>
</div>
@endsection

@push('scripts')
{{-- Vue 3 CDN (ESM via importmap) --}}
<script type="importmap">
{
    "imports": {
        "vue": "https://unpkg.com/vue@3/dist/vue.esm-browser.prod.js"
    }
}
</script>

<script type="module">
import { createApp } from 'vue';
import UtilityReadingApp from '/js/billing/UtilityReadingForm.js';

createApp(UtilityReadingApp).mount('#utility-reading-app');
</script>

{{-- Pass server data to Vue via global window object --}}
<script>
    window.__BILLING_CONFIG__ = {
        apiBase: '{{ url("/api") }}',
        // Sanctum token không cần thiết với auth:api vì dùng session cookie
        // nhưng nếu dùng Sanctum SPA mode ta sẽ thêm ở đây
        csrfToken: '{{ csrf_token() }}',
        currentUser: {
            id: {{ auth()->id() }},
            name: '{{ auth()->user()->name }}',
        },
        currentMonth: {{ now()->month }},
        currentYear: {{ now()->year }},
    };
</script>
@endpush
