<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateInvoicePdfAndSendEmailJob;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\VnpayService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * InvoicePaymentController
 *
 * Xử lý 2 luồng thanh toán hóa đơn qua VNPAY:
 *
 *   1. GET /api/invoices/{id}/payment-url
 *      → Tạo URL checkout VNPAY (tenant gọi từ giao diện)
 *
 *   2. GET /api/webhooks/vnpay-invoice-ipn
 *      → VNPAY gọi ngầm vào đây sau khi giao dịch hoàn tất (IPN)
 *      → KHÔNG bọc middleware auth (VNPAY không gửi token)
 *
 * ┌──────────────────────────────────────────────────────────┐
 * │  LƯU Ý BẢO MẬT                                          │
 * │                                                          │
 * │  IPN endpoint phải PUBLIC (không auth).                  │
 * │  Bảo mật thực sự nằm ở verifySignature() bằng           │
 * │  HMAC-SHA512 với HashSecret chỉ server biết.             │
 * │                                                          │
 * │  Cơ chế chống race condition:                            │
 * │   - lockForUpdate() trên Invoice row                     │
 * │   - DB::transaction() bao toàn bộ xử lý                 │
 * │   - idempotency check: paid → trả 02 ngay               │
 * └──────────────────────────────────────────────────────────┘
 */
class InvoicePaymentController extends Controller
{
    // ── Prefix để phân biệt TxnRef của hóa đơn với TxnRef của deposit ──────
    // vnp_TxnRef phải unique trên toàn bộ hệ thống VNPAY.
    // Format: "INV-{invoice_id}-{timestamp}" → vừa unique vừa parse được.
    private const TXN_REF_PREFIX = 'INV-';

    // ══════════════════════════════════════════════════════════════════════
    // 1. GENERATE PAYMENT URL
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/invoices/{id}/payment-url
     *
     * Sinh VNPAY checkout URL cho hóa đơn cụ thể.
     *
     * Auth: Chỉ tenant của hóa đơn này được gọi (qua auth:api middleware).
     *
     * Response thành công:
     *   { "data": { "payment_url": "https://sandbox.vnpayment.vn/..." } }
     *
     * Các lỗi có thể:
     *   404 – Invoice không tìm thấy
     *   403 – Người dùng không phải tenant của hóa đơn
     *   422 – Hóa đơn đã paid hoặc cancelled
     */
    public function generatePaymentUrl(Request $request, int $id, VnpayService $vnpayService): JsonResponse
    {
        // ── Load Invoice với quan hệ cần thiết ───────────────────────────
        $invoice = Invoice::with(['tenant', 'contract'])->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Hóa đơn không tồn tại.'], 404);
        }

        // ── Kiểm tra quyền: chỉ tenant của hóa đơn này mới được thanh toán ─
        if (Auth::id() !== $invoice->tenant_id) {
            return response()->json([
                'message' => 'Bạn không có quyền thanh toán hóa đơn này.',
            ], 403);
        }

