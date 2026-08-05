<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PublicController extends Controller
{
    public function privacy(): View
    {
        return view('public.privacy', [
            'supportEmail' => config('ballspot.support_email'),
            'webUrl'       => config('ballspot.web_url'),
            'minimumAge'   => config('ballspot.legal.minimum_age'),
        ]);
    }

    public function terms(): View
    {
        return view('public.terms', [
            'supportEmail' => config('ballspot.support_email'),
            'minimumAge'   => config('ballspot.legal.minimum_age'),
        ]);
    }

    public function support(): View
    {
        return view('public.support', [
            'supportEmail' => config('ballspot.support_email'),
        ]);
    }
}
