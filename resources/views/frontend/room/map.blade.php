@extends('layouts.app')

{{-- ═══════════════════════════════════════════════════════════════
     BƯỚC 3 + 4 + 5: Bản đồ thông minh tìm kiếm phòng trọ theo bán kính
     - Leaflet JS (OpenStreetMap tiles)
     - Geolocation API + SweetAlert2
     - Range Slider bán kính 1–10 km (debounce 500ms)
     - L.circle() vẽ vùng tìm kiếm
     - Leaflet.markercluster gom cụm marker
     - AJAX fetch /api/map/rooms + Loading overlay
     - Popup: ảnh, tên, giá, khoảng cách, Chỉ đường
     - Leaflet Routing Machine: vẽ tuyến đường, ẩn bảng text, box tóm tắt
═══════════════════════════════════════════════════════════════ --}}

{{-- ── CSS ───────────────────────────────────────────────────── --}}
@section('style')
<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<!-- Leaflet MarkerCluster CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css"/>
<!-- Leaflet Routing Machine CSS (Bước 5) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css"/>

<style>
    /* ── Layout tổng thể ── */
    /*
     * Chiều cao thực của header được đo bằng JS và gán vào
     * CSS variable --header-h ngay sau khi DOM sẵn sàng.
     * Fallback 80px phòng trường hợp JS chưa chạy kịp.
     */
    :root { --header-h: 80px; }

    /* Ẩn footer khi đang ở trang bản đồ toàn màn hình */
    body.map-page footer,
    body.map-page .tap-top {
        display: none !important;
    }

    .map-page-wrapper {
        display: flex;
        gap: 0;
        height: calc(100vh - var(--header-h));
        overflow: hidden;
    }

    /* ── Panel điều khiển bên trái ── */
    .map-control-panel {
        width: 320px;
        min-width: 260px;
        background: #fff;
        border-right: 1px solid #e8ecf0;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        box-shadow: 2px 0 12px rgba(0,0,0,.08);
        z-index: 10;
    }

    .map-control-panel .panel-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        padding: 20px 18px 16px;
    }

    .map-control-panel .panel-header h5 {
        margin: 0 0 4px;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: .3px;
    }

    .map-control-panel .panel-header p {
        margin: 0;
        font-size: .78rem;
        opacity: .85;
    }

    .map-control-panel .panel-body {
        padding: 18px;
        flex: 1;
    }

    /* ── Nút Định vị ── */
    #btn-locate {
        width: 100%;
        padding: 11px;
        font-size: .9rem;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: #fff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: opacity .2s, transform .15s;
        box-shadow: 0 4px 14px rgba(102,126,234,.35);
    }

    #btn-locate:hover  { opacity: .9; transform: translateY(-1px); }
    #btn-locate:active { transform: translateY(0); }
    #btn-locate.loading { opacity: .7; cursor: not-allowed; }

    /* spinner animation dùng khi đang định vị */
    @keyframes spin { to { transform: rotate(360deg); } }
    #btn-locate .spinner {
        width: 16px; height: 16px;
        border: 2px solid rgba(255,255,255,.4);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin .7s linear infinite;
        display: none; /* ẩn mặc định */
    }
    #btn-locate.loading .spinner    { display: block; }
    #btn-locate.loading .btn-icon   { display: none; }

    /* ── Divider ── */
    .section-divider {
        border: none;
        border-top: 1px solid #f0f2f5;
        margin-bottom: 12px;
    }

    /* ── Search Location ── */
    .search-location-wrapper {
        position: relative;
        margin-bottom: 12px;
        display: flex;
    }
    .search-location-wrapper input {
        flex: 1;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 8px 0 0 8px;
        font-size: 14px;
        outline: none;
        transition: border-color 0.2s;
    }
    .search-location-wrapper input:focus {
        border-color: #667eea;
    }
    .search-location-wrapper button {
        background: #f7fafc;
        border: 1px solid #e2e8f0;
        border-left: none;
        border-radius: 0 8px 8px 0;
        padding: 0 14px;
        cursor: pointer;
        color: #4a5568;
        transition: background 0.2s;
    }
    .search-location-wrapper button:hover {
        background: #edf2f7;
    }
    .search-results-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        margin-top: 4px;
        z-index: 100;
        max-height: 250px;
        overflow-y: auto;
        display: none;
    }
    .search-results-dropdown.active {
        display: block;
    }
    .search-result-item {
        padding: 10px 12px;
        font-size: 13px;
        color: #4a5568;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        line-height: 1.4;
    }
    .search-result-item:last-child {
        border-bottom: none;
    }
    .search-result-item:hover {
        background: #f8fafc;
        color: #667eea;
    }
    .location-or-divider {
        text-align: center;
        font-size: 12px;
        color: #a0aec0;
        margin-bottom: 12px;
        position: relative;
    }
    .location-or-divider::before, .location-or-divider::after {
        content: "";
        position: absolute;
        top: 50%;
        width: 40%;
        height: 1px;
        background: #e2e8f0;
    }
    .location-or-divider::before { left: 0; }
    .location-or-divider::after { right: 0; }
    
    /* ── Leaflet Popup Customization ── */
    .radius-label {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 10px;
    }

    .radius-label span:first-child {
        font-size: .82rem;
        font-weight: 600;
        color: #444;
    }

    /* Badge hiển thị giá trị km */
    #radius-badge {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: #fff;
        font-size: .78rem;
        font-weight: 700;
        padding: 2px 10px;
        border-radius: 20px;
        min-width: 52px;
        text-align: center;
        transition: background .3s;
    }

    /* ── Range Slider tùy chỉnh ── */
    #radius-slider {
        -webkit-appearance: none;
        width: 100%;
        height: 6px;
        border-radius: 3px;
        background: linear-gradient(to right, #667eea 0%, #667eea var(--pct, 22%), #dde1e8 var(--pct, 22%));
        outline: none;
        cursor: pointer;
        transition: background .2s;
    }

    #radius-slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 20px; height: 20px;
        border-radius: 50%;
        background: #fff;
        border: 3px solid #667eea;
        box-shadow: 0 2px 6px rgba(102,126,234,.4);
        cursor: pointer;
        transition: transform .15s;
    }

    #radius-slider::-webkit-slider-thumb:hover { transform: scale(1.15); }

    .radius-ticks {
        display: flex;
        justify-content: space-between;
        font-size: .68rem;
        color: #aaa;
        margin-top: 4px;
        padding: 0 2px;
    }

    /* ── Thông tin vị trí hiện tại ── */
    #location-info {
        background: #f8f9ff;
        border: 1px solid #e6e9ff;
        border-radius: 10px;
        padding: 12px 14px;
        font-size: .8rem;
        color: #555;
        display: none; /* hiện sau khi định vị */
    }

    #location-info .info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 4px;
    }

    #location-info .info-row:last-child { margin-bottom: 0; }

    #location-info .info-val {
        font-weight: 600;
        color: #333;
    }

    /* ── Nút Tìm kiếm ── */
    #btn-search-radius {
        width: 100%;
        margin-top: 16px;
        padding: 11px;
        border-radius: 10px;
        border: 2px solid #667eea;
        background: transparent;
        color: #667eea;
        font-weight: 700;
        font-size: .88rem;
        cursor: pointer;
        transition: all .2s;
        display: none;
    }
    #btn-search-radius:hover  { background: #667eea; color: #fff; }
    #btn-search-radius.loading { opacity: .6; cursor: not-allowed; }

    /* ── Bộ đếm kết quả ── */
    #result-counter {
        margin-top: 14px;
        padding: 8px 12px;
        border-radius: 8px;
        background: #f0f4ff;
        font-size: .78rem;
        color: #555;
        text-align: center;
        display: none;
    }
    #result-counter strong { color: #667eea; }

    /* Custom jQuery UI slider in Map */
    #slider-range-price .ui-slider-range, #slider-range-area .ui-slider-range {
        background: linear-gradient(to right, #667eea, #764ba2);
    }
    #slider-range-price .ui-slider-handle, #slider-range-area .ui-slider-handle {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #fff;
        border: 2px solid #667eea;
        top: -6px;
        cursor: pointer;
        outline: none;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    /* ═══════════════════════════════════════════════════════════
       BƯỚC 4 — Loading overlay & Popup styles
    ═══════════════════════════════════════════════════════════ */

    /* ── Loading overlay phủ lên bản đồ ── */
    #map-loading-overlay {
        position: absolute;
        inset: 0;                         /* phủ toàn bộ #map */
        background: rgba(255,255,255,.55);
        backdrop-filter: blur(2px);       /* làm mờ bản đồ phía sau */
        z-index: 500;                     /* trên tile nhưng dưới popup */
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        pointer-events: none;             /* không block click khi ẩn */
        transition: opacity .25s ease;
    }
    #map-loading-overlay.active {
        opacity: 1;
        pointer-events: all;
    }

    /* Vòng xoay loading trung tâm */
    .map-spinner {
        width: 48px; height: 48px;
        border: 4px solid rgba(102,126,234,.25);
        border-top-color: #667eea;
        border-radius: 50%;
        animation: spin .75s linear infinite;
    }

    /* ── Popup phòng trọ ── */
    .room-popup {
        width: 220px;
        font-family: inherit;
    }
    .room-popup .popup-img {
        width: 100%;
        height: 120px;
        object-fit: cover;
        border-radius: 8px 8px 0 0;
        display: block;
        background: #eee;
    }
    .room-popup .popup-img-placeholder {
        width: 100%;
        height: 120px;
        background: linear-gradient(135deg,#e8eaf6,#c5cae9);
        border-radius: 8px 8px 0 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
    }
    .room-popup .popup-body {
        padding: 10px 12px 12px;
    }
    .room-popup .popup-name {
        font-size: .85rem;
        font-weight: 700;
        color: #222;
        margin: 0 0 6px;
        line-height: 1.3;
        /* cắt tên dài */
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .room-popup .popup-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    .room-popup .popup-price {
        color: #667eea;
        font-weight: 700;
        font-size: .82rem;
    }
    .room-popup .popup-dist {
        font-size: .72rem;
        color: #888;
        background: #f4f5ff;
        padding: 2px 7px;
        border-radius: 10px;
    }
    .room-popup .popup-actions {
        display: flex;
        gap: 6px;
    }
    .room-popup .btn-detail {
        flex: 1;
        padding: 6px;
        border-radius: 7px;
        background: linear-gradient(135deg,#667eea,#764ba2);
        color: #fff;
        font-size: .75rem;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: opacity .2s;
    }
    .room-popup .btn-detail:hover { opacity: .85; color: #fff; }
    .room-popup .btn-directions {
        flex: 1;
        padding: 6px;
        border-radius: 7px;
        border: 1.5px solid #667eea;
        background: transparent;
        color: #667eea;
        font-size: .75rem;
        font-weight: 600;
        text-align: center;
        cursor: pointer;
        transition: all .2s;
        white-space: nowrap;
    }
    .room-popup .btn-directions:hover {
        background: #667eea;
        color: #fff;
    }

    /* ── Reset Leaflet Popup for infoBox ── */
    .custom-infobox-popup .leaflet-popup-content-wrapper {
        padding: 0;
        overflow: hidden;
        border-radius: 8px;
    }
    .custom-infobox-popup .leaflet-popup-content {
        margin: 0;
        width: 260px !important;
    }
    .custom-infobox-popup .infoBox {
        margin: 0;
        box-shadow: none;
    }
    .custom-infobox-popup .leaflet-popup-close-button {
        color: #fff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        z-index: 10;
        top: 5px;
        right: 5px;
    }

    /* ── Custom cluster icon style ── */
    .marker-cluster-custom {
        background: linear-gradient(135deg,#667eea,#764ba2) !important;
        border: 3px solid #fff !important;
        box-shadow: 0 3px 10px rgba(102,126,234,.45) !important;
        border-radius: 50% !important;
    }
    .marker-cluster-custom div {
        color: #fff !important;
        font-weight: 700 !important;
        font-size: .8rem !important;
    }

    /* ══════════════════════════════════════════════════════════════
       BƯỚC 5 — Leaflet Routing Machine CSS overrides
    ══════════════════════════════════════════════════════════════ */

    /* [5-A] ẨN HOÀN TOÀN bảng hướng dẫn turn-by-turn mặc định của LRM
       ("Turn left", "Turn right"...) — gây xấu giao diện */
    .leaflet-routing-container {
        display: none !important;
    }

    /* [5-B] Box tóm tắt tuyến đường (tự xây dựng trong panel) */
    #route-summary {
        display: none;                        /* ẩn mặc định */
        margin-top: 14px;
        border-radius: 12px;
        overflow: hidden;
        border: 1.5px solid #e0e4ff;
        box-shadow: 0 2px 10px rgba(102,126,234,.12);
        animation: slideDown .25s ease;
    }

    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* Header gradient của box tóm tắt */
    .route-summary-header {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: #fff;
        padding: 8px 14px;
        font-size: .75rem;
        font-weight: 700;
        letter-spacing: .3px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Hai ô số liệu Khoảng cách / Thời gian */
    .route-summary-stats {
        background: #fff;
        display: flex;
        gap: 0;
    }

    .route-stat {
        flex: 1;
        text-align: center;
        padding: 10px 8px;
        border-right: 1px solid #f0f2ff;
    }
    .route-stat:last-child { border-right: none; }

    .route-stat .stat-value {
        display: block;
        font-size: 1.05rem;
        font-weight: 800;
        color: #667eea;
        line-height: 1;
        margin-bottom: 3px;
    }

    .route-stat .stat-label {
        font-size: .68rem;
        color: #999;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    /* Nút Xóa tuyến đường */
    #btn-clear-route {
        display: block;
        width: 100%;
        padding: 7px;
        background: #fff8f8;
        border: none;
        border-top: 1px solid #ffe0e0;
        color: #e53e3e;
        font-size: .75rem;
        font-weight: 600;
        cursor: pointer;
        transition: background .2s;
        text-align: center;
    }
    #btn-clear-route:hover { background: #fff0f0; }

    /* [5-C] Line style cho tuyến đường — màu tím gradient */
    .leaflet-interactive.route-line-shadow {
        stroke: rgba(102,126,234,.25);
        stroke-width: 9;
    }

    /* ── Khung bản đồ ── */
    #map {
        flex: 1;
        height: 100%;
        z-index: 1;
    }

    /* ── Responsive mobile ── */
    @media (max-width: 768px) {
        .map-page-wrapper {
            flex-direction: column;
            height: auto;
        }

        .map-control-panel {
            width: 100%;
            border-right: none;
            border-bottom: 1px solid #e8ecf0;
        }

        #map {
            height: 55vh;
        }
    }
</style>
@endsection

{{-- ── HTML ────────────────────────────────────────────────── --}}
@section('content')
<div class="map-page-wrapper">

    {{-- ── Panel điều khiển ─────────────────────────────────── --}}
    <aside class="map-control-panel">

        <div class="panel-header">
            <h5>🗺️ Bản đồ tìm phòng</h5>
            <p>Tìm kiếm phòng trọ xung quanh vị trí của bạn</p>
        </div>

        <div class="panel-body">

            {{-- Form Tìm kiếm địa điểm --}}
            <div class="search-location-wrapper">
                <input type="text" id="input-search-location" placeholder="Tìm tên đường, khu vực..." autocomplete="off">
                <button type="button" id="btn-search-location" aria-label="Tìm kiếm địa điểm">🔍</button>
                <div id="search-location-results" class="search-results-dropdown"></div>
            </div>
            
            <div class="location-or-divider">hoặc</div>

            {{-- Nút Định vị --}}
            <button id="btn-locate" type="button" aria-label="Định vị vị trí của tôi">
                {{-- Icon định vị (hiện mặc định) --}}
                <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                     fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 0a.5.5 0 0 1 .5.5V2a6 6 0 0 1 5.5 5.5h1.5a.5.5 0 0 1 0 1H14A6 6 0 0 1 8.5 14v1.5a.5.5 0 0 1-1 0V14A6 6 0 0 1 2 8.5H.5a.5.5 0 0 1 0-1H2A6 6 0 0 1 7.5 2V.5A.5.5 0 0 1 8 0zm0 3a5 5 0 1 0 0 10A5 5 0 0 0 8 3zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5z"/>
                </svg>
                {{-- Spinner loading (ẩn mặc định) --}}
                <div class="spinner"></div>
                <span>Định vị tôi bằng GPS</span>
            </button>

            {{-- Thông tin vị trí (ẩn cho đến khi định vị thành công) --}}
            <div id="location-info" role="status" aria-live="polite">
                <div class="info-row" id="info-address-row" style="display:none; flex-direction:column; align-items:flex-start;">
                    <span style="font-weight:600; color:#2d3748; margin-bottom:4px;">📍 Địa điểm</span>
                    <span class="info-val" id="info-address" style="text-align:left; line-height:1.4;">—</span>
                </div>
                <div class="info-row">
                    <span>Vĩ độ</span>
                    <span class="info-val" id="info-lat">—</span>
                </div>
                <div class="info-row">
                    <span>Kinh độ</span>
                    <span class="info-val" id="info-lng">—</span>
                </div>
            </div>

            <hr class="section-divider">

            {{-- Range Slider bán kính --}}
            <div class="radius-label">
                <span>📏 Bán kính tìm kiếm</span>
                <span id="radius-badge">3 km</span>
            </div>

            {{-- min=1, max=10, step=0.5, default=3 --}}
            <input
                type="range"
                id="radius-slider"
                min="1"
                max="50"
                step="0.5"
                value="3"
                aria-label="Bán kính tìm kiếm tính bằng km"
            >

            {{-- Tick marks phụ --}}
            <div class="radius-ticks">
                <span>1km</span>
                <span>5km</span>
                <span>10km</span>
                <span>20km</span>
                <span>50km</span>
            </div>

            <hr class="section-divider">

            {{-- ── Bộ lọc nâng cao (Bổ sung) ── --}}
            <div class="advanced-filter-section" style="margin-bottom: 15px;">
                <div style="font-weight:600; font-size:.85rem; margin-bottom:12px; color:#444;">⚙️ Bộ lọc nâng cao</div>
                
                {{-- Lọc Giá --}}
                <div class="filter-group" style="margin-bottom: 18px;">
                    <div class="radius-label" style="margin-bottom: 5px;">
                        <span>Giá phòng</span>
                        <input type="text" id="amount-price" readonly style="border:0; color:#667eea; font-weight:700; text-align:right; background:transparent; width:140px; font-size:.78rem;">
                    </div>
                    <div id="slider-range-price" style="height:6px; border:none; background:#dde1e8; border-radius:3px; margin-top:8px;"></div>
                    <input type="hidden" id="price_from" value="500000">
                    <input type="hidden" id="price_to" value="10000000">
                </div>

                {{-- Lọc Diện tích --}}
                <div class="filter-group" style="margin-bottom: 15px;">
                    <div class="radius-label" style="margin-bottom: 5px;">
                        <span>Diện tích</span>
                        <input type="text" id="amount-area" readonly style="border:0; color:#667eea; font-weight:700; text-align:right; background:transparent; width:100px; font-size:.78rem;">
                    </div>
                    <div id="slider-range-area" style="height:6px; border:none; background:#dde1e8; border-radius:3px; margin-top:8px;"></div>
                    <input type="hidden" id="area_from" value="10">
                    <input type="hidden" id="area_to" value="100">
                </div>
                {{-- Lọc Tiện ích --}}
                <div class="filter-group" style="margin-bottom: 5px;">
                    <div class="radius-label" style="margin-bottom: 8px;">
                        <span>Tiện ích cơ bản</span>
                    </div>
                    <div class="d-flex flex-wrap" style="gap: 10px; font-size: .8rem;">
                        <label style="display:flex; align-items:center; gap:4px; cursor:pointer; color:#555;">
                            <input type="checkbox" name="add_ons[]" class="map-addon-checkbox" value="Nơi để xe"> Nơi để xe
                        </label>
                        <label style="display:flex; align-items:center; gap:4px; cursor:pointer; color:#555;">
                            <input type="checkbox" name="add_ons[]" class="map-addon-checkbox" value="Camera an ninh"> Camera an ninh
                        </label>
                        <label style="display:flex; align-items:center; gap:4px; cursor:pointer; color:#555;">
                            <input type="checkbox" name="add_ons[]" class="map-addon-checkbox" value="Wifi miễn phí"> Wifi
                        </label>
                        <label style="display:flex; align-items:center; gap:4px; cursor:pointer; color:#555;">
                            <input type="checkbox" name="add_ons[]" class="map-addon-checkbox" value="Điều hòa"> Điều hòa
                        </label>
                    </div>
                </div>
            </div>

            {{-- Nút tìm kiếm --}}
            <button id="btn-search-radius" type="button">
                🔍 Tìm phòng trong bán kính này
            </button>

            {{-- Bộ đếm kết quả (ẩn cho đến khi có kết quả) --}}
            <div id="result-counter" role="status" aria-live="polite"></div>

            {{-- ── BƯỚC 5: Box tóm tắt tuyến đường ─────────────── --}}
            <div id="route-summary" role="status" aria-live="polite">
                <div class="route-summary-header">
                    <span>🗺️</span> Tuyến đường
                </div>
                <div class="route-summary-stats">
                    <div class="route-stat">
                        <span class="stat-value" id="route-distance">—</span>
                        <span class="stat-label">Khoảng cách</span>
                    </div>
                    <div class="route-stat">
                        <span class="stat-value" id="route-time">—</span>
                        <span class="stat-label">Thời gian</span>
                    </div>
                </div>
                <button id="btn-clear-route" type="button" aria-label="Xóa tuyến đường">
                    ✕ Xóa tuyến đường
                </button>
            </div>

        </div>{{-- /panel-body --}}
    </aside>

    {{-- ── Khung bản đồ Leaflet ─────────────────────────────── --}}
    {{-- position:relative để loading overlay định vị tuyệt đối bên trong --}}
    <div style="position:relative; flex:1; height:100%;">
        <div id="map" role="main" aria-label="Bản đồ tìm kiếm phòng trọ" style="width:100%;height:100%;"></div>

        {{-- Loading overlay (BƯỚC 4) --}}
        <div id="map-loading-overlay" aria-hidden="true">
            <div class="map-spinner"></div>
        </div>
    </div>

</div>{{-- /map-page-wrapper --}}
@endsection

{{-- ── JavaScript ─────────────────────────────────────────── --}}
@section('js')
<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- Leaflet MarkerCluster JS -->
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<!-- Leaflet Routing Machine JS (Bước 5) -->
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>

<script>
/* ═══════════════════════════════════════════════════════════════
   BƯỚC 3 — Khởi tạo bản đồ & Geolocation
═══════════════════════════════════════════════════════════════ */

// Khởi tạo thanh trượt jQuery UI cho Bộ lọc nâng cao
$(function() {
    // Giá phòng
    $("#slider-range-price").slider({
        range: true,
        min: 100,
        max: 30000, // 30 triệu
        values: [ 500, 10000 ], // Mặc định 500k - 10tr
        slide: function( event, ui ) {
            let html = (ui.values[0] * 1000).toLocaleString('it-IT') + "đ - " + (ui.values[1] * 1000).toLocaleString('it-IT') + "đ";
            $("#amount-price").val(html);
            $("#price_from").val(ui.values[0] * 1000);
            $("#price_to").val(ui.values[1] * 1000);
        }
    });
    // Set initial value for UI
    let initPrice = ($("#slider-range-price").slider("values", 0) * 1000).toLocaleString('it-IT') + "đ - " + ($("#slider-range-price").slider("values", 1) * 1000).toLocaleString('it-IT') + "đ";
    $("#amount-price").val(initPrice);

    // Diện tích
    $("#slider-range-area").slider({
        range: true,
        min: 0,
        max: 200,
        values: [ 10, 100 ], // Mặc định 10m2 - 100m2
        slide: function( event, ui ) {
            $("#amount-area").val( ui.values[0] + "m² - " + ui.values[1] + "m²" );
            $("#area_from").val(ui.values[0]);
            $("#area_to").val(ui.values[1]);
        }
    });
    // Set initial value for UI
    $("#amount-area").val( $("#slider-range-area").slider("values", 0) + "m² - " + $("#slider-range-area").slider("values", 1) + "m²" );
});

/* ── [0] Tính chiều cao header thực tế, gán vào CSS variable ───
   Header dùng class fixed-header với logo max-height:100px.
   Đo offsetHeight thực sau khi layout render xong.
─────────────────────────────────────────────────────────────── */
(function setHeaderHeight() {
    const header = document.querySelector('header');
    if (!header) return;
    const h = header.offsetHeight;
    if (h > 0) {
        document.documentElement.style.setProperty('--header-h', h + 'px');
    }
    // Đánh dấu body để CSS ẩn footer
    document.body.classList.add('map-page');
})();


/* ── [1] Khởi tạo bản đồ Leaflet ─────────────────────────────
   Tọa độ mặc định: trung tâm Hà Nội (21.0285, 105.8542)
   Zoom 13 = mức thành phố, đủ thấy bán kính 3–5km
─────────────────────────────────────────────────────────────── */
const DEFAULT_LAT  = 21.0285;
const DEFAULT_LNG  = 105.8542;
const DEFAULT_ZOOM = 13;

const map = L.map('map', {
    center: [DEFAULT_LAT, DEFAULT_LNG],
    zoom:   DEFAULT_ZOOM,
    zoomAnimation: true,
});

/* ── Tile layer: OpenStreetMap (miễn phí, không cần API key) ── */
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19,
}).addTo(map);

/* ── invalidateSize: bắt buộc gọi sau khi container có size thực
   Lý do: @yield('js') giờ chạy sau tất cả template script;
   layout có thể đã vẽ xong nhưng Leaflet cần được thông báo
   lại kích thước container để load đúng tiles.
─────────────────────────────────────────────────────────────── */
window.addEventListener('load', function () {
    /* Đo lại header sau khi mọi asset đã load (fonts, images) */
    const header = document.querySelector('header');
    if (header && header.offsetHeight > 0) {
        document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px');
    }
    /* Thông báo lại kích thước map để tiles load đúng */
    setTimeout(function () { map.invalidateSize(); }, 150);
});

/* Gọi lại khi resize cửa sổ (vd: mobile xoay màn hình) */
window.addEventListener('resize', function () {
    const header = document.querySelector('header');
    if (header && header.offsetHeight > 0) {
        document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px');
    }
    map.invalidateSize();
});


/* ── [2] Biến trạng thái toàn cục ───────────────────────────── */
let userLat    = null;   // Vĩ độ người dùng
let userLng    = null;   // Kinh độ người dùng
let userMarker = null;   // Marker vị trí người dùng (L.marker)
let radiusCircle = null; // Vòng tròn bán kính (L.circle)

/* Đọc giá trị slider ban đầu */
const radiusSlider = document.getElementById('radius-slider');
let currentRadiusKm = parseFloat(radiusSlider.value); // 3 km mặc định


/* ── [3] Cập nhật gradient thanh slider theo giá trị ────────── */
function updateSliderTrack() {
    const min = parseFloat(radiusSlider.min);   // 1
    const max = parseFloat(radiusSlider.max);   // 10
    const val = parseFloat(radiusSlider.value);
    const pct = ((val - min) / (max - min) * 100).toFixed(1) + '%';
    // CSS custom property để tô màu phần đã kéo
    radiusSlider.style.setProperty('--pct', pct);
}
updateSliderTrack(); // chạy ngay khi load


/* ── [4] Hàm vẽ / cập nhật vòng tròn bán kính ──────────────── */
function drawRadiusCircle(lat, lng, radiusKm) {
    const radiusMeters = radiusKm * 1000; // Leaflet dùng mét

    if (radiusCircle) {
        // Nếu vòng tròn đã tồn tại → chỉ cập nhật vị trí & bán kính
        radiusCircle.setLatLng([lat, lng]);
        radiusCircle.setRadius(radiusMeters);
    } else {
        // Tạo mới vòng tròn mờ bao quanh vị trí người dùng
        radiusCircle = L.circle([lat, lng], {
            radius:      radiusMeters,
            color:       '#667eea',        // màu viền
            weight:      2,                // độ dày viền (px)
            opacity:     0.8,              // độ mờ viền
            fillColor:   '#667eea',        // màu nền
            fillOpacity: 0.10,             // nền rất mờ để thấy bản đồ
            dashArray:   '6, 4',           // viền nét đứt
        }).addTo(map);
    }

    // Căn chỉnh viewport bản đồ vừa khít vòng tròn (thêm padding 20px)
    map.fitBounds(radiusCircle.getBounds(), { padding: [20, 20] });
}


/* ── [5] Hàm vẽ marker vị trí người dùng ──────────────────── */
function drawUserMarker(lat, lng) {
    // Icon tùy chỉnh hình giọt nước màu tím
    const userIcon = L.divIcon({
        className: '',
        html: `<div style="
            width:28px; height:28px;
            background:linear-gradient(135deg,#667eea,#764ba2);
            border:3px solid #fff;
            border-radius:50% 50% 50% 0;
            transform:rotate(-45deg);
            box-shadow:0 3px 10px rgba(102,126,234,.5);
        "></div>`,
        iconSize:   [28, 28],
        iconAnchor: [14, 28],  // điểm neo ở đáy icon
        popupAnchor:[0, -30],
    });

    if (userMarker) {
        // Đã có marker → chỉ cập nhật vị trí
        userMarker.setLatLng([lat, lng]);
    } else {
        userMarker = L.marker([lat, lng], { icon: userIcon }).addTo(map);
    }
    userMarker.bindPopup('<strong>📍 Vị trí tâm tìm kiếm</strong>', { offset: [0, -10] });
}

/* ── [5.1] Hàm cập nhật tâm tìm kiếm chung ────────────────── */
function updateLocationCenter(lat, lng, addressName = null) {
    userLat = lat;
    userLng = lng;

    document.getElementById('info-lat').textContent = userLat.toFixed(6);
    document.getElementById('info-lng').textContent = userLng.toFixed(6);
    
    const addressRow = document.getElementById('info-address-row');
    const addressEl = document.getElementById('info-address');
    if (addressName) {
        addressEl.textContent = addressName;
        addressRow.style.display = 'flex';
    } else {
        addressRow.style.display = 'none';
    }

    document.getElementById('location-info').style.display = 'block';
    document.getElementById('btn-search-radius').style.display = 'block';

    drawUserMarker(userLat, userLng);
    drawRadiusCircle(userLat, userLng, currentRadiusKm);
    userMarker.openPopup();
    map.setView([userLat, userLng], 14, { animate: true });

    if (typeof clearRoute === 'function') clearRoute();
    if (typeof debouncedFetch === 'function') debouncedFetch();
    else if (typeof fetchAndRenderMap === 'function') fetchAndRenderMap();
}

/* ── [5.2] Lắng nghe sự kiện Click trên bản đồ ───────────── */
map.on('click', async function(e) {
    const lat = e.latlng.lat;
    const lng = e.latlng.lng;
    
    // Gọi API Reverse Geocoding để lấy tên địa điểm (tuỳ chọn)
    let addressName = 'Vị trí đã chọn trên bản đồ';
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
        if (res.ok) {
            const data = await res.json();
            if (data && data.display_name) {
                // Rút gọn địa chỉ (bỏ quốc gia)
                const parts = data.display_name.split(', ');
                if (parts.length > 2) parts.pop();
                addressName = parts.join(', ');
            }
        }
    } catch (err) {
        console.log('Reverse geocoding failed', err);
    }

    updateLocationCenter(lat, lng, addressName);
});

