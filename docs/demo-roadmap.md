# Roteiro de demonstração — Debt Service

Guia de bolso pra demo ao vivo. Cobre os caminhos principais (happy path, validação, resiliência, runtime config, observabilidade, qualidade) + uma seção dedicada ao circuit breaker (com as limitações declaradas honestamente) + um plano narrativo sugerido.

---

## 0. Setup base (rodar uma vez)

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env` em estado "canon" (defaults do enunciado):

```env
APP_ENV=local
APP_DEBUG=true
PROVIDER_A_URL=http://127.0.0.1:8000/mock/provider-a
PROVIDER_B_URL=http://127.0.0.1:8000/mock/provider-b
# Demais vars: deixar comentadas para usar defaults canônicos
```

Servidor numa aba dedicada (mantém de pé durante toda a demo). Forma recomendada:

```bash
./docs/demo/run.sh
```

Esse script abre uma janela **nova** (Ghostty se instalado, senão Terminal.app) rodando o server + tracer; sua aba atual fica livre pros curls. Bônus: tee'a o trace em `storage/logs/demo-trace.log`, então dá pra "voltar" e mostrar o trace de um request anterior com `tail -n 50 storage/logs/demo-trace.log` ou `less -R` (cores preservadas).

Equivalente manual, sem o launcher:

```bash
composer dev                                   # apenas trace na janela
./docs/demo/server.sh                          # composer dev + tee pra arquivo
```

Por que `composer dev` (ou o wrapper) e não `php artisan serve` direto? Porque ele injeta `PHP_CLI_SERVER_WORKERS=4 --no-reload`, permitindo que o mesmo processo atenda `/api/debts/query` E o loopback `/mock/provider-{a,b}` em paralelo. Single-thread bloqueia a si mesmo e a chain estoura o budget. Detalhes em [CLAUDE.md §9](../CLAUDE.md).

Padrão de curl recomendado (mostra HTTP status sem quebrar o `jq`):

```bash
curl -sS -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}' \
  -w '%{stderr}\nHTTP %{http_code}\n' \
  | jq
