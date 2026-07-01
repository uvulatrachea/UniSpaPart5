# 3.2.4 UCD400 - Manage Scheduling

| Attribute | Value |
|---|---|
| **Use Case Name** | Manage Scheduling |
| **Created by** | Hasya Dini |
| **Last Updated** | 2026-06-28 (based on actual system implementation) |

---

## 1. Brief Description

This use case enables **Admin** to manage work schedules for UniSpa staff, and **Student Staff** to submit their own availability for services they are certified to perform. For **General Staff**, the Admin sets fixed weekly shifts (date, time, treatment room). For **Student Staff**, they self-submit weekly availability which Admin can approve or reject. Once schedules are approved and published, the system derives bookable time slots for customers. Admin can also process pending QR-payment bookings by assigning staff and treatment rooms.

---

## 2. Actors

| Actor | Description |
|---|---|
| **Admin** | Logged-in staff with `admin.only` middleware. Allowed to create shifts, approve student availability, publish schedules, assign staff/rooms to bookings. |
| **Student Staff** | Staff with `staff_type = 'student'`. Can log in via `staff.only` middleware and set weekly availability (draft or submit for approval). |
| **System** | Laravel backend (controllers, database, email queue). |

---

## 3. Related Use Cases & Stakeholders

| Related Use Case | Relationship |
|---|---|
| **UCD500 - Manage Booking** | Pending bookings appear in scheduling dashboard for staff/room assignment |
| **UCD200 - Manage User Account** | Staff accounts must exist; admin manages staff via `Admin/ManageUsers` |
| **UCD300 - Manage Services** | Service categories define room compatibility; staff certifications link to services |

**Stakeholders:** Admin, Staff (General and Student), Customer

---

## 4. Preconditions

1. Admin must be logged in (authenticated via `StaffAuthController`, guarded by `admin.only` middleware).
2. Staff accounts must exist in `staff` table with correct `staff_type` (`general` / `student`).
3. Treatment rooms must exist in `treatment_room` table.
4. Service categories must be defined (for room compatibility checks).
5. Database must be accessible (PostgreSQL / MySQL).

---

## 5. Postconditions

1. **Schedules** are created, updated, or published in the `schedule` table.
2. **Room assignments** are recorded (via `slot.room_id` when booking is confirmed or QR booking assigned).
3. **Slot availability** is derived from approved schedules — customers can only book when approved staff are scheduled.
4. **Student Staff availability** is saved with `approval_status` of `draft`, `pending`, `approved`, or `rejected`.
5. **Notifications** are sent to Student Staff upon availability review (approval/rejection via email).
6. **Conflicts** (staff double-booking, room overlap, outside office hours) are detected and prevented.

---

## 6. Controllers & Methods (Actual Implementation)

### 6.1 `Auth\Admin\AdminDashboardController`

| Method | Route | Purpose |
|---|---|---|
| `scheduling(Request)` | `GET /admin/scheduling` | Renders scheduling dashboard with weekly calendar, staff directory, room list, schedules, assigned bookings, pending QR bookings, pending student availability |
| `storeShift(Request)` | `POST /admin/scheduling/shift` | Create a new general staff shift (date, start/end time). Checks for staff conflict. |
| `publishSchedule(Request)` | `POST /admin/scheduling/publish` | Publishes schedule for a given week (`schedule.status = 'active'`). |
| `approveStudentAvailability(Request)` | `POST /admin/scheduling/student-availability/approve` | Approve or reject pending student staff availability entries. Sends `StaffAvailabilityReviewedMail`. |
| `confirmQrAndAssign(Request, bookingId)` | `POST /admin/scheduling/qr-confirm/{bookingId}` | Confirms QR-payment booking, creates real slot, assigns staff & room. |
| `approveBooking(bookingId)` | `POST /admin/bookings/{bookingId}/approve` | Approves a booking (used in bookings flow). |
| `updateBookingStatus(Request, bookingId)` | `PATCH /admin/bookings/{bookingId}/status` | Updates booking status. |
| `updateBookingPayment(Request, bookingId)` | `PATCH /admin/bookings/{bookingId}/payment` | Updates booking payment status. |
| `bookings()` | `GET /admin/bookings` | Renders bookings management page. |
| `payments()` | `GET /admin/payments` | Renders payments management page. |

### 6.2 `Staff\StaffAvailabilityController`

| Method | Route | Purpose |
|---|---|---|
| `index(Request)` | `GET /staff/availability` | Shows weekly availability form for Student Staff. Pre-fills draft entries. Only accessible by `staff.only` + `staff_type = 'student'`. |
| `store(Request)` | `POST /staff/availability` | Saves availability entries (mode: `draft` or `submit`). Validates min hours, office hours, conflict detection. Sends `StaffAvailabilitySubmittedMail` on submit. |

