<?php

declare(strict_types=1);

namespace App\Infrastructure\Mocks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ProviderBMockController
{
    public function show(Request $request, string $plate): HttpResponse
    {
        if ($failure = $this->simulateFailure($request)) {
            return $failure;
        }

        $path = __DIR__."/fixtures/provider-b-{$plate}.xml";
        if (! is_file($path)) {
            return new Response('<?xml version="1.0"?><error>plate not found</error>', 404, [
                'Content-Type' => 'application/xml',
            ]);
        }

        $body = (string) file_get_contents($path);

        return new Response($body, 200, ['Content-Type' => 'application/xml']);
    }

    private function simulateFailure(Request $request): ?HttpResponse
    {
        $fail = $request->query('fail');

        if ($fail === 'timeout') {
            sleep(10);

            return new Response('<?xml version="1.0"?><ok/>', 200, [
                'Content-Type' => 'application/xml',
            ]);
        }

        if ($fail === '500') {
            return new JsonResponse(['error' => 'simulated upstream failure'], 500);
        }

        return null;
    }
}
