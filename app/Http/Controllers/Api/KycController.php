<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitKycRequest;
use App\Models\KycRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * KycController – Chủ trọ nộp hồ sơ xác minh danh tính
 *
 * Quy tắc controller mỏng (Thin Controller):
 * ─────────────────────────────────────────────────────────
 * 1. Validate đầu vào → SubmitKycRequest (FormRequest).
 * 2. Lưu file vào disk 'local' (private, KHÔNG phải 'public').
 *    Đường dẫn: private_kyc/{user_id}/{uuid}.{ext}
 * 3. Tạo bản ghi kyc_requests với status = 'pending'.
 * 4. Nếu upload thất bại → rollback DB + xóa file đã upload.
 *
 * Bảo mật file:
 * ─────────────────────────────────────────────────────────
 * • Storage::disk('local') = storage/app/ – KHÔNG accessible qua URL.
 * • Không bao giờ dùng Storage::disk('public') cho file KYC.
 * • Admin xem file qua route GET /api/private-files/kyc/{filename}
 *   được bảo vệ bởi middleware admin.api.
 *
 * @see AdminKycController  Phần admin duyệt/từ chối
 * @see PrivateFileController  Phục vụ file an toàn
 */
class KycController extends Controller
{
    /**
     * POST /api/landlord/kyc
     *
     * Chủ trọ nộp hồ sơ KYC gồm ảnh CMND hai mặt
     * và (tùy chọn) giấy tờ chứng minh sở hữu bất động sản.
     *
     * Middleware: auth:api
     * Request:   SubmitKycRequest (validation + 5MB limit)
     *
     * Luồng xử lý:
     *   1. Kiểm tra user chưa có request đang pending/verified
     *   2. Upload 2-3 file vào storage PRIVATE (không public)
     *   3. Tạo bản ghi kyc_requests trong DB::transaction
     *   4. Rollback + xóa file nếu DB thất bại
     */
    public function submit(SubmitKycRequest $request): JsonResponse
    {
        $user   = Auth::user();
        $userId = $user->id;

        // ── 1. Kiểm tra trạng thái KYC hiện tại ─────────────────────────────
        // Tránh nộp trùng khi đang pending hoặc đã verified
        $latestKyc = KycRequest::where('user_id', $userId)
            ->whereIn('status', ['pending', 'verified'])
            ->latest()
            ->first();

        if ($latestKyc) {
            $statusLabel = $latestKyc->status === 'pending'
                ? 'đang chờ xét duyệt'
                : 'đã được xác minh';

            return response()->json([
                'success' => false,
                'message' => "Bạn đã có hồ sơ KYC {$statusLabel}. Không thể nộp thêm.",
                'data'    => [
                    'kyc_id' => $latestKyc->id,
                    'status' => $latestKyc->status,
                ],
            ], 422);
        }

        // ── 2. Upload file vào storage PRIVATE ──────────────────────────────
        // Thư mục: storage/app/private_kyc/{user_id}/
        // KHÔNG bao giờ upload vào disk 'public'
        $uploadedPaths = [];

        try {
            $uploadedPaths['id_card_front'] = $this->storePrivateFile(
                $request->file('id_card_front'),
                $userId
            );

            $uploadedPaths['id_card_back'] = $this->storePrivateFile(
                $request->file('id_card_back'),
                $userId
            );

            if ($request->hasFile('ownership_proof')) {
                $uploadedPaths['ownership_proof'] = $this->storePrivateFile(
                    $request->file('ownership_proof'),
                    $userId
                );
            }

        } catch (\Throwable $e) {
            // Upload thất bại → dọn dẹp file đã upload thành công
            $this->cleanupFiles($uploadedPaths);

            Log::error('[KycController@submit] Lỗi upload file', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể tải file lên. Vui lòng thử lại sau.',
                'data'    => null,
            ], 500);
        }