### 6.3 `Booking\ScheduleController`

| Method | Route | Purpose |
|---|---|---|
| `show()` | `GET /booking/schedule` | Shows schedule/calendar page for customers to pick time slots. |
| `slots(Request)` | `POST /booking/slots` | Returns available slots for a given service + date, computed from approved staff schedules + room availability. |
| `monthAvailability(Request)` | `GET /booking/slots/month` | Returns which dates in a month have available slots (for calendar highlight). |
| `confirm(Request)` | `POST /booking/schedule/confirm` | Saves selected slot IDs into cart items, redirects to guest/payment page. |

### 6.4 `Staff\StaffDashboardController`

| Method | Route | Purpose |
|---|---|---|
| `index(Request)` | `GET /staff/dashboard` | Staff dashboard overview. |

---

## 7. Models (Database Tables)

| Model / Table | Key Columns | Role in Scheduling |
|---|---|---|
| `schedule` | `schedule_id`, `staff_id`, `schedule_date`, `start_time`, `end_time`, `status` (`active`), `created_by` (`admin`/`staff`), `approval_status` (`draft`/`pending`/`approved`/`rejected`), `approval_notes` | Core schedule data for both General Staff shifts and Student Staff availability |
| `staff` | `staff_id`, `name`, `staff_type` (`general`/`student`), `work_status` (`active`/`inactive`), `role` | Staff directory (filtered by type) |
| `slot` | `slot_id`, `service_id`, `staff_id`, `room_id`, `slot_date`, `start_time`, `end_time`, `status` (`available`/`held`/`booked`) | Derived bookable slots from schedules |
| `treatment_room` | `room_id`, `room_name`, `room_type`, `category_id`, `gender`, `status`, `is_active` | Treatment rooms for shift/booking assignment |
| `booking` | `booking_id`, `customer_id`, `slot_id`, `status`, `payment_method`, `payment_status` | Linked to slots for staff/room assignment |
| `student_staff` | `staff_id`, `working_hours` | Student staff profile (working hours limit) |
| `general_staff` | `staff_id` | General staff profile |
| `service_category` | `id`, `name`, `gender` | Room compatibility (category_id on treatment_room) |
| `service` | `id`, `name`, `category_id`, `duration_minutes` | Service definition for slot generation |

---

## 8. Flow of Activities

### Flow A1: Admin Manages General Staff Shifts

```
  Actor (Admin)                                    System
  ────────────                                    ──────
  1. Select "Scheduling" from                      1.1 Renders scheduling dashboard
     admin dashboard                                    (Admin/ManageScheduling.jsx):
                                                        • Weekly calendar
                                                        • Staff directory (general/student)
                                                        • Room list
                                                        • Existing schedules & bookings
                                                        • Pending student availability
                                                        • Pending QR bookings

  2. Choose to manage
     General Staff Shifts                          2.1 Shows weekly calendar with shift creation form

  3. Select staff, date, start_time,               3.1 Validates:
     end_time, treatment room                           • Staff conflict (already scheduled) [E1]
                                                        • Room conflict [E2]
                                                        • Business hours (10:00–18:00) [E3]
                                                    3.2 Inserts into `schedule` table
                                                        with `created_by = 'admin'`
  4. (Optional) Publish the weekly schedule         4.1 Sets `schedule.status = 'active'`
                                                        for all rows in the selected week
```

### Flow A2: Student Staff Submits Availability

```
  Actor (Student Staff)                            System
  ────────────────────                            ──────
  1. Log in & navigate to
     "My Availability"                             1.1 Renders Staff/StaffAvailability.jsx
                                                       • Weekly calendar form
                                                       • Pre-fills draft entries (if any)
                                                       • Shows office hours (10:00–19:00)
                                                       • Minimum hours: 12hrs/week

  2. Add availability entries                      2.1 For each entry validates:
     (date, start, end)                                • Date within Mon-Sun of selected week
                                                       • Date within current month
                                                       • Start < End
                                                       • Within office hours 10:00–19:00
                                                       • No overlap with existing schedules
  3. Choose mode:                                   3.1 If mode = `draft`:
     [Save Draft] or [Submit]                           • Saves with `approval_status = 'draft'`
                                                       • Staff can keep editing
                                                    3.2 If mode = `submit`:
                                                       • Validates: ≥ 2 different days
                                                       • Validates: ≥ 12 hours total
                                                       • Saves with `approval_status = 'pending'`
                                                       • Sends StaffAvailabilitySubmittedMail
```

