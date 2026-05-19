#!/usr/bin/env bash
# Strict mode: campo extra no body → 422 unknown_fields antes da validação.
set -euo pipefail

curl -i -s -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234","extra":"noise","attack":true}'
