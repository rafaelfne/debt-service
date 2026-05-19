#!/usr/bin/env bash
# Validação: placa fora do formato Mercosul/antigo → 422 validation_failed.
set -euo pipefail

curl -i -s -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"INVALID"}'
