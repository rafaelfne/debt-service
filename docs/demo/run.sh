#!/usr/bin/env bash
# Layout de demo em 2 janelas:
#   • Janela nova (Ghostty se disponível, senão Terminal.app): roda
#     `docs/demo/server.sh` — server + tracer ao vivo, tee em
#     storage/logs/demo-trace.log.
#   • Esta janela (a que você usou pra rodar o script): livre pros curls.
#
# Uso:
#   ./docs/demo/run.sh                 # abre janela do server
#   ./docs/demo/run.sh --stop          # mata o que estiver na porta 8000
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SERVER_SH="$ROOT/docs/demo/server.sh"

if [[ "${1:-}" == "--stop" ]]; then
  pids="$(lsof -ti :8000 2>/dev/null || true)"
  if [[ -n "$pids" ]]; then
    echo "→ matando PIDs na :8000: $pids"
    kill -9 $pids
  else
    echo "→ porta 8000 livre"
  fi
  exit 0
fi

if lsof -ti :8000 >/dev/null 2>&1; then
  echo "✗ porta 8000 já ocupada. rode \`$0 --stop\` antes." >&2
  exit 1
fi

if [[ -d /Applications/Ghostty.app ]]; then
  echo "→ abrindo Ghostty com server..."
  open -na Ghostty.app --args -e "$SERVER_SH"
else
  echo "→ abrindo Terminal.app com server..."
  osascript <<APPLESCRIPT
tell application "Terminal"
    activate
    do script "$SERVER_SH"
end tell
APPLESCRIPT
fi

cat <<EOF

✓ Janela do server aberta. Esta aba está livre pros curls.
  (Aguarde a outra janela mostrar 'INFO Server running on http://127.0.0.1:8000' antes do primeiro curl.)

  Curls (happy-path já termina em jq; os outros mostram headers via curl -i):
    ./docs/curls/happy-path.sh
    ./docs/curls/invalid-plate.sh
    ./docs/curls/all-providers-down.sh

  Inspecionar o trace (mesmo dado da janela do server, agora persistido):
    tail -n 50 storage/logs/demo-trace.log
    grep -E 'ABC|plac' storage/logs/demo-trace.log
    less -R storage/logs/demo-trace.log
    tail -f storage/logs/demo-trace.log

  Parar tudo:
    $0 --stop
EOF
