# Test Cases — UCD400 Manage Scheduling (TEST_UBMS_400)

**Type:** Demonstration  
**Level:** CSC  
**Goal:** Verify that Admin can create and manage staff shifts (with conflict detection and room assignment), Part-Time staff can submit weekly availability for admin review, and that scheduling feeds correctly into the booking assignment flow.

---

## 4.4.1 TEST_UBMS_401 — Admin Creates a Valid Shift

**Goal:** Verify that Admin can add a new shift for Full-Time staff within business hours.

### Initialization
Admin is logged in and on the **Manage Scheduling** page.

### Test Inputs
- Staff: a Full-Time Staff member (e.g., "Ahmad Fariz")
- Date: any future date that has no existing conflict
- Start Time: `10:00`
- End Time: `18:00`
- Treatment Room: any available room (optional)

### Expected Result
System saves the shift and displays a green toast:
> **"Shift added successfully."**

The shift appears in the calendar view for the selected date.

### Exception Condition (E1 — Business Hours)
If `start_time < 10:00` or `end_time > 18:00`, system returns:
> **"Time outside business hours. Shifts must be within 10:00 – 18:00."**

### Test Procedure
1. Admin logs in and navigates to **Manage Scheduling** from the admin sidebar.
2. Click **"+ Add Shift"** (or the "New Shift" button).
3. In the Add Shift modal:
   - Select a Full-Time Staff member from the dropdown.
   - Enter a valid future date.
   - Set **Start Time** to `10:00` and **End Time** to `18:00`.
   - Optionally select a Treatment Room.
4. Click **"Save Shift"**.
5. Observe the green toast: **"Shift added successfully."**
6. Verify the shift appears in the scheduling calendar on the selected date.

### Assumption and Constraints
- Business hours are strictly `10:00 – 18:00`.
- The selected staff member must exist and have `work_status = active`.
- Room assignment is optional; if omitted, the shift is saved without a room.

---

## 4.4.2 TEST_UBMS_402 — Admin Attempts to Create a Conflicting Shift

**Goal:** Verify that the system prevents double-scheduling a staff member in the same time window.

### Initialization
Admin is logged in. A shift already exists for staff member "Ahmad Fariz" on 2026-07-15 from `10:00` to `14:00`.

### Test Inputs
- Staff: "Ahmad Fariz" (same staff)
- Date: `2026-07-15` (same date)
- Start Time: `12:00`
- End Time: `16:00` (overlaps with existing shift)

### Expected Result
System rejects the shift and displays a red error:
> **"Staff already scheduled during this time period."**

No new shift row is inserted.

### Exception Condition (E2 — Room Conflict)
If the selected room is already occupied during the requested window:
> **"Selected room is already occupied during this time period."**

### Test Procedure
1. Admin navigates to **Manage Scheduling**.
2. Click **"+ Add Shift"**.
3. Select the same staff member ("Ahmad Fariz"), same date (`2026-07-15`), and a time window that overlaps the existing `10:00–14:00` shift (e.g., `12:00–16:00`).
4. Click **"Save Shift"**.
5. Observe the red error: **"Staff already scheduled during this time period."**
6. Verify no new row appears in the schedule for that staff on that date.

### Assumption and Constraints
- Conflict is detected using `start_time < end_time_of_existing AND end_time > start_time_of_existing` (overlap logic).
- Only `status = 'active'` schedules are checked.

---

## 4.4.3 TEST_UBMS_403 — Admin Assigns Staff and Room to a Pending QR Booking

**Goal:** Verify that Admin can confirm a QR-payment booking by assigning a staff member and room, and that the booking status changes to "confirmed".

### Initialization
Admin is logged in. A booking exists with:
- `payment_method = 'qr'`
- `status = 'pending'`
- QR payment proof uploaded (`depo_qr_pic` is not null)

### Test Inputs
- Booking ID: (the pending QR booking)
- Staff: any available Full-Time or Part-Time staff
- Room: any available treatment room (optional)

### Expected Result
- Booking `status` changes to **`confirmed`**
- Booking `payment_status` changes to **`paid`**
- Customer receives a confirmation email
- Admin sees a green toast:
  > **"Booking confirmed. Customer has been notified."**

### Exception Condition (E3 — Missing QR Proof)
If `depo_qr_pic` is null, system returns:
> **"Payment proof image is missing for this booking."**

### Exception Condition (E4 — Staff Already Assigned)
If the selected staff is already assigned to another booking at the same time:
> **"Selected staff is already assigned in this time range."**

### Test Procedure
1. Admin navigates to **Manage Scheduling** from the sidebar.
2. In the **"Pending QR Bookings"** section, find a booking with QR proof uploaded.
3. Select an available staff member from the **Staff** dropdown.
4. Optionally select a **Treatment Room**.
5. Click **"Confirm & Assign"**.
6. Observe the green toast: **"Booking confirmed. Customer has been notified."**
7. Verify the booking status in **Manage Bookings** is now **"confirmed"** and payment status is **"paid"**.
8. Verify the customer receives an email notification.

### Assumption and Constraints
- The QR payment proof image must already be uploaded by the customer before admin can confirm.
- Room compatibility with service category is enforced if categories are configured.

---

## 4.4.4 TEST_UBMS_404 — Part-Time Staff Submits Weekly Availability

**Goal:** Verify that Part-Time (student) staff can submit their weekly availability for admin review and publication.

### Initialization
Part-Time staff member is logged in and on the **Staff Availability** page.

### Test Inputs
- Week: current week (Monday–Sunday)
- Entries: at least 2 different days within business hours (`10:00 – 18:00`)
- Total hours: at least 12 hours across the week

### Expected Result
System saves the availability entries with `approval_status = 'pending'` and displays a green toast:
> **"Availability saved. Admin will review and publish."**

Staff member receives a confirmation email.

### Exception Condition (E5 — Outside Business Hours)
If any entry is outside `10:00 – 18:00`:
> **"Availability must be within office hours (10:00 - 18:00)."**

### Exception Condition (E6 — Insufficient Hours)
If total submitted hours < 12:
> **"Total availability must be at least 12 hours per week."**

### Exception Condition (E7 — Insufficient Days)
If entries span fewer than 2 different days:
> **"Please choose at least 2 different days in the week."**

### Exception Condition (E8 — Only Current Month)
If the selected week is outside the current month:
> **"Student staff availability can only be submitted for the current month."**

### Test Procedure
1. Part-Time staff logs in and navigates to **Availability** from the staff sidebar.
2. Select the current week using the week picker.
3. Add availability entries spanning at least 2 days (e.g., Mon `10:00–16:00`, Wed `10:00–16:00`, Fri `10:00–16:00`).
4. Verify total hours displayed is ≥ 12.
5. Click **"Submit for Approval"**.
6. Observe the green toast: **"Availability saved. Admin will review and publish."**
7. Verify entries appear in the admin's **"Part-Time Staff Availability Requests (Pending Approval)"** table.
8. Admin approves the availability — it becomes `approved` and appears on the scheduling calendar.

### Assumption and Constraints
- Only `staff_type = 'student'` (Part-Time) staff can access and submit the availability form.
- Saving as draft does not trigger email notification; only submitting for approval does.
- Admin reviews from **Manage Scheduling → Part-Time Staff Availability Requests** section.
- After admin approval, the entries are published and appear as bookable shifts for customers.
