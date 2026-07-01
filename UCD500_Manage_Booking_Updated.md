# UCD500 — Manage Booking (Updated)

**System:** UniSpa Booking Management System (UBMS)  
**Version:** 2.0 (updated to match TEST_UBMS_500 series)  
**Related Test Suite:** TEST_UBMS_500 — Manage Booking

---

## Use Case Overview

| Field | Details |
|---|---|
| Use Case ID | UCD500 |
| Use Case Name | Manage Booking |
| Actor(s) | Customer, Admin |
| Trigger | Customer initiates a booking or Admin manages existing bookings |
| Pre-condition | Customer is logged in; at least one service and staff shift exists |
| Post-condition | Booking is created, paid, confirmed, or cancelled |
| Related UC | UCD400 Manage Scheduling (staff/room assignment), UCD300 Manage Services (promotions) |

---

## Main Flow — Customer Books a Service

1. Customer logs in and browses services from the **Services** page.
2. Customer clicks **"Reserve Now"** on a service.
3. Customer selects the number of participants (1–3) and enters guest details if applicable.
4. Customer selects an available date and time slot from the calendar.
   - Available slots are computed in real time from approved staff schedules and available treatment rooms.
   - Slots where all scheduled staff are busy are displayed as **"Fully Booked"** (greyed out, not selectable).
   - If booking for multiple participants, only slots with sufficient staff capacity (`capacity ≥ pax`) are selectable.
5. Customer reviews the booking summary (service, participants, date, time, total).
6. Customer optionally enters a **Promo Code** in the cart to apply a discount.
7. Customer proceeds to the Payment page and uploads a QR payment receipt.
8. System saves the booking with `status = 'pending'` and `payment_status = 'pending'`.
9. Admin reviews the uploaded QR proof and confirms — booking becomes `status = 'confirmed'`, `payment_status = 'paid'`.
10. Customer receives a confirmation email.

---

## Exception Conditions

| ID | Condition | System Response |
|---|---|---|
| E1 | Customer selects a slot where `booked_count = max_capacity` (fully booked) | Slot is shown as greyed "Fully Booked"; customer cannot proceed |
| E2 | Customer selects a slot but `capacity < pax` (not enough staff for group size) | Slot is shown as "Fully Booked"; Next button remains disabled |
| E3 | Customer applies an invalid or expired promo code | Red message: "Invalid or expired promotion code." |
| E4 | Customer cancels a booking more than 24 hours before appointment | Green toast: "Booking cancelled successfully. Refund initiated." |
| E5 | Customer attempts to cancel within 24 hours of appointment | Red error: "Cancellation not allowed within 24 hours of appointment." |
| E6 | Admin approves a QR booking without an uploaded QR proof | Red error: "QR proof is required before approval." |
| E7 | Admin attempts to assign a staff member already busy in that time slot | Red error: "Selected staff is already assigned in this time range." |
| E8 | Booking has a TMP slot ID (not yet materialized) | System parses TMP format and displays service name, date, and time correctly; no error |

---

## Test Cases Traceability

| Test Case | Description | Exception Covered |
|---|---|---|
| TEST_UBMS_501 | Customer adds service to cart and proceeds to checkout | Main flow |
| TEST_UBMS_502 | Customer applies a valid promo code | E3 (valid path) |
| TEST_UBMS_503 | Customer attempts to book a fully booked slot | E1, E2 |
| TEST_UBMS_504 | Customer cancels booking more than 24 hours before appointment | E4 |
| TEST_UBMS_505 | Customer attempts to cancel within 24 hours | E5 |
| TEST_UBMS_506 | Admin approves a pending QR booking | E6 (valid path) |
| TEST_UBMS_507 | Admin views KPI dashboard and uses filters | Main flow (admin) |
| TEST_UBMS_508 | Admin navigates to staff assignment from booking list | Links to UCD400 |
| TEST_UBMS_509 | Booking with TMP slot ID is visible and parsed correctly | E8 |
| TEST_UBMS_510 | Admin deletes a booking with cascade on participants | Main flow (admin) |

---

## TEST_UBMS_501 — Customer Add Service to Cart and Proceed to Checkout

**Goal:** Verify that a customer can select a service, add participants, choose a time slot, and proceed to the payment page.

### Initialization
Customer logged in (`hasyadini15@gmail.com / Customer@123`), on the Customer Dashboard.

### Test Inputs
Click "Reserve Now" on a service; select participants; enter guest details; select date and time slot.

### Expected Result
Service added to cart. Cart shows correct subtotal. After selecting slot, system calculates 30% deposit. Customer is redirected to the Payment page.

