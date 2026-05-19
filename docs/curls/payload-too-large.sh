#!/usr/bin/env bash
# MaxBodySize middleware: body > 1 MiB → 413 payload_too_large.
set -euo pipefail

payload=$(printf '{"placa":"ABC1234","noise":"%*s"}' 1048600 x)

curl -i -s -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d "${payload}"
