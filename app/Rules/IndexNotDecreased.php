<?php

namespace App\Rules;

use App\Models\UtilityReading;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rule: IndexNotDecreased
 *
 * Xác thực rằng chỉ số công-tơ điện/nước tháng này
 * phải >= chỉ số tháng trước của cùng phòng đó.
 *
 * Cách dùng trong FormRequest:
 *   new IndexNotDecreased($request->input('room_id'), 'electricity_index', $month, $year),
 */
class IndexNotDecreased implements ValidationRule
{
    /**
     * @param int    $roomId    ID phòng cần kiểm tra
     * @param string $field     Tên cột: 'electricity_index' hoặc 'water_index'
     * @param int    $month     Tháng hiện tại (1-12)
     * @param int    $year      Năm hiện tại
     */
    public function __construct(
        private readonly int    $roomId,
        private readonly string $field,
        private readonly int    $month,
        private readonly int    $year,
    ) {}

    /**
     * Chạy validation rule.
     *
     * Logic:
     *   1. Tìm bản ghi chỉ số của tháng trước (có thể khác năm nếu tháng = 1).
     *   2. Nếu không tìm thấy → đây là lần nhập đầu tiên → luôn pass.
     *   3. Nếu tìm thấy → $value phải >= giá trị tháng trước.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$prevMonth, $prevYear] = $this->previousMonthYear();

        $previous = UtilityReading::where('room_id', $this->roomId)
            ->where('month', $prevMonth)
            ->where('year', $prevYear)
            ->value($this->field);

        // Không có dữ liệu tháng trước → không cần so sánh
        if ($previous === null) {
            return;
        }

        if ((int) $value < (int) $previous) {
            $label = $this->field === 'electricity_index' ? 'điện' : 'nước';
            $fail("Chỉ số {$label} ({$value}) không được nhỏ hơn chỉ số tháng trước ({$previous}).");
        }
    }

    /**
     * Tính tháng/năm của kỳ trước.
     * Tháng 1 → tháng 12 của năm trước.
     *
     * @return array{int, int} [month, year]
     */
    private function previousMonthYear(): array
    {
        if ($this->month === 1) {
            return [12, $this->year - 1];
        }

        return [$this->month - 1, $this->year];
    }
}
