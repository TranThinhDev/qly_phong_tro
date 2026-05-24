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
     */
    public function index()
    {
        $user = Auth::user();
        $Rooms = Room::where('chutro_id', $user->id)->get();
        $BookingList =  array();
        foreach ($Rooms as $key => $value) {
            foreach ($value->getBooking as $item) {
                array_push($BookingList,$item);
            }
        }
    
        return view('frontend.booking.show', compact('BookingList'));
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

        // 2. Trỏ tới view HTML vừa tạo và truyền biến dữ liệu vào
        $pdf = Pdf::loadView('pdf.booking_receipt', compact('booking'));

        // 3. Tùy chọn: Thiết lập khổ giấy A4
        $pdf->setPaper('a4', 'portrait');

        // 4. Trả về file PDF cho trình duyệt tải xuống
        return $pdf->download('bien-nhan-dat-phong-' . $booking->id . '.pdf');
    }
}
