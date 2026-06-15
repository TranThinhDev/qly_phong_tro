<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\Api\AdminKycController;
use App\Http\Controllers\Api\AdminWithdrawalController;
use App\Http\Controllers\Api\PrivateFileController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::namespace('App\Http\Controllers\Api')->group(function() {
    // Cập nhật thông tin cá nhân
    Route::put('upload-step-1/{id}','UserController@uploadstep1')->name('upload_step_1');
    Route::put('save-description/{id}','UserController@save_description')->name('save_description');
    Route::put('upload-step-2/{id}','UserController@uploadstep2')->name('upload_step_2');
    Route::put('upload-step-3/{id}','UserController@uploadstep3')->name('upload_step_3');
    Route::post('upload-image-user','UserController@uploadImage')->name('upload_avatar');
    Route::post('delete-image-user','UserController@deleteImage')->name('delete_avatar');
    // Tạo phòng trọ
    Route::post('create-room-step-1','RoomController@createStep1')->name('create_room_step_1');
    Route::put('create-room-step-2/{id}','RoomController@createStep2')->name('create_room_step_2');
    Route::put('create-room-step-3/{id}','RoomController@createStep3')->name('create_room_step_3');
    Route::post('get-wards','RoomController@getWards')->name('get_wards');
    Route::post('upload-main-image-room','RoomController@uploadMainImageRoom')->name('upload_main_image_room');
    Route::post('upload-multi-image-room','RoomController@uploadMultiImageRoom')->name('upload_multi_image_room');
    Route::put('delete-image/{path}','RoomController@deleteImages')->name('delete_image');
    Route::put('delete-image-update/{path}','RoomController@deleteImagesForUpdate')->name('delete_image_update');
    // Đăng bài viết
    Route::post('upload-anh-bai-viet', 'NewsController@upload')->name('news.uploadThumnail');
    Route::post('luu-bai-viet','NewsController@store')->name('news.api.store');
    Route::post('cap-nhat-bai-viet','NewsController@update')->name('news.api.update');

    // ── Tranh chấp & hoàn tiền ────────────────────────────────────────────
    // Khách hàng: yêu cầu hoàn tiền (status=paid, trong 48h)
    Route::post('dispute/request-refund', 'DisputeController@requestRefund')
        ->name('dispute.request-refund');

    // ── Module Auto-Billing: Chỉ số điện/nước ────────────────────────────
    // Prefix 'landlord' phân biệt với các route tenant/admin
    Route::prefix('landlord')->group(function () {
        // Lấy danh sách phòng active + chỉ số tháng hiện tại
        Route::get('utility-readings/rooms', 'UtilityReadingController@activeRooms')
            ->name('landlord.utility-readings.rooms');

        // Lưu/cập nhật chỉ số điện nước (multipart/form-data vì có upload ảnh)
        Route::post('utility-readings', 'UtilityReadingController@store')
            ->name('landlord.utility-readings.store');

        // ── Module 4: KYC – Chủ trọ nộp hồ sơ xác minh danh tính ─────────
        // POST: nộp hồ sơ KYC (CMND 2 mặt + giấy tờ sở hữu)
        // File lưu trong storage/app/private_kyc/{user_id}/ (KHÔNG public)
        Route::post('kyc', 'KycController@submit')
            ->name('landlord.kyc.submit');

        // GET: kiểm tra trạng thái KYC của chính mình
        Route::get('kyc/status', 'KycController@status')
            ->name('landlord.kyc.status');

        // Yêu cầu rút tiền từ ví
        Route::post('withdraw', 'WithdrawalController@withdraw')
            ->name('landlord.withdraw');
    });

    // ── Module Auto-Billing: Thanh toán hóa đơn (auth – chỉ tenant) ─────
    // Tenant gọi để lấy VNPAY checkout URL cho một hóa đơn cụ thể.
    Route::get('invoices/{id}/payment-url', 'InvoicePaymentController@generatePaymentUrl')
        ->name('invoices.payment-url')
        ->where('id', '[0-9]+');

})->middleware('auth:api');

