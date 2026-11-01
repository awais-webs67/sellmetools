<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\EmailNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'These credentials do not match our records.']);
        }

        /** @var User $user */
        $user = Auth::user();
        if (! ($user->is_active ?? true)) {
            Auth::logout();

            return back()->withInput($request->only('email'))->withErrors(['email' => 'Your account has been suspended. Please contact support.']);
        }

        $guestOrders = (array) $request->session()->get('guest_orders', []);
        if ($guestOrders !== []) {
            Order::whereNull('user_id')->whereIn('number', $guestOrders)->update(['user_id' => $user->id]);
        }

        $request->session()->regenerate();

        if ($user->is_reseller && filled($user->reseller_slug)) {
            return redirect()->intended(route('reseller.portal', $user->reseller_slug));
        }

        return redirect()->intended(route('account.orders'));
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $normalizedPhone = format_whatsapp($data['phone']);
        if (! preg_match('/^923\d{9}$/', $normalizedPhone) && ! preg_match('/^\d{10,15}$/', $normalizedPhone)) {
            throw ValidationException::withMessages([
                'phone' => 'Please enter a valid WhatsApp number in exact format (e.g. 923001234567 or 03001234567).',
            ]);
        }
        $data['phone'] = $normalizedPhone;

        $user = User::create($data);

        $guestOrders = (array) $request->session()->get('guest_orders', []);
        if ($guestOrders !== []) {
            Order::whereNull('user_id')->whereIn('number', $guestOrders)->update(['user_id' => $user->id]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('account.orders')->with('success', 'Welcome! Your account is ready.');
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->input('email'))->first();
        if (! $user) {
            return back()->with('success', 'If your email is registered with us, a password reset link has been sent to your inbox!');
        }

        $token = PasswordBroker::broker()->createToken($user);
        $sent = app(EmailNotifier::class)->sendPasswordReset($user, $token);

        if ($sent) {
            return back()->with('success', 'A password reset link has been sent to your email! Please check your inbox.');
        }

        $wa = admin_whatsapp_number();
        $fallbackMsg = 'Email service is currently offline. ';
        if ($wa !== '') {
            $fallbackMsg .= "Please message our support on WhatsApp (+{$wa}) with your email to reset your password immediately.";
        } else {
            $fallbackMsg .= 'Please contact store support to reset your password.';
        }

        return back()->with('error', $fallbackMsg);
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $status = PasswordBroker::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();
            }
        );

        if ($status === PasswordBroker::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', 'Your password has been reset successfully! You can now sign in.');
        }

        return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
