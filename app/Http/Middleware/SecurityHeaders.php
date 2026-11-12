<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower((string) $request->getHost());
        if (str_starts_with($host, 'www.')) {
            $nonWwwHost = substr($host, 4);
            $targetUrl = 'https://'.$nonWwwHost.$request->getRequestUri();

            return redirect()->to($targetUrl, 301);
        }

        if ($request->isMethod('GET') && $request->query->has('seed')) {
            $query = $request->query->all();
            unset($query['seed']);
            if (($query['page'] ?? null) === '1' || ($query['page'] ?? null) === 1 || $request->getPathInfo() === '/') {
                unset($query['page']);
            }
            $cleanUrl = $request->url().($query !== [] ? '?'.http_build_query($query) : '');

            return redirect()->to($cleanUrl, 301);
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
