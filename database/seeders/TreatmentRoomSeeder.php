<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TreatmentRoomSeeder extends Seeder
{
    /**
     * Seed treatment rooms — one room per service category so every
     * service has at least one bookable room.
     */
    public function run(): void
    {
        $categories = DB::table('service_category')->select('id', 'name', 'gender')->get();

        if ($categories->isEmpty()) {
            $this->command->warn('No service categories found — skipping treatment room seeding.');
            return;
        }

        $roomNumber = 1;
        $defaultRoomCounts = [
            'barber' => 2,
            'hair spa' => 2,
            'massage' => 4,
            'nail' => 3,
            'foot' => 3,
            'facial' => 2,
            'makeup' => 2,
            'muslimah' => 2,
        ];

        foreach ($categories as $cat) {
            $roomName = strtolower((string) $cat->name);
            $count = 2;
            foreach ($defaultRoomCounts as $needle => $rooms) {
                if (str_contains($roomName, $needle)) {
                    $count = $rooms;
                    break;
                }
            }

            for ($i = 1; $i <= $count; $i++) {
                $roomLabel = str_pad($roomNumber, 2, '0', STR_PAD_LEFT);
                $roomName = $cat->name . ' Room ' . $roomLabel;

                DB::table('treatment_room')->updateOrInsert(
                    ['room_name' => $roomName],
                    [
                        'room_type'   => $cat->name,
                        'category_id' => $cat->id,
                        'gender'      => $cat->gender ?? 'unisex',
                        'status'      => 'available',
                        'is_active'   => true,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]
                );

                $roomNumber++;
            }
        }

        $this->command->info("TreatmentRoomSeeder: created " . ($roomNumber - 1) . " rooms across {$categories->count()} categories.");
    }
}
