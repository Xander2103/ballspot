<?php

namespace App\Http\Controllers;

use App\Support\StorePlatform;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DownloadController extends Controller
{
    public function landing(): Response
    {
        return response()->view('public.landing', ['stores' => config('ballspot.stores')]);
    }

    /**
     * The link in every share message. The answer depends on the User-Agent,
     * so every outcome carries Vary, and the redirect is a temporary one that
     * nothing may store: a cached 302 would send the next device to the wrong
     * store.
     */
    public function download(Request $request): Response
    {
        $stores   = config('ballspot.stores');
        $platform = StorePlatform::fromUserAgent($request->userAgent());

        $response = $platform !== null
            ? redirect()->away($stores[$platform], 302)->header('Cache-Control', 'no-store, private')
            : response()->view('public.download', ['stores' => $stores]);

        return $response->header('Vary', 'User-Agent, Accept-Language');
    }
}
