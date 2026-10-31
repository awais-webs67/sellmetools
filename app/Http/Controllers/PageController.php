<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    public function __invoke(string $page): View
    {
        return view("pages.{$page}");
    }
}
