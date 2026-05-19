# Conhecimento geral do projeto — Debt Service

Documento autocontido para alimentar o NotebookLM. O objetivo é dar uma visão **arquitetural** completa do serviço, incluindo decisões, trade-offs e perguntas frequentes que um avaliador pode fazer numa entrevista técnica ou code review.

Companheiros desse documento (não duplicados aqui, leitura recomendada):
- `CLAUDE.md` — guia operacional para a IA assistente, com cheat sheet de demo, invariantes numeradas e mapa de arquivos.
- `docs/demo-roadmap.md` — roteiro narrativo da demo ao vivo (comandos, ordem, caveats).

---

## 1. Visão executiva

### 1.1 O que é o serviço

API HTTP em PHP/Laravel que expõe um único endpoint: `POST /api/debts/query`. Dada uma placa de veículo, o serviço:

1. Valida a placa (formato Mercosul ou antigo).
2. Consulta dois provedores externos em cascata (Provider A em JSON e Provider B em XML) com fallback transparente em caso de indisponibilidade.
3. Calcula juros sobre cada débito em atraso aplicando políticas distintas por **tipo** (IPVA com teto, MULTA sem teto).
4. Simula formas de pagamento (Pix com desconto fixo e Cartão de Crédito em 1x/6x/12x via PMT).
5. Agrupa em **opções**: `TOTAL` (sempre) + `SOMENTE_<TIPO>` por tipo distinto presente na lista.
6. Retorna um JSON canônico onde **todo valor monetário é string com 2 casas decimais**.

### 1.2 Restrições não-funcionais inegociáveis

| # | Restrição | Motivação |
|---|---|---|
| R1 | Precisão decimal end-to-end (sem `float` em qualquer ponto monetário) | `0.1 + 0.2 ≠ 0.3` em IEEE 754; resposta é byte-a-byte com o enunciado. |
| R2 | Resiliência multi-provider transparente | Falha de um provider não pode degradar a UX nem o status HTTP. |
| R3 | Domínio puro (sem dependência de Laravel/HTTP/JSON/XML) | Testabilidade, longevidade, mobilidade entre frameworks. |
| R4 | LGPD — placas mascaradas em todos os logs | Compliance por construção, não opt-in. |
| R5 | Determinismo da resposta para os casos canônicos do enunciado | Test suite valida byte-a-byte; mudanças de shape são intencionais. |

### 1.3 Estado atual

