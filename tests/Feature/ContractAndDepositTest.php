<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use App\Models\User;
use App\Models\Room;
use App\Models\Contract;
use App\Models\PendingWallet;
use App\Services\VnpayService;

class ContractAndDepositTest extends TestCase
{
    use RefreshDatabase;

    private $landlord;
    private $tenant;
    private $room;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Tối ưu hóa việc tạo dữ liệu bằng Model Factory cho User
        $this->landlord = User::factory()->create();
        $this->tenant = User::factory()->create();

        // Tạo dữ liệu giả cho Room. 
        // Trong trường hợp có RoomFactory, ta có thể đổi thành Room::factory()->create(...)
        // Tắt tạm thời kiểm tra khóa ngoại (tránh lỗi thiếu dữ liệu bảng Wards, CategoryRooms khi RefreshDatabase)
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        $this->room = Room::create([
            'name' => 'Phòng Trọn Gói 01',
            'status' => 1,
            'chutro_id' => $this->landlord->id,
            'category_id' => 1, 
            'quantity' => 1,
            'area' => 25.5,
            'describe_room' => 'Mô tả phòng trọ test',
            'unit' => 'Phòng',
            'main_img' => 'test_img.jpg',
            'ward_id' => '00001',
            'price' => 3000000,
            'electric' => 3500,
            'water' => 20000,
            'deposit_amount' => 1500000,
        ]);

        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
    }

    /**
     * Test Luồng Chủ trọ tạo Draft: 
     * Kiểm tra quyền truy cập (chỉ Landlord), validate dữ liệu đầu vào (giá, cọc > 0), 
     * và xác nhận DB tạo đúng trạng thái draft.
     */
    public function test_landlord_can_create_draft_contract_with_valid_data()
    {
        // Kiểm tra validate: Cọc phải > 0
        $invalidPayload = [
            'tenant_id'      => $this->tenant->id,
            'room_id'        => $this->room->id,
            'start_date'     => now()->addDay()->format('Y-m-d'),
            'monthly_rent'   => 3000000,
            'deposit_amount' => 0, // Cọc không hợp lệ
        ];

        // 1. Gửi request giả lập Landlord đang đăng nhập
        $this->actingAs($this->landlord)
             ->postJson(route('contracts.draft'), $invalidPayload)
             ->assertStatus(422) // Lỗi Validation Unprocessable Entity
             ->assertJsonValidationErrors(['deposit_amount']);

        // Payload hợp lệ
        $validPayload = [
            'tenant_id'      => $this->tenant->id,
            'room_id'        => $this->room->id,
            'start_date'     => now()->addDay()->format('Y-m-d'),
            'monthly_rent'   => 3000000,
            'deposit_amount' => 1500000,
        ];

        // 2. Gửi request tạo hợp đồng hợp lệ
        $response = $this->actingAs($this->landlord)
                         ->postJson(route('contracts.draft'), $validPayload);

        $response->assertStatus(201);
        
        // 3. Xác nhận Database đã tạo record với trạng thái draft
        $this->assertDatabaseHas('contracts', [
            'tenant_id'      => $this->tenant->id,
            'room_id'        => $this->room->id,
            'status'         => 'draft',
            'monthly_rent'   => 3000000,
            'deposit_amount' => 1500000,
        ]);
    }

    /**
     * Test Luồng Khách thuê đồng ý (Clickwrap): 
     * Kiểm tra xem hệ thống có ghi nhận đúng tenant_ip, tenant_user_agent, timestamp signed_at
     * Trạng thái hợp đồng phải chuyển sang pending_payment.
     */
    public function test_tenant_can_sign_clickwrap_contract()
    {
        $contract = Contract::create([
            'contract_code'  => 'CT-TEST-001',
            'landlord_id'    => $this->landlord->id,
            'tenant_id'      => $this->tenant->id,
            'room_id'        => $this->room->id,
            'start_date'     => now()->addDay(),
            'monthly_rent'   => 3000000,
            'deposit_amount' => 1500000,
            'status'         => 'draft'
        ]);

        /* 
         * Mocking VnpayService: 
         * Do hàm agreeAndPay có gọi $vnpayService->createPaymentUrl(),
         * ta cần mock nó lại để không gọi ra ngoài API của VNPAY thực tế,
         * đồng thời trả về một đường link thanh toán giả để assert Redirect.
         */
        $this->mock(VnpayService::class, function ($mock) {
            $mock->shouldReceive('createPaymentUrl')
                 ->once()
                 ->andReturn('https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?test=123');
        });

        $ip = '192.168.1.100';
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Test Agent';
        
        // Giả lập request ký hợp đồng với IP và User-Agent cụ thể
        $response = $this->actingAs($this->tenant)
             ->withServerVariables([
                 'REMOTE_ADDR'     => $ip,
                 'HTTP_USER_AGENT' => $userAgent,
             ])
             ->post(route('contracts.agree_pay', $contract->id));

        $response->assertRedirect('https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?test=123');

        $contract->refresh();

        // Kiểm tra tính bất biến (Immutable Clickwrap Data)
        $this->assertNotNull($contract->signed_at);
        $this->assertEquals($ip, $contract->tenant_ip);
        $this->assertEquals($userAgent, $contract->tenant_user_agent);
        $this->assertEquals('pending_payment', $contract->status);

        // Kiểm tra hệ thống tự động sinh Transaction Pending
        $this->assertDatabaseHas('transactions', [
            'contract_id' => $contract->id,
            'payer_id'    => $this->tenant->id,
            'type'        => 'deposit_payment',
            'status'      => 'pending',
            'amount'      => 1500000,
        ]);
    }

    /**
     * Test Webhook/IPN VNPAY (Thành công): 
     * Mock VNPAY IPN, kiểm tra Transaction -> success, Contract -> active, 
     * PendingWallet được sinh ra và Room bị ẩn (status=0 / rented).
     */
    public function test_vnpay_ipn_success_updates_models_correctly()
    {
        $contract = Contract::create([
            'contract_code'  => 'CT-TEST-002',
            'landlord_id'    => $this->landlord->id,
            'tenant_id'      => $this->tenant->id,
            'room_id'        => $this->room->id,
            'start_date'     => now(),
            'monthly_rent'   => 3000000,
            'deposit_amount' => 1500000,
            'status'         => 'pending_payment'
        ]);

        $transaction = $contract->transactions()->create([
            'transaction_code' => 'TXN-TEST-123',
            'payer_id'         => $this->tenant->id,
            'type'             => 'deposit_payment',
            'amount'           => 1500000,
            'currency'         => 'VND',
            'status'           => 'pending',
        ]);

        /* 
         * Mocking VnpayService: Giả lập IPN của VNPAY gửi đến.
         * Ép hệ thống bỏ qua bước check signature mã hóa và cho phép luôn trả về "Thanh toán thành công" (isSuccess = true).
         */
        $this->mock(VnpayService::class, function ($mock) {
            $mock->shouldReceive('verifySignature')->once()->andReturn(true);
            $mock->shouldReceive('isSuccess')->once()->andReturn(true);
        });

        // Dữ liệu giả định bắn từ VNPAY Server
        $payload = [
            'vnp_TxnRef'        => 'TXN-TEST-123',
            'vnp_Amount'        => 1500000 * 100, // VNPAY amount luôn nhân 100
            'vnp_TransactionNo' => 'VNP123456789',
        ];

        // Gửi GET Webhook (IPN)
        $response = $this->getJson('/api/vnpay/ipn?' . http_build_query($payload));

        $response->assertStatus(200);
        $response->assertJson(['RspCode' => '00', 'Message' => 'Confirm Success']);

        $transaction->refresh();
        $contract->refresh();
        $this->room->refresh();

        // 1. Kiểm tra Transaction được cập nhật thành công
        $this->assertEquals('completed', $transaction->status);
        $this->assertEquals('VNP123456789', $transaction->gateway_transaction_id);

        // 2. Kiểm tra Hợp đồng có hiệu lực & Phòng được ẩn đi (status=0)
        $this->assertEquals('active', $contract->status);
        $this->assertEquals(0, $this->room->status);

        // 3. Đảm bảo tiền nhảy vào ví tạm PendingWallet (Escrow)
        $this->assertDatabaseHas('pending_wallets', [
            'contract_id'           => $contract->id,
            'owner_id'              => $this->tenant->id,
            'held_amount'           => 1500000,
            'status'                => 'holding',
            'source_transaction_id' => $transaction->id,
        ]);
    }

    /**
     * Test Webhook/IPN VNPAY (Thất bại/Hủy): 
     * Hợp đồng không update lên active và Transaction chuyển thành failed.
     */
    public function test_vnpay_ipn_failure_reverts_contract_to_draft()
    {
        $contract = Contract::create([
            'contract_code'  => 'CT-TEST-003',
            'landlord_id'    => $this->landlord->id,
            'tenant_id'      => $this->tenant->id,
            'room_id'        => $this->room->id,
            'start_date'     => now(),
            'monthly_rent'   => 3000000,
            'deposit_amount' => 1500000,
            'status'         => 'pending_payment'
        ]);

        $transaction = $contract->transactions()->create([
            'transaction_code' => 'TXN-TEST-FAIL',
            'payer_id'         => $this->tenant->id,
            'type'             => 'deposit_payment',
            'amount'           => 1500000,
            'status'           => 'pending',
        ]);

        /* 
         * Mocking VnpayService: 
         * Lần này giả lập người dùng hủy thanh toán hoặc tài khoản không đủ tiền (isSuccess = false).
         */
        $this->mock(VnpayService::class, function ($mock) {
            $mock->shouldReceive('verifySignature')->once()->andReturn(true);
            $mock->shouldReceive('isSuccess')->once()->andReturn(false);
        });

        $payload = [
            'vnp_TxnRef'        => 'TXN-TEST-FAIL',
            'vnp_Amount'        => 1500000 * 100,
            'vnp_TransactionNo' => 'VNP987654321',
            'vnp_ResponseCode'  => '24', // Mã 24 trong VNPAY là User Canceled
        ];

        $response = $this->getJson('/api/vnpay/ipn?' . http_build_query($payload));

        // Dù giao dịch lỗi, VNPAY vẫn cần response 00 để ghi nhận IPN
        $response->assertStatus(200);
        $response->assertJson(['RspCode' => '00']); 

        $transaction->refresh();
        $contract->refresh();

        // 1. Transaction bị đánh dấu failed
        $this->assertEquals('failed', $transaction->status);
        
        // 2. Contract bị rollback trạng thái về draft để khách có thể thử thanh toán lại
        $this->assertEquals('draft', $contract->status);
    }

    /**
     * Test Security (Race Condition): 
     * Giả lập VNPAY bắn IPN 2 lần liên tiếp cùng một lúc.
     * Hệ thống sử dụng LockForUpdate() để đảm bảo PendingWallet không bị cộng dồn tiền.
     */
    public function test_vnpay_ipn_prevents_race_condition()
    {
        $contract = Contract::create([
            'contract_code'  => 'CT-TEST-004',
            'landlord_id'    => $this->landlord->id,
            'tenant_id'      => $this->tenant->id,
            'room_id'        => $this->room->id,
            'start_date'     => now(),
            'monthly_rent'   => 3000000,
            'deposit_amount' => 1500000,
            'status'         => 'pending_payment'
        ]);

        $transaction = $contract->transactions()->create([
            'transaction_code' => 'TXN-TEST-RACE',
            'payer_id'         => $this->tenant->id,
            'type'             => 'deposit_payment',
            'amount'           => 1500000,
            'status'           => 'pending',
        ]);

        /*
         * Mocking cho IPN lần 1 và Lần 2
         * Lần 1: isSuccess() trả về true.
         * Lần 2: Do transaction status đã đổi thành != 'pending' (nhờ LockForUpdate + logic check), 
         * hệ thống sẽ không gọi đến isSuccess() nữa mà văng ra thông báo RspCode=02.
         */
        $this->mock(VnpayService::class, function ($mock) {
            // Chữ ký thì luôn được gọi và trả về true (2 lần test)
            $mock->shouldReceive('verifySignature')->twice()->andReturn(true);
            
            // isSuccess chỉ được trigger 1 lần ở request đầu tiên
            $mock->shouldReceive('isSuccess')->once()->andReturn(true);
        });

        $payload = [
            'vnp_TxnRef'        => 'TXN-TEST-RACE',
            'vnp_Amount'        => 1500000 * 100,
            'vnp_TransactionNo' => 'VNP000111222', // Fix: Thêm trường bắt buộc để tránh Undefined array key
        ];

        // Lần gọi 1 (Thành công)
        $response1 = $this->getJson('/api/vnpay/ipn?' . http_build_query($payload));
        $response1->assertJson(['RspCode' => '00', 'Message' => 'Confirm Success']);

        // Lần gọi 2 (Đến trễ / Gọi đúp)
        // Khi row lock được nhả ra, tiến trình thứ 2 đọc DB thấy Transaction không còn pending nữa.
        $response2 = $this->getJson('/api/vnpay/ipn?' . http_build_query($payload));
        $response2->assertJson(['RspCode' => '02', 'Message' => 'Order already confirmed']);

        // Xác nhận chốt chặn cuối: Ví tạm (Pending Wallet) chỉ có ĐÚNG 1 bản ghi
        $walletCount = PendingWallet::where('contract_id', $contract->id)->count();
        $this->assertEquals(1, $walletCount);
    }
}
