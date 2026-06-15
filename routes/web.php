<?php

use App\Http\Controllers\RoomController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\admin\ManagerRoom;
use App\Http\Controllers\admin\MenuController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/




Auth::routes();
Route::middleware('blockAccount')->group(function () {

    Route::prefix('/admin')->group(function () {
        // Route cần phải đăng nhập admin
        Route::middleware(['auth', 'verified', 'checkAdmin'])->namespace('App\Http\Controllers\admin')->group(function () {
            Route::get('/', 'DashboardController@index')->name('home');
            Route::prefix('/loai-phong')->group(function () {
                // Loại phòng
                Route::get('loai-phong-tro', 'CategoryRoomController@index')->name('loai_phong');
                Route::get('tao-loai-phong-tro', 'CategoryRoomController@create')->name('them_loai_phong');
                Route::post('upload-anh', 'CategoryRoomController@upload')->name('upload_anh_loai_phong');
                Route::post('delete-anh', 'CategoryRoomController@deleteImage')->name('xoa_anh_loai_phong');
                Route::post('luu-loai-phong', 'CategoryRoomController@store')->name('luu_loai_phong');
                Route::get('xoa-loai-phong/{id}', 'CategoryRoomController@destroy')->name('xoa_loai_phong');
                Route::get('sua-loai-phong/{id}', 'CategoryRoomController@edit')->name('sua_loai_phong');
                Route::post('cap-nhat-loai-phong', 'CategoryRoomController@update')->name('cap_nhat_loai_phong');
            });
            //Phòng trọ
            Route::resource('ManagerRoom', ManagerRoom::class);
            Route::get('an-phong-tro/{id}', 'ManagerRoom@hideRoom')->name('hide_room');
            Route::get('hien-phong-tro/{id}', 'ManagerRoom@showRoom')->name('show_room');
            // Menu
            Route::resource('menus', MenuController::class);
            Route::get('xoa-menus/{id}',  'MenuController@delete')->name('menus.delete');
            // Quản lý tài khoản
            Route::prefix('quan-ly-tai-khoan')->group(function () {
                Route::get('/', 'ManagerAccount@index')->name('account.index');
                Route::get('sua/{id}', 'ManagerAccount@edit')->name('account.edit');
                Route::post('sua/{id}', 'ManagerAccount@update')->name('account.edit');
                Route::get('chan/{id}', 'ManagerAccount@block')->name('account.block');
                Route::get('gui-thong-bao/{id}', 'ManagerAccount@InputNotification')->name('account.sendNotification');
                Route::post('gui-thong-bao/{id}', 'ManagerAccount@SendNotification')->name('account.sendNotification');
            });
            // Quản lý tin tức

            // Loại tin tức
            Route::prefix('loai-tin-tuc')->group(function () {
                Route::get('danh-sach', 'CategoryNewsController@index')->name('categorynew.index');
                Route::post('luu-tru', 'CategoryNewsController@store')->name('categorynew.store');
                Route::get('xoa/{id}', 'CategoryNewsController@destroy')->name('categorynew.delete');
                Route::get('sua/{id}', 'CategoryNewsController@edit')->name('categorynew.edit');
                Route::put('cap-nhat/{id}', 'CategoryNewsController@update')->name('categorynews.update');
            });
            //  tin tức
            Route::prefix('tin-tuc')->group(function () {
                Route::get('danh-sach', 'ManagerNewsController@index')->name('news.index');
                Route::get('them', 'ManagerNewsController@create')->name('news.add');
                Route::get('xoa/{slug}', 'ManagerNewsController@destroy')->name('news.delete');
                Route::get('sua/{slug}', 'ManagerNewsController@edit')->name('news.edit');
                Route::get('status/{slug}', 'ManagerNewsController@status')->name('news.status');
                Route::get('confirm_add/{slug}', 'ManagerNewsController@confirmAdd')->name('news.confirm_add');
                Route::get('confirm_update/{slug}', 'ManagerNewsController@confirmUpdate')->name('news.confirm_update');
            });
            //Profile
            Route::prefix('tai-khoan-ca-nhan')->group(function () {
                Route::get('/', 'ProfileController@index')->name('profile.index');
                Route::post('doi-mat-khau', 'ProfileController@ChangePassword')->name('profile.doiMatKhau');
            });
            // Quản lý khiếu nại - hoàn tiền
            Route::prefix('khieu-nai')->group(function () {
                Route::get('/', 'DisputeWebController@index')->name('admin.disputes.index');
                Route::post('{id}/duyet', 'DisputeWebController@approve')->name('admin.disputes.approve');
                Route::post('{id}/tu-choi', 'DisputeWebController@reject')->name('admin.disputes.reject');
            });

            // ── Module 4: Quản lý KYC (Admin) ──────────────────────────────────
            Route::prefix('kyc')->group(function () {
                Route::get('/', 'KycWebController@index')->name('admin.kyc_web.index');
            });

            // ── Module 4: Quản lý Rút Tiền (Admin) ─────────────────────────────
            Route::prefix('withdrawals')->group(function () {
                Route::get('/', 'WithdrawalWebController@index')->name('admin.withdrawals_web.index');
            });

            // ── Quản lý Hợp đồng & Hóa đơn (Admin) ─────────────────────────────
            Route::prefix('contracts')->group(function () {
                Route::get('/', 'AdminContractController@index')->name('admin.contracts.index');
            });
            Route::prefix('invoices')->group(function () {
                Route::get('/', 'AdminInvoiceController@index')->name('admin.invoices.index');
            });
        });
    });

    // Route của khách hàng cần phải đăng nhập
    Route::middleware(['auth', 'verified',])->namespace('App\Http\Controllers')->group(function () {
        // Route cần điền thông tin cá nhân mới được truy cập
        Route::middleware(['checkFormInformation'])->group(function () {
            Route::group(['middleware' => 'CheckHost'], function () {
                Route::get('tao-phong', 'RoomController@create')->name('room_create');
            });
            Route::put('binh-luan-bai-viet/{id}', 'NewsController@comment')->name('frontend.news.comment');
            Route::get('xoa-binh-luan-bai-viet/{id}', 'NewsController@deleteComment')->name('news.comment.delete');
            Route::put('binh-luan-phong/{id}', 'RoomController@comment')->name('commentRoom');
        });
        Route::resource('user', UserController::class);
        Route::resource('Room', RoomController::class);
        Route::get('xuat-ban-phong/{id}', 'RoomController@publish')->name('xuat_ban_phong');
        Route::get('xoa-phong/{id}', 'RoomController@destroy')->name('Room_destroy');
        Route::get('xem-trang-mau/{room}', 'RoomController@demoRoom')->name('demo_room');
        Route::get('change-status-room/{id}', 'RoomController@changeStatusRoom')->name('change_status_room');

        // Thông báo
        Route::resource('notification', NotificationController::class);
        // ── Booking: custom routes phải đặt TRƯỚC Route::resource để tránh conflict ──
        // booking.store được tạo tự động bởi Route::resource (POST /booking)
        // Trang xác nhận đặt cọc (checkout summary + countdown timer)
        Route::get('booking/checkout/{booking_code}', 'BookingController@checkout')->name('booking.checkout');
        // Xử lý thanh toán VNPay (POST từ form tại trang checkout)
        Route::post('booking/vnpay-payment', 'BookingController@createVnpayPayment')->name('vnpay.payment');
        // Trang thanh toán giả lập (dùng khi chưa tích hợp VNPay thật)
        Route::get('booking/payment-fake/{booking_code}', 'BookingController@fakePayment')->name('booking.payment.fake');
        // Export PDF biên nhận
        Route::get('booking/export-pdf/{id}', 'BookingController@exportPdf')->name('booking.export_pdf');
        // Yêu cầu hoàn tiền
        Route::post('booking/refund-request', 'BookingController@submitRefundRequest')->name('booking.refund.request');
        // Huỷ lịch hẹn hoặc huỷ đặt cọc chưa thanh toán
        Route::post('booking/cancel', 'BookingController@cancelBooking')->name('booking.cancel');
        // Tiếp tục thanh toán khi lỡ thoát trang checkout
        Route::get('booking/resume/{booking_code}', 'BookingController@resumePayment')->name('booking.resume');

        // ── Route::resource tạo các CRUD routes còn lại (index, create, store, show, edit, update, destroy)
        Route::resource('booking', BookingController::class);
        
        // ── Contract & Deposit Routes ─────────────────────────────────────────
        Route::get('contracts/create', 'ContractController@create')->name('contracts.create');
        Route::post('contracts/draft', 'ContractController@store')->name('contracts.draft');
        Route::post('contracts/{contract}/generate-pdf', 'ContractController@generateAndSavePDF')->name('contracts.generate_pdf');
        Route::get('contracts/{contract}/download-pdf', 'ContractController@downloadPDF')->name('contracts.download_pdf');
        Route::get('contracts/{contract}/sign', 'ContractController@showSignPage')->name('contracts.sign');
        Route::post('contracts/{contract}/agree-and-pay', 'ContractController@agreeAndPay')->name('contracts.agree_pay');

        // ── Module Auto-Billing: Nhập chỉ số điện/nước ───────────────────────
        Route::get('utility-readings', [\App\Http\Controllers\UtilityReadingWebController::class, 'index'])->name('billing.utility-readings');
        Route::post('utility-readings', [\App\Http\Controllers\UtilityReadingWebController::class, 'store'])->name('billing.utility-readings.store');

        // ── Khách thuê: Danh sách Hợp đồng, Hóa đơn & Thanh toán ────────────────────
        Route::prefix('tenant')->group(function () {
            Route::get('contracts', 'ContractController@index')->name('tenant.contracts.index');
            Route::get('invoices', 'TenantInvoiceWebController@index')->name('tenant.invoices.index');
            Route::get('invoices/{id}', 'TenantInvoiceWebController@show')->name('tenant.invoices.show');
        });

        // ── Chủ trọ: Quản lý Hợp đồng, Hóa đơn & Ví tiền ────────────────────────────
        Route::prefix('landlord')->group(function () {
            Route::get('contracts', 'ContractController@index')->name('landlord.contracts.index');
            Route::get('invoices', 'LandlordInvoiceWebController@index')->name('landlord.invoices.index');
            Route::get('invoices/{id}', 'LandlordInvoiceWebController@show')->name('landlord.invoices.show');
            Route::post('invoices/{id}/update-status', 'LandlordInvoiceWebController@updateStatus')->name('landlord.invoices.update_status');
            Route::get('wallet', 'WalletWebController@index')->name('landlord.wallet.index');
        });

        Route::get('contracts/{id}', 'ContractController@show')->name('contracts.show');
        Route::post('contracts/{id}/terminate', 'ContractController@terminate')->name('contracts.terminate');

        // Đổi mật khẩu tài khoản
        Route::get('doi-mat-khau', 'UserController@changePassword')->name('user.change_password');
    });

    // Route không cần đăng nhập
    Route::namespace('App\Http\Controllers')->group(function () {
        Route::put('gui-thong-tin-dat-phong/{id}', 'RoomController@sendBookingRoom')->name('send_booking');
        Route::get('xem-phong/{id}', 'RoomController@show')->name('Room_show');
        Route::get('/', 'HomeController@index')->name('trang_chu');
        Route::get('danh-sach-phong', 'ListRoomController@filter')->name('filter_room');
        // ── Bản đồ tìm kiếm thông minh theo bán kính ──────────────────────────
        Route::get('ban-do-tim-phong', 'HomeController@mapSearch')->name('map.search');
        Route::get('gioi-thieu', function () {
            return view('frontend.about');
        })->name('about');
        Route::prefix('tin-tuc')->group(function () {
            Route::get('danh-sach', 'NewsController@index')->name('frontend.news.index');
            Route::get('chi-tiet/{slug}', 'NewsController@show')->name('frontend.news.show');
            Route::post('tim-kiem', 'NewsController@search')->name('frontend.news.search');

            Route::get('tim-kiem/{id}', 'NewsController@filter')->name('frontend.news.filter');
        });
    });
});
Route::get('khoa-tai-khoan', function () {
    return view('errors.block');
})->name('error.block');

// VNPay Return URL — không cần auth (VNPay redirect trực tiếp)
Route::get('vnpay-return', [BookingController::class, 'vnpayReturn'])->name('vnpay.return');