<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\UtilityReading;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * InvoiceService – Nghiệp vụ sinh và quản lý hóa đơn hàng tháng
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  NGUYÊN TẮC THIẾT KẾ                                    │
 * │                                                         │
 * │  1. Mỗi hành động liên quan đến tiền hoặc trạng thái   │
 * │     đều chạy trong DB::transaction().                   │
 * │                                                         │
 * │  2. generateMonthlyInvoices() xử lý từng hợp đồng       │
 * │     trong transaction riêng → lỗi 1 hợp đồng không     │
 * │     rollback toàn bộ batch.                             │
 * │                                                         │
 * │  3. Idempotency: kiểm tra invoice đã tồn tại trước khi  │
 * │     tạo (UNIQUE constraint + service-level check).      │
 * │                                                         │
 * │  4. Utility readings vắng mặt không dừng job:           │
 * │     vẫn sinh invoice với tiền thuê, bỏ qua utility      │
 * │     items và ghi flag 'utility_missing = true'.         │
 * └─────────────────────────────────────────────────────────┘
 */
class InvoiceService
{
    // ── Cấu hình nghiệp vụ ───────────────────────────────────────────────
    /** Hạn thanh toán: ngày bao nhiêu của tháng billing */
    private const DUE_DAY = 10;

    /** Phí phạt trễ hạn: % trên total_amount */
    private const LATE_FEE_RATE = 0.05; // 5%

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Sinh hóa đơn cho TẤT CẢ hợp đồng đang active trong một tháng.
     *
     * Được gọi từ RunMonthlyBilling command vào ngày 1 hàng tháng.
     *
     * @param  Carbon|null $billingDate  Ngày tính cho kỳ nào (mặc định = tháng hiện tại)
     * @return array{
     *     generated: Invoice[],
     *     skipped:   int[],
     *     failed:    array<int, string>
     * }
     */
    public function generateMonthlyInvoices(?Carbon $billingDate = null): array
    {
        $billingDate = $billingDate ?? now();
        $month       = (int) $billingDate->format('m');
        $year        = (int) $billingDate->format('Y');
        $billingMonth = $billingDate->format('m/Y'); // e.g. "06/2026"

        Log::info("[InvoiceService] Bắt đầu sinh hóa đơn kỳ {$billingMonth}");

        $result = [
            'generated' => [],
            'skipped'   => [],
            'failed'    => [],
        ];

        // Chunkby 50 để không load toàn bộ bảng contracts vào memory
        Contract::with([
            'tenant:id,name,email,PhoneNumber',
            'landlord:id,name,email',
            'room:id,name,electric,water,add_ons',
        ])
        ->where('status', 'active')
        ->where(function ($q) use ($billingDate) {
            // Bỏ qua hợp đồng đã hết hạn (end_date không null và đã qua)
            $q->whereNull('end_date')
              ->orWhere('end_date', '>=', $billingDate->toDateString());
        })
        ->chunkById(50, function ($contracts) use (
            $month, $year, $billingMonth, &$result
        ) {
            foreach ($contracts as $contract) {
                $this->processContract(
                    $contract, $month, $year, $billingMonth, $result
                );
            }
        });

        Log::info("[InvoiceService] Hoàn thành kỳ {$billingMonth}", [
            'generated' => count($result['generated']),
            'skipped'   => count($result['skipped']),
            'failed'    => count($result['failed']),
        ]);

        return $result;
    }