        // ── Kiểm tra trạng thái hóa đơn ─────────────────────────────────
        if ($invoice->status === 'paid') {
            return response()->json([
                'message' => 'Hóa đơn đã được thanh toán đầy đủ.',
            ], 422);
        }

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'message' => 'Hóa đơn đã bị hủy, không thể thanh toán.',
            ], 422);
        }

        // ── Tính số tiền còn lại cần thanh toán ──────────────────────────
        // Nếu partial → cho phép thanh toán phần còn lại.
        // Nếu unpaid/overdue → thanh toán toàn bộ.
        $amountToPayVnd = (int) ceil($invoice->remainingAmount());

        if ($amountToPayVnd <= 0) {
            return response()->json([
                'message' => 'Hóa đơn không có số tiền cần thanh toán.',
            ], 422);
        }

        // ── Tạo TxnRef duy nhất ───────────────────────────────────────────
        // Format: INV-{invoice_id}-{timestamp_milliseconds}
        // Thêm timestamp để đảm bảo unique kể cả khi tenant thanh toán lại
        // sau khi giao dịch thất bại.
        $txnRef = self::TXN_REF_PREFIX . $invoice->id . '-' . now()->getTimestampMs();

        // ── Tạo bản ghi PaymentTransaction ở trạng thái 'pending' ─────────
        // Trước khi redirect sang VNPAY, ghi lại intent để có thể audit
        // ngay cả khi user tắt trình duyệt sau khi redirect.
        $paymentTransaction = DB::transaction(function () use (
            $invoice, $txnRef, $amountToPayVnd
        ) {
            return PaymentTransaction::create([
                'invoice_id'       => $invoice->id,
                'transaction_code' => $txnRef,
                'amount'           => $amountToPayVnd,
                'payment_method'   => 'vnpay',
                'note'             => "Thanh toan hoa don {$invoice->invoice_code} thang {$invoice->billing_month}",
                // status mặc định = 'pending' (không trong fillable → dùng DB default)
            ]);
        });

        // ── Sinh VNPAY URL ────────────────────────────────────────────────
        // VnpayService::createPaymentUrl() tự nhân amount × 100 bên trong.
        $orderInfo  = "Thanh toan hoa don {$invoice->invoice_code}";
        $paymentUrl = $vnpayService->createPaymentUrl(
            orderId:   $txnRef,
            amount:    $amountToPayVnd,     // Truyền nguyên VNĐ, service tự ×100
            orderInfo: $orderInfo,
            ipAddr:    $request->ip(),
        );

        Log::info('[InvoicePaymentController] Generated VNPAY URL', [
            'invoice_id'   => $invoice->id,
            'txn_ref'      => $txnRef,
            'amount'       => $amountToPayVnd,
        ]);

        return response()->json([
            'data' => [
                'payment_url'       => $paymentUrl,
                'transaction_code'  => $txnRef,
                'amount'            => $amountToPayVnd,
                'invoice_code'      => $invoice->invoice_code,
                'billing_month'     => $invoice->billing_month,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. VNPAY IPN WEBHOOK
    // ══════════════════════════════════════════════════════════════════════

    /**
     * GET /api/webhooks/vnpay-invoice-ipn
     *
     * VNPAY gọi ngầm vào đây sau khi giao dịch hoàn tất.
     * Route này KHÔNG được bọc middleware auth hay CSRF.
     *
     * Luồng xử lý:
     *  1. Xác thực chữ ký HMAC-SHA512 (giống hệt PaymentController::vnpayIpn)
     *  2. Parse vnp_TxnRef → tìm PaymentTransaction → tìm Invoice
     *  3. lockForUpdate() trên Invoice → chống race condition
     *  4. Idempotency: Invoice đã paid → trả 02
     *  5. So sánh amount → cập nhật status:
     *     - paid_amount >= total_amount → 'paid'
     *     - paid_amount <  total_amount → 'partial'
     *  6. Cập nhật PaymentTransaction với kết quả
     *  7. Trả {"RspCode":"00","Message":"Confirm Success"}
     */
    public function vnpayIpn(Request $request, VnpayService $vnpayService, WalletService $walletService): JsonResponse
    {
        $inputData = $request->all();

        Log::info('[InvoiceIPN] Received VNPAY IPN webhook', [
            'txn_ref'     => $inputData['vnp_TxnRef']     ?? null,
            'response_code' => $inputData['vnp_ResponseCode'] ?? null,
            'amount'      => $inputData['vnp_Amount']     ?? null,
        ]);

        try {
            // ── 1. Xác thực chữ ký ────────────────────────────────────────
            // Dùng ĐÚNG method verifySignature() như PaymentController hiện tại.
            // Không tự implement lại để tránh sai lệch.
            if (! $vnpayService->verifySignature($inputData)) {
                Log::warning('[InvoiceIPN] Invalid signature', ['input' => $inputData]);

                return response()->json([
                    'RspCode' => '97',
                    'Message' => 'Invalid signature',
                ]);
            }

            // ── 2. Parse dữ liệu từ VNPAY ─────────────────────────────────
            $txnRef   = $inputData['vnp_TxnRef'] ?? '';
            // VNPAY gửi amount đã ×100 → chia lại để được số VNĐ thật
            $vnpAmountVnd = ($inputData['vnp_Amount'] ?? 0) / 100;
            $vnpTransactionNo = $inputData['vnp_TransactionNo'] ?? null;
            $responseCode     = $inputData['vnp_ResponseCode'] ?? '99';

            // ── 3. Tìm PaymentTransaction từ TxnRef ───────────────────────
            // TxnRef của chúng ta có dạng: INV-{invoice_id}-{timestamp}
            $paymentTxn = PaymentTransaction::where('transaction_code', $txnRef)
                ->first();

            if (! $paymentTxn) {
                Log::error('[InvoiceIPN] PaymentTransaction not found', ['txn_ref' => $txnRef]);

                return response()->json([
                    'RspCode' => '01',
                    'Message' => 'Order not found',
                ]);
            }

            // ── 4. Toàn bộ xử lý bên trong DB::transaction + lockForUpdate ──
            return DB::transaction(function () use (
                $paymentTxn, $vnpAmountVnd, $vnpTransactionNo,
                $responseCode, $inputData, $vnpayService, $walletService
            ) {
                // Lock Invoice row để chống race condition với IPN đồng thời
                $invoice = Invoice::lockForUpdate()->find($paymentTxn->invoice_id);

                if (! $invoice) {
                    return response()->json([
                        'RspCode' => '01',
                        'Message' => 'Order not found',
                    ]);
                }

                // ── 4a. Idempotency: hóa đơn đã paid → trả 02 ngay ───────
                if ($invoice->status === 'paid') {
                    Log::info('[InvoiceIPN] Invoice already paid', ['invoice_id' => $invoice->id]);

                    return response()->json([
                        'RspCode' => '02',
                        'Message' => 'Order already confirmed',
                    ]);
                }

                // ── 4b. Xử lý theo ResponseCode từ VNPAY ──────────────────
                if ($vnpayService->isSuccess($inputData)) {
                    // ── VNPAY XÁC NHẬN THÀNH CÔNG ─────────────────────────

                    // Cập nhật PaymentTransaction → success
                    $paymentTxn->markAsSuccess([
                        'vnp_TransactionNo' => $vnpTransactionNo,
                        'vnp_ResponseCode'  => $responseCode,
                        'raw'               => $inputData,
                    ]);

                    // Tính tổng đã thanh toán (bao gồm cả lần này)
                    $totalPaid = $invoice->totalPaid(); // Đã được cập nhật bởi markAsSuccess bên trên
                    // markAsSuccess() gọi save() ngay, nên totalPaid() sẽ include giao dịch này
                    // Refresh để lấy số liệu mới nhất
                    $invoice->refresh();
                    $totalPaidAfter = $invoice->totalPaid();

                    // ── So sánh: paid >= total → full payment; paid < total → partial ──
                    if ($totalPaidAfter >= (float) $invoice->total_amount) {
                        // Thanh toán ĐỦ hoặc dư → chuyển sang 'paid'
                        $invoice->transitionTo('paid');

                        // Thêm tiền vào ví escrow của chủ trọ (pending_balance)
                        $contract = $invoice->contract()->with('room')->first();
                        $landlordId = $contract ? $contract->room->chutro_id : null;
                        
                        if ($landlordId) {
                            $walletService->addPendingFunds(
                                $landlordId,
                                (float) $invoice->total_amount,
                                $invoice,
                                "Thanh toán hóa đơn {$invoice->invoice_code} (chờ giải phóng)"
                            );
                            
                            Log::info('[InvoiceIPN] Tiền đã vào escrow của chủ trọ', [
                                'invoice_id'  => $invoice->id,
                                'landlord_id' => $landlordId,
                                'amount'      => $invoice->total_amount,
                            ]);
                        }

                        Log::info('[InvoiceIPN] Invoice PAID in full', [
                            'invoice_id'   => $invoice->id,
                            'total_amount' => $invoice->total_amount,
                            'total_paid'   => $totalPaidAfter,
                        ]);

                        // Dispatch job để gửi email xác nhận đã thanh toán
                        GenerateInvoicePdfAndSendEmailJob::dispatch($invoice->id)
                            ->onQueue('invoices');

                    } else {
                        // Thanh toán THIẾU → chuyển sang 'partial'
                        // (Invoice state machine: unpaid/overdue → partial)
                        if (in_array($invoice->status, ['unpaid', 'overdue'], true)) {
                            $invoice->transitionTo('partial');
                        }
                        // Nếu đã partial thì giữ nguyên (đã xử lý idempotency ở trên)

                        Log::info('[InvoiceIPN] Invoice PARTIALLY paid', [
                            'invoice_id'    => $invoice->id,
                            'total_amount'  => $invoice->total_amount,
                            'total_paid'    => $totalPaidAfter,
                            'remaining'     => $invoice->total_amount - $totalPaidAfter,
                        ]);
                    }

                } else {
                    // ── VNPAY XÁC NHẬN THẤT BẠI ──────────────────────────
                    $paymentTxn->markAsFailed(
                        "VNPAY trả về mã lỗi: {$responseCode}",
                        $inputData,
                    );

                    Log::info('[InvoiceIPN] Payment FAILED', [
                        'invoice_id'    => $invoice->id,
                        'response_code' => $responseCode,
                    ]);
                }

                // ── 5. Trả kết quả cho VNPAY ──────────────────────────────
                // RspCode "00" = hệ thống đã ghi nhận IPN thành công.
                // Phân biệt với vnp_ResponseCode (kết quả giao dịch từ ngân hàng).
                return response()->json([
                    'RspCode' => '00',
                    'Message' => 'Confirm Success',
                ]);
            }); // end DB::transaction

        } catch (\Throwable $e) {
            Log::error('[InvoiceIPN] Unhandled exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'input'   => $inputData,
            ]);

            return response()->json([
                'RspCode' => '99',
                'Message' => 'Unknown error',
            ]);
        }
    }
}
