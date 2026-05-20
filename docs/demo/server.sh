#!/usr/bin/env bash
# Sobe o servidor da demo + tee'a o trace do tracer demo para
# storage/logs/demo-trace.log (sobrescrito a cada execução).
#
# Por que existe: o `composer dev` joga os traços (→, ✓, ✗, ⚡, ←) só no
# stderr da própria janela. Se o avaliador pedir "mostra o trace do request
# anterior" três curls depois, scroll na janela é frágil. Com tee, dá pra
# rodar `tail -n 80 storage/logs/demo-trace.log` de qualquer aba.
#
# `--ansi` força Symfony Console a manter SGR codes mesmo com stdout não-TTY
# (sem isso, o pipe pelo tee derruba as cores do tracer — armadilha #12 da
# CLAUDE.md §10).
set -euo pipefail

cd "$(dirname "$0")/../.."

mkdir -p storage/logs
TRACE_FILE="storage/logs/demo-trace.log"
: > "$TRACE_FILE"

echo "→ demo trace → $TRACE_FILE"
echo "→ servidor → http://localhost:8000"
echo

# Belt-and-suspenders mask: o PlateMaskingProcessor já mascara o que passa
# pelo Monolog, mas o próprio `php artisan serve` emite uma linha de access
# log por request (`/mock/provider-a/debts/ABC1234 ~ 0.11ms`) direto no
# stderr — fora do Monolog. Esse filtro perl pega as duas pontas (acesso +
# qualquer trace que escape) ANTES de chegar no tee/terminal. `$|=1` força
# autoflush pra manter o stream ao vivo. Mesmo regex do processor.
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload --ansi 2>&1 \
  | perl -pe 'BEGIN{$|=1} no warnings "experimental::vlb"; s/(?:\b|(?<=\033\[[\d;]{0,16}m))([A-Za-z]{3})[0-9][A-Za-z0-9][0-9]{2}\b/uc($1)."****"/eg' \
  | tee "$TRACE_FILE"
