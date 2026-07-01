<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AppointmentController,
    CustomerProfileController,
    DashboardController,
    GuestDashboardController,
    GuestServicesController,
    ProfileController,
    ReviewController,
    ServiceController,
    VerificationController
};

use App\Http\Controllers\Booking\{
    CartController,
    GuestController,
    PaymentController,
    ScheduleController
};

use App\Http\Controllers\Auth\{
    AdminAuthController,
    StaffAuthController,
    CustomerAuthController
};

use App\Http\Controllers\Auth\Admin\AdminDashboardController;
use App\Http\Controllers\Staff\{
    StaffDashboardController,
    StaffAvailabilityController
};

/*
|--------------------------------------------------------------------------
| HOME (GUEST DASHBOARD)
|--------------------------------------------------------------------------
| Home page should be guest dashboard.
*/
Route::get('/', [GuestDashboardController::class, 'index'])
    ->name('home');

/*
|--------------------------------------------------------------------------
| GUEST PAGES
|--------------------------------------------------------------------------
*/
Route::get('/guest/dashboard', [GuestDashboardController::class, 'index'])
    ->name('guest.dashboard');

Route::get('/guest/services', [GuestServicesController::class, 'index'])
    ->name('guest.services');

// Service detail (public — accessible to guests and logged-in customers)
Route::get('/booking/services/{id}', [ServiceController::class, 'show'])
    ->name('booking.services.show');

/*
|--------------------------------------------------------------------------
| CUSTOMER AUTH
|--------------------------------------------------------------------------
*/
Route::middleware('guest:customer')->group(function () {

    // Pages (Inertia render Login.jsx / Register.jsx)
    Route::get('/customer/login', [CustomerAuthController::class, 'showLogin'])
        ->name('customer.login');

    Route::get('/customer/signup', [CustomerAuthController::class, 'showSignup'])
        ->name('customer.signup');

    // Password login/register
    Route::post('/customer/login', [CustomerAuthController::class, 'passwordLogin'])
        ->name('customer.login.post');

    Route::post('/customer/signup', [CustomerAuthController::class, 'passwordRegister'])
        ->name('customer.register');

    // OTP login
    Route::post('/customer/auth/login-send-otp', [CustomerAuthController::class, 'sendLoginOtp'])
        ->name('customer.login.sendOtp');

    Route::post('/customer/auth/verify-otp-login', [CustomerAuthController::class, 'verifyLoginOtp'])
        ->name('customer.login.verifyOtp');

    // OTP signup
    Route::post('/customer/signup/send-otp', [CustomerAuthController::class, 'sendSignupOtp'])
        ->name('customer.signup.sendOtp');

    Route::post('/customer/signup/verify-otp', [CustomerAuthController::class, 'verifySignupOtp'])
        ->name('customer.signup.verifyOtp');

    Route::post('/customer/signup/resend-otp', [CustomerAuthController::class, 'resendSignupOtp'])
        ->name('customer.signup.resendOtp');

    Route::post('/customer/signup/complete', [CustomerAuthController::class, 'completeEmailSignup'])
        ->name('customer.signup.complete');

    // OTP verification page (shown after UITM signup)
    Route::get('/verify-otp', [CustomerAuthController::class, 'showVerifyOtp'])
        ->name('verify.otp.page');

    Route::post('/verify-otp', [CustomerAuthController::class, 'handleVerifyOtp'])
        ->name('verify.otp');

    Route::post('/verify-otp/resend', [CustomerAuthController::class, 'resendOtp'])
        ->name('verify.otp.resend');

    // Email verification (link-based)
    Route::get('/verification/notice', [CustomerAuthController::class, 'showVerificationNotice'])
        ->name('verification.notice');

    Route::post('/verification/resend', [CustomerAuthController::class, 'resendVerificationEmail'])
        ->name('verification.resend');
});

use Illuminate\Foundation\Auth\EmailVerificationRequest;

