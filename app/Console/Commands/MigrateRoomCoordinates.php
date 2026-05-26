<?php

namespace App\Console\Commands;

use App\Models\Room;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateRoomCoordinates extends Command
{
    /**
     * Tên và signature của command.
     * Chạy bằng: php artisan rooms:migrate-coordinates
     */
    protected $signature = 'rooms:migrate-coordinates
                            {--dry-run : Chạy thử, không lưu vào DB}
                            {--chunk=100 : Số bản ghi xử lý mỗi batch}';

    protected $description = 'Trích xuất lat/lng từ cột JSON latlng và lưu vào cột latitude, longitude riêng biệt';

    public function handle(): int
    {
        $isDryRun  = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        $this->info('=== Bắt đầu migrate tọa độ phòng trọ ===');
        $isDryRun && $this->warn('[DRY RUN] Không có dữ liệu nào được lưu thực sự.');

        // Đếm tổng số phòng cần xử lý (chưa có tọa độ hoặc có JSON latlng)
        $total = Room::whereNotNull('latlng')->count();
        $this->info("Tổng số phòng có dữ liệu latlng JSON: {$total}");

        if ($total === 0) {
            $this->warn('Không có phòng nào cần migrate. Thoát.');
            return Command::SUCCESS;
        }

        $bar         = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | OK: %ok% | Lỗi: %error%');
        $bar->setMessage(0, 'ok');
        $bar->setMessage(0, 'error');
        $bar->start();

        $countOk    = 0;
        $countError = 0;
        $errorLogs  = [];

        Room::whereNotNull('latlng')
            ->orderBy('id')
            ->chunk($chunkSize, function ($rooms) use (
                $isDryRun, $bar, &$countOk, &$countError, &$errorLogs
            ) {
                foreach ($rooms as $room) {
                    try {
                        // latlng có thể là string JSON hoặc array (nếu đã cast)
                        $latlng = is_string($room->getRawOriginal('latlng'))
                            ? json_decode($room->getRawOriginal('latlng'), true)
                            : $room->latlng;

                        // Hỗ trợ cả 2 key: 'lng' (chuẩn) và 'long' (legacy)
                        $lngValue = $latlng['lng'] ?? $latlng['long'] ?? null;

                        // Kiểm tra cấu trúc hợp lệ
                        if (
                            empty($latlng) ||
                            !isset($latlng['lat']) ||
                            $lngValue === null ||
                            !is_numeric($latlng['lat']) ||
                            !is_numeric($lngValue)
                        ) {
                            throw new \Exception("latlng không hợp lệ: " . json_encode($latlng));
                        }

                        $lat = (float) $latlng['lat'];
                        $lng = (float) $lngValue;

                        // Kiểm tra range tọa độ hợp lý cho Việt Nam
                        if ($lat < 8.0 || $lat > 23.5 || $lng < 102.0 || $lng > 110.0) {
                            throw new \Exception(
                                "Tọa độ ngoài phạm vi Việt Nam: lat={$lat}, lng={$lng}"
                            );
                        }

                        if (!$isDryRun) {
                            // Dùng DB::table để tránh trigger events/observers không cần thiết
                            DB::table('rooms')->where('id', $room->id)->update([
                                'latitude'  => $lat,
                                'longitude' => $lng,
                            ]);
                        }

                        $countOk++;
                    } catch (\Exception $e) {
                        $countError++;
                        $errorLogs[] = "Room ID {$room->id}: " . $e->getMessage();
                    }

                    $bar->setMessage($countOk, 'ok');
                    $bar->setMessage($countError, 'error');
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        // --- Tổng kết ---
        $this->info("✅ Thành công : {$countOk} phòng");
        $this->warn("❌ Lỗi       : {$countError} phòng");

        if (!empty($errorLogs)) {
            $this->newLine();
            $this->error('Chi tiết các lỗi:');
            foreach ($errorLogs as $log) {
                $this->line("  • {$log}");
            }
        }

        if (!$isDryRun && $countOk > 0) {
            $this->newLine();
            $this->info('💡 Kiểm tra lại bằng lệnh:');
            $this->line('   SELECT id, latitude, longitude FROM rooms WHERE latitude IS NOT NULL LIMIT 10;');
        }

        return $countError > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
