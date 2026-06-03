<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitWithdrawalRequest extends FormRequest
{
    /**
     * Xác định xem user có quyền thực hiện request này không.
     */
    public function authorize(): bool
    {
        return true; // Sẽ check is_verified trong Controller
    }

    /**
     * Rules cho việc tạo yêu cầu rút tiền.
     */
    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'numeric',
                'min:50000',     // Tối thiểu 50.000 VNĐ
                'max:500000000', // Tối đa 500 triệu / lần (bảo vệ chống số quá lớn)
            ],
            'bank_code' => [
                'required',
                'string',
                'max:20',
            ],
            'bank_account_number' => [
                'required',
                'string',
                'max:50',
                'regex:/^[0-9]+$/', // Tài khoản ngân hàng thường chỉ chứa số
            ],
            'bank_account_name' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }

    /**
     * Custom messages.
     */
    public function messages(): array
    {
        return [
            'amount.required'             => 'Vui lòng nhập số tiền cần rút.',
            'amount.min'                  => 'Số tiền rút tối thiểu là 50,000 VNĐ.',
            'amount.max'                  => 'Số tiền rút tối đa là 500,000,000 VNĐ.',
            'bank_code.required'          => 'Vui lòng chọn ngân hàng.',
            'bank_account_number.required'=> 'Vui lòng nhập số tài khoản hợp lệ.',
            'bank_account_number.regex'   => 'Số tài khoản chỉ được chứa chữ số.',
            'bank_account_name.required'  => 'Vui lòng nhập tên chủ tài khoản.',
        ];
    }
}