// ── Route dành riêng cho Admin ────────────────────────────────────────────────
// Middleware: auth:api (xác thực token) → admin.api (kiểm tra role, trả JSON 403)
// Tách thành group riêng để không ảnh hưởng các route khách hàng phía trên.
Route::namespace('App\Http\Controllers\Api')
    ->middleware(['auth:api', 'admin.api'])
    ->prefix('admin')
    ->group(function () {

        // Duyệt yêu cầu hoàn tiền: huỷ booking + giải phóng phòng
        Route::post('dispute/approve-refund', 'DisputeController@approveRefund')
            ->name('dispute.approve-refund');

        // ── Module 4: KYC – Admin xét duyệt hồ sơ ─────────────────────────
        // GET  /api/admin/kyc              Danh sách KYC (có filter ?status=)
        // GET  /api/admin/kyc/{id}         Chi tiết + secure preview URLs
        // POST /api/admin/kyc/{id}/approve Phê duyệt + tạo Wallet
        // POST /api/admin/kyc/{id}/reject  Từ chối kèm lý do
        Route::prefix('kyc')->group(function () {
            Route::get('/', 'AdminKycController@index')
                ->name('admin.kyc.index');
            Route::get('{id}', 'AdminKycController@show')
                ->name('admin.kyc.show')
                ->where('id', '[0-9]+');
            Route::post('{id}/approve', 'AdminKycController@approve')
                ->name('admin.kyc.approve')
                ->where('id', '[0-9]+');
            Route::post('{id}/reject', 'AdminKycController@reject')
                ->name('admin.kyc.reject')
                ->where('id', '[0-9]+');
        });

        // ── Module 4: Yêu cầu rút tiền – Admin xét duyệt ──────────────────
        // Các route này đã được chuyển sang web.php để tương thích với Blade view (session/CSRF)
        Route::prefix('withdrawals')->group(function () {
            Route::get('/', 'AdminWithdrawalController@index')
                ->name('api.admin.withdrawals.index');
            Route::post('{id}/approve', 'AdminWithdrawalController@approve')
                ->name('api.admin.withdrawals.approve')
                ->where('id', '[0-9]+');
            Route::post('{id}/reject', 'AdminWithdrawalController@reject')
                ->name('api.admin.withdrawals.reject')
                ->where('id', '[0-9]+');
        });

    });

// ── Module 4: Phục vụ file KYC riêng tư (Admin only) ──────────────────────────
// Route tách riêng để tường minh middleware stack.
// Middleware: auth:api (xác thực) + admin.api (chỉ role=1)
//
// GET /api/private-files/kyc?path={encoded_storage_path}
// → Stream file từ storage/app/private_kyc/ về client
// → Không bao giờ expose URL trực tiếp của storage
Route::namespace('App\Http\Controllers\Api')
    ->middleware(['auth:api', 'admin.api'])
    ->group(function () {
        Route::get('private-files/kyc', 'PrivateFileController@streamKycFile')
            ->name('private-files.kyc');
    });

// ── Bản đồ tìm kiếm theo bán kính (Public – không cần auth) ──────────────────
// Cho phép cả GET (test trên browser) lẫn POST (từ Leaflet JS fetch)
Route::match(['get', 'post'], 'map/rooms', 'App\Http\Controllers\Api\MapController@getRooms')
    ->name('api.map.rooms');

// ── VNPay IPN Webhook: Tiền cọc hợp đồng (Public – không cần auth) ──────────────
Route::get('vnpay/ipn', 'App\Http\Controllers\PaymentController@vnpayIpn')
    ->name('api.vnpay.ipn');

// ── VNPay IPN Webhook: Hóa đơn hàng tháng (Public – không cần auth) ─────────────
// Tách endpoint riêng để log/debug độc lập với luồng deposit.
// VNPAY gọi GET đến URL này sau khi giao dịch hoàn tất (bất kể thành công hay thất bại).
Route::get('webhooks/vnpay-invoice-ipn', 'App\Http\Controllers\Api\InvoicePaymentController@vnpayIpn')
    ->name('api.invoices.vnpay.ipn');

// ── Chatbot AI tư vấn phòng trọ (Public – không yêu cầu đăng nhập) ──────────
// POST /api/chatbot/send-message   → Gửi tin nhắn, nhận phản hồi từ Gemini AI
// POST /api/chatbot/clear-history  → Xóa lịch sử hội thoại khỏi Session
Route::prefix('chatbot')
    ->namespace('App\Http\Controllers\Api')
    ->group(function () {
        Route::post('send-message', 'ChatController@sendMessage')
            ->name('chatbot.send-message');
        Route::post('clear-history', 'ChatController@clearHistory')
            ->name('chatbot.clear-history');
    });

Route::fallback(function(){
    return response()->json([
        'message' => 'Page Not Found. If error persists, contact admin@gmail.com'], 404);
});