// Handle email verification link (public - no auth required)
Route::get('/email/verify/{id}/{hash}', [CustomerAuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

/*
|--------------------------------------------------------------------------
| VERIFICATION (CUSTOMER ONLY)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:customer')->group(function () {
    Route::get('/verify-otp', [VerificationController::class, 'showVerifyOtp'])
        ->name('verify.otp.page');

    Route::post('/verify-otp', [VerificationController::class, 'verifyOtp'])
        ->name('verify.otp');

    Route::post('/verify-otp/resend', [VerificationController::class, 'resendOtp'])
        ->name('verify.otp.resend');

    Route::get('/verification/status', [VerificationController::class, 'checkStatus'])
        ->name('verification.status');
});

// Logout (customer only)
Route::match(['get', 'post'], '/customer/logout', [CustomerAuthController::class, 'logout'])
    ->middleware('auth:customer')
    ->name('customer.logout');

/*
|--------------------------------------------------------------------------
| CUSTOMER DASHBOARD
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth:customer', 'chack.status'])
    ->name('customer.dashboard');

/*
|--------------------------------------------------------------------------
| BOOKING (CUSTOMER ONLY)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:customer')->group(function () {

    // Single-page appointment booking (Book Now → /appointment/appointment-i)
    Route::get('/appointment/appointment-i', [AppointmentController::class, 'appointmentI'])
        ->name('appointment.i');

    Route::get('/booking/services', [ServiceController::class, 'index'])
        ->name('booking.services');

    Route::get('/booking/cart', [CartController::class, 'show'])
        ->name('booking.cart');

    Route::post('/booking/cart/add', [CartController::class, 'add'])
        ->name('booking.cart.add');

    Route::post('/booking/cart/remove', [CartController::class, 'remove'])
        ->name('booking.cart.remove');

    Route::post('/booking/cart/clear', [CartController::class, 'clear'])
        ->name('booking.cart.clear');

    Route::post('/booking/cart/promo/apply', [CartController::class, 'applyPromo'])
        ->name('booking.cart.promo.apply');

    Route::post('/booking/cart/promo/remove', [CartController::class, 'removePromo'])
        ->name('booking.cart.promo.remove');

    Route::get('/booking/schedule', [ScheduleController::class, 'show'])
        ->name('booking.schedule');

    Route::get('/booking/slots/month', [ScheduleController::class, 'monthAvailability'])
        ->name('booking.slots.month');

    Route::post('/booking/slots', [ScheduleController::class, 'slots'])
        ->name('booking.slots');

    Route::post('/booking/schedule/confirm', [ScheduleController::class, 'confirm'])
        ->name('booking.schedule.confirm');

    Route::get('/booking/guests', [GuestController::class, 'show'])
        ->name('booking.guests');

    Route::post('/booking/guests', [GuestController::class, 'save'])
        ->name('booking.guests.save');

    Route::get('/booking/payment', [PaymentController::class, 'show'])
        ->name('booking.payment');

    Route::post('/booking/payment/stripe/session', [PaymentController::class, 'createStripeSession'])
        ->name('booking.payment.stripe.session');

    Route::get('/booking/payment/stripe/success', [PaymentController::class, 'stripeSuccess'])
        ->name('booking.payment.stripe.success');

    Route::get('/booking/payment/stripe/cancel', [PaymentController::class, 'stripeCancel'])
        ->name('booking.payment.stripe.cancel');

    Route::post('/booking/payment/qr/upload', [PaymentController::class, 'uploadQrReceipt'])
        ->name('booking.payment.qr.upload');

    // Appointment (draft) flow – Stripe success/cancel
    Route::get('/payment/stripe/success', [AppointmentController::class, 'stripeSuccess'])
        ->name('payment.stripe.success');

    Route::get('/payment/stripe/cancel', [AppointmentController::class, 'stripeCancel'])
        ->name('payment.stripe.cancel');

    // Customer "My Bookings" / Appointments (list, detail, cancel, update details)
    Route::get('/bookings', [AppointmentController::class, 'myBookings'])
        ->name('bookings.index');

    Route::get('/bookings/{bookingId}', [AppointmentController::class, 'showBooking'])
        ->name('bookings.show');

    Route::patch('/bookings/{bookingId}/cancel', [AppointmentController::class, 'cancelBooking'])
        ->name('bookings.cancel');

    Route::patch('/bookings/{bookingId}/details', [AppointmentController::class, 'updateBookingDetails'])
        ->name('bookings.update-details');

    // Repay an unpaid booking (shows QR + Stripe options for a single booking)
    Route::get('/bookings/{bookingId}/pay', [PaymentController::class, 'repayShow'])
        ->name('bookings.repay');

    Route::post('/bookings/{bookingId}/pay/qr', [PaymentController::class, 'repayQr'])
        ->name('bookings.repay.qr');

    Route::post('/bookings/{bookingId}/pay/stripe', [PaymentController::class, 'repayStripe'])
        ->name('bookings.repay.stripe');

    // Customer Profile
    Route::get('/customer/profile', [CustomerProfileController::class, 'show'])
        ->name('customer.profile');

    Route::patch('/customer/profile', [CustomerProfileController::class, 'update'])
        ->name('customer.profile.update');
});

/*
|--------------------------------------------------------------------------
| REVIEWS
|--------------------------------------------------------------------------
*/
Route::get('/reviews', [ReviewController::class, 'index'])
    ->name('reviews.index');

Route::post('/reviews', [ReviewController::class, 'store'])
    ->middleware('auth:customer')
    ->name('reviews.store');

/*
|--------------------------------------------------------------------------
| ADMIN AUTH
|--------------------------------------------------------------------------
*/
Route::middleware('guest:staff')->group(function () {
    Route::get('/admin/login', [AdminAuthController::class, 'create'])
        ->name('admin.login');

    Route::post('/admin/login', [AdminAuthController::class, 'store'])
        ->name('admin.login.store');
});

Route::post('/admin/logout', [AdminAuthController::class, 'destroy'])
    ->middleware('admin.only')
    ->name('admin.logout');

/*
|--------------------------------------------------------------------------
| ADMIN DASHBOARD & MANAGEMENT (protected by admin.only)
|--------------------------------------------------------------------------
*/
Route::middleware('admin.only')->prefix('admin')->group(function () {

    // Dashboard
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])
        ->name('admin.dashboard');

    // --- Users ---
    Route::get('/users', [AdminDashboardController::class, 'users'])
        ->name('admin.users');

    Route::post('/users/staff', [AdminDashboardController::class, 'storeStaff'])
        ->name('admin.users.staff.store');

    Route::match(['put', 'patch'], '/users/staff/{staffId}', [AdminDashboardController::class, 'updateStaff'])
        ->name('admin.users.staff.update');

    Route::patch('/users/staff/{staffId}/status', [AdminDashboardController::class, 'updateStaffStatus'])
        ->name('admin.users.staff.status');

    Route::delete('/users/staff/{staffId}', [AdminDashboardController::class, 'destroyStaff'])
        ->name('admin.users.staff.destroy');

    Route::post('/users/customer', [AdminDashboardController::class, 'storeCustomer'])
        ->name('admin.users.customers.store');

    Route::match(['put', 'patch'], '/users/customer/{customerId}', [AdminDashboardController::class, 'updateCustomer'])
        ->name('admin.users.customers.update');

    Route::patch('/users/customer/{customerId}/verification', [AdminDashboardController::class, 'updateCustomerVerification'])
        ->name('admin.users.customers.verification');

    Route::delete('/users/customer/{customerId}', [AdminDashboardController::class, 'destroyCustomer'])
        ->name('admin.users.customers.destroy');

    Route::get('/users/staff/export', [AdminDashboardController::class, 'exportStaff'])
        ->name('admin.users.staff.export');

    Route::get('/users/customers/export', [AdminDashboardController::class, 'exportCustomers'])
        ->name('admin.users.customers.export');

    Route::post('/users/staff/import', [AdminDashboardController::class, 'importStaff'])
        ->name('admin.users.staff.import');

    Route::post('/users/customers/import', [AdminDashboardController::class, 'importCustomers'])
        ->name('admin.users.customers.import');

    // --- Services, Categories & Promotions ---
    Route::get('/services', [AdminDashboardController::class, 'services'])
        ->name('admin.services');

    Route::post('/services/categories', [AdminDashboardController::class, 'storeServiceCategory'])
        ->name('admin.services.categories.store');

    Route::match(['post', 'put', 'patch'], '/services/categories/{categoryId}', [AdminDashboardController::class, 'updateServiceCategory'])
        ->name('admin.services.categories.update');

    Route::delete('/services/categories/{categoryId}', [AdminDashboardController::class, 'destroyServiceCategory'])
        ->name('admin.services.categories.destroy');

    Route::post('/services/items', [AdminDashboardController::class, 'storeService'])
        ->name('admin.services.items.store');

    Route::match(['post', 'put', 'patch'], '/services/items/{serviceId}', [AdminDashboardController::class, 'updateService'])
        ->name('admin.services.items.update');

    Route::delete('/services/items/{serviceId}', [AdminDashboardController::class, 'destroyService'])
        ->name('admin.services.items.destroy');

    Route::post('/services/promotions', [AdminDashboardController::class, 'storePromotion'])
        ->name('admin.services.promotions.store');

    Route::match(['post', 'put', 'patch'], '/services/promotions/{promotionId}', [AdminDashboardController::class, 'updatePromotion'])
        ->name('admin.services.promotions.update');

    Route::patch('/services/promotions/{promotionId}/status', [AdminDashboardController::class, 'updatePromotionStatus'])
        ->name('admin.services.promotions.status');

    Route::patch('/services/promotions/{promotionId}/header', [AdminDashboardController::class, 'updatePromotionHeaderVisibility'])
        ->name('admin.services.promotions.dashboard_header');

    Route::delete('/services/promotions/{promotionId}', [AdminDashboardController::class, 'destroyPromotion'])
        ->name('admin.services.promotions.destroy');

    Route::get('/services/promotions/{promotionId}/print', [AdminDashboardController::class, 'printPromotion'])
        ->name('admin.services.promotions.print');

    // --- Scheduling ---
    Route::get('/scheduling', [AdminDashboardController::class, 'scheduling'])
        ->name('admin.scheduling');

    Route::post('/scheduling/shift', [AdminDashboardController::class, 'storeShift'])
        ->name('admin.scheduling.shifts.store');

    Route::post('/scheduling/publish', [AdminDashboardController::class, 'publishSchedule'])
        ->name('admin.scheduling.publish');

    Route::post('/scheduling/student-availability/approve', [AdminDashboardController::class, 'approveStudentAvailability'])
        ->name('admin.scheduling.student_availability.approve');

    Route::post('/scheduling/qr-confirm/{bookingId}', [AdminDashboardController::class, 'confirmQrAndAssign'])
        ->name('admin.scheduling.bookings.confirm');

    // --- Bookings ---
    Route::get('/bookings', [AdminDashboardController::class, 'bookings'])
        ->name('admin.bookings');

    Route::post('/bookings/{bookingId}/approve', [AdminDashboardController::class, 'approveBooking'])
        ->name('admin.bookings.approve');

    Route::patch('/bookings/{bookingId}/status', [AdminDashboardController::class, 'updateBookingStatus'])
        ->name('admin.bookings.status');

    Route::patch('/bookings/{bookingId}/payment', [AdminDashboardController::class, 'updateBookingPayment'])
        ->name('admin.bookings.payment');

    Route::patch('/bookings/{bookingId}/details', [AdminDashboardController::class, 'updateBookingDetails'])
        ->name('admin.bookings.details');

    Route::delete('/bookings/{bookingId}', [AdminDashboardController::class, 'destroyBooking'])
        ->name('admin.bookings.destroy');

    Route::post('/bookings/{bookingId}/qr-approve', [AdminDashboardController::class, 'approveQrBooking'])
        ->name('admin.bookings.qr.approve');

    // --- Reviews ---
    Route::get('/reviews', [AdminDashboardController::class, 'reviews'])
        ->name('admin.reviews');

    // --- Payments ---
    Route::get('/payments', [AdminDashboardController::class, 'payments'])
        ->name('admin.payments');
});