/* ── [5.3] Tìm kiếm địa điểm bằng API Nominatim ──────────── */
const inputSearchLoc = document.getElementById('input-search-location');
const btnSearchLoc = document.getElementById('btn-search-location');
const resultsDropdown = document.getElementById('search-location-results');

async function performLocationSearch() {
    const query = inputSearchLoc.value.trim();
    if (!query) {
        resultsDropdown.classList.remove('active');
        return;
    }
    btnSearchLoc.textContent = '⏳';
    
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query + ', Việt Nam')}&limit=5`);
        const data = await res.json();
        
        resultsDropdown.innerHTML = '';
        if (data && data.length > 0) {
            data.forEach(place => {
                const item = document.createElement('div');
                item.className = 'search-result-item';
                // Rút gọn tên địa điểm hiển thị
                const shortName = place.display_name.split(', ').slice(0, -1).join(', ');
                item.textContent = shortName || place.display_name;
                
                item.addEventListener('click', () => {
                    inputSearchLoc.value = item.textContent;
                    resultsDropdown.classList.remove('active');
                    updateLocationCenter(parseFloat(place.lat), parseFloat(place.lon), item.textContent);
                });
                resultsDropdown.appendChild(item);
            });
            resultsDropdown.classList.add('active');
        } else {
            const empty = document.createElement('div');
            empty.className = 'search-result-item';
            empty.textContent = 'Không tìm thấy địa điểm này.';
            resultsDropdown.appendChild(empty);
            resultsDropdown.classList.add('active');
        }
    } catch (err) {
        console.error('Lỗi tìm kiếm:', err);
    } finally {
        btnSearchLoc.textContent = '🔍';
    }
}

btnSearchLoc.addEventListener('click', performLocationSearch);
inputSearchLoc.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') performLocationSearch();
});
// Ẩn dropdown khi click ra ngoài
document.addEventListener('click', function(e) {
    if (!e.target.closest('.search-location-wrapper')) {
        resultsDropdown.classList.remove('active');
    }
});


/* ── [6] Xử lý sự kiện nút "Định vị tôi" ──────────────────── */
document.getElementById('btn-locate').addEventListener('click', function () {
    const btn = this;

    /* Kiểm tra trình duyệt có hỗ trợ Geolocation không */
    if (!navigator.geolocation) {
        Swal.fire({
            icon: 'error',
            title: 'Không hỗ trợ',
            text: 'Trình duyệt của bạn không hỗ trợ định vị. Hãy thử trên Chrome hoặc Firefox.',
            confirmButtonColor: '#667eea',
        });
        return;
    }

    /* Hiện trạng thái loading */
    btn.classList.add('loading');
    btn.querySelector('span').textContent = 'Đang định vị...';

    /* Gọi Geolocation API */
    navigator.geolocation.getCurrentPosition(
        /* ── Success callback ── */
        function (position) {
            userLat = position.coords.latitude;
            userLng = position.coords.longitude;
            const accuracy = Math.round(position.coords.accuracy); // mét

            /* Tắt loading */
            btn.classList.remove('loading');
            btn.querySelector('span').textContent = '✓ Đã định vị';

            /* Cập nhật UI thông qua hàm tập trung */
            updateLocationCenter(position.coords.latitude, position.coords.longitude, 'Vị trí GPS của bạn');
        },

        /* ── Error callback ── */
        function (error) {
            btn.classList.remove('loading');
            btn.querySelector('span').textContent = 'Định vị tôi';

            /* Bản đồ lỗi theo từng mã lỗi Geolocation API */
            const messages = {
                1: {  // PERMISSION_DENIED
                    title: 'Bị từ chối quyền truy cập',
                    text:  'Bạn đã chặn quyền truy cập vị trí. Hãy vào Cài đặt trình duyệt → Quyền riêng tư → cho phép Vị trí cho trang này.',
                },
                2: {  // POSITION_UNAVAILABLE
                    title: 'Không xác định được vị trí',
                    text:  'Thiết bị không thể xác định vị trí hiện tại. Hãy kiểm tra GPS hoặc kết nối internet.',
                },
                3: {  // TIMEOUT
                    title: 'Quá thời gian chờ',
                    text:  'Yêu cầu định vị mất quá nhiều thời gian. Hãy thử lại.',
                },
            };

            const msg = messages[error.code] || {
                title: 'Lỗi định vị',
                text:  'Đã xảy ra lỗi không xác định khi lấy vị trí.',
            };

            Swal.fire({
                icon:               'warning',
                title:              msg.title,
                text:               msg.text,
                confirmButtonText:  'Đã hiểu',
                confirmButtonColor: '#667eea',
            });
        },

        /* ── Tùy chọn Geolocation ── */
        {
            enableHighAccuracy: true,  // yêu cầu độ chính xác cao (dùng GPS nếu có)
            timeout:            10000, // tối đa 10 giây
            maximumAge:         0,     // luôn lấy vị trí mới, không cache
        }
    );
});


/* ── [7] Sự kiện kéo Range Slider ─────────────────────────── */
radiusSlider.addEventListener('input', function () {
    currentRadiusKm = parseFloat(this.value);
    document.getElementById('radius-badge').textContent = currentRadiusKm + ' km';
    updateSliderTrack();

    if (userLat !== null && userLng !== null) {
        drawRadiusCircle(userLat, userLng, currentRadiusKm);
        /* Gọi API với debounce khi kéo slider (Bước 4) */
        debouncedFetch();
    }
});


/* ═══════════════════════════════════════════════════════════════
   BƯỚC 4 — fetchAndRenderMap(): AJAX + MarkerCluster + Popup
═══════════════════════════════════════════════════════════════ */

/* ── [A] MarkerCluster group (khởi tạo một lần) ─────────────── */
const clusterGroup = L.markerClusterGroup({
    /* Tùy chỉnh icon cụm để khớp màu theme tím */
    iconCreateFunction(cluster) {
        const count = cluster.getChildCount();
        return L.divIcon({
            html: `<div><span>${count}</span></div>`,
            className: 'marker-cluster marker-cluster-custom',
            iconSize: L.point(40, 40),
        });
    },
    maxClusterRadius: 60,      // px — hai marker cách nhau < 60px thì gom
    spiderfyOnMaxZoom: true,   // zoom max → tách cụm ra hình nhện
    showCoverageOnHover: false, // ẩn vùng phủ khi hover cụm
    zoomToBoundsOnClick: true, // click cụm → zoom vào
});
map.addLayer(clusterGroup);


/* ── [B] Loading overlay helpers ────────────────────────────── */
const loadingOverlay = document.getElementById('map-loading-overlay');
const btnSearch      = document.getElementById('btn-search-radius');

function showLoading() {
    loadingOverlay.classList.add('active');
    loadingOverlay.setAttribute('aria-hidden', 'false');
    btnSearch.classList.add('loading');
    btnSearch.disabled = true;
}

function hideLoading() {
    loadingOverlay.classList.remove('active');
    loadingOverlay.setAttribute('aria-hidden', 'true');
    btnSearch.classList.remove('loading');
    btnSearch.disabled = false;
}


/* ── [C] Tạo HTML Popup cho mỗi phòng trọ ──────────────────── */
function buildPopupHTML(room) {
    const electricStr = room.electric ? Number(room.electric).toLocaleString('vi-VN') + 'đ' : 'Miễn phí';
    const waterStr    = room.water ? Number(room.water).toLocaleString('vi-VN') + 'đ' : 'Miễn phí';
    const priceStr    = Number(room.price).toLocaleString('vi-VN') + 'đ/' + (room.unit || 'tháng');
    const areaStr     = room.area ? room.area : '—';
    const detailUrl   = `/xem-phong/${room.id}`;
    const imgSrc      = room.main_img ? room.main_img : '/assets/images/placeholder.jpg';
    const label       = room.category || 'Phòng trọ';

    return `
    <div class="infoBox">
        <div class="marker-detail">
            <img src="${imgSrc}" alt="${room.name}" style="width:100%; height:160px; object-fit:cover;"/>
            <div class="label label-shadow" style="position:absolute; top:10px; left:10px; background:#ff4c4c; color:#fff; padding:3px 10px; border-radius:4px; font-size:12px; font-weight:bold;">${label}</div>
            <div class="detail-part" style="padding:15px;">
                <h6 style="font-size:15px; margin-bottom:10px; font-weight:600; color:#2d3748;">${room.name}</h6>
                <ul style="list-style:none; padding:0; margin:0 0 12px 0; font-size:13px; color:#4a5568;">
                    <li style="margin-bottom:4px;">⚡ Điện : ${electricStr}</li>
                    <li style="margin-bottom:4px;">💧 Nước : ${waterStr}</li>
                    <li style="margin-bottom:4px;">📐 Diện tích : ${areaStr} m2</li>
                    <li style="margin-bottom:4px;">📍 Cách bạn : ${room.distance_km.toFixed(2)} km</li>
                </ul>
                <span style="display:block; font-size:16px; font-weight:bold; color:#e53e3e; margin-bottom:12px;">${priceStr}</span>
                <div style="display:flex; gap:8px;">
                    <a href="${detailUrl}" target="_blank" class="btn btn-sm btn-outline-primary" style="flex:1; text-align:center; padding:6px 0; font-size:13px; border-radius:4px;">Chi tiết</a>
                    <a href="javascript:void(0)" onclick="drawRoute(${room.latitude}, ${room.longitude})" class="btn btn-sm" style="flex:1; text-align:center; padding:6px 0; font-size:13px; border-radius:4px; background:linear-gradient(135deg, #667eea, #764ba2); color:white;">Chỉ đường</a>
                </div>
            </div>
        </div>
    </div>`;
}


/* ═══════════════════════════════════════════════════════════════
   BƯỚC 5 — Leaflet Routing Machine: drawRoute()
═══════════════════════════════════════════════════════════════ */

/* ── [D-1] Biến lưu route control hiện tại ───────────────────── */
let routeControl = null; // L.Routing.Control đang hoạt động (null = chưa có)


/* ── [D-2] Hàm cập nhật box tóm tắt trong panel ─────────────── */
function updateRouteSummary(distanceKm, timeMin) {
    /* Định dạng khoảng cách: "2.3 km" hoặc "850 m" */
    const distStr = distanceKm >= 1
        ? distanceKm.toFixed(1) + ' km'
        : Math.round(distanceKm * 1000) + ' m';

    /* Định dạng thời gian: "45 phút" hoặc "1 giờ 20 phút" */
    let timeStr;
    if (timeMin < 60) {
        timeStr = timeMin + ' phút';
    } else {
        const h = Math.floor(timeMin / 60);
        const m = timeMin % 60;
        timeStr = h + ' giờ' + (m > 0 ? ' ' + m + ' phút' : '');
    }

    document.getElementById('route-distance').textContent = distStr;
    document.getElementById('route-time').textContent     = timeStr;
    document.getElementById('route-summary').style.display = 'block';
}


/* ── [D-3] Hàm xóa tuyến đường ──────────────────────────────── */
function clearRoute() {
    if (routeControl !== null) {
        map.removeControl(routeControl); // xóa khỏi bản đồ
        routeControl = null;
    }
    /* Ẩn box tóm tắt */
    document.getElementById('route-summary').style.display = 'none';
    document.getElementById('route-distance').textContent  = '—';
    document.getElementById('route-time').textContent      = '—';
}

/* Gán nút "Xóa tuyến đường" trong panel */
document.getElementById('btn-clear-route').addEventListener('click', clearRoute);


/* ── [D-4] Hàm chính: drawRoute(endLat, endLng) ─────────────────
   Điểm A: vị trí người dùng (userLat, userLng)
   Điểm B: tọa độ phòng trọ  (endLat, endLng)
──────────────────────────────────────────────────────────────── */
function drawRoute(endLat, endLng) {

    /* Guard: phải định vị trước */
    if (userLat === null || userLng === null) {
        Swal.fire({
            icon              : 'warning',
            title             : 'Chưa định vị',
            text              : 'Hãy bấm "Định vị tôi" để xác định vị trí của bạn trước khi dùng tính năng Chỉ đường.',
            confirmButtonText : 'Đã hiểu',
            confirmButtonColor: '#667eea',
        });
        return;
    }

    /* [1] Xóa tuyến đường CŨ trước khi vẽ mới */
    clearRoute();

    /* [2] Đóng popup đang mở (nếu có) để map thoáng hơn */
    map.closePopup();

    /* [3] Hiện loading overlay trong khi OSRM tính toán */
    showLoading();

    /* [4] Tạo routing control mới ─────────────────────────────────
       - router: OSRM public (miễn phí, không cần API key)
       - show: false   → TẮT bảng turn-by-turn mặc định của LRM
       - addWaypoints / draggableWaypoints: false → không cho kéo
       - createMarker: null → không tạo marker A/B mặc định của LRM
         (marker user và marker phòng đã có sẵn từ Bước 3/4)
    ──────────────────────────────────────────────────────────────── */
    routeControl = L.Routing.control({
        waypoints: [
            L.latLng(userLat, userLng), // Điểm A: vị trí người dùng
            L.latLng(endLat,  endLng),  // Điểm B: phòng trọ
        ],

        /* Router: OSRM public endpoint, profile driving (ô tô) */
        router: L.Routing.osrmv1({
            serviceUrl : 'https://router.project-osrm.org/route/v1',
            profile    : 'driving',
        }),

        /* ── Tắt hoàn toàn UI mặc định của LRM ── */
        show               : false,   // ẩn container turn-by-turn
        collapsible        : false,
        addWaypoints       : false,   // không thêm waypoint bằng click
        draggableWaypoints : false,   // không kéo waypoint
        fitSelectedRoutes  : true,    // auto zoom vừa khít tuyến đường

        /* ── KHÔNG tạo marker mặc định của LRM ── */
        createMarker: function() { return null; },

        /* ── Style đường vẽ: màu tím chủ đạo, có viền bóng mờ ── */
        lineOptions: {
            styles: [
                /* Lớp bóng mờ phía dưới (tạo hiệu ứng chiều sâu) */
                { color: '#667eea', opacity: 0.15, weight: 12 },
                /* Lớp chính màu tím */
                { color: '#667eea', opacity: 0.9,  weight: 5  },
                /* Viền trắng mỏng ở giữa tạo hiệu ứng cao cấp */
                { color: '#ffffff', opacity: 0.4,  weight: 2  },
            ],
            extendToWaypoints  : true,
            missingRouteTolerance: 0,
        },
    });

    /* [5] Thêm control vào map */
    routeControl.addTo(map);

    /* [6] Sự kiện: tính toán xong → ẩn loading, hiện tóm tắt ── */
    routeControl.on('routesfound', function (e) {
        hideLoading();

        const summary     = e.routes[0].summary;
        const distanceKm  = summary.totalDistance / 1000;   // mét → km
        const timeMin     = Math.ceil(summary.totalTime / 60); // giây → phút

        /* Cập nhật box tóm tắt trong panel */
        updateRouteSummary(distanceKm, timeMin);

        /* Cuộn panel xuống để thấy box tóm tắt */
        document.getElementById('route-summary').scrollIntoView({
            behavior: 'smooth', block: 'nearest',
        });
    });

    /* [7] Sự kiện: lỗi tính đường (vd: OSRM không tìm được route) */
    routeControl.on('routingerror', function (e) {
        hideLoading();
        console.error('[drawRoute] routing error:', e.error);
        Swal.fire({
            icon              : 'error',
            title             : 'Không thể tính đường',
            html              : 'OSRM không tìm được tuyến đường lái xe đến vị trí này.<br>Thử <a href="' +
                                `https://www.google.com/maps/dir/${userLat},${userLng}/${endLat},${endLng}` +
                                '" target="_blank" style="color:#667eea">mở Google Maps</a> thay thế.',
            confirmButtonText : 'Đóng',
            confirmButtonColor: '#667eea',
        });
    });
}

