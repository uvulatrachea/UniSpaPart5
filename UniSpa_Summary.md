# UniSpa Codebase Condensed Summary

> Generated for AI review: controllers, models, pages, migrations, and redundancies.

---
## 1. ALL CONTROLLERS

### app\Http\Controllers\AppointmentController.php

**Class:** AppointmentController extends Controller

  - private function **bookingRecipients**(Booking $booking)
  - public function **appointmentI**(Request $request)
  - public function **servicesPage**(Request $request)
  - public function **guestsPage**(Request $request)
  - public function **schedulePage**(Request $request)
  - public function **paymentPage**(Request $request)
  - public function **myBookings**(Request $request)
  - public function **showBooking**(Request $request, string $bookingId)
  - private function **appointmentsQuery**(?string $customerId)
  - private function **transformBooking**(Booking $b)
  - public function **cancelBooking**(Request $request, string $bookingId)
  - public function **updateBookingDetails**(Request $request, string $bookingId)
  - private function **getDraft**()
  - private function **saveDraft**(array $draft)
  - private function **recalcDraft**(array $draft)
  - public function **resetDraft**(Request $request)
  - public function **setDraftService**(Request $request)
  - public function **setDraftGuests**(Request $request)
  - public function **getAvailableSlots**(Request $request)
  - public function **setDraftSchedule**(Request $request)
  - public function **confirmBooking**(Request $request)
  - public function **createStripeSession**(Request $request)
  - public function **stripeSuccess**(Request $request)
  - public function **stripeCancel**()
  - public function **markQrPaid**(Request $request)

---

### app\Http\Controllers\BookingController.php

**Class:** BookingController extends Controller

  - public function **slots**(Request $request)
  - public function **checkout**(Request $request)
  - public function **guests**(Request $request)

---

### app\Http\Controllers\Controller.php

**Class:** Controller


---

### app\Http\Controllers\CustomerProfileController.php

**Class:** CustomerProfileController extends Controller

  - public function **show**()
  - public function **update**(Request $request)

---

### app\Http\Controllers\DashboardController.php

**Class:** DashboardController extends Controller

  - public function **index**()

---

### app\Http\Controllers\GuestDashboardController.php

**Class:** GuestDashboardController extends Controller

  - public function **index**()

---

### app\Http\Controllers\GuestServicesController.php

**Class:** GuestServicesController extends Controller

  - public function **index**()

---

### app\Http\Controllers\PageController.php

**Class:** PageController extends Controller

  - public function **promotions**()
  - public function **contact**()
  - public function **about**()

---

### app\Http\Controllers\PaymentController.php

**Class:** PaymentController extends Controller

  - public function **markQrPaid**(Request $request)
  - public function **createStripeCheckoutSession**(Request $request)
  - public function **stripeSuccess**(Request $request)
  - public function **stripeCancel**(Request $request)

---

### app\Http\Controllers\ProfileController.php

**Class:** ProfileController extends Controller

  - public function **edit**(Request $request)
  - public function **update**(ProfileUpdateRequest $request)
  - public function **destroy**(Request $request)

---

### app\Http\Controllers\RegisteredUserController.php

**Class:** RegisteredCustomerController extends Controller

  - public function **store**(Request $request)

---

### app\Http\Controllers\ReviewController.php

**Class:** ReviewController extends Controller

  - public function **index**()
  - public function **store**(Request $request)

---

### app\Http\Controllers\ServiceController.php

**Class:** ServiceController extends Controller

  - public function **index**()
  - public function **show**(int $id)

---

### app\Http\Controllers\StaffController.php

**Class:** StaffController extends Controller

  - public function **index**()
  - public function **create**()
  - public function **store**(Request $request)
  - public function **show**(string $id)
  - public function **edit**(string $id)
  - public function **update**(Request $request, string $id)
  - public function **destroy**(string $id)
  - private function **generateStaffId**()

---

### app\Http\Controllers\VerificationController.php

**Class:** VerificationController extends Controller

  - public function **__construct**(OtpService $otpService)
  - public function **showVerifyOtp**(Request $request)
  - public function **verifyOtp**(Request $request)
  - public function **resendOtp**(Request $request)
  - public function **checkStatus**(Request $request)

---

### app\Http\Controllers\Auth\AdminAuthController.php

**Class:** AdminAuthController extends Controller

  - public function **create**()
  - public function **store**(Request $request)
  - public function **destroy**(Request $request)

---

### app\Http\Controllers\Auth\auth.php


---

### app\Http\Controllers\Auth\AuthenticatedSessionController.php

**Class:** AuthenticatedSessionController extends Controller

  - public function **create**()
  - public function **store**(LoginRequest $request)
  - public function **destroy**(Request $request)

