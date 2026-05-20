#!/usr/bin/env bash
# Simula ambos providers indisponíveis apontando .env para hosts inexistentes
# antes de subir o servidor. Espera HTTP 503 all_providers_unavailable após
# ~6s (timeout 2s × 3 retries × 2 providers).
#
# Antes de rodar: (as variáveis serão exportadas apenas para o contexto do comando abaixo)
#   php artisan serve
set -euo pipefail

php artisan config:cache
php artisan config:clear

PROVIDER_A_FAIL=500 PROVIDER_B_FAIL=500 \
curl -i -s -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}'
