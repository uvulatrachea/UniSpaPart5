<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        if (!auth('customer')->check()) {
            return redirect()->route('customer.login')->with('error', 'Please login to access the dashboard.');
        }

        $customerId = auth('customer')->user()->customer_id;

        // Promotions for dashboard header carousel — active, not expired, flagged to show
        $promotions = DB::table('promotion as p')
            ->select('p.promotion_id', 'p.title', 'p.description', 'p.discount_type', 'p.discount_value', 'p.banner_image', 'p.link')
            ->where('p.is_active', true)
            ->where('p.show_in_dashboard_header', true)
            ->where(function ($q) {
                $q->whereNull('p.end_date')
                  ->orWhere('p.end_date', '>=', now()->toDateString());
            })
            ->orderByDesc('p.promotion_id')
            ->limit(8)
            ->get();

        // Popular services (simple)
        $services = DB::table('service')
            ->select('id', 'name', 'description', 'price', 'duration_minutes', 'image_url', 'is_popular')
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn ($s) => [
                'service_id' => (int) $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'price' => (float) $s->price,
                'duration_minutes' => (int) $s->duration_minutes,
                'image' => $s->image_url,
            ]);

        // Customer appointments (join booking + slot + staff + service)
        $bookingTmpStart = DB::connection()->getDriverName() === 'pgsql'
            ? "CASE WHEN b.slot_id LIKE 'TMP:%' THEN CAST(split_part(b.slot_id, ':', 4) || ':' || split_part(b.slot_id, ':', 5) AS time) ELSE NULL END"
            : "CASE WHEN b.slot_id LIKE 'TMP:%' THEN split_part(b.slot_id, ':', 4) || ':' || split_part(b.slot_id, ':', 5) ELSE NULL END";

        $appointments = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->join('service as sv', DB::raw("COALESCE(sl.service_id, CASE WHEN b.slot_id LIKE 'TMP:%' THEN CAST(split_part(b.slot_id, ':', 2) AS integer) ELSE NULL END)"), '=', 'sv.id')
            ->leftJoin('staff as st', 'st.staff_id', '=', 'sl.staff_id')
            ->where('b.customer_id', $customerId)
            ->orderByDesc(DB::raw("COALESCE(sl.slot_date, CASE WHEN b.slot_id LIKE 'TMP:%' THEN CAST(split_part(b.slot_id, ':', 3) AS date) ELSE NULL END)"))
            ->orderByDesc(DB::raw('COALESCE(sl.start_time, ' . $bookingTmpStart . ')'))
            ->limit(8)
            ->get([
                'b.booking_id',
                'b.status',
                DB::raw("COALESCE(sl.slot_date, CASE WHEN b.slot_id LIKE 'TMP:%' THEN CAST(split_part(b.slot_id, ':', 3) AS date) ELSE NULL END) as slot_date"),
                DB::raw('COALESCE(sl.start_time, ' . $bookingTmpStart . ') as start_time'),
                'sl.end_time',
                'sv.name as service_name',
                'st.name as staff_name',
            ]);

        $stats = [
            'totalCustomers' => (int) DB::table('customers')->count(),
            'totalBookings' => (int) DB::table('booking')->count(),
            'totalServices' => (int) DB::table('service')->count(),
            'totalStaff' => (int) DB::table('staff')->count(),
            'generalStaff' => (int) DB::table('staff')->where('staff_type', 'general')->count(),
            'studentStaff' => (int) DB::table('staff')->where('staff_type', 'student')->count(),
            'avgRating' => (float) (DB::table('review')->avg('rating') ?? 0),
            'completedBookings' => (int) DB::table('booking')->where('status', 'completed')->count(),
        ];

        return Inertia::render('CustomerDashboard', [
            'stats' => $stats,
            'promotions' => $promotions,
            'appointments' => $appointments,
            'services' => $services,
            // placeholders for optional UI sections
            'favoriteServices' => [],
            'goToService' => null,
            'notifications' => [],
        ]);
    }
}
