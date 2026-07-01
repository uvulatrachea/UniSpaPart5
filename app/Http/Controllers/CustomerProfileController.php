<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomerProfileController extends Controller
{
    public function show()
    {
        $customer = auth('customer')->user();

        // Quick stats for the sidebar
        $totalCustomers = DB::table('customers')->count();

        $studentStaff = 0;
        $generalStaff = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('staff')) {
            $studentStaff = DB::table('staff')
                ->where('staff_type', 'student')
                ->where('work_status', 'active')
                ->count();
            $generalStaff = DB::table('staff')
                ->where('staff_type', '!=', 'student')
                ->where('work_status', 'active')
                ->count();
        }

        $avgRating = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('review')) {
            $avgRating = DB::table('review')->avg('rating') ?? 0;
        }

        return Inertia::render('Profile', [
            'stats' => [
                'totalCustomers' => $totalCustomers,
                'studentStaff'   => $studentStaff,
                'generalStaff'   => $generalStaff,
                'avgRating'      => round((float) $avgRating, 1),
            ],
        ]);
    }

    public function update(Request $request)
    {
        try {
            $customer = auth('customer')->user();

            if (! $customer) {
                return response()->json(['ok' => false, 'message' => 'Not authenticated.'], 401);
            }

            $customerId = $customer->customer_id;

            $validated = $request->validate([
                'name'  => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customerId, 'customer_id')],
                'phone' => ['nullable', 'string', 'max:50'],
            ]);

            DB::table('customers')
                ->where('customer_id', $customerId)
                ->update([
                    'name'       => $validated['name'],
                    'email'      => strtolower(trim($validated['email'])),
                    'phone'      => $validated['phone'] ?? null,
                    'updated_at' => now(),
                ]);

            return response()->json(['ok' => true, 'message' => 'Profile updated successfully.']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Profile update failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }
}