---

### app\Http\Controllers\Auth\ConfirmablePasswordController.php

**Class:** ConfirmablePasswordController extends Controller

  - public function **show**()
  - public function **store**(Request $request)

---

### app\Http\Controllers\Auth\CustomerAuthController.php

**Class:** CustomerAuthController extends Controller

  - public function **__construct**(OtpService $otpService)
  - public function **showLogin**()
  - public function **showSignup**()
  - public function **passwordLogin**(Request $request)
  - public function **showVerifyOtp**(Request $request)
  - public function **handleVerifyOtp**(Request $request)
  - public function **resendOtp**(Request $request)
  - public function **passwordRegister**(Request $request)
  - public function **showVerificationNotice**(Request $request)
  - public function **resendVerificationEmail**(Request $request)
  - public function **verifyEmail**(Request $request, $id, $hash)
  - public function **sendLoginOtp**(SendOtpRequest $request)
  - public function **verifyLoginOtp**(VerifyOtpRequest $request)
  - public function **sendSignupOtp**(SendOtpRequest $request)
  - public function **resendSignupOtp**(Request $request)
  - public function **verifySignupOtp**(VerifyOtpRequest $request)
  - public function **completeEmailSignup**(Request $request)
  - public function **logout**()

---

### app\Http\Controllers\Auth\CustomerSignupController.php

**Class:** CustomerSignupController extends Controller

  - public function **__construct**(OtpService $otpService)
  - public function **sendOtp**(Request $request)
  - public function **verifyOtp**(Request $request)
  - public function **resendOtp**(Request $request)
  - public function **completeSignup**(Request $request)

---

### app\Http\Controllers\Auth\EmailVerificationNotificationController.php

**Class:** EmailVerificationNotificationController extends Controller

  - public function **store**(Request $request)

---

### app\Http\Controllers\Auth\EmailVerificationPromptController.php

**Class:** EmailVerificationPromptController extends Controller

  - public function **__invoke**(Request $request)

---

### app\Http\Controllers\Auth\NewPasswordController.php

**Class:** NewPasswordController extends Controller

  - public function **create**(Request $request)
  - public function **store**(Request $request)

---

### app\Http\Controllers\Auth\PasswordController.php

**Class:** PasswordController extends Controller

  - public function **update**(Request $request)

---

### app\Http\Controllers\Auth\PasswordResetLinkController.php

**Class:** PasswordResetLinkController extends Controller

  - public function **create**()
  - public function **store**(Request $request)

---

### app\Http\Controllers\Auth\RegisteredCustomerController.php

**Class:** RegisteredCustomerController extends Controller

  - public function **store**(Request $request)

---

### app\Http\Controllers\Auth\RegisteredUserController.php

**Class:** RegisteredUserController extends Controller

  - public function **__construct**(OtpService $otpService)
  - public function **create**()
  - public function **store**(Request $request)

---

### app\Http\Controllers\Auth\StaffAuthController.php

**Class:** StaffAuthController extends Controller

  - public function **create**()
  - public function **store**(Request $request)
  - public function **destroy**(Request $request)

---

### app\Http\Controllers\Auth\VerifyEmailController.php

**Class:** VerifyEmailController extends Controller

  - public function **__invoke**(EmailVerificationRequest $request)

---

### app\Http\Controllers\Auth\Admin\AdminDashboardController.php

