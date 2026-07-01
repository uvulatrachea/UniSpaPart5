import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function StaffLogin() {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('staff.login.store'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout wide>
            <Head title="Staff Login" />

            <div className="min-h-screen bg-gradient-to-br from-unispa-surfaceAlt to-white px-3 py-6 sm:px-6 md:py-10">
                <div className="mx-auto w-full max-w-6xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                    <div className="grid min-h-[640px] grid-cols-1 lg:grid-cols-12">
                        {/* VISUAL PANEL */}
                        <div
                            className="relative hidden p-8 text-white lg:col-span-5 lg:flex lg:flex-col lg:justify-center lg:p-10"
                            style={{
                                backgroundImage: 'url("/images/spa-login-bg.jpg")',
                                backgroundSize: 'cover',
                                backgroundPosition: 'center',
                            }}
                        >
                            <div className="absolute inset-0 bg-gradient-to-br from-unispa-primary/20 to-unispa-primaryDark/80" />
                            <div className="relative z-10 h-full flex flex-col justify-center text-center">
                                <img src="/images/unispa-logo-gold.png" className="mx-auto mb-4 w-20 xl:w-24" alt="UniSpa Logo" />
                                <h1 className="text-3xl font-bold xl:text-4xl">UniSpa Staff</h1>
                                <p className="mt-4 text-sm opacity-90 xl:text-base">Authorized staff access portal</p>
                            </div>
                        </div>

                        {/* FORM PANEL */}
                        <div className="col-span-1 flex flex-col justify-center p-5 sm:p-8 md:p-10 lg:col-span-7 lg:p-12">
                            <div className="mb-7 text-center lg:hidden">
                                <img src="/images/unispa-logo-gold.png" className="mx-auto mb-2 w-14" alt="UniSpa Logo" />
                                <p className="text-xs uppercase tracking-[0.2em] text-unispa-subtle">Staff Portal</p>
                            </div>

                            <h2 className="mb-2 text-2xl font-bold sm:text-3xl">Staff Login</h2>
                            <p className="mb-6 text-sm text-unispa-subtle sm:text-base">Sign in to manage appointments and operations</p>

                            {/* BIG ERROR BOX */}
                            {(errors.email || errors.password) && (
                                <div className="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                                    <p className="font-semibold mb-1">Login failed</p>
                                    {errors.email && <p>- {errors.email}</p>}
                                    {errors.password && <p>- {errors.password}</p>}
                                </div>
                            )}

                            <form onSubmit={submit} className="space-y-5 sm:space-y-6">
                                <div>
                                    <InputLabel htmlFor="email" value="Staff Email" />
                                    <TextInput
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        className="mt-1 block w-full"
                                        autoComplete="username"
                                        error={errors.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="staff@unispa.com"
                                        required
                                    />
                                    <InputError message={errors.email} className="mt-1" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="password" value="Password" />
                                    <TextInput
                                        id="password"
                                        type="password"
                                        value={data.password}
                                        className="mt-1 block w-full"
                                        autoComplete="current-password"
                                        error={errors.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.password} className="mt-1" />
                                </div>

                                <div className="flex items-center">
                                    <Checkbox
                                        name="remember"
                                        checked={data.remember}
                                        onChange={(e) => setData('remember', e.target.checked)}
                                    />
                                    <span className="ml-2 text-sm">Remember me</span>
                                </div>

                                <PrimaryButton className="w-full py-3" disabled={processing}>
                                    {processing ? 'Signing in...' : 'Sign In'}
                                </PrimaryButton>
                            </form>

                            <div className="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-700 sm:text-sm">
                                Security notice: this portal is restricted to active Staff accounts only.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </GuestLayout>
    );
}