    /**
     * Sinh hóa đơn cho MỘT hợp đồng cụ thể.
     *
     * Dùng khi tạo thủ công hoặc test.
     *
     * @throws \RuntimeException Nếu invoice đã tồn tại cho kỳ này
     */
    public function generateForContract(Contract $contract, Carbon $billingDate): Invoice
    {
        $month        = (int) $billingDate->format('m');
        $year         = (int) $billingDate->format('Y');
        $billingMonth = $billingDate->format('m/Y');

        // Kiểm tra idempotency
        $existing = Invoice::where('contract_id', $contract->id)
            ->where('billing_month', $billingMonth)
            ->first();

        if ($existing) {
            throw new \RuntimeException(
                "Hóa đơn kỳ {$billingMonth} cho hợp đồng #{$contract->contract_code} đã tồn tại (ID: {$existing->id})."
            );
        }

        return DB::transaction(function () use ($contract, $month, $year, $billingMonth) {
            return $this->buildInvoice($contract, $month, $year, $billingMonth);
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE – CORE LOGIC
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Xử lý một hợp đồng trong batch generateMonthlyInvoices().
     * Mỗi hợp đồng chạy trong transaction riêng.
     */
    private function processContract(
        Contract $contract,
        int $month,
        int $year,
        string $billingMonth,
        array &$result
    ): void {
        try {
            // ── Idempotency check ─────────────────────────────────────────
            $alreadyExists = Invoice::where('contract_id', $contract->id)
                ->where('billing_month', $billingMonth)
                ->exists();

            if ($alreadyExists) {
                $result['skipped'][] = $contract->id;
                Log::info("[InvoiceService] SKIP contract #{$contract->id} – invoice đã tồn tại.");
                return;
            }

            // ── Sinh hóa đơn trong transaction ───────────────────────────
            $invoice = DB::transaction(function () use ($contract, $month, $year, $billingMonth) {
                return $this->buildInvoice($contract, $month, $year, $billingMonth);
            });

            $result['generated'][] = $invoice;

        } catch (\Throwable $e) {
            // Lỗi từng hợp đồng được log riêng, không dừng cả batch
            $result['failed'][$contract->id] = $e->getMessage();
            Log::error("[InvoiceService] LỖI contract #{$contract->id}", [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Xây dựng Invoice + InvoiceItems bên trong một DB transaction.
     *
     * Thuật toán:
     *  1. Tạo Invoice (header) với total_amount = 0 tạm thời.
     *  2. Tạo item: rent (luôn có).
     *  3. Tìm utility readings → tạo item electricity + water nếu có.
     *  4. Finalize readings → chuyển status = 'finalized'.
     *  5. Cập nhật total_amount = SUM(items.total).
     *
     * Gọi method này PHẢI nằm trong DB::transaction().
     */
    private function buildInvoice(
        Contract $contract,
        int $month,
        int $year,
        string $billingMonth
    ): Invoice {
        $room   = $contract->room;
        $tenant = $contract->tenant;

        // ── 1. Tạo Invoice header ─────────────────────────────────────────
        $dueDate = Carbon::create($year, $month, self::DUE_DAY);

        $invoice = Invoice::create([
            'invoice_code'  => $this->generateInvoiceCode($billingMonth),
            'contract_id'   => $contract->id,
            'tenant_id'     => $contract->tenant_id,
            'billing_month' => $billingMonth,
            'due_date'      => $dueDate->toDateString(),
            'notes'         => null,
        ]);

        // ── 2. Item: Tiền thuê (luôn tạo) ─────────────────────────────────
        $items = [];

        $items[] = $invoice->items()->create([
            'type'        => 'rent',
            'description' => "Tiền thuê phòng tháng {$billingMonth}",
            'quantity'    => 1,
            'unit_price'  => $contract->monthly_rent,
            'total'       => $contract->monthly_rent,
        ]);

        // ── 3. Items: Điện & Nước (chỉ tạo nếu có reading) ───────────────
        $utilityMissing = false;

        if ($room) {
            [$currentReading, $previousReading] = $this->getReadingPair($room->id, $month, $year);

            if ($currentReading && $previousReading) {
                // ── Điện ──────────────────────────────────────────────────
                $electricityUnits = max(0, $currentReading->electricity_index - $previousReading->electricity_index);
                $electricityPrice = (float) $room->electric; // đ/kWh
                $electricityTotal = round($electricityUnits * $electricityPrice, 2);

                if ($electricityUnits > 0) {
                    $items[] = $invoice->items()->create([
                        'type'        => 'electricity',
                        'description' => "Tiền điện tháng {$billingMonth}: "
                            . "{$electricityUnits} kWh × " . number_format($electricityPrice, 0, ',', '.') . "đ",
                        'quantity'    => $electricityUnits,
                        'unit_price'  => $electricityPrice,
                        'total'       => $electricityTotal,
                    ]);
                }

                // ── Nước ──────────────────────────────────────────────────
                $waterUnits = max(0, $currentReading->water_index - $previousReading->water_index);
                $waterPrice = (float) $room->water; // đ/m³
                $waterTotal = round($waterUnits * $waterPrice, 2);

                if ($waterUnits > 0) {
                    $items[] = $invoice->items()->create([
                        'type'        => 'water',
                        'description' => "Tiền nước tháng {$billingMonth}: "
                            . "{$waterUnits} m³ × " . number_format($waterPrice, 0, ',', '.') . "đ",
                        'quantity'    => $waterUnits,
                        'unit_price'  => $waterPrice,
                        'total'       => $waterTotal,
                    ]);
                }

                // ── Finalize reading ───────────────────────────────────────
                try {
                    $currentReading->finalize();
                } catch (\LogicException $e) {
                    // Đã được finalize trước → không sao
                    Log::info("[InvoiceService] Reading đã finalized: " . $e->getMessage());
                }

            } elseif ($currentReading && ! $previousReading) {
                // Có reading tháng này nhưng không có tháng trước
                // → không thể tính sản lượng → bỏ qua utility
                $utilityMissing = true;
                Log::warning("[InvoiceService] Không có reading tháng trước cho phòng #{$room->id}.");

            } else {
                // Không có reading tháng hiện tại → bỏ qua utility
                $utilityMissing = true;
                Log::warning("[InvoiceService] Không có utility reading tháng {$month}/{$year} cho phòng #{$room->id}.");
            }

            // ── 4. Items: Phí dịch vụ (WiFi, rác, giữ xe, ...) ──────────
            // add_ons lưu dạng JSON string: [{"name":"WiFi","price":100000}, ...]
            $this->addServiceItems($invoice, $room, $billingMonth);
        }

        // ── 5. Cập nhật total_amount ──────────────────────────────────────
        $totalAmount = $invoice->items()->sum('total');
        $invoice->forceFill([
            'total_amount'    => $totalAmount,
            // Ghi flag để email/PDF biết utility chưa có
            'notes' => $utilityMissing
                ? '[!] Chỉ số điện/nước chưa được nhập – tiền điện nước chưa tính.'
                : null,
        ])->save();

        Log::info("[InvoiceService] Sinh invoice #{$invoice->invoice_code} thành công", [
            'contract_id'   => $contract->id,
            'billing_month' => $billingMonth,
            'total_amount'  => $totalAmount,
            'items'         => count($items),
            'utility_missing' => $utilityMissing,
        ]);

        // ── Gửi Notification cho Người thuê ─────────────────────────────
        Notification::create([
            'user_id' => $contract->tenant_id,
            'title'   => "Bạn có hóa đơn mới tháng {$billingMonth} cho phòng " . ($room ? $room->name : '') . ". Vui lòng thanh toán.",
            'link'    => route('tenant.invoices.show', ['invoice' => $invoice->id]),
            'status'  => 0
        ]);

        return $invoice->load('items');
    }

    /**
     * Thêm các InvoiceItem cho phí dịch vụ (add_ons).
     *
     * add_ons của Room lưu dạng JSON string, ví dụ:
     *   '[{"name":"WiFi","price":100000},{"name":"Giữ xe","price":50000}]'
     *
     * Nếu add_ons không hợp lệ → bỏ qua, không ném exception.
     */
    private function addServiceItems(Invoice $invoice, $room, string $billingMonth): void
    {
        if (empty($room->add_ons)) {
            return;
        }

        $addOns = is_array($room->add_ons)
            ? $room->add_ons
            : json_decode($room->add_ons, true);

        if (! is_array($addOns)) {
            return;
        }

        foreach ($addOns as $service) {
            $name  = $service['name']  ?? null;
            $price = $service['price'] ?? null;

            if (! $name || ! is_numeric($price) || (float) $price <= 0) {
                continue;
            }

            $invoice->items()->create([
                'type'        => 'service',
                'description' => "{$name} tháng {$billingMonth}",
                'quantity'    => 1,
                'unit_price'  => (float) $price,
                'total'       => (float) $price,
            ]);
        }
    }

    /**
     * Lấy cặp [currentReading, previousReading] cho một phòng.
     *
     * @return array{UtilityReading|null, UtilityReading|null}
     */
    private function getReadingPair(int $roomId, int $month, int $year): array
    {
        [$prevMonth, $prevYear] = $month === 1 ? [12, $year - 1] : [$month - 1, $year];

        $current  = UtilityReading::where('room_id', $roomId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        $previous = UtilityReading::where('room_id', $roomId)
            ->where('month', $prevMonth)
            ->where('year', $prevYear)
            ->first();

        return [$current, $previous];
    }

    /**
     * Sinh mã hóa đơn duy nhất.
     * Format: INV-MM/YYYY-XXXXXX  (e.g. INV-06/2026-A3F9K2)
     *
     * UNIQUE constraint ở DB là lưới an toàn thứ 2.
     */
    private function generateInvoiceCode(string $billingMonth): string
    {
        return 'INV-' . $billingMonth . '-' . strtoupper(Str::random(6));
    }
}
