#!/usr/bin/env bash
# Simula ambos providers indisponíveis apontando .env para hosts inexistentes
# antes de subir o servidor. Espera HTTP 503 all_providers_unavailable após
# ~6s (timeout 2s × 3 retries × 2 providers).
#
# Antes de rodar:
#   export PROVIDER_A_URL=http://127.0.0.1:9999/down
#   export PROVIDER_B_URL=http://127.0.0.1:9999/down
#   php artisan serve
set -euo pipefail

curl -i -s -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}'
