<?php

namespace App\Http\Requests;

use App\Rules\IndexNotDecreased;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * FormRequest: StoreUtilityReadingRequest
 *
 * Xác thực dữ liệu đầu vào khi chủ trọ nhập chỉ số điện/nước.
 *
 * Rules:
 *   room_id            – bắt buộc, tồn tại trong bảng rooms,
 *                        và phải thuộc sở hữu của landlord đang đăng nhập.
 *   month              – bắt buộc, số nguyên 1-12.
 *   year               – bắt buộc, số nguyên 2020-2099.
 *   electricity_index  – bắt buộc, số nguyên >= 0,
 *                        >= chỉ số điện tháng trước (IndexNotDecreased rule).
 *   water_index        – bắt buộc, số nguyên >= 0,
 *                        >= chỉ số nước tháng trước.
 *   evidence_image     – tùy chọn, file ảnh, tối đa 2MB.
 */
class StoreUtilityReadingRequest extends FormRequest
{
    /**
     * Chỉ landlord (chủ trọ) đã đăng nhập mới được nhập chỉ số.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $roomId = (int) $this->input('room_id');
        $month  = (int) $this->input('month');
        $year   = (int) $this->input('year');

        return [
            // ── Phòng ──────────────────────────────────────────────────────
            'room_id' => [
                'required',
                'integer',
                'exists:rooms,id',
            ],

            // ── Kỳ ghi chỉ số ──────────────────────────────────────────────
            'month' => [
                'required',
                'integer',
                'between:1,12',
            ],
            'year' => [
                'required',
                'integer',
                'between:2020,2099',
            ],

            // ── Chỉ số điện ───────────────────────────────────────────────
            'electricity_index' => [
                'required',
                'integer',
                'min:0',
                new IndexNotDecreased($roomId, 'electricity_index', $month, $year),
            ],

            // ── Chỉ số nước ───────────────────────────────────────────────
            'water_index' => [
                'required',
                'integer',
                'min:0',
                new IndexNotDecreased($roomId, 'water_index', $month, $year),
            ],

            // ── Ảnh chứng minh Điện ────────────────────────────────────────────
            'electricity_evidence_image' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048',
            ],

            // ── Ảnh chứng minh Nước ────────────────────────────────────────────
            'water_evidence_image' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required'           => 'Vui lòng chọn phòng.',
            'room_id.exists'             => 'Phòng không tồn tại.',
            'month.required'             => 'Vui lòng nhập tháng.',
            'month.between'              => 'Tháng phải từ 1 đến 12.',
            'year.required'              => 'Vui lòng nhập năm.',
            'year.between'               => 'Năm không hợp lệ.',
            'electricity_index.required' => 'Vui lòng nhập chỉ số điện.',
            'electricity_index.integer'  => 'Chỉ số điện phải là số nguyên.',
            'electricity_index.min'      => 'Chỉ số điện không được âm.',
            'water_index.required'       => 'Vui lòng nhập chỉ số nước.',
            'water_index.integer'        => 'Chỉ số nước phải là số nguyên.',
            'water_index.min'            => 'Chỉ số nước không được âm.',
            'electricity_evidence_image.image' => 'Ảnh đồng hồ điện phải là định dạng hình ảnh.',
            'electricity_evidence_image.mimes' => 'Ảnh đồng hồ điện chỉ hỗ trợ: jpeg, jpg, png, webp.',
            'electricity_evidence_image.max'   => 'Ảnh đồng hồ điện không được vượt quá 2MB.',
            'water_evidence_image.image' => 'Ảnh đồng hồ nước phải là định dạng hình ảnh.',
            'water_evidence_image.mimes' => 'Ảnh đồng hồ nước chỉ hỗ trợ: jpeg, jpg, png, webp.',
            'water_evidence_image.max'   => 'Ảnh đồng hồ nước không được vượt quá 2MB.',
        ];
    }

    /**
     * Trả về JSON 422 thay vì redirect khi validation thất bại.
     * Giống pattern của StoreBookingRequest trong codebase.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
