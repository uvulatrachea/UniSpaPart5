<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Service;
use App\Models\Slot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ScheduleController extends Controller
{
    private function servicePk(): string
    {
        // Support both schemas: service.id (new) or service.service_id (old SQL).
        return Schema::hasColumn('service', 'id') ? 'id' : 'service_id';
    }

    private function buildAvailableSlotsForDate(int $serviceId, int $duration, Collection $scheduleRows, Collection $busySlots, Collection $rooms, string $date, int $officeStart = 600, int $officeEnd = 1140, int $step = 30): array
    {
        $scheduleByStaff = $scheduleRows->groupBy('staff_id');
        $busyByStaff = $busySlots->whereNotNull('staff_id')->groupBy('staff_id');
        $busyByRoom = $busySlots->whereNotNull('room_id')->groupBy('room_id');

        $results = [];

        for ($t = $officeStart; $t + $duration <= $officeEnd; $t += $step) {
            $start = $this->minutesToTime($t);
            $end = $this->minutesToTime($t + $duration);

            $totalScheduledStaff = 0;
            $availableStaffCount = 0;
            foreach ($scheduleByStaff as $staffId => $ranges) {
                $onShift = $ranges->contains(function ($r) use ($t, $duration) {
                    $s = $this->timeToMinutes($r->start_time);
                    $e = $this->timeToMinutes($r->end_time);
                    return $t >= $s && ($t + $duration) <= $e;
                });
                if (!$onShift) {
                    continue;
                }

                $totalScheduledStaff++;

                $conflicts = $busyByStaff->get($staffId, collect())->contains(function ($b) use ($t, $duration) {
                    $bs = $this->timeToMinutes($b->start_time);
                    $be = $this->timeToMinutes($b->end_time);
                    return $this->overlap($t, $t + $duration, $bs, $be);
                });

                if ($conflicts) {
                    continue;
                }

                $availableStaffCount++;
            }

            // No staff scheduled at all for this window — skip silently (no slot to show).
            if ($totalScheduledStaff === 0) {
                continue;
            }

            $capacity = $availableStaffCount;
            $availableRoomCount = null;

            if ($rooms->isNotEmpty()) {
                $availableRoomCount = 0;
                foreach ($rooms as $room) {
                    $conflicts = $busyByRoom->get($room->room_id, collect())->contains(function ($b) use ($t, $duration) {
                        $bs = $this->timeToMinutes($b->start_time);
                        $be = $this->timeToMinutes($b->end_time);
                        return $this->overlap($t, $t + $duration, $bs, $be);
                    });

                    if ($conflicts) {
                        continue;
                    }

                    $availableRoomCount++;
                }

                $capacity = min($capacity, $availableRoomCount);
            }

            $maxCapacity = $rooms->isNotEmpty()
                ? min($totalScheduledStaff, $rooms->count())
                : $totalScheduledStaff;

            $bookedCount = $maxCapacity - max(0, $capacity);
            $status = $capacity > 0 ? 'available' : 'full';

            $results[] = [
                'slot_id' => 'TMP:' . $serviceId . ':' . $date . ':' . $start,
                'slot_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'status' => $status,
                'capacity' => max(0, $capacity),
                'max_capacity' => $maxCapacity,
                'booked_count' => $bookedCount,
            ];
        }

        return $results;
    }

    public function show()
    {
        $customerId = auth('customer')->user()->customer_id;

        $cart = Cart::where('customer_id', $customerId)->first();
        $items = $cart?->items ?? [];

        $serviceIds = collect($items)->pluck('service_id')->filter()->unique()->values()->all();

        $servicePk = $this->servicePk();
        $services = DB::table('service')
            ->whereIn($servicePk, $serviceIds)
            ->get()
            ->keyBy($servicePk);

        $cartItems = collect($items)->map(function ($it, $idx) use ($services) {
            $s = $services->get($it['service_id'] ?? null);

            return [
                'index' => $idx,
                'service_id' => (int)($it['service_id'] ?? 0),
                'pax' => (int)($it['quantity'] ?? $it['pax'] ?? 1),
                'slot_id' => $it['slot_id'] ?? null,
                'service' => $s ? [
                    // Frontend expects `service.id`
                    'id' => (int)($s->id ?? $s->service_id),
                    'name' => $s->name,
                    'price' => (float)$s->price,
                    'duration_minutes' => (int)$s->duration_minutes,
                    'image_url' => $s->image_url ?? null,
                    'category_id' => (int)($s->category_id ?? 0),
                ] : null,
            ];
        })->values();

        return Inertia::render('Booking/Schedule', [
            'cartItems' => $cartItems,
        ]);
    }

    // POST /booking/slots
    public function slots(Request $request)
    {
        $data = $request->validate([
            'service_id' => ['required','integer'],
            'date' => ['required','date'],
        ]);

        // NEW: slots are derived from (approved) staff schedule + room availability.
        // We return synthetic slot ids (TMP:...) and create the real slot row later
        // when payment is initiated (see PaymentController).

        $service = DB::table('service')->where('id', (int) $data['service_id'])->first();
        if (!$service) {
            return response()->json(['slots' => []]);
        }

        $duration = (int) ($service->duration_minutes ?? 60);
        $duration = max(15, min(240, $duration));

        $date = (string) $data['date'];
        $serviceCategoryId = property_exists($service, 'category_id') ? (int) $service->category_id : null;

        $hasApproval = Schema::hasTable('schedule') && Schema::hasColumn('schedule', 'approval_status');
        $hasRooms = Schema::hasTable('treatment_room');

        if (!Schema::hasTable('staff') || !Schema::hasTable('schedule')) {
            return response()->json(['slots' => []]);
        }

        $scheduleRows = DB::table('schedule as sc')
            ->join('staff as st', 'st.staff_id', '=', 'sc.staff_id')
            ->where('st.work_status', 'active')
            ->whereDate('sc.schedule_date', $date)
            ->where('sc.status', 'active')
            ->when($hasApproval, fn ($q) => $this->applyApprovedScheduleFilter($q, 'sc.approval_status'))
            ->get(['sc.staff_id', 'sc.start_time', 'sc.end_time']);

        if ($scheduleRows->isEmpty()) {
            return response()->json(['slots' => []]);
        }

        $busySlots = Schema::hasTable('slot')
            ? DB::table('slot')
                ->whereDate('slot_date', $date)
                ->whereIn('status', ['held', 'booked'])
                ->get(['staff_id', Schema::hasColumn('slot', 'room_id') ? 'room_id' : DB::raw('NULL as room_id'), 'start_time', 'end_time'])
            : collect();

        $rooms = collect();
        if ($hasRooms) {
            $rooms = DB::table('treatment_room')
                ->when(Schema::hasColumn('treatment_room', 'is_active'), fn ($q) => $q->where('is_active', true))
                ->when(Schema::hasColumn('treatment_room', 'status'), fn ($q) => $q->where('status', '!=', 'maintenance'))
                ->when(Schema::hasColumn('treatment_room', 'category_id') && $serviceCategoryId, fn ($q) => $q->where('category_id', $serviceCategoryId))
                ->orderBy('room_id')
                ->get([
                    'room_id',
                    Schema::hasColumn('treatment_room', 'status') ? 'status' : DB::raw("'available' as status"),
                    Schema::hasColumn('treatment_room', 'category_id') ? 'category_id' : DB::raw('NULL as category_id'),
                ]);

            if ($rooms->isEmpty()) {
                $rooms = $this->getDefaultRoomsForCategory($serviceCategoryId);
            }
        }

        $results = $this->buildAvailableSlotsForDate((int)$data['service_id'], $duration, $scheduleRows, $busySlots, $rooms, $date);

        return response()->json(['slots' => $results]);
    }

    private function applyApprovedScheduleFilter($query, string $column)
    {
        $query->where(function ($q) use ($column) {
            $q->where($column, 'approved')
                ->orWhereNull($column)
                ->orWhere($column, '');
        });
    }

    private function getDefaultRoomsForCategory(?int $serviceCategoryId): Collection
    {
        if (!Schema::hasTable('service_category')) {
            return collect();
        }

        $category = DB::table('service_category')
            ->where('id', $serviceCategoryId)
            ->first(['name', 'gender']);

        $name = strtolower((string) ($category->name ?? ''));
        $gender = $category->gender ?? 'unisex';

        $roomCounts = [
            'barber' => 2,
            'hair spa' => 2,
            'massage' => 4,
            'nail' => 3,
            'foot' => 3,
            'facial' => 2,
            'makeup' => 2,
            'muslimah' => 2,
        ];

        $count = 2;
        foreach ($roomCounts as $needle => $rooms) {
            if (str_contains($name, $needle)) {
                $count = $rooms;
                break;
            }
        }

        $rooms = [];
        for ($i = 1; $i <= $count; $i++) {
            $rooms[] = (object) [
                'room_id' => $i,
                'status' => 'available',
                'category_id' => $serviceCategoryId,
                'gender' => $gender,
            ];
        }

        return collect($rooms);
    }

    // GET /booking/slots/month?service_id=3&month=2026-03-01
    public function monthAvailability(Request $request)
    {
        $data = $request->validate([
            'service_id' => ['required','integer'],
            'month' => ['required','date'], // first day of month
        ]);

        $start = date('Y-m-01', strtotime($data['month']));
        $end   = date('Y-m-t', strtotime($data['month']));

        // NEW: month availability is derived from approved schedules.
        // If there is at least 1 approved schedule on a day, the date is enabled.
        $hasApproval = Schema::hasTable('schedule') && Schema::hasColumn('schedule', 'approval_status');

        if (!Schema::hasTable('schedule') || !Schema::hasTable('staff')) {
            return response()->json(['dates' => []]);
        }

        $scheduleRows = DB::table('schedule as sc')
            ->join('staff as st', 'st.staff_id', '=', 'sc.staff_id')
            ->where('st.work_status', 'active')
            ->whereBetween('sc.schedule_date', [$start, $end])
            ->where('sc.status', 'active')
            ->when($hasApproval, fn ($q) => $this->applyApprovedScheduleFilter($q, 'sc.approval_status'))
            ->get(['sc.staff_id', 'sc.schedule_date', 'sc.start_time', 'sc.end_time']);

        if ($scheduleRows->isEmpty()) {
            return response()->json(['dates' => []]);
        }

        $busySlots = Schema::hasTable('slot')
            ? DB::table('slot')
                ->whereBetween('slot_date', [$start, $end])
                ->whereIn('status', ['held', 'booked'])
                ->get(['staff_id', Schema::hasColumn('slot', 'room_id') ? 'room_id' : DB::raw('NULL as room_id'), 'slot_date', 'start_time', 'end_time'])
            : collect();

        $service = DB::table('service')->where('id', (int) $data['service_id'])->first();
        if (!$service) {
            return response()->json(['dates' => []]);
        }

        $duration = (int) ($service->duration_minutes ?? 60);
        $duration = max(15, min(240, $duration));
        $serviceCategoryId = property_exists($service, 'category_id') ? (int) $service->category_id : null;
        $hasRooms = Schema::hasTable('treatment_room');

        $rooms = collect();
        if ($hasRooms) {
            $rooms = DB::table('treatment_room')
                ->when(Schema::hasColumn('treatment_room', 'is_active'), fn ($q) => $q->where('is_active', true))
                ->when(Schema::hasColumn('treatment_room', 'status'), fn ($q) => $q->where('status', '!=', 'maintenance'))
                ->when(Schema::hasColumn('treatment_room', 'category_id') && $serviceCategoryId, fn ($q) => $q->where('category_id', $serviceCategoryId))
                ->orderBy('room_id')
                ->get([
                    'room_id',
                    Schema::hasColumn('treatment_room', 'status') ? 'status' : DB::raw("'available' as status"),
                    Schema::hasColumn('treatment_room', 'category_id') ? 'category_id' : DB::raw('NULL as category_id'),
                ]);

            if ($rooms->isEmpty()) {
                $rooms = $this->getDefaultRoomsForCategory($serviceCategoryId);
            }
        }

        $dates = [];
        foreach ($scheduleRows->groupBy('schedule_date') as $date => $rows) {
            $dayBusySlots = $busySlots->where('slot_date', $date);
            $availableSlots = $this->buildAvailableSlotsForDate((int)$data['service_id'], $duration, $rows, $dayBusySlots, $rooms, (string) $date);
            if (!empty($availableSlots)) {
                $dates[] = (string) $date;
            }
        }

        return response()->json(['dates' => $dates]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate([
            'picks' => ['required','array','min:1'],
            'picks.*.index' => ['required','integer','min:0'],
            'picks.*.slot_id' => ['required','string'],
        ]);

        $customerId = auth('customer')->user()->customer_id;
        $cart = Cart::where('customer_id', $customerId)->firstOrFail();
        $items = $cart->items ?? [];

        // Save chosen slot_id into each cart item
        foreach ($data['picks'] as $pick) {
            $idx = $pick['index'];
            if (!isset($items[$idx])) continue;
            $items[$idx]['slot_id'] = (string)$pick['slot_id'];
        }

        $cart->update(['items' => $items]);

        // Build participant data so the payment page can use it
        $customer = auth('customer')->user();
        $bookingGuests = collect($items)->map(function ($it, $idx) use ($customer) {
            $participants = collect($it['participants'] ?? [])->map(function ($p) use ($customer) {
                return [
                    'is_self' => (bool)($p['is_self'] ?? false),
                    'name' => trim((string)($p['name'] ?? '')),
                    'phone' => trim((string)($p['phone'] ?? '')),
                    'email' => $p['email'] ?? null,
                    'is_uitm_member' => (bool)($p['is_uitm_member'] ?? false),
                ];
            })->values();

            if ($participants->isEmpty()) {
                $participants = collect([[
                    'is_self' => true,
                    'name' => $customer->name,
                    'phone' => $customer->phone ?? '',
                    'email' => $customer->email,
                    'is_uitm_member' => (bool)($customer->is_uitm_member ?? false),
                ]]);
            }

            return [
                'bookingIndex' => $idx,
                'list' => $participants->all(),
            ];
        })->values()->all();

        session()->put('booking_guests', $bookingGuests);

        $hasAdditionalGuests = collect($bookingGuests)
            ->contains(fn($row) => collect($row['list'] ?? [])->contains(fn($p) => !($p['is_self'] ?? false)));

        return redirect()->route($hasAdditionalGuests ? 'booking.guests' : 'booking.payment');
    }

    private function timeToMinutes($t): int
    {
        [$h, $m] = array_map('intval', explode(':', substr((string) $t, 0, 5)));
        return $h * 60 + $m;
    }

    private function minutesToTime(int $minutes): string
    {
        $h = (int) floor($minutes / 60);
        $m = $minutes % 60;
        return str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }

    private function overlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $aEnd > $bStart;
    }
}