/* Expose ra window để onclick trong Popup HTML gọi được */
window.drawRoute = drawRoute;


/* ── [E] Hàm chính: fetchAndRenderMap() ────────────────────────
   Luồng: show loading → gọi API → clear markers → render → hide loading
─────────────────────────────────────────────────────────────── */
async function fetchAndRenderMap() {
    /* Guard: chưa định vị thì không làm gì */
    if (userLat === null || userLng === null) return;

    showLoading();

    /* Xây dựng query params */
    const params = new URLSearchParams({
        user_lat : userLat,
        user_lng : userLng,
        radius   : currentRadiusKm,
        all      : true,            // lấy toàn bộ (không phân trang) để hiện marker
    });

    // Lấy giá trị bộ lọc nâng cao
    if ($('#price_from').val() && $('#price_to').val()) {
        params.append('price[0]', $('#price_from').val());
        params.append('price[1]', $('#price_to').val());
    }
    if ($('#area_from').val() && $('#area_to').val()) {
        params.append('area[0]', $('#area_from').val());
        params.append('area[1]', $('#area_to').val());
    }
    
    // Lấy tiện ích
    const addons = [];
    $('.map-addon-checkbox:checked').each(function() {
        params.append('add_ons[]', $(this).val());
    });

    try {
        /* ── Gọi API ── */
        const res = await fetch(`/api/map/rooms?${params.toString()}`, {
            method : 'GET',
            headers: { 'Accept': 'application/json' },
        });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const json = await res.json();

        if (!json.success) {
            throw new Error(json.message || 'API trả về lỗi');
        }

        const rooms = json.data;   // mảng phòng từ MapController@getRooms
        const total = json.meta.total;

        /* ── Xóa toàn bộ marker cũ trong cluster ── */
        clusterGroup.clearLayers();

        /* ── Cập nhật bộ đếm kết quả ── */
        const counter = document.getElementById('result-counter');
        counter.style.display = 'block';
        counter.innerHTML = total > 0
            ? `Tìm thấy <strong>${total}</strong> phòng trong bán kính <strong>${currentRadiusKm} km</strong>`
            : 'Không tìm thấy phòng nào.';

        /* ── Empty state ── */
        if (total === 0) {
            hideLoading();
            Swal.fire({
                icon             : 'info',
                title            : 'Không tìm thấy phòng',
                html             : `Không có phòng trọ nào trong bán kính <b>${currentRadiusKm} km</b>.<br>Hãy thử mở rộng bán kính tìm kiếm!`,
                confirmButtonText: 'Đồng ý',
                confirmButtonColor: '#667eea',
                showCancelButton : true,
                cancelButtonText : `Mở rộng lên ${Math.min(currentRadiusKm + 5, 50)} km`,
                cancelButtonColor: '#a0aec0',
            }).then(result => {
                /* Nếu user click "Mở rộng" → tự động tăng slider & tìm lại */
                if (result.isDismissed && result.dismiss === Swal.DismissReason.cancel) {
                    const newRadius = Math.min(currentRadiusKm + 5, 50);
                    radiusSlider.value = newRadius;
                    currentRadiusKm    = newRadius;
                    document.getElementById('radius-badge').textContent = newRadius + ' km';
                    updateSliderTrack();
                    drawRadiusCircle(userLat, userLng, newRadius);
                    fetchAndRenderMap(); // gọi lại ngay
                }
            });
            return;
        }

        /* ── Render marker cho từng phòng ── */
        rooms.forEach(room => {
            /* Icon marker hình giọt nước màu cam cho phòng trọ */
            const roomIcon = L.divIcon({
                className: '',
                html: `<div style="
                    width:22px; height:22px;
                    background:linear-gradient(135deg,#f093fb,#f5576c);
                    border:2.5px solid #fff;
                    border-radius:50% 50% 50% 0;
                    transform:rotate(-45deg);
                    box-shadow:0 2px 8px rgba(245,87,108,.45);
                "></div>`,
                iconSize  : [22, 22],
                iconAnchor: [11, 22],
                popupAnchor: [0, -24],
            });

            /* Tạo marker và gắn popup */
            const marker = L.marker([room.latitude, room.longitude], { icon: roomIcon })
                .bindPopup(buildPopupHTML(room), {
                    maxWidth    : 260,
                    minWidth    : 260,
                    className   : 'custom-infobox-popup',
                    closeButton : true,
                });

            /* Thêm vào cluster (không addTo(map) trực tiếp) */
            clusterGroup.addLayer(marker);
        });

    } catch (err) {
        /* ── Lỗi mạng / server ── */
        console.error('[MapSearch] fetch error:', err);
        Swal.fire({
            icon              : 'error',
            title             : 'Lỗi kết nối',
            text              : 'Không thể tải dữ liệu phòng. Vui lòng thử lại sau.',
            confirmButtonColor: '#667eea',
        });
    } finally {
        hideLoading();
    }
}


