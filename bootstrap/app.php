<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Cloudflare / hosting load balancers: trust forwarded proto & client IP.
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, Request $request) {
            if ($request->isMethod('GET') && ($request->is('livewire*') || str_contains($request->path(), 'livewire'))) {
                if ($request->hasSession() && str_contains((string) $request->session()->get('url.intended', ''), 'livewire')) {
                    $request->session()->forget('url.intended');
                }

                $referer = (string) $request->headers->get('referer', '');
                if ($referer !== '' && ! str_contains($referer, 'livewire')) {
                    return redirect()->to($referer);
                }

                return redirect()->to('/admin');
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->hasHeader('X-Livewire') || $request->is('livewire*') || str_contains($request->path(), 'livewire')) {
                $referer = (string) $request->headers->get('referer', '');
                $target = ($referer !== '' && ! str_contains($referer, 'livewire')) ? $referer : url('/admin');
                if (preg_match('#/admin/([^/]+)/[^/]+$#', $target, $m)) {
                    $target = url('/admin/'.$m[1]);
                }

                return redirect()->to($target);
            }
        });
    })->create();