### Flow A3: Admin Reviews Student Staff Availability

```
  Actor (Admin)                                    System
  ────────────                                    ──────
  1. Sees pending student availability
     in scheduling dashboard                       1.1 Shows list of pending entries grouped
                                                       by student staff name

  2. Selects entries & clicks                      2.1 Updates `approval_status`:
     [Approve] or [Reject]                             • `approved` or `rejected`
                                                        • If rejected: stores `approval_notes`
                                                    2.2 Sends StaffAvailabilityReviewedMail
                                                        to each affected student staff
```

### Flow A4: Admin Processes Pending QR Booking & Assigns

```
  Actor (Admin)                                    System
  ────────────                                    ──────
  1. Views pending QR booking in                   1.1 Shows booking with:
     scheduling dashboard                              • Customer name, service, time
                                                       • QR proof image
                                                       • Staff & room assignment fields

  2. Selects staff & room                          2.1 Validates:
     • Confirms booking                                • Staff certification matches service [E5]
                                                       • Room category matches service [E6]
                                                    2.2 Creates real slot from TMP slot
                                                    2.3 Updates booking status to `confirmed`
                                                    2.4 Updates slot availability
```

---

## 9. Exception Conditions

| ID | Condition | Message | Triggered By |
|---|---|---|---|
| [E1] | Staff conflict | "Staff scheduling conflict detected for this time range." | `AdminDashboardController::storeShift()` |
| [E2] | Room conflict | (Detected during slot generation — no explicit room assigned until booking) | `ScheduleController::buildAvailableSlotsForDate()` |
| [E3] | Time outside business hours | "Availability must be within office hours (10:00 - 19:00)." | `StaffAvailabilityController::store()` |
| [E4] | Time conflict (overlap) | "Scheduling conflict detected with your existing availability/shifts." | `StaffAvailabilityController::store()` |
| [E5] | Staff not certified | (Not explicitly validated in current code — future enhancement) | — |
| [E6] | Room incompatible | Room filtered by `category_id` matching `service.category_id` | `ScheduleController::slots()` |
| [E7] | Min hours not met | "Total availability must be at least 12 hours per week." | `StaffAvailabilityController::store()` |
| [E8] | Min days not met | "Please choose at least 2 different days in the week." | `StaffAvailabilityController::store()` |
| [E9] | Max hours exceeded | "Total availability cannot exceed 40 hours for the week." | `StaffAvailabilityController::store()` |
| [E10] | Current month only | "Student staff availability can only be submitted for the current month." | `StaffAvailabilityController::store()` |

---

## 10. Business Rules

| Rule | Details |
|---|---|
| **BR1** | Only Admin can create General Staff shifts and publish schedules. |
| **BR2** | General Staff have fixed shifts created by Admin; Student Staff set their own availability. |
| **BR3** | Student Staff must submit at least **12 hours** across at least **2 different days** per week when submitting (not when saving draft). |
| **BR4** | Student Staff availability is capped at **40 hours/week**. |
| **BR5** | Student Staff can only submit for the **current month**. |
| **BR6** | All scheduling respects office hours: **10:00–19:00** for staff availability, **10:00–18:00** for customer booking slots. |
| **BR7** | Published schedules (`status = 'active'` + `approval_status = 'approved'`) become available for customer booking slot generation. |
| **BR8** | Treatment rooms are checked by `category_id` matching `service.category_id` for compatibility. |
| **BR9** | Student Staff can save **draft** availability (no validation of min hours/days) and later submit. |
| **BR10** | Student Staff can modify their own availability (draft or pending); Admin can only view/approve/reject it. |
| **BR11** | Once a schedule is published, it's used dynamically — no explicit "publish" toggle on individual entries. |
| **BR12** | Schedule changes must not affect confirmed bookings (conflict detection prevents overlap with booked slots). |
| **BR13** | Email notifications are sent for: availability submission (Student Staff) and availability review (Admin approval/rejection). |

---

## 11. Special Requirements

| Requirement | Implementation |
|---|---|
| Calendar view with staff & room assignment | `Admin/ManageScheduling.jsx` — weekly calendar with drag-and-drop (inertia rendering) |
| Real-time conflict detection | Server-side validation on `storeShift()` and `StaffAvailabilityController::store()` |
| Visual indicators for staff availability | `approval_status` field: draft (yellow), pending (orange), approved (green), rejected (red) |
| Room compatibility by service category | `treatment_room.category_id` filtered against `service.category_id` in slot generation |
| Student Staff self-service availability | `Staff/StaffAvailability.jsx` with draft/submit modes |
| Export / print functionality | `AdminDashboardController::scheduling()` provides full data for rendering; no CSV export of schedules in current code |
| Email notifications | `StaffAvailabilitySubmittedMail` + `StaffAvailabilityReviewedMail` (Mailables) |
| QR booking assignment workflow | `confirmQrAndAssign()` creates real slot + assigns staff/room in one action |

