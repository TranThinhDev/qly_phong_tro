<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * FormRequest: SubmitKycRequest
 *
 * Xác thực dữ liệu khi chủ trọ nộp hồ sơ KYC.
 *
 * Quy tắc bảo mật file:
 * ─────────────────────────────────────────────────────────
 * 1. Chỉ chấp nhận JPEG/PNG/PDF (không cho SVG/GIF/HTML
 *    vì chúng có thể nhúng XSS khi admin xem trước).
 * 2. Giới hạn 5MB mỗi file — kiểm tra tại tầng validation
 *    trước khi đụng đến storage.
 * 3. ownership_proof_url là nullable: tenant không cần,
 *    nhưng landlord nên nộp để tăng cơ hội được duyệt.
 * 4. failedValidation() trả về JSON 422 thay vì redirect
 *    vì đây là API endpoint (stateless).
 */
class SubmitKycRequest extends FormRequest
{
    /**
     * Chỉ user đã đăng nhập mới được nộp KYC.
     * Phân quyền chi tiết (landlord only) được kiểm tra trong controller.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // ── CMND/CCCD mặt trước (bắt buộc) ──────────────────────────────
            'id_card_front' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,pdf',  // Không cho SVG/GIF/HTML (XSS risk)
                'max:5120',                // 5MB = 5 × 1024 KB
            ],

            // ── CMND/CCCD mặt sau (bắt buộc) ─────────────────────────────────
            'id_card_back' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,pdf',
                'max:5120',
            ],

            // ── Giấy tờ chứng minh sở hữu (tùy chọn) ────────────────────────
            // Sổ đỏ, hợp đồng mua bán, v.v.
            // Nullable vì user có thể nộp sau, nhưng nếu có thì phải hợp lệ.
            'ownership_proof' => [
                'nullable',
                'file',
                'mimes:jpeg,jpg,png,pdf',
                'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'id_card_front.required' => 'Vui lòng upload ảnh CMND/CCCD mặt trước.',
            'id_card_front.file'     => 'File CMND mặt trước không hợp lệ.',
            'id_card_front.mimes'    => 'CMND mặt trước chỉ chấp nhận JPG, PNG, PDF.',
            'id_card_front.max'      => 'CMND mặt trước không được vượt quá 5MB.',

            'id_card_back.required'  => 'Vui lòng upload ảnh CMND/CCCD mặt sau.',
            'id_card_back.file'      => 'File CMND mặt sau không hợp lệ.',
            'id_card_back.mimes'     => 'CMND mặt sau chỉ chấp nhận JPG, PNG, PDF.',
            'id_card_back.max'       => 'CMND mặt sau không được vượt quá 5MB.',

            'ownership_proof.file'   => 'File giấy tờ sở hữu không hợp lệ.',
            'ownership_proof.mimes'  => 'Giấy tờ sở hữu chỉ chấp nhận JPG, PNG, PDF.',
            'ownership_proof.max'    => 'Giấy tờ sở hữu không được vượt quá 5MB.',
        ];
    }

    /**
     * Trả về JSON 422 thay vì redirect khi validation thất bại.
     * Bắt buộc cho API endpoint — client không biết xử lý redirect.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ. Vui lòng kiểm tra lại file đính kèm.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