**Class:** AdminDashboardController extends Controller

  - private function **bookingRecipients**(Booking $booking)
  - private function **generateBookingReceipt**(Booking $booking, ?string $paymentReference = null)
  - public function **index**()
  - public function **users**()
  - public function **storeStaff**(Request $request)
  - public function **updateStaff**(Request $request, int $staffId)
  - public function **updateStaffStatus**(Request $request, int $staffId)
  - public function **destroyStaff**(int $staffId)
  - public function **storeCustomer**(Request $request)
  - public function **updateCustomer**(Request $request, int $customerId)
  - public function **updateCustomerVerification**(Request $request, int $customerId)
  - public function **destroyCustomer**(int $customerId)
  - public function **exportStaff**(Request $request)
  - public function **exportCustomers**(Request $request)
  - public function **importStaff**(Request $request)
  - public function **importCustomers**(Request $request)
  - public function **services**()
  - public function **storeServiceCategory**(Request $request)
  - public function **updateServiceCategory**(Request $request, int $categoryId)
  - public function **destroyServiceCategory**(int $categoryId)
  - public function **storeService**(Request $request)
  - public function **updateService**(Request $request, int $serviceId)
  - public function **destroyService**(int $serviceId)
  - public function **storePromotion**(Request $request)
  - public function **updatePromotion**(Request $request, int $promotionId)
  - public function **updatePromotionStatus**(Request $request, int $promotionId)
  - public function **destroyPromotion**(int $promotionId)
  - public function **updatePromotionHeaderVisibility**(Request $request, int $promotionId)
  - public function **printPromotion**(int $promotionId)
  - private function **storeImage**(?UploadedFile $file, string $folder)
  - private function **withTimestamps**(string $table, array $payload, bool $updateOnly = false)
  - private function **payloadForTable**(string $table, array $payload)
  - private function **categoryPrimaryKey**()
  - private function **servicePrimaryKey**()
  - private function **promotionServiceServiceColumn**()
  - private function **formatTags**(?string $raw)
  - public function **scheduling**(Request $request)
  - public function **approveStudentAvailability**(Request $request)
  - public function **storeShift**(Request $request)
  - public function **publishSchedule**(Request $request)
  - public function **confirmQrAndAssign**(Request $request, string $bookingId)
  - public function **bookings**()
  - public function **approveBooking**(string $bookingId)
  - public function **updateBookingStatus**(Request $request, string $bookingId)
  - public function **updateBookingPayment**(Request $request, string $bookingId)
  - public function **updateBookingDetails**(Request $request, string $bookingId)
  - public function **destroyBooking**(string $bookingId)
  - public function **reviews**()
  - public function **payments**()
  - private function **renderModulePage**(string $title, string $description, array $kpis)
  - public function **approveQrBooking**(Request $request, string $bookingId)

---

### app\Http\Controllers\Booking\CartController.php

**Class:** CartController extends Controller

  - public function **show**(Request $request)
  - public function **add**(Request $request)
  - public function **remove**(Request $request)
  - public function **clear**(Request $request)

---

### app\Http\Controllers\Booking\GuestController.php

**Class:** GuestController extends Controller

  - public function **show**()
  - public function **save**(Request $request)

---

### app\Http\Controllers\Booking\PaymentController.php

**Class:** PaymentController extends Controller

  - private function **allocateRealSlotId**(string $pickedSlotId, int $serviceId)
  - private function **generateBookingReceipt**(Booking $booking, ?string $paymentReference = null)
  - private function **bookingRecipients**(Booking $booking)
  - private function **sendBookingEmails**(Collection $bookings, bool $pendingReview = false)
  - private function **stripeMockEnabled**()
  - private function **deposit30**($amount)
  - private function **makeBookingId**()
  - public function **show**()
  - private function **parseTmpSlot**(string $tmpId)
  - private function **createBookingsFromCartForQr**()
  - private function **createBookingsFromCart**(bool $strictValidation = true)
  - public function **createStripeSession**(Request $request)
  - public function **stripeSuccess**(Request $request)
  - public function **stripeCancel**()
  - public function **uploadQrReceipt**(Request $request)
  - public function **repayShow**(string $bookingId)
  - public function **repayQr**(Request $request, string $bookingId)
  - public function **repayStripe**(Request $request, string $bookingId)

---

### app\Http\Controllers\Booking\ScheduleController.php

**Class:** ScheduleController extends Controller

  - private function **servicePk**()
  - public function **show**()
  - public function **slots**(Request $request)
  - public function **monthAvailability**(Request $request)
  - public function **confirm**(Request $request)
  - private function **timeToMinutes**($t)
  - private function **minutesToTime**(int $minutes)
  - private function **overlap**(int $aStart, int $aEnd, int $bStart, int $bEnd)

---

### app\Http\Controllers\Staff\StaffAvailabilityController.php

**Class:** StaffAvailabilityController extends Controller

  - private function **minWeeklyHours**()
  - public function **index**(Request $request)
  - public function **store**(Request $request)

---

### app\Http\Controllers\Staff\StaffDashboardController.php

**Class:** StaffDashboardController extends Controller

  - public function **index**(Request $request)

---

## 2. ALL MODELS

### app\Models\Booking.php

**Class:** Booking extends Model
- **belongsTo:** customer(Customer::class, 'customer_id', 'customer_id')
- **belongsTo:** slot(Slot::class, 'slot_id', 'slot_id')
- **hasMany:** participants(BookingParticipant::class, 'booking_id', 'booking_id')
- **hasOne:** review(Review::class, 'booking_id', 'booking_id')

---

### app\Models\BookingParticipant.php

**Class:** BookingParticipant extends Model

---

### app\Models\Cart.php

**Class:** Cart extends Model

---

### app\Models\Customer.php

**Class:** Customer extends Authenticatable
- **hasMany:** bookings(Booking::class, 'customer_id', 'customer_id')
- **hasOne:** memberVerification(MemberVerification::class, 'customer_id', 'customer_id')

---

### app\Models\GeneralStaff.php

**Class:** GeneralStaff extends Model

