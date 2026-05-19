#!/usr/bin/env bash
# Happy path: placa canon, retorna o envelope completo do enunciado.
# Pré-req: `php artisan serve` rodando na porta 8000.
set -euo pipefail

curl -s -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}' \
  | jq