        // ── 3. Lưu bản ghi vào DB trong transaction ──────────────────────────
        try {
            $kyc = DB::transaction(function () use ($userId, $uploadedPaths): KycRequest {
                return KycRequest::create([
                    'user_id'             => $userId,
                    'id_card_front_url'   => $uploadedPaths['id_card_front'],
                    'id_card_back_url'    => $uploadedPaths['id_card_back'],
                    'ownership_proof_url' => $uploadedPaths['ownership_proof'] ?? null,
                    // status mặc định 'pending' (định nghĩa ở DB migration)
                ]);
            });

            Log::info('[KycController@submit] KYC nộp thành công', [
                'user_id' => $userId,
                'kyc_id'  => $kyc->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Hồ sơ KYC đã được nộp thành công. Admin sẽ xem xét trong 1-3 ngày làm việc.',
                'data'    => [
                    'kyc_id'     => $kyc->id,
                    'status'     => $kyc->status,
                    'created_at' => $kyc->created_at->toIso8601String(),
                    // KHÔNG trả về file paths — thông tin nhạy cảm
                ],
            ], 201);

        } catch (\Throwable $e) {
            // DB thất bại → xóa file đã upload để không rác storage
            $this->cleanupFiles($uploadedPaths);

            Log::error('[KycController@submit] Lỗi lưu DB', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * GET /api/landlord/kyc/status
     *
     * User kiểm tra trạng thái KYC hiện tại của mình.
     * Không trả về file paths.
     *
     * Middleware: auth:api
     */
    public function status(): JsonResponse
    {
        $userId = Auth::id();

        $kyc = KycRequest::where('user_id', $userId)
            ->latest()
            ->first();

        if (! $kyc) {
            return response()->json([
                'success' => true,
                'message' => 'Bạn chưa nộp hồ sơ KYC.',
                'data'    => [
                    'status'      => 'not_submitted',
                    'has_kyc'     => false,
                    'is_verified' => false,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'kyc_id'            => $kyc->id,
                'status'            => $kyc->status,
                'has_kyc'           => true,
                'is_verified'       => $kyc->isVerified(),
                'has_ownership_doc' => $kyc->ownership_proof_url !== null,
                'admin_notes'       => $kyc->admin_notes, // lý do từ chối (nếu rejected)
                'verified_at'       => $kyc->verified_at?->toIso8601String(),
                'submitted_at'      => $kyc->created_at->toIso8601String(),
                // KHÔNG trả về *_url paths
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Upload một file vào storage PRIVATE (local disk).
     *
     * Đường dẫn kết quả: private_kyc/{user_id}/{uuid}.{ext}
     * Dùng UUID để tên file không đoán được (security through obscurity).
     *
     * LƯU Ý: Dùng Storage::disk('local') — KHÔNG phải 'public'.
     *   - 'local' → storage/app/ (không bao giờ web-accessible)
     *   - 'public' → storage/app/public/ (accessible qua /storage symlink)
     *
     * @param  \Illuminate\Http\UploadedFile $file
     * @param  int $userId  Dùng làm thư mục con để tổ chức
     * @return string  Đường dẫn tương đối trong disk 'local'
     *                 Ví dụ: "private_kyc/5/550e8400-e29b-41d4-a716-446655440000.jpg"
     * @throws \RuntimeException nếu upload thất bại
     */
    private function storePrivateFile(\Illuminate\Http\UploadedFile $file, int $userId): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . '.' . $extension;
        $directory = "private_kyc/{$userId}";

        // putFileAs với disk 'local' = storage/app/private_kyc/{userId}/
        // KHÔNG BAO GIỜ dùng disk 'public' cho KYC documents
        $path = Storage::disk('local')->putFileAs($directory, $file, $filename);

        if ($path === false) {
            throw new \RuntimeException("Không thể lưu file vào storage: {$filename}");
        }

        return $path;
    }

    /**
     * Xóa các file đã upload khi có lỗi xảy ra sau đó.
     * Tránh rác storage khi DB transaction rollback.
     *
     * @param array<string, string> $paths  Mảng [key => path] các file đã upload
     */
    private function cleanupFiles(array $paths): void
    {
        foreach ($paths as $key => $path) {
            try {
                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            } catch (\Throwable $e) {
                // Log nhưng không ném exception — đây là cleanup, không phải business logic
                Log::warning("[KycController] Không thể xóa file cleanup: {$path}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
