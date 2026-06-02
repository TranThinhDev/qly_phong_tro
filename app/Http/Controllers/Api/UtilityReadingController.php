<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUtilityReadingRequest;
use App\Models\Contract;
use App\Models\Room;
use App\Models\UtilityReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * UtilityReadingController
 *
 * Quản lý chỉ số điện/nước hàng tháng cho chủ trọ.
 *
 * Quy tắc controller mỏng (Thin Controller):
 *   - Không có business logic phức tạp ở đây.
 *   - Validation → Service/Model → Response.
 *   - Mọi thao tác liên quan đến tiền/trạng thái dùng DB::transaction().
 */
class UtilityReadingController extends Controller
{
    /**
     * GET /api/landlord/utility-readings/rooms
     *
     * Trả về danh sách các phòng đang có hợp đồng active
     * thuộc sở hữu của landlord đang đăng nhập,
     * kèm theo chỉ số tháng hiện tại (nếu đã nhập).
     *
     * Dữ liệu này dùng để render bảng nhập liệu trên frontend.
     */
    public function activeRooms(Request $request): JsonResponse
    {
        $landlordId = Auth::id();
        $month      = (int) ($request->query('month', now()->month));
        $year       = (int) ($request->query('year',  now()->year));

        // Lấy các phòng có hợp đồng đang active thuộc chủ trọ này
        $rooms = Room::where('chutro_id', $landlordId)
            ->whereHas('activeContract')
            ->with([
                // Eager load hợp đồng active để lấy tenant name
                'activeContract.tenant:id,name,email,PhoneNumber',
                // Eager load chỉ số tháng hiện tại (nếu có)
                'utilityReadings' => function ($q) use ($month, $year) {
                    $q->where('month', $month)->where('year', $year);
                },
            ])
            ->get(['id', 'name', 'electric', 'water'])
            ->map(function (Room $room) use ($month, $year) {
                $reading = $room->utilityReadings->first();

                return [
                    'id'               => $room->id,
                    'name'             => $room->name,
                    'electric_price'   => (float) $room->electric, // Đơn giá điện (đ/kWh)
                    'water_price'      => (float) $room->water,    // Đơn giá nước (đ/m³)
                    'tenant_name'      => $room->activeContract?->tenant?->name,
                    'tenant_phone'     => $room->activeContract?->tenant?->PhoneNumber,
                    // Chỉ số tháng hiện tại (null nếu chưa nhập)
                    'reading' => $reading ? [
                        'id'                => $reading->id,
                        'electricity_index' => $reading->electricity_index,
                        'water_index'       => $reading->water_index,
                        'evidence_image_url'=> $reading->evidence_image_url
                            ? Storage::url($reading->evidence_image_url)
                            : null,
                        'status'            => $reading->status,
                    ] : null,
                    // Chỉ số tháng trước để frontend hiển thị tham chiếu
                    'previous_reading'  => $this->getPreviousReading($room->id, $month, $year),
                ];
            });

        return response()->json([
            'data'  => $rooms,
            'month' => $month,
            'year'  => $year,
        ]);
    }

    /**
     * POST /api/landlord/utility-readings
     *
     * Lưu hoặc cập nhật chỉ số điện/nước cho một phòng trong một tháng.
     *
     * Logic:
     *   - Nếu đã có bản ghi (draft) → cập nhật.
     *   - Nếu chưa có → tạo mới.
     *   - Không cho phép cập nhật nếu status = 'finalized'.
     *   - Xử lý upload ảnh vào storage/app/public/utilities/{year}/{month}/
     */
    public function store(StoreUtilityReadingRequest $request): JsonResponse
    {
        $validated  = $request->validated();
        $landlordId = Auth::id();

        // ── Kiểm tra quyền sở hữu phòng ─────────────────────────────────
        $room = Room::where('id', $validated['room_id'])
            ->where('chutro_id', $landlordId)
            ->first();

        if (! $room) {
            return response()->json([
                'success' => false,
                'message' => 'Phòng không tồn tại hoặc bạn không có quyền truy cập.',
            ], 403);
        }

        // ── Kiểm tra phòng có hợp đồng đang active không ─────────────────
        if (! $room->activeContract) {
            return response()->json([
                'success' => false,
                'message' => 'Phòng này chưa có hợp đồng đang hiệu lực.',
            ], 422);
        }

        // ── Tìm bản ghi hiện tại (nếu có) ────────────────────────────────
        $existingReading = UtilityReading::where('room_id', $validated['room_id'])
            ->where('month', $validated['month'])
            ->where('year', $validated['year'])
            ->first();

        // Không cho sửa sau khi đã chốt để sinh hóa đơn
        if ($existingReading && $existingReading->isFinalized()) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ số tháng này đã được chốt và không thể thay đổi.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            // ── Xử lý upload ảnh bằng chứng ──────────────────────────────
            $imageUrl = $existingReading?->evidence_image_url; // Giữ ảnh cũ mặc định

            if ($request->hasFile('evidence_image')) {
                // Xóa ảnh cũ nếu có (tiết kiệm storage)
                if ($imageUrl && Storage::exists($imageUrl)) {
                    Storage::delete($imageUrl);
                }

                // Lưu ảnh mới vào: storage/app/public/utilities/{year}/{month}/
                $path     = "utilities/{$validated['year']}/{$validated['month']}";
                $imageUrl = $request->file('evidence_image')->store($path, 'public');
            }

            // ── Upsert bản ghi chỉ số ─────────────────────────────────────
            $reading = UtilityReading::updateOrCreate(
                // Điều kiện tìm kiếm (unique key)
                [
                    'room_id' => $validated['room_id'],
                    'month'   => $validated['month'],
                    'year'    => $validated['year'],
                ],
                // Giá trị cần cập nhật/tạo mới
                [
                    'electricity_index'  => $validated['electricity_index'],
                    'water_index'        => $validated['water_index'],
                    'evidence_image_url' => $imageUrl,
                    // status giữ nguyên 'draft' (không forceFill ở đây)
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Lưu chỉ số điện/nước thành công.',
                'data'    => [
                    'id'                => $reading->id,
                    'room_id'           => $reading->room_id,
                    'month'             => $reading->month,
                    'year'              => $reading->year,
                    'electricity_index' => $reading->electricity_index,
                    'water_index'       => $reading->water_index,
                    'evidence_image_url'=> $reading->evidence_image_url
                        ? Storage::url($reading->evidence_image_url)
                        : null,
                    'status'            => $reading->status,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Lấy chỉ số tháng trước của một phòng.
     * Dùng để frontend hiển thị tham chiếu và tính sản lượng tiêu thụ.
     *
     * @return array{electricity_index: int, water_index: int}|null
     */
    private function getPreviousReading(int $roomId, int $month, int $year): ?array
    {
        [$prevMonth, $prevYear] = $month === 1
            ? [12, $year - 1]
            : [$month - 1, $year];

        $prev = UtilityReading::where('room_id', $roomId)
            ->where('month', $prevMonth)
            ->where('year', $prevYear)
            ->first(['electricity_index', 'water_index']);

        if (! $prev) {
            return null;
        }

        return [
            'electricity_index' => $prev->electricity_index,
            'water_index'       => $prev->water_index,
            'month'             => $prevMonth,
            'year'              => $prevYear,
        ];
    }
}
