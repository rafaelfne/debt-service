<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Money;
use JsonSerializable;

final class PaymentOption implements JsonSerializable
{
    public function __construct(
        public readonly string $type,
        public readonly Money $baseAmount,
        public readonly PixPayment $pix,
        public readonly CreditCardPayment $creditCard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'tipo' => $this->type,
            'valor_base' => $this->baseAmount,
            'pix' => [
                'total_com_desconto' => $this->pix->totalComDesconto,
            ],
            'cartao_credito' => [
                'parcelas' => array_map(
                    fn (Installment $installment): array => [
                        'quantidade' => $installment->count,
                        'valor_parcela' => $installment->amount,
                    ],
                    $this->creditCard->installments,
                ),
            ],
        ];
    }
}
