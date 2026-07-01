<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Booking;
use App\Models\Review;
use Inertia\Inertia;

class GuestDashboardController extends Controller
{
    public function index()
    {
        // Promotions — active, not expired, flagged to show in dashboard header
        $promotions = Promotion::query()
            ->where('is_active', true)
            ->where('show_in_dashboard_header', true)
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderByDesc('promotion_id')
            ->get()
            ->map(function ($p) {
                $serviceId = $p->services()->value('service.id');

                return [
                    'promotion_id' => $p->promotion_id,
                    'title' => $p->title,
                    'description' => $p->description,
                    'banner_image' => $p->banner_image,
                    'discount_type' => $p->discount_type,
                    'discount_value' => $p->discount_value,
                    'service_id' => $serviceId,
                    'link' => $p->link,
                ];
            });

        // ✅ Services from DB
        $services = Service::query()
            ->orderByDesc('is_popular')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        // ✅ Stats
        $totalStaff = Staff::count();
        $generalStaff = Staff::where('staff_type', 'general')->count();
        $studentStaff = Staff::where('staff_type', 'student')->count();
        $avgRating = Review::avg('rating') ?? 0;
        $totalServices = Service::count();
        $completedBookings = Booking::whereIn('status', ['completed', 'Completed'])->count();

        return Inertia::render('Guest/GuestDashboard', [
            'stats' => [
                'totalStaff' => $totalStaff,
                'generalStaff' => $generalStaff,
                'studentStaff' => $studentStaff,
                'avgRating' => $avgRating,
                'totalServices' => $totalServices,
                'completedBookings' => $completedBookings,
            ],
            'promotions' => $promotions,
            'services' => $services,
        ]);
    }
}
