<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\BookingInformation;
use App\Models\Notification;
use App\Models\Room;
use App\Models\User;
use App\Services\VnpayService;
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
                        'phone'        => auth()->user()->PhoneNumber ?? null,
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
                    'phone'            => auth()->user()->PhoneNumber ?? null,
                ]);

                // ── Thông báo cho chủ trọ về lịch hẹn mới ─────────────────
                // Lấy thông tin phòng & chủ trọ (đã load $room bên trên)
                // Thực hiện NGOÀI transaction chính (đây là phần cuối của closure)
                $appointmentDateStr = $booking->appointment_date
                    ? $booking->appointment_date->format('d/m/Y H:i')
                    : 'chưa xác định';

                $chuTroForAppointment = $room->chutro_id
                    ? User::find($room->chutro_id)
                    : null;

                if ($chuTroForAppointment) {
                    try {
                        Notification::create([
                            'user_id' => $chuTroForAppointment->id,
                            'title'   => 'Khách hàng ' . auth()->user()->name
                                       . ' vừa đặt lịch xem phòng ' . $room->name
                                       . ' vào ngày ' . $appointmentDateStr . '.',
                            'status'  => 0,  // 0 = chưa đọc
                            'link'    => route('booking.show', $room->id),
                        ]);
                    } catch (\Throwable $notifEx) {
                        Log::warning('[BookingController@store] Không thể tạo thông báo cho chủ trọ', [
                            'chutro_id' => $chuTroForAppointment->id,
                            'message'   => $notifEx->getMessage(),
                        ]);
                    }
                }

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
     * Hiển thị trang xác nhận đặt cọc (checkout summary + countdown timer).
     *
     * GET /booking/checkout/{booking_code}
     */
    public function checkout(string $booking_code)
    {
        $booking = BookingInformation::where('booking_code', $booking_code)
            ->where('email', auth()->user()->email)
            ->where('status', 'pending')
            ->with('room')
            ->firstOrFail();

        $room = $booking->room;

        // Tính số giây còn lại trong thời gian giữ chỗ
        $holdUntil = $room->hold_until;
        $timeLeftInSeconds = $holdUntil ? max(0, now()->diffInSeconds($holdUntil, false)) : 0;

        return view('frontend.booking.checkout', compact('booking', 'room', 'timeLeftInSeconds'));
    }

    /**
     * Tạo URL thanh toán VNPay Sandbox và redirect user.
     *
     * POST /booking/vnpay-payment
     * Body: booking_id
     */
    public function createVnpayPayment(Request $request)
    {
        $request->validate([
            'booking_id' => ['required', 'integer', 'exists:booking_information,id'],
        ]);

        $booking = BookingInformation::with('room')->findOrFail($request->booking_id);

        // Authorization: chỉ người đặt mới được thanh toán
        if ($booking->email !== auth()->user()->email) {
            abort(403, 'Bạn không có quyền thực hiện thanh toán này.');
        }

        if ($booking->status !== 'pending') {
            return back()->with('error', 'Đơn đặt phòng này không ở trạng thái chờ thanh toán.');
        }

        // Kiểm tra thời gian giữ chỗ còn hiệu lực
        $room = $booking->room;
        if ($room->hold_until && $room->hold_until->lt(now())) {
            $room->update(['status' => 1, 'hold_until' => null]);
            $booking->update(['status' => 'cancelled']);
            return back()->with('error', 'Phần giữ chỗ đã hết hạn. Vui lòng thực hiện đặt phòng lại.');
        }

        // Tạo URL thanh toán VNPay
        $vnpay      = new VnpayService();
        $amount     = (int) ($room->deposit_amount ?? 0);
        $orderInfo  = 'Dat coc phong ' . preg_replace('/[^a-zA-Z0-9 ]/', '', $room->name ?? '');
        $ipAddr     = $request->ip();

        $paymentUrl = $vnpay->createPaymentUrl(
            $booking->booking_code,
            $amount,
            $orderInfo,
            $ipAddr
        );

        Log::info('[BookingController] Redirecting to VNPay Sandbox', [
            'booking_code' => $booking->booking_code,
            'amount'       => $amount,
        ]);

        return redirect($paymentUrl);
    }

    /**
     * Trang thanh toán giả lập (chỉ dùng khi chưa tích hợp VNPay thật).
     *
     * GET /booking/payment-fake/{booking_code}
     */
    public function fakePayment(string $booking_code)
    {
        $booking = BookingInformation::where('booking_code', $booking_code)
            ->where('email', auth()->user()->email)
            ->with('room')
            ->firstOrFail();

        $room = $booking->room;
        $timeLeftInSeconds = $room->hold_until
            ? max(0, now()->diffInSeconds($room->hold_until, false))
            : 0;

        return view('frontend.booking.checkout', compact('booking', 'room', 'timeLeftInSeconds'));
    }

    /**
     * Xử lý callback từ VNPay sau khi thanh toán (Return URL).
     *
     * GET /vnpay-return
     * (Không cần auth — VNPay redirect thẳng vào URL này)
     */
    public function vnpayReturn(Request $request)
    {
        $vnpData = $request->all();
        $vnpay   = new VnpayService();

        // ── 1. Xác thực chữ ký (bảo vệ chống giả mạo) ─────────────────────────
        if (! $vnpay->verifySignature($vnpData)) {
            Log::warning('[VNPay Return] Chữ ký không hợp lệ', ['data' => $vnpData]);
            return redirect()->route('home')
                ->with('error', 'Chữ ký thanh toán không hợp lệ. Vui lòng liên hệ hỗ trợ.');
        }

        $bookingCode = $vnpData['vnp_TxnRef'] ?? '';
        $responseCode = $vnpData['vnp_ResponseCode'] ?? '';
        $transactionId = $vnpData['vnp_TransactionNo'] ?? '';
        $bankCode      = $vnpData['vnp_BankCode'] ?? '';
        $amount        = isset($vnpData['vnp_Amount']) ? (int)($vnpData['vnp_Amount'] / 100) : 0;

        // Nếu mã tham chiếu bắt đầu bằng TXN- thì đây là thanh toán hợp đồng (Contract)
        if (\Illuminate\Support\Str::startsWith($bookingCode, 'TXN-')) {
            return app(\App\Http\Controllers\ContractController::class)->vnpayReturn($request);
        }

        // Nếu mã tham chiếu bắt đầu bằng INV- thì đây là thanh toán Hóa đơn (Invoice)
        if (\Illuminate\Support\Str::startsWith($bookingCode, 'INV-')) {
            return app(\App\Http\Controllers\Api\InvoicePaymentController::class)->vnpayReturn($request);
        }

        // ── 2. Tìm booking tương ứng ───────────────────────────────────────────
        $booking = BookingInformation::with('room')
            ->where('booking_code', $bookingCode)
            ->first();

        if (! $booking) {
            Log::error('[VNPay Return] Không tìm thấy booking', ['booking_code' => $bookingCode]);
            return redirect()->route('trang_chu')
                ->with('error', 'Không tìm thấy đơn đặt phòng tương ứng.');
        }

        // ── 3. Xử lý kết quả thanh toán ───────────────────────────────────────
        if ($vnpay->isSuccess($vnpData)) {

            // Chỉ cập nhật nếu chưa được xử lý trước (chống IPN replay)
            if ($booking->status === 'pending') {
                DB::transaction(function () use ($booking, $transactionId, $bankCode, $amount) {
                    // 3a. Cập nhật booking → paid
                    $booking->update([
                        'status'         => 'paid',
                        'payment_method' => 'vnpay',
                        'transaction_id' => $transactionId,
                        'deposit_amount' => $amount,
                    ]);

                    // 3b. Cập nhật phòng → đã bán / có người ở (status = 3)
                    //     Và xóa thời gian giữ chỗ
                    if ($booking->room) {
                        $booking->room->update([
                            'status'     => 3,        // 3 = đã có người cọc / đã bọn
                            'hold_until' => null,
                        ]);

                        // Đưa tiền cọc vào ví tạm giữ (pending_balance) của Chủ trọ
                        $walletService = app(\App\Services\WalletService::class);
                        $walletService->addPendingFunds(
                            $booking->room->chutro_id,
                            (float) $amount,
                            $booking,
                            "Cọc tiền giữ phòng " . $booking->room->name . " (Mã: " . $booking->booking_code . ")"
                        );
                    }
                });

                // ── Gửi thông báo SAU khi transaction đã commit ────────────
                // Wrap trong try-catch độc lập để lỗi thông báo không ảnh hưởng
                // đến luồng xử lý thanh toán.
                $roomForNotif = $booking->room;

                // (A) Thông báo cho Chủ trọ
                if ($roomForNotif && $roomForNotif->chutro_id) {
                    try {
                        Notification::create([
                            'user_id' => $roomForNotif->chutro_id,
                            'title'   => 'Khách hàng ' . $booking->name
                                       . ' đã đặt cọc thành công phòng ' . $roomForNotif->name . '.',
                            'status'  => 0,
                            'link'    => route('booking.show', $roomForNotif->id),
                        ]);
                    } catch (\Throwable $notifEx) {
                        Log::warning('[VNPay Return] Không thể tạo thông báo cho chủ trọ', [
                            'chutro_id' => $roomForNotif->chutro_id,
                            'message'   => $notifEx->getMessage(),
                        ]);
                    }
                }

                // (B) Thông báo cho Khách hàng (tìm user qua email)
                $customerUser = User::where('email', $booking->email)->first();
                if ($customerUser) {
                    try {
                        Notification::create([
                            'user_id' => $customerUser->id,
                            'title'   => 'Thanh toán thành công. Bạn đã đặt cọc phòng '
                                       . ($roomForNotif->name ?? 'N/A') . '.',
                            'status'  => 0,
                            'link'    => route('booking.index'),
                        ]);
                    } catch (\Throwable $notifEx) {
                        Log::warning('[VNPay Return] Không thể tạo thông báo cho khách hàng', [
                            'email'   => $booking->email,
                            'message' => $notifEx->getMessage(),
                        ]);
                    }
                }
            }

            Log::info('[VNPay Return] Thanh toán thành công', [
                'booking_code'  => $bookingCode,
                'transaction_id' => $transactionId,
                'amount'        => $amount,
                'bank'          => $bankCode,
            ]);

            return redirect()->route('booking.index')
                ->with('vnpay_success', true)
                ->with('vnpay_booking_code', $bookingCode)
                ->with('vnpay_amount', $amount)
                ->with('vnpay_bank', $bankCode);

        } else {
            // Thanh toán thất bại / bị huỷ → trả phòng về trống
            if ($booking->status === 'pending' && $booking->room) {
                $booking->room->update(['status' => 1, 'hold_until' => null]);
            }
            $booking->update(['status' => 'cancelled']);

            Log::warning('[VNPay Return] Thanh toán thất bại', [
                'booking_code'  => $bookingCode,
                'response_code' => $responseCode,
            ]);

            return redirect()->route('Room_show', $booking->rooms_id)
                ->with('error', 'Thanh toán không thành công (mã lỗi: ' . $responseCode . '). Phòng đã được trả lại.');
        }
    }

    // =========================================================================
    /**
     * Huỷ đơn đặt phòng (appointment hoặc deposit chưa thanh toán).
     *
     * POST /booking/cancel
     * Body: booking_id
     *
     * Business rules:
     *   - Chỉ huỷ được booking thuộc về mình (so email).
     *   - Appointment: chỉ huỷ khi status = 'pending'.
     *   - Deposit:     chỉ huỷ khi status = 'pending' (chưa thanh toán).
     *     Khi huỷ deposit-pending → trả phòng về status=1, xoá hold_until.
     */
    public function cancelBooking(Request $request)
    {
        $request->validate([
            'booking_id' => ['required', 'integer', 'exists:booking_information,id'],
        ]);

        $booking = BookingInformation::with('room')->findOrFail($request->booking_id);

        // ── Authorization ────────────────────────────────────────────────────
        if ($booking->email !== auth()->user()->email) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
        }

        // ── Chỉ cho phép huỷ khi status = pending ───────────────────────────
        if ($booking->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Không thể huỷ đơn này. Chỉ huỷ được khi đơn đang ở trạng thái chờ.',
            ], 422);
        }

        DB::transaction(function () use ($booking) {
            // Nếu là deposit → giải phóng phòng
            if ($booking->booking_type === 'deposit' && $booking->room) {
                $booking->room->update([
                    'status'     => 1,       // trống
                    'hold_until' => null,
                ]);
            }

            $booking->update(['status' => 'cancelled']);
        });

        Log::info('[BookingController@cancelBooking] Đơn đã bị huỷ bởi người dùng', [
            'booking_id'   => $booking->id,
            'booking_type' => $booking->booking_type,
            'user_email'   => auth()->user()->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => $booking->booking_type === 'appointment'
                ? 'Đã huỷ lịch hẹn xem phòng thành công.'
                : 'Đã huỷ đặt cọc. Phòng được trả về trạng thái trống.',
        ]);
    }
    // =========================================================================

    // =========================================================================
    /**
     * Tiếp tục thanh toán khi user lỡ thoát trang checkout.
     *
     * GET /booking/resume/{booking_code}
     *
     * Logic:
     *   - Nếu hold_until còn hiệu lực → redirect về checkout (tạo link VNPay mới).
     *   - Nếu hold_until đã hết → huỷ booking, trả phòng, báo lỗi.
     */
    public function resumePayment(string $booking_code)
    {
        $booking = BookingInformation::with('room')
            ->where('booking_code', $booking_code)
            ->where('email', auth()->user()->email)
            ->where('booking_type', 'deposit')
            ->where('status', 'pending')
            ->first();

        if (! $booking) {
            return redirect()->route('booking.index')
                ->with('error', 'Không tìm thấy giao dịch cần tiếp tục hoặc giao dịch đã được xử lý.');
        }

        $room = $booking->room;

        // Kiểm tra thời gian giữ chỗ
        if ($room && $room->hold_until && $room->hold_until->lt(now())) {
            // Hết giờ → huỷ tự động
            DB::transaction(function () use ($booking, $room) {
                $room->update(['status' => 1, 'hold_until' => null]);
                $booking->update(['status' => 'cancelled']);
            });

            return redirect()->route('booking.index')
                ->with('error', 'Thời gian giữ chỗ 15 phút đã hết. Vui lòng thực hiện đặt cọc lại.');
        }

        // Còn thời gian → về trang checkout
        return redirect()->route('booking.checkout', ['booking_code' => $booking_code]);
    }
    // =========================================================================

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

            // ── 5. Gửi thông báo SAU khi DB commit thành công ────────────────
            $roomForDispute = $booking->room ?? $booking->load('room')->room;

            // (A) Thông báo cho Admin (role = '1')
            $admins = User::where('role', '1')->get();
            foreach ($admins as $admin) {
                try {
                    Notification::create([
                        'user_id' => $admin->id,
                        'title'   => 'Khách hàng ' . auth()->user()->name
                                   . ' vừa gửi yêu cầu hoàn tiền cho phòng '
                                   . ($roomForDispute->name ?? 'N/A')
                                   . '. Lý do: ' . $validated['reason'],
                        'status'  => 0,
                        'link'    => route('admin.disputes.index'),
                    ]);
                } catch (\Throwable $notifEx) {
                    Log::warning('[BookingController@submitRefundRequest] Không thể tạo thông báo cho admin', [
                        'admin_id' => $admin->id,
                        'message'  => $notifEx->getMessage(),
                    ]);
                }
            }

            // (B) Thông báo cho Chủ trọ (nếu phòng có chủ trọ)
            if ($roomForDispute && $roomForDispute->chutro_id) {
                try {
                    Notification::create([
                        'user_id' => $roomForDispute->chutro_id,
                        'title'   => 'Khách hàng ' . auth()->user()->name
                                   . ' vừa gửi yêu cầu hoàn tiền cho phòng '
                                   . $roomForDispute->name . '.',
                        'status'  => 0,
                        'link'    => route('booking.show', $roomForDispute->id),
                    ]);
                } catch (\Throwable $notifEx) {
                    Log::warning('[BookingController@submitRefundRequest] Không thể tạo thông báo cho chủ trọ', [
                        'chutro_id' => $roomForDispute->chutro_id,
                        'message'   => $notifEx->getMessage(),
                    ]);
                }
            }

            // ── 6. Trả về thành công ──────────────────────────────────────────
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
