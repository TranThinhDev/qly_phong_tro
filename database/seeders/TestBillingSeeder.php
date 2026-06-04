<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Room;
use App\Models\User;
use App\Models\RoomOccupant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestBillingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. Create a Landlord (if not exists)
        $landlord = User::firstOrCreate(
            ['email' => 'landlord_test@gmail.com'],
            [
                'name' => 'Chủ Trọ Test',
                'password' => Hash::make('password'),
                'role' => '1', // 1 = admin/landlord
                'PhoneNumber' => '0999999999',
            ]
        );

        // 2. Create a Tenant
        $tenant = User::firstOrCreate(
            ['email' => 'tenant_test@gmail.com'],
            [
                'name' => 'Khách Thuê Test',
                'password' => Hash::make('password'),
                'role' => '0', // 0 = customer/tenant
                'PhoneNumber' => '0888888888',
            ]
        );

        // 3. Create a Room
        // Dùng firstOrCreate theo tên phòng để chạy nhiều lần không bị duplicate
        $room = Room::firstOrCreate(
            ['name' => 'Phòng Test 101', 'chutro_id' => $landlord->id],
            [
                'status' => 0, // 0 thường là đã thuê
                'price' => 3000000,
                'electric' => 3500,
                'water' => 20000,
                'add_ons' => json_encode([
                    ['name' => 'Dịch vụ Wifi', 'price' => 100000]
                ]),
                'describe_room' => 'Phòng test module Auto-Billing',
                'quantity' => 2,
                'area' => 30,
                'unit' => '1 Tháng',
            ]
        );

        // 4. Create an Active Contract
        $contract = Contract::firstOrCreate(
            [
                'room_id' => $room->id,
                'tenant_id' => $tenant->id,
                'status' => 'active'
            ],
            [
                'contract_code' => 'TEST-CTR-' . strtoupper(Str::random(6)),
                'landlord_id' => $landlord->id,
                'start_date' => now()->subMonths(2)->format('Y-m-d'),
                'end_date' => now()->addMonths(10)->format('Y-m-d'),
                'monthly_rent' => 3000000,
                'deposit_amount' => 3000000,
                'signed_at' => now()->subMonths(2),
            ]
        );

        // 5. Create Room Occupant
        RoomOccupant::firstOrCreate(
            [
                'contract_id' => $contract->id,
                'room_id' => $room->id,
                'user_id' => $tenant->id,
            ],
            [
                'full_name' => $tenant->name,
                'phone' => '0888888888',
                'identity_card_number' => '012345678912',
                'is_representative' => true,
            ]
        );

        $this->command->info('✅ Dữ liệu mẫu (TestBillingSeeder) đã được tạo thành công!');
        $this->command->info(' - Chủ trọ: landlord_test@gmail.com / password');
        $this->command->info(' - Khách thuê: tenant_test@gmail.com / password');
        $this->command->info(' - Hợp đồng Test: ' . $contract->contract_code . ' (Active)');
    }
}
