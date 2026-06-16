<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectUnexpectedFieldsMiddleware
{
    private const ALLOWED = ['placa'];

    public function handle(Request $request, Closure $next): Response
    {
        $unexpected = array_values(array_diff(
            $request->keys(),
            self::ALLOWED,
        ));

        if (!empty($unexpected)) {
            return response()->json([
                'error'  => 'unexpected_fields',
                'fields' => $unexpected,
            ], 422);
        }

        return $next($request);
    }
}