### Test Procedure
1. Customer logs in and navigates to **Services** in the top menu.
2. Click **"Reserve Now"** (green) on a service (e.g., "Swedish Massage").
3. In the booking panel:
   - **Step 1 (Service):** Service is pre-selected. Click **Next**.
   - **Step 2 (Guest Selection):** Choose "For myself" or add guests (up to 2). Click **Next**.
   - **Step 3 (Time Slot):** Select a date on the calendar, click an available time slot (e.g., `10:00 AM – 11:00 AM`). Click **Next**.
   - **Step 4 (Confirmation):** Review summary (service, participants, date, time, total price).
4. Click **"Proceed to Payment"**.
5. Verify redirect to the Payment page.

### Constraints
The selected slot must have `status = 'available'` and `capacity ≥ pax`.

---

## TEST_UBMS_502 — Customer Apply Valid Promotion Code

**Goal:** Verify that a valid promo code applies a discount to the booking total.

### Initialization
Customer has items in cart. An active promotion with `promo_code = 'STUDENT10'` exists and is not expired.

### Test Inputs
Enter promo code `STUDENT10` in the cart promo field.

### Expected Result
Green message: **"Promotion applied successfully!"** Total and deposit amounts update to reflect the discount.

### Test Procedure
1. After adding a service to cart, go to the **Cart** page.
2. Locate the **"Promo Code"** field (right side, below the subtotal).
3. Enter `STUDENT10` and click **"Apply"**.
4. Observe the green message: **"Promotion applied successfully!"**
5. Verify the total amount decreases and the deposit (30% of discounted total) updates.

### Constraints
- Promo code must be set by Admin in Manage Services → Promotions (Promo Code field).
- The promotion must be `is_active = true` and `end_date >= today` (or no end date).

---

## TEST_UBMS_503 — Customer Tries to Book a Fully Booked Slot

**Goal:** Verify that a customer cannot select a fully booked time slot.

### Initialization
Customer is at the time slot selection step. A slot exists where all scheduled staff are already assigned to other bookings (`capacity = 0`), or `capacity < pax`.

### Test Inputs
Attempt to click on a greyed-out "Fully Booked" slot.

### Expected Result
The slot is displayed with a grey background and **"Fully Booked"** label. It is not selectable. The **Next** button remains disabled.

### Test Procedure
1. Repeat steps 1–3 from TEST_UBMS_501 until reaching time slot selection.
2. Observe that a fully booked slot is shown as greyed with "Fully Booked" label.
3. Attempt to click it — system does not allow selection; **Next** remains disabled.
4. Verify you cannot proceed with that slot.

### Constraints
The system computes capacity in real time from `staff schedules − busy slots`. If booking for N people, `capacity ≥ N` is required.

---

## TEST_UBMS_504 — Customer Cancels Booking More Than 24 Hours Before Appointment

**Goal:** Verify that a customer can cancel a booking when the appointment is more than 24 hours away.

### Initialization
Customer has a confirmed booking whose appointment date is more than 24 hours in the future.

### Test Inputs
Click **"Cancel booking"** on the reservation card, then confirm in the modal.

### Expected Result
Green toast: **"Booking cancelled successfully. Refund initiated."** Booking status changes to `cancelled`.

### Test Procedure
1. Customer navigates to **"My Reservations"**.
2. Find a confirmed booking with appointment > 24 hours away.
3. Click **"Cancel booking"** (red button).
4. In the confirmation modal, click **"Yes, Cancel"** (red).
5. Observe toast: **"Booking cancelled successfully. Refund initiated."**
6. Verify booking status changes to `cancelled`.

### Constraints
System time must be accurate. Only bookings with `can_manage = true` show an enabled cancel button.

---

## TEST_UBMS_505 — Customer Cancels Booking Within 24 Hours (Expected Failure)

**Goal:** Verify that a customer cannot cancel a booking within 24 hours of the appointment.

### Initialization
Customer has a confirmed booking scheduled for tomorrow at 10:00 AM. Current time is 11:00 AM today (< 24 hours).

### Test Inputs
Click the **"Cancel booking"** button.

### Expected Result
Red error: **"Cancellation not allowed within 24 hours of appointment."** Booking remains confirmed.

### Test Procedure
1. Customer navigates to **"My Reservations"**.
2. Find a booking with appointment time less than 24 hours away.
3. The cancel button is disabled (greyed). If somehow accessible, system returns the error.
4. Verify booking remains `confirmed`.

---

## TEST_UBMS_506 — Admin Approves a Pending Booking

**Goal:** Verify Admin can approve a pending QR-payment booking and the status changes appropriately.

### Initialization
Admin logged in. A booking exists with `payment_method = 'qr'`, `status = 'pending'`, and QR proof uploaded.

