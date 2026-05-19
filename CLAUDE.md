# CLAUDE.md

Guia de desenvolvimento para o **Serviço de Consulta e Simulação de Débitos Veiculares**. Este arquivo é a fonte de verdade para a IA assistente (Claude Code) e para devs humanos sobre arquitetura, convenções e regras de domínio do projeto.

> Atualize este arquivo sempre que uma decisão técnica relevante mudar.

---

## 0. Cheat sheet (demo ao vivo)

**Status do projeto:** todos os 10 épicos / 38 sub-issues fechados (PRs #50–#94). 200 testes / 421 assertions verdes; 100% coverage em `app/Domain` + `app/Application` no CI.

**Comandos quentes pra demo:**
```bash
composer test                              # 200 passed em ~3s
composer test:coverage                     # gate 100% domain+application (precisa pcov)
./vendor/bin/pest --filter=Money           # filtra por nome de classe/teste
./vendor/bin/pint --test                   # checa estilo
php artisan serve                          # API em :8000
./docs/curls/happy-path.sh | jq            # canon do enunciado byte-a-byte
./docs/curls/invalid-plate.sh              # 422 validation_failed
./docs/curls/all-providers-down.sh         # 503 all_providers_unavailable (após ajustar .env)
```

**Pontos pra abrir primeiro quando o avaliador pedir uma mudança:**
- Adicionar tipo de débito → §7.2 deste arquivo
- Adicionar provider → §7.1
- Trocar taxa de juros → §7.4
- Mudar shape do JSON de saída → `app/Infrastructure/Http/Resources/DebtResponseResource.php`
- Mudar validação da placa → `app/Domain/Plate/Plate.php` (regex em `PATTERN`); `PlateRule` herda automaticamente

**O que NÃO mexer durante demo (invariantes #1 a #10 da §4):**
- Construtor de `Money` aceitando float — todo monetário é string
- Catch genérico de `Throwable` em adapters — domain exceptions propagam raw
- Arredondamento intermediário em policies — só `Money::toString` arredonda
- Mock routes sem o guard `! app()->isProduction()`

---

## 1. Visão geral

API HTTP em PHP/Laravel que recebe uma placa veicular, consulta dois providers externos (JSON e XML), calcula juros sobre débitos em atraso e simula formas de pagamento (Pix com desconto e Cartão em 1x/6x/12x).

**Objetivos não-funcionais inegociáveis:**

- **Precisão decimal end-to-end** — nenhuma operação monetária pode passar por `float`.
- **Resiliência multi-provider** — falha de um provider não derruba a request; fallback é transparente.
- **Domínio puro** — regras de juros e pagamento vivem em código que não conhece HTTP, JSON, XML ou Laravel.
- **LGPD** — placas são mascaradas em todos os logs.

---

## 2. Arquitetura

Hexagonal com três camadas. Dependências só apontam para dentro (**Infrastructure → Application → Domain**).

```
app/
├── Domain/           # Regras de negócio puras. Sem Laravel, sem HTTP, sem I/O.
├── Application/      # Use cases + ports. Orquestra Domain. Não conhece Infra.
└── Infrastructure/   # Adapters (Http, Providers, Resilience, Logging). Conhece tudo.
```

**Namespaces PSR-4** (`composer.json`):

| Namespace | Diretório |
|---|---|
| `App\Domain\` | `app/Domain/` |
| `App\Application\` | `app/Application/` |
| `App\Infrastructure\` | `app/Infrastructure/` |

**Regra de import:** se um arquivo em `App\Domain\` precisa importar algo de `App\Infrastructure\`, é bug de arquitetura. Recusar e propor extrair uma porta na Application.

---

## 3. Regras de domínio

### Tipos de débito
- **Fechado**: apenas `IPVA` e `MULTA`. Qualquer tipo desconhecido lança `UnknownDebtTypeException` → HTTP 422. **Não** mapear para "OUTROS".

### Cálculo de juros (calculado sobre `valor_original`, retorna **juros**, não total)

| Tipo | Taxa | Teto |
|---|---|---|
| `IPVA` | 0,33% ao dia | 20% sobre o **juro** (não sobre o total) |
| `MULTA` | 1% ao dia | sem teto |

- `daysOverdue <= 0` → juros = `Money::zero()`
- Fórmula IPVA: `juros = min(valor × 0.0033 × dias, valor × 0.20)`
- Fórmula Multa: `juros = valor × 0.01 × dias`
- Arredondamento **apenas na saída** (`toString`/JSON), HALF_UP, 2 casas. Cálculos intermediários em `BigDecimal` puro.

### Simulação de pagamento

**Pix:** 5% de desconto sobre o valor base. `total = base × 0.95`.

**Cartão:** 1x sem juros; 6x e 12x via PMT com `i = 0.025` (2,5% a.m. compostos).
```
pmt = base × i × (1+i)^n / ((1+i)^n − 1)
```

**Opções agregadas:**
- Sempre gera **`TOTAL`** (soma de todos os `valor_atualizado`).
- Gera uma **`SOMENTE_<TYPE>`** por tipo **distinto** (não por débito). Naming sempre **singular**: `SOMENTE_IPVA`, `SOMENTE_MULTA`.
- Lista com 2 IPVAs → `TOTAL` + um único `SOMENTE_IPVA` (soma dos dois).

### Data de referência

Toda comparação de "dias em atraso" usa **UTC**. A data de referência é passada de fora (injetada em `InterestCalculator`), não acoplada à entidade. Para os testes canônicos do enunciado, a referência fixa é `2024-05-10T00:00:00Z`.

---

## 4. Invariantes críticas (não negociar)

| # | Invariante | Razão |
|---|---|---|
| 1 | `Money` construído **só** de `string`. Tentar `float` lança `InvalidArgumentException`. | Float corrompe `0.1`. |
| 2 | `(float)` e `floatval()` são **proibidos** em código de domínio e adapters de provider. | Mesma razão. |
| 3 | Arredondamento HALF_UP **somente em `toString()`/`jsonSerialize()`**. | Manter precisão total no cálculo. |
| 4 | Datas: `DateTimeImmutable` em **UTC**. `DateTime` mutável é proibido. | Off-by-one por timezone. |
| 5 | VOs e entidades de domínio são `final` e imutáveis (sem setters, propriedades `readonly`). | Eliminar bugs de aliasing. |
| 6 | Domain não importa nada de Application/Infrastructure. Application não importa Infrastructure. | Hexagonal. |
| 7 | Provider adapters lançam **`ProviderUnavailableException`** em falha de rede (timeout, 5xx). **Não** lançam para tipo desconhecido — esse é `UnknownDebtTypeException`, que propaga. | Fallback só para indisponibilidade. |
| 8 | Logs **nunca** vazam placa sem mascaramento. `PlateMaskingProcessor` é global. | LGPD. |
| 9 | Valores monetários na resposta JSON são **strings** com 2 casas decimais. Nunca `number`. | Precisão na rede. |
| 10 | Resposta a 4xx **não aciona retry**. Apenas `ConnectionException` e 5xx fazem. | 4xx é determinístico. |

---

## 5. Convenções

### Naming
- Classes: `PascalCase` (ex: `IpvaInterestPolicy`, não `IPVAInterestPolicy`).
- VOs: `final` + propriedades `readonly`.
- Interfaces sem prefixo `I` (ex: `DebtProvider`, não `IDebtProvider`).
- Exceptions de domínio terminam em `Exception` e estendem `App\Domain\Exceptions\DomainException`.

### Testes
- Framework: **Pest 3**.
- Estrutura:
  ```
  tests/
  ├── Unit/Domain/         # Por VO/policy/entidade
  ├── Unit/Application/    # Use cases com fakes
  ├── Unit/Infrastructure/ # Resilience, logging
  └── Feature/             # HTTP end-to-end, providers contra Http::fake
  ```
- Fakes ficam em `tests/Support/Fakes/`. Fixtures em `tests/Support/Fixtures/` ou ao lado do teste em `fixtures/`.
- Casos canônicos do enunciado **devem** estar em testes nomeados explicitamente (ex: `it('matches the enunciado canonical output for ABC1234')`).

### Commits & branches
- Branch de feature: `feature/I<épico>.<issue>-slug-curto` (ex: `feature/I3.3-ipva-policy`).
- Commit em PT-BR ou EN, imperativo, sem prefixo Conventional Commits estrito (mas pode usar `feat:`, `fix:`, `test:` quando ajudar).
- 1 issue ≈ 1 PR. PR contra `main`.

### PHP
- `declare(strict_types=1);` no topo de **todo** arquivo PHP.
- PHP 8.2+ (enums, readonly properties, `new` expressions).
- Sem `mixed` em assinaturas de domínio — tipar tudo.

---

## 6. Workflow com issues (histórico)

O projeto teve **10 épicos / 38 sub-issues** no GitHub — todos fechados. Veja `https://github.com/rafaelfne/debt-service/issues?state=closed`.

**Ordem executada (bottom-up):**
```
Feature 1 → 2 → 3 ⚠️ → 4 ⚠️ → 5 → 6 ⚠️ → 7 → 8 → 9 → 10
```

**Gates de revisão** (Features 3, 4, 6 — marcadas com ⚠️) tiveram revisão de plano antes da implementação. Decisões dos gates estão registradas nos PRs:
- Feature 3 (#65): rounding mora no `Money::toString`; `referenceDate` no construtor do `InterestCalculator`; canon combinado em `InterestCalculatorTest`.
- Feature 4 (#70): shape JSON em PT-BR com `cartao_credito` (não `cartao`); lista vazia → `[TOTAL=0.00]`; ordem das `SOMENTE_` por primeira ocorrência.
- Feature 6 (#80): shape dos providers em inglês snake_case; `2s timeout + 3 retries`; mocks só fora de produção.

**Cada sub-issue** tinha no GitHub: critérios de aceitação, casos de teste obrigatórios, escopo técnico (arquivos + skeleton) e Definition of Done — usados como contrato durante a execução.

---

## 7. Como adicionar / modificar (guias rápidos)

### Como adicionar um novo Provider (ex: Provider C em CSV)
1. Criar `app/Infrastructure/Providers/ProviderCCsvAdapter.php` implementando `App\Application\Ports\DebtProvider`.
2. Configurar `Http::timeout(2)->retry(3, 100, callback-só-5xx-ou-ConnectionException)`.
3. Lançar `ProviderUnavailableException` em falha de rede; deixar `UnknownDebtTypeException` propagar.
4. Registrar no service provider, adicionando ao array passado para `ProviderChain` (ordem é canon).
5. Adicionar mock em `app/Infrastructure/Mocks/` + teste em `tests/Feature/Providers/ProviderCCsvAdapterTest.php`.

### Como adicionar um novo tipo de débito (ex: `LICENCIAMENTO`)
1. Adicionar caso ao enum em `app/Domain/Debt/DebtType.php`.
2. Criar `app/Domain/Debt/LicenciamentoInterestPolicy.php` implementando `InterestPolicy`.
3. Registrar no service provider mapeando `DebtType::LICENCIAMENTO->value => LicenciamentoInterestPolicy::class`.
4. Adicionar teste em `tests/Unit/Domain/Debt/LicenciamentoInterestPolicyTest.php`.
5. Atualizar este `CLAUDE.md` (seção 3) com a nova taxa/teto.

### Como adicionar uma nova forma de pagamento (ex: PIX parcelado)
1. Criar `app/Domain/Payment/PixInstallmentSimulator.php` retornando um novo VO `PixInstallmentPayment`.
2. Estender `PaymentOption` para incluir o novo campo, ou criar VO específico.
3. Ajustar `PaymentSimulator::simulate()` para chamar o novo simulator e popular o VO.
4. Ajustar `DebtResponseResource` para serializar o novo campo no shape esperado.
5. Adicionar teste do shape em `tests/Feature/Http/DebtResponseResourceTest.php`.

### Como mudar a taxa de juros (ex: IPVA para 0,5%/dia)
1. Editar constante `DAILY_RATE` em `app/Domain/Debt/IpvaInterestPolicy.php`.
2. Atualizar os valores esperados nos testes de `tests/Unit/Domain/Debt/IpvaInterestPolicyTest.php`.
3. Atualizar este `CLAUDE.md` (tabela em §3) e o README na "Tabela de decisões".

### Como mudar a data de referência (ex: passada na request)
1. Adicionar campo opcional `referencia` ao `QueryDebtsRequest` (validar como `date_format:Y-m-d`).
2. Passar para o use case via parâmetro adicional em `execute(Plate $plate, ?DateTimeImmutable $referenceDate = null)`.
3. No use case, repassar para o `InterestCalculator` (que já recebe data no construtor — refatorar para método se necessário).
4. Default permanece `now()` em UTC.

---

## 8. Mapa de arquivos

```
app/
├── Domain/
│   ├── Money/Money.php                                # I2.1 (#15)
│   ├── Plate/Plate.php, InvalidPlateException.php     # I2.2 (#16)
│   ├── Debt/
│   │   ├── DebtType.php                               # I3.1 (#18)
│   │   ├── UnknownDebtTypeException.php
│   │   ├── Debt.php                                   # I3.2 (#19)
│   │   ├── InterestPolicy.php                         # interface
│   │   ├── IpvaInterestPolicy.php                     # I3.3 (#20)
│   │   ├── MultaInterestPolicy.php                    # I3.4 (#21)
│   │   ├── InterestCalculator.php, UpdatedDebt.php    # I3.5 (#22)
│   ├── Payment/
│   │   ├── PixSimulator.php, PixPayment.php           # I4.1 (#23)
│   │   ├── CreditCardSimulator.php,
│   │   │   CreditCardPayment.php, Installment.php     # I4.2 (#24)
│   │   ├── PaymentOption.php                          # I4.3 (#25)
│   │   └── PaymentSimulator.php                       # I4.4 (#26)
│   └── Exceptions/DomainException.php                 # I8.4 (#42)
│
├── Application/
│   ├── Ports/
│   │   ├── DebtProvider.php                           # I5.1 (#27)
│   │   └── ProviderUnavailableException.php
│   └── UseCases/
│       ├── QueryDebtsUseCase.php                      # I5.2 (#28)
│       ├── DebtQueryResult.php, DebtSummary.php
│
└── Infrastructure/
    ├── Providers/
    │   ├── ProviderAJsonAdapter.php                   # I6.2 (#31)
    │   └── ProviderBXmlAdapter.php                    # I6.3 (#32)
    ├── Resilience/
    │   ├── ProviderChain.php                          # I7.1 (#35)
    │   ├── AllProvidersUnavailableException.php
    │   ├── CircuitBreaker.php, CircuitOpenException.php  # I7.4 (#38)
    │   └── CircuitBreakerDebtProvider.php
    ├── Http/
    │   ├── Controllers/DebtsController.php            # I8.3 (#41)
    │   ├── Requests/QueryDebtsRequest.php             # I8.2 (#40)
    │   ├── Rules/PlateRule.php                        # I8.1 (#39)
    │   ├── Resources/DebtResponseResource.php         # I8.5 (#43)
    │   └── Middleware/MaxBodySize.php
    ├── Logging/
    │   ├── PlateMaskingProcessor.php                  # I9.1 (#44)
    │   └── TapPlateMasking.php
    └── Mocks/
        ├── ProviderAMockController.php                # I6.1 (#30)
        ├── ProviderBMockController.php
        └── fixtures/

bootstrap/app.php   # handler de exceptions (I8.4), tap de logging (I9.2)
config/logging.php  # processor global (I9.2)
routes/api.php      # POST /debts/query
routes/web.php      # rotas de mock
```

---

## 9. Comandos úteis

```bash
# Setup
composer install
cp .env.example .env

# Testes (depois da I1.2)
composer test
./vendor/bin/pest --filter=IpvaInterestPolicy
./vendor/bin/pest --coverage

# Rodar API local
php artisan serve

# Subir mocks (depois da I6.1) — em outro terminal
PROVIDER_A_URL=http://localhost:8000/mock/provider-a php artisan serve

# Request de exemplo (depois da I8.3)
curl -X POST http://localhost:8000/api/debts/query \
  -H 'Content-Type: application/json' \
  -d '{"placa":"ABC1234"}'

# Inspecionar rotas
php artisan route:list

# Static analysis (se configurado)
./vendor/bin/phpstan analyse
```

---

## 10. Armadilhas conhecidas (não cair de novo)

1. **Teto de IPVA sobre o total** em vez de sobre o juro. Releia §3.
2. **Cast para float** em qualquer ponto (JSON decode default, `(float)$x`, `floatval`, comparação com `==` em literais 0.1). Use `Money::of(string)` + `BigDecimal`.
3. **`<debts/>` autofechado** em XML interpretado como erro de parsing. Tratar como `[]`. Ver I6.4.
4. **PMT com arredondamento intermediário** dá valor errado nas casas finais. Arredondar **só** a parcela final.
5. **`SOMENTE_IPVAS`** no plural. Sempre singular.
6. **Retry em 4xx** por engano. Filtrar o callback de `Http::retry` para só `ConnectionException` ou `serverError()`.
7. **`UnknownDebtTypeException` acionando fallback** na `ProviderChain`. Não — exceptions de domínio propagam direto, só `ProviderUnavailableException` aciona fallback.
8. **Timezone do `DateTimeImmutable`** sem normalizar para UTC. Off-by-one nos dias em atraso.
9. **Lista vazia de débitos** retornando `[TOTAL R$ 0]` ou `[]` sem decisão. Documentar antes da I5.3.
10. **Body do JSON com campo monetário como número** (`"valor": 1500.00`) em vez de string (`"valor": "1500.00"`). Verificar com `is_string` no teste de byte-a-byte.

---

## 11. Histórico de decisões técnicas (espelha o README, mantém aqui para fácil acesso)

| # | Decisão | Justificativa curta |
|---|---|---|
| 1 | `brick/math` para precisão | Domain-grade, sem float interno |
| 2 | `pcrov/jsonreader` para parsing JSON | Streaming, preserva tokens raw |
| 3 | SimpleXML + cast `(string)` para XML | Native, sem libs extras |
| 4 | Hexagonal 3 camadas | Testabilidade + isolamento de Laravel |
| 5 | Pest 3 | Sintaxe declarativa, integração Laravel |
| 6 | `Http::retry` nativo do Laravel | Sem libs de resilience extras |
| 7 | Circuit Breaker in-memory simples | Suficiente para single-instance; cluster fica como melhoria futura |
| 8 | Exceptions de domínio + handler central | Controllers ficam minimalistas |
| 9 | Monolog processor global para mask | LGPD por construção, não opt-in |
| 10 | Resposta JSON com valores como **string** | Precisão preservada na rede |

---

## 12. Quando perguntar antes de fazer

A IA assistente **deve pausar e perguntar** antes de:

- Adicionar dependência nova ao `composer.json`.
- Mudar uma das 10 invariantes da §4.
- Decidir comportamento para lista vazia (§10 item 9).
- Refatorar nomes de campos do JSON de resposta (impacto no byte-a-byte do teste #43).
- Adicionar persistência (banco, cache) — fora do escopo atual.
- Implementar issues marcadas com ⚠️ (gates) sem revisão prévia do plano.