---

### app\Models\GeneralStaffQualification.php

**Class:** GeneralStaffQualification extends Model

---

### app\Models\MemberVerification.php

**Class:** MemberVerification extends Model

---

### app\Models\OtpVerification.php

**Class:** OtpVerification extends Model

---

### app\Models\Promotion.php

**Class:** Promotion extends Model

---

### app\Models\PromotionService.php

**Class:** PromotionService extends Model

---

### app\Models\Qualification.php

**Class:** Qualification extends Model

---

### app\Models\Review.php

**Class:** Review extends Model

---

### app\Models\Schedule.php

**Class:** Schedule extends Model

---

### app\Models\Service.php

**Class:** Service extends Model

---

### app\Models\ServiceCategory.php

**Class:** ServiceCategory extends Model

---

### app\Models\Slot.php

**Class:** Slot extends Model
- **belongsTo:** service(Service::class, 'service_id', 'id')

---

### app\Models\Staff.php

**Class:** Staff extends Authenticatable
- **hasOne:** generalStaff(GeneralStaff::class, 'staff_id', 'staff_id')
- **hasOne:** studentStaff(StudentStaff::class, 'staff_id', 'staff_id')
- **hasMany:** schedules(Schedule::class, 'staff_id', 'staff_id')
- **hasMany:** slots(Slot::class, 'staff_id', 'staff_id')

---

### app\Models\StudentStaff.php

**Class:** StudentStaff extends Model

---

### app\Models\SystemEmail.php

**Class:** SystemEmail extends Model

---

### app\Models\TreatmentRoom.php

**Class:** TreatmentRoom extends Model

---

### app\Models\User.php

**Class:** User extends Authenticatable

---

### app\Models\verification.php

**Class:** verification extends Model
- **belongsTo:** customer(Customer::class, 'customer_id', 'customer_id')

---

## 3. ALL PAGE COMPONENTS

### resources\js\Pages\About.jsx

- **Component:** About

### resources\js\Pages\AdminDashboard.jsx

- **Component:** AdminDashboard
- **Props:** auth

### resources\js\Pages\AppointmentBooking.jsx

- **Component:** AppointmentBooking

### resources\js\Pages\Appointments.jsx

- **Component:** Appointments
- **Props:** auth, appointments, highlightBookingId = null

### resources\js\Pages\BookingFlow.jsx

- **Component:** BookingFlow
- **Props:** auth, categories, services, qrImageUrl

### resources\js\Pages\CustomerDashboard.jsx

- **Component:** CustomerDashboard
- **Props:** auth, stats, promotions, appointments, services, favoriteServices = [], goToService = null, notifications = []

### resources\js\Pages\Dashboard.jsx

- **Component:** Dashboard
- **Props:** auth

### resources\js\Pages\Guests.jsx


### resources\js\Pages\Profile.jsx

- **Component:** Profile
- **Props:** label, icon, editing, name, value, onChange, error, type

### resources\js\Pages\Promotions.jsx

- **Component:** Promotions

### resources\js\Pages\ResponsiveTest.jsx

- **Component:** ResponsiveTest

### resources\js\Pages\Reviews.jsx

- **Component:** Reviews
- **Props:** title, value

### resources\js\Pages\TestModels.jsx

- **Component:** TestModels
- **Props:** customer_count, sample_customer, service_count, sample_service, sample_bookings

### resources\js\Pages\VerifyOtp.jsx

- **Component:** VerifyOtp
- **Props:** email

### resources\js\Pages\Welcome.jsx

- **Component:** Welcome
- **Props:** auth, laravelVersion, phpVersion

### resources\js\Pages\Admin\AdminDashboard.jsx

- **Component:** AdminDashboard
- **Props:** labels = [], series = []

### resources\js\Pages\Admin\AdminModule.jsx

- **Component:** AdminModule

### resources\js\Pages\Admin\ManageBookings.jsx

- **Component:** ManageBookings
- **Props:** label, value

### resources\js\Pages\Admin\ManageReviews.jsx

- **Component:** ManageReviews
- **Props:** label, value

### resources\js\Pages\Admin\ManageScheduling.jsx

- **Component:** ManageScheduling
- **Props:** title, items = [], onQuickAssign

### resources\js\Pages\Admin\ManageServices.jsx

- **Component:** ManageServices
- **Props:** modal, onClose

### resources\js\Pages\Admin\ManageUsers.jsx

- **Component:** ManageUsers
- **Props:** row, onEdit

### resources\js\Pages\Admin\Partials\AdminShell.jsx

- **Component:** AdminShell
- **Props:** title, subtitle, children

### resources\js\Pages\Appointment\AppointmentI.jsx

