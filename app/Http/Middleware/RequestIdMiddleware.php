<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID') ?? Str::uuid()->toString();

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
