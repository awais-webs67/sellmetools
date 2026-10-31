<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function orders(Request $request): View
    {
        $orders = $request->user()->orders()
            ->with(['items.deliveries', 'paymentMethod'])
            ->latest()
            ->paginate(15);

        return view('account.orders', compact('orders'));
    }
}
