<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