```

`%{stderr}` manda o `HTTP <code>` pro stderr separado — o stdout vai limpo pro `jq`.

---

## 1. Demo HTTP — caminhos felizes e de validação

### 1.1 Happy path canônico (byte-a-byte do enunciado)

```bash
./docs/curls/happy-path.sh | jq
```

**O que destacar:**
- Valores monetários como **string** (`"2550.00"`, não `2550.00`). Invariante #9 da §4.
- `SOMENTE_IPVA` e `SOMENTE_MULTA` no **singular**. Armadilha #5 da §10.
- `cartao_credito` (não `cartao`). Decisão do gate da Feature 4.
- Parcelas 1/6/12 com PMT (2,5% a.m.).
- Pix com desconto de 5%.

### 1.2 Placa inválida → 422

```bash
./docs/curls/invalid-plate.sh
```

**O que destacar:**
- Resposta `validation_failed` com mensagem específica.
- Placa mascarada no log (LGPD). Veja §6 abaixo.
- 4xx **não** aciona retry no provider. Invariante #10.

---

## 2. Demo HTTP — resiliência

### 2.1 Fallback Provider A → Provider B

`.env`:

```env
PROVIDER_A_FAIL=500
```

Reinicia:

```bash
php artisan config:clear && composer dev
```

```bash
./docs/curls/happy-path.sh | jq
```

**No stderr:**
```
→ POST /api/debts/query placa=ABC1234
→ ProviderChain: trying 2 providers (budget=5.0s)
✗ Provider A failed: returned 500
✓ Provider B returned 2 debts
← 200 (total 0.29s)
```

Fallback é transparente — cliente recebe 200 normal.

### 2.2 Provider A com timeout (rede lenta simulada)

`.env`:

```env
PROVIDER_A_FAIL=timeout
PROVIDER_A_TIMEOUT=1      # encurta pra não esperar 2s
```

Resposta ainda 200, e o tracer mostra o timeout do A antes do fallback. O mock está com `StreamedResponse + connection_aborted`, então o worker libera assim que o adapter desiste.

### 2.3 Ambos providers fora → 503

```bash
./docs/curls/all-providers-down.sh
```

Ou manual:

```env
PROVIDER_A_FAIL=500
PROVIDER_B_FAIL=500
```

**O que destacar:**
- `503 all_providers_unavailable`.
- Ambos providers marcados como falha no tracer.
- O JSON de erro está padronizado (handler central em `bootstrap/app.php`).

---

## 3. Circuit breaker

### 3.1 Pest (canônico, determinístico) — recomendado

```bash
./vendor/bin/pest --filter=CircuitBreaker
./vendor/bin/pest --filter=ProviderChain
./vendor/bin/pest --filter=CircuitBreakerDebtProvider
```

**O que destacar:**
- `clock` injetável → testa cooldown sem `sleep`.
- `shouldRecordFailure` filtra: só `ProviderUnavailableException` trippa (placa inválida ou tipo desconhecido não).
- 1 breaker por provider — slow B nunca derruba A.
- Cooldown reseta `failureCount` ao expirar.

### 3.2 Tinker (in-process, estado acumula entre chamadas)

`.env`:

```env
PROVIDER_A_FAIL=500
PROVIDER_B_FAIL=500
PROVIDER_A_RETRIES=0
PROVIDER_B_RETRIES=0
CB_FAILURE_THRESHOLD=2
CB_COOLDOWN_SECONDS=30
```

Suba `composer dev` numa aba e, em outra:

```bash
php artisan tinker
```

```php
$p = app(\App\Application\Ports\DebtProvider::class);
$plate = \App\Domain\Plate\Plate::fromString('ABC1234');
for ($i = 1; $i <= 5; $i++) {
    try { $p->fetchDebts($plate); }
    catch (\Throwable $e) { echo "$i → ".class_basename($e).": ".$e->getMessage().PHP_EOL; }
}
```

**Esperado:**
- Hits 1–2: `AllProvidersUnavailableException: Provider A returned 500…`
- Hits 3+: `AllProvidersUnavailableException: Circuit breaker is open…`

Funciona porque tudo roda no mesmo processo PHP — o `failureCount` acumula entre as iterações do loop.

### 3.3 Demo HTTP do breaker (com `CB_STORE=file`)

Por padrão, o breaker é **per-process** (shared-nothing do PHP). Pra demo via curl, ligar o store em arquivo no `.env`:

```env
PROVIDER_A_FAIL=500
PROVIDER_B_FAIL=500
PROVIDER_A_RETRIES=0
PROVIDER_B_RETRIES=0
CB_FAILURE_THRESHOLD=2
CB_COOLDOWN_SECONDS=30
CB_STORE=file
```

`php artisan config:clear && composer dev`. Depois, na outra aba:

```bash
rm -f storage/app/circuit-breaker/*.json   # estado limpo
for i in 1 2 3 4; do
  curl -s -X POST http://localhost:8000/api/debts/query \
    -H 'Content-Type: application/json' \
    -d '{"placa":"ABC1234"}' | jq -r '.error_code'
done
```

**Esperado:**
- Hits 1–2: `all_providers_unavailable` por `500` dos providers (cada um falha 2x → tripa o respectivo breaker).
- Hits 3+: mesmo `error_code`, mas a causa interna passa a ser `Circuit breaker is open` (visível no tracer do `composer dev` em stderr).

Limpar pra próxima rodada: `rm storage/app/circuit-breaker/*.json` (ou esperar 30s do cooldown).

### 3.4 Discussão arquitetural

O `CB_STORE=file` é uma solução **single-instance**: usa `flock()` sobre `storage/app/circuit-breaker/<provider>.json`, então funciona pros workers do `php artisan serve` na mesma máquina mas **não** pra cluster (file locks são locais). O default segue `memory` (per-process) pra preservar comportamento dos testes byte-a-byte.

**Caminhos de evolução** (mencionar como conhecimento, sem implementar):
- **APCu via `Cache`** (single-instance sem I/O em disco — mais rápido que arquivo).
- **Laravel Octane** (FrankenPHP / Swoole / RoadRunner — mantém app vivo, singletons in-memory persistem entre requests).
- **Redis-backed `CircuitBreaker`** (cluster, com CAS pra atomicidade real).

Decisão registrada em [CLAUDE.md §11 #7](../CLAUDE.md) e no comentário do [CircuitBreaker.php](../app/Infrastructure/Resilience/CircuitBreaker.php).

---

## 4. Runtime config — mudanças sem refactor

Para cada item: edita `.env` → `php artisan config:clear` → `composer dev` → curl. Narrar: "se o avaliador me pedir X, é uma linha no `.env`".

| Pedido típico | Var | Default | Como mostrar |
|---|---|---|---|
| IPVA a 0,5%/dia | `IPVA_DAILY_RATE=0.005` | `0.0033` | happy-path com juros maiores |
| Teto IPVA 15% | `IPVA_CAP_RATE=0.15` | `0.20` | débito muito antigo bate o teto antes |
| Multa a 2%/dia | `MULTA_DAILY_RATE=0.02` | `0.01` | idem |
| Pix com 10% desc | `PIX_DISCOUNT_FACTOR=0.90` | `0.95` | `pix.total_com_desconto` muda |
| Cartão a 3% a.m. | `CC_MONTHLY_RATE=0.03` | `0.025` | parcelas 6x/12x sobem |
| Body limit 2MB | `HTTP_MAX_BODY_BYTES=2097152` | `1048576` | post grande passa |
| Budget chain 10s | `CHAIN_BUDGET_SECONDS=10` | `5.0` | combina com `PROVIDER_A_FAIL=timeout` |
| Provider A 5s timeout | `PROVIDER_A_TIMEOUT=5` | `2` | testa lentidão maior |
| Breaker mais sensível | `CB_FAILURE_THRESHOLD=2` | `5` | trippa antes |
| Cooldown maior | `CB_COOLDOWN_SECONDS=60` | `30.0` | breaker fica aberto mais tempo |
| Breaker compartilhado entre workers | `CB_STORE=file` | `memory` | tripa via curl (§3.3) |

São **15 knobs** em `config/business.php`, todos com defaults canônicos preservados. Listar a tabela completa por cima ([CLAUDE.md §0](../CLAUDE.md)).

Mudanças **estruturais** (novo tipo de débito, novo provider, mudar shape JSON) ainda exigem código. Guias rápidos em [CLAUDE.md §7](../CLAUDE.md).

---

## 5. Observabilidade — demo tracer

O `composer dev` em modo non-prod liga o tracer no stderr automaticamente. Durante toda a demo ele está rodando e mostra:

```
→ POST /api/debts/query placa=ABC1234
→ ProviderChain: trying 2 providers (budget=5.0s)
✗ Provider A failed: returned 500
✓ Provider B returned 2 debts
✓ calculated interest for 2 debts
✓ simulated payments: 3 options [TOTAL, SOMENTE_IPVA, SOMENTE_MULTA]
← 200 (total 0.29s)
```

**Pontos de discussão arquitetural:**
- Port `QueryTracer` em Application; adapter `DemoLogger` em Infrastructure → Application não conhece a impl.
- No-op em produção (flag `enabled` no construtor do `DemoLogger`).
- Canal `demo` em `config/logging.php`, separado dos logs normais.
- ANSI inline pra contornar o `<fg=gray>` que o `ServeCommand` injeta no stderr dos workers — armadilha #12 da [CLAUDE.md §10](../CLAUDE.md).

---

## 6. LGPD — mascaramento de placas

A placa em claro só pode aparecer no body da request. Em **qualquer canal de log** (tracer demo, error log) ela é mascarada.

Demonstração via tracer (caminho normal — `composer dev` ou `./docs/demo/run.sh`):

```bash
./docs/curls/happy-path.sh > /dev/null
tail -n 20 storage/logs/demo-trace.log | grep -E 'ABC|plac'
```

**Importante:** `storage/logs/laravel.log` **não** recebe nada no happy path — ele só guarda stack traces quando uma exception sobe pro handler. O tracer demo vai pro canal Monolog `demo` (stderr → tee → `demo-trace.log`). Ambos os canais têm `TapPlateMasking` aplicado globalmente em `config/logging.php`.

Se preferir provocar uma escrita no `laravel.log` pra mostrar a invariante, force um erro mapeado pelo handler:

```bash
./docs/curls/invalid-plate.sh > /dev/null      # 422 + log do handler
tail -n 5 storage/logs/laravel.log | grep -E 'INVALID|plac'
```

**O que mostrar:**
- Placa aparece mascarada (`ABC****` — 3 letras + 4 estrelas, ver [PlateMaskingProcessor.php](../app/Infrastructure/Logging/PlateMaskingProcessor.php#L23)), **nunca** `ABC1234` em claro.
- `PlateMaskingProcessor` é registrado globalmente via tap em `config/logging.php` → vale pra **todos** os canais, não opt-in.
- `TapPlateMasking` aceita `Monolog\Logger` E `Illuminate\Log\Logger` (armadilha #11 da [CLAUDE.md §10](../CLAUDE.md)) — sem isso, o tap silenciosamente caía no emergency logger e o mask nunca rodava.
- Invariante #8 da §4: LGPD por construção.

---

## 7. Qualidade — testes e estática

```bash
composer test                 # 207 passed em ~3s
composer test:coverage        # gate 100% em Domain + Application
./vendor/bin/pint --test      # estilo
```

**Discussão:**
- Test pyramid: Unit (Domain) → Unit (Application com fakes) → Feature (HTTP + `Http::fake`).
- Casos canônicos do enunciado em testes nomeados explicitamente (ex: `it('matches the enunciado canonical output for ABC1234')`).
- Coverage gate no CI para `app/Domain` + `app/Application`.
- Fakes em `tests/Support/Fakes/`, fixtures em `tests/Support/Fixtures/`.

---

## 8. Arquitetura — tour de 5min

Abrir, em ordem:

1. **`app/Domain/`** — mostrar que não tem nenhum `use Illuminate\…` nem `use App\Infrastructure\…`. Domain é PHP puro.
2. **`app/Application/Ports/DebtProvider.php`** — interface. Application orquestra Domain via ports; não conhece adapters.
3. **`app/Infrastructure/Providers/ProviderAJsonAdapter.php`** — adapter concreto que implementa a port. Aqui mora HTTP, retry, parsing.
4. **`app/Providers/AppServiceProvider.php`** — wiring: bindings, singletons, montagem da chain com 2 providers e 2 breakers isolados.
5. Apontar a regra: dependências só apontam **pra dentro** (Infra → App → Domain).

---

## 9. "E se o avaliador pedir…"

| Pergunta | Onde abrir | Tempo |
|---|---|---|
| "Como adiciono Provider C?" | [CLAUDE.md §7.1](../CLAUDE.md) | 5min |
| "Como adiciono tipo LICENCIAMENTO?" | [CLAUDE.md §7.2](../CLAUDE.md), `DebtType.php`, nova policy | 5min |
| "Como mudo a taxa pra X%?" | `.env` (runtime) ou `IpvaInterestPolicy` (estrutural) | 30s / 2min |
| "Por que decimal e não float?" | invariante #1 da §4 + `Money::__construct` | 1min |
| "E se mandar 100k débitos?" | `MaxBodySize` middleware + `HTTP_MAX_BODY_BYTES` | 1min |
| "Logs vazam dado?" | grep nos logs (§6 acima) | 30s |
| "Como você lida com falha em cluster?" | discussão de §3.3 + decisão [§11 #7](../CLAUDE.md) | 2min |
| "Como adiciono Pix parcelado?" | [CLAUDE.md §7.3](../CLAUDE.md) | 5min |
| "Posso passar a data de referência?" | [CLAUDE.md §7.5](../CLAUDE.md) | 3min |

---

## 10. Ordem narrativa sugerida (~15min)

1. **(2min)** Happy path → mostrar JSON + tracer.
2. **(2min)** Fallback A→B → invariante de resiliência transparente.
3. **(2min)** All providers down → 503 limpo.
4. **(2min)** Runtime config → mudar IPVA pra 0,5% ao vivo.
5. **(2min)** Pest do CircuitBreaker → prova de correção da lógica.
6. **(2min)** Arquitetura → tour rápido pelos 3 níveis.
7. **(2min)** LGPD + qualidade → grep nos logs + coverage 100%.
8. **(1min)** Roadmap → o que evoluiria (Redis-backed breaker, Octane, persistência) → mostra autoconsciência.

---

## 11. Caveats — declarar **antes** que perguntem

1. **Circuit breaker é in-memory por processo no default.** Pra compartilhar estado entre workers numa demo HTTP, ligar `CB_STORE=file` (ver §3.3) — usa `flock()` sobre `storage/app/circuit-breaker/*.json`, single-instance only. Pra cluster ainda precisa APCu ou Redis. Registrado em [CLAUDE.md §11 #7](../CLAUDE.md).
2. **`composer dev` precisa de `--no-reload`** pra honrar `PHP_CLI_SERVER_WORKERS=4`. Sem isso, o loopback dos mocks bloqueia a si mesmo. Detalhes em [CLAUDE.md §9](../CLAUDE.md).
3. **Mocks só fora de produção** — guard `! app()->isProduction()` em `routes/web.php`. Não vão pro deploy.
4. **Tracer é no-op em produção** — só ativa via canal `demo` quando `APP_ENV != production`.
5. **Casos do enunciado são byte-a-byte.** Os feature tests comparam bytes (não estruturas). Mudar o shape do JSON quebra esses testes — proposital.

---

## 12. Pegadinhas durante a demo

- **Estado sujo do `.env`** entre cenários. Antes de mudar de seção, conferir:
  ```bash
  grep -E '^(PROVIDER_[AB]_FAIL|CB_)' .env
  ```
  e limpar leftovers. `php artisan config:clear` sempre depois.
- **Curl pegando 500 sem mostrar erro:** se você esqueceu `-S` (maiúsculo), curl falha em silêncio e o `jq` recebe vazio. Usar o padrão recomendado em §0.
- **`jq` quebrando com `parse error`:** se o body não for JSON (HTML de error page do Laravel), `jq` estoura. Trocar por `curl -sS -i …` (sem `jq`) pra ver o body cru e diagnosticar.
- **Porta 8000 ocupada:** `composer dev` cai pra 8001 silenciosamente. Conferir o `INFO Server running on …` antes de bater curl. Para matar zumbi:
  ```bash
  lsof -ti :8000 | xargs kill -9
  ```
- **Tracer da chain não aparece** mas o request retorna 500 → suspeitar de erro fatal no `AppServiceProvider` (a closure do container nem chega a montar a chain). Olhar `storage/logs/laravel.log`.

---

## Fallback seguro

Se travar em qualquer ponto, sempre dá pra cair em:

> "Deixa eu te mostrar como isso funciona via teste."

```bash
./vendor/bin/pest --filter=<o-que-você-quer>
```

200+ testes verdes em 3s. É a rede de segurança.
