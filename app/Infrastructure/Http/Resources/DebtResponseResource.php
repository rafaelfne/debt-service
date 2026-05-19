<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Resources;

use App\Application\UseCases\DebtQueryResult;
use App\Domain\Debt\UpdatedDebt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises `DebtQueryResult` into the canonical PT-BR JSON envelope.
 *
 * All monetary fields are emitted as JSON strings (via `Money::jsonSerialize`)
 * to preserve full decimal precision across the wire (CLAUDE.md §4 #9).
 *
 * @property DebtQueryResult $resource
 */
final class DebtResponseResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(DebtQueryResult $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @param  Request|null  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var DebtQueryResult $result */
        $result = $this->resource;

        return [
            'placa' => $result->plate->toString(),
            'debitos' => array_map(
                static fn (UpdatedDebt $debt): array => [
                    'tipo' => $debt->type->value,
                    'valor_original' => $debt->originalAmount,
                    'valor_atualizado' => $debt->updatedAmount,
                    'vencimento' => $debt->dueDate->format('Y-m-d'),
                    'dias_atraso' => $debt->daysOverdue,
                ],
                $result->debts,
            ),
            'resumo' => [
                'total_original' => $result->summary->totalOriginal,
                'total_atualizado' => $result->summary->totalUpdated,
            ],
            'pagamentos' => [
                'opcoes' => $result->paymentOptions,
            ],
        ];
    }
}