- **Component:** AppointmentI
- **Props:** auth, services = [], selectedServiceId = null

### resources\js\Pages\Auth\AdminLogin.jsx

- **Component:** AdminLogin

### resources\js\Pages\Auth\Auth.jsx

- **Component:** Auth

### resources\js\Pages\Auth\ConfirmPassword.jsx

- **Component:** ConfirmPassword

### resources\js\Pages\Auth\CustomerLogin.jsx

- **Component:** CustomerLogin

### resources\js\Pages\Auth\CustomerSignup.jsx

- **Component:** CustomerSignup

### resources\js\Pages\Auth\EmailVerificationNotice.jsx

- **Component:** EmailVerificationNotice
- **Props:** email

### resources\js\Pages\Auth\ForgotPassword.jsx

- **Component:** ForgotPassword
- **Props:** status

### resources\js\Pages\Auth\Login.jsx

- **Component:** Login
- **Props:** status, canResetPassword = true

### resources\js\Pages\Auth\Register.jsx

- **Component:** Register

### resources\js\Pages\Auth\ResetPassword.jsx

- **Component:** ResetPassword
- **Props:** token, email

### resources\js\Pages\Auth\StaffLogin.jsx

- **Component:** StaffLogin

### resources\js\Pages\Auth\VerifyEmail.jsx

- **Component:** VerifyEmail
- **Props:** status

### resources\js\Pages\Booking\Cart.jsx

- **Component:** Cart
- **Props:** cartItems = [], subtotal: propSubtotal, discountAmount = 0, total: propTotal, isUitmMember = false

### resources\js\Pages\Booking\Guests.jsx

- **Component:** Guests

### resources\js\Pages\Booking\Payment.jsx

- **Component:** Payment
- **Props:** preview = [], totalFinal = 0, totalDeposit = 0, qrUploadUrl, stripeSessionUrl, stripeMock = false

### resources\js\Pages\Booking\Repay.jsx

- **Component:** Repay
- **Props:** booking, qrUploadUrl, stripeSessionUrl, stripeMock = false

### resources\js\Pages\Booking\Schedule.jsx

- **Component:** Schedule
- **Props:** open, title, message, onClose

### resources\js\Pages\Booking\ServiceDetail.jsx

- **Component:** ServiceDetail
- **Props:** service, reviews = [], related = []

### resources\js\Pages\Booking\Services.jsx

- **Component:** ServicesPage
- **Props:** auth, services = [], categories = []

### resources\js\Pages\Booking\StripeResult.jsx

- **Component:** StripeResult
- **Props:** ok = false, message = "", draft = null

### resources\js\Pages\Guest\GuestDashboard.jsx

- **Component:** GuestDashboard
- **Props:** stats, promotions, services

### resources\js\Pages\Guest\GuestServices.jsx

- **Component:** GuestServices
- **Props:** services, categories

### resources\js\Pages\Profile\Edit.jsx

- **Component:** Edit
- **Props:** mustVerifyEmail, status

### resources\js\Pages\Profile\Partials\DeleteUserForm.jsx

- **Component:** DeleteUserForm
- **Props:** className = ''

### resources\js\Pages\Profile\Partials\UpdatePasswordForm.jsx

- **Component:** UpdatePasswordForm
- **Props:** className = ''

### resources\js\Pages\Profile\Partials\UpdateProfileInformationForm.jsx

- **Component:** UpdateProfileInformation
- **Props:** mustVerifyEmail, status, className = '', 
- **Uses page prop:** auth

### resources\js\Pages\Staff\StaffAvailability.jsx

- **Component:** StaffAvailability
- **Props:** label, value, tone = "normal"

### resources\js\Pages\Staff\StaffDashboard.jsx

- **Component:** StaffDashboard

### resources\js\Pages\Staff\Partials\StaffShell.jsx

- **Component:** StaffShell
- **Props:** title, subtitle, children

---

## 4. ALL MIGRATIONS

### database\migrations\0001_01_01_000000_create_customers_table.php

- **Table:** `customers`
- **Columns:**
  - `customer_id` (id)
  - `name` (string)
  - `email` (string)
  - `email_verified_at` (timestamp)
  - `password` (string)
  - `phone` (string)
  - `is_uitm_member` (boolean)
  - `verification_status` (string)
  - `cust_type` (string)
  - `member_type` (string)
  - `otp_token` (string)
  - `otp_expires_at` (timestamp)
  - `is_email_verified` (boolean)
  - `google_id` (string)
  - `auth_method` (string)
  - `profile_completed` (boolean)

### database\migrations\0001_01_01_000001_create_cache_table.php

- **Table:** `cache`
- **Columns:**
  - `key` (string)
  - `value` (mediumText)
  - `expiration` (integer)
  - `key` (string)
  - `owner` (string)
  - `expiration` (integer)

