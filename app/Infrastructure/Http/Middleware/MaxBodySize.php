<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests whose body exceeds `$maxBytes` (default 1 MiB) with HTTP 413.
 *
 * Reads Content-Length when available, falls back to `strlen($request->getContent())`
 * if the client didn't send a header. The fallback opens the body only once and
 * does not consume the FormRequest's later parsing because Symfony caches it.
 */
final class MaxBodySize
{
    public const DEFAULT_MAX_BYTES = 1_048_576; // 1 MiB

    public function handle(Request $request, Closure $next, ?int $maxBytes = null): Response
    {
        $limit = $maxBytes ?? self::DEFAULT_MAX_BYTES;

        $declared = (int) ($request->headers->get('Content-Length') ?? 0);
        $size = $declared > 0 ? $declared : strlen((string) $request->getContent());

        if ($size > $limit) {
            return new JsonResponse([
                'error' => 'payload_too_large',
                'max_bytes' => $limit,
                'received_bytes' => $size,
            ], 413);
        }

        return $next($request);
    }
}
