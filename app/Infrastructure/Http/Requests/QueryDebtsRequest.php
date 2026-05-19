<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Requests;

use App\Infrastructure\Http\Rules\PlateRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

final class QueryDebtsRequest extends FormRequest
{
    private const ALLOWED_KEYS = ['placa'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'placa' => ['required', 'string', new PlateRule],
        ];
    }

    /**
     * Strict mode: any key other than `placa` aborts the request with 422
     * before validation runs. Keeps the endpoint surface minimal and prevents
     * client typos from being silently ignored.
     */
    protected function prepareForValidation(): void
    {
        $extras = array_diff(array_keys($this->all()), self::ALLOWED_KEYS);

        if ($extras !== []) {
            throw new HttpResponseException(new JsonResponse([
                'error' => 'unknown_fields',
                'unknown_fields' => array_values($extras),
            ], 422));
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(new JsonResponse([
            'error' => 'validation_failed',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