### database\migrations\0001_01_01_000002_create_jobs_table.php

- **Table:** `jobs`
- **Columns:**
  - `queue` (string)
  - `payload` (longText)
  - `attempts` (unsignedTinyInteger)
  - `reserved_at` (unsignedInteger)
  - `available_at` (unsignedInteger)
  - `created_at` (unsignedInteger)
  - `id` (string)
  - `name` (string)
  - `total_jobs` (integer)
  - `pending_jobs` (integer)
  - `failed_jobs` (integer)
  - `failed_job_ids` (longText)
  - `options` (mediumText)
  - `cancelled_at` (integer)
  - `created_at` (integer)
  - `finished_at` (integer)
  - `uuid` (string)
  - `connection` (text)
  - `queue` (text)
  - `payload` (longText)
  - `exception` (longText)
  - `failed_at` (timestamp)

### database\migrations\2026_01_04_091732_add_otp_fields_to_customer_table.php

- **Alters Table:** `customers`
- **Columns:**
  - `phone` (string)
  - `is_uitm_member` (boolean)
  - `verification_status` (string)
  - `cust_type` (string)
  - `otp_token` (string)
  - `otp_expires_at` (timestamp)
  - `is_email_verified` (boolean)
  - `google_id` (string)
  - `auth_method` (string)
  - `profile_completed` (boolean)

### database\migrations\2026_01_04_094325_create_laravel_essential_tables.php

- **Table:** `password_reset_tokens`
- **Columns:**
  - `email` (string)
  - `token` (string)
  - `created_at` (timestamp)
  - `tokenable` (morphs)
  - `name` (string)
  - `token` (string)
  - `abilities` (text)
  - `last_used_at` (timestamp)
  - `expires_at` (timestamp)
  - `id` (string)
  - `user_id` (foreignId)
  - `ip_address` (string)
  - `user_agent` (text)
  - `payload` (longText)
  - `last_activity` (integer)

### database\migrations\2026_01_05_000000_create_users_table.php

- **Table:** `users`
- **Columns:**
  - `name` (string)
  - `email` (string)
  - `email_verified_at` (timestamp)
  - `password` (string)

### database\migrations\2026_01_06_000000_fix_customer_cust_type.php

- **Alters Table:** `customer`
- **Columns:**
  - `cust_type` (string)

### database\migrations\2026_01_09_025315_create_service_categories_table.php

- **Table:** `service_category`
- **Columns:**
  - `name` (string)
  - `gender` (string)

### database\migrations\2026_01_10_024846_create_service_table.php

- **Table:** `service`
- **Columns:**
  - `category_id` (unsignedBigInteger)
  - `name` (string)
  - `description` (text)
  - `price` (decimal)
  - `duration_minutes` (integer)
  - `image_url` (string)
  - `is_popular` (boolean)
  - `tags` (string)
  - `location_mode` (string)
  - `category_id` (foreign)

### database\migrations\2026_02_06_202431_create_slots_table.php

- **Table:** `slot`
- **Columns:**
  - `slot_id` (string)
  - `service_id` (unsignedBigInteger)
  - `staff_id` (unsignedBigInteger)
  - `slot_date` (date)
  - `start_time` (time)
  - `end_time` (time)
  - `status` (string)
  - `service_id` (foreign)

### database\migrations\2026_02_06_202433_add_capacity_to_service_category_table.php

- **Alters Table:** `service_category`
- **Columns:**
  - `capacity_units` (integer)
  - `capacity_units` (dropColumn)

### database\migrations\2026_02_07_025748_create_promotion_table.php

- **Table:** `promotion`
- **Columns:**
  - `promotion_id` (id)
  - `title` (string)
  - `description` (text)
  - `discount_type` (string)
  - `discount_value` (decimal)
  - `banner_image` (string)
  - `link` (string)
  - `start_date` (date)
  - `end_date` (date)
  - `is_active` (boolean)

### database\migrations\2026_02_08_153600_ensure_promotion_service_connection.php

- **Table:** `promotion_service`
- **Columns:**
  - `promotion_id` (unsignedBigInteger)
  - `service_id` (unsignedBigInteger)
  - `promotion_id` (index)
  - `service_id` (index)
  - `promotion_id` (foreign)
  - `service_id` (foreign)

### database\migrations\2026_02_08_160000_add_dashboard_visibility_to_promotion_table.php

- **Alters Table:** `promotion`
- **Columns:**
  - `show_in_dashboard_header` (boolean)
  - `show_in_dashboard_header` (dropColumn)

### database\migrations\2026_02_09_220000_add_schedule_approval_status_and_slot_room_constraints.php

