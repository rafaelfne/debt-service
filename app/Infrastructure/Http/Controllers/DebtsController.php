<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controllers;

use App\Application\UseCases\QueryDebtsUseCase;
use App\Domain\Plate\Plate;
use App\Infrastructure\Http\Requests\QueryDebtsRequest;
use App\Infrastructure\Http\Resources\DebtResponseResource;

/**
 * Single-action controller. Validates input via {@see QueryDebtsRequest},
 * runs the use case, returns the {@see DebtResponseResource}. No business
 * logic, no try/catch — exceptions propagate to the central handler (I8.4).
 */
final class DebtsController extends Controller
{
    public function __construct(
        private readonly QueryDebtsUseCase $useCase,
    ) {}

    public function __invoke(QueryDebtsRequest $request): DebtResponseResource
    {
        $plate = Plate::fromString((string) $request->validated('placa'));
        $result = $this->useCase->execute($plate);

        return new DebtResponseResource($result);
    }
}
