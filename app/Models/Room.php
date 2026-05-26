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
        'latlng'              => 'array',   // auto decode/encode JSON
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
     * Scope lọc phòng trong bán kính từ một tọa độ trung tâm.
     * Dùng công thức Haversine tính trực tiếp trên MySQL.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  float  $lat      Vĩ độ trung tâm (độ)
     * @param  float  $lng      Kinh độ trung tâm (độ)
     * @param  float  $radiusKm Bán kính tìm kiếm (km), mặc định 3km
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * Cách dùng:
     *   Room::nearby(21.0285, 105.8542, 3)->where('status', 1)->get();
     */
    public function scopeNearby($query, float $lat, float $lng, float $radiusKm = 3.0)
    {
        // R = 6371 km (bán kính trái đất)
        // Công thức Haversine:
        //   d = 2R * asin( sqrt(
        //         sin²(Δlat/2) + cos(lat1)*cos(lat2)*sin²(Δlng/2)
        //       ))
        $haversine = "(
            6371 * ACOS(
                LEAST(1.0, -- tránh lỗi floating point làm ACOS trả về NULL
                    COS(RADIANS(?)) * COS(RADIANS(latitude))
                    * COS(RADIANS(longitude) - RADIANS(?))
                    + SIN(RADIANS(?)) * SIN(RADIANS(latitude))
                )
            )
        )";

        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            // Bounding box sơ bộ: lọc nhanh bằng index trước khi tính Haversine
            // 1 độ latitude ≈ 111 km → offset = radius / 111
            ->whereBetween('latitude',  [$lat - $radiusKm / 111, $lat + $radiusKm / 111])
            ->whereBetween('longitude', [$lng - $radiusKm / 85,  $lng + $radiusKm / 85])
            // Lọc chính xác bằng Haversine
            ->whereRaw("{$haversine} <= ?", [$lat, $lng, $lat, $radiusKm])
            // Thêm cột distance_km vào kết quả để frontend hiển thị
            ->selectRaw("rooms.*, {$haversine} AS distance_km", [$lat, $lng, $lat])
            ->orderByRaw("{$haversine} ASC", [$lat, $lng, $lat]);
    }
}