- **Alters Table:** `schedule`
- **Columns:**
  - `approval_status` (string)
  - `room_id` (unsignedBigInteger)
  - `slot_staff_date_time_unique` (dropUnique)
  - `slot_room_date_time_unique` (dropUnique)
  - `approval_status` (dropColumn)

### database\migrations\2026_02_10_000000_add_schedule_approval_notes.php

- **Alters Table:** `schedule`
- **Columns:**
  - `approval_notes` (text)
  - `approval_notes` (dropColumn)

### database\migrations\2026_02_10_010000_sync_customers_auth_columns.php

- **Alters Table:** `customers`
- **Columns:**
  - `is_email_verified` (boolean)
  - `auth_method` (string)
  - `profile_completed` (boolean)
  - `google_id` (string)
  - `otp_token` (string)
  - `otp_expires_at` (timestamp)

### database\migrations\2026_02_10_030145_create_staff_table.php

- **Table:** `staff`
- **Columns:**
  - `staff_id` (id)
  - `name` (string)
  - `email` (string)
  - `phone` (string)
  - `password` (string)
  - `staff_type` (string)
  - `role` (string)
  - `work_status` (string)

### database\migrations\2026_02_10_030302_create_review_table.php

- **Table:** `review`
- **Columns:**
  - `review_id` (id)
  - `rating` (tinyInteger)

### database\migrations\2026_02_10_030828_create_booking_table.php

- **Table:** `booking`
- **Columns:**
  - `booking_id` (string)
  - `customer_id` (unsignedBigInteger)
  - `slot_id` (unsignedBigInteger)
  - `total_amount` (decimal)
  - `discount_amount` (decimal)
  - `final_amount` (decimal)
  - `deposit_amount` (decimal)
  - `status` (string)
  - `payment_method` (string)
  - `payment_status` (string)
  - `depo_qr_pic` (string)
  - `digital_receipt` (string)
  - `customer_id` (foreign)
  - `slot_id` (foreign)

### database\migrations\2026_02_10_120000_add_booking_id_to_review_table.php

- **Alters Table:** `review`
- **Columns:**
  - `booking_id` (string)
  - `booking_id` (dropColumn)

### database\migrations\2026_02_10_130000_create_cart_table.php

- **Table:** `cart`
- **Columns:**
  - `customer_id` (unsignedBigInteger)
  - `items` (json)
  - `customer_id` (foreign)

### database\migrations\2026_02_10_140000_create_schedule_table.php

- **Table:** `schedule`
- **Columns:**
  - `staff_id` (unsignedBigInteger)
  - `schedule_date` (date)
  - `start_time` (time)
  - `end_time` (time)
  - `status` (string)
  - `approval_status` (string)
  - `staff_id` (foreign)

### database\migrations\2026_02_10_150000_create_booking_participant_table.php

- **Table:** `booking_participant`
- **Columns:**
  - `participant_id` (id)
  - `booking_id` (string)
  - `is_self` (boolean)
  - `name` (string)
  - `phone` (string)
  - `email` (string)
  - `is_uitm_member` (boolean)
  - `discount_amount` (decimal)

### database\migrations\2026_02_10_150100_fix_booking_slot_id_to_string.php

- **Alters Table:** `booking`
- **Columns:**
  - `slot_id` (string)
  - `slot_id` (unsignedBigInteger)

### database\migrations\2026_02_10_160000_create_treatment_room_table.php

- **Table:** `treatment_room`
- **Columns:**
  - `room_id` (id)
  - `room_name` (string)
  - `room_type` (string)
  - `category_id` (unsignedBigInteger)
  - `gender` (string)
  - `status` (string)
  - `is_active` (boolean)
  - `category_id` (foreign)

### database\migrations\2026_02_11_000000_add_missing_columns_to_review_table.php

- **Alters Table:** `review`
- **Columns:**
  - `customer_id` (unsignedBigInteger)
  - `comment` (text)
  - `customer_id` (dropColumn)
  - `comment` (dropColumn)

### database\migrations\2026_02_11_120000_create_student_staff_and_general_staff_tables.php

- **Table:** `student_staff`
- **Columns:**
  - `staff_id` (unsignedBigInteger)
  - `working_hours` (integer)
  - `staff_id` (foreign)
  - `staff_id` (unsignedBigInteger)
  - `staff_id` (foreign)
  - `qualification_id` (id)
  - `staff_id` (unsignedBigInteger)
  - `qualification_name` (string)
  - `institution` (string)
  - `year_obtained` (year)
  - `staff_id` (foreign)

### database\migrations\2026_02_11_130000_fix_schedule_table_columns.php

