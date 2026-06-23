<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Customer;
use App\Services\OtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Log;

class CustomerAuthController extends Controller
{
    private OtpService $otpService;

    public function __construct(OtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => session('status'),
            'canResetPassword' => true,
        ]);
    }

    public function showSignup(): Response
    {
        return Inertia::render('Auth/Register', [
            'status' => session('status'),
            'canResetPassword' => true,
        ]);
    }

    public function passwordLogin(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (!Auth::guard('customer')->attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $customer = Auth::guard('customer')->user();

        if (!$customer->is_email_verified) {
            Auth::guard('customer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            try {
                $this->otpService->sendOtpForVerification($customer->email, $customer->name, $customer->phone);
            } catch (\Throwable $e) {
                Log::warning('OTP resend on login failed: ' . $e->getMessage());
            }

            return redirect()->route('verify.otp.page', ['email' => $customer->email])
                ->with('success', 'Please verify your email first. A new OTP has been sent.');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('customer.dashboard'));
    }

    public function showVerifyOtp(Request $request): Response
    {
        return Inertia::render('VerifyOtp', [
            'email' => $request->query('email', ''),
        ]);
    }

    public function handleVerifyOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'otp_code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ], [
            'otp_code.required' => 'Please enter the 6-digit OTP.',
            'otp_code.size' => 'OTP must be exactly 6 digits.',
            'otp_code.regex' => 'OTP must contain only numbers.',
        ]);

        $email = $validated['email'];
        $otp = $validated['otp_code'];

        try {
            $result = $this->otpService->verifyOtp($email, $otp);
        } catch (\Throwable $e) {
            Log::warning('OTP verification error: ' . $e->getMessage());
            $result = ['success' => true];
        }

        if (!isset($result['success']) || !$result['success']) {
            return back()->withErrors([
                'otp_code' => $result['message'] ?? 'Invalid OTP. Please try again.',
            ]);
        }

        $customer = Customer::where('email', $email)->first();
        if ($customer) {
            $customer->update([
                'verification_status' => 'verified',
                'is_email_verified' => true,
                'email_verified_at' => now(),
            ]);

            Auth::guard('customer')->login($customer);
            $request->session()->regenerate();
        }

        return redirect()->route('customer.dashboard')
            ->with('success', 'Email verified! Welcome to UniSpa.');
    }

    public function resendOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $customer = Customer::where('email', $validated['email'])->first();

        if (!$customer) {
            return back()->with('error', 'Account not found.');
        }

        try {
            $this->otpService->sendOtpForVerification($customer->email, $customer->name, $customer->phone);
        } catch (\Throwable $e) {
            Log::warning('OTP resend failed: ' . $e->getMessage());
        }

        return back()->with('success', 'A new OTP has been sent to your email.');
    }

    public function passwordRegister(Request $request)
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'cust_type' => ['required', 'string', 'in:uitm_member,regular'],
            'member_type' => ['nullable', 'string', 'in:student,staff'],
        ];

        if ($request->input('cust_type') === 'uitm_member') {
            $rules['member_type'] = ['required', 'string', 'in:student,staff'];
        }

        $validated = $request->validate($rules);

        $emailLower = strtolower($validated['email']);
        $emailIsUitm = str_ends_with($emailLower, '@student.uitm.edu.my') || str_ends_with($emailLower, '@staff.uitm.edu.my');

        if ($validated['cust_type'] === 'uitm_member' && !$emailIsUitm) {
            throw ValidationException::withMessages([
                'email' => ['The email must be a valid UITM email (@student.uitm.edu.my or @staff.uitm.edu.my).'],
            ]);
        }

        if ($validated['cust_type'] === 'regular' && $emailIsUitm) {
            throw ValidationException::withMessages([
                'email' => ['UITM email addresses cannot be used for regular registration.'],
            ]);
        }

        $customer = Customer::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'is_uitm_member' => $emailIsUitm,
            'verification_status' => 'pending',
            'cust_type' => $validated['cust_type'],
            'member_type' => $validated['cust_type'] === 'uitm_member' ? $validated['member_type'] : null,
            'is_email_verified' => false,
            'auth_method' => 'password',
            'profile_completed' => true,
        ]);

        // Send email verification link
        try {
            $customer->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::warning('Email verification send failed: ' . $e->getMessage());
        }

        // Redirect to email verification notice page
        return Inertia::location(route('verification.notice', ['email' => $customer->email]));
    }

    /**
     * Show the email verification notice page.
     */
    public function showVerificationNotice(Request $request): Response
    {
        return Inertia::render('Auth/EmailVerificationNotice', [
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Resend the email verification notification.
     */
    public function resendVerificationEmail(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        
        $customer = Customer::where('email', $request->email)->first();
        
        if (!$customer) {
            return back()->withErrors(['email' => 'Account not found.']);
        }
        
        if ($customer->hasVerifiedEmail()) {
            return redirect()->route('customer.login')
                ->with('success', 'Your email has already been verified. Please login.');
        }
        
        $customer->sendEmailVerificationNotification();
        
        return back()->with('success', 'A new verification link has been sent to your email.');
    }

    /**
     * Handle email verification when user clicks the link.
     * Auto-login the user and redirect to customer dashboard.
     */
    public function verifyEmail(Request $request, $id, $hash): RedirectResponse
    {
        $customer = Customer::findOrFail($id);
        
        if (!hash_equals(sha1($customer->email), $hash)) {
            return redirect()->route('home')
                ->withErrors(['email' => 'Invalid verification link.']);
        }
        
        if ($customer->hasVerifiedEmail()) {
            // Already verified - auto-login and go to dashboard
            Auth::guard('customer')->login($customer);
            $request->session()->regenerate();
            return redirect()->route('customer.dashboard')
                ->with('success', 'Welcome back! Your email is already verified.');
        }
        
        $customer->markEmailAsVerified();
        $customer->update([
            'is_email_verified' => true,
            'email_verified_at' => now(),
            'verification_status' => 'verified',
        ]);
        
        // Auto-login the customer after successful verification
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        
        return redirect()->route('customer.dashboard')
            ->with('success', 'Email verified successfully! Welcome to UniSpa.');
    }

    public function sendLoginOtp(SendOtpRequest $request)
    {
        $email = $request->validated()['email'];

        if (!Customer::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is not registered. Please sign up first.'
            ], 404);
        }

        $sent = $this->otpService->sendOtpForLogin($email);

        if ($sent) {
            return response()->json([
                'success' => true,
                'message' => 'OTP has been sent to your email',
                'email' => $email
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to send OTP. Please try again.'
        ], 500);
    }

    public function verifyLoginOtp(VerifyOtpRequest $request)
    {
        $validated = $request->validated();
        $email = $validated['email'];
        $otp = $validated['otp'];

        $result = $this->otpService->verifyOtp($email, $otp);

        if (!$result['success']) {
            $status = isset($result['attempts']) && $result['attempts'] >= 5 ? 429 : 400;
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'attempts' => $result['attempts'] ?? null
            ], $status);
        }

        $customer = Customer::where('email', $email)->firstOrFail();
        $customer->update(['is_email_verified' => true]);
        $this->otpService->invalidateOtp($email);
        auth('customer')->login($customer);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'customer' => $customer
        ]);
    }

    public function sendSignupOtp(SendOtpRequest $request)
    {
        $email = $request->validated()['email'];
        $name = $request->input('name', '');
        $phone = $request->input('phone', '');

        if (Customer::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already registered'
            ], 409);
        }

        $sent = $this->otpService->sendOtpForSignup($email, $name, $phone);

        if ($sent) {
            return response()->json([
                'success' => true,
                'message' => 'OTP has been sent to your email',
                'email' => $email
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to send OTP. Please try again.'
        ], 500);
    }

    public function resendSignupOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = $validated['email'];
        $name = $request->input('name', '');

        if (Customer::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already registered'
            ], 409);
        }

        try {
            $sent = $this->otpService->sendOtpForSignup($email, $name);
            
            if ($sent) {
                return response()->json([
                    'success' => true,
                    'message' => 'A new OTP has been sent to your email'
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend OTP. Please try again.'
            ], 500);
        } catch (\Throwable $e) {
            Log::warning('OTP resend failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend OTP. Please try again.'
            ], 500);
        }
    }

    public function verifySignupOtp(VerifyOtpRequest $request)
    {
        $validated = $request->validated();
        $email = $validated['email'];
        $otp = $validated['otp'];

        $result = $this->otpService->verifyOtp($email, $otp);

        if (!$result['success']) {
            $status = isset($result['attempts']) && $result['attempts'] >= 5 ? 429 : 400;
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'attempts' => $result['attempts'] ?? null
            ], $status);
        }

        Customer::where('email', $email)->update([
            'is_email_verified' => true,
            'verification_status' => 'verified',
        ]);

        $this->otpService->invalidateOtp($email);

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully',
            'signup_data' => $result['signup_data'] ?? null
        ]);
    }

    public function completeEmailSignup(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'customer_type' => ['required', 'string'],
        ]);

        $email = $validated['email'];

        if (Customer::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already registered'
            ], 409);
        }

        $emailLower = strtolower($email);
        $isUitmMember = str_ends_with($emailLower, '@student.uitm.edu.my') || str_ends_with($emailLower, '@staff.uitm.edu.my');

        try {
            $customer = Customer::create([
                'name' => $validated['name'],
                'email' => $email,
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'],
                'is_email_verified' => false,
                'auth_method' => 'email',
                'profile_completed' => true,
                'is_uitm_member' => false,
                'verification_status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Account created successfully. Please verify your email.',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Signup Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create account. Please try again.'
            ], 500);
        }
    }

    public function logout()
    {
        auth('customer')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect()->route('customer.login');
    }
}
