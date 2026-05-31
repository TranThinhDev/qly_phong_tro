<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use App\Services\VnpayService;

class ContractController extends Controller
{
    /**
     * Hiển thị giao diện Tạo Hợp đồng cho Chủ trọ
     */
    public function create()
    {
        $landlordId = Auth::id();
        
        // Lấy danh sách các phòng thuộc sở hữu của chủ trọ
        // (Có thể thêm điều kiện phòng đang trống nếu bạn có cột trạng thái cho thuê)
        $rooms = Room::where('chutro_id', $landlordId)->get();
        
        return view('contracts.create', compact('rooms'));
    }

    /**
     * Khởi tạo bản nháp hợp đồng (Draft) - Manual Flow (Khách vãng lai)
     */
    public function store(Request $request)
    {
        // 1. Validate dữ liệu đầu vào (Không cần tenant_id vì ta sẽ tự xử lý)
        $validated = $request->validate([
            'tenant_name'    => 'required|string|max:255',
            'tenant_email'   => 'required|email|max:255',
            'tenant_phone'   => 'required|string|max:20',
            'room_id'        => 'required|exists:rooms,id',
            'start_date'     => 'required|date|after_or_equal:today',
            'end_date'       => 'nullable|date|after:start_date',
            'monthly_rent'   => 'required|numeric|gt:0',
            'deposit_amount' => 'required|numeric|gt:0',
            'terms_content'  => 'nullable|string',
            'notes'          => 'nullable|string',
        ], [
            'monthly_rent.gt'   => 'Giá thuê hàng tháng phải lớn hơn 0.',
            'deposit_amount.gt' => 'Số tiền cọc phải lớn hơn 0.',
        ]);

        $landlordId = Auth::id(); 
        
        $room = Room::where('id', $validated['room_id'])
                    ->where('chutro_id', $landlordId)
                    ->first();
                    
        if (!$room) {
            return response()->json(['error' => 'Phòng không tồn tại hoặc bạn không có quyền.'], 403);
        }

        try {
            DB::beginTransaction();

            // 2. Xử lý Logic tạo User
            $plainPassword = null;
            // Ưu tiên tìm theo Email hoặc SĐT
            $tenant = User::where('email', $validated['tenant_email'])
                          ->orWhere('phone', $validated['tenant_phone'])
                          ->first();

            if (!$tenant) {
                // Khách hàng hoàn toàn mới -> Tự động tạo tài khoản
                $plainPassword = Str::random(8); // Mật khẩu raw 8 ký tự ngẫu nhiên
                
                $tenant = User::create([
                    'name'     => $validated['tenant_name'],
                    'email'    => $validated['tenant_email'],
                    'phone'    => $validated['tenant_phone'],
                    'password' => bcrypt($plainPassword),
                    // 'role' => 'tenant' // Bổ sung role nếu hệ thống của bạn có phân quyền
                ]);
            }

            // 3. Sinh mã hợp đồng duy nhất và Tạo Hợp Đồng
            $contractCode = 'CT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

            $contract = Contract::create([
                'contract_code'  => $contractCode,
                'landlord_id'    => $landlordId,
                'tenant_id'      => $tenant->id,
                'room_id'        => $validated['room_id'],
                'start_date'     => $validated['start_date'],
                'end_date'       => $validated['end_date'] ?? null,
                'monthly_rent'   => $validated['monthly_rent'],
                'deposit_amount' => $validated['deposit_amount'],
                'terms_content'  => $validated['terms_content'] ?? null,
                'notes'          => $validated['notes'] ?? null,
            ]);

            DB::commit();

            // 4. Gửi Email thông báo (Đưa vào Queue)
            \Illuminate\Support\Facades\Mail::to($tenant->email)->send(
                new \App\Mail\SendContractAndAccountInfoMail($contract, $tenant, $plainPassword)
            );

            return response()->json([
                'message' => 'Khởi tạo hợp đồng thành công.',
                'data'    => $contract
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Đã xảy ra lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Tạo file PDF từ View và lưu vào Storage bảo mật
     */
    public function generateAndSavePDF($contractId)
    {
        $contract = Contract::with(['tenant', 'landlord', 'room'])->findOrFail($contractId);

        // Chuẩn bị dữ liệu cho View
        $data = [
            'contract' => $contract,
            'tenant'   => $contract->tenant,
            'landlord' => $contract->landlord,
            'room'     => $contract->room,
            'date'     => now()->format('d/m/Y'),
        ];

        // Tạo PDF từ blade view
        $pdf = Pdf::loadView('contracts.pdf', $data);

        // Tên file lưu trữ
        $fileName = 'contract_' . $contract->contract_code . '.pdf';
        
        // Lưu vào thư mục bảo mật: storage/app/contracts/ (không public)
        $filePath = 'contracts/' . $fileName;
        
        // Put content vào local storage
        Storage::put($filePath, $pdf->output());

        return response()->json([
            'message' => 'Đã tạo và lưu file PDF thành công.',
            'file_path' => $filePath // Có thể lưu filePath này vào bảng contracts nếu cần
        ]);
    }

    /**
     * Stream hoặc Download file PDF
     * Yêu cầu kiểm tra quyền truy cập khắt khe
     */
    public function downloadPDF($contractId)
    {
        $contract = Contract::findOrFail($contractId);
        $userId = Auth::id();

        // Kiểm tra quyền: Chỉ landlord hoặc tenant của hợp đồng này mới được tải
        if ($contract->landlord_id !== $userId && $contract->tenant_id !== $userId) {
            abort(403, 'Bạn không có quyền xem hoặc tải xuống hợp đồng này.');
        }

        $fileName = 'contract_' . $contract->contract_code . '.pdf';
        $filePath = 'contracts/' . $fileName;

        // Kiểm tra file có tồn tại không, nếu chưa thì tự tạo để tránh 404
        if (!Storage::exists($filePath)) {
            $this->generateAndSavePDF($contractId);
        }

        // Nếu có tham số ?inline=1 thì hiển thị trực tiếp trên trình duyệt (phù hợp cho iframe)
        if (request()->has('inline')) {
            return response()->file(storage_path('app/' . $filePath), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $fileName . '"'
            ]);
        }

        // Ngược lại thì tải xuống
        return Storage::download($filePath, $fileName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Hiển thị giao diện ký hợp đồng dành cho Khách thuê (Tenant)
     */
    public function showSignPage($contractId)
    {
        $contract = Contract::with(['tenant', 'landlord', 'room'])->findOrFail($contractId);
        $userId = Auth::id();

        // 1. Kiểm tra quyền: Chỉ khách thuê (tenant) của hợp đồng này mới được truy cập
        if ($contract->tenant_id !== $userId) {
            abort(403, 'Bạn không có quyền truy cập trang ký hợp đồng này.');
        }

        // 2. Kiểm tra trạng thái: Hợp đồng phải ở trạng thái draft (chờ ký)
        if ($contract->status !== 'draft') {
            return redirect()->back()->with('error', 'Hợp đồng này đã được ký hoặc đang chờ thanh toán.');
        }

        return view('contracts.sign', compact('contract'));
    }

    /**
     * Khách thuê (Tenant) ký hợp đồng điện tử và chuyển hướng sang VNPAY
     */
    public function agreeAndPay(Request $request, $contractId, VnpayService $vnpayService)
    {
        $contract = Contract::findOrFail($contractId);
        $userId = Auth::id();

        // 1. Kiểm tra quyền (chỉ tenant mới được ký)
        if ($contract->tenant_id !== $userId) {
            abort(403, 'Chỉ khách thuê mới có quyền ký hợp đồng này.');
        }

        // 2. Kiểm tra trạng thái hợp đồng
        if ($contract->status !== 'draft') {
            return response()->json(['error' => 'Hợp đồng không ở trạng thái chờ ký.'], 400);
        }

        try {
            DB::beginTransaction();

            // 3. Ghi nhận Clickwrap (chữ ký điện tử - Immutable)
            // Phương thức recordClickwrap sẽ ném exception nếu đã ký rồi.
            $contract->recordClickwrap($request->ip(), $request->userAgent());

            // 4. Chuyển trạng thái hợp đồng
            $contract->transitionTo('pending_payment');

            // 5. Tạo giao dịch (transaction) thanh toán cọc
            $transactionCode = 'TXN-' . time() . '-' . strtoupper(Str::random(6));
            $transaction = $contract->transactions()->create([
                'transaction_code' => $transactionCode,
                'payer_id'         => $userId,
                'type'             => 'deposit_payment',
                'amount'           => $contract->deposit_amount,
                'currency'         => 'VND',
                'status'           => 'pending',
                'note'             => 'Thanh toán tiền cọc hợp đồng ' . $contract->contract_code,
            ]);

            DB::commit();

            // 6. Chuyển hướng sang VNPAY
            $orderInfo = "Thanh toan tien coc hop dong " . $contract->contract_code;
            $paymentUrl = $vnpayService->createPaymentUrl(
                $transactionCode,
                (float) $contract->deposit_amount,
                $orderInfo,
                $request->ip()
            );

            // Trả về redirect URL
            return redirect($paymentUrl);

        } catch (\LogicException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Đã xảy ra lỗi: ' . $e->getMessage()], 500);
        }
    }
}