### Test Inputs
Click **"Approve"** on the pending booking row.

### Expected Result
- Booking `status` → `accepted` (or `confirmed`)
- Booking `payment_status` → `paid`
- Customer receives confirmation email
- Green toast: **"Booking confirmed."**

### Exception Condition
If QR proof is missing, system returns:
> **"QR proof is required before approval."**

### Test Procedure
1. Admin logs in and goes to **Manage Bookings** from the sidebar.
2. Find a pending booking with QR receipt uploaded (shown as "View QR Proof" link).
3. Click **"Approve"** (green button) on that row.
4. Observe green toast: **"Booking confirmed."**
5. Verify booking status becomes `accepted`/`confirmed` and payment status becomes `paid`.
6. Verify the customer receives a confirmation email.

---

## TEST_UBMS_507 — Admin Views Booking KPIs and Filters

**Goal:** Verify that Admin can view KPI metrics and filter bookings by multiple criteria.

### Initialization
Admin is logged in and on the **Manage Bookings** page.

### Test Inputs
- View KPI cards
- Apply filters: search, status, payment, service
- Select a date on the booking calendar

### Expected Result
**KPI Cards:** Total Bookings, Pending, Approved, Pending Payments, Completed counts displayed.  
**Filters:** Search by booking ID / customer name / email / service name. Filter by status, payment status, service.  
**Calendar:** Monthly booking counts and pending payment counts displayed per day. Selected date controls the booking table.

### Test Procedure
1. Admin logs in and goes to **Manage Bookings**.
2. Verify KPI cards are displayed at the top.
3. Enter a search keyword — verify results update.
4. Select a status filter — verify results update.
5. Select a payment status filter — verify results update.
6. Select a service filter — verify results update.
7. Click a date on the calendar — verify bookings for that date are shown in the daily table.

### Constraints
Filters preserve state in the query string (`?search=...&status=...`).

---

## TEST_UBMS_508 — Admin Navigates to Staff Assignment from Booking

**Goal:** Verify Admin can navigate from a booking to the staff assignment page in Manage Scheduling.

### Initialization
Admin is logged in and on the **Manage Bookings** page.

### Test Inputs
Click the **"Staff"** button on a booking row.

### Expected Result
Admin is redirected to `/admin/scheduling?booking_id={bookingId}`. Staff and room assignment can be completed in the Manage Scheduling module.

### Test Procedure
1. Admin goes to **Manage Bookings**.
2. Find a booking that needs staff assignment.
3. Click the **"Staff"** button (indigo) on that row.
4. Verify the page redirects to `/admin/scheduling?booking_id={bookingId}`.

---

## TEST_UBMS_509 — View Booking with TMP Slot ID Fallback

**Goal:** Verify that bookings with temporary slot IDs remain visible and correctly parsed in the admin view.

### Initialization
A booking exists with `slot_id = 'TMP:4:2026-06-23:10:00'` where `4` = service_id, `2026-06-23` = date, `10:00` = start time.

### Test Inputs
Admin views the **Manage Bookings** page.

### Expected Result
- Booking appears in the main booking listing
- Service name is correctly resolved from `service_id = 4`
- Date and time displayed as `2026-06-23 10:00`
- Booking appears in the calendar date table for `2026-06-23`
- No errors occur

### Test Procedure
1. Admin goes to **Manage Bookings**.
2. Verify the booking with TMP slot ID appears.
3. Verify service name, date `2026-06-23`, and time `10:00` are shown correctly.
4. Select date `2026-06-23` on the calendar — verify the booking appears.
5. Verify no error messages are displayed.

### Constraints
TMP slot ID format must be exactly `TMP:{service_id}:{YYYY-MM-DD}:{HH:MM}`. Time must be full `HH:MM` (e.g., `10:00`, not `10`).

---

## TEST_UBMS_510 — Admin Deletes Booking with Cascade

**Goal:** Verify Admin can delete a booking and all linked `booking_participant` rows are also deleted.

### Initialization
Admin is logged in. A booking exists with associated `booking_participant` records.

### Test Inputs
Click the **"Delete"** button on a booking row and confirm.

### Expected Result
- Confirmation modal appears
- After confirmation, booking is deleted
- All associated `booking_participant` rows are deleted
- Green success message appears
- Booking is removed from the list

### Exception Condition
If deletion fails due to other linked records, system displays:
> **"Unable to delete booking with linked records."**

### Test Procedure
1. Admin goes to **Manage Bookings**.
2. Find a booking with participants.
3. Click the **"Delete"** button (red) on that row.
4. In the confirmation modal, confirm the deletion.
5. Observe the success message.
6. Verify the booking is removed from the list.
7. Verify the `booking_participant` records for that booking are also deleted.