/* ── [F] Debounce wrapper (500ms) ───────────────────────────── */
function debounce(fn, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}
/* Mỗi lần kéo slider, đợi 500ms ngừng kéo mới gọi API */
const debouncedFetch = debounce(fetchAndRenderMap, 500);


/* ── [G] Gán sự kiện cho nút "Tìm phòng" ───────────────────── */
document.getElementById('btn-search-radius').addEventListener('click', function () {
    fetchAndRenderMap();
});


/* ── [H] Tự động tìm kiếm ngay sau khi định vị thành công ─────
   Override phần gọi fetchAndRenderMap sau khi có tọa độ
   (Bước 3 đã vẽ marker & circle, bây giờ thêm fetch luôn)
─────────────────────────────────────────────────────────────── */
/* Hook vào sự kiện sau khi marker vị trí được vẽ lần đầu */
const _origDrawUserMarker = drawUserMarker;
drawUserMarker = function(lat, lng) {
    _origDrawUserMarker(lat, lng);
    /* Lần đầu định vị → tự động tìm phòng luôn */
    if (clusterGroup.getLayers().length === 0) {
        fetchAndRenderMap();
    }
};


/* ── [I] Expose window.mapState (đầy đủ cả Bước 3, 4, 5) ──────── */
window.mapState = {
    get userLat()         { return userLat; },
    get userLng()         { return userLng; },
    get currentRadiusKm() { return currentRadiusKm; },
    map,
    clusterGroup,
    fetchAndRenderMap,
    drawRoute,
    clearRoute,
};
</script>
@endsection