- **10 épicos / 38 sub-issues** fechados (PRs #50–#94 + PR #100 de runtime config).
- **207 testes / ~421 assertions** verdes em ~3s.
- **Coverage gate de 100%** no CI para `app/Domain` + `app/Application`.
- **14 knobs de runtime** em `config/business.php` controláveis por `.env` (taxas, tetos, timeouts, thresholds do breaker, body limit, budget da chain etc.).

---

## 2. Stack & dependências

### 2.1 Tecnologias

| Camada | Tecnologia | Por quê |
|---|---|---|
| Linguagem | PHP 8.2+ | Enums nativos, `readonly` properties, `new` em expressions. |
| Framework HTTP | Laravel 11 | Foundation pragmática (routing, container, validation, Http client). Mantida na borda. |
| Aritmética decimal | `brick/math` (`BigDecimal`) | Precisão arbitrária, immutable, domain-grade. |
| Parser JSON | `pcrov/jsonreader` | Streaming + preserva tokens raw (evita virar `float` no `json_decode`). |
| Parser XML | SimpleXML nativo + cast `(string)` | Já é parte do PHP; sem dep extra. |
| Testes | Pest 3 | Sintaxe declarativa; integração nativa com Laravel. |
| Static analysis | (opcional) PHPStan | Configurado, não obrigatório no CI. |
| Style | Laravel Pint | Padrão da comunidade. |

### 2.2 O que NÃO foi adicionado (e por quê)

- **Lib de resilience dedicada** (Resilience4PHP etc.) — `Http::retry` + `CircuitBreaker` próprio bastam pro escopo.
- **Persistência** (DB/cache) — fora do escopo; serviço é puro request/response.
- **Filas/eventos** — síncrono é suficiente; nenhum efeito colateral.
- **Tracing distribuído** (OpenTelemetry) — substituído por tracer ad-hoc em stderr pra demo.

Adicionar qualquer dependência nova exige aprovação prévia (CLAUDE.md §12).

---

## 3. Arquitetura

### 3.1 Hexagonal, 3 camadas

```
Infrastructure  →  Application  →  Domain
   (adapters)        (use cases,        (regras puras,
   HTTP, providers,  ports/interfaces)  VOs, policies)
   logging, resilience)
```

Regra única e absoluta de dependência: **setas só apontam pra dentro**. Concretamente:

- `App\Domain\*` **não** importa nada de `Application` nem de `Infrastructure`.
- `App\Application\*` **não** importa de `Infrastructure`.
- `App\Infrastructure\*` pode importar de qualquer lugar.

Violar isso é bug arquitetural e deve ser refatorado extraindo uma **port** (interface) na Application.

### 3.2 Mapeamento PSR-4

| Namespace | Diretório |
|---|---|
| `App\Domain\` | `app/Domain/` |
| `App\Application\` | `app/Application/` |
| `App\Infrastructure\` | `app/Infrastructure/` |

### 3.3 Composição (alto nível)

```
HTTP Request
   ↓
DebtsController (Infra/Http)
   ↓
QueryDebtsRequest → PlateRule → Plate VO (Domain)
   ↓
QueryDebtsUseCase (Application)
   ├─→ DebtProvider port (Application)
   │      └─ ProviderChain (Infra/Resilience)
   │            ├─ CircuitBreakerDebtProvider(ProviderAJsonAdapter)
   │            └─ CircuitBreakerDebtProvider(ProviderBXmlAdapter)
   ├─→ InterestCalculator (Domain)
   │      ├─ IpvaInterestPolicy
   │      └─ MultaInterestPolicy
   └─→ PaymentSimulator (Domain)
          ├─ PixSimulator
          └─ CreditCardSimulator
   ↓
DebtResponseResource (Infra/Http) → JSON
```

### 3.4 Ports & Adapters (interfaces de borda)

| Port (Application) | Adapter(s) concretos (Infrastructure) |
|---|---|
| `DebtProvider` | `ProviderAJsonAdapter`, `ProviderBXmlAdapter`, `CircuitBreakerDebtProvider` (decorator), `ProviderChain` (composite) |
| `QueryTracer` | `DemoLogger` (canal Monolog `demo` em stderr); no-op em produção |

---

## 4. Domínio detalhado

### 4.1 Value Objects

| VO | Propósito | Restrições |
|---|---|---|
| `Money` | Encapsular valor monetário decimal | Construído **só** a partir de `string`; aceita `float` lança `InvalidArgumentException`. Internamente `BigDecimal`. Imutável. Arredondamento HALF_UP **apenas** em `toString()`/`jsonSerialize()`. |
| `Plate` | Placa veicular | Regex `\b([A-Z]{3})[0-9][A-Z0-9][0-9]{2}\b` cobre Mercosul e antigo. `fromString()` normaliza pra UPPER. |

### 4.2 Entidades

| Entidade | Propósito |
|---|---|
| `Debt` | Débito bruto vindo do provider: tipo (`DebtType`), `valor_original` (`Money`), `vencimento` (`DateTimeImmutable` UTC). |
| `UpdatedDebt` | Snapshot pós-cálculo: débito + juros + valor atualizado + dias em atraso. |

Todas `final`, todas com propriedades `readonly`. Zero setters.

### 4.3 Tipos de débito

Enum fechado `DebtType` com **dois** cases: `IPVA` e `MULTA`. Qualquer outro valor vindo do provider lança `UnknownDebtTypeException` → HTTP 422 (e **não** aciona fallback). Adicionar um novo tipo é tarefa estrutural (CLAUDE.md §7.2).

### 4.4 Políticas de juros (`InterestPolicy`)

Interface `InterestPolicy::computeInterest(Money $base, int $daysOverdue): Money`.

| Tipo | Taxa default | Teto default | Fórmula |
|---|---|---|---|
| IPVA | 0,33%/dia | 20% sobre o **juro** | `min(valor × 0.0033 × dias, valor × 0.20)` |
| MULTA | 1%/dia | sem teto | `valor × 0.01 × dias` |

Detalhes críticos:
- `daysOverdue <= 0` ⇒ retorna `Money::zero()`.
- Teto de IPVA incide sobre o **valor do juro**, não sobre o valor total. Armadilha histórica (CLAUDE.md §10 item 1).
- Cálculos em `BigDecimal` puro; arredondamento HALF_UP só na saída.

### 4.5 Simulação de pagamento

- **Pix**: `total = base × 0.95` (5% de desconto). VO: `PixPayment`.
- **Cartão de crédito** (`CreditCardPayment` com `Installment[]`):
  - 1x: sem juros, igual ao base.
  - 6x e 12x: PMT com `i = 0.025` (2,5% a.m. compostos).
  - Fórmula: `pmt = base × i × (1+i)^n / ((1+i)^n − 1)`.
  - Arredondar **apenas a parcela final** (HALF_UP, 2 casas). Arredondar intermediário corrompe centavos.

### 4.6 Agregação em opções

`PaymentSimulator::simulate(UpdatedDebt[] $debts): PaymentOption[]`:

- Sempre emite uma `TOTAL` (soma de todos os `valor_atualizado`).
- Emite uma `SOMENTE_<TYPE>` por **tipo distinto** presente (não por débito). Naming **singular**: `SOMENTE_IPVA`, não `SOMENTE_IPVAS`.
- Lista vazia ⇒ `[TOTAL = 0.00]` (decisão registrada no gate da Feature 4, PR #70).
- Ordem das `SOMENTE_` segue a **primeira ocorrência** de cada tipo na lista de entrada.

### 4.7 Data de referência

- Sempre `DateTimeImmutable` em UTC.
- Injetada de fora no construtor do `InterestCalculator` (não acoplada à entidade).
- Para os testes canônicos do enunciado, referência fixa é `2024-05-10T00:00:00Z`.
- Permite, no futuro, aceitar uma `referencia` na request sem refatoração ampla (CLAUDE.md §7.5).

---

## 5. Camada de Application

### 5.1 Use case principal: `QueryDebtsUseCase`

Responsabilidades:

1. Receber uma `Plate` validada.
2. Pedir débitos ao `DebtProvider` (port) → recebe `Debt[]` ou propaga exception.
3. Aplicar `InterestCalculator` em cada `Debt` → produz `UpdatedDebt[]`.
4. Aplicar `PaymentSimulator` na lista → produz `PaymentOption[]`.
5. Retornar `DebtQueryResult` agregando `UpdatedDebt[]` + `PaymentOption[]` + `DebtSummary`.

Não conhece HTTP, JSON, XML, Laravel ou nada de Infra. Recebe um `DebtProvider` injetado e fim.

### 5.2 Tracer port

`QueryTracer` é uma interface emitida em pontos chave do use case (chain start, cada provider, fallback, interest, payments). Em produção a impl é no-op; em demo é `DemoLogger`.

---

## 6. Resiliência

### 6.1 Estratégia em camadas

```
DebtProvider port
   └─ ProviderChain                    [iteração + budget global]
        ├─ CircuitBreakerDebtProvider  [decorator com estado in-mem]
        │    └─ ProviderAJsonAdapter   [retry + timeout HTTP]
        └─ CircuitBreakerDebtProvider
             └─ ProviderBXmlAdapter
```

### 6.2 `ProviderChain`

- Itera providers na **ordem canônica** (A → B).
- Mantém um **budget global** em segundos (default 5s, env `CHAIN_BUDGET_SECONDS`). Se o orçamento estourar antes de tentar o próximo, propaga `AllProvidersUnavailableException`.
- Captura **somente** `ProviderUnavailableException` (e `CircuitOpenException`, que estende a primeira) e tenta o próximo. Outras exceptions (de domínio, ex: `UnknownDebtTypeException`) propagam direto sem fallback.
- Se todos falharem por indisponibilidade ⇒ lança `AllProvidersUnavailableException` ⇒ HTTP 503.

### 6.3 `CircuitBreaker` (in-memory, single-instance)

- Estados: `closed` → `open` → `half-open` → `closed`.
- Trigger: `failureCount >= CB_FAILURE_THRESHOLD` (default 5) ⇒ abre.
- Cooldown: `CB_COOLDOWN_SECONDS` (default 30s) ⇒ depois passa pra half-open na próxima request.
- Half-open ⇒ uma única request de prova; sucesso fecha, falha reabre.
- **Filtro:** só `ProviderUnavailableException` conta como falha. Erros de domínio (placa inválida, tipo desconhecido) **não trippam**. Configurado via `shouldRecordFailure`.
- **Clock injetável** ⇒ testes determinísticos sem `sleep`.

**Limitação assumida:** PHP é shared-nothing — cada request boota o `public/index.php` zero e o container é recriado. O `failureCount` **não persiste entre requests** no modo HTTP padrão. Caminhos de evolução (todos viáveis, nenhum implementado):

- APCu via `Cache::store('apcu')` (single-instance, memória compartilhada do PHP).
- Laravel Octane (FrankenPHP/Swoole/RoadRunner) — mantém app vivo, singletons persistem.
- Redis-backed (`Cache::store('redis')`) para cluster.

A demonstração do breaker funciona via:
- **Pest** (canônico, com clock fake e injeção direta no construtor).
- **Tinker** (mesmo processo PHP, loop in-process onde o estado acumula).

Decisão registrada em CLAUDE.md §11 #7.

### 6.4 Retries e timeouts no adapter

Cada adapter usa o `Http` client do Laravel:

```php
Http::timeout($timeoutSeconds)        // default 2s
    ->retry($retries, $delayMs, $shouldRetryCallback);
```

Importante:
- `$shouldRetryCallback` **filtra** pra retry apenas `ConnectionException` e respostas `5xx`. **4xx nunca retry** (R10 das invariantes; armadilha #6 da §10 do CLAUDE.md).
- Defaults: 3 tentativas, 100ms entre elas.
- Em qualquer falha persistente, o adapter lança `ProviderUnavailableException` com a causa anexada.

### 6.5 Mocks (não produção)

`routes/web.php` define `/mock/provider-a` e `/mock/provider-b` com guard `! app()->isProduction()`. As fixtures vivem em `app/Infrastructure/Mocks/fixtures/`. Knobs `PROVIDER_A_FAIL` / `PROVIDER_B_FAIL` aceitam `timeout` ou `500` para forçar erros sem mudar código.

O servidor da demo (`composer dev`) usa `PHP_CLI_SERVER_WORKERS=4 --no-reload` justamente porque o adapter consome o próprio servidor via loopback — sem multi-worker, single-thread bloqueia a si mesmo.

---

## 7. Precisão decimal (R1 detalhado)

### 7.1 Por que decimal, não float

- IEEE 754 não representa exatamente `0.1`, `0.2`, etc. — `0.1 + 0.2 === 0.30000000000000004`.
- Cálculos compostos (juros sobre juros, PMT) acumulam erro.
- Comparação com `==` em literais flutuantes é uma armadilha clássica.

### 7.2 Como é garantido

- `Money::__construct(string $amount)` — **somente** string. Passar float ou número lança `InvalidArgumentException`.
- Internamente `BigDecimal`; todas as operações (`plus`, `minus`, `multipliedBy`, `dividedBy`) preservam precisão arbitrária.
- Arredondamento HALF_UP a 2 casas **apenas** no `toString()` e `jsonSerialize()`.
- JSON do provider é parseado com `pcrov/jsonreader` para preservar o token raw (sem o cast automático pra float do `json_decode` default).
- XML é lido com SimpleXML + cast `(string)` — o conteúdo textual nunca vira float intermediário.
- `(float)` e `floatval()` são proibidos no domínio e nos adapters de provider (R2 das invariantes).
- Resposta JSON serializa valor monetário como **string** (`"valor": "1500.33"`) — não como número. Verificado nos testes com `is_string`.

---

## 8. HTTP & contrato

### 8.1 Entrada

```http
POST /api/debts/query
Content-Type: application/json
{
  "placa": "ABC1234"
}
```

Validação:
- `PlateRule` valida formato.
- `MaxBodySize` middleware impõe limite (default 1 MiB, env `HTTP_MAX_BODY_BYTES`).

### 8.2 Saída (shape canônico)

```json
{
  "placa": "ABC1234",
  "debitos": [
    {
      "tipo": "IPVA",
      "valor_original": "1500.33",
      "vencimento": "2024-01-15",
      "dias_em_atraso": 116,
      "juros": "300.00",
      "valor_atualizado": "1800.00"
    }
  ],
  "opcoes_pagamento": [
    {
      "tipo": "TOTAL",
      "valor_total": "1800.00",
      "pix": { "total_com_desconto": "1710.00" },
      "cartao_credito": {
        "1x": "1800.00",
        "6x": "326.40",
        "12x": "175.40"
      }
    },
    {
      "tipo": "SOMENTE_IPVA",
      "valor_total": "1800.00",
      "pix": { ... },
      "cartao_credito": { ... }
    }
  ]
}
```

Notas críticas:
- Valores monetários **sempre string**.
- `cartao_credito` (não `cartao`) — decisão de Feature 4.
- Naming **singular** das opções `SOMENTE_`.

### 8.3 Erros (handler central em `bootstrap/app.php`)

| Causa | HTTP | Body |
|---|---|---|
| `InvalidPlateException` | 422 | `{"error":"validation_failed","message":"..."}` |
| `UnknownDebtTypeException` | 422 | `{"error":"unknown_debt_type",...}` |
| `AllProvidersUnavailableException` | 503 | `{"error":"all_providers_unavailable",...}` |
| `HttpException` (body > limite) | 413 | `{"error":"payload_too_large",...}` |
| Qualquer outra | 500 | genérico |

---

## 9. Observabilidade

### 9.1 Demo tracer

- Port: `App\Application\Ports\QueryTracer`.
- Adapter: `App\Infrastructure\Logging\DemoLogger` (canal Monolog `demo` em stderr).
- Habilitado fora de produção; no-op via flag no construtor em prod.
- Eventos cobertos: entrada/saída HTTP, montagem da chain, tentativa de cada provider, fallback, abertura do breaker, cálculo de juros, montagem de opções.
- ANSI inline (`\033[0m`, cores) pra contornar o `<fg=gray>` que o `ServeCommand` força no stderr dos workers (armadilha #12 da §10 do CLAUDE.md).

### 9.2 Mascaramento de placas (LGPD)

- `PlateMaskingProcessor` é um Monolog processor global.
- Registrado via `TapPlateMasking` em `config/logging.php` ⇒ **todos os canais** passam por ele, sem opt-in.
- Regex idêntica à do VO `Plate` (`\b([A-Za-z]{3})[0-9][A-Za-z0-9][0-9]{2}\b`).
- ANSI codes ao redor não quebram o match (boundaries `\b` ignoram não-word chars).
- **Armadilha histórica:** `TapPlateMasking` originalmente type-hinteava `Monolog\Logger`. O `LogManager::tap` do Laravel envolve em `Illuminate\Log\Logger`, type-hint estrito quebrava em runtime e caía silenciosamente no emergency logger ⇒ mask **não rodava**. Solução: aceitar ambos e desempacotar com `getLogger()`. Detalhe em CLAUDE.md §10 item 11.

---

## 10. Estratégia de testes

### 10.1 Pirâmide

```
Feature (HTTP end-to-end, Http::fake)        — borda
Unit/Application (use cases + fakes)         — orquestração
Unit/Infrastructure (resilience, logging)    — adapters em isolamento
Unit/Domain (VOs, policies, calculator)      — base ampla
```

### 10.2 Padrões

- Fakes em `tests/Support/Fakes/`.
- Fixtures em `tests/Support/Fixtures/` ou ao lado do teste em `fixtures/`.
- Casos canônicos do enunciado em testes nomeados explicitamente — ex: `it('matches the enunciado canonical output for ABC1234')`.
- Comparação **byte-a-byte** do JSON nos casos canônicos (não estrutura) ⇒ mudar shape quebra testes propositalmente.
- `Http::fake` substitui qualquer chamada real a providers.

### 10.3 Gates

- `composer test` ⇒ 207 testes / ~3s.
- `composer test:coverage` ⇒ exige 100% em `app/Domain` + `app/Application` (gate no CI). Requer `pcov` instalado.

---

## 11. Runtime configuration

### 11.1 Filosofia

Mudanças que um avaliador típico pode pedir ao vivo (trocar taxa, mudar timeout, trocar threshold do breaker) **não** devem exigir refactor — apenas edição em `.env` + `php artisan config:clear` + restart.

Mudanças **estruturais** (novo tipo de débito, novo provider, mudança de shape do JSON) continuam exigindo código (CLAUDE.md §7).

### 11.2 Mapa de knobs

Centralizado em `config/business.php`. 14 entradas:

| Domínio | Env var | Default |
|---|---|---|
| IPVA taxa diária | `IPVA_DAILY_RATE` | `0.0033` |
| IPVA teto | `IPVA_CAP_RATE` | `0.20` |
| MULTA taxa diária | `MULTA_DAILY_RATE` | `0.01` |
| Pix fator (1 - desconto) | `PIX_DISCOUNT_FACTOR` | `0.95` |
| Cartão taxa mensal | `CC_MONTHLY_RATE` | `0.025` |
| Provider A timeout | `PROVIDER_A_TIMEOUT` | `2` |
| Provider B timeout | `PROVIDER_B_TIMEOUT` | `2` |
| Provider A retries | `PROVIDER_A_RETRIES` | `3` |
| Provider B retries | `PROVIDER_B_RETRIES` | `3` |
| Chain budget (s) | `CHAIN_BUDGET_SECONDS` | `5.0` |
| CB failure threshold | `CB_FAILURE_THRESHOLD` | `5` |
| CB cooldown (s) | `CB_COOLDOWN_SECONDS` | `30.0` |
| HTTP body max bytes | `HTTP_MAX_BODY_BYTES` | `1048576` |
| Mock failure mode | `PROVIDER_A_FAIL` / `PROVIDER_B_FAIL` | `''` (vazio) |

---

## 12. Invariantes (10 regras que não negociamos)

| # | Invariante | Razão |
|---|---|---|
| 1 | `Money` construído **só** de string | Float corrompe `0.1`. |
| 2 | `(float)`/`floatval()` proibidos em Domain e adapters de provider | Idem. |
| 3 | Arredondamento HALF_UP somente em `toString()`/`jsonSerialize()` | Precisão total no cálculo. |
| 4 | Datas: `DateTimeImmutable` em UTC | Off-by-one por timezone. |
| 5 | VOs/entidades `final` + `readonly` | Sem aliasing. |
| 6 | Domain não conhece Application/Infra; Application não conhece Infra | Hexagonal. |
| 7 | Adapters lançam `ProviderUnavailableException` em falha de rede; **não** lançam para tipo desconhecido (esse é `UnknownDebtTypeException` que propaga) | Fallback só para indisponibilidade. |
| 8 | Logs nunca vazam placa sem mascaramento | LGPD. |
| 9 | Valores monetários no JSON são **strings** com 2 casas | Precisão na rede. |
| 10 | 4xx **não** aciona retry; apenas `ConnectionException` e 5xx | 4xx é determinístico. |

---

## 13. Armadilhas conhecidas

1. **Teto de IPVA sobre o total** em vez do juro. Sempre `min(juro, base × 0.20)`.
2. **Cast pra float** em qualquer ponto (incluindo `json_decode` default).
3. **`<debts/>` autofechado** em XML virando erro de parsing — tratar como `[]`.
4. **PMT com arredondamento intermediário** corrompe centavos.
5. **`SOMENTE_IPVAS`** plural — sempre singular.
6. **Retry em 4xx** — filtrar callback de `Http::retry`.
7. **`UnknownDebtTypeException` acionando fallback** na chain — não, propaga direto.
8. **Timezone do `DateTimeImmutable`** não normalizado pra UTC ⇒ off-by-one nos dias.
9. **Lista vazia** retornando `[]` em vez de `[TOTAL=0.00]`.
10. **Body JSON com campo monetário como número** em vez de string.
11. **`TapPlateMasking` com type-hint apertado em `Monolog\Logger`** ⇒ mask silenciosamente desativado.
12. **ANSI codes no `[demo]` sendo descartados pelo `ServeCommand`** ⇒ usar `\033[0m` inline.

(Detalhes em CLAUDE.md §10.)

---

## 14. Trade-offs e limitações assumidas

| Decisão | Trade-off |
|---|---|
| Circuit breaker in-memory single-instance | Simples, zero deps, suficiente pro escopo. **Não persiste entre requests no PHP padrão** — APCu, Octane ou Redis seriam evoluções claras. |
| Síncrono, sem queue | Latência crua, sem complexidade de retry assíncrono ou idempotência. Aceito pq toda dependência externa é idempotente (GET por placa). |
| Sem persistência (banco/cache de resposta) | Mais simples; cada request consulta provider. Cache de placa seria ganho futuro. |
| Mocks no mesmo servidor (loopback) | Demo-friendly mas exige `PHP_CLI_SERVER_WORKERS=4 --no-reload`. Em produção, mocks são bloqueados por guard. |
| 14 knobs por `.env` | Demo-friendly. Knobs validados implicitamente pelos defaults; sem validação de range nas envs (poderia ser melhorado). |
| Resposta JSON com string para money | Cliente precisa parsear como decimal, não como número. Decisão consciente (R1). |
| Comparação byte-a-byte nos testes canônicos | Frágil a mudanças cosméticas, mas garante shape estável. |

---

## 15. Roadmap de evolução

- **Persistência** (cache de resposta com TTL por placa) — alívio em providers degradados.
- **Circuit breaker compartilhado** (APCu single-host ou Redis cluster).
- **Octane** (FrankenPHP/Swoole) — mantém singletons vivos, breaker funciona naturalmente.
- **Tracing distribuído** (OpenTelemetry) — substituir tracer ad-hoc.
- **Suporte a data de referência via request** (já desacoplado no calculator — apenas adicionar campo na request).
- **Pix parcelado** como nova forma de pagamento (CLAUDE.md §7.3).
- **Novos tipos de débito** (LICENCIAMENTO, DPVAT) via nova policy (CLAUDE.md §7.2).
- **Provider C em CSV** ou outro formato (CLAUDE.md §7.1).
- **Validação de schema** nas envs (Symfony Config ou similar).
- **Rate limiting** no endpoint.

---

## 16. FAQ arquitetural — Q&A explícito

> Seção pensada para alimentar o NotebookLM com perguntas-resposta. Cada item é autocontido.

### 16.1 Decisões fundamentais

**P: Por que hexagonal e não MVC tradicional do Laravel?**
R: Para isolar regras de domínio (cálculo de juros, política de teto, PMT) de detalhes mutáveis (HTTP, JSON, XML, framework). Trocar Laravel por Symfony ou expor por gRPC ficaria contido na borda de Infrastructure. Além disso, hexagonal força testabilidade — domain é puro PHP, testado sem stack.

**P: Por que decimal em vez de float?**
R: Resposta exige precisão até o centavo e é comparada byte-a-byte com o enunciado. `0.1 + 0.2` em IEEE 754 é `0.30000000000000004`. `brick/math` em `BigDecimal` resolve. Encapsulado em `Money` para que o resto do código não precise pensar nisso.

**P: Por que `pcrov/jsonreader` e não `json_decode`?**
R: `json_decode` por default faz cast numérico pra float, perdendo precisão antes mesmo de o valor chegar no `Money`. `pcrov/jsonreader` é um streaming reader que entrega tokens raw como string. Alternativa seria `json_decode($s, true, 512, JSON_BIGINT_AS_STRING)` — mas só cobre integers grandes, não decimais.

**P: Por que dois adapters de provider em vez de um genérico?**
R: O contrato de cada provider é diferente — A retorna JSON com campos em inglês, B retorna XML com estrutura distinta. Tentar abstrair em um adapter genérico viraria um if/else gigante. Hexagonal abraça a diversidade na borda: cada adapter implementa a mesma **port** (`DebtProvider`) mas com lógica de parsing isolada.

**P: Por que circuit breaker se já tem retry?**
R: Retry resolve falha transitória (ex: latência momentânea, 503 esporádico). Circuit breaker resolve falha persistente (provider está fora há minutos) — pulando o overhead de tentativa+timeout+retry e indo direto pro próximo. Os dois compõem: retry é a primeira linha; breaker é a segunda.

### 16.2 Resiliência

**P: Como o serviço se comporta se Provider A está fora?**
R: Adapter A tenta 3 vezes com timeout 2s; se falhar, lança `ProviderUnavailableException`. `ProviderChain` captura, marca como falha no breaker A, e tenta B. Se B funciona, cliente recebe 200 normal — fallback transparente.

**P: E se os dois estão fora?**
R: `ProviderChain` lança `AllProvidersUnavailableException` ⇒ HTTP 503 com body `{"error":"all_providers_unavailable",...}`. Handler central em `bootstrap/app.php` traduz a exception em response.

**P: O que evita um retry numa resposta 422 do provider?**
R: O callback `$shouldRetry` passado pro `Http::retry` filtra: só retorna `true` para `ConnectionException` e respostas `serverError()` (5xx). 4xx é tratado como determinístico e propaga. Invariante #10.

**P: O budget de 5s da chain é por provider ou total?**
R: **Total**. Cada iteração checa o tempo restante antes de tentar o próximo. Garante que mesmo cenários adversários (A timeout 4.9s + B vai começar) não estourem o SLA externo.

**P: O circuit breaker funciona em produção?**
R: No PHP padrão (shared-nothing), **não persiste estado entre requests** — cada request recria o container. Vale pra single-instance também. A lógica está correta e testada (Pest); falta apenas backing store persistente. Caminhos: APCu, Octane, Redis. Decisão documentada e comunicada honestamente.

**P: O que dispara o circuit breaker?**
R: Apenas `ProviderUnavailableException` (`shouldRecordFailure`). Erros de domínio (placa inválida, tipo desconhecido) **não** contam — não fazem sentido como sinal de saúde do provider.

**P: Tem isolamento entre os breakers?**
R: Sim — **1 breaker por provider**. Slow B nunca trippa o breaker de A. Wiring em `AppServiceProvider` instancia dois `CircuitBreaker` separados e injeta em dois `CircuitBreakerDebtProvider`.

### 16.3 Domínio

**P: Por que IPVA tem teto e Multa não?**
R: Regra do enunciado, refletindo prática real do CTB onde multa não tem teto explícito de juros mas IPVA tem. Implementação: o teto é parâmetro da `IpvaInterestPolicy`, então alterar é trivial.

**P: O teto de IPVA incide sobre o quê?**
R: **Sobre o juro**, não sobre o valor total. `min(valor × 0.0033 × dias, valor × 0.20)`. Armadilha clássica — testes do enunciado pegam quem implementa errado.

**P: Por que `SOMENTE_<TIPO>` em vez de uma lista de débitos por tipo?**
R: Decisão do enunciado — facilita a UX (cliente paga "só os IPVAs" como um bloco). Implementação: agrupa por `DebtType`, soma, aplica simuladores em cima da soma. Naming singular (`SOMENTE_IPVA`) — armadilha #5.

**P: Como adicionar um terceiro tipo (ex: LICENCIAMENTO)?**
R: (1) Novo case em `DebtType`; (2) Nova `LicenciamentoInterestPolicy implements InterestPolicy`; (3) Bind no `AppServiceProvider` mapeando `DebtType::LICENCIAMENTO->value => LicenciamentoInterestPolicy::class`; (4) Teste unitário da policy; (5) Atualizar `CLAUDE.md` §3. Detalhe em CLAUDE.md §7.2.

**P: Como mudar a taxa de IPVA pra 0,5%/dia ao vivo?**
R: Edita `IPVA_DAILY_RATE=0.005` no `.env`, `php artisan config:clear`, restart. Default canônico (0,33%) preservado pelo `config/business.php`. Knob #1 dos 14 (§11.2).

### 16.4 Domínio puro

**P: Como garantir que Domain não importa Infra?**
R: Convenção forte (PSR-4 + revisão) + ausência de imports detectáveis com grep. Não usamos arqudiff/deptrac no CI hoje — seria evolução natural. A regra é simples o suficiente para revisão humana e a árvore de imports é rasa.

**P: Por que Plate é VO e não string crua?**
R: Validação é responsabilidade de domínio. `Plate::fromString('abc1234')` normaliza para UPPER e valida via regex. Domain confia em todo `Plate` recebido. Conversão `(string)$plate` retorna canônica.

**P: Por que `Money` aceita só string?**
R: Para impedir, em construção, o cast acidental de float que corromperia a representação. `Money::of('0.1')->plus(Money::of('0.2'))->toString()` retorna `"0.30"` exato.

### 16.5 HTTP & contrato

**P: Por que a resposta usa string para valor monetário?**
R: JSON `number` é IEEE 754 — `"valor": 1500.33` no wire vira `1500.0` em parser float. String preserva `"1500.33"`. Cliente deve parsear como decimal. Invariante #9.

**P: Por que `cartao_credito` em vez de `cartao`?**
R: Decisão do gate da Feature 4. Mais explícito; deixa espaço pra `cartao_debito` futuro sem confusão.

**P: Como lidar com placa Mercosul (`ABC1D23`)?**
R: A regex `\b([A-Z]{3})[0-9][A-Z0-9][0-9]{2}\b` aceita ambos os formatos (antigo e Mercosul) na mesma expressão. `[A-Z0-9]` na posição 4 acomoda a letra do Mercosul.

**P: O que acontece se o body tem 10 MB?**
R: Middleware `MaxBodySize` rejeita ANTES do parsing JSON. Default 1 MiB (env `HTTP_MAX_BODY_BYTES`). Retorna 413 Payload Too Large. Protege o serviço de OOM.

### 16.6 Observabilidade

**P: Tracer afeta performance em produção?**
R: Não. `DemoLogger::__construct(bool $enabled)` recebe `false` em produção via service provider. Todos os métodos viram no-op. Mesmo em demo, é write em stderr, baratíssimo.

**P: Como garantir mask de placas em todos os logs?**
R: `TapPlateMasking` registrado em `config/logging.php` envolve o Monolog logger e injeta `PlateMaskingProcessor` no pipeline. Como o processor é registrado no **handler stack**, vale pra qualquer mensagem que passe pelo canal — inclusive logs implícitos do framework.

**P: O mask cobre quê?**
R: Regex `\b([A-Za-z]{3})[0-9][A-Za-z0-9][0-9]{2}\b` — mesma do VO `Plate`. Cobre antigo e Mercosul. Substitui por `***NNNN` (mantém últimos 4 chars). Boundaries `\b` ignoram ANSI codes ao redor.

**P: E se um dev novo logar placa em uma `Log::error` ad-hoc?**
R: Como o processor é global via tap, **toda** `Log::error` passa por ele, independente do canal. LGPD é por construção, não opt-in.

### 16.7 Testes

**P: Por que Pest e não PHPUnit?**
R: Sintaxe declarativa (`it('does X')`) lê melhor, mas o output PHPUnit roda por baixo — mesma compatibilidade. Integração nativa com Laravel.

**P: 100% de coverage em Domain+Application — overkill?**
R: Não, porque a superfície é pequena e crítica. Domain é onde mora a regra de juros; bug ali sangra. Application orquestra; bug ali deixa fallback quebrado. Infrastructure tem coverage menor por design (parsing XML/JSON e adapters HTTP são testados em feature tests).

**P: Como testa o circuit breaker sem `sleep`?**
R: Clock injetável. `CircuitBreaker::__construct(callable $clock)` recebe um callable que retorna `int` (timestamp). Em testes, é um clock fake controlável. Em produção, `fn() => time()`.

**P: Os testes canônicos são byte-a-byte? Não é frágil?**
R: Sim, é proposital. O enunciado define o JSON exato; qualquer mudança cosmética é regressão até prova em contrário. Quando precisamos mudar shape (raro), o teste quebra e revisamos com intenção.

### 16.8 Configuração

**P: Por que 14 knobs em vez de hardcoded?**
R: Demo-friendly. Avaliador típico quer ver "trocar IPVA pra 0,5%" sem refactor. Defaults canônicos preservam byte-a-byte do enunciado. Estrutural (novo tipo) continua exigindo código — separação clara.

**P: As envs são validadas?**
R: Implicitamente — `config/business.php` faz cast (`(float)`, `(int)`) e tem default. Strings inválidas viram `0` ou `0.0`, quebrando o cálculo de forma visível em testes. Roadmap: validação de range explícita.

**P: Mudar `.env` em runtime pega na hora?**
R: Não. Precisa de `php artisan config:clear` + restart do `composer dev`. Laravel cacheia config compilada por padrão.

---

## 17. Mapa de arquivos rápido

```
app/
├── Domain/
│   ├── Money/Money.php
│   ├── Plate/Plate.php
│   ├── Debt/
│   │   ├── DebtType.php
│   │   ├── Debt.php
│   │   ├── InterestPolicy.php
│   │   ├── IpvaInterestPolicy.php
│   │   ├── MultaInterestPolicy.php
│   │   ├── InterestCalculator.php
│   │   └── UpdatedDebt.php
│   ├── Payment/
│   │   ├── PixSimulator.php, PixPayment.php
│   │   ├── CreditCardSimulator.php, CreditCardPayment.php, Installment.php
│   │   ├── PaymentOption.php
│   │   └── PaymentSimulator.php
│   └── Exceptions/DomainException.php
│
├── Application/
│   ├── Ports/
│   │   ├── DebtProvider.php
│   │   ├── ProviderUnavailableException.php
│   │   └── QueryTracer.php
│   └── UseCases/
│       ├── QueryDebtsUseCase.php
│       ├── DebtQueryResult.php
│       └── DebtSummary.php
│
└── Infrastructure/
    ├── Providers/
    │   ├── ProviderAJsonAdapter.php
    │   └── ProviderBXmlAdapter.php
    ├── Resilience/
    │   ├── ProviderChain.php
    │   ├── AllProvidersUnavailableException.php
    │   ├── CircuitBreaker.php
    │   ├── CircuitOpenException.php
    │   └── CircuitBreakerDebtProvider.php
    ├── Http/
    │   ├── Controllers/DebtsController.php
    │   ├── Requests/QueryDebtsRequest.php
    │   ├── Rules/PlateRule.php
    │   ├── Resources/DebtResponseResource.php
    │   ├── Middleware/MaxBodySize.php
    │   └── Middleware/DemoRequestLogger.php
    ├── Logging/
    │   ├── PlateMaskingProcessor.php
    │   ├── TapPlateMasking.php
    │   └── DemoLogger.php
    └── Mocks/
        ├── ProviderAMockController.php
        ├── ProviderBMockController.php
        └── fixtures/

bootstrap/app.php   — handler central de exceptions + tap de logging
config/logging.php  — processor global de mask
config/business.php — 14 knobs runtime
routes/api.php      — POST /api/debts/query
routes/web.php      — mocks (apenas !production)
```

---

## 18. Glossário curto

- **VO (Value Object)** — objeto imutável definido pelo valor, não pela identidade (ex: `Money`, `Plate`).
- **Port** — interface da Application que descreve uma colaboração externa (ex: `DebtProvider`).
- **Adapter** — implementação concreta de uma port, vivendo em Infrastructure (ex: `ProviderAJsonAdapter`).
- **PMT** — Parcela uniforme em juros compostos: `pmt = base × i × (1+i)^n / ((1+i)^n − 1)`.
- **Half-up** — modo de arredondamento que arredonda 0.5 sempre pra cima (5 vira 10, não vira 0).
- **Shared-nothing** — modelo de execução do PHP em que cada request inicia processo limpo (sem estado entre requests).
- **Loopback** — chamada HTTP do serviço para si mesmo (ex: o adapter consumindo `/mock/provider-a` no mesmo servidor).
- **Budget (chain)** — orçamento total de tempo que a chain tem para tentar todos os providers antes de desistir.
- **Half-open (breaker)** — estado intermediário pós-cooldown em que o breaker libera 1 request de prova.
- **byte-a-byte** — comparação textual exata do output (não estrutural). Captura mudanças invisíveis a comparações estruturais.
