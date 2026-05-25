<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\BookingInformation;
use App\Models\Room;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Hiển thị danh sách đơn đặt phòng của khách hàng đang đăng nhập.
     * Dùng email làm định danh vì booking_information không có cột user_id.
     */
    public function index()
    {
        $bookings = BookingInformation::with('room')
            ->where('email', Auth::user()->email)
            ->latest()
            ->paginate(10);

        return view('frontend.booking.index', compact('bookings'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Dual-Flow Booking:
     *   - Flow 1 (Deposit):     is_deposit_required == true  → đặt cọc, khoá phòng 15 phút
     *   - Flow 2 (Appointment): is_deposit_required == false → hẹn xem, giới hạn 3 lịch pending
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request): JsonResponse {

                // 1. Lấy phòng với pessimistic locking để tránh race condition
                $room = Room::lockForUpdate()->findOrFail($request->room_id);

                // ──────────────────────────────────────────────
                // FLOW 1 – DEPOSIT (phòng yêu cầu đặt cọc)
                // ──────────────────────────────────────────────
                if ($room->is_deposit_required) {

                    // Phòng phải đang trống (status = 1)
                    if ($room->status != 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Phòng hiện không còn trống.',
                        ], 400);
                    }

                    // Phòng không được đang bị giữ chỗ bởi giao dịch khác
                    if ($room->hold_until && $room->hold_until->gt(now())) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Phòng đang được giữ chỗ, vui lòng thử lại sau ' .
                                         $room->hold_until->diffForHumans() . '.',
                        ], 400);
                    }

                    // Tạo mã booking duy nhất
                    do {
                        $bookingCode = strtoupper(Str::random(8));
                    } while (BookingInformation::where('booking_code', $bookingCode)->exists());

                    // Tạo bản ghi Booking
                    $booking = BookingInformation::create([
                        'rooms_id'     => $room->id,
                        'booking_code' => $bookingCode,
                        'booking_type' => 'deposit',
                        'status'       => 'pending',
                        'name'         => auth()->user()->name,
                        'email'        => auth()->user()->email,
                        'phone'        => auth()->user()->phone ?? null,
                    ]);

                    // Khoá phòng trong 15 phút (status = 2: đang giữ chỗ)
                    $room->update([
                        'status'     => 2,
                        'hold_until' => now()->addMinutes(15),
                    ]);

                    // URL thanh toán giả lập (thay bằng cổng thật khi cần)
                    $paymentUrl = route('booking.payment.fake', [
                        'booking_code' => $bookingCode,
                    ]);

                    return response()->json([
                        'success'      => true,
                        'message'      => 'Đặt cọc thành công. Vui lòng hoàn tất thanh toán trong 15 phút.',
                        'booking_code' => $bookingCode,
                        'payment_url'  => $paymentUrl,
                        'hold_until'   => $room->hold_until->toIso8601String(),
                    ], 201);
                }

                // ──────────────────────────────────────────────
                // FLOW 2 – APPOINTMENT (hẹn xem, không cần cọc)
                // ──────────────────────────────────────────────

                // Rate Limit: tối đa 3 lịch hẹn đang pending cùng lúc
                $pendingCount = BookingInformation::where('email', auth()->user()->email)
                    ->where('booking_type', 'appointment')
                    ->where('status', 'pending')
                    ->count();

                if ($pendingCount >= 3) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bạn đang có ' . $pendingCount . ' lịch hẹn chờ xác nhận. ' .
                                     'Vui lòng chờ xử lý hoặc huỷ bớt trước khi đặt thêm.',
                    ], 429);
                }

                // Tạo lịch hẹn (không thay đổi trạng thái phòng)
                $booking = BookingInformation::create([
                    'rooms_id'         => $room->id,
                    'booking_code'     => strtoupper(Str::random(8)),
                    'booking_type'     => 'appointment',
                    'status'           => 'pending',
                    'appointment_date' => $request->appointment_date,
                    'name'             => auth()->user()->name,
                    'email'            => auth()->user()->email,
                    'phone'            => auth()->user()->phone ?? null,
                ]);

                return response()->json([
                    'success'          => true,
                    'message'          => 'Đặt lịch hẹn xem phòng thành công. Chúng tôi sẽ liên hệ xác nhận sớm nhất.',
                    'booking_code'     => $booking->booking_code,
                    'appointment_date' => $booking->appointment_date->toIso8601String(),
                ], 200);
            });

        } catch (\Throwable $e) {
            Log::error('[BookingController@store] Lỗi không mong đợi', [
                'user_id'  => auth()->id(),
                'room_id'  => $request->room_id,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show( string $id)
    {
        $user = Auth::user();
        $BookList = Room::where('chutro_id', $user->id)->where('id', $id)->first()->getBooking;
       
        $BookingList =array_reverse($BookList->all());
        return view('frontend.booking.show', compact('BookingList'));

    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
       
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function exportPdf($id)
    {
        // 1. Lấy dữ liệu booking (Eager load thêm thông tin phòng nếu cần)
        $booking = \App\Models\BookingInformation::with('room')->findOrFail($id);

        // 2. Authorization — chống IDOR
        //    Dự án dùng email làm định danh sở hữu (booking_information không có user_id).
        //    Admin (role = 1) được phép download mọi booking để hỗ trợ khách hàng.
        $currentUser = auth()->user();
        $isOwner     = $booking->email === $currentUser->email;
        $isAdmin     = $currentUser->isAdmin();

        if (! $isOwner && ! $isAdmin) {
            abort(403, 'Bạn không có quyền tải biên nhận của đơn đặt phòng này.');
        }

        // 3. Trỏ tới view HTML và truyền biến dữ liệu vào
        $pdf = Pdf::loadView('pdf.booking_receipt', compact('booking'));

        // 4. Thiết lập khổ giấy A4
        $pdf->setPaper('a4', 'portrait');

        // 5. Trả về file PDF cho trình duyệt tải xuống
        return $pdf->download('bien-nhan-dat-phong-' . $booking->id . '.pdf');
    }

    // =========================================================================
    /**
     * Xử lý yêu cầu hoàn tiền đặt cọc từ khách hàng (Web Form).
     *
     * POST /booking/refund-request
     * Middleware: auth, verified
     *
     * Business rules:
     *   - Booking phải thuộc về user đang đăng nhập (so khớp email).
     *   - Trạng thái booking phải là 'paid' và chưa có refund_status.
     *   - Phải trong vòng 48 giờ kể từ khi tạo booking.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function submitRefundRequest(Request $request)
    {
        // ── 1. Validate đầu vào ───────────────────────────────────────────────
        $validated = $request->validate(
            [
                'booking_id'     => ['required', 'integer', 'exists:booking_information,id'],
                'reason'         => ['required', 'string', 'min:10', 'max:500'],
                'evidence_image' => ['nullable', 'image', 'max:2048'],   // max 2 MB
            ],
            [
                'booking_id.required'    => 'Thiếu mã đặt phòng.',
                'booking_id.exists'      => 'Đơn đặt phòng không tồn tại.',
                'reason.required'        => 'Vui lòng nhập lý do yêu cầu hoàn tiền.',
                'reason.min'             => 'Lý do phải có ít nhất :min ký tự.',
                'reason.max'             => 'Lý do không được vượt quá :max ký tự.',
                'evidence_image.image'   => 'File bằng chứng phải là ảnh (jpg, png, gif, ...).',
                'evidence_image.max'     => 'Ảnh bằng chứng không được vượt quá 2 MB.',
            ]
        );

        try {
            // ── 2. Lấy booking & kiểm tra quyền sở hữu ───────────────────────
            //
            // Dùng email để xác định chủ sở hữu (booking_information hiện lưu
            // email của người đặt, không có cột user_id riêng).
            $booking = BookingInformation::findOrFail($validated['booking_id']);

            if ($booking->email !== auth()->user()->email) {
                return back()
                    ->with('error', 'Bạn không có quyền thực hiện thao tác này.');
            }

            // ── 3. Kiểm tra lại điều kiện nghiệp vụ phía Server ──────────────

            // 3a. Trạng thái phải là 'paid'
            if ($booking->status !== 'paid') {
                return back()
                    ->with('error', 'Chỉ có thể yêu cầu hoàn tiền cho đơn đã thanh toán.');
            }

            // 3b. Chưa có yêu cầu hoàn tiền nào trước đó
            if (! is_null($booking->refund_status)) {
                return back()
                    ->with('error', 'Đơn đặt phòng này đã có yêu cầu hoàn tiền trước đó.');
            }

            // 3c. Trong vòng 48 giờ kể từ khi tạo booking
            if ($booking->created_at->diffInHours(now()) >= 48) {
                return back()
                    ->with('error', 'Đã quá thời hạn 48 giờ để yêu cầu hoàn tiền.');
            }

            // ── 4. Xử lý bên trong DB Transaction ────────────────────────────
            DB::beginTransaction();

                // 4a. Chuẩn bị dữ liệu cập nhật
                $updateData = [
                    'refund_status' => 'requested',
                    'refund_reason' => $validated['reason'],
                ];

                // 4b. Xử lý upload ảnh bằng chứng (nếu có)
                if ($request->hasFile('evidence_image')) {
                    // Lưu vào storage/app/public/disputes/
                    // Truy cập qua: asset('storage/disputes/filename.jpg')
                    $imagePath = $request->file('evidence_image')
                                         ->store('disputes', 'public');

                    $updateData['evidence_image_path'] = $imagePath;
                }

                // 4c. Cập nhật booking
                $booking->update($updateData);

            DB::commit();

            // ── 5. Trả về thành công ──────────────────────────────────────────
            return back()
                ->with('success', 'Yêu cầu hoàn tiền đã được ghi nhận. Chúng tôi sẽ xem xét và phản hồi sớm nhất.');

        } catch (\Throwable $e) {

            // ── 6. Rollback & log nếu có lỗi bất ngờ ─────────────────────────
            DB::rollBack();

            Log::error('[BookingController@submitRefundRequest] Lỗi không mong đợi', [
                'user_id'    => auth()->id(),
                'user_email' => auth()->user()->email ?? null,
                'booking_id' => $validated['booking_id'] ?? $request->input('booking_id'),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return back()
                ->with('error', 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.')
                ->withInput();
        }
    }
    // =========================================================================
}
