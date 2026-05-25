<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VNPay Configuration
    |--------------------------------------------------------------------------
    | Thông tin cấu hình VNPay Sandbox / Production
    | Lấy từ: https://sandbox.vnpayment.vn/merchantv2/
    */

    // Mã website tại VNPay (TMN = Terminal Merchant Number)
    'tmn_code'    => env('VNP_TMN_CODE', ''),

    // Chuỗi bí mật để tạo chữ ký HMAC-SHA512
    'hash_secret' => env('VNP_HASH_SECRET', ''),

    // URL cổng thanh toán VNPay
    'url'         => env('VNP_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),

    // URL nhận callback sau khi thanh toán xong (VNPay redirect về đây)
    'return_url'  => env('VNP_RETURN_URL', ''),

    // Phiên bản API VNPay
    'version'     => '2.1.0',

    // Command VNPay
    'command'     => 'pay',

    // Loại tiền tệ
    'curr_code'   => 'VND',

    // Locale (ngôn ngữ hiển thị trên trang VNPay)
    'locale'      => 'vn',

    // Order type (topup, billpayment, fashion, etc.)
    'order_type'  => 'other',
];
