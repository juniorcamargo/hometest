<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequestSizeLimitMiddleware
{
    private const MAX_BYTES = 1_048_576; // 1 MiB

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength !== null && (int) $contentLength > self::MAX_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        return $next($request);
    }
}
