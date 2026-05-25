<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kiểm tra quyền admin cho các API route.
 *
 * Khác với checkAdmin (dành cho web, trả về redirect),
 * middleware này trả về JSON 403 — phù hợp cho stateless API client.
 *
 * Role hợp lệ cho admin: role == "1"
 * (role "0" = user thường, role "2" = chủ trọ, xem checkAdmin.php)
 */
class EnsureApiAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Guard: user phải đã được xác thực (auth:api middleware chạy trước)
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Chỉ role admin (role == "1") mới được phép
        if ($user->role != '1') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Bạn không có quyền thực hiện thao tác này.',
            ], 403);
        }

        return $next($request);
    }
}
