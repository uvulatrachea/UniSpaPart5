<?php

namespace App\Http\Controllers;

use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\Service;
use App\Support\BookingCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;

class AppointmentController extends Controller
{
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

        return $emails->unique('email')->values()->all();
    }

    /**
     * Draft session key (per customer).
     */
    private string $draftKey = 'booking_draft_v1';

    /**
     * Deposit rule: 30% of total cart amount.
     */
    private float $depositRate = 0.30;

    /* ============================================================
     |  PAGES (Inertia)
     * ============================================================ */

    /**
     * Single-page "Book appointment" flow (AppointmentI.jsx).
     * GET /appointment/appointment-i?service=1
     */
    public function appointmentI(Request $request)
    {
        $services = Service::query()
            ->select(['id', 'category_id', 'name', 'description', 'price', 'duration_minutes', 'image_url'])
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'service_id' => (int) $s->id,
                'id' => (int) $s->id,
                'category_id' => (int) $s->category_id,
                'name' => $s->name,
                'description' => $s->description,
                'price' => (float) $s->price,
                'duration_minutes' => (int) $s->duration_minutes,
                'image' => $s->image_url ?: 'https://via.placeholder.com/800x500/5B21B6/ffffff?text=UniSpa+Service',
                'image_url' => $s->image_url,
            ]);

        $selectedServiceId = $request->query('service') ? (int) $request->query('service') : null;

        return Inertia::render('Appointment/AppointmentI', [
            'services' => $services,
            'selectedServiceId' => $selectedServiceId,
        ]);
    }

    // Step 1: list services for booking
    public function servicesPage(Request $request)
    {
        $services = Service::query()
            ->select(['id', 'category_id', 'name', 'description', 'price', 'duration_minutes', 'image_url'])
            ->orderBy('name')
            ->get()
            ->map(fn($s) => [
                'service_id' => (int) $s->id,
                'category_id' => (int) $s->category_id,
                'name' => $s->name,
                'description' => $s->description,
                'price' => (float) $s->price,
                'duration_minutes' => (int) $s->duration_minutes,
                'image' => $s->image_url ?: 'https://via.placeholder.com/800x500/5B21B6/ffffff?text=UniSpa+Service',
            ]);

        $draft = $this->getDraft();

        return Inertia::render('Booking/Services', [
            'services' => $services,
            'draft' => $draft,
            'step' => 1,
        ]);
    }

    // Step 2: guests page (self/others max 3)
    public function guestsPage(Request $request)
    {
        $draft = $this->getDraft();
        return Inertia::render('Booking/Guests', [
            'draft' => $draft,
            'step' => 2,
        ]);
    }

    // Step 3: schedule page (date/time slots)
    public function schedulePage(Request $request)
    {
        $draft = $this->getDraft();
        return Inertia::render('Booking/Schedule', [
            'draft' => $draft,
            'step' => 3,
        ]);
    }

    // Step 4: payment page (deposit 30%)
    public function paymentPage(Request $request)
    {
        $draft = $this->getDraft();
        return Inertia::render('Booking/Payment', [
            'draft' => $draft,
            'step' => 4,
        ]);
    }

    // My bookings list (simple, adapt to your booking table later)
    public function myBookings(Request $request)
    {
        $customer = auth('customer')->user();
        $customerId = $customer?->customer_id;

        $rows = $this->appointmentsQuery($customerId)
            ->paginate(8)
            ->through(fn ($b) => $this->transformBooking($b));

        return Inertia::render('Appointments', [
            'appointments' => $rows,
            'highlightBookingId' => null,
        ]);
    }

    public function showBooking(Request $request, string $bookingId)
    {
        $customer = auth('customer')->user();
        $customerId = $customer?->customer_id;

        $rows = $this->appointmentsQuery($customerId)
            ->paginate(8)
            ->through(fn ($b) => $this->transformBooking($b));

        $exists = Booking::query()
            ->where('customer_id', $customerId)
            ->where('booking_id', $bookingId)
            ->exists();

        return Inertia::render('Appointments', [
            'appointments' => $rows,
            'highlightBookingId' => $exists ? $bookingId : null,
        ]);
    }

    private function appointmentsQuery(?string $customerId)
    {
        return Booking::query()
            ->with(['slot.service', 'participants'])
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at');
    }

    private function transformBooking(Booking $b): array
    {
        $slot = $b->slot;
        $canManage = $b->canBeManagedByCustomer(24);

        $slotDate = optional($slot?->slot_date)?->toDateString();
        $startTime = $slot?->start_time;
        $endTime = $slot?->end_time;
        $serviceName = $slot?->service?->name;
        $durationMinutes = $slot?->service?->duration_minutes;

        // If no real slot row exists, parse the TMP slot id for display
        // Format: TMP:{service_id}:{YYYY-MM-DD}:{HH:MM}
        $rawSlotId = (string) $b->slot_id;
        if (!$slot && str_starts_with($rawSlotId, 'TMP:')) {
            $parts = explode(':', $rawSlotId, 4);
            $tmpServiceId = (int) ($parts[1] ?? 0);
            $slotDate = $parts[2] ?? null;
            $startTime = $parts[3] ?? null;

            // Look up service name from the service_id in the TMP slot
            if ($tmpServiceId > 0) {
                $svc = Service::find($tmpServiceId);
                if ($svc) {
                    $serviceName = $svc->name;
                    $durationMinutes = (int) $svc->duration_minutes;
                    // Calculate end time from start + duration
                    if ($startTime && $durationMinutes) {
                        $endTime = date('H:i', strtotime($startTime . ':00') + $durationMinutes * 60);
                    }
                }
            }
        }

        return [
            'booking_id' => $b->booking_id,
            'status' => $b->status,
            'payment_method' => $b->payment_method,
            'payment_status' => $b->payment_status,
            'qr_receipt_url' => $b->depo_qr_pic ? asset('storage/' . ltrim($b->depo_qr_pic, '/')) : null,
            'booking_receipt_url' => $b->digital_receipt ? asset('storage/' . ltrim($b->digital_receipt, '/')) : null,
            'final_amount' => (float) ($b->final_amount ?? $b->total_amount ?? 0),
            'deposit_amount' => (float) ($b->deposit_amount ?? 0),
            'special_requests' => $b->special_requests,
            'slot_date' => $slotDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'service_name' => $serviceName,
            'duration_minutes' => $durationMinutes,
            'participants_count' => $b->participants->count(),
            'participants' => $b->participants->map(fn ($p) => [
                'name' => $p->name,
                'is_self' => (bool) $p->is_self,
            ])->values(),
            'can_manage' => $canManage,
            'manage_cutoff_label' => 'Allowed until 24 hours before start time',
            'google_calendar_url' => BookingCalendar::googleCalendarUrl($b),
        ];
    }

    public function cancelBooking(Request $request, string $bookingId)
    {
        $customerId = auth('customer')->user()?->customer_id;

        $booking = Booking::query()
            ->with('slot')
            ->where('customer_id', $customerId)
            ->where('booking_id', $bookingId)
            ->first();

        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        if (!$booking->canBeManagedByCustomer(24)) {
            return back()->with('error', 'Cancellation not allowed within 24 hours of appointment.');
        }

        $booking->status = 'cancelled';
        $booking->save();

        return back()->with('success', 'Booking cancelled successfully. Refund initiated.');
    }

    public function updateBookingDetails(Request $request, string $bookingId)
    {
        $data = $request->validate([
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ]);

        $customerId = auth('customer')->user()?->customer_id;

        $booking = Booking::query()
            ->with(['slot', 'customer', 'slot.service'])
            ->where('customer_id', $customerId)
            ->where('booking_id', $bookingId)
            ->first();

        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        if (!$booking->canBeManagedByCustomer(24)) {
            return back()->with('error', 'Booking details can only be edited until 24 hours before your appointment.');
        }

        $booking->special_requests = $data['special_requests'] ?? null;
        $booking->save();

        $booking->loadMissing('participants');

        foreach ($this->bookingRecipients($booking) as $recipient) {
            Mail::to($recipient['email'])->send(
                new BookingConfirmedMail(
                    booking: $booking,
                    isUpdate: true,
                    recipientName: $recipient['name']
                )
            );
        }

        return back()->with('success', 'Booking details updated successfully.');
    }

    /* ============================================================
     |  DRAFT HELPERS (SESSION CART)
     * ============================================================ */

    private function getDraft(): array
    {
        $draft = Session::get($this->draftKey);

        if (!is_array($draft)) {
            $customer = auth('customer')->user();

            $draft = [
                'service_id' => null,
                'service' => null, // cached service snapshot
                'guests' => [
                    [
                        'who' => 'me',
                        'name' => $customer->name ?? '',
                        'phone' => $customer->phone ?? '',
                        'email' => $customer->email ?? '',
                    ],
                ],
                'schedule' => [
                    'date' => null,
                    'slot_id' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'staff_id' => null,
                    'staff_name' => null,
                ],
                'pricing' => [
                    'total' => 0,
                    'deposit' => 0,
                ],
                'payment' => [
                    'method' => null, // 'qr' | 'stripe'
                    'status' => 'unpaid', // unpaid|paid
                    'stripe_session_id' => null,
                ],
            ];

            $draft = $this->recalcDraft($draft);
            Session::put($this->draftKey, $draft);
        }

        return $draft;
    }

    private function saveDraft(array $draft): array
    {
        $draft = $this->recalcDraft($draft);
        Session::put($this->draftKey, $draft);
        return $draft;
    }

    /** UiTM member discount: 10% off. */
    private const UITM_DISCOUNT_RATE = 0.10;

    private function recalcDraft(array $draft): array
    {
        $service = null;
        if (!empty($draft['service_id'])) {
            $service = Service::query()->find($draft['service_id']);
        }

        $guestCount = is_array($draft['guests'] ?? null) ? count($draft['guests']) : 0;
        $guestCount = max(1, min(3, $guestCount));

        $price = $service ? (float) $service->price : 0.0;
        $subtotal = round($price * $guestCount, 2);

        // Apply 10% discount for UiTM members
        $customer = auth('customer')->user();
        $isUitmMember = $customer && !empty($customer->is_uitm_member);
        $discountAmount = 0.0;
        $total = $subtotal;
        if ($isUitmMember && $subtotal > 0) {
            $discountAmount = round($subtotal * self::UITM_DISCOUNT_RATE, 2);
            $total = round($subtotal - $discountAmount, 2);
        }

        $deposit = round($total * $this->depositRate, 2);

        $draft['service'] = $service ? [
            'service_id' => (int) $service->id,
            'name' => $service->name,
            'price' => (float) $service->price,
            'duration_minutes' => (int) $service->duration_minutes,
        ] : null;

        $draft['pricing'] = [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'deposit' => $deposit,
            'deposit_rate' => $this->depositRate,
            'guest_count' => $guestCount,
            'is_uitm_discount' => $isUitmMember,
        ];

        return $draft;
    }

    /* ============================================================
     |  DRAFT APIs
     * ============================================================ */

    public function resetDraft(Request $request)
    {
        Session::forget($this->draftKey);
        return response()->json(['ok' => true, 'draft' => $this->getDraft()]);
    }

    // Step 1: choose 1 service only
    public function setDraftService(Request $request)
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
        ]);

        $service = Service::query()->find($data['service_id']);
        if (!$service) {
            return response()->json(['ok' => false, 'message' => 'Service not found'], 404);
        }

        $draft = $this->getDraft();
        $draft['service_id'] = (int) $service->id;

        // Reset schedule when service changes
        $draft['schedule'] = [
            'date' => null,
            'slot_id' => null,
            'start_time' => null,
            'end_time' => null,
            'staff_id' => null,
            'staff_name' => null,
        ];

        $draft = $this->saveDraft($draft);

        return response()->json(['ok' => true, 'draft' => $draft]);
    }

    // Step 2: guests (max 3). Name+phone required, email optional.
    public function setDraftGuests(Request $request)
    {
        $data = $request->validate([
            'guests' => ['required', 'array', 'min:1', 'max:3'],
            'guests.*.who' => ['required', 'in:me,other'],
            'guests.*.name' => ['required', 'string', 'max:255'],
            'guests.*.phone' => ['required', 'string', 'max:50'],
            'guests.*.email' => ['nullable', 'email', 'max:255'],
        ]);

        $draft = $this->getDraft();
        $draft['guests'] = array_slice($data['guests'], 0, 3);

        $draft = $this->saveDraft($draft);
        return response()->json(['ok' => true, 'draft' => $draft]);
    }

    /* ============================================================
     |  SLOTS + SCHEDULE
     * ============================================================ */

    // You can replace this later with your real slots table.
    // For now, it returns sample slots 10AM-6PM every hour.
    public function getAvailableSlots(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $draft = $this->getDraft();
        if (empty($draft['service_id'])) {
            return response()->json(['ok' => false, 'message' => 'Select a service first'], 422);
        }

        $date = $data['date'];

        // Sample slots (replace with DB slot query later)
        $slots = [];
        for ($h = 10; $h <= 17; $h++) {
            $start = sprintf('%02d:00', $h);
            $end = sprintf('%02d:00', $h + 1);
            $slots[] = [
                'slot_id' => $date . '-' . $start,
                'slot_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'staff_id' => null,
                'staff_name' => 'Available',
            ];
        }

        return response()->json(['ok' => true, 'slots' => $slots]);
    }

    public function setDraftSchedule(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'slot_id' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'string', 'max:10'],
            'end_time' => ['required', 'string', 'max:10'],
            'staff_id' => ['nullable', 'integer'],
            'staff_name' => ['nullable', 'string', 'max:255'],
        ]);

        $draft = $this->getDraft();
        if (empty($draft['service_id'])) {
            return response()->json(['ok' => false, 'message' => 'Select a service first'], 422);
        }

        $draft['schedule'] = [
            'date' => $data['date'],
            'slot_id' => $data['slot_id'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'staff_id' => $data['staff_id'] ?? null,
            'staff_name' => $data['staff_name'] ?? null,
        ];

        $draft = $this->saveDraft($draft);
        return response()->json(['ok' => true, 'draft' => $draft]);
    }

    /* ============================================================
     |  CONFIRM BOOKING (writes to DB)
     * ============================================================ */

    public function confirmBooking(Request $request)
    {
        $draft = $this->getDraft();

        if (empty($draft['service_id'])) {
            return response()->json(['ok' => false, 'message' => 'Select a service first'], 422);
        }
        if (empty($draft['schedule']['date']) || empty($draft['schedule']['start_time'])) {
            return response()->json(['ok' => false, 'message' => 'Select date & time first'], 422);
        }

        // Payment must be at least "pending" (qr chosen) or "paid" (stripe success)
        if (($draft['payment']['method'] ?? null) === null) {
            return response()->json(['ok' => false, 'message' => 'Choose payment method'], 422);
        }

        $customerId = auth('customer')->id();

        // Store a booking row (total_amount = final amount after UiTM 10% discount if applied)
        $pricing = $draft['pricing'] ?? [];
        $bookingId = DB::table('bookings')->insertGetId([
            'customer_id' => $customerId,
            'service_id' => (int) $draft['service_id'],
            'service_name' => $draft['service']['name'] ?? null,
            'slot_date' => $draft['schedule']['date'],
            'start_time' => $draft['schedule']['start_time'],
            'end_time' => $draft['schedule']['end_time'],
            'staff_name' => $draft['schedule']['staff_name'],
            'guest_count' => (int) ($pricing['guest_count'] ?? 1),
            'guests_json' => json_encode($draft['guests']),
            'total_amount' => (float) ($pricing['total'] ?? 0),
            'deposit_amount' => (float) ($pricing['deposit'] ?? 0),
            'payment_method' => $draft['payment']['method'],
            'payment_status' => $draft['payment']['status'] ?? 'unpaid',
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Clear draft after booking created
        Session::forget($this->draftKey);

        return response()->json([
            'ok' => true,
            'booking_id' => $bookingId,
        ]);
    }

    /* ============================================================
     |  PAYMENTS
     * ============================================================ */

    public function createStripeSession(Request $request)
    {
        $draft = $this->getDraft();

        if (empty($draft['service_id'])) {
            return response()->json(['ok' => false, 'message' => 'Select service first'], 422);
        }
        if (empty($draft['schedule']['date'])) {
            return response()->json(['ok' => false, 'message' => 'Select schedule first'], 422);
        }

        // Deposit amount (30%)
        $deposit = (float) ($draft['pricing']['deposit'] ?? 0);
        if ($deposit <= 0) {
            return response()->json(['ok' => false, 'message' => 'Invalid deposit amount'], 422);
        }

        // Mark payment method chosen
        $draft['payment']['method'] = 'stripe';
        $draft['payment']['status'] = 'unpaid';
        $draft = $this->saveDraft($draft);

        // ⚠️ You must have STRIPE_SECRET in .env (config/services.php stripe.secret).
        Stripe::setApiKey(config('services.stripe.secret'));

        $session = StripeCheckoutSession::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'myr',
                    'unit_amount' => (int) round($deposit * 100),
                    'product_data' => [
                        'name' => 'UniSpa Deposit (30%)',
                        'description' => ($draft['service']['name'] ?? 'Booking') . ' - deposit payment',
                    ],
                ],
            ]],
            'success_url' => route('payment.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('payment.stripe.cancel'),
        ]);

        $draft['payment']['stripe_session_id'] = $session->id;
        $draft = $this->saveDraft($draft);

        return response()->json([
            'ok' => true,
            'url' => $session->url,
        ]);
    }

    public function stripeSuccess(Request $request)
    {
        $sessionId = $request->query('session_id');
        $draft = $this->getDraft();

        // basic validate: session id matches what we created
        if (!$sessionId || ($draft['payment']['stripe_session_id'] ?? null) !== $sessionId) {
            // still render a page so user sees something
            return Inertia::render('Booking/StripeResult', [
                'ok' => false,
                'message' => 'Stripe session mismatch.',
            ]);
        }

        $draft['payment']['method'] = 'stripe';
        $draft['payment']['status'] = 'paid';
        $draft = $this->saveDraft($draft);

        return Inertia::render('Booking/StripeResult', [
            'ok' => true,
            'message' => 'Deposit payment successful.',
            'draft' => $draft,
        ]);
    }

    public function stripeCancel()
    {
        return Inertia::render('Booking/StripeResult', [
            'ok' => false,
            'message' => 'Payment cancelled.',
        ]);
    }

    // QR flow: user chooses QR, pays manually, then clicks "I have paid"
    public function markQrPaid(Request $request)
    {
        $draft = $this->getDraft();

        $draft['payment']['method'] = 'qr';
        // In real system: should be "pending_verification".
        // For assignment/demo: mark paid after user confirms.
        $draft['payment']['status'] = 'paid';

        $draft = $this->saveDraft($draft);

        return response()->json(['ok' => true, 'draft' => $draft]);
    }
}
