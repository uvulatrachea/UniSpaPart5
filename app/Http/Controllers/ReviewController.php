<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReviewController extends Controller
{
    public function index()
    {
        $customer = Auth::guard('customer')->user();
        if (!$customer) {
            return redirect()->route('customer.login');
        }

        $reviews = DB::table('review as r')
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
                'sv.name as service_name',
            ])
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.review_id')
            ->get();

        $topReview = DB::table('review as r')
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
                'sv.name as service_name',
            ])
            ->orderByDesc('r.rating')
            ->orderByDesc('r.created_at')
            ->first();

        $stats = [
            'totalReviews' => (int) DB::table('review')->count(),
            'avgRating' => round((float) (DB::table('review')->avg('rating') ?? 0), 1),
            'fiveStarReviews' => (int) DB::table('review')->where('rating', 5)->count(),
            'myReviews' => (int) DB::table('review')->where('customer_id', $customer->customer_id)->count(),
        ];

        $canReviewBookings = DB::table('booking as b')
            ->leftJoin('slot as sl', 'sl.slot_id', '=', 'b.slot_id')
            ->leftJoin('review as r', 'r.booking_id', '=', 'b.booking_id')
            ->where('b.customer_id', $customer->customer_id)
            ->where('b.status', 'completed')
            ->whereNull('r.review_id')
            ->selectRaw("
                b.booking_id,
                (SELECT sv.name FROM service sv
                 WHERE sv.id = COALESCE(sl.service_id,
                   CASE WHEN b.slot_id LIKE 'TMP:%'
                        THEN SPLIT_PART(b.slot_id, ':', 2)::integer
                        ELSE NULL END)
                 LIMIT 1) AS service_name,
                COALESCE(sl.slot_date::text,
                  CASE WHEN b.slot_id LIKE 'TMP:%'
                       THEN SPLIT_PART(b.slot_id, ':', 3)
                       ELSE NULL END) AS slot_date
            ")
            ->orderByRaw("
                COALESCE(sl.slot_date,
                  CASE WHEN b.slot_id LIKE 'TMP:%'
                       THEN SPLIT_PART(b.slot_id, ':', 3)::date
                       ELSE NULL END) DESC NULLS LAST
            ")
            ->get();

        return Inertia::render('Reviews', [
            'auth' => [
                'user' => [
                    'customer_id' => $customer->customer_id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                ],
            ],
            'reviews' => $reviews,
            'topReview' => $topReview,
            'stats' => $stats,
            'canReviewBookings' => $canReviewBookings,
            'initialBookingId' => request()->query('booking_id', ''),
        ]);
    }

    public function store(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        if (!$customer) {
            return redirect()->route('customer.login');
        }

        $validated = $request->validate([
            'booking_id' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = DB::table('booking')
            ->where('booking_id', $validated['booking_id'])
            ->where('customer_id', $customer->customer_id)
            ->where('status', 'completed')
            ->first();

        if (!$booking) {
            return back()->with('error', 'Selected booking is not eligible for review.');
        }

        $alreadyReviewed = DB::table('review')
            ->where('booking_id', $validated['booking_id'])
            ->exists();

        if ($alreadyReviewed) {
            return back()->with('error', 'This booking already has a review.');
        }

        DB::table('review')->insert([
            'booking_id' => $validated['booking_id'],
            'customer_id' => $customer->customer_id,
            'rating' => (int) $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Thank you for your review!');
    }
}
