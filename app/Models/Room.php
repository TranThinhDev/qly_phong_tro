<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;
    protected $table = 'rooms';
    protected $primaryKey = 'id';
    protected $fillable = [
        'id',
        'name',
        'status',
        'chutro_id',
        'category_id',
        'main_img',
        'list_img',
        'video_link',
        'price',
        'electric',
        'water',
        'area',
        'unit',
        'describe_room',
        'quantity',
        'add_ons',
        'latlng',
        'ward_id',
        'hold_until',
        'is_deposit_required',
        'deposit_amount',
        // --- Tọa độ riêng biệt (dùng cho tìm kiếm theo bán kính) ---
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'hold_until'          => 'datetime',
        'is_deposit_required' => 'boolean',
        // KHÔNG cast latlng → 'array' ở đây vì nhiều Blade view cũ
        // vẫn dùng json_decode($room->latlng) thủ công.
        // latitude/longitude (decimal riêng) mới là nguồn tọa độ chính xác.
        'latitude'            => 'decimal:8',
        'longitude'           => 'decimal:8',
    ];
    public function CommentRoom()
    {
        return $this->hasMany(CommentRoom::class, 'rooms_id', 'id');
    }
    public function getBooking()
    {
        return $this->hasMany(BookingInformation::class, 'rooms_id', 'id');
    }
    public function CategoryRoom()
    {
        return $this->belongsTo(CategoryRoom::class, 'category_id', 'id');
    }
    public function getWard()
    {
        return $this->belongsTo(wards::class, 'ward_id', 'code');
    }
    public function User()
    {
        return $this->belongsTo(User::class, 'chutro_id', 'id');
    }

    /**
     * Các hợp đồng thuê phòng gắn với phòng này.
     * Room hasMany Contract (rooms.id → contracts.room_id)
     * Sử dụng withTrashed() để bao gồm cả hợp đồng đã xóa mềm.
     */
    public function contracts()
    {
        return $this->hasMany(Contract::class, 'room_id', 'id');
    }

    /**
     * Hợp đồng đang hiệu lực của phòng (nếu có).
     * Room hasOne Contract (lọc theo status = active)
     */
    public function activeContract()
    {
        return $this->hasOne(Contract::class, 'room_id', 'id')
                    ->where('status', 'active');
    }
    public function scopeName($query, $request)
    {
        if (isset($request->name)) {
            $query->where('name', 'LIKE', '%' . $request->name . '%');
            return $query;
        }
    }
    public function scopeCategory($query, $request)
    {
        if (isset($request->category_id)) {
            if ($request->category_id == "Tất cả" || $request->category_id == null) {
                return $query;
            }
            $query->where('category_id', $request->category_id);
        }
        return $query;
    }
    public function scopeDistrict($query, $request)
    {
        if (isset($request->district_input)) {
            if ($request->district_input== "Tất cả" || $request->district_input == null) {
                return $query;
            }
            $huyenName = $request->district_input;
            return $query->whereHas('getWard.getDistrict', function ($query) use ($huyenName) {
                $query->where('full_name', $huyenName);
            });
        }
        return $query;
    }
    public function scopeWard($query, $request)
    {
        if (isset($request->ward_id)) {
            if ($request->ward_id == "Tất cả" || $request->ward_id == null) {
                return $query;
            }
            $query->where('ward_id', $request->ward_id);
        }
        return $query;
    }
    public function ScopePrice($query, $request)
    {
        if (isset($request->price)) {
            $range = $request->price;
            if (!$range[0] && !$range[1])
                return $query;
            if ($range[0] && !$range[1]) {
                return $query
                    ->where('price', '>=', $range[0]);
            }
            if (!$range[0] && $range[1]) {
                return $query
                    ->where('price', '<=', $range[1]);
            }
            return $query
                ->whereBetween('price', $range);
        }
    }

    public function ScopeArea($query, $request)
    {
        if (isset($request->area)) {
            $range = $request->area;
            if (!$range[0] && !$range[1])
                return $query;
            if ($range[0] && !$range[1]) {
                return $query
                    ->where('area', '>=', $range[0]);
            }
            if (!$range[0] && $range[1]) {
                return $query
                    ->where('area', '<=', $range[1]);
            }
            return $query
                ->whereBetween('area', $range);
        }
    }
    public function ScopeAddons($query, $request)
    {
        if (isset($request->add_ons)) {
            $add_ons = $request->add_ons;
            foreach ($add_ons as $key => $value) {
                $query->where('add_ons', 'LIKE', '%' . $value . '%');
            }
            return $query;
        }
    }

    /**
     * Tạo chuỗi SQL Haversine dùng chung (tránh lặp code).
     * Trả về expression tính khoảng cách km từ ($lat, $lng) đến (latitude, longitude).
     *
     * Bindings cần truyền theo thứ tự: [$lat, $lng, $lat]
     */
    private static function haversineSQL(): string
    {
        // Công thức: d = 6371 * ACOS(LEAST(1, cos_a * cos_b * cos_c + sin_a * sin_b))
        // LEAST(1.0, ...) bảo vệ khỏi floating-point error khiến ACOS() trả NULL
        return '(6371 * ACOS(LEAST(1.0,
            COS(RADIANS(?)) * COS(RADIANS(latitude))
            * COS(RADIANS(longitude) - RADIANS(?))
            + SIN(RADIANS(?)) * SIN(RADIANS(latitude))
        )))';
    }

    /**
     * Scope lọc phòng trong bán kính từ một tọa độ trung tâm.
     *
     * Chiến lược 2 tầng để tối ưu hiệu năng:
     *   Tầng 1 – Bounding box (dùng index): loại ngay các phòng rõ ràng nằm ngoài
     *   Tầng 2 – Haversine (chính xác): lọc lại trong hình vuông đã thu hẹp
     * → Giảm số lần MySQL phải tính ACOS() xuống đáng kể.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  float  $lat      Vĩ độ trung tâm (độ)
     * @param  float  $lng      Kinh độ trung tâm (độ)
     * @param  float  $radius   Bán kính tìm kiếm (km)
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * Cách dùng:
     *   Room::radius(21.0285, 105.8542, 3)->where('status', 1)->get();
     *   // Mỗi room trong kết quả có thêm thuộc tính distance_km
     */
    public function scopeRadius($query, float $lat, float $lng, float $radius)
    {
        $h = self::haversineSQL(); // expression SQL, bindings: [$lat, $lng, $lat]

        // ── Tầng 1: Bounding box – tận dụng index latitude & longitude ──────────
        // 1° latitude  ≈ 111 km  →  offset_lat = radius / 111
        // 1° longitude ≈ 85 km (tại vĩ độ ~21°N VN) →  offset_lng = radius / 85
        $latMin = $lat - $radius / 111;
        $latMax = $lat + $radius / 111;
        $lngMin = $lng - $radius / 85;
        $lngMax = $lng + $radius / 85;

        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('status', 1)
            ->whereBetween('latitude',  [$latMin, $latMax])
            ->whereBetween('longitude', [$lngMin, $lngMax])
            // ── Tầng 2: Haversine chính xác ──────────────────────────────────────
            ->whereRaw("$h <= ?", [$lat, $lng, $lat, $radius])
            // Đính kèm distance_km vào mỗi record, sắp xếp gần → xa
            ->selectRaw("rooms.*, $h AS distance_km", [$lat, $lng, $lat])
            ->orderByRaw("$h ASC",                   [$lat, $lng, $lat]);
    }
}