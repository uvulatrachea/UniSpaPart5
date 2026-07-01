<?php

namespace App\Http\Controllers\Auth\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Booking\PaymentController;
use App\Mail\BookingConfirmedMail;
use App\Mail\StaffAvailabilityReviewedMail;
use App\Models\Booking;
use App\Models\Staff;
use App\Support\BookingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class AdminDashboardController extends Controller
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

    private function parseTmpSlotId(string $tmpSlotId): ?array
    {
        if (!str_starts_with($tmpSlotId, 'TMP:')) {
            return null;
        }

        $parts = explode(':', $tmpSlotId, 4);
        if (count($parts) < 4) {
            return null;
        }

        return [
            'service_id' => (int) ($parts[1] ?? 0),
            'date' => (string) ($parts[2] ?? ''),
            'start_time' => (string) ($parts[3] ?? ''),
        ];
    }

    private function tmpServiceIdSql(): string
    {
        return "CASE WHEN b.slot_id LIKE 'TMP:%' THEN CAST(split_part(b.slot_id, ':', 2) AS integer) ELSE NULL END";
    }

    private function tmpSlotDateSql(): string
    {
        return "CASE WHEN b.slot_id LIKE 'TMP:%' THEN CAST(split_part(b.slot_id, ':', 3) AS date) ELSE NULL END";
    }

    private function tmpSlotStartSql(): string
    {
        // The tmp slot start is stored inside the slot_id as two parts (hour and minute).
        // For Postgres we must return a value of type TIME so COALESCE(sl.start_time, ...) has matching types.
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "CASE WHEN b.slot_id LIKE 'TMP:%' THEN CAST(split_part(b.slot_id, ':', 4) || ':' || split_part(b.slot_id, ':', 5) AS time) ELSE NULL END";
        }

        return "CASE WHEN b.slot_id LIKE 'TMP:%' THEN split_part(b.slot_id, ':', 4) || ':' || split_part(b.slot_id, ':', 5) ELSE NULL END";
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

    public function index()
    {
        $admin = Auth::guard('staff')->user();
        $today = now(config('app.timezone', 'Asia/Kuala_Lumpur'))->toDateString();
        $tzNow = now(config('app.timezone', 'Asia/Kuala_Lumpur'));

        $bookingOrderColumn = Schema::hasColumn('booking', 'created_at') ? 'b.created_at' : 'b.booking_id';
        $reviewOrderColumn = Schema::hasColumn('review', 'created_at') ? 'r.created_at' : 'r.review_id';

        $stats = [
            'totalStaff' => DB::table('staff')->count(),
            'activeStaff' => DB::table('staff')->where('work_status', 'active')->count(),
            'generalStaff' => DB::table('staff')->where('staff_type', 'general')->count(),
            'studentStaff' => DB::table('staff')->where('staff_type', 'student')->count(),

            'totalCustomers' => DB::table('customers')->count(),
            'verifiedCustomers' => DB::table('customers')->where('verification_status', 'verified')->count(),

            'totalServices' => DB::table('service')->count(),
            'activePromotions' => DB::table('promotion')->where('is_active', true)->count(),

            'todaySlots' => DB::table('slot')->whereDate('slot_date', $today)->count(),
            'availableSlotsToday' => DB::table('slot')
                ->whereDate('slot_date', $today)
                ->where('status', 'available')
                ->count(),

            'todayBookings' => DB::table('booking as b')
                ->join('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
                ->whereDate('sl.slot_date', $today)
                ->count(),
            'pendingBookings' => DB::table('booking')->whereIn('status', ['pending', 'cart'])->count(),
            'completedBookings' => DB::table('booking')->where('status', 'completed')->count(),
            'cancelledBookings' => DB::table('booking')->where('status', 'cancelled')->count(),

            'pendingPayments' => DB::table('booking')->where('payment_status', 'pending')->count(),
            'paidPayments' => DB::table('booking')->where('payment_status', 'paid')->count(),

            'totalReviews' => DB::table('review')->count(),
            'avgRating' => round((float) (DB::table('review')->avg('rating') ?? 0), 1),
        ];

        $recentBookings = DB::table('booking as b')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('service as sv', 'sv.id', '=', 'sl.service_id')
            ->select([
                'b.booking_id',
                'b.status',
                'b.payment_status',
                'b.payment_method',
                'b.final_amount',
                'c.name as customer_name',
                'sv.name as service_name',
                'sl.slot_date',
                'sl.start_time',
            ])
            ->orderByDesc($bookingOrderColumn)
            ->limit(8)
            ->get();

        $recentReviews = DB::table('review as r')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'r.customer_id')
            ->leftJoin('booking as b', 'b.booking_id', '=', 'r.booking_id')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('service as sv', 'sv.id', '=', 'sl.service_id')
            ->select([
                'r.review_id',
                'r.rating',
                'r.comment',
                'c.name as customer_name',
                'sv.name as service_name',
            ])
            ->orderByDesc($reviewOrderColumn)
            ->limit(6)
            ->get();

        $topServices = DB::table('service as sv')
            ->leftJoin('slot as sl', 'sl.service_id', '=', 'sv.id')
            ->leftJoin('booking as b', 'b.slot_id', '=', 'sl.slot_id')
            ->select([
                'sv.id',
                'sv.name',
                DB::raw('COUNT(b.booking_id) as booking_count'),
            ])
            ->groupBy('sv.id', 'sv.name')
            ->orderByDesc('booking_count')
            ->limit(6)
            ->get();

        // Dashboard chart analytics (last 8 weeks)
        $trendStart = $tzNow->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(7);
        $weekStarts = collect(range(0, 7))
            ->map(fn (int $i) => $trendStart->copy()->addWeeks($i)->startOfWeek(Carbon::MONDAY));

        $trendRows = DB::table('booking as b')
            ->join('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('service as sv', 'sv.id', '=', 'sl.service_id')
            ->whereDate('sl.slot_date', '>=', $trendStart->toDateString())
            ->whereNotIn('b.status', ['cart', 'cancelled'])
            ->select([
                'sl.slot_date',
                DB::raw("COALESCE(sv.name, 'Unknown Service') as service_name"),
                DB::raw('COUNT(*) as booking_count'),
            ])
            ->groupBy('sl.slot_date', 'service_name')
            ->get();

        $topTrendServices = collect($trendRows)
            ->groupBy('service_name')
            ->map(fn ($rows, $name) => [
                'name' => (string) $name,
                'total' => (int) collect($rows)->sum(fn ($r) => (int) ($r->booking_count ?? 0)),
            ])
            ->sortByDesc('total')
            ->take(4)
            ->pluck('name')
            ->values();

        $serviceWeeklyMap = [];
        foreach ($topTrendServices as $serviceName) {
            $serviceWeeklyMap[$serviceName] = [];
        }

        foreach ($trendRows as $row) {
            $serviceName = (string) ($row->service_name ?? 'Unknown Service');
            if (!$topTrendServices->contains($serviceName)) {
                continue;
            }

            $weekKey = Carbon::parse((string) $row->slot_date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $serviceWeeklyMap[$serviceName][$weekKey] = ($serviceWeeklyMap[$serviceName][$weekKey] ?? 0) + (int) ($row->booking_count ?? 0);
        }

        $serviceTrend = [
            'labels' => $weekStarts->map(fn (Carbon $d) => $d->format('d M'))->values(),
            'series' => $topTrendServices->map(function (string $serviceName) use ($weekStarts, $serviceWeeklyMap) {
                return [
                    'name' => $serviceName,
                    'data' => $weekStarts
                        ->map(fn (Carbon $week) => (int) ($serviceWeeklyMap[$serviceName][$week->toDateString()] ?? 0))
                        ->values(),
                ];
            })->values(),
        ];

        $bookingStatusBreakdown = DB::table('booking')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) ($row->status ?? 'unknown'),
                'count' => (int) ($row->total ?? 0),
            ])
            ->values();

        $weeklyBookingsOverview = $weekStarts->map(function (Carbon $week) {
            $weekStart = $week->toDateString();
            $weekEnd = $week->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

            return [
                'label' => $week->format('d M'),
                'count' => (int) DB::table('booking as b')
                    ->join('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
                    ->whereBetween('sl.slot_date', [$weekStart, $weekEnd])
                    ->whereNotIn('b.status', ['cart', 'cancelled'])
                    ->count(),
            ];
        })->values();

        return Inertia::render('Admin/AdminDashboard', [
            'adminName' => $admin->name ?? 'Admin',
            'stats' => $stats,
            'recentBookings' => $recentBookings,
            'recentReviews' => $recentReviews,
            'topServices' => $topServices,
            'serviceTrend' => $serviceTrend,
            'bookingStatusBreakdown' => $bookingStatusBreakdown,
            'weeklyBookingsOverview' => $weeklyBookingsOverview,
        ]);
    }

    public function users()
    {
        $search = trim((string) request('search', ''));
        $sort = (string) request('sort', 'created_at');
        $direction = strtolower((string) request('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $staffStatus = (string) request('staff_status', 'all');
        $customerVerification = (string) request('customer_verification', 'all');
        $customerMembership = (string) request('customer_membership', 'all');
        $section = (string) request('section', 'staff');
        $perPage = (int) request('per_page', 8);
        $perPage = in_array($perPage, [5, 8, 10, 15, 20], true) ? $perPage : 8;

        $allowedStaffSort = ['staff_id', 'name', 'email', 'work_status', 'role', 'created_at'];
        $allowedCustomerSort = ['customer_id', 'name', 'email', 'verification_status', 'is_uitm_member', 'created_at'];

        $staffSort = in_array($sort, $allowedStaffSort, true) ? $sort : 'created_at';
        $customerSort = in_array($sort, $allowedCustomerSort, true) ? $sort : 'created_at';

        $staffBase = DB::table('staff as s')
            ->leftJoin('student_staff as ss', 'ss.staff_id', '=', 's.staff_id')
            ->leftJoin('general_staff_qualification as gsq', 'gsq.staff_id', '=', 's.staff_id')
            ->select([
                's.staff_id',
                's.name',
                's.email',
                's.phone',
                's.staff_type',
                's.role',
                's.work_status',
                's.created_at',
                'ss.working_hours',
                DB::raw('COUNT(gsq.qualification_id) as qualification_count'),
            ])
            ->groupBy([
                's.staff_id',
                's.name',
                's.email',
                's.phone',
                's.staff_type',
                's.role',
                's.work_status',
                's.created_at',
                'ss.working_hours',
            ]);

        if ($search !== '') {
            $staffBase->where(function ($q) use ($search) {
                $q->where('s.name', 'like', "%{$search}%")
                    ->orWhere('s.email', 'like', "%{$search}%")
                    ->orWhere('s.phone', 'like', "%{$search}%")
                    ->orWhere('s.staff_id', 'like', "%{$search}%")
                    ->orWhere('s.role', 'like', "%{$search}%");
            });
        }

        if (in_array($staffStatus, ['active', 'inactive'], true)) {
            $staffBase->where('s.work_status', $staffStatus);
        }

        $generalStaff = (clone $staffBase)
            ->where('s.staff_type', 'general')
            ->orderBy("s.{$staffSort}", $direction)
            ->paginate($perPage, ['*'], 'general_page')
            ->withQueryString();

        $studentStaff = (clone $staffBase)
            ->where('s.staff_type', 'student')
            ->orderBy("s.{$staffSort}", $direction)
            ->paginate($perPage, ['*'], 'student_page')
            ->withQueryString();

        $customersBase = DB::table('customers as c')
            ->select([
                'c.customer_id',
                'c.name',
                'c.email',
                'c.phone',
                'c.cust_type',
                'c.verification_status',
                'c.is_uitm_member',
                'c.created_at',
            ]);

        if ($search !== '') {
            $customersBase->where(function ($q) use ($search) {
                $q->where('c.name', 'like', "%{$search}%")
                    ->orWhere('c.email', 'like', "%{$search}%")
                    ->orWhere('c.phone', 'like', "%{$search}%")
                    ->orWhere('c.customer_id', 'like', "%{$search}%");
            });
        }

        if (in_array($customerVerification, ['pending', 'verified', 'rejected'], true)) {
            $customersBase->where('c.verification_status', $customerVerification);
        }

        if ($customerMembership === 'uitm') {
            $customersBase->where('c.is_uitm_member', true);
        }
        if ($customerMembership === 'regular') {
            $customersBase->where('c.is_uitm_member', false);
        }

        $customers = $customersBase
            ->orderBy("c.{$customerSort}", $direction)
            ->paginate($perPage, ['*'], 'customer_page')
            ->withQueryString();

        $staffKpi = DB::table('staff')
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN staff_type='general' THEN 1 ELSE 0 END) as general")
            ->selectRaw("SUM(CASE WHEN staff_type='student' THEN 1 ELSE 0 END) as student")
            ->selectRaw("SUM(CASE WHEN work_status='active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN work_status='inactive' THEN 1 ELSE 0 END) as inactive")
            ->first();

        $customerKpi = DB::table('customers')
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN verification_status='verified' THEN 1 ELSE 0 END) as verified")
            ->selectRaw("SUM(CASE WHEN verification_status='pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN verification_status='rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw("SUM(CASE WHEN is_uitm_member = true THEN 1 ELSE 0 END) as uitm")
            ->first();

        return Inertia::render('Admin/ManageUsers', [
            'kpis' => [
                'totalStaff' => (int) ($staffKpi->total ?? 0),
                'generalStaff' => (int) ($staffKpi->general ?? 0),
                'studentStaff' => (int) ($staffKpi->student ?? 0),
                'activeStaff' => (int) ($staffKpi->active ?? 0),
                'inactiveStaff' => (int) ($staffKpi->inactive ?? 0),
                'totalCustomers' => (int) ($customerKpi->total ?? 0),
                'verifiedCustomers' => (int) ($customerKpi->verified ?? 0),
                'pendingVerification' => (int) ($customerKpi->pending ?? 0),
                'rejectedCustomers' => (int) ($customerKpi->rejected ?? 0),
                'uitmCustomers' => (int) ($customerKpi->uitm ?? 0),
            ],
            'generalStaff' => $generalStaff,
            'studentStaff' => $studentStaff,
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'staff_status' => $staffStatus,
                'customer_verification' => $customerVerification,
                'customer_membership' => $customerMembership,
                'per_page' => $perPage,
                'section' => in_array($section, ['staff', 'customers'], true) ? $section : 'staff',
            ],
        ]);
    }

    public function storeStaff(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', 'unique:staff,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'staff_type' => ['required', Rule::in(['general', 'student'])],
            'role' => ['required', 'string', 'max:50'],
            'work_status' => ['required', Rule::in(['active', 'inactive'])],
            // Student staff must be assigned at least 12 hours.
            'working_hours' => ['exclude_unless:staff_type,student', 'required', 'integer', 'min:12', 'max:40'],
        ]);

        DB::transaction(function () use ($validated) {
            $payload = [
                'name' => $validated['name'],
                'email' => strtolower(trim($validated['email'])),
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'staff_type' => $validated['staff_type'],
                'role' => $validated['role'],
                'work_status' => $validated['work_status'],
            ];

            if (Schema::hasColumn('staff', 'created_at')) {
                $payload['created_at'] = now();
            }
            if (Schema::hasColumn('staff', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            // Postgres uses `returning "id"` by default; our PK is `staff_id`.
            $staffId = DB::table('staff')->insertGetId($payload, 'staff_id');

            if ($validated['staff_type'] === 'student') {
                DB::table('student_staff')->insert([
                    'staff_id' => $staffId,
                    'working_hours' => (int) $validated['working_hours'],
                ]);
            } else {
                DB::table('general_staff')->insertOrIgnore([
                    'staff_id' => $staffId,
                ]);
            }
        });

        return back()->with('success', 'Staff user created.');
    }

    public function updateStaff(Request $request, int $staffId)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', Rule::unique('staff', 'email')->ignore($staffId, 'staff_id')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'staff_type' => ['required', Rule::in(['general', 'student'])],
            'role' => ['required', 'string', 'max:50'],
            'work_status' => ['required', Rule::in(['active', 'inactive'])],
            // Student staff must be assigned at least 12 hours.
            'working_hours' => ['exclude_unless:staff_type,student', 'required', 'integer', 'min:12', 'max:40'],
        ]);

        DB::transaction(function () use ($validated, $staffId) {
            $payload = [
                'name' => $validated['name'],
                'email' => strtolower(trim($validated['email'])),
                'phone' => $validated['phone'] ?? null,
                'staff_type' => $validated['staff_type'],
                'role' => $validated['role'],
                'work_status' => $validated['work_status'],
            ];

            if (Schema::hasColumn('staff', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            if (!empty($validated['password'])) {
                $payload['password'] = Hash::make($validated['password']);
            }

            DB::table('staff')->where('staff_id', $staffId)->update($payload);

            if ($validated['staff_type'] === 'student') {
                DB::table('general_staff_qualification')->where('staff_id', $staffId)->delete();
                DB::table('general_staff')->where('staff_id', $staffId)->delete();
                DB::table('student_staff')->updateOrInsert(
                    ['staff_id' => $staffId],
                    ['working_hours' => (int) $validated['working_hours']]
                );
            } else {
                DB::table('student_staff')->where('staff_id', $staffId)->delete();
                DB::table('general_staff')->insertOrIgnore(['staff_id' => $staffId]);
            }
        });

        return back()->with('success', 'Staff user updated.');
    }

    public function updateStaffStatus(Request $request, int $staffId)
    {
        $validated = $request->validate([
            'work_status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $payload = [
            'work_status' => $validated['work_status'],
        ];
        if (Schema::hasColumn('staff', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('staff')->where('staff_id', $staffId)->update($payload);

        return back()->with('success', 'Staff status updated.');
    }

    public function destroyStaff(int $staffId)
    {
        try {
            DB::transaction(function () use ($staffId) {
                DB::table('general_staff_qualification')->where('staff_id', $staffId)->delete();
                DB::table('student_staff')->where('staff_id', $staffId)->delete();
                DB::table('general_staff')->where('staff_id', $staffId)->delete();
                DB::table('staff')->where('staff_id', $staffId)->delete();
            });

            return back()->with('success', 'Staff user deleted.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Unable to delete staff user.');
        }
    }

    public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'verification_status' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
            'is_uitm_member' => ['required', 'boolean'],
        ]);

        DB::table('customers')->insert([
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'verification_status' => $validated['verification_status'],
            'is_uitm_member' => (bool) $validated['is_uitm_member'],
            'cust_type' => (bool) $validated['is_uitm_member'] ? 'uitm_member' : 'regular',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Customer user created.');
    }

    public function updateCustomer(Request $request, int $customerId)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', Rule::unique('customers', 'email')->ignore($customerId, 'customer_id')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'verification_status' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
            'is_uitm_member' => ['required', 'boolean'],
        ]);

        $payload = [
            'name' => $validated['name'],
            'email' => strtolower(trim($validated['email'])),
            'phone' => $validated['phone'] ?? null,
            'verification_status' => $validated['verification_status'],
            'is_uitm_member' => (bool) $validated['is_uitm_member'],
            'cust_type' => (bool) $validated['is_uitm_member'] ? 'uitm_member' : 'regular',
            'updated_at' => now(),
        ];

        if (!empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        DB::table('customers')->where('customer_id', $customerId)->update($payload);

        return back()->with('success', 'Customer user updated.');
    }

    public function updateCustomerVerification(Request $request, int $customerId)
    {
        $validated = $request->validate([
            'verification_status' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
        ]);

        DB::table('customers')->where('customer_id', $customerId)->update([
            'verification_status' => $validated['verification_status'],
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Customer verification updated.');
    }

    public function destroyCustomer(int $customerId)
    {
        try {
            DB::transaction(function () use ($customerId) {
                // Get all booking IDs for this customer
                $bookingIds = DB::table('booking')
                    ->where('customer_id', $customerId)
                    ->pluck('booking_id')
                    ->toArray();

                // Delete related records in correct order
                if (!empty($bookingIds)) {
                    // Get all slot IDs linked to these bookings
                    $slotIds = DB::table('slot')
                        ->whereIn('slot_id', function ($q) use ($bookingIds) {
                            $q->select('slot_id')
                              ->from('booking')
                              ->whereIn('booking_id', $bookingIds);
                        })
                        ->pluck('slot_id')
                        ->toArray();

                    // Delete review participants/responses if any
                    DB::table('review_participant')
                        ->whereIn('booking_id', $bookingIds)
                        ->delete();
                    
                    // Delete reviews linked to bookings
                    DB::table('review')
                        ->whereIn('booking_id', $bookingIds)
                        ->delete();

                    // Delete booking participants
                    DB::table('booking_participant')
                        ->whereIn('booking_id', $bookingIds)
                        ->delete();

                    // Delete the bookings themselves
                    DB::table('booking')
                        ->whereIn('booking_id', $bookingIds)
                        ->delete();

                    // Delete slots linked to the deleted bookings
                    if (!empty($slotIds)) {
                        DB::table('slot')
                            ->whereIn('slot_id', $slotIds)
                            ->delete();
                    }
                }

                // Delete reviews linked directly to customer (not through booking)
                DB::table('review')
                    ->where('customer_id', $customerId)
                    ->delete();

                // Delete cart records for this customer
                DB::table('cart')->where('customer_id', $customerId)->delete();

                // Delete verification records for this customer
                DB::table('verifications')->where('customer_id', $customerId)->delete();

                // Finally delete the customer
                DB::table('customers')->where('customer_id', $customerId)->delete();
            });

            return back()->with('success', 'Customer and all related records deleted.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Unable to delete customer: ' . $e->getMessage());
        }
    }

    public function exportStaff(Request $request)
    {
        $filename = 'staff-users-' . now()->format('Ymd_His') . '.csv';

        $rows = DB::table('staff as s')
            ->leftJoin('student_staff as ss', 'ss.staff_id', '=', 's.staff_id')
            ->leftJoin('general_staff_qualification as gsq', 'gsq.staff_id', '=', 's.staff_id')
            ->select([
                's.staff_id',
                's.name',
                's.email',
                's.phone',
                's.staff_type',
                's.role',
                's.work_status',
                's.created_at',
                'ss.working_hours',
                DB::raw('COUNT(gsq.qualification_id) as qualification_count'),
            ])
            ->groupBy([
                's.staff_id',
                's.name',
                's.email',
                's.phone',
                's.staff_type',
                's.role',
                's.work_status',
                's.created_at',
                'ss.working_hours',
            ])
            ->orderBy('s.staff_id')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Staff ID', 'Name', 'Email', 'Phone', 'Type', 'Role', 'Status', 'Working Hours', 'Qualification Count', 'Created At']);
            foreach ($rows as $row) {
                fputcsv($file, [
                    $row->staff_id,
                    $row->name,
                    $row->email,
                    $row->phone,
                    $row->staff_type,
                    $row->role,
                    $row->work_status,
                    $row->working_hours,
                    $row->qualification_count,
                    $row->created_at,
                ]);
            }
            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportCustomers(Request $request)
    {
        $filename = 'customer-users-' . now()->format('Ymd_His') . '.csv';
        $rows = DB::table('customers')->orderBy('customer_id')->get();

        return response()->streamDownload(function () use ($rows) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Customer ID', 'Name', 'Email', 'Phone', 'Type', 'UITM Member', 'Verification', 'Created At']);
            foreach ($rows as $row) {
                fputcsv($file, [
                    $row->customer_id,
                    $row->name,
                    $row->email,
                    $row->phone,
                    $row->cust_type,
                    $row->is_uitm_member ? 'Yes' : 'No',
                    $row->verification_status,
                    $row->created_at,
                ]);
            }
            fclose($file);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function importStaff(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (!$handle) {
            return back()->with('error', 'Unable to read file.');
        }

        $header = fgetcsv($handle);
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string)($row[0] ?? ''));
            $email = strtolower(trim((string)($row[1] ?? '')));
            if ($name === '' || $email === '' || DB::table('staff')->where('email', $email)->exists()) {
                continue;
            }

            $staffType = strtolower(trim((string)($row[2] ?? 'general')));
            $staffType = in_array($staffType, ['general', 'student'], true) ? $staffType : 'general';
            $workStatus = strtolower(trim((string)($row[4] ?? 'active')));
            $workStatus = in_array($workStatus, ['active', 'inactive'], true) ? $workStatus : 'active';

            $workingHours = 20;
            if ($staffType === 'student') {
                $rawHours = $row[7] ?? null;
                $workingHours = is_numeric($rawHours) ? (int) $rawHours : 20;
                // Enforce minimum hours for student staff.
                $workingHours = max(12, min(40, $workingHours));
            }

            $payload = [
                'name' => $name,
                'email' => $email,
                'phone' => trim((string)($row[5] ?? '')) ?: null,
                'password' => Hash::make((string)($row[6] ?? 'Password123!')),
                'staff_type' => $staffType,
                'role' => trim((string)($row[3] ?? 'Staff')),
                'work_status' => $workStatus,
            ];

            if (Schema::hasColumn('staff', 'created_at')) {
                $payload['created_at'] = now();
            }
            if (Schema::hasColumn('staff', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            $staffId = DB::table('staff')->insertGetId($payload, 'staff_id');

            if ($staffType === 'student') {
                DB::table('student_staff')->insert([
                    'staff_id' => $staffId,
                    'working_hours' => $workingHours,
                ]);
            } else {
                DB::table('general_staff')->insertOrIgnore(['staff_id' => $staffId]);
            }

            $count++;
        }

        fclose($handle);
        return back()->with('success', "Imported {$count} staff rows.");
    }

    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (!$handle) {
            return back()->with('error', 'Unable to read file.');
        }

        $header = fgetcsv($handle);
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string)($row[0] ?? ''));
            $email = strtolower(trim((string)($row[1] ?? '')));
            if ($name === '' || $email === '' || DB::table('customers')->where('email', $email)->exists()) {
                continue;
            }

            $isUitm = in_array(strtolower(trim((string)($row[3] ?? 'no'))), ['1', 'yes', 'true', 'uitm'], true);
            $verification = strtolower(trim((string)($row[4] ?? 'pending')));
            $verification = in_array($verification, ['pending', 'verified', 'rejected'], true) ? $verification : 'pending';

            DB::table('customers')->insert([
                'name' => $name,
                'email' => $email,
                'phone' => trim((string)($row[2] ?? '')) ?: null,
                'password' => Hash::make((string)($row[5] ?? 'Password123!')),
                'is_uitm_member' => $isUitm,
                'verification_status' => $verification,
                'cust_type' => $isUitm ? 'uitm_member' : 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $count++;
        }

        fclose($handle);
        return back()->with('success', "Imported {$count} customer rows.");
    }

    public function services()
    {
        $section = (string) request('section', 'services');
        $search = trim((string) request('search', ''));
        $sort = (string) request('sort', 'created_at');
        $direction = strtolower((string) request('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = (int) request('per_page', 8);
        $perPage = in_array($perPage, [5, 8, 10, 15, 20], true) ? $perPage : 8;

        $categoryPk = $this->categoryPrimaryKey();
        $servicePk = $this->servicePrimaryKey();
        $promotionServiceServiceFk = $this->promotionServiceServiceColumn();

        $serviceSort = in_array($sort, ['id', 'name', 'price', 'duration_minutes', 'created_at'], true) ? $sort : 'created_at';
        $promotionSort = in_array($sort, ['promotion_id', 'title', 'discount_value', 'end_date', 'is_active', 'created_at'], true) ? $sort : 'created_at';
        $categorySort = in_array($sort, ['id', 'name', 'gender', 'sort_order', 'is_active', 'created_at'], true) ? $sort : 'created_at';

        $categorySortMap = [
            'id' => "c.{$categoryPk}",
            'name' => 'c.name',
            'gender' => 'c.gender',
            'sort_order' => Schema::hasColumn('service_category', 'sort_order') ? 'c.sort_order' : 'c.name',
            'is_active' => Schema::hasColumn('service_category', 'is_active') ? 'c.is_active' : 'c.name',
            'created_at' => Schema::hasColumn('service_category', 'created_at') ? 'c.created_at' : "c.{$categoryPk}",
        ];

        $serviceSortMap = [
            'id' => "s.{$servicePk}",
            'name' => 's.name',
            'price' => 's.price',
            'duration_minutes' => 's.duration_minutes',
            'created_at' => Schema::hasColumn('service', 'created_at') ? 's.created_at' : "s.{$servicePk}",
        ];

        $promotionSortMap = [
            'promotion_id' => 'p.promotion_id',
            'title' => 'p.title',
            'discount_value' => 'p.discount_value',
            'end_date' => 'p.end_date',
            'is_active' => 'p.is_active',
            'created_at' => Schema::hasColumn('promotion', 'created_at') ? 'p.created_at' : 'p.promotion_id',
        ];

        $categorySelects = [
            "c.{$categoryPk} as id",
            'c.name',
            'c.gender',
            Schema::hasColumn('service_category', 'description') ? 'c.description' : DB::raw('NULL as description'),
            Schema::hasColumn('service_category', 'icon') ? 'c.icon' : DB::raw('NULL as icon'),
            Schema::hasColumn('service_category', 'image_url') ? 'c.image_url' : DB::raw('NULL as image_url'),
            Schema::hasColumn('service_category', 'sort_order') ? 'c.sort_order' : DB::raw('0 as sort_order'),
            Schema::hasColumn('service_category', 'is_active') ? 'c.is_active' : DB::raw('TRUE as is_active'),
            Schema::hasColumn('service_category', 'created_at') ? 'c.created_at' : DB::raw('NULL as created_at'),
            DB::raw("COUNT(s.{$servicePk}) as service_count"),
        ];

        $categoryGroupBy = ["c.{$categoryPk}", 'c.name', 'c.gender'];
        foreach (['description', 'icon', 'image_url', 'sort_order', 'is_active', 'created_at'] as $col) {
            if (Schema::hasColumn('service_category', $col)) {
                $categoryGroupBy[] = "c.{$col}";
            }
        }

        $categories = DB::table('service_category as c')
            ->leftJoin('service as s', 's.category_id', '=', "c.{$categoryPk}")
            ->select($categorySelects)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('c.name', 'like', "%{$search}%")
                        ->orWhere('c.gender', 'like', "%{$search}%")
                        ->orWhere('c.category_id', 'like', "%{$search}%")
                        ->orWhere('c.id', 'like', "%{$search}%");
                });
            })
            ->groupBy($categoryGroupBy)
            ->orderBy($categorySortMap[$categorySort] ?? "c.{$categoryPk}", $direction)
            ->paginate($perPage, ['*'], 'categories_page')
            ->withQueryString();

        $serviceSelects = [
            "s.{$servicePk} as id",
            's.category_id',
            'c.name as category_name',
            's.name',
            's.description',
            's.price',
            's.duration_minutes',
            Schema::hasColumn('service', 'image_url') ? 's.image_url' : DB::raw('NULL as image_url'),
            Schema::hasColumn('service', 'is_popular') ? 's.is_popular' : DB::raw('FALSE as is_popular'),
            Schema::hasColumn('service', 'tags') ? (DB::connection()->getDriverName() === 'mysql' ? DB::raw("COALESCE(CAST(s.tags AS CHAR), '[]') as tags") : DB::raw("COALESCE(s.tags::text, '[]') as tags")) : DB::raw("'[]' as tags"),
            Schema::hasColumn('service', 'created_at') ? 's.created_at' : DB::raw('NULL as created_at'),
            DB::raw("COUNT(ps.{$promotionServiceServiceFk}) as promotion_count"),
        ];

        $serviceGroupBy = ["s.{$servicePk}", 's.category_id', 'c.name', 's.name', 's.description', 's.price', 's.duration_minutes'];
        if (Schema::hasColumn('service', 'image_url')) {
            $serviceGroupBy[] = 's.image_url';
        }
        if (Schema::hasColumn('service', 'is_popular')) {
            $serviceGroupBy[] = 's.is_popular';
        }
        if (Schema::hasColumn('service', 'tags')) {
            $serviceGroupBy[] = DB::connection()->getDriverName() === 'mysql' ? DB::raw('CAST(s.tags AS CHAR)') : DB::raw('s.tags::text');
        }
        if (Schema::hasColumn('service', 'created_at')) {
            $serviceGroupBy[] = 's.created_at';
        }

        $services = DB::table('service as s')
            ->leftJoin('service_category as c', "c.{$categoryPk}", '=', 's.category_id')
            ->leftJoin('promotion_service as ps', "ps.{$promotionServiceServiceFk}", '=', "s.{$servicePk}")
            ->select($serviceSelects)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('s.name', 'like', "%{$search}%")
                        ->orWhere('s.description', 'like', "%{$search}%")
                        ->orWhere('s.service_id', 'like', "%{$search}%")
                        ->orWhere('s.id', 'like', "%{$search}%")
                        ->orWhere('c.name', 'like', "%{$search}%");
                });
            })
            ->groupBy($serviceGroupBy)
            ->orderBy($serviceSortMap[$serviceSort] ?? "s.{$servicePk}", $direction)
            ->paginate($perPage, ['*'], 'services_page')
            ->withQueryString();

        $promotions = DB::table('promotion as p')
            ->leftJoin('promotion_service as ps', 'ps.promotion_id', '=', 'p.promotion_id')
            ->select([
                'p.promotion_id',
                'p.title',
                'p.description',
                'p.discount_type',
                'p.discount_value',
                'p.banner_image',
                'p.start_date',
                'p.end_date',
                'p.is_active',
                Schema::hasColumn('promotion', 'show_in_dashboard_header')
                    ? 'p.show_in_dashboard_header'
                    : DB::raw('TRUE as show_in_dashboard_header'),
                'p.link',
                Schema::hasColumn('promotion', 'created_at') ? 'p.created_at' : DB::raw('NULL as created_at'),
                DB::raw("COUNT(ps.{$promotionServiceServiceFk}) as service_count"),
                DB::raw(
                    DB::connection()->getDriverName() === 'pgsql'
                        ? "COALESCE(array_remove(array_agg(DISTINCT ps.{$promotionServiceServiceFk}), NULL), ARRAY[]::bigint[]) as linked_service_ids"
                        : "COALESCE(GROUP_CONCAT(DISTINCT ps.{$promotionServiceServiceFk} ORDER BY ps.{$promotionServiceServiceFk}), '') as linked_service_ids"
                ),
                DB::raw("CASE WHEN p.end_date IS NULL THEN 'permanent' ELSE 'seasonal' END as promotion_type"),
            ])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('p.title', 'like', "%{$search}%")
                        ->orWhere('p.description', 'like', "%{$search}%")
                        ->orWhere('p.promotion_id', 'like', "%{$search}%");
                });
            })
            ->groupBy('p.promotion_id', 'p.title', 'p.description', 'p.discount_type', 'p.discount_value', 'p.banner_image', 'p.start_date', 'p.end_date', 'p.is_active', 'p.link')
            ->when(Schema::hasColumn('promotion', 'show_in_dashboard_header'), fn ($q) => $q->groupBy('p.show_in_dashboard_header'))
            ->when(Schema::hasColumn('promotion', 'created_at'), fn ($q) => $q->groupBy('p.created_at'))
            ->orderBy($promotionSortMap[$promotionSort] ?? 'p.promotion_id', $direction)
            ->paginate($perPage, ['*'], 'promotions_page')
            ->withQueryString();

        $allServices = DB::table('service')->select("{$servicePk} as id", 'name')->orderBy('name')->get();

        $kpiRaw = DB::table('promotion')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN end_date IS NULL THEN 1 ELSE 0 END) as permanent")
            ->selectRaw("SUM(CASE WHEN end_date IS NOT NULL THEN 1 ELSE 0 END) as seasonal")
            ->first();

        return Inertia::render('Admin/ManageServices', [
            'kpis' => [
                'totalServices' => (int) DB::table('service')->count(),
                'totalCategories' => (int) DB::table('service_category')->count(),
                'popularServices' => (int) DB::table('service')->where('is_popular', true)->count(),
                'totalPromotions' => (int) ($kpiRaw->total ?? 0),
                'activePromotions' => (int) ($kpiRaw->active ?? 0),
                'permanentPromotions' => (int) ($kpiRaw->permanent ?? 0),
                'seasonalPromotions' => (int) ($kpiRaw->seasonal ?? 0),
            ],
            'categories' => $categories,
            'services' => $services,
            'promotions' => $promotions,
            'allServices' => $allServices,
            'filters' => [
                'section' => in_array($section, ['services', 'promotions', 'categories'], true) ? $section : 'services',
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function storeServiceCategory(Request $request)
    {
        $categoryPk = $this->categoryPrimaryKey();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'image_file' => ['nullable', 'image', 'max:4096'],
        ]);

        DB::table('service_category')->insert($this->payloadForTable('service_category', $this->withTimestamps('service_category', [
            'name' => $validated['name'],
            'gender' => $validated['gender'] ?? null,
            'description' => $validated['description'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => (bool) $validated['is_active'],
            'image_url' => $this->storeImage($request->file('image_file'), 'admin/categories'),
        ])));

        return back()->with('success', 'Category created.');
    }

    public function updateServiceCategory(Request $request, int $categoryId)
    {
        $categoryPk = $this->categoryPrimaryKey();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'image_file' => ['nullable', 'image', 'max:4096'],
        ]);

        $payload = [
            'name' => $validated['name'],
            'gender' => $validated['gender'] ?? null,
            'description' => $validated['description'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => (bool) $validated['is_active'],
        ];

        if ($request->hasFile('image_file')) {
            $payload['image_url'] = $this->storeImage($request->file('image_file'), 'admin/categories');
        }

        DB::table('service_category')
            ->where($categoryPk, $categoryId)
            ->update($this->payloadForTable('service_category', $this->withTimestamps('service_category', $payload, true)));

        return back()->with('success', 'Category updated.');
    }

    public function destroyServiceCategory(int $categoryId)
    {
        $categoryPk = $this->categoryPrimaryKey();

        try {
            DB::table('service_category')->where($categoryPk, $categoryId)->delete();
            return back()->with('success', 'Category deleted.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Unable to delete category (possibly linked to services).');
        }
    }

    public function storeService(Request $request)
    {
        $categoryPk = $this->categoryPrimaryKey();

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('service_category', $categoryPk)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'is_popular' => ['required', 'boolean'],
            'tags_text' => ['nullable', 'string'],
            'image_file' => ['nullable', 'image', 'max:4096'],
        ]);

        DB::table('service')->insert($this->payloadForTable('service', $this->withTimestamps('service', [
            'category_id' => (int) $validated['category_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => (float) $validated['price'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'is_popular' => (bool) $validated['is_popular'],
            'image_url' => $this->storeImage($request->file('image_file'), 'admin/services'),
            'tags' => $this->formatTags($validated['tags_text'] ?? null),
        ])));

        return back()->with('success', 'Service created.');
    }

    public function updateService(Request $request, int $serviceId)
    {
        $categoryPk = $this->categoryPrimaryKey();
        $servicePk = $this->servicePrimaryKey();

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('service_category', $categoryPk)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'is_popular' => ['required', 'boolean'],
            'tags_text' => ['nullable', 'string'],
            'image_file' => ['nullable', 'image', 'max:4096'],
        ]);

        $payload = [
            'category_id' => (int) $validated['category_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => (float) $validated['price'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'is_popular' => (bool) $validated['is_popular'],
            'tags' => $this->formatTags($validated['tags_text'] ?? null),
        ];

        if ($request->hasFile('image_file')) {
            $payload['image_url'] = $this->storeImage($request->file('image_file'), 'admin/services');
        }

        DB::table('service')
            ->where($servicePk, $serviceId)
            ->update($this->payloadForTable('service', $this->withTimestamps('service', $payload, true)));

        return back()->with('success', 'Service updated.');
    }

    public function destroyService(int $serviceId)
    {
        $servicePk = $this->servicePrimaryKey();
        $promotionServiceServiceFk = $this->promotionServiceServiceColumn();

        try {
            DB::table('promotion_service')->where($promotionServiceServiceFk, $serviceId)->delete();
            DB::table('service')->where($servicePk, $serviceId)->delete();
            return back()->with('success', 'Service deleted.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Unable to delete service (possibly linked to slots/bookings).');
        }
    }

    public function storePromotion(Request $request)
    {
        $servicePk = $this->servicePrimaryKey();
        $promotionServiceServiceFk = $this->promotionServiceServiceColumn();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'promotion_type' => ['required', Rule::in(['permanent', 'seasonal'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['required', 'boolean'],
            'show_in_dashboard_header' => ['required', 'boolean'],
            'link' => ['nullable', 'string', 'max:255'],
            'promo_code' => ['nullable', 'string', 'max:50', Rule::unique('promotion', 'promo_code')],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', Rule::exists('service', $servicePk)],
            'banner_file' => ['nullable', 'image', 'max:5120'],
        ]);

        $promoCode = !empty($validated['promo_code']) ? strtoupper(trim($validated['promo_code'])) : null;

        $promotionId = DB::table('promotion')->insertGetId($this->payloadForTable('promotion', $this->withTimestamps('promotion', [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'discount_type' => $validated['discount_type'],
            'discount_value' => (float) $validated['discount_value'],
            'banner_image' => $this->storeImage($request->file('banner_file'), 'admin/promotions'),
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['promotion_type'] === 'permanent' ? null : ($validated['end_date'] ?? null),
            'is_active' => (bool) $validated['is_active'],
            'show_in_dashboard_header' => (bool) $validated['show_in_dashboard_header'],
            'link' => $validated['link'] ?? null,
            'promo_code' => $promoCode,
        ])), 'promotion_id');

        foreach (($validated['service_ids'] ?? []) as $serviceId) {
            DB::table('promotion_service')->insert([
                'promotion_id' => $promotionId,
                $promotionServiceServiceFk => (int) $serviceId,
            ]);
        }

        return back()->with('success', 'Promotion created.');
    }

    public function updatePromotion(Request $request, int $promotionId)
    {
        $servicePk = $this->servicePrimaryKey();
        $promotionServiceServiceFk = $this->promotionServiceServiceColumn();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'promotion_type' => ['required', Rule::in(['permanent', 'seasonal'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['required', 'boolean'],
            'show_in_dashboard_header' => ['required', 'boolean'],
            'link' => ['nullable', 'string', 'max:255'],
            'promo_code' => ['nullable', 'string', 'max:50', Rule::unique('promotion', 'promo_code')->ignore($promotionId, 'promotion_id')],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', Rule::exists('service', $servicePk)],
            'banner_file' => ['nullable', 'image', 'max:5120'],
        ]);

        $promoCode = !empty($validated['promo_code']) ? strtoupper(trim($validated['promo_code'])) : null;

        $payload = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'discount_type' => $validated['discount_type'],
            'discount_value' => (float) $validated['discount_value'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['promotion_type'] === 'permanent' ? null : ($validated['end_date'] ?? null),
            'is_active' => (bool) $validated['is_active'],
            'show_in_dashboard_header' => (bool) $validated['show_in_dashboard_header'],
            'link' => $validated['link'] ?? null,
            'promo_code' => $promoCode,
        ];

        if ($request->hasFile('banner_file')) {
            $payload['banner_image'] = $this->storeImage($request->file('banner_file'), 'admin/promotions');
        }

        DB::transaction(function () use ($promotionId, $payload, $validated, $promotionServiceServiceFk) {
            DB::table('promotion')->where('promotion_id', $promotionId)->update($this->payloadForTable('promotion', $this->withTimestamps('promotion', $payload, true)));
            DB::table('promotion_service')->where('promotion_id', $promotionId)->delete();
            foreach (($validated['service_ids'] ?? []) as $serviceId) {
                DB::table('promotion_service')->insert([
                    'promotion_id' => $promotionId,
                    $promotionServiceServiceFk => (int) $serviceId,
                ]);
            }
        });

        return back()->with('success', 'Promotion updated.');
    }

    public function updatePromotionStatus(Request $request, int $promotionId)
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        DB::table('promotion')->where('promotion_id', $promotionId)->update($this->payloadForTable('promotion', $this->withTimestamps('promotion', [
            'is_active' => (bool) $validated['is_active'],
        ], true)));

        return back()->with('success', 'Promotion status updated.');
    }

    public function destroyPromotion(int $promotionId)
    {
        DB::transaction(function () use ($promotionId) {
            DB::table('promotion_service')->where('promotion_id', $promotionId)->delete();
            DB::table('promotion')->where('promotion_id', $promotionId)->delete();
        });

        return back()->with('success', 'Promotion deleted.');
    }

    public function updatePromotionHeaderVisibility(Request $request, int $promotionId)
    {
        if (!Schema::hasColumn('promotion', 'show_in_dashboard_header')) {
            return back()->with('error', 'Header visibility column is not available yet. Please run migrations.');
        }

        $validated = $request->validate([
            'show_in_dashboard_header' => ['required', 'boolean'],
        ]);

        DB::table('promotion')
            ->where('promotion_id', $promotionId)
            ->update($this->payloadForTable('promotion', $this->withTimestamps('promotion', [
                'show_in_dashboard_header' => (bool) $validated['show_in_dashboard_header'],
            ], true)));

        return back()->with('success', 'Promotion dashboard visibility updated.');
    }

    public function printPromotion(int $promotionId)
    {
        $servicePk = $this->servicePrimaryKey();
        $promotionServiceServiceFk = $this->promotionServiceServiceColumn();

        $promotion = DB::table('promotion')->where('promotion_id', $promotionId)->first();

        abort_unless($promotion, 404, 'Promotion not found.');

        $services = DB::table('promotion_service as ps')
            ->join('service as s', "s.{$servicePk}", '=', "ps.{$promotionServiceServiceFk}")
            ->where('ps.promotion_id', $promotionId)
            ->orderBy('s.name')
            ->pluck('s.name')
            ->all();

        $discountText = strtolower((string) $promotion->discount_type) === 'fixed'
            ? 'RM ' . number_format((float) $promotion->discount_value, 2)
            : rtrim(rtrim(number_format((float) $promotion->discount_value, 2), '0'), '.') . '%';

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Promotion #' . $promotion->promotion_id . '</title><style>@media print{body{margin:0;} .no-print{display:none !important;}}</style></head><body style="font-family:Arial,sans-serif;padding:24px;line-height:1.5;color:#1f2937;">'
            . '<div class="no-print" style="margin:0 0 16px;"><button onclick="window.print()" style="padding:8px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;font-weight:700;cursor:pointer;">Print</button></div>'
            . '<h1 style="margin:0 0 8px;">UniSpa Promotion</h1>'
            . '<p style="margin:0 0 18px;color:#64748b;">Printed on ' . now()->format('d M Y, h:i A') . '</p>'
            . '<table style="width:100%;border-collapse:collapse;">'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;width:220px;"><strong>Promotion ID</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">#' . e((string) $promotion->promotion_id) . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>Title</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . e((string) ($promotion->title ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>Description</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . e((string) ($promotion->description ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>Discount</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . e($discountText) . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>Start Date</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . e((string) ($promotion->start_date ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>End Date</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . e((string) ($promotion->end_date ?? 'No End Date')) . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>Active</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . ($promotion->is_active ? 'Yes' : 'No') . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>Show in Dashboard Header</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . ((property_exists($promotion, 'show_in_dashboard_header') ? $promotion->show_in_dashboard_header : true) ? 'Yes' : 'No') . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;"><strong>Link</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . e((string) ($promotion->link ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:8px;border:1px solid #e2e8f0;vertical-align:top;"><strong>Applied Services</strong></td><td style="padding:8px;border:1px solid #e2e8f0;">' . e(implode(', ', $services ?: ['All / None selected'])) . '</td></tr>'
            . '</table>'
            . '<script>window.onload = function(){ try { window.print(); } catch(e) {} };</script>'
            . '</body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function storeImage(?UploadedFile $file, string $folder): ?string
    {
        if (!$file) {
            return null;
        }

        $path = $file->store($folder, 'public');
        return Storage::url($path);
    }

    private function withTimestamps(string $table, array $payload, bool $updateOnly = false): array
    {
        if (Schema::hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = now();
        }
        if (!$updateOnly && Schema::hasColumn($table, 'created_at')) {
            $payload['created_at'] = now();
        }

        return $payload;
    }

    private function payloadForTable(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($_, $column) => Schema::hasColumn($table, (string) $column))
            ->all();
    }

    private function categoryPrimaryKey(): string
    {
        return Schema::hasColumn('service_category', 'category_id') ? 'category_id' : 'id';
    }

    private function servicePrimaryKey(): string
    {
        return Schema::hasColumn('service', 'service_id') ? 'service_id' : 'id';
    }

    private function promotionServiceServiceColumn(): string
    {
        return Schema::hasColumn('promotion_service', 'service_id') ? 'service_id' : 'id';
    }

    private function formatTags(?string $raw): string
    {
        if (!$raw) {
            return json_encode([]);
        }
        return json_encode(
            collect(explode(',', $raw))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->values()
            ->all()
        );
    }

    public function scheduling(Request $request)
    {
        $tz = config('app.timezone', 'Asia/Kuala_Lumpur');
        $selectedDate = Carbon::parse((string) $request->query('selected_date', now($tz)->toDateString()), $tz);
        $weekStart = Carbon::parse((string) $request->query('week_start', $selectedDate->toDateString()), $tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $monthStart = $selectedDate->copy()->startOfMonth();
        $monthEnd = $selectedDate->copy()->endOfMonth();
        $today = now($tz)->toDateString();

        $staffRows = DB::table('staff')
            ->select(['staff_id', 'name', 'email', 'phone', 'staff_type', 'role', 'work_status'])
            ->orderBy('staff_type')
            ->orderBy('name')
            ->get();

        $staffDirectory = [
            'general' => $staffRows->where('staff_type', 'general')->values(),
            'student' => $staffRows->where('staff_type', 'student')->values(),
        ];

        $weekDays = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $today) {
            $date = $weekStart->copy()->addDays($offset);
            return [
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'label' => $date->format('d M'),
                'is_today' => $date->toDateString() === $today,
            ];
        })->values();

        $hasApproval = Schema::hasColumn('schedule', 'approval_status');

        $schedules = DB::table('schedule as sc')
            ->join('staff as st', 'st.staff_id', '=', 'sc.staff_id')
            ->whereBetween('sc.schedule_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->select([
                'sc.schedule_id',
                'sc.staff_id',
                'st.name as staff_name',
                'st.staff_type',
                'sc.schedule_date',
                'sc.start_time',
                'sc.end_time',
                'sc.created_by',
                'sc.status',
                $hasApproval ? 'sc.approval_status' : DB::raw("'approved' as approval_status"),
            ])
            ->orderBy('sc.schedule_date')
            ->orderBy('sc.start_time')
            ->get();

        $assignedBookings = DB::table('booking as b')
            ->join('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->join('staff as st', 'st.staff_id', '=', 'sl.staff_id')
            ->leftJoin('service as sv', 'sv.id', '=', 'sl.service_id')
            ->whereBetween('sl.slot_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereIn('b.status', ['pending', 'accepted', 'confirmed'])
            ->select([
                'b.booking_id',
                'b.status as booking_status',
                'sl.staff_id',
                'st.name as staff_name',
                'st.staff_type',
                'sl.slot_date as schedule_date',
                'sl.start_time',
                'sl.end_time',
                'sv.name as service_name',
            ])
            ->orderBy('sl.slot_date')
            ->orderBy('sl.start_time')
            ->get();

        $pendingQrBookings = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->leftJoin('service as sv', DB::raw('COALESCE(sl.service_id, ' . $this->tmpServiceIdSql() . ')'), '=', 'sv.id')
            ->leftJoin('staff as st', 'st.staff_id', '=', 'sl.staff_id')
            ->leftJoin('treatment_room as tr', 'tr.room_id', '=', 'sl.room_id')
            ->where('b.payment_method', 'qr')
            ->where('b.payment_status', 'pending')
            ->whereNotNull('b.depo_qr_pic')
            ->select([
                'b.booking_id',
                'b.status',
                'b.payment_status',
                'b.depo_qr_pic',
                'b.special_requests',
                DB::raw('COALESCE(sl.slot_id, b.slot_id) as slot_id'),
                DB::raw('COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ') as slot_date'),
                DB::raw('COALESCE(sl.start_time, ' . $this->tmpSlotStartSql() . ') as start_time'),
                'sl.end_time',
                'sl.staff_id',
                'sl.room_id',
                DB::raw('COALESCE(sl.service_id, ' . $this->tmpServiceIdSql() . ') as service_id'),
                'sv.category_id as service_category_id',
                'c.name as customer_name',
                'sv.name as service_name',
                'st.name as assigned_staff_name',
                'tr.room_name',
                'tr.room_type',
            ])
            ->orderByRaw('COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ')')
            ->orderByRaw('COALESCE(sl.start_time, ' . $this->tmpSlotStartSql() . ')')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                $row->proof_url = $row->depo_qr_pic ? Storage::url($row->depo_qr_pic) : null;
                return $row;
            });

        $selectedDateBookings = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->leftJoin('service as sv', DB::raw('COALESCE(sl.service_id, ' . $this->tmpServiceIdSql() . ')'), '=', 'sv.id')
            ->leftJoin('staff as st', 'st.staff_id', '=', 'sl.staff_id')
            ->leftJoin('treatment_room as tr', 'tr.room_id', '=', 'sl.room_id')
            ->where(function ($q) use ($selectedDate) {
                $selectedDateStr = $selectedDate->toDateString();
                $q->whereDate('sl.slot_date', $selectedDateStr)
                    ->orWhereRaw("b.slot_id LIKE ? AND split_part(b.slot_id, ':', 3) = ?", ['TMP:%', $selectedDateStr]);
            })
            ->whereIn('b.status', ['pending', 'accepted', 'confirmed'])
            ->select([
                'b.booking_id',
                'b.status',
                'b.payment_status',
                'b.payment_method',
                'b.depo_qr_pic',
                'b.special_requests',
                DB::raw('COALESCE(sl.slot_id, b.slot_id) as slot_id'),
                DB::raw('COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ') as slot_date'),
                DB::raw('COALESCE(sl.start_time, ' . $this->tmpSlotStartSql() . ') as start_time'),
                'sl.end_time',
                'sl.staff_id',
                'sl.room_id',
                DB::raw('COALESCE(sl.service_id, ' . $this->tmpServiceIdSql() . ') as service_id'),
                'sv.category_id as service_category_id',
                'sv.name as service_name',
                'c.name as customer_name',
                'st.name as assigned_staff_name',
                'tr.room_name',
                'tr.room_type',
            ])
            ->orderByRaw('COALESCE(sl.start_time, ' . $this->tmpSlotStartSql() . ')')
            ->get()
            ->map(function ($row) {
                $row->proof_url = $row->depo_qr_pic ? Storage::url($row->depo_qr_pic) : null;
                return $row;
            });

        $monthAppointmentDateSql = 'COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ')';
        $monthAppointmentCounts = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->whereBetween(DB::raw($monthAppointmentDateSql), [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereIn('b.status', ['pending', 'accepted', 'confirmed'])
            ->select([
                DB::raw($monthAppointmentDateSql . ' as slot_date'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN b.payment_method = 'qr' AND b.payment_status = 'pending' THEN 1 ELSE 0 END) as qr_pending_count"),
            ])
            ->groupByRaw($monthAppointmentDateSql)
            ->get();

        $monthSchedules = DB::table('schedule as sc')
            ->join('staff as st', 'st.staff_id', '=', 'sc.staff_id')
            ->whereBetween('sc.schedule_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->select([
                'sc.schedule_id',
                'sc.staff_id',
                'st.name as staff_name',
                'st.staff_type',
                'sc.schedule_date',
                'sc.start_time',
                'sc.end_time',
                'sc.created_by',
                'sc.status',
                $hasApproval ? 'sc.approval_status' : DB::raw("'approved' as approval_status"),
            ])
            ->orderBy('sc.schedule_date')
            ->orderBy('sc.start_time')
            ->get();

        // Pending student staff availability requests (for admin approval)
        $pendingStudentAvailability = collect();
        if ($hasApproval) {
            $pendingStudentAvailability = DB::table('schedule as sc')
                ->join('staff as st', 'st.staff_id', '=', 'sc.staff_id')
                ->where('st.staff_type', 'student')
                ->whereBetween('sc.schedule_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('sc.created_by', 'staff')
                ->where('sc.status', 'active')
                ->where('sc.approval_status', 'pending')
                ->select([
                    'sc.schedule_id',
                    'sc.staff_id',
                    'st.name as staff_name',
                    'sc.schedule_date',
                    'sc.start_time',
                    'sc.end_time',
                    'sc.approval_status',
                ])
                ->orderBy('sc.schedule_date')
                ->orderBy('sc.start_time')
                ->get();
        }

        $roomLabelCol = Schema::hasColumn('treatment_room', 'room_name') ? 'room_name' : 'room_type';
        $roomCategoryCol = Schema::hasColumn('treatment_room', 'category_id') ? 'category_id' : null;
        $roomGenderCol = Schema::hasColumn('treatment_room', 'gender') ? 'gender' : null;
        $rooms = DB::table('treatment_room')
            ->select([
                'room_id',
                DB::raw("COALESCE({$roomLabelCol}, room_type) as room_label"),
                'room_type',
                'status',
                $roomCategoryCol ? "{$roomCategoryCol} as category_id" : DB::raw('NULL as category_id'),
                $roomGenderCol ? "{$roomGenderCol} as gender" : DB::raw('NULL as gender'),
            ])
            ->when(Schema::hasColumn('treatment_room', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('room_id')
            ->get();

        return Inertia::render('Admin/ManageScheduling', [
            'kpis' => [
                'todaySchedules' => DB::table('schedule')->whereDate('schedule_date', $today)->count(),
                'todaySlots' => DB::table('slot')->whereDate('slot_date', $today)->count(),
                'availableSlots' => DB::table('slot')->whereDate('slot_date', $today)->where('status', 'available')->count(),
                'pendingQrProofs' => DB::table('booking')->where('payment_method', 'qr')->where('payment_status', 'pending')->whereNotNull('depo_qr_pic')->count(),
                'totalRooms' => DB::table('treatment_room')->count(),
            ],
            'filters' => [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'selected_date' => $selectedDate->toDateString(),
                'month_start' => $monthStart->toDateString(),
                'month_end' => $monthEnd->toDateString(),
            ],
            'weekDays' => $weekDays,
            'staffDirectory' => $staffDirectory,
            'staffOptions' => $staffRows->where('work_status', 'active')->values(),
            'rooms' => $rooms,
            'schedules' => $schedules,
            'monthSchedules' => $monthSchedules,
            'assignedBookings' => $assignedBookings,
            'selectedDateBookings' => $selectedDateBookings,
            'monthAppointmentCounts' => $monthAppointmentCounts,
            'pendingQrBookings' => $pendingQrBookings,
            'pendingStudentAvailability' => $pendingStudentAvailability,
        ]);
    }

    public function approveStudentAvailability(Request $request)
    {
        if (!Schema::hasColumn('schedule', 'approval_status')) {
            return back()->with('error', 'Approval workflow is not enabled yet. Please run migrations.');
        }

        $validated = $request->validate([
            'schedule_ids' => ['required', 'array', 'min:1'],
            'schedule_ids.*' => ['integer'],
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = $validated['action'] === 'approve' ? 'approved' : 'rejected';

        $payload = ['approval_status' => $newStatus];
        if ($newStatus === 'rejected' && Schema::hasColumn('schedule', 'approval_notes')) {
            $payload['approval_notes'] = !empty($validated['notes']) ? trim((string) $validated['notes']) : null;
        }
        if ($newStatus === 'approved' && Schema::hasColumn('schedule', 'approval_notes')) {
            // Clear notes on approval
            $payload['approval_notes'] = null;
        }

        DB::table('schedule')
            ->whereIn('schedule_id', $validated['schedule_ids'])
            ->update($payload);

        // Send email to each affected student staff (best-effort).
        try {
            $scheduleIds = collect($validated['schedule_ids'])->map(fn ($v) => (int) $v)->values();
            $staffIds = DB::table('schedule')
                ->whereIn('schedule_id', $scheduleIds)
                ->pluck('staff_id')
                ->unique()
                ->values();

            $tz = config('app.timezone', 'Asia/Kuala_Lumpur');
            $now = now($tz);
            $monthStart = $now->copy()->startOfMonth();
            $monthEnd = $now->copy()->endOfMonth();

            foreach ($staffIds as $sid) {
                $staff = Staff::query()->where('staff_id', (int) $sid)->first();
                if (!$staff || empty($staff->email)) {
                    continue;
                }
                Mail::to($staff->email)->send(new StaffAvailabilityReviewedMail(
                    staffName: (string) ($staff->name ?? 'Student Staff'),
                    monthStart: $monthStart,
                    monthEnd: $monthEnd,
                    decision: $newStatus,
                    itemsCount: (int) $scheduleIds->count(),
                    notes: $newStatus === 'rejected' ? ($payload['approval_notes'] ?? null) : null,
                ));
            }
        } catch (\Throwable $e) {
            // ignore mail errors
        }

        return back()->with('success', "Part-Time staff availability has been {$newStatus}.");
    }

    public function storeShift(Request $request)
    {
        $validated = $request->validate([
            'staff_id'      => ['required', 'integer', Rule::exists('staff', 'staff_id')],
            'schedule_date' => ['required', 'date'],
            'start_time'    => ['required', 'date_format:H:i'],
            'end_time'      => ['required', 'date_format:H:i', 'after:start_time'],
            'created_by'    => ['nullable', Rule::in(['admin', 'staff'])],
            'room_id'       => ['nullable', 'integer', Rule::exists('treatment_room', 'room_id')],
        ]);

        // Business hours validation (10:00 – 18:00)
        if ($validated['start_time'] < '10:00' || $validated['end_time'] > '18:00') {
            return back()->with('error', 'Time outside business hours. Shifts must be within 10:00 – 18:00.');
        }

        // Staff conflict check [E1]
        $hasConflict = DB::table('schedule')
            ->where('staff_id', (int) $validated['staff_id'])
            ->whereDate('schedule_date', $validated['schedule_date'])
            ->where('status', 'active')
            ->where('start_time', '<', $validated['end_time'])
            ->where('end_time', '>', $validated['start_time'])
            ->exists();

        if ($hasConflict) {
            return back()->with('error', 'Staff already scheduled during this time period.');
        }

        // Room conflict check [E2]
        if (!empty($validated['room_id'])) {
            $roomConflict = DB::table('schedule')
                ->whereDate('schedule_date', $validated['schedule_date'])
                ->where('status', 'active')
                ->where('start_time', '<', $validated['end_time'])
                ->where('end_time', '>', $validated['start_time'])
                ->when(Schema::hasColumn('schedule', 'room_id'), fn ($q) => $q->where('room_id', (int) $validated['room_id']))
                ->exists();

            if ($roomConflict && Schema::hasColumn('schedule', 'room_id')) {
                return back()->with('error', 'Selected room is already occupied during this time period.');
            }
        }

        $insertData = [
            'staff_id'      => (int) $validated['staff_id'],
            'schedule_date' => $validated['schedule_date'],
            'start_time'    => $validated['start_time'],
            'end_time'      => $validated['end_time'],
            'created_by'    => $validated['created_by'] ?? 'admin',
            'status'        => 'active',
        ];

        if (!empty($validated['room_id']) && Schema::hasColumn('schedule', 'room_id')) {
            $insertData['room_id'] = (int) $validated['room_id'];
        }

        DB::table('schedule')->insert($insertData);

        return back()->with('success', 'Shift added successfully.');
    }

    public function publishSchedule(Request $request)
    {
        $validated = $request->validate([
            'week_start' => ['required', 'date'],
        ]);

        $weekStart = Carbon::parse($validated['week_start'])->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $updated = DB::table('schedule')
            ->whereBetween('schedule_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->update(['status' => 'active']);

        return back()->with('success', "Schedule published for {$weekStart->format('d M')} - {$weekEnd->format('d M Y')} ({$updated} rows). Notifications can now be sent.");
    }

    public function confirmQrAndAssign(Request $request, string $bookingId)
    {
        $validated = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('staff', 'staff_id')],
            'room_id' => ['nullable', 'integer', Rule::exists('treatment_room', 'room_id')],
        ]);

        $booking = Booking::with(['customer', 'participants', 'slot.service'])
            ->where('booking_id', $bookingId)
            ->first();

        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        if (($booking->payment_method ?? null) !== 'qr') {
            return back()->with('error', 'Only QR payment bookings can be confirmed here.');
        }

        if (empty($booking->depo_qr_pic)) {
            return back()->with('error', 'Payment proof image is missing for this booking.');
        }

        $slot = $booking->slot;
        if (!$slot && str_starts_with((string) $booking->slot_id, 'TMP:')) {
            $parsedTmp = $this->parseTmpSlotId((string) $booking->slot_id);
            if (!$parsedTmp || empty($parsedTmp['service_id'])) {
                return back()->with('error', 'Slot data is invalid for this booking.');
            }

            try {
                $realSlotId = app(PaymentController::class)->allocateRealSlotId((string) $booking->slot_id, $parsedTmp['service_id']);
                $booking->slot_id = $realSlotId;
                $booking->save();
                $booking->refresh();
                $slot = $booking->slot;
            } catch (\Throwable $e) {
                \Log::error('Unable to allocate real slot for QR booking confirmation: ' . $e->getMessage(), [
                    'booking_id' => $booking->booking_id,
                    'slot_id' => $booking->slot_id,
                ]);
                return back()->with('error', 'Unable to allocate a real slot for this booking. Please review availability and try again.');
            }
        }

        if (!$slot) {
            return back()->with('error', 'Slot not found for this booking.');
        }

        $staffBusy = DB::table('slot as sl')
            ->join('booking as b', 'b.slot_id', '=', 'sl.slot_id')
            ->where('sl.slot_date', $slot->slot_date)
            ->where('sl.staff_id', (int) $validated['staff_id'])
            ->whereIn('b.status', ['pending', 'accepted', 'confirmed'])
            ->where('b.booking_id', '!=', $booking->booking_id)
            ->where('sl.start_time', '<', (string) $slot->end_time)
            ->where('sl.end_time', '>', (string) $slot->start_time)
            ->exists();

        if ($staffBusy) {
            return back()->with('error', 'Selected staff is already assigned in this time range.');
        }

        if (!empty($validated['room_id'])) {
            if (Schema::hasColumn('treatment_room', 'category_id')) {
                $serviceCategoryId = DB::table('service')
                    ->where('id', $slot->service_id)
                    ->value('category_id');

                $roomCategoryId = DB::table('treatment_room')
                    ->where('room_id', (int) $validated['room_id'])
                    ->value('category_id');

                if (!empty($serviceCategoryId) && (int) $serviceCategoryId !== (int) $roomCategoryId) {
                    return back()->with('error', 'Selected room is not compatible with the booked service category.');
                }
            }

            $roomBusy = DB::table('slot as sl')
                ->join('booking as b', 'b.slot_id', '=', 'sl.slot_id')
                ->where('sl.slot_date', $slot->slot_date)
                ->where('sl.room_id', (int) $validated['room_id'])
                ->whereIn('b.status', ['pending', 'accepted', 'confirmed'])
                ->where('b.booking_id', '!=', $booking->booking_id)
                ->where('sl.start_time', '<', (string) $slot->end_time)
                ->where('sl.end_time', '>', (string) $slot->start_time)
                ->exists();

            if ($roomBusy) {
                return back()->with('error', 'Selected room is already occupied in this time range.');
            }
        }

        DB::transaction(function () use ($booking, $slot, $validated) {
            DB::table('slot')
                ->where('slot_id', $slot->slot_id)
                ->update([
                    'staff_id' => (int) $validated['staff_id'],
                    'room_id' => !empty($validated['room_id']) ? (int) $validated['room_id'] : $slot->room_id,
                    'status' => 'booked',
                ]);

            $booking->payment_status = 'paid';
            $booking->status = 'confirmed';
            if (!empty($validated['room_id']) && Schema::hasColumn('booking', 'room_id')) {
                $booking->room_id = (int) $validated['room_id'];
            }

            $receiptRef = 'QR-' . now()->format('YmdHis');
            $booking->digital_receipt = $this->generateBookingReceipt($booking, $receiptRef);
            $booking->save();
        });

        $booking->refresh()->loadMissing(['customer', 'participants', 'slot.service']);

        try {
            foreach ($this->bookingRecipients($booking) as $recipient) {
                Mail::to($recipient['email'])->send(
                    new BookingConfirmedMail(
                        booking: $booking,
                        recipientName: $recipient['name']
                    )
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('QR confirm email failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Booking confirmed. Customer has been notified.');
    }

    public function bookings()
    {
        $search = trim((string) request('search', ''));
        $status = (string) request('status', 'all');
        $payment = (string) request('payment', 'all');
        $serviceId = (string) request('service_id', 'all');
        $selectedDate = Carbon::parse((string) request('selected_date', now(config('app.timezone', 'Asia/Kuala_Lumpur'))->toDateString()));
        $perPage = (int) request('per_page', 10);
        $perPage = in_array($perPage, [8, 10, 15, 20], true) ? $perPage : 10;

        $monthStart = $selectedDate->copy()->startOfMonth()->toDateString();
        $monthEnd = $selectedDate->copy()->endOfMonth()->toDateString();
        $selectedDateStr = $selectedDate->toDateString();

        $bookingsBase = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->leftJoin('service as sv', DB::raw('COALESCE(sl.service_id, ' . $this->tmpServiceIdSql() . ')'), '=', 'sv.id')
            ->leftJoin('staff as st', 'st.staff_id', '=', 'sl.staff_id')
            ->leftJoin('treatment_room as tr', 'tr.room_id', '=', 'sl.room_id')
            ->select([
                'b.booking_id',
                'b.customer_id',
                'b.status',
                'b.payment_method',
                'b.payment_status',
                'b.final_amount',
                'b.deposit_amount',
                'b.total_amount',
                'b.special_requests',
                'b.depo_qr_pic',
                'b.digital_receipt',
                'b.created_at',
                DB::raw('COALESCE(sl.slot_id, b.slot_id) as slot_id'),
                DB::raw('COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ') as slot_date'),
                DB::raw('COALESCE(sl.start_time, ' . $this->tmpSlotStartSql() . ') as start_time'),
                'sl.end_time',
                DB::raw('COALESCE(sl.service_id, ' . $this->tmpServiceIdSql() . ') as service_id'),
                'sv.name as service_name',
                'sv.category_id as service_category_id',
                'c.name as customer_name',
                'c.email as customer_email',
                'st.staff_id',
                'st.name as staff_name',
                'tr.room_id',
                'tr.room_name',
                'tr.room_type',
            ]);

        if ($search !== '') {
            $bookingsBase->where(function ($q) use ($search) {
                $q->where('b.booking_id', 'like', "%{$search}%")
                    ->orWhere('c.name', 'like', "%{$search}%")
                    ->orWhere('c.email', 'like', "%{$search}%")
                    ->orWhere('sv.name', 'like', "%{$search}%");
            });
        }

        if ($status !== 'all') {
            $bookingsBase->where('b.status', $status);
        }

        if ($payment !== 'all') {
            $bookingsBase->where('b.payment_status', $payment);
        }

        if ($serviceId !== 'all' && is_numeric($serviceId)) {
            $bookingsBase->where(function ($q) use ($serviceId) {
                $q->where('sl.service_id', (int) $serviceId)
                    ->orWhereRaw("b.slot_id LIKE ? AND split_part(b.slot_id, ':', 2) = ?", ['TMP:%', (string) $serviceId]);
            });
        }

        $bookingDateSql = 'COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ')';
        $bookingStartSql = 'COALESCE(sl.start_time, ' . $this->tmpSlotStartSql() . ')';
        $bookings = (clone $bookingsBase)
            ->orderByDesc(DB::raw($bookingDateSql))
            ->orderByDesc(DB::raw($bookingStartSql))
            ->paginate($perPage)
            ->through(function ($row) {
                $booking = new Booking([
                    'booking_id' => $row->booking_id,
                    'status' => $row->status,
                ]);
                $booking->setRelation('slot', (object) [
                    'slot_date' => Carbon::parse((string) $row->slot_date),
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'service' => (object) ['name' => $row->service_name],
                ]);

                return [
                    ...((array) $row),
                    'proof_url' => $row->depo_qr_pic ? Storage::url($row->depo_qr_pic) : null,
                    'receipt_url' => $row->digital_receipt ? Storage::url($row->digital_receipt) : null,
                    'google_calendar_url' => BookingCalendar::googleCalendarUrl($booking),
                ];
            })
            ->withQueryString();

        $selectedDateBookings = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'b.customer_id')
            ->leftJoin('service as sv', DB::raw('COALESCE(sl.service_id, ' . $this->tmpServiceIdSql() . ')'), '=', 'sv.id')
            ->where(function ($q) use ($selectedDateStr) {
                $q->whereDate('sl.slot_date', $selectedDateStr)
                    ->orWhereRaw("b.slot_id LIKE ? AND split_part(b.slot_id, ':', 3) = ?", ['TMP:%', $selectedDateStr]);
            })
            ->select([
                'b.booking_id',
                'b.status',
                'b.payment_status',
                'b.payment_method',
                DB::raw('COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ') as slot_date'),
                DB::raw('COALESCE(sl.start_time, ' . $this->tmpSlotStartSql() . ') as start_time'),
                'sl.end_time',
                'sv.name as service_name',
                'c.name as customer_name',
                'b.final_amount',
            ])
            ->orderByRaw($bookingStartSql)
            ->get();

        $monthBookingDateSql = 'COALESCE(sl.slot_date, ' . $this->tmpSlotDateSql() . ')';
        $monthBookingCounts = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->whereBetween(DB::raw($monthBookingDateSql), [$monthStart, $monthEnd])
            ->select([
                DB::raw($monthBookingDateSql . ' as slot_date'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN b.payment_status = 'pending' THEN 1 ELSE 0 END) as pending_payment_count"),
            ])
            ->groupByRaw($monthBookingDateSql)
            ->get();

        $services = DB::table('service')
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/ManageBookings', [
            'kpis' => [
                'totalBookings' => DB::table('booking')->count(),
                'pendingBookings' => DB::table('booking')->whereIn('status', ['pending', 'cart'])->count(),
                'approvedBookings' => DB::table('booking')->whereIn('status', ['accepted', 'confirmed'])->count(),
                'pendingPayments' => DB::table('booking')->where('payment_status', 'pending')->count(),
                'completedBookings' => DB::table('booking')->where('status', 'completed')->count(),
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'payment' => $payment,
                'service_id' => $serviceId,
                'selected_date' => $selectedDateStr,
                'per_page' => $perPage,
            ],
            'services' => $services,
            'bookings' => $bookings,
            'selectedDateBookings' => $selectedDateBookings,
            'monthBookingCounts' => $monthBookingCounts,
        ]);
    }

    public function approveBooking(string $bookingId)
    {
        $booking = Booking::with(['customer', 'participants', 'slot.service'])
            ->where('booking_id', $bookingId)
            ->first();

        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        if (($booking->payment_method ?? null) === 'qr' && empty($booking->depo_qr_pic)) {
            return back()->with('error', 'QR proof is required before approval.');
        }

        $booking->status = 'accepted';
        if (($booking->payment_method ?? null) === 'qr') {
            $booking->payment_status = 'paid';
        }

        try {
            $receiptRef = strtoupper((string)($booking->payment_method ?: 'MANUAL')) . '-' . now()->format('YmdHis');
            $booking->digital_receipt = $this->generateBookingReceipt($booking, $receiptRef);
        } catch (\Throwable $e) {
            \Log::warning('Receipt generation failed: ' . $e->getMessage());
        }
        $booking->save();

        try {
            foreach ($this->bookingRecipients($booking) as $recipient) {
                Mail::to($recipient['email'])->send(
                    new BookingConfirmedMail(
                        booking: $booking,
                        recipientName: $recipient['name']
                    )
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Booking approved but email failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Booking confirmed.');
    }

    public function updateBookingStatus(Request $request, string $bookingId)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['cart', 'pending', 'accepted', 'confirmed', 'completed', 'cancelled'])],
        ]);

        $booking = Booking::where('booking_id', $bookingId)->first();
        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        $booking->status = $validated['status'];
        $booking->save();

        return back()->with('success', 'Booking status updated.');
    }

    public function updateBookingPayment(Request $request, string $bookingId)
    {
        $validated = $request->validate([
            'payment_status' => ['required', Rule::in(['pending', 'paid', 'unpaid'])],
        ]);

        $booking = Booking::where('booking_id', $bookingId)->first();
        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        $booking->payment_status = $validated['payment_status'];
        $booking->save();

        return back()->with('success', 'Payment status updated.');
    }

    public function updateBookingDetails(Request $request, string $bookingId)
    {
        $validated = $request->validate([
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = Booking::where('booking_id', $bookingId)->first();
        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        $booking->special_requests = $validated['special_requests'] ?? null;
        $booking->save();

        return back()->with('success', 'Booking details updated.');
    }

    public function destroyBooking(string $bookingId)
    {
        $booking = Booking::where('booking_id', $bookingId)->first();
        if (!$booking) {
            return back()->with('error', 'Booking not found.');
        }

        try {
            DB::transaction(function () use ($booking) {
                DB::table('booking_participant')->where('booking_id', $booking->booking_id)->delete();
                DB::table('booking')->where('booking_id', $booking->booking_id)->delete();
            });

            return back()->with('success', 'Booking deleted successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Unable to delete booking with linked records.');
        }
    }

    public function reviews()
    {
        $search = trim((string) request('search', ''));
        $rating = (string) request('rating', 'all');
        $perPage = (int) request('per_page', 10);
        $perPage = in_array($perPage, [8, 10, 15, 20], true) ? $perPage : 10;

        $reviewsQuery = DB::table('review as r')
            ->leftJoin('customers as c', 'c.customer_id', '=', 'r.customer_id')
            ->leftJoin('booking as b', 'b.booking_id', '=', 'r.booking_id')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('service as sv', 'sv.id', '=', 'sl.service_id')
            ->select([
                'r.review_id',
                'r.booking_id',
                'r.customer_id',
                'r.rating',
                'r.comment',
                'r.created_at',
                'c.name as customer_name',
                'c.email as customer_email',
                'sv.name as service_name',
            ]);

        if ($search !== '') {
            $reviewsQuery->where(function ($q) use ($search) {
                $q->where('r.review_id', 'like', "%{$search}%")
                    ->orWhere('r.booking_id', 'like', "%{$search}%")
                    ->orWhere('c.name', 'like', "%{$search}%")
                    ->orWhere('c.email', 'like', "%{$search}%")
                    ->orWhere('sv.name', 'like', "%{$search}%")
                    ->orWhere('r.comment', 'like', "%{$search}%");
            });
        }

        if ($rating !== 'all' && in_array($rating, ['1', '2', '3', '4', '5'], true)) {
            $reviewsQuery->where('r.rating', (int) $rating);
        }

        $reviews = $reviewsQuery
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.review_id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Admin/ManageReviews', [
            'kpis' => [
                'totalReviews' => (int) DB::table('review')->count(),
                'avgRating' => round((float) (DB::table('review')->avg('rating') ?? 0), 1),
                'fiveStarReviews' => (int) DB::table('review')->where('rating', 5)->count(),
                'lowReviews' => (int) DB::table('review')->where('rating', '<=', 2)->count(),
            ],
            'filters' => [
                'search' => $search,
                'rating' => $rating,
                'per_page' => $perPage,
            ],
            'reviews' => $reviews,
        ]);
    }

    public function payments()
    {
        return $this->renderModulePage(
            'Manage Payment',
            'Verify QR payments, monitor pending transactions and maintain payment status integrity (UCD700).',
            [
                'totalPayments' => DB::table('booking')->count(),
                'pendingPayments' => DB::table('booking')->where('payment_status', 'pending')->count(),
                'paidPayments' => DB::table('booking')->where('payment_status', 'paid')->count(),
                'qrPending' => DB::table('booking')
                    ->where('payment_method', 'qr')
                    ->where('payment_status', 'pending')
                    ->count(),
            ]
        );
    }

    private function renderModulePage(string $title, string $description, array $kpis)
    {
        return Inertia::render('Admin/AdminModule', [
            'title' => $title,
            'description' => $description,
            'kpis' => $kpis,
        ]);
    }

    public function approveQrBooking(Request $request, string $bookingId)
    {
        return $this->approveBooking($bookingId);
    }
}