- **Alters Table:** `schedule`
- **Columns:**
  - `id` (renameColumn)
  - `created_by` (string)
  - `approval_notes` (text)
  - `schedule_id` (renameColumn)
  - `created_by` (dropColumn)
  - `approval_notes` (dropColumn)

### database\migrations\2026_02_11_130100_add_special_requests_to_booking_table.php

- **Alters Table:** `booking`
- **Columns:**
  - `special_requests` (text)
  - `special_requests` (dropColumn)

### database\migrations\2026_02_11_140000_create_otp_verifications_table.php

- **Table:** `otp_verifications`
- **Columns:**
  - `email` (string)
  - `otp_token` (string)
  - `expires_at` (timestamp)
  - `attempts` (integer)
  - `type` (string)
  - `signup_data` (json)

### database\migrations\2026_02_11_215156_create_verifications_table.php

- **Table:** `verifications`
- **Columns:**
  - `customer_id` (unsignedBigInteger)
  - `unique_id` (string)
  - `otp` (string)
  - `type` (enum)
  - `send_via` (enum)
  - `resend` (integer)
  - `status` (enum)
  - `customer_id` (foreign)

---

## 5. DUPLICATES AND REDUNDANCIES

### A. Duplicate Controller Classes

- **PaymentController** defined in:
  - app\Http\Controllers\PaymentController.php
  - app\Http\Controllers\Booking\PaymentController.php

- **RegisteredCustomerController** defined in:
  - app\Http\Controllers\RegisteredUserController.php
  - app\Http\Controllers\Auth\RegisteredCustomerController.php

### B. Duplicated or Similar Model Classes

- No duplicate model class names found.

### C. Potentially Redundant Routes

- **Duplicate route name:** `verify.otp.page` (2 times)
- **Duplicate route name:** `verify.otp` (2 times)
- **Duplicate route name:** `verify.otp.resend` (2 times)

### D. Empty or Near-Empty Model Classes

- **app\Models\GeneralStaff.php** (11 lines) - no relationships or fillable defined
- **app\Models\GeneralStaffQualification.php** (11 lines) - no relationships or fillable defined
- **app\Models\MemberVerification.php** (11 lines) - no relationships or fillable defined
- **app\Models\PromotionService.php** (11 lines) - no relationships or fillable defined
- **app\Models\Qualification.php** (11 lines) - no relationships or fillable defined
- **app\Models\Review.php** (13 lines) - no relationships or fillable defined
- **app\Models\Schedule.php** (11 lines) - no relationships or fillable defined
- **app\Models\ServiceCategory.php** (11 lines) - no relationships or fillable defined
- **app\Models\StudentStaff.php** (11 lines) - no relationships or fillable defined
- **app\Models\SystemEmail.php** (11 lines) - no relationships or fillable defined
- **app\Models\TreatmentRoom.php** (11 lines) - no relationships or fillable defined

### E. Duplicated Method Implementations Across Controllers

- **bookingRecipients(Booking):** Defined in AppointmentController, AdminDashboardController, and Booking\PaymentController
- **generateBookingReceipt(Booking, ?string):** Defined in AdminDashboardController and Booking\PaymentController
- **createBookingsFromCart / parseTmpSlot pattern:** Duplicated between Booking\PaymentController and AppointmentController
- **deposit30 / stripeMockEnabled:** Repeated in root PaymentController and Booking\PaymentController
- **RegisteredCustomerController:** Exists in both Auth\ and root Controllers\ directory

### F. Other Observations

- **verification.php** - lowercase filename, class is `verification` (lowercase), inconsistent with other PascalCase model names
- **Schedule.php** - class body is empty (only has `use` statement, no properties or relationships)
- **PaymentController.php** (root) vs **Booking\PaymentController.php** - two payment controllers with overlapping methods
- **CustomerSignupController.php** (Auth) vs **RegisteredCustomerController.php** (Auth) vs **RegisteredCustomerController.php** (root) vs **CustomerAuthController.php** - multiple controllers handling overlapping OTP/signup logic
- **BookingController.php** vs **AppointmentController.php** vs **Booking\ScheduleController.php** - overlapping booking/slot responsibilities

---

## 6. PROJECT OVERVIEW

- **Framework:** Laravel 12 + Inertia.js 2 + React (JSX)
- **Database:** PostgreSQL (pgsql driver)
- **Payment:** Stripe + QR/manual upload
- **Auth Guards:** customer, staff (with admin.only and staff.only middleware)
- **Auth Methods:** Password, OTP (email), Google Socialite
- **User Types:** Customer (UITM member / non-member), Staff (admin, general_staff, student_staff)
- **Key Features:** Service booking with cart, slot scheduling, staff availability, promotions, reviews
- **Deployment:** Docker (PHP 8.4, nginx proxy), Render.com