---

## 12. View Layer (Actual JSX Pages)

| Page | Route | Purpose |
|---|---|---|
| `Admin/ManageScheduling.jsx` | `GET /admin/scheduling` | Admin scheduling dashboard with calendar, staff, rooms, pending items |
| `Admin/Partials/AdminShell.jsx` | — | Layout wrapper for admin pages |
| `Staff/StaffAvailability.jsx` | `GET /staff/availability` | Student Staff availability form (weekly, draft/submit) |
| `Staff/StaffDashboard.jsx` | `GET /staff/dashboard` | Staff dashboard overview |
| `Staff/Partials/StaffShell.jsx` | — | Layout wrapper for staff pages |
| `Booking/Schedule.jsx` | `GET /booking/schedule` | Customer-facing schedule with available slots |
| `Appointment/AppointmentI.jsx` | `GET /appointment/appointment-i` | Single-page appointment booking flow |
| `Admin/ManageBookings.jsx` | `GET /admin/bookings` | Admin bookings management (staff/room assignment) |

---

## 13. Email Templates

| Template | Trigger |
|---|---|
| `emails/staff-availability-submitted.blade.php` | Sent to Student Staff when they submit availability (mode = `submit`) |
| `emails/staff-availability-reviewed.blade.php` | Sent to Student Staff when Admin approves/rejects their availability |

---

## 14. Assumptions

1. General Staff work fixed shifts; Student Staff work based on self-set availability.
2. All staff have defined certifications (future: validation against `qualification` table).
3. Treatment rooms are categorized by `service_category.category_id`.
4. Business hours are **10:00–18:00** for customer-facing booking, **10:00–19:00** for staff availability submissions.
5. Student Staff must set availability BEFORE Admin can assign them to bookings.

---

## 15. Notes and Issues

| # | Note |
|---|---|
| 1 | **WhatsApp notifications** from original UCD design not implemented — only email notifications exist. |
| 2 | **Staff certification validation** (E5) is not explicitly implemented — the system currently filters treatment rooms by category but does not validate staff qualifications against service requirements. |
| 3 | **Duplicate controllers exist**: `PaymentController` (root) vs `Booking\PaymentController` — overlapping payment logic. |
| 4 | **Schedule model** (`app/Models/Schedule.php`) is empty — no Eloquent relationships defined; all schedule queries use `DB::table()` directly. |
| 5 | **TMP slot system**: Bookable slots are synthetic (`TMP:serviceId:date:startTime`) until payment is initiated, at which point real slots are created in the `slot` table. |
| 6 | **Manual conflict resolution** may be required if automated checks fail — the system rejects conflicting entries rather than suggesting alternatives. |
| 7 | **No drag-and-drop** has been implemented in the current UI — it's a standard form-based calendar. |

---

## 16. Package Components Mapping (UCD400)

### Controller Layer
```
Auth/Admin/AdminDashboardController.php     (scheduling, storeShift, publishSchedule, approveStudentAvailability, confirmQrAndAssign, bookings, approveBooking, updateBookingStatus, payments)
Staff/StaffAvailabilityController.php       (index, store)
Staff/StaffDashboardController.php          (index)
Booking/ScheduleController.php              (show, slots, monthAvailability, confirm)
```

### View Layer
```
Admin/ManageScheduling.jsx                  (scheduling dashboard)
Admin/ManageBookings.jsx                    (bookings management)
Admin/Partials/AdminShell.jsx               (admin layout)
Staff/StaffAvailability.jsx                 (student staff availability form)
Staff/StaffDashboard.jsx                     (staff dashboard)
Staff/Partials/StaffShell.jsx               (staff layout)
Booking/Schedule.jsx                        (customer-facing schedule calendar)
Appointment/AppointmentI.jsx                (single-page booking)
```

### Model Layer
```
Schedule.php                                (schedule table - empty model, DB::raw queries)
Staff.php                                   (staff accounts with hasMany schedules, slots)
Slot.php                                    (bookable time slots)
TreatmentRoom.php                           (treatment rooms)
Service.php                                 (service definitions)
ServiceCategory.php                         (service categories)
Booking.php                                 (booking records)
Customer.php                                (customer accounts)
GeneralStaff.php                            (general staff profile)
StudentStaff.php                            (student staff profile)