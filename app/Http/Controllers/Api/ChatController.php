<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Exception;

class ChatController extends Controller
{
    public function __construct(private readonly GeminiService $gemini)
    {
        //
    }

    /**
     * Nhận tin nhắn từ client, gọi GeminiService và trả về phản hồi AI.
     *
     * POST /api/chatbot/send-message
     * Body: { "message": "Cho tôi xem phòng giá rẻ nhất" }
     *
     * Response thành công:
     * {
     *   "status": "success",
     *   "reply": "Hiện tại có phòng A giá 2.500.000 VNĐ/tháng..."
     * }
     *
     * Response lỗi:
     * {
     *   "status": "error",
     *   "message": "Mô tả lỗi"
     * }
     */
    public function sendMessage(Request $request): JsonResponse
    {
        // ── Validation ────────────────────────────────────────────────────────
        try {
            $validated = $request->validate([
                'message' => ['required', 'string', 'max:500'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->validator->errors()->first(),
            ], 422);
        }

        // ── Gọi GeminiService ──────────────────────────────────────────────
        try {
            $aiResponse = $this->gemini->askChatbot($validated['message']);

            return response()->json([
                'status' => 'success',
                'reply'  => $aiResponse,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Không thể kết nối đến trợ lý AI. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    /**
     * Xóa lịch sử hội thoại của phiên hiện tại.
     *
     * POST /api/chatbot/clear-history
     */
    public function clearHistory(): JsonResponse
    {
        $this->gemini->clearHistory();

        return response()->json([
            'status'  => 'success',
            'message' => 'Lịch sử trò chuyện đã được xóa.',
        ]);
    }
}
