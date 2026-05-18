# Serviço de Consulta e Simulação de Débitos Veiculares

API HTTP em PHP/Laravel que recebe uma placa veicular, consulta dois providers externos
(JSON e XML), calcula juros sobre débitos em atraso e simula formas de pagamento
(Pix com desconto e Cartão em 1x/6x/12x).

> Especificação detalhada (regras de domínio, invariantes, mapa de arquivos, armadilhas
> conhecidas) vive em [`CLAUDE.md`](CLAUDE.md).

---

## Visão geral

- **Arquitetura:** hexagonal em 3 camadas (`Domain` → `Application` → `Infrastructure`).
- **Precisão monetária:** `brick/math` (`BigDecimal`) de ponta a ponta. Nenhum `float` em
  código de domínio ou adapters.
- **Resiliência:** chain sequencial de providers (JSON → XML), retry com backoff em
  `ConnectionException`/5xx, circuit breaker in-memory por provider.
- **LGPD:** placas mascaradas em todo log via processor global do Monolog.

---

## Como rodar

```bash
# 1. Clonar e instalar dependências
composer install

# 2. Configurar ambiente
cp .env.example .env
php artisan key:generate

# 3. Rodar a API
php artisan serve
# → http://localhost:8000

# 4. (Opcional) Subir os mocks de provider em outro terminal
php artisan serve --port=8001
# Aponte PROVIDER_A_URL e PROVIDER_B_URL para http://localhost:8001/mock/...
```

### Rodar os testes

```bash
composer test                       # roda Pest
composer test:coverage              # exige 100% no app/Domain + app/Application (precisa de Xdebug/PCOV)
./vendor/bin/pest --filter=...      # filtra um teste específico
```

> O CI mantém um gate de **100% de cobertura** em `app/Domain/` e `app/Application/` (job
> `coverage` em `.github/workflows/ci.yml`). Infrastructure é fora do escopo do gate por
> agora — adapters de provider serão avaliados por testes de contrato (I6.x), não por
> percentual de linha.

---

## Endpoints

| Método | Rota | Descrição |
|---|---|---|
| `POST` | `/api/debts/query` | Consulta débitos de uma placa e devolve simulações de pagamento. |
| `GET` | `/api/health` | Health check (smoke). |
| `GET` | `/up` | Health check default do Laravel. |

> Os endpoints `POST /api/debts/query` e mocks de provider serão implementados nos épicos
> 6 e 8.

### Exemplo (após I8.3)

```bash
curl -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}'
```

---

## Arquitetura

```
app/
├── Domain/           # Regras de negócio puras. Sem Laravel, sem HTTP.
├── Application/      # Use cases + ports. Orquestra Domain.
└── Infrastructure/   # Adapters (Http, Providers, Resilience, Logging).
```

Dependências apontam **somente para dentro**: Infrastructure conhece Application e Domain;
Application conhece Domain; Domain não conhece ninguém.

> Diagrama de fluxo: _placeholder, adicionar quando os adapters estiverem prontos (I6.5)_.

---

## Tabela de decisões técnicas

| # | Decisão | Justificativa |
|---|---|---|
| 1 | `brick/math` para precisão monetária | Domain-grade `BigDecimal`, sem float interno. Pinado em `^0.14` por compatibilidade com Laravel 12 — a API de soma/multiplicação/arredondamento HALF_UP é estável nesse range. |
| 2 | `pcrov/jsonreader` (com `FLOATS_AS_STRINGS`) para Provider A | Streaming, preserva tokens decimais raw quando a flag está setada — evita o cast nativo para `float` que corromperia `1500.00`. |
| 3 | SimpleXML + cast `(string)` para Provider B | Built-in, sem libs extras. Cast explícito garante que valores nunca passem por `float`. |
| 4 | Hexagonal em 3 camadas | Testabilidade do domínio + isolamento de Laravel. Permite trocar providers ou expor o use case por CLI sem mexer no core. |
| 5 | Pest 3 como framework de testes | Sintaxe declarativa, integração com Laravel, suporte a fakes/mocks. |
| 6 | `Http::retry()` nativo do Laravel | Backoff exponencial + filtro `shouldRetry` para só `ConnectionException`/5xx. Sem libs de resilience extras. |
| 7 | Circuit Breaker in-memory simples | Suficiente para single-instance. Estado por chave de provider, com `open`/`closed` e cooldown. Cluster fica como melhoria futura. |
| 8 | Exceptions de domínio + handler central | Controllers ficam minimalistas; a hierarquia `DomainException` mapeia 1:1 para códigos HTTP no handler. |
| 9 | Monolog processor global para mask de placa | LGPD por construção (não opt-in). Mesmo logs de exceção saem mascarados. |
| 10 | Resposta JSON com valores monetários como **string** (`"1500.00"`) | Precisão preservada na rede. Cliente decide parsing. Evita perda de casas em JSON.parse JS. |

---

## Estratégia para divergência entre providers

A chain é **sequencial e fail-over**, não consenso:

1. Tenta Provider A (JSON). Se respondeu 2xx, **usa o resultado** e encerra.
2. Se A lança `ProviderUnavailableException` (timeout/ConnectionException/5xx após retries) ou
   abre o circuit breaker, tenta Provider B (XML).
3. Se B também falhar, lança `AllProvidersUnavailableException` → HTTP 503.

**Por que não consultar os dois e mergear?**
- Cada provider é fonte autoritativa para a placa nele cadastrada. Não temos contrato de que
  ambos vejam a mesma realidade.
- Mergear lista única poderia duplicar débitos com `tipo`/`vencimento` iguais e diferentes
  `valor_original`. Sem regra de negócio para resolver isso.
- Operação custa o dobro de latência e budget de timeout.

**Trade-off conhecido:** se A está saudável mas com dado desatualizado, B nunca é consultado.
A alternativa "consenso" fica como melhoria futura, exigindo regra explícita de tie-break
(ex.: maior `valor_original` por chave `tipo|vencimento`).

---

## Trade-offs e melhorias futuras

- **Persistência:** nenhuma. Cada request consulta os providers do zero. Adicionar cache
  (Redis com TTL curto por placa) reduz latência e custo dos providers, mas exige decisão
  sobre invalidação.
- **Circuit breaker em cluster:** estado in-memory. Múltiplas instâncias do app não
  compartilham — risco de "thundering herd" parcial. Mover para Redis se for horizontal.
- **Tipos de débito:** apenas `IPVA` e `MULTA`. Adicionar `LICENCIAMENTO` é uma nova
  `InterestPolicy` + registro no service provider (guia em `CLAUDE.md` §7).
- **Resposta para lista vazia:** comportamento definido em I5.3.
- **Observabilidade:** logs estruturados com placa mascarada. Métricas/tracing
  (OpenTelemetry) ficam para uma issue dedicada.
- **Frontend:** o skeleton Laravel trouxe Vite + `package.json`. Mantidos por padrão,
  removíveis se a API for back-end puro em produção.
