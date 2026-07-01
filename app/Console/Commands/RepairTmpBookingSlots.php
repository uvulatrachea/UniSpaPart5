<?php

namespace App\Console\Commands;

use App\Http\Controllers\Booking\PaymentController;
use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairTmpBookingSlots extends Command
{
    protected $signature = 'booking:repair-tmp-slots {--dry-run : Show what would be repaired without updating bookings}';

    protected $description = 'Convert bookings with TMP slot IDs into persisted slot rows';

    public function handle(PaymentController $payments): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $bookings = Booking::query()
            ->where('slot_id', 'like', 'TMP:%')
            ->orderBy('booking_id')
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('No TMP booking slots found.');
            return self::SUCCESS;
        }

        $repaired = 0;
        $failed = 0;

        foreach ($bookings as $booking) {
            $parts = explode(':', (string) $booking->slot_id, 4);
            $serviceId = (int) ($parts[1] ?? 0);

            if ($serviceId <= 0) {
                $failed++;
                $this->warn("{$booking->booking_id}: invalid TMP slot id {$booking->slot_id}");
                continue;
            }

            try {
                if ($dryRun) {
                    $this->line("{$booking->booking_id}: would allocate real slot for {$booking->slot_id}");
                    continue;
                }

                DB::transaction(function () use ($booking, $payments, $serviceId) {
                    $realSlotId = $payments->allocateRealSlotId((string) $booking->slot_id, $serviceId);
                    $booking->slot_id = $realSlotId;
                    $booking->save();

                    if ($booking->payment_status === 'paid' || in_array($booking->status, ['accepted', 'confirmed'], true)) {
                        DB::table('slot')->where('slot_id', $realSlotId)->update(['status' => 'booked']);
                    }
                });

                $repaired++;
                $this->info("{$booking->booking_id}: repaired");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$booking->booking_id}: {$e->getMessage()}");
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete. TMP bookings found: {$bookings->count()}.");
            return self::SUCCESS;
        }

        $this->info("Repair complete. Repaired: {$repaired}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
