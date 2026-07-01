<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Mail\StaffAvailabilitySubmittedMail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StaffAvailabilityController extends Controller
{
    private function minWeeklyHours(): int
    {
        // Business rule: Student staff must submit at least 12 hours/week.
        // (Constant rule, not per-student.)
        return 12;
    }

    public function index(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 403);

        // Only student staff can submit weekly availability.
        abort_unless(($staff->staff_type ?? null) === 'student', 403);

        $tz = config('app.timezone', 'Asia/Kuala_Lumpur');
        $now = now($tz);

        $weekStart = Carbon::parse((string) $request->query('week_start', $now->toDateString()), $tz)
            ->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $hasApprovalStatus = Schema::hasColumn('schedule', 'approval_status');

        $rows = DB::table('schedule')
            ->where('staff_id', (int) $staff->staff_id)
            ->whereBetween('schedule_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('schedule_date')
            ->orderBy('start_time')
            ->get([
                'schedule_id',
                'schedule_date',
                'start_time',
                'end_time',
                'created_by',
                'status',
                Schema::hasColumn('schedule', 'approval_notes') ? 'approval_notes' : DB::raw('NULL as approval_notes'),
                $hasApprovalStatus ? 'approval_status' : DB::raw("'approved' as approval_status"),
            ]);

        // Prefill the form with saved DRAFT entries (if any).
        $draftEntries = collect($rows)
            ->filter(fn ($r) => strtolower((string) ($r->approval_status ?? '')) === 'draft')
            ->map(fn ($r) => [
                'schedule_date' => (string) $r->schedule_date,
                'start_time' => (string) $r->start_time,
                'approval_notes' => $r->approval_notes !== null ? (string) $r->approval_notes : null,
                'end_time' => (string) $r->end_time,
            ])
            ->values();

        $requiredHours = $this->minWeeklyHours();

        return Inertia::render('Staff/StaffAvailability', [
            'staffName' => $staff->name ?? 'Student Staff',
            'requiredHours' => $requiredHours,
            'officeHours' => [
                'start' => '10:00',
                'end' => '18:00',
            ],
            'filters' => [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'month' => $now->format('Y-m'),
            ],
            'existing' => $rows,
            'draftEntries' => $draftEntries,
        ]);
    }

    public function store(Request $request)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff, 403);

        // Only student staff can submit weekly availability.
        abort_unless(($staff->staff_type ?? null) === 'student', 403);

        $tz = config('app.timezone', 'Asia/Kuala_Lumpur');
        $now = now($tz);

        Log::info('Staff availability store called', [
            'staff_id' => $staff->staff_id ?? null,
            'staff_type' => $staff->staff_type ?? null,
            'mode' => $request->input('mode'),
            'week_start' => $request->input('week_start'),
            'entries_count' => is_array($request->input('entries')) ? count($request->input('entries')) : null,
        ]);

        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'mode' => ['nullable', Rule::in(['draft', 'submit'])],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.schedule_date' => ['required', 'date'],
            'entries.*.start_time' => ['required', 'date_format:H:i'],
            // NOTE: `after:*` with wildcards is tricky; we validate (end > start) manually below.
            'entries.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        $mode = (string) ($data['mode'] ?? 'submit');

        $weekStart = Carbon::parse((string) $data['week_start'], $tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        // Current month/year only.
        $currentMonth = $now->format('Y-m');

        $entries = collect($data['entries'] ?? [])
            ->map(fn ($e) => [
                'schedule_date' => (string) $e['schedule_date'],
                'start_time' => (string) $e['start_time'],
                'end_time' => (string) $e['end_time'],
            ])
            ->values();

        // Validate all entries are within the selected week + current month.
        foreach ($entries as $e) {
            $d = Carbon::parse($e['schedule_date'], $tz);
            if ($d->lt($weekStart) || $d->gt($weekEnd)) {
                return back()->with('error', 'All availability entries must be within the selected week (Mon-Sun).');
            }
            if ($d->format('Y-m') !== $currentMonth) {
                return back()->with('error', 'Student staff availability can only be submitted for the current month.');
            }

            // End time must be after start time (per-row).
            if (Carbon::parse($e['schedule_date'] . ' ' . $e['end_time'], $tz)
                ->lessThanOrEqualTo(Carbon::parse($e['schedule_date'] . ' ' . $e['start_time'], $tz))) {
                return back()->with('error', 'End time must be after start time.');
            }

            // Office hours 10:00 - 18:00
            if ($e['start_time'] < '10:00' || $e['end_time'] > '18:00') {
                return back()->with('error', 'Availability must be within office hours (10:00 - 18:00).');
            }
        }

        $minHours = $this->minWeeklyHours();

        $totalMinutes = 0;
        foreach ($entries as $e) {
            $start = Carbon::parse($e['schedule_date'] . ' ' . $e['start_time'], $tz);
            $end = Carbon::parse($e['schedule_date'] . ' ' . $e['end_time'], $tz);
            $totalMinutes += max(0, $end->diffInMinutes($start));
        }

        // Safety cap (avoid absurd submissions). This is NOT the business minimum.
        $maxHours = 40;
        if ($totalMinutes > $maxHours * 60) {
            return back()->with('error', "Total availability cannot exceed {$maxHours} hours for the week.");
        }

        // Eligibility rules only apply when actually submitting for admin approval.
        $uniqueDays = $entries->pluck('schedule_date')->unique()->count();
        if ($mode === 'submit') {
            if ($uniqueDays < 2) {
                return back()->with('error', 'Please choose at least 2 different days in the week.');
            }
            if ($totalMinutes < $minHours * 60) {
                return back()->with('error', "Total availability must be at least {$minHours} hours per week.");
            }
        }

        $hasApprovalStatus = Schema::hasColumn('schedule', 'approval_status');

        try {
            DB::transaction(function () use ($entries, $staff, $hasApprovalStatus, $mode, $weekStart, $weekEnd) {
                // Replace draft rows for this week (so staff can keep editing).
                if ($hasApprovalStatus) {
                    DB::table('schedule')
                        ->where('staff_id', (int) $staff->staff_id)
                        ->whereBetween('schedule_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                        ->where('created_by', 'staff')
                        ->where('status', 'active')
                        ->where('approval_status', 'draft')
                        ->delete();
                }

                foreach ($entries as $e) {
                    // Detect overlap with existing active schedules/availability.
                    $q = DB::table('schedule')
                        ->where('staff_id', (int) $staff->staff_id)
                        ->whereDate('schedule_date', $e['schedule_date'])
                        ->where('status', 'active')
                        ->where('start_time', '<', $e['end_time'])
                        ->where('end_time', '>', $e['start_time']);

                    if ($hasApprovalStatus) {
                        // Draft can coexist only with other drafts (but we deleted drafts above anyway).
                        $q->whereIn('approval_status', ['pending', 'approved']);
                    }

                    if ($q->exists()) {
                        throw new \RuntimeException('Scheduling conflict detected with your existing availability/shifts.');
                    }

                    $payload = [
                        'staff_id' => (int) $staff->staff_id,
                        'schedule_date' => $e['schedule_date'],
                        'start_time' => $e['start_time'],
                        'end_time' => $e['end_time'],
                        'created_by' => 'staff',
                        'status' => 'active',
                    ];

                    if ($hasApprovalStatus) {
                        $payload['approval_status'] = $mode === 'draft' ? 'draft' : 'pending';
                    }

                    DB::table('schedule')->insert($payload);
                }
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage() ?: 'Unable to save availability.');
        }

        $msg = $mode === 'draft'
            ? 'Draft saved. You can keep adding hours until you reach the minimum, then submit for approval.'
            : 'Availability saved. Admin will review and publish.';

        // Email notification to student staff upon successful submission
        // (draft should not send email)
        if ($mode !== 'draft' && !empty($staff->email)) {
            try {
                Mail::to($staff->email)->send(new StaffAvailabilitySubmittedMail(
                    staffName: (string) ($staff->name ?? 'Student Staff'),
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    requiredHours: $minHours,
                    entries: $entries->all(),
                ));
            } catch (\Throwable $e) {
                // don't block UX if mail fails
            }
        }

        return redirect()->route('staff.availability', ['week_start' => $weekStart->toDateString()])
            ->with('success', $msg);
    }
}
