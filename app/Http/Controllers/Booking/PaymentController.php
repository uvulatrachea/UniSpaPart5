<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Cart;
use App\Models\Service;
use App\Models\Slot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function allocateRealSlotId(string $pickedSlotId, int $serviceId): string
    {
        // If frontend picked a real slot_id, keep it.
        if (!str_starts_with($pickedSlotId, 'TMP:')) {
            return $pickedSlotId;
        }

        // Format: TMP:{service_id}:{YYYY-MM-DD}:{HH:MM}
        $parts = explode(':', $pickedSlotId, 4);
        if (count($parts) < 4) {
            throw new \RuntimeException('Invalid slot selection.');
        }

        $tmpServiceId = (int) ($parts[1] ?? 0);
        $date = (string) ($parts[2] ?? '');
        $start = (string) ($parts[3] ?? '');

        if ($tmpServiceId !== (int) $serviceId) {
            throw new \RuntimeException('Invalid slot selection for this service.');
        }

        $service = DB::table('service')->where('id', (int) $serviceId)->first();
        if (!$service) {
            throw new \RuntimeException('Service not found.');
        }

        $duration = (int) ($service->duration_minutes ?? 60);
        $duration = max(15, min(240, $duration));
        $end = date('H:i', strtotime($start . ':00') + $duration * 60);

        // Find candidate staff on approved shift
        $hasApproval = Schema::hasTable('schedule') && Schema::hasColumn('schedule', 'approval_status');

        $candidateStaffIds = DB::table('schedule as sc')
            ->join('staff as st', 'st.staff_id', '=', 'sc.staff_id')
            ->where('st.work_status', 'active')
            ->whereDate('sc.schedule_date', $date)
            ->where('sc.status', 'active')
            ->when($hasApproval, fn($q) => $q->where(function ($q) {
                $q->where('sc.approval_status', 'approved')
                    ->orWhereNull('sc.approval_status')
                    ->orWhere('sc.approval_status', '');
            }))
            ->where('sc.start_time', '<=', $start)
            ->where('sc.end_time', '>=', $end)
            ->select('sc.staff_id')
            ->distinct()
            ->pluck('sc.staff_id');

        if ($candidateStaffIds->isEmpty()) {
            throw new \RuntimeException('No staff available for the selected time.');
        }

        // Candidate rooms (optional — if treatment_room table is empty/missing, allow staff-only slots)
        $hasRoomTable = Schema::hasTable('treatment_room');
        $rooms = collect();

        if ($hasRoomTable) {
            $serviceCategoryId = property_exists($service, 'category_id') ? (int) $service->category_id : null;
            $rooms = DB::table('treatment_room')
                ->when(Schema::hasColumn('treatment_room', 'is_active'), fn($q) => $q->where('is_active', true))
                ->when(Schema::hasColumn('treatment_room', 'status'), fn($q) => $q->where('status', '!=', 'maintenance'))
                ->when(Schema::hasColumn('treatment_room', 'category_id') && $serviceCategoryId, fn($q) => $q->where('category_id', $serviceCategoryId))
                ->orderBy('room_id')
                ->pluck('room_id');

            if ($rooms->isEmpty()) {
                $rooms = collect();
                $defaultRooms = $this->getDefaultRoomsForCategory($serviceCategoryId);
                foreach ($defaultRooms as $room) {
                    $rooms->push($room->room_id);
                }
            }
        }

        // Busy slots on that date/time
        $hasRoomCol = Schema::hasColumn('slot', 'room_id');
        $busy = DB::table('slot')
            ->whereDate('slot_date', $date)
            ->whereIn('status', ['held', 'booked'])
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->get(['staff_id', $hasRoomCol ? 'room_id' : DB::raw('NULL as room_id')]);

        $busyStaff = $busy->pluck('staff_id')->filter()->map(fn($v) => (string) $v)->unique()->flip();
        $busyRooms = $busy->pluck('room_id')->filter()->map(fn($v) => (string) $v)->unique()->flip();

        $chosenStaffId = null;
        foreach ($candidateStaffIds as $sid) {
            if (!$busyStaff->has((string) $sid)) {
                $chosenStaffId = (int) $sid;
                break;
            }
        }
        if (!$chosenStaffId) {
            throw new \RuntimeException('Selected time is no longer available (staff busy).');
        }

        // Room allocation (only if rooms exist)
        $chosenRoomId = null;
        if ($rooms->isNotEmpty()) {
            foreach ($rooms as $rid) {
                if (!$busyRooms->has((string) $rid)) {
                    $chosenRoomId = (int) $rid;
                    break;
                }
            }
            if (!$chosenRoomId) {
                throw new \RuntimeException('Selected time is no longer available (room busy).');
            }
        }

        // Create real slot row (status=held). Must satisfy unique constraints.
        $realSlotId = 'SL' . strtoupper(Str::random(10));

        $slotRow = [
            'slot_id' => $realSlotId,
            'service_id' => (int) $serviceId,
            'staff_id' => $chosenStaffId,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'held',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($hasRoomCol && $chosenRoomId) {
            $slotRow['room_id'] = $chosenRoomId;
        }

        DB::table('slot')->insert($slotRow);

        return $realSlotId;
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

    private function generateBookingReceipt(Booking $booking, ?string $paymentReference = null): string
    {
        $service = $booking->slot?->service?->name ?: 'Spa Service';
        $customer = $booking->customer?->name ?: 'Customer';
        $date = optional($booking->slot?->slot_date)?->toDateString() ?: '-';
        $start = (string)($booking->slot?->start_time ?: '-');
        $end = (string)($booking->slot?->end_time ?: '-');

        $html = "<html><head><meta charset='utf-8'><title>Receipt {$booking->booking_id}</title></head><body style='font-family:Arial,sans-serif;padding:20px;'>"
            . "<h2>UniSpa Booking Receipt</h2>"
            . "<p><strong>Booking ID:</strong> {$booking->booking_id}</p>"
            . "<p><strong>Customer:</strong> {$customer}</p>"
            . "<p><strong>Service:</strong> {$service}</p>"
            . "<p><strong>Date:</strong> {$date}</p>"
            . "<p><strong>Time:</strong> {$start} - {$end}</p>"
            . "<p><strong>Final Amount:</strong> RM " . number_format((float)($booking->final_amount ?? $booking->total_amount ?? 0), 2) . "</p>"
            . "<p><strong>Deposit:</strong> RM " . number_format((float)($booking->deposit_amount ?? 0), 2) . "</p>"
            . "<p><strong>Payment Method:</strong> " . e((string)($booking->payment_method ?: '-')) . "</p>"
            . "<p><strong>Payment Status:</strong> " . e((string)($booking->payment_status ?: '-')) . "</p>"
            . "<p><strong>Reference:</strong> " . e((string)($paymentReference ?: '-')) . "</p>"
            . "<hr><p>Generated at " . now()->toDateTimeString() . "</p></body></html>";

        $base = 'receipts/' . $booking->booking_id . '-' . now()->format('YmdHis');

        $dompdfClass = 'Dompdf\Dompdf';
        if (class_exists($dompdfClass)) {
            $dompdf = new $dompdfClass();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            $dompdf->render();

            $path = $base . '.pdf';
            Storage::disk('public')->put($path, $dompdf->output());

            return $path;
        }

        $path = $base . '.html';
        Storage::disk('public')->put($path, $html);

        return $path;
    }

    private function bookingRecipients(Booking $booking): array
    {
        $emails = collect();

        if (!empty($booking->customer?->email)) {
            $emails->push([
                'email' => strtolower(trim((string) $booking->customer->email)),
                'name' => $booking->customer->name ?: 'Customer',
            ]);
        }

        foreach ($booking->participants ?? [] as $participant) {
            $email = strtolower(trim((string) ($participant->email ?? '')));
            if ($email === '') {
                continue;
            }

            $emails->push([
                'email' => $email,
                'name' => $participant->name ?: 'Guest',
            ]);
        }

        return $emails
            ->unique(fn($row) => $row['email'])
            ->values()
            ->all();
    }

    private function sendBookingEmails(Collection $bookings, bool $pendingReview = false): void
    {
        foreach ($bookings as $booking) {
            $recipients = $this->bookingRecipients($booking);

            foreach ($recipients as $recipient) {
                Mail::to($recipient['email'])->send(
                    new BookingConfirmedMail(
                        booking: $booking,
                        isPendingReview: $pendingReview,
                        recipientName: $recipient['name']
                    )
                );
            }
        }
    }

    private function stripeMockEnabled(): bool
    {
        return filter_var(config('services.stripe.mock', false), FILTER_VALIDATE_BOOL);
    }

    private function deposit30($amount): float
    {
        return round(((float)$amount) * 0.30, 2);
    }

    private function makeBookingId(): string
    {
        // e.g. BK7F3A2C9D
        return 'BK' . strtoupper(Str::random(8));
    }

    public function show()
    {
        $customerId = auth('customer')->user()->customer_id;
        $cart = Cart::where('customer_id', $customerId)->first();
        $items = $cart?->items ?? [];

        if (empty($items)) {
            return redirect()->route('booking.cart')
                ->with('error', 'Your cart is empty. Please add at least one service before payment.');
        }

        // Must have slot chosen for all items
        foreach ($items as $i => $it) {
            if (empty($it['slot_id'])) {
                return redirect()->route('booking.schedule')
                    ->with('error', "Please choose schedule for item #" . ($i + 1));
            }
        }

        // Ensure participant data exists (self-only fallback) so payment + receipt can proceed.
        $bookingGuests = session()->get('booking_guests', []);
        if (empty($bookingGuests)) {
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
        }

        // Build preview totals (no DB writes yet)
        $serviceIds = collect($items)->pluck('service_id')->unique()->values()->all();
        $services = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        $slotIds = collect($items)->pluck('slot_id')->unique()->values()->all();
        $realSlotIds = collect($slotIds)->filter(fn($id) => !str_starts_with((string) $id, 'TMP:'))->values()->all();
        $slots = empty($realSlotIds) ? collect() : Slot::whereIn('slot_id', $realSlotIds)->get()->keyBy('slot_id');

        $customer = auth('customer')->user();
        $isUitmMember = $customer && !empty($customer->is_uitm_member);

        $preview = collect($items)->map(function ($it, $idx) use ($services, $slots, $isUitmMember) {
            $s = $services->get((int)($it['service_id'] ?? 0));
            $sl = str_starts_with((string) ($it['slot_id'] ?? ''), 'TMP:') ? null : $slots->get($it['slot_id']);

            $unit = $s ? (float)$s->price : 0;
            $pax = (int)($it['quantity'] ?? $it['pax'] ?? 1);
            $subtotal = round($unit * $pax, 2);
            // UiTM members get 10% off
            $finalAmount = $subtotal;
            if ($isUitmMember && $subtotal > 0) {
                $finalAmount = round($subtotal * 0.90, 2);
            }

            return [
                'index' => $idx,
                'service' => $s ? [
                    'service_id' => $s->id,
                    'name' => $s->name,
                    'price' => (float)$s->price,
                    'duration_minutes' => (int)$s->duration_minutes,
                    'image_url' => $s->image_url,
                ] : null,
                'slot' => $sl ? [
                    'slot_id' => (string)$sl->slot_id,
                    'slot_date' => (string)$sl->slot_date,
                    'start_time' => (string)$sl->start_time,
                    'end_time' => (string)$sl->end_time,
                ] : $this->parseTmpSlot((string)($it['slot_id'] ?? '')),
                'pax' => $pax,
                'final_amount' => $finalAmount,
                'deposit_amount' => $this->deposit30($finalAmount),
            ];
        })->values();

        $totalFinal = round($preview->sum('final_amount'), 2);

        // Apply session promo discount
        $appliedPromo = session('applied_promo');
        $promoDiscountAmount = 0.0;
        if ($appliedPromo && $totalFinal > 0) {
            if (($appliedPromo['discount_type'] ?? '') === 'percentage') {
                $promoDiscountAmount = round($totalFinal * ((float)($appliedPromo['discount_value'] ?? 0) / 100), 2);
            } else {
                $promoDiscountAmount = min(round((float)($appliedPromo['discount_value'] ?? 0), 2), $totalFinal);
            }
            $totalFinal = max(0, round($totalFinal - $promoDiscountAmount, 2));
        }

        $totalDeposit = $this->deposit30($totalFinal);

        return Inertia::render('Booking/Payment', [
            'preview' => $preview,
            'totalFinal' => $totalFinal,
            'totalDeposit' => $totalDeposit,
            'qrUploadUrl' => route('booking.payment.qr.upload'),
            'appliedPromo' => $appliedPromo ? [
                'code' => $appliedPromo['code'],
                'title' => $appliedPromo['title'],
                'discount_type' => $appliedPromo['discount_type'],
                'discount_value' => $appliedPromo['discount_value'],
                'promo_discount_amount' => $promoDiscountAmount,
            ] : null,
        ]);
    }

    private function parseTmpSlot(string $tmpId): ?array
    {
        if (!str_starts_with($tmpId, 'TMP:')) {
            return null;
        }

        $parts = explode(':', $tmpId, 4);
        if (count($parts) < 4) {
            return null;
        }

        $date = (string) ($parts[2] ?? '');
        $start = (string) ($parts[3] ?? '');
        if ($date === '' || $start === '') {
            return null;
        }

        // end_time is not known here without DB call; frontend already has it in Schedule.
        return [
            'slot_id' => $tmpId,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => null,
        ];
    }

    private function parseTmpSlotId(string $tmpId): ?array
    {
        if (!str_starts_with($tmpId, 'TMP:')) {
            return null;
        }

        $parts = explode(':', $tmpId, 4);
        if (count($parts) < 4) {
            return null;
        }

        return [
            'service_id' => (int) ($parts[1] ?? 0),
            'date' => (string) ($parts[2] ?? ''),
            'start_time' => (string) ($parts[3] ?? ''),
        ];
    }

    private function ensureBookingHasRealSlot(Booking $booking): Booking
    {
        if (!str_starts_with((string) $booking->slot_id, 'TMP:')) {
            return $booking;
        }

        $tmpData = $this->parseTmpSlotId((string) $booking->slot_id);
        if (empty($tmpData['service_id'])) {
            throw new \RuntimeException('Slot data is invalid for this booking.');
        }

        $booking->slot_id = $this->allocateRealSlotId((string) $booking->slot_id, (int) $tmpData['service_id']);
        $booking->save();

        return $booking->refresh();
    }

    /**
     * Create bookings for QR payment — NO staff/room allocation.
     * Stores TMP slot id as-is; staff will allocate real slot after approving receipt.
     */
    private function createBookingsFromCartForQr(): array
    {
        $customer = auth('customer')->user();
        $customerId = $customer->customer_id;
        $isUitmMember = !empty($customer->is_uitm_member);

        $cart = Cart::where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
        $items = $cart->items ?? [];

        if (empty($items)) {
            throw new \RuntimeException('Your cart is empty. Please add at least one service.');
        }

        $serviceIds = collect($items)->pluck('service_id')->unique()->values()->all();
        $services = Service::whereIn('id', $serviceIds)->get()->keyBy('id');
        $bookingGuests = session()->get('booking_guests', []);
        $created = [];

        foreach ($items as $idx => $it) {
            $service = $services->get((int)($it['service_id'] ?? 0));
            if (!$service) {
                throw new \RuntimeException('One of the selected services is invalid or no longer available.');
            }
            if (empty($it['slot_id'])) {
                throw new \RuntimeException('Please choose a schedule time before payment.');
            }

            // Keep TMP slot id — staff allocates the real slot after receipt approval
            $slotId = (string) $it['slot_id'];

            $unit = (float)$service->price;
            $pax = (int)($it['quantity'] ?? $it['pax'] ?? 1);
            $subtotal = round($unit * $pax, 2);
            $discountAmount = 0.0;
            $final = $subtotal;
            if ($isUitmMember && $subtotal > 0) {
                $discountAmount = round($subtotal * 0.10, 2);
                $final = round($subtotal - $discountAmount, 2);
            }

            $deposit = $this->deposit30($final);
            $bookingId = $this->makeBookingId();

            Booking::create([
                'booking_id'      => $bookingId,
                'customer_id'     => $customerId,
                'slot_id'         => $slotId,
                'total_amount'    => $subtotal,
                'discount_amount' => $discountAmount,
                'final_amount'    => $final,
                'deposit_amount'  => $deposit,
                'status'          => 'pending_approval',
                'payment_status'  => 'pending',
            ]);

            $row = collect($bookingGuests)->firstWhere('bookingIndex', $idx);
            foreach ($row['list'] ?? [] as $p) {
                BookingParticipant::create([
                    'booking_id'     => $bookingId,
                    'is_self'        => (bool)$p['is_self'],
                    'name'           => $p['name'],
                    'phone'          => $p['phone'],
                    'email'          => $p['email'] ?? null,
                    'is_uitm_member' => (bool)$p['is_uitm_member'],
                    'discount_amount'=> 0,
                ]);
            }

            $created[] = $bookingId;
        }

        $cart->update(['items' => []]);
        return $created;
    }

    /**
     * Create bookings in DB and return array booking_ids.
     * We do this only once right before payment (Stripe flow — allocates real slot).
     */
    private function createBookingsFromCart(bool $strictValidation = true): array
    {
        $customer = auth('customer')->user();
        $customerId = $customer->customer_id;
        $isUitmMember = !empty($customer->is_uitm_member);

        $cart = Cart::where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
        $items = $cart->items ?? [];

        if ($strictValidation && empty($items)) {
            throw new \RuntimeException('Your cart is empty. Please add at least one service.');
        }

        $serviceIds = collect($items)->pluck('service_id')->unique()->values()->all();
        $services = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

        $bookingGuests = session()->get('booking_guests', []);

        $created = [];

        foreach ($items as $idx => $it) {
            $service = $services->get((int)($it['service_id'] ?? 0));
            if ($strictValidation && !$service) {
                throw new \RuntimeException('One of the selected services is invalid or no longer available.');
            }

            // Allocate slot if TMP id is provided
            if (empty($it['slot_id'])) {
                throw new \RuntimeException('Please choose a schedule time before payment.');
            }

            $realSlotId = $this->allocateRealSlotId((string) $it['slot_id'], (int) ($service->id ?? 0));

            $unit = $service ? (float)$service->price : 0;
            $pax = (int)($it['quantity'] ?? $it['pax'] ?? 1);
            $subtotal = round($unit * $pax, 2);
            // UiTM members get 10% off
            $discountAmount = 0.0;
            $final = $subtotal;
            if ($isUitmMember && $subtotal > 0) {
                $discountAmount = round($subtotal * 0.10, 2);
                $final = round($subtotal - $discountAmount, 2);
            }

            if ($strictValidation && $final <= 0) {
                throw new \RuntimeException('Selected service amount is 0. Please re-add the service in cart.');
            }

            $deposit = $this->deposit30($final);

            $bookingId = $this->makeBookingId();

            Booking::create([
                'booking_id' => $bookingId,
                'customer_id' => $customerId,
                'slot_id' => (string)$realSlotId,
                'total_amount' => $subtotal,
                'discount_amount' => $discountAmount,
                'final_amount' => $final,
                'deposit_amount' => $deposit,
                'status' => 'pending',
                'payment_status' => 'pending',
            ]);

            // Save participants for this booking index (if any)
            $row = collect($bookingGuests)->firstWhere('bookingIndex', $idx);
            $list = $row['list'] ?? [];

            foreach ($list as $p) {
                BookingParticipant::create([
                    'booking_id' => $bookingId,
                    'is_self' => (bool)$p['is_self'],
                    'name' => $p['name'],
                    'phone' => $p['phone'],
                    'email' => $p['email'] ?? null,
                    'is_uitm_member' => (bool)$p['is_uitm_member'],
                    'discount_amount' => 0,
                ]);
            }

            $created[] = $bookingId;
        }

        // Optional: clear cart after creating bookings
        $cart->update(['items' => []]);

        return $created;
    }

    // ========= STRIPE =========
    public function createStripeSession(Request $request)
    {
        // Install: composer require stripe/stripe-php
        $request->validate([]);

        $forceMock = $request->boolean('mock') || $this->stripeMockEnabled();

        if ($forceMock) {
            try {
                $bookingIds = DB::transaction(function () {
                    return $this->createBookingsFromCart();
                });
            } catch (\Throwable $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            // Mark as paid immediately in mock mode
            Booking::whereIn('booking_id', $bookingIds)->update([
                'payment_method' => 'stripe',
                'payment_status' => 'paid',
                'status'         => 'confirmed',
            ]);
            DB::table('slot')
                ->whereIn('slot_id', Booking::whereIn('booking_id', $bookingIds)->pluck('slot_id'))
                ->update(['status' => 'booked']);

            session()->flash('success', 'Mock payment successful! Your booking is now confirmed.');

            return response()->json([
                'mock'        => true,
                'booking_ids' => $bookingIds,
                'id'          => 'mock_' . Str::uuid()->toString(),
                'url'         => route('bookings.index'),
            ]);
        }

        $stripeSecret = (string) config('services.stripe.secret');
        if (blank($stripeSecret) || str_contains($stripeSecret, 'xxxxx')) {
            return response()->json([
                'error' => 'Stripe is not configured yet. Please set a real STRIPE_SECRET key in .env.',
            ], 422);
        }

        try {
            $session = DB::transaction(function () use ($stripeSecret) {
                $bookingIds = $this->createBookingsFromCart();

                $bookings = Booking::whereIn('booking_id', $bookingIds)->get();
                $depositTotal = round($bookings->sum('deposit_amount'), 2);

                if ($depositTotal <= 0) {
                    throw new \RuntimeException('Deposit amount must be greater than 0.');
                }

                \Stripe\Stripe::setApiKey($stripeSecret);

                return \Stripe\Checkout\Session::create([
                    'mode' => 'payment',
                    'line_items' => [[
                        'price_data' => [
                            'currency' => 'myr',
                            'product_data' => ['name' => 'UniSpa Booking Deposit (30%)'],
                            'unit_amount' => (int) round($depositTotal * 100), // cents
                        ],
                        'quantity' => 1,
                    ]],
                    'metadata' => [
                        'booking_ids' => implode(',', $bookingIds),
                    ],
                    'success_url' => route('booking.payment.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('booking.payment.stripe.cancel'),
                ]);
            });

            // Return session id for frontend stripe redirect
            return response()->json([
                'id' => $session->id,
                'url' => $session->url,
            ]);
        } catch (\Stripe\Exception\AuthenticationException $e) {
            Log::error('Stripe authentication failed while creating checkout session.', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid Stripe secret key. Please check STRIPE_SECRET in .env.',
            ], 422);
        } catch (\RuntimeException $e) {
            Log::warning('Stripe checkout blocked due to invalid booking/cart state.', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout session creation failed.', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Unable to create Stripe checkout session. Please try again.',
            ], 500);
        }
    }

    public function stripeSuccess(Request $request)
    {
        $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $bookingIds = [];
        $receiptId = $request->session_id;
        $isMock = $this->stripeMockEnabled()
            || $request->boolean('mock')
            || str_starts_with($request->session_id, 'mock_');

        if ($isMock) {
            $bookingIds = array_filter(explode(',', (string) $request->query('booking_ids', '')));

            if (empty($bookingIds)) {
                $bookingIds = session()->pull('mock_payment_booking_ids', []);
            }
        } else {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $session = \Stripe\Checkout\Session::retrieve($request->session_id);
            $receiptId = $session->id;

            if (!empty($session->metadata?->booking_ids)) {
                $bookingIds = array_filter(explode(',', $session->metadata->booking_ids));
            }
        }

        if ($bookingIds) {
            Booking::whereIn('booking_id', $bookingIds)->update([
                'payment_method' => 'stripe',
                'payment_status' => 'paid',
                'status' => 'confirmed',
            ]);

            $confirmedBookings = Booking::with(['customer', 'participants', 'slot.service'])
                ->whereIn('booking_id', $bookingIds)
                ->get();

            foreach ($confirmedBookings as $booking) {
                if (str_starts_with((string) $booking->slot_id, 'TMP:')) {
                    try {
                        $booking = $this->ensureBookingHasRealSlot($booking);
                    } catch (\Throwable $e) {
                        Log::warning('Unable to allocate real slot after Stripe success.', [
                            'booking_id' => $booking->booking_id,
                            'slot_id' => $booking->slot_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if (!str_starts_with((string) $booking->slot_id, 'TMP:')) {
                    DB::table('slot')->where('slot_id', $booking->slot_id)->update(['status' => 'booked']);
                }

                $receiptPath = $this->generateBookingReceipt($booking, $receiptId);
                $booking->digital_receipt = $receiptPath;
                $booking->save();
            }

            $this->sendBookingEmails($confirmedBookings, false);
        }

        return redirect()->route('customer.dashboard')->with(
            'success',
            $isMock ? 'Mock payment marked as successful.' : 'Deposit paid successfully!'
        );
    }

    public function stripeCancel()
    {
        return redirect()->route('booking.payment')->with('error', 'Stripe payment cancelled.');
    }

    // ========= QR =========
    public function uploadQrReceipt(Request $request)
    {
        $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        // Use the QR-specific method — NO staff/room allocation.
        // Booking stays as pending_approval until staff verifies the receipt.
        try {
            $bookingIds = DB::transaction(function () {
                return $this->createBookingsFromCartForQr();
            });
        } catch (\Throwable $e) {
            Log::error('QR upload – booking creation failed.', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', $e->getMessage());
        }

        $path = $request->file('receipt')->store('qr_deposits', 'public');

        Booking::whereIn('booking_id', $bookingIds)->update([
            'payment_method' => 'qr',
            'payment_status' => 'pending',
            'depo_qr_pic' => $path,
        ]);

        // No email sent now — staff will verify the receipt first,
        // then approve → allocate real slot → email notification sent.

        $message = 'Your booking has been noted! '
            . 'Our staff will review your payment receipt and verify it. '
            . 'Once approved, you will receive an email confirmation with your appointment details.';

        return redirect()->route('bookings.index')
            ->with('success', $message);
    }

    // ========= REPAY an existing unpaid booking =========

    public function repayShow(string $bookingId)
    {
        $customer = auth('customer')->user();
        $booking = Booking::where('booking_id', $bookingId)
            ->where('customer_id', $customer->customer_id)
            ->firstOrFail();

        if (in_array($booking->payment_status, ['paid'])) {
            return redirect()->route('bookings.index')->with('error', 'This booking is already paid.');
        }

        $slot = $booking->slot;
        $service = $slot?->service;

        $slotDate = optional($slot?->slot_date)?->toDateString();
        $startTime = $slot?->start_time;
        $endTime = $slot?->end_time;
        $serviceName = $service?->name ?? 'Spa Service';

        // Parse TMP slot id for display when no real slot exists
        $rawSlotId = (string) $booking->slot_id;
        if (!$slot && str_starts_with($rawSlotId, 'TMP:')) {
            $parts = explode(':', $rawSlotId, 4);
            $tmpServiceId = (int) ($parts[1] ?? 0);
            $slotDate = $parts[2] ?? null;
            $startTime = $parts[3] ?? null;

            if ($tmpServiceId > 0) {
                $svc = Service::find($tmpServiceId);
                if ($svc) {
                    $serviceName = $svc->name;
                    $duration = (int) $svc->duration_minutes;
                    if ($startTime && $duration) {
                        $endTime = date('H:i', strtotime($startTime . ':00') + $duration * 60);
                    }
                }
            }
        }

        return Inertia::render('Booking/Repay', [
            'booking' => [
                'booking_id'     => $booking->booking_id,
                'status'         => $booking->status,
                'payment_status' => $booking->payment_status,
                'final_amount'   => (float)($booking->final_amount ?? 0),
                'deposit_amount' => (float)($booking->deposit_amount ?? 0),
                'service_name'   => $serviceName,
                'slot_date'      => $slotDate,
                'start_time'     => $startTime,
                'end_time'       => $endTime,
            ],
            'qrUploadUrl' => route('bookings.repay.qr', $bookingId),
        ]);
    }

    public function repayQr(Request $request, string $bookingId)
    {
        $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $customer = auth('customer')->user();
        $booking = Booking::where('booking_id', $bookingId)
            ->where('customer_id', $customer->customer_id)
            ->firstOrFail();

        if ($booking->payment_status === 'paid') {
            return redirect()->route('bookings.index')->with('error', 'This booking is already paid.');
        }

        $path = $request->file('receipt')->store('qr_deposits', 'public');

        $booking->update([
            'payment_method' => 'qr',
            'payment_status' => 'pending',
            'status'         => 'pending_approval',
            'depo_qr_pic'    => $path,
        ]);

        $message = 'Payment receipt uploaded! '
            . 'Our staff will review and verify your receipt. '
            . 'Once approved, you will receive an email confirmation.';

        return redirect()->route('bookings.index')
            ->with('success', $message);
    }

    public function repayStripe(Request $request, string $bookingId)
    {
        $customer = auth('customer')->user();
        $booking = Booking::where('booking_id', $bookingId)
            ->where('customer_id', $customer->customer_id)
            ->firstOrFail();

        if ($booking->payment_status === 'paid') {
            return response()->json(['error' => 'This booking is already paid.'], 422);
        }

        $depositTotal = round((float)($booking->deposit_amount ?? 0), 2);
        if ($depositTotal <= 0) {
            return response()->json(['error' => 'Deposit amount must be greater than 0.'], 422);
        }

        $forceMock = $request->boolean('mock') || $this->stripeMockEnabled();

        if ($forceMock) {
            try {
                DB::transaction(function () use ($booking) {
                    $booking = $this->ensureBookingHasRealSlot($booking);
                    $booking->update([
                        'payment_method' => 'stripe',
                        'payment_status' => 'paid',
                        'status'         => 'confirmed',
                    ]);
                    DB::table('slot')->where('slot_id', $booking->slot_id)->update(['status' => 'booked']);
                });
            } catch (\Throwable $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            session()->flash('success', 'Payment done! Your booking is now confirmed.');

            return response()->json([
                'mock' => true,
                'id'   => 'mock_' . Str::uuid()->toString(),
                'url'  => route('bookings.index'),
            ]);
        }

        $stripeSecret = (string) config('services.stripe.secret');
        if (blank($stripeSecret) || str_contains($stripeSecret, 'xxxxx')) {
            return response()->json([
                'error' => 'Stripe is not configured yet. Please set a real STRIPE_SECRET key in .env.',
            ], 422);
        }

        try {
            \Stripe\Stripe::setApiKey($stripeSecret);

            $session = \Stripe\Checkout\Session::create([
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'myr',
                        'product_data' => ['name' => 'UniSpa Booking Deposit – ' . $booking->booking_id],
                        'unit_amount' => (int) round($depositTotal * 100),
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'booking_ids' => $bookingId,
                ],
                'success_url' => route('booking.payment.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => route('bookings.repay', $bookingId),
            ]);

            return response()->json([
                'id'  => $session->id,
                'url' => $session->url,
            ]);
        } catch (\Throwable $e) {
            Log::error('Repay Stripe session failed.', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Unable to create Stripe session. Please try again.'], 500);
        }
    }
}
