<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PrivateFileController – Phục vụ file nhạy cảm một cách an toàn
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  BẢO MẬT FILE                                               │
 * │                                                             │
 * │  Route này được bảo vệ bởi: auth:api + admin.api           │
 * │  → Chỉ admin (role = 1) đã đăng nhập mới truy cập được.   │
 * │                                                             │
 * │  Tại sao không dùng Storage::url() hay symlink public?      │
 * │  → KYC documents chứa CCCD/CMND — dữ liệu cá nhân nhạy   │
 * │    cảm. Nếu để public, bất kỳ ai biết filename đều đọc    │
 * │    được (enumeration attack, accidental indexing bởi bot). │
 * │                                                             │
 * │  Cơ chế bảo vệ:                                             │
 * │  1. File lưu trong storage/app/private_kyc (không public)  │
 * │  2. Route có middleware auth:api + admin.api               │
 * │  3. Path traversal protection: không cho '../' trong path   │
 * │  4. Content-Type được set từ file, không từ URL extension   │
 * │  5. Log mọi lần admin truy cập file để audit               │
 * └─────────────────────────────────────────────────────────────┘
 *
 * Route: GET /api/private-files/kyc?path={encoded_path}
 * Middleware: auth:api, admin.api
 */
class PrivateFileController extends Controller
{
    /**
     * Stream file KYC về client một cách an toàn.
     *
     * Query param: ?path={url_encoded_storage_path}
     * Ví dụ: ?path=private_kyc%2F5%2F550e8400.jpg
     *
     * Các lớp bảo vệ:
     *   1. Middleware auth:api + admin.api (route level)
     *   2. Path traversal check ('../', absolute path)
     *   3. Chỉ được phép đọc từ thư mục 'private_kyc/'
     *   4. File phải tồn tại trên disk 'local'
     *   5. Content-Type từ Storage::mimeType(), không từ extension URL
     */
    public function streamKycFile(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $adminId  = auth()->id();
        $rawPath  = $request->query('path', '');

        // ── 1. Validate path không rỗng ─────────────────────────────────────
        if (empty($rawPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Thiếu tham số path.',
            ], 400);
        }

        // ── 2. Path traversal protection ─────────────────────────────────────
        // Chặn '../', './', absolute path (bắt đầu bằng '/'), null byte
        if (
            str_contains($rawPath, '..') ||
            str_contains($rawPath, "\0") ||
            str_starts_with($rawPath, '/')
        ) {
            Log::warning('[PrivateFileController] Path traversal attempt', [
                'admin_id' => $adminId,
                'path'     => $rawPath,
                'ip'       => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Đường dẫn không hợp lệ.',
            ], 400);
        }

        // ── 3. Bắt buộc file phải nằm trong thư mục private_kyc/ ────────────
        // Ngăn admin vô tình (hoặc cố ý) đọc file ngoài vùng KYC
        if (! str_starts_with($rawPath, 'private_kyc/')) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ được phép truy cập file trong thư mục KYC.',
            ], 403);
        }

        // ── 4. Kiểm tra file tồn tại trên disk local ────────────────────────
        $disk = Storage::disk('local');

        if (! $disk->exists($rawPath)) {
            Log::warning('[PrivateFileController] File not found', [
                'admin_id' => $adminId,
                'path'     => $rawPath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'File không tồn tại.',
            ], 404);
        }

        // ── 5. Audit log mỗi lần truy cập file nhạy cảm ────────────────────
        Log::info('[PrivateFileController] Admin truy cập file KYC', [
            'admin_id' => $adminId,
            'path'     => $rawPath,
            'ip'       => $request->ip(),
        ]);

        // ── 6. Xác định MIME type từ file thật (không từ extension URL) ─────
        $mimeType = $disk->mimeType($rawPath) ?: 'application/octet-stream';
        $filename = basename($rawPath);

        // ── 7. Stream file về client ─────────────────────────────────────────
        // Dùng StreamedResponse để không load toàn bộ file vào memory
        return response()->stream(
            function () use ($disk, $rawPath): void {
                $stream = $disk->readStream($rawPath);
                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type'        => $mimeType,
                // inline: hiển thị trong trình duyệt (cho admin xem preview)
                // Đổi thành 'attachment' nếu muốn force download
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
                'Content-Length'      => $disk->size($rawPath),
                // Không cache file nhạy cảm trên trình duyệt
                'Cache-Control'       => 'no-store, no-cache, must-revalidate',
                'Pragma'              => 'no-cache',
                // Ngăn trình duyệt sniff MIME type (bảo mật)
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
