<?php

namespace App\Http\Controllers;

use App\Services\EmailNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __invoke(string $page): View
    {
        return view("pages.{$page}");
    }

    public function submitContact(Request $request, EmailNotifier $mailer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:35'],
            'order_number' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:140'],
            'message' => ['required', 'string', 'min:10', 'max:2500'],
        ]);

        $mailer->sendContactMessage($data);

        return redirect()
            ->route('pages.show', 'contact')
            ->with('contact_sent', 'Thank you! Your message has been sent to our support desk. We will reply to '.$data['email'].' shortly.');
    }
}
