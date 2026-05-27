<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MapController extends Controller
{
    /**
     * Giới hạn bán kính tối đa để tránh query quá nặng (km).
     */
    private const RADIUS_MAX = 50;
    private const RADIUS_MIN = 0.1;
    private const RADIUS_DEFAULT = 3;

    /**
     * GET/POST /api/map/rooms
     *
     * Tìm phòng trọ trong bán kính từ vị trí người dùng.
     *
     * ── Tham số đầu vào ───────────────────────────────────────────────────────
     * @required float  user_lat    Vĩ độ người dùng
     * @required float  user_lng    Kinh độ người dùng
     * @optional float  radius      Bán kính tìm kiếm (km), mặc định 3, tối đa 50
     *
     * ── Bộ lọc tái sử dụng từ scope hiện có ──────────────────────────────────
     * @optional int    category_id Lọc theo loại phòng
     * @optional array  price       [min, max] – giá phòng (VNĐ)
     * @optional array  area        [min, max] – diện tích (m²)
     * @optional array  add_ons     Danh sách tiện ích
     *
     * ── Phân trang ────────────────────────────────────────────────────────────
     * @optional int    per_page    Số phòng mỗi trang (mặc định 20, tối đa 100)
     *
     * ── Response JSON ─────────────────────────────────────────────────────────
     * {
     *   "success": true,
     *   "meta": { "user_lat", "user_lng", "radius_km", "total" },
     *   "data": [
     *     { ...room fields..., "distance_km": 1.23 },
     *     ...
     *   ]
     * }
     */
    public function getRooms(Request $request): JsonResponse
    {
        // ── 1. Validation ─────────────────────────────────────────────────────
        $validator = Validator::make($request->all(), [
            'user_lat'    => ['required', 'numeric', 'between:-90,90'],
            'user_lng'    => ['required', 'numeric', 'between:-180,180'],
            'radius'      => ['nullable', 'numeric',
                              'min:' . self::RADIUS_MIN,
                              'max:' . self::RADIUS_MAX],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'price'       => ['nullable', 'array', 'size:2'],
            'price.*'     => ['nullable', 'integer', 'min:0'],
            'area'        => ['nullable', 'array', 'size:2'],
            'area.*'      => ['nullable', 'numeric', 'min:0'],
            'add_ons'     => ['nullable', 'array'],
            'add_ons.*'   => ['nullable', 'string'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'user_lat.required' => 'Thiếu tọa độ vĩ độ (user_lat).',
            'user_lng.required' => 'Thiếu tọa độ kinh độ (user_lng).',
            'user_lat.numeric'  => 'user_lat phải là số thực.',
            'user_lng.numeric'  => 'user_lng phải là số thực.',
            'radius.max'        => "Bán kính tối đa là " . self::RADIUS_MAX . " km.",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Tham số không hợp lệ.',
                'errors'  => $validator->errors(),
            ], 400);
        }

        // ── 2. Chuẩn hoá tham số ──────────────────────────────────────────────
        $lat      = (float) $request->input('user_lat');
        $lng      = (float) $request->input('user_lng');
        $radius   = (float) $request->input('radius', self::RADIUS_DEFAULT);
        $perPage  = (int)   $request->input('per_page', 20);

        // Đóng gói bộ lọc thành object để tái sử dụng các scope hiện có
        // (scopeCategory, scopePrice, scopeArea, scopeAddons đều nhận $request-like object)
        $filters = (object) $request->only(['category_id', 'price', 'area', 'add_ons']);

        // ── 3. Query ──────────────────────────────────────────────────────────
        // scopeRadius đã bao gồm:
        //   - whereNotNull(latitude/longitude)
        //   - where('status', 1)
        //   - bounding box (dùng index)
        //   - Haversine chính xác
        //   - selectRaw(..., distance_km)
        //   - orderByRaw(...) gần → xa
        $query = Room::radius($lat, $lng, $radius)
            ->category($filters)    // lọc loại phòng
            ->price($filters)       // lọc giá
            ->area($filters)        // lọc diện tích
            ->addons($filters)      // lọc tiện ích
            ->with([
                // Eager load quan hệ cần thiết cho marker popup trên bản đồ
                'CategoryRoom:id,name',
                'getWard:code,full_name,district_code',
                'getWard.getDistrict:code,full_name',
            ]);

        // ── 4. Phân trang (tuỳ chọn) ──────────────────────────────────────────
        // Nếu frontend gửi per_page=-1 hoặc all=true → trả toàn bộ (không phân trang)
        // Mặc định: phân trang để tránh trả dữ liệu quá lớn
        if ($request->boolean('all')) {
            $rooms = $query->get();
            $total = $rooms->count();

            return response()->json([
                'success' => true,
                'meta'    => [
                    'user_lat'  => $lat,
                    'user_lng'  => $lng,
                    'radius_km' => $radius,
                    'total'     => $total,
                    'paginated' => false,
                ],
                'data' => $this->formatRooms($rooms),
            ]);
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'meta'    => [
                'user_lat'     => $lat,
                'user_lng'     => $lng,
                'radius_km'    => $radius,
                'total'        => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'paginated'    => true,
            ],
            'data' => $this->formatRooms($paginated->getCollection()),
        ]);
    }

    /**
     * Format collection phòng trọ trước khi trả về JSON.
     * Làm tròn distance_km, chuyển main_img thành URL tuyệt đối.
     *
     * @param  \Illuminate\Support\Collection $rooms
     * @return array
     */
    private function formatRooms($rooms): array
    {
        return $rooms->map(function (Room $room) {
            return [
                'id'           => $room->id,
                'name'         => $room->name,
                'price'        => $room->price,
                'area'         => $room->area,
                'unit'         => $room->unit,
                'status'       => $room->status,
                'main_img'     => $room->main_img
                                    ? asset('images/main_room/' . $room->main_img)
                                    : null,
                'latitude'     => (float) $room->latitude,
                'longitude'    => (float) $room->longitude,
                'distance_km'  => round((float) $room->distance_km, 2),
                'category'     => $room->CategoryRoom?->name,
                'ward'         => $room->getWard?->full_name,
                'district'     => $room->getWard?->getDistrict?->full_name,
                'is_deposit_required' => $room->is_deposit_required,
                'add_ons'      => $room->add_ons,
                'electric'     => $room->electric,
                'water'        => $room->water,
            ];
        })->toArray();
    }
}
