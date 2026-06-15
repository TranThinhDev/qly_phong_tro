<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Room;
use App\Models\UtilityReading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UtilityReadingWebController extends Controller
{
    /**
     * GET /utility-readings
     *
     * Hiển thị danh sách các phòng đang có hợp đồng active thuộc sở hữu của chủ trọ,
     * kèm theo chỉ số tháng hiện tại (nếu đã nhập).
     */
    public function index(Request $request)
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
            ->get()
            ->map(function (Room $room) use ($month, $year) {
                $reading = $room->utilityReadings->first();

                $room->reading = $reading;
                $room->previous_reading = $this->getPreviousReading($room->id, $month, $year);
                return $room;
            });

        return view('billing.utility-readings', compact('rooms', 'month', 'year'));
    }

    /**
     * POST /utility-readings
     *
     * Lưu hoặc cập nhật chỉ số điện/nước cho một phòng trong một tháng.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id'           => ['required', 'integer', 'exists:rooms,id'],
            'month'             => ['required', 'integer', 'min:1', 'max:12'],
            'year'              => ['required', 'integer', 'min:2000', 'max:2100'],
            'electricity_index' => ['required', 'integer', 'min:0'],
            'water_index'       => ['required', 'integer', 'min:0'],
            'evidence_image'    => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:5120'], // max 5MB
        ]);

        $landlordId = Auth::id();

        // ── Kiểm tra quyền sở hữu phòng ─────────────────────────────────
        $room = Room::where('id', $validated['room_id'])
            ->where('chutro_id', $landlordId)
            ->first();

        if (! $room) {
            return redirect()->back()->with('error', 'Phòng không tồn tại hoặc bạn không có quyền truy cập.');
        }

        // ── Kiểm tra phòng có hợp đồng đang active không ─────────────────
        if (! $room->activeContract) {
            return redirect()->back()->with('error', 'Phòng này chưa có hợp đồng đang hiệu lực.');
        }

        // ── Tìm bản ghi hiện tại (nếu có) ────────────────────────────────
        $existingReading = UtilityReading::where('room_id', $validated['room_id'])
            ->where('month', $validated['month'])
            ->where('year', $validated['year'])
            ->first();

        // Không cho sửa sau khi đã chốt để sinh hóa đơn
        if ($existingReading && $existingReading->isFinalized()) {
            return redirect()->back()->with('error', 'Chỉ số tháng này đã được chốt và không thể thay đổi.');
        }

        try {
            DB::beginTransaction();

            // ── Xử lý upload ảnh bằng chứng Điện ──────────────────────────────
            $electricityImageUrl = $existingReading?->electricity_evidence_image_url; // Giữ ảnh cũ mặc định

            if ($request->hasFile('electricity_evidence_image')) {
                if ($electricityImageUrl && Storage::disk('public')->exists($electricityImageUrl)) {
                    Storage::disk('public')->delete($electricityImageUrl);
                }
                $electricityImageUrl = $request->file('electricity_evidence_image')->store('utility_readings', 'public');
            }

            // ── Xử lý upload ảnh bằng chứng Nước ──────────────────────────────
            $waterImageUrl = $existingReading?->water_evidence_image_url;

            if ($request->hasFile('water_evidence_image')) {
                if ($waterImageUrl && Storage::disk('public')->exists($waterImageUrl)) {
                    Storage::disk('public')->delete($waterImageUrl);
                }
                $waterImageUrl = $request->file('water_evidence_image')->store('utility_readings', 'public');
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
                    'electricity_index'              => $validated['electricity_index'],
                    'water_index'                    => $validated['water_index'],
                    'electricity_evidence_image_url' => $electricityImageUrl,
                    'water_evidence_image_url'       => $waterImageUrl,
                    // status giữ nguyên 'draft' (không forceFill ở đây)
                ]
            );

            DB::commit();

            return redirect()->back()->with('success', 'Lưu chỉ số điện/nước cho phòng ' . $room->name . ' thành công.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Đã xảy ra lỗi hệ thống: ' . $e->getMessage());
        }
    }

    /**
     * Lấy chỉ số tháng trước của một phòng.
     *
     * @return object|null
     */
    private function getPreviousReading(int $roomId, int $month, int $year)
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

        return (object)[
            'electricity_index' => $prev->electricity_index,
            'water_index'       => $prev->water_index,
            'month'             => $prevMonth,
            'year'              => $prevYear,
        ];
    }
}
