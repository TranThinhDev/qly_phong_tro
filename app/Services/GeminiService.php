<?php

namespace App\Services;

use App\Models\Room;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Exception;

class GeminiService
{
    // ── Cấu hình ────────────────────────────────────────────────────────────
    private string $apiKey;
    private string $model;
    private string $endpoint;

    /** Số phòng trống tối đa đưa vào context (RAG). */
    private const RAG_LIMIT = 5;

    /** Tên session lưu lịch sử hội thoại. */
    private const SESSION_KEY = 'chat_history';

    public function __construct()
    {
        $this->apiKey   = config('gemini.api_key');
        $this->model    = config('gemini.model', 'gemini-2.0-flash');
        $baseUrl        = rtrim(config('gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->endpoint = "{$baseUrl}/models/{$this->model}:generateContent";
    }

    // ────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Gửi tin nhắn người dùng tới Gemini, kết hợp RAG + Session memory.
     *
     * Luồng xử lý:
     *  1. Retrieval  – truy vấn Eloquent lấy ≤5 phòng đang trống
     *  2. Prompt     – ghép system prompt chứa dữ liệu phòng + câu hỏi
     *  3. Memory     – đọc lịch sử hội thoại từ Session
     *  4. Call API   – gọi Gemini với toàn bộ context
     *  5. Persist    – lưu lượt hội thoại mới vào Session
     *
     * @param  string $userMessage  Tin nhắn của khách hàng
     * @return string               Câu trả lời từ AI
     *
     * @throws Exception  Ném exception khi Gemini API trả lỗi
     */
    public function askChatbot(string $userMessage): string
    {
        // ── Bước 1: Retrieval (RAG) ─────────────────────────────────────────
        $roomsJson = $this->fetchAvailableRoomsAsJson();

        // ── Bước 2: Xây dựng System Prompt ─────────────────────────────────
        $systemPrompt = $this->buildSystemPrompt($roomsJson, $userMessage);

        // ── Bước 3: Đọc lịch sử hội thoại từ Session ───────────────────────
        $history = Session::get(self::SESSION_KEY, []);

        // ── Bước 4: Gọi Gemini API ──────────────────────────────────────────
        $aiReply = $this->callGeminiApi($systemPrompt, $history);

        // ── Bước 5: Cập nhật lịch sử vào Session ────────────────────────────
        $history[] = ['role' => 'user',  'parts' => [['text' => $userMessage]]];
        $history[] = ['role' => 'model', 'parts' => [['text' => $aiReply]]];
        Session::put(self::SESSION_KEY, $history);

        return $aiReply;
    }

    /**
     * Xóa toàn bộ lịch sử hội thoại trong Session.
     * Gọi khi khách bắt đầu cuộc trò chuyện mới.
     */
    public function clearHistory(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    // ────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Truy vấn Eloquent lấy ≤RAG_LIMIT phòng đang trống (status = 1).
     * Chỉ select các cột cần thiết để giảm kích thước context.
     *
     * @return string  JSON string của danh sách phòng
     */
    private function fetchAvailableRoomsAsJson(): string
    {
        try {
            $rooms = Room::where('status', 1)
                ->select([
                    'id',
                    'name',
                    'price',
                    'area',
                    'unit',
                    'quantity',
                    'add_ons',
                    'describe_room',
                    'ward_id',
                ])
                ->with([
                    // Lấy tên phường/xã và tên quận/huyện để tạo địa chỉ đầy đủ
                    'getWard:code,full_name,district_code',
                    'getWard.getDistrict:code,full_name',
                ])
                ->limit(self::RAG_LIMIT)
                ->get()
                ->map(function (Room $room) {
                    // Ghép địa chỉ: "Phường X, Quận Y"
                    $ward     = optional($room->getWard);
                    $district = optional($ward->getDistrict);
                    $address  = implode(', ', array_filter([
                        $ward->full_name     ?? null,
                        $district->full_name ?? null,
                    ]));

                    return [
                        'id'        => $room->id,
                        'ten_phong' => $room->name,
                        'gia_thue'  => number_format($room->price, 0, ',', '.') . ' VNĐ/tháng',
                        'dien_tich' => $room->area . ' m²',
                        'suc_chua'  => $room->quantity . ' người',
                        'dia_chi'   => $address ?: 'Chưa cập nhật',
                        'tien_ich'  => $room->add_ons   ?: 'Không có thông tin',
                        'mo_ta'     => $room->describe_room ?: 'Không có mô tả',
                    ];
                });

            return $rooms->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (Exception $e) {
            Log::error('GeminiService: Không thể lấy danh sách phòng', [
                'error' => $e->getMessage(),
            ]);
            // Trả về mảng rỗng thay vì crash toàn bộ chatbot
            return '[]';
        }
    }

    /**
     * Tạo system prompt kết hợp dữ liệu phòng và câu hỏi của khách.
     *
     * @param  string $roomsJson    JSON danh sách phòng trống
     * @param  string $userMessage  Câu hỏi của khách
     * @return string
     */
    private function buildSystemPrompt(string $roomsJson, string $userMessage): string
    {
        return <<<PROMPT
Bạn là trợ lý ảo tư vấn phòng trọ thân thiện và chuyên nghiệp.

Dưới đây là danh sách các phòng đang trống hiện tại (dữ liệu thực tế từ hệ thống):
{$roomsJson}

Nguyên tắc tư vấn:
- Chỉ tư vấn dựa trên danh sách phòng có trong dữ liệu trên, KHÔNG bịa đặt thông tin.
- Nếu không có phòng phù hợp, hãy nói thật và đề nghị khách để lại thông tin liên hệ.
- Trả lời ngắn gọn, rõ ràng, dùng tiếng Việt thân thiện.
- Khi đề xuất phòng, hãy nêu rõ: tên phòng, giá, diện tích, địa chỉ, và tiện ích nổi bật.

Khách hàng vừa hỏi: "{$userMessage}"
PROMPT;
    }

    /**
     * Gửi request POST đến Gemini API.
     *
     * Cấu trúc payload:
     *  - systemInstruction : system prompt (RAG context + câu hỏi)
     *  - contents          : lịch sử hội thoại (mảng {role, parts})
     *
     * @param  string $systemPrompt  Hướng dẫn cho AI
     * @param  array  $history       Lịch sử hội thoại từ Session
     * @return string                Nội dung phản hồi từ AI
     *
     * @throws Exception
     */
    private function callGeminiApi(string $systemPrompt, array $history): string
    {
        // Nếu chưa có lịch sử, gửi một lượt hội thoại giả để tránh mảng rỗng
        $contents = !empty($history)
            ? $history
            : [['role' => 'user', 'parts' => [['text' => ' ']]]];

        $payload = [
            // System prompt: chứa ngữ cảnh RAG và câu hỏi mới nhất
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            // Lịch sử hội thoại nhiều lượt
            'contents' => $contents,
            // Tham số sinh văn bản
            'generationConfig' => [
                'temperature'     => 0.7,
                'topK'            => 40,
                'topP'            => 0.95,
                'maxOutputTokens' => 1024,
            ],
        ];

        $response = Http::timeout(config('gemini.timeout', 30))
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post($this->endpoint, $payload);

        if ($response->failed()) {
            $errorMessage = $response->json('error.message', 'Unknown error');
            Log::error('GeminiService: API call thất bại', [
                'status'  => $response->status(),
                'message' => $errorMessage,
                'model'   => $this->model,
            ]);
            throw new Exception(
                "Gemini API lỗi (HTTP {$response->status()}): {$errorMessage}"
            );
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');

        if (empty($text)) {
            Log::warning('GeminiService: Phản hồi rỗng từ API', [
                'response' => $response->json(),
            ]);
            return 'Xin lỗi, tôi không thể xử lý yêu cầu của bạn lúc này. Vui lòng thử lại sau.';
        }

        return trim($text);
    }
}
