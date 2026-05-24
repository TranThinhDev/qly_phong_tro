<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Room;

class StoreBookingRequest extends FormRequest
{
    /**
     * Xác định người dùng có quyền thực hiện request này không.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Các quy tắc validation.
     *
     * appointment_date chỉ bắt buộc khi phòng KHÔNG yêu cầu đặt cọc
     * (is_deposit_required == false).
     */
    public function rules(): array
    {
        // Lấy thông tin phòng để kiểm tra is_deposit_required
        $room = Room::find($this->input('room_id'));
        $isDepositRequired = $room?->is_deposit_required ?? true;

        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],

            // required_if được thay thế bằng logic tùy chỉnh vì giá trị
            // phụ thuộc vào DB chứ không phải một field khác trong request
            'appointment_date' => array_filter([
                $isDepositRequired ? 'nullable' : 'required',
                'date',
                'after:now',
            ]),
        ];
    }

    /**
     * Thông báo lỗi tùy chỉnh (tiếng Việt).
     */
    public function messages(): array
    {
        return [
            'room_id.required'             => 'Vui lòng chọn phòng.',
            'room_id.exists'               => 'Phòng không tồn tại.',
            'appointment_date.required'    => 'Vui lòng chọn ngày hẹn xem phòng.',
            'appointment_date.date'        => 'Ngày hẹn không hợp lệ.',
            'appointment_date.after'       => 'Ngày hẹn phải sau thời điểm hiện tại.',
        ];
    }

    /**
     * Trả về JSON 422 thay vì redirect khi validation thất bại.
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