/*
|--------------------------------------------------------------------------
| STAFF AUTH
|--------------------------------------------------------------------------
*/
Route::middleware('guest:staff')->group(function () {
    Route::get('/staff/login', [StaffAuthController::class, 'create'])
        ->name('staff.login');

    Route::post('/staff/login', [StaffAuthController::class, 'store'])
        ->name('staff.login.store');
});

Route::post('/staff/logout', [StaffAuthController::class, 'destroy'])
    ->middleware('staff.only')
    ->name('staff.logout');

/*
|--------------------------------------------------------------------------
| STAFF DASHBOARD (protected by staff.only)
|--------------------------------------------------------------------------
*/
Route::middleware('staff.only')->prefix('staff')->group(function () {

    Route::get('/dashboard', [StaffDashboardController::class, 'index'])
        ->name('staff.dashboard');

    Route::get('/availability', [StaffAvailabilityController::class, 'index'])
        ->name('staff.availability');

    Route::post('/availability', [StaffAvailabilityController::class, 'store'])
        ->name('staff.availability.store');
});

/*
|--------------------------------------------------------------------------
| FALLBACK LOGIN / REGISTER
|--------------------------------------------------------------------------
| Default Laravel /login and /register go to customer auth pages.
*/
Route::get('/login', fn () => redirect()->route('customer.login'))->name('login');
Route::get('/register', fn () => redirect()->route('customer.signup'))->name('register');
