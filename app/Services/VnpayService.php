<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * VnpayService
 *
 * Tạo URL thanh toán VNPay và xác thực chữ ký callback.
 * Tương thích VNPay API v2.1.0
 *
 * Tài liệu: https://sandbox.vnpayment.vn/apis/docs/huong-dan-tich-hop/
 */
class VnpayService
{
    private string $tmnCode;
    private string $hashSecret;
    private string $vnpUrl;
    private string $returnUrl;
    private string $version;
    private string $command;
    private string $currCode;
    private string $locale;
    private string $orderType;

    public function __construct()
    {
        $this->tmnCode   = trim(config('vnpay.tmn_code'));
        $this->hashSecret = trim(config('vnpay.hash_secret'));
        $this->vnpUrl    = config('vnpay.url');
        $this->returnUrl = config('vnpay.return_url');
        $this->version   = config('vnpay.version', '2.1.0');
        $this->command   = config('vnpay.command', 'pay');
        $this->currCode  = config('vnpay.curr_code', 'VND');
        $this->locale    = config('vnpay.locale', 'vn');
        $this->orderType = config('vnpay.order_type', 'other');
    }

    /**
     * Tạo URL thanh toán VNPay.
     *
     * @param  string  $orderId      Mã đơn hàng (booking_code)
     * @param  int     $amount       Số tiền (VNĐ, KHÔNG nhân 100 – service tự nhân)
     * @param  string  $orderInfo    Mô tả giao dịch (ví dụ: "Dat coc phong ABC")
     * @param  string  $ipAddr       IP người dùng
     * @return string                URL redirect đến cổng VNPay
     */
    public function createPaymentUrl(
        string $orderId,
        int    $amount,
        string $orderInfo,
        string $ipAddr = '127.0.0.1'
    ): string {
        $createDate = date('YmdHis');
        $expireDate = date('YmdHis', strtotime('+15 minutes'));

        $inputData = [
            'vnp_Version'    => $this->version,
            'vnp_TmnCode'    => $this->tmnCode,
            'vnp_Amount'     => $amount * 100,          // VNPay yêu cầu nhân 100
            'vnp_Command'    => $this->command,
            'vnp_CreateDate' => $createDate,
            'vnp_CurrCode'   => $this->currCode,
            'vnp_IpAddr'     => $ipAddr,
            'vnp_Locale'     => $this->locale,
            'vnp_OrderInfo'  => $orderInfo,
            'vnp_OrderType'  => $this->orderType,
            'vnp_ReturnUrl'  => $this->returnUrl,
            'vnp_TxnRef'     => $orderId,
            'vnp_ExpireDate' => $expireDate,
        ];

        // Sắp xếp key theo thứ tự alphabet (yêu cầu của VNPay)
        ksort($inputData);

        // Tạo query string để ký
        $hashData  = '';
        $query     = '';
        $i = 0;
        foreach ($inputData as $key => $value) {
            $hashData .= ($i > 0 ? '&' : '') . urlencode($key) . '=' . urlencode($value);
            $query    .= ($i > 0 ? '&' : '') . urlencode($key) . '=' . urlencode($value);
            $i++;
        }

        // Tạo chữ ký HMAC-SHA512
        $vnpSecureHash = hash_hmac('sha512', $hashData, $this->hashSecret);

        $paymentUrl = $this->vnpUrl . '?' . $query . '&vnp_SecureHash=' . $vnpSecureHash;

        Log::info('[VnpayService] Created payment URL', [
            'orderId'    => $orderId,
            'amount'     => $amount,
            'createDate' => $createDate,
        ]);

        return $paymentUrl;
    }

    /**
     * Xác thực chữ ký callback từ VNPay (IPN / Return URL).
     *
     * @param  array  $vnpData  Toàn bộ $_GET / request()->all() từ VNPay callback
     * @return bool
     */
    public function verifySignature(array $vnpData): bool
    {
        $vnpSecureHash = $vnpData['vnp_SecureHash'] ?? '';

        // Loại bỏ các key về chữ ký trước khi tính lại
        unset($vnpData['vnp_SecureHash'], $vnpData['vnp_SecureHashType']);

        ksort($vnpData);

        $hashData = '';
        $i = 0;
        foreach ($vnpData as $key => $value) {
            $hashData .= ($i > 0 ? '&' : '') . urlencode($key) . '=' . urlencode($value);
            $i++;
        }

        $calculatedHash = hash_hmac('sha512', $hashData, $this->hashSecret);

        return hash_equals($calculatedHash, $vnpSecureHash);
    }

    /**
     * Kiểm tra giao dịch thành công hay không.
     *
     * @param  array  $vnpData
     * @return bool
     */
    public function isSuccess(array $vnpData): bool
    {
        return ($vnpData['vnp_ResponseCode'] ?? '') === '00';
    }
}
