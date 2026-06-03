<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycRequest;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AdminKycController – Admin xem xét và phê duyệt/từ chối KYC
 *
 * Quy tắc controller mỏng (Thin Controller):
 * ─────────────────────────────────────────────────────────
 * 1. Mọi thao tác thay đổi trạng thái chạy trong DB::transaction().
 * 2. Khi approve: cập nhật kyc_requests → tạo Wallet nếu chưa có.
 *    Dùng lockForUpdate() trên KycRequest để tránh double-approve.
 * 3. Logic nghiệp vụ (approve/reject) được delegate cho KycRequest model.
 * 4. File preview stream qua PrivateFileController, không phải đây.
 *
 * Middleware bảo vệ: auth:api + admin.api (role = 1)
 *
 * Routes:
 *   GET  /api/admin/kyc              → index()   Danh sách KYC
 *   GET  /api/admin/kyc/{id}         → show()    Chi tiết 1 KYC
 *   POST /api/admin/kyc/{id}/approve → approve() Phê duyệt
 *   POST /api/admin/kyc/{id}/reject  → reject()  Từ chối
 */
class AdminKycController extends Controller
{
    /**
     * GET /api/admin/kyc
     *
     * Danh sách tất cả KYC requests, có thể lọc theo status.
     * Kết quả được phân trang (15 bản ghi/trang).
     *
     * Query params:
     *   ?status=pending|verified|rejected  (lọc theo trạng thái)
     *   ?page=N                            (phân trang)
     */
    public function index(Request $request): JsonResponse
    {
        $query = KycRequest::with(['user:id,name,email,PhoneNumber,role'])
            ->latest();

        // Lọc theo status nếu có
        if ($request->filled('status')) {
            $status = $request->input('status');

            if (! in_array($status, ['pending', 'verified', 'rejected'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status không hợp lệ. Chỉ chấp nhận: pending, verified, rejected.',
                ], 422);
            }

            $query->where('status', $status);
        }

        $paginator = $query->paginate(15);

        // Biến đổi: ẩn file paths, thêm secure preview URL
        $items = $paginator->getCollection()->map(function (KycRequest $kyc) {
            return $this->formatKycForAdmin($kyc, withFilePaths: false);
        });

        return response()->json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/admin/kyc/{id}
     *
     * Chi tiết một KYC request, bao gồm secure URL xem file.
     * URL xem file trỏ về PrivateFileController (có auth guard).
     */
    public function show(int $id): JsonResponse
    {
        $kyc = KycRequest::with([
            'user:id,name,email,PhoneNumber,role',
            'reviewer:id,name,email',
        ])->find($id);

        if (! $kyc) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ KYC.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatKycForAdmin($kyc, withFilePaths: true),
        ]);
    }

    /**
     * POST /api/admin/kyc/{id}/approve
     *
     * Admin phê duyệt hồ sơ KYC.
     *
     * Các bước trong DB::transaction():
     *   1. lockForUpdate() trên kyc_request → tránh double-approve
     *   2. Re-check status = 'pending' sau khi có lock
     *   3. Gọi $kyc->approve() → cập nhật status, verified_at [IMMUTABLE]
     *   4. Tạo Wallet rỗng cho user nếu chưa có (firstOrCreate)
     *
     * Body (optional):
     *   { "notes": "Giấy tờ hợp lệ" }
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $adminId = auth()->id();

        try {
            $result = DB::transaction(function () use ($id, $adminId, $request): array {

                // ── 1. Pessimistic lock — tránh 2 admin cùng approve ─────────
                $kyc = KycRequest::lockForUpdate()->find($id);

                if (! $kyc) {
                    throw new \RuntimeException('Không tìm thấy hồ sơ KYC.');
                }

                // ── 2. Re-check sau khi có lock ──────────────────────────────
                if ($kyc->status !== 'pending') {
                    throw new \RuntimeException(
                        "Hồ sơ KYC đã được xử lý (status hiện tại: '{$kyc->status}'). "
                        . 'Không thể phê duyệt lại.'
                    );
                }

                // ── 3. Cập nhật KYC status (delegate sang model) ─────────────
                // KycRequest::approve() ghi verified_at [IMMUTABLE] và reviewed_by
                $kyc->approve($adminId, $request->input('notes'));

                // ── 4. Tạo Wallet rỗng nếu user chưa có ─────────────────────
                // firstOrCreate đảm bảo idempotency — không tạo 2 wallet cùng user
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $kyc->user_id],
                    [
                        // available_balance = 0, pending_balance = 0 (DB default)
                        // is_frozen = false (DB default)
                        'is_frozen' => false,
                    ]
                );

                $walletCreated = $wallet->wasRecentlyCreated;

                Log::info('[AdminKycController@approve] Duyệt KYC thành công', [
                    'admin_id'      => $adminId,
                    'kyc_id'        => $kyc->id,
                    'user_id'       => $kyc->user_id,
                    'wallet_id'     => $wallet->id,
                    'wallet_new'    => $walletCreated,
                ]);

                return [
                    'kyc_id'        => $kyc->id,
                    'user_id'       => $kyc->user_id,
                    'status'        => $kyc->status,
                    'verified_at'   => $kyc->verified_at->toIso8601String(),
                    'wallet_id'     => $wallet->id,
                    'wallet_new'    => $walletCreated,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Phê duyệt KYC thành công. Ví điện tử đã được khởi tạo cho người dùng.',
                'data'    => $result,
            ]);

        } catch (\RuntimeException $e) {
            // Lỗi nghiệp vụ có thể đoán trước (double-approve, not found)
            Log::warning('[AdminKycController@approve] Lỗi nghiệp vụ', [
                'admin_id' => $adminId,
                'kyc_id'   => $id,
                'message'  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);

        } catch (\Throwable $e) {
            Log::error('[AdminKycController@approve] Lỗi không mong đợi', [
                'admin_id' => $adminId,
                'kyc_id'   => $id,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * POST /api/admin/kyc/{id}/reject
     *
     * Admin từ chối hồ sơ KYC với lý do bắt buộc.
     * User có thể nộp lại sau khi bị từ chối (rejected → pending cho phép).
     *
     * Body:
     *   { "reason": "Ảnh CMND không rõ, chụp lại góc thẳng" }
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'reason.required' => 'Vui lòng nhập lý do từ chối.',
            'reason.min'      => 'Lý do từ chối phải có ít nhất 10 ký tự.',
            'reason.max'      => 'Lý do không được vượt quá 500 ký tự.',
        ]);

        $adminId = auth()->id();

        try {
            $result = DB::transaction(function () use ($id, $adminId, $validated): array {

                // ── Pessimistic lock ──────────────────────────────────────────
                $kyc = KycRequest::lockForUpdate()->find($id);

                if (! $kyc) {
                    throw new \RuntimeException('Không tìm thấy hồ sơ KYC.');
                }

                if ($kyc->status !== 'pending') {
                    throw new \RuntimeException(
                        "Hồ sơ KYC đã được xử lý (status: '{$kyc->status}'). Không thể từ chối."
                    );
                }

                // Delegate sang model (ghi reviewed_by, admin_notes)
                $kyc->reject($adminId, $validated['reason']);

                Log::info('[AdminKycController@reject] Từ chối KYC', [
                    'admin_id' => $adminId,
                    'kyc_id'   => $kyc->id,
                    'user_id'  => $kyc->user_id,
                    'reason'   => $validated['reason'],
                ]);

                return [
                    'kyc_id'      => $kyc->id,
                    'user_id'     => $kyc->user_id,
                    'status'      => $kyc->status,
                    'admin_notes' => $kyc->admin_notes,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Đã từ chối hồ sơ KYC. Người dùng sẽ nhận được lý do và có thể nộp lại.',
                'data'    => $result,
            ]);

        } catch (\RuntimeException $e) {
            Log::warning('[AdminKycController@reject] Lỗi nghiệp vụ', [
                'admin_id' => $adminId,
                'kyc_id'   => $id,
                'message'  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);

        } catch (\Throwable $e) {
            Log::error('[AdminKycController@reject] Lỗi không mong đợi', [
                'admin_id' => $adminId,
                'kyc_id'   => $id,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
                'data'    => null,
            ], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Format dữ liệu KYC cho admin, tùy chọn thêm secure preview URLs.
     *
     * Secure preview URLs trỏ về route GET /api/private-files/kyc/{filename}
     * (được bảo vệ bởi auth:api + admin.api middleware).
     *
     * File paths gốc (storage paths) KHÔNG bao giờ được trả về trong API response.
     *
     * @param bool $withFilePaths Nếu true, thêm preview_urls (chỉ cho detail view)
     */
    private function formatKycForAdmin(KycRequest $kyc, bool $withFilePaths = false): array
    {
        $data = [
            'id'          => $kyc->id,
            'status'      => $kyc->status,
            'admin_notes' => $kyc->admin_notes,
            'verified_at' => $kyc->verified_at?->toIso8601String(),
            'submitted_at' => $kyc->created_at->toIso8601String(),
            'user'        => $kyc->user ? [
                'id'    => $kyc->user->id,
                'name'  => $kyc->user->name,
                'email' => $kyc->user->email,
                'phone' => $kyc->user->PhoneNumber,
            ] : null,
            'reviewer'    => $kyc->reviewer ? [
                'id'   => $kyc->reviewer->id,
                'name' => $kyc->reviewer->name,
            ] : null,
        ];

        // Thêm secure preview URLs (chỉ khi admin xem chi tiết)
        // URL trỏ về PrivateFileController - không expose storage path thật
        if ($withFilePaths) {
            $data['preview_urls'] = [
                'id_card_front'   => $this->buildPreviewUrl($kyc->id_card_front_url),
                'id_card_back'    => $this->buildPreviewUrl($kyc->id_card_back_url),
                'ownership_proof' => $kyc->ownership_proof_url
                    ? $this->buildPreviewUrl($kyc->ownership_proof_url)
                    : null,
            ];
        }

        return $data;
    }

    /**
     * Tạo URL xem ảnh an toàn cho admin.
     *
     * Trả về URL trỏ về PrivateFileController@stream thay vì
     * trực tiếp expose storage path. URL này cần token auth để truy cập.
     *
     * Ví dụ đầu vào:  "private_kyc/5/550e8400.jpg"
     * Ví dụ đầu ra:   "/api/private-files/kyc/private_kyc/5/550e8400.jpg"
     *                  (đã encode để xử lý slash trong filename)
     *
     * @param  string $storagePath  Đường dẫn trong disk 'local'
     * @return string  URL an toàn để admin gọi preview
     */
    private function buildPreviewUrl(string $storagePath): string
    {
        // Encode path để an toàn khi dùng làm query param hoặc segment
        $encoded = urlencode($storagePath);
        return url("/api/private-files/kyc?path={$encoded}");
    }
}
