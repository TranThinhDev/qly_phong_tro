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
     * Tạo bản nháp hợp đồng (Draft)
     * Yêu cầu: validate dữ liệu khắt khe (giá, cọc > 0).
     */
    public function createDraft(Request $request)
    {
        // 1. Validate dữ liệu đầu vào
        $validated = $request->validate([
            'tenant_id'      => 'required|exists:users,id',
            'room_id'        => 'required|exists:rooms,id',
            'start_date'     => 'required|date|after_or_equal:today',
            'end_date'       => 'nullable|date|after:start_date',
            // Giá thuê và cọc phải > 0
            'monthly_rent'   => 'required|numeric|gt:0',
            'deposit_amount' => 'required|numeric|gt:0',
            // Bổ sung validate các chỉ số nếu cần (ở đây ví dụ điện nước >= 0 nếu có gửi lên)
            'electric_index' => 'nullable|numeric|gte:0',
            'water_index'    => 'nullable|numeric|gte:0',
            'terms_content'  => 'nullable|string',
            'notes'          => 'nullable|string',
        ], [
            'monthly_rent.gt'   => 'Giá thuê hàng tháng phải lớn hơn 0.',
            'deposit_amount.gt' => 'Số tiền cọc phải lớn hơn 0.',
            'electric_index.gte'=> 'Chỉ số điện phải lớn hơn hoặc bằng 0.',
            'water_index.gte'   => 'Chỉ số nước phải lớn hơn hoặc bằng 0.',
        ]);

        // Đảm bảo người tạo là chủ trọ (hoặc admin tùy logic dự án)
        $landlordId = Auth::id(); // Lấy ID người đang đăng nhập (chủ trọ)
        
        // Có thể thêm bước kiểm tra room_id này có đúng thuộc về landlordId không
        $room = Room::where('id', $validated['room_id'])
                    ->where('chutro_id', $landlordId)
                    ->first();
                    
        if (!$room) {
            return response()->json(['error' => 'Phòng không tồn tại hoặc bạn không có quyền.'], 403);
        }

        // Sinh mã hợp đồng duy nhất
        $contractCode = 'CT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

        // 2. Tạo record với status = 'draft'
        $contract = Contract::create([
            'contract_code'  => $contractCode,
            'landlord_id'    => $landlordId,
            'tenant_id'      => $validated['tenant_id'],
            'room_id'        => $validated['room_id'],
            'start_date'     => $validated['start_date'],
            'end_date'       => $validated['end_date'],
            'monthly_rent'   => $validated['monthly_rent'],
            'deposit_amount' => $validated['deposit_amount'],
            'terms_content'  => $validated['terms_content'] ?? null,
            'notes'          => $validated['notes'] ?? null,
            // Trạng thái mặc định từ DB/Model đã là 'draft', nhưng có thể set rõ ràng
            // 'status' => 'draft', (Không cần thiết nếu Model/DB đã có default, và status không ở $fillable)
        ]);

        // Trả về response (hoặc redirect)
        return response()->json([
            'message' => 'Tạo nháp hợp đồng thành công.',
            'data'    => $contract
        ], 201);
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

        // Kiểm tra file có tồn tại không
        if (!Storage::exists($filePath)) {
            // Tùy chọn: tự động tạo lại nếu chưa có
            // $this->generateAndSavePDF($contractId);
            abort(404, 'File hợp đồng chưa được tạo.');
        }

        // Stream file (xem trực tiếp trên trình duyệt) hoặc Download
        // Trả về file từ storage, đảm bảo an toàn vì không nằm trong /public
        return Storage::download($filePath, $fileName, [
            'Content-Type' => 'application/pdf',
        ]);
        
        // Nếu muốn hiển thị inline trên trình duyệt:
        // return response()->file(storage_path('app/' . $filePath));
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
