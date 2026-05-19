<?php

declare(strict_types=1);

namespace App\Infrastructure\Mocks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ProviderCMockController
{
    public function show(Request $request, string $plate): HttpResponse
    {
        if ($failure = $this->simulateFailure($request)) {
            return $failure;
        }

        $path = __DIR__."/fixtures/provider-c-{$plate}.csv";
        if (! is_file($path)) {
            return new Response("error,plate not found\n", 404, [
                'Content-Type' => 'text/csv',
            ]);
        }

        $body = (string) file_get_contents($path);

        return new Response($body, 200, ['Content-Type' => 'text/csv']);
    }

    private function simulateFailure(Request $request): ?HttpResponse
    {
        $fail = $request->query('fail');

        if ($fail === 'timeout') {
            sleep(10);

            return new Response("ok\n", 200, ['Content-Type' => 'text/csv']);
        }

        if ($fail === '500') {
            return new JsonResponse(['error' => 'simulated upstream failure'], 500);
        }

        return null;
    }
}
