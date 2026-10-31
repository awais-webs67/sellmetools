<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
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

        $guestOrders = (array) $request->session()->get('guest_orders', []);
        if ($guestOrders !== []) {
            Order::whereNull('user_id')->whereIn('number', $guestOrders)->update(['user_id' => Auth::id()]);
        }

        $request->session()->regenerate();

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
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

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
