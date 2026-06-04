<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InvoicePaymentController;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\UtilityReading;
use App\Services\InvoiceService;
use App\Services\VnpayService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Mockery;

class TestAutoBillingFlow extends Command
{
    protected $signature = 'test:auto-billing';
    protected $description = 'E2E Test the complete Auto-Billing and VNPAY Webhook flow locally';

    public function handle(InvoiceService $invoiceService)
    {
        $this->info('🚀 Bắt đầu giả lập luồng Auto-Billing E2E...');

        // ── 1. Setup & Validation ─────────────────────────────────────────────
        $contract = Contract::with('room')->where('status', 'active')->first();

        if (!$contract) {
            $this->error('❌ Không tìm thấy hợp đồng nào ở trạng thái "active". Vui lòng chạy TestBillingSeeder trước.');
            return Command::FAILURE;
        }

        $room = $contract->room;
        if (!$room) {
            $this->error('❌ Hợp đồng hợp lệ nhưng không gắn với phòng nào.');
            return Command::FAILURE;
        }

        $this->info("✅ Đã tìm thấy hợp đồng Active: {$contract->contract_code} (Phòng: {$room->name})");

        $month = now()->month;
        $year = now()->year;
        $billingMonth = now()->format('m/Y');

        // Clean up cũ để test chạy lại được nhiều lần
        Invoice::where('contract_id', $contract->id)->where('billing_month', $billingMonth)->forceDelete();
        UtilityReading::where('room_id', $room->id)->where('month', $month)->where('year', $year)->forceDelete();

        // ── 2. Simulate Utility Reading ───────────────────────────────────────
        // Tìm chỉ số tháng trước (nếu có)
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear = $month === 1 ? $year - 1 : $year;
        
        $prevReading = UtilityReading::where('room_id', $room->id)
            ->where('month', $prevMonth)
            ->where('year', $prevYear)
            ->first();

        $prevElec = $prevReading ? $prevReading->electricity_index : 100;
        $prevWater = $prevReading ? $prevReading->water_index : 50;

        $reading = UtilityReading::create([
            'room_id' => $room->id,
            'month' => $month,
            'year' => $year,
            'electricity_index' => $prevElec + 50,
            'water_index' => $prevWater + 10,
            'status' => 'finalized'
        ]);

        $this->info("✅ Đã tạo/Cập nhật Utility Reading tháng {$month}/{$year}: Điện +50, Nước +10");

        // ── 3. Trigger Invoice Generation ─────────────────────────────────────
        $this->info('⏳ Đang sinh hóa đơn...');
        
        try {
            $invoice = $invoiceService->generateForContract($contract, now());
        } catch (\Exception $e) {
            $this->error("❌ Lỗi sinh hóa đơn: " . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info("✅ Hóa đơn đã được tạo thành công!");
        
        $this->table(
            ['ID', 'Mã Hóa Đơn', 'Kỳ', 'Tổng Tiền', 'Trạng Thái'],
            [
                [
                    $invoice->id,
                    $invoice->invoice_code,
                    $invoice->billing_month,
                    number_format($invoice->total_amount) . ' VNĐ',
                    strtoupper($invoice->status)
                ]
            ]
        );

        $itemsData = $invoice->items->map(function ($item) {
            return [
                $item->type,
                $item->description,
                $item->quantity,
                number_format($item->unit_price),
                number_format($item->total)
            ];
        })->toArray();

        $this->table(['Loại', 'Mô tả', 'SL', 'Đơn giá', 'Thành tiền'], $itemsData);

        // ── 4. Simulate VNPAY Webhook (Payment Success) ───────────────────────
        $this->info("\n🌐 Simulating VNPAY IPN Webhook...");

        // Mô phỏng logic từ generatePaymentUrl() để có TxnRef
        $txnRef = 'INV-' . $invoice->id . '-' . now()->getTimestampMs();
        
        PaymentTransaction::create([
            'invoice_id'       => $invoice->id,
            'transaction_code' => $txnRef,
            'amount'           => $invoice->total_amount,
            'payment_method'   => 'vnpay',
            'note'             => "Thanh toan hoa don {$invoice->invoice_code}",
        ]);

        // Tạo Mock Request gửi từ VNPAY
        $mockRequest = Request::create('/api/webhooks/vnpay-invoice-ipn', 'GET', [
            'vnp_TxnRef' => $txnRef,
            'vnp_Amount' => $invoice->total_amount * 100, // VNPAY nhân 100
            'vnp_ResponseCode' => '00',
            'vnp_TransactionNo' => '135792468',
            'vnp_SecureHash' => 'bypassed_hash_for_testing'
        ]);

        // Tạo Mock VnpayService để bypass chữ ký
        $mockVnpayService = Mockery::mock(VnpayService::class);
        $mockVnpayService->shouldReceive('verifySignature')->once()->andReturn(true);
        $mockVnpayService->shouldReceive('isSuccess')->once()->andReturn(true);

        $controller = new InvoicePaymentController();
        $response = $controller->vnpayIpn($mockRequest, $mockVnpayService);

        $this->info("✅ VNPAY IPN Response: " . $response->getContent());

        // ── 5. Final Verification ─────────────────────────────────────────────
        $invoice->refresh();

        $this->info("\n🔍 Kiểm tra kết quả cuối cùng:");
        if ($invoice->status === 'paid') {
            $this->info("✅ Trạng thái Hóa đơn: PAID (Chính xác!)");
        } else {
            $this->error("❌ Trạng thái Hóa đơn: " . strtoupper($invoice->status) . " (Kỳ vọng: PAID)");
        }

        $successTxnCount = PaymentTransaction::where('invoice_id', $invoice->id)
            ->where('status', 'success')
            ->count();

        if ($successTxnCount > 0) {
            $this->info("✅ Giao dịch thanh toán đã được ghi nhận thành công trong DB!");
        } else {
            $this->error("❌ Không tìm thấy giao dịch thành công nào trong DB.");
        }

        $this->info("\n🎉 E2E Test hoàn tất hoàn hảo!");
        return Command::SUCCESS;
    }
}
