# UCD600 — Manage Review

**System:** UniSpa Booking Management System (UBMS)
**Version:** 1.0
**Related Test Suite:** TEST_UBMS_600 — Manage Review

---

## Use Case Overview

| Field | Details |
|---|---|
| Use Case ID | UCD600 |
| Use Case Name | Manage Review |
| Actor(s) | Customer |
| Trigger | Customer navigates to the Reviews page or clicks "Write a Review" from My Reservations |
| Pre-condition | Customer is logged in; at least one booking with `status = 'completed'` exists and has not been reviewed |
| Post-condition | Review is stored; booking no longer appears in the reviewable dropdown |
| Related UC | UCD500 Manage Booking (provides completed bookings eligible for review) |

---

## Main Flow — Customer Submits a Review

1. Customer navigates to **My Reservations** (or directly to the **Reviews** page).
2. For any booking with `status = 'completed'`, a **"Write a Review"** button is shown on the reservation card.
3. Customer clicks **"Write a Review"** — the system redirects to the Reviews page with that booking pre-selected in the dropdown.
4. Customer selects a star rating (1–5) and optionally writes a comment.
5. Customer clicks **"Submit Review"**.
6. System validates: booking belongs to the customer, status is `completed`, no prior review exists.
7. Review is saved. Green toast displayed: **"Thank you for your review!"**
8. The reviewed booking disappears from the dropdown immediately (page re-renders without it).

---

## Exception Conditions

| ID | Condition | System Response |
|---|---|---|
| E1 | Customer attempts to review a booking they already reviewed | Red error: "This booking already has a review." |
| E2 | Customer submits without selecting a booking | Validation error: "The booking id field is required." |
| E3 | Customer tries to review a `confirmed` or future booking directly via API | Red error: "Selected booking is not eligible for review." |
| E4 | Customer has no completed, unreviewed bookings | Review form submit button is disabled; message: "No eligible bookings available for review yet." |

---

## Test Cases Traceability

| Test Case | Description | Exception Covered |
|---|---|---|
| TEST_UBMS_601 | Customer submits a review for a completed booking | Main flow |
| TEST_UBMS_602 | Reviewed booking disappears from dropdown (duplicate prevention) | E1 |
| TEST_UBMS_603 | Confirmed/future bookings do not appear in the review dropdown | E3, E4 |

---

## TEST_UBMS_601 — Customer Submits a Review for a Completed Booking

**Goal:** Verify that a customer can write and submit a review for a completed booking, and receives the correct success message.

### Initialization
Customer `hasyadini15@gmail.com` (password: `Customer@123`) is logged in. Two completed bookings exist:
- **Aromatherapy Massage (60 mins)** — 10 June 2026
- **Deep Cleansing Facial** — 20 June 2026

Both are unreviewed.

### Test Inputs
- Navigate to **My Reservations** → click **"Write a Review"** on the Aromatherapy Massage booking
- Select rating: **5 stars**
- Comment: `"Amazing experience, very relaxing!"`

### Expected Result
Green toast: **"Thank you for your review!"**
Review is saved and visible in the **All Customer Reviews** section.
The "My Reviews" stat counter increments by 1.

### Test Procedure
1. Customer logs in and navigates to **My Reservations**.
2. Locate the completed booking for **Aromatherapy Massage (60 mins) — 10 June 2026**.
3. Verify the **"Write a Review"** button (amber) is visible.
4. Click **"Write a Review"** — verify redirect to Reviews page with that booking pre-selected in the dropdown.
5. Verify the dropdown shows: `Aromatherapy Massage (60 mins) – 10 June 2026`.
6. Select **5 stars** by clicking the 5th star.
7. Enter comment: `"Amazing experience, very relaxing!"`
8. Click **"Submit Review"**.
9. Observe green toast: **"Thank you for your review!"**
10. Scroll to **All Customer Reviews** — verify the new review appears.

### Constraints
Only bookings with `status = 'completed'` are eligible. The "Write a Review" button does not appear on pending, confirmed, accepted, or cancelled bookings.

---

## TEST_UBMS_602 — Reviewed Booking Disappears from Dropdown

**Goal:** Verify that once a booking is reviewed, it no longer appears in the reviewable booking dropdown (duplicate prevention).

### Initialization
Customer `hasyadini15@gmail.com` has just submitted a review for the Aromatherapy Massage booking (as in TEST_UBMS_601). The Deep Cleansing Facial booking is still unreviewed.

### Test Inputs
Navigate back to the Reviews page after submitting the review.

### Expected Result
The **Aromatherapy Massage (60 mins) – 10 June 2026** booking is **no longer present** in the dropdown.
Only **Deep Cleansing Facial – 20 June 2026** (and any other unreviewed completed bookings) remain.

### Test Procedure
1. After completing TEST_UBMS_601, remain on (or re-navigate to) the **Reviews** page.
2. Open the **"Select Booking"** dropdown.
3. Verify the reviewed Aromatherapy Massage booking is **absent** from the list.
4. Verify the Deep Cleansing Facial booking **still appears** (it has not been reviewed).
5. Attempt to POST a review for the same booking ID directly (e.g., via form manipulation) — system returns: **"This booking already has a review."**

### Constraints
The backend enforces this check via `whereNull('r.review_id')` in the `canReviewBookings` query, and an `exists()` check in `store()`.

---

## TEST_UBMS_603 — Confirmed/Future Bookings Do Not Appear in Review Dropdown

**Goal:** Verify that bookings with `status = 'confirmed'` (future appointments) do not appear in the reviewable bookings dropdown.

### Initialization
Customer `hasyadini15@gmail.com` has a **confirmed** booking:
- **Foot Reflexology (30 mins)** — 15 July 2026 (future date, status: `confirmed`)

Two completed bookings also exist (from TEST_UBMS_601/602 setup).

### Test Inputs
Customer navigates to the Reviews page and opens the booking dropdown.

### Expected Result
The **Foot Reflexology (30 mins) – 15 July 2026** booking is **not present** in the dropdown.
Only bookings with `status = 'completed'` that have not been reviewed appear.

### Test Procedure
1. Customer logs in and navigates to **My Reservations**.
2. Verify the Foot Reflexology booking shows status **"confirmed"** — no "Write a Review" button appears on it.
3. Navigate to the **Reviews** page.
4. Open the **"Select Booking"** dropdown.
5. Verify the Foot Reflexology booking does **not** appear in the list.
6. Verify only completed, unreviewed bookings appear.

### Constraints
The `canReviewBookings` query filters by `b.status = 'completed'` exclusively. Confirmed bookings are excluded regardless of date. The "Write a Review" button in My Reservations only renders when `a.status === 'completed'`.

---

## Dummy Data Reference

The following records were seeded for `hasyadini15@gmail.com` (customer_id: 3):

| Booking | Service | Date | Status | Purpose |
|---|---|---|---|---|
| BKHSYA0610VD | Aromatherapy Massage (60 mins) | 10 June 2026 | completed | TEST_UBMS_601 — reviewable |
| BKHSYA06201B | Deep Cleansing Facial | 20 June 2026 | completed | TEST_UBMS_602 — reviewable (second booking) |
| BKHSYA0715HD | Foot Reflexology (30 mins) | 15 July 2026 | confirmed | TEST_UBMS_603 — must NOT appear in review dropdown |
