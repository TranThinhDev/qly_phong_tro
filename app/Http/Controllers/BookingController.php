<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BookingInformation;
use Illuminate\Support\Facades\Auth;
use App\Models\Room;
use Barryvdh\DomPDF\Facade\Pdf;
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
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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
