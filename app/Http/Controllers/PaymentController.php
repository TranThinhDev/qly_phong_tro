<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction;
use App\Models\Contract;
use App\Models\Room;
use App\Services\VnpayService;

class PaymentController extends Controller
{
    /**
     * IPN Webhook: VNPay gọi ngầm vào URL này để thông báo kết quả thanh toán
     * Route này KHÔNG được bọc middleware auth hay csrf
     */
    public function vnpayIpn(Request $request, VnpayService $vnpayService, \App\Services\WalletService $walletService)
    {
        $inputData = $request->all();
        $transactionCode = $inputData['vnp_TxnRef'] ?? '';
        
        // Router: Phân luồng xử lý dựa trên tiền tố của mã giao dịch (vnp_TxnRef)
        if (\Illuminate\Support\Str::startsWith($transactionCode, 'INV-')) {
            Log::info('[VNPAY IPN Router] Forwarding to InvoicePaymentController', $inputData);
            return app(\App\Http\Controllers\Api\InvoicePaymentController::class)->vnpayIpn($request, $vnpayService, $walletService);
        }

        Log::info('[VNPAY IPN] Received webhook for Contract/Booking', $inputData);

        try {
            // 1. Xác thực chữ ký dữ liệu từ VNPay để đảm bảo không bị giả mạo
            if (!$vnpayService->verifySignature($inputData)) {
                return response()->json([
                    'RspCode' => '97',
                    'Message' => 'Invalid signature'
                ]);
            }

            $vnpAmount       = ($inputData['vnp_Amount'] ?? 0) / 100; // VNPay gửi sang là x100

            // 2. Tìm Transaction và Lock Row để chống Race Condition
            // Bắt buộc nằm trong DB::transaction để lock có hiệu lực
            return DB::transaction(function () use ($inputData, $transactionCode, $vnpAmount, $vnpayService, $walletService) {
                
                // Dùng lockForUpdate() để block các request đồng thời truy cập vào record này
                $transaction = Transaction::where('transaction_code', $transactionCode)
                                          ->lockForUpdate()
                                          ->first();

                // 3. Kiểm tra tính hợp lệ của giao dịch
                if (!$transaction) {
                    return response()->json([
                        'RspCode' => '01',
                        'Message' => 'Order not found'
                    ]);
                }

                // Kiểm tra số tiền có khớp không
                if ($transaction->amount != $vnpAmount) {
                    return response()->json([
                        'RspCode' => '04',
                        'Message' => 'Invalid amount'
                    ]);
                }

                // Kiểm tra xem giao dịch đã được xử lý chưa (Idempotency)
                if ($transaction->status !== 'pending') {
                    return response()->json([
                        'RspCode' => '02',
                        'Message' => 'Order already confirmed'
                    ]);
                }

                $contract = $transaction->contract;
                
                if (!$contract) {
                     return response()->json([
                        'RspCode' => '99',
                        'Message' => 'Contract not found'
                    ]);
                }

                // 4. Xử lý logic tùy theo kết quả thanh toán từ VNPay
                if ($vnpayService->isSuccess($inputData)) {
                    // THÀNH CÔNG:

                    // a. Cập nhật trạng thái giao dịch
                    $transaction->markAsCompleted($inputData);
                    $transaction->gateway_transaction_id = $inputData['vnp_TransactionNo'];
                    $transaction->save();

                    // b. Cập nhật hợp đồng thành 'active'
                    $contract->transitionTo('active');

                    // c. Cập nhật trạng thái phòng (ví dụ: status = 3 để ẩn khỏi danh sách tìm kiếm/đã cho thuê)
                    if ($contract->room) {
                        $contract->room->update(['status' => 3]);
                    }

                    // d. Cộng tiền cọc thẳng vào ví khả dụng (available_balance) của Chủ trọ
                    $walletService->topUp(
                        $contract->landlord_id,
                        (float) $transaction->amount,
                        $contract,
                        "Thanh toán cọc hợp đồng cho phòng " . ($contract->room ? $contract->room->name : '')
                    );

                } else {
                    // THẤT BẠI:
                    
                    // a. Đánh dấu giao dịch lỗi
                    $transaction->markAsFailed('Thanh toán thất bại từ VNPAY');
                    $transaction->gateway_transaction_id = $inputData['vnp_TransactionNo'] ?? null;
                    $transaction->gateway_response = $inputData;
                    $transaction->save();

                    // b. Hợp đồng quay về trạng thái 'draft' để có thể thanh toán lại
                    if ($contract->status === 'pending_payment') {
                         $contract->transitionTo('draft');
                    }
                }

                // Trả về kết quả thành công cho VNPay (RspCode 00 có nghĩa là tao đã ghi nhận IPN thành công)
                return response()->json([
                    'RspCode' => '00',
                    'Message' => 'Confirm Success'
                ]);

            }); // End DB::transaction

        } catch (\Exception $e) {
            Log::error('[VNPAY IPN] Lỗi xử lý webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $inputData
            ]);
            
            return response()->json([
                'RspCode' => '99',
                'Message' => 'Unknow error'
            ]);
        }
    }
}
