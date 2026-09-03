# Faturamento — próximas etapas

Situação em **30/08/2026**, com as etapas **A a F** e **J** implementadas (migrations **034** a
**040** e **046**) e as etapas **G** (descartada) e **H** (operação) decididas.

> As migrations **041–045** são de **outros módulos** (Carbonio, histórico de contrato, IPs, o SSL
> que saiu do monitoramento e o marcador `erro_pagina`) e não tocam faturamento. A do contas a
> receber é a **046**.

## O que já está pronto

O CDW Finance gera as próprias faturas, **registra a cobrança no banco (boleto + PIX)**, guarda o PDF
do boleto, reajusta contratos por índice e avisa o cliente do reajuste por e-mail.

`crm_contracts.billing_source` decide quem cobra cada contrato (`bomcontrole` | `cdwfinance`), e o
default é `bomcontrole` — a base inteira continua sendo cobrada pelo ERP até alguém virar contrato a
contrato. Quando é `cdwfinance`, **`crm_contracts.psp` diz por qual banco**, e a fatura carrega o
snapshot desse provedor.

Desde 19/08/2026 o ciclo está **fechado em código**: o boleto vai ao cliente por e-mail (B), o
pagamento é reconhecido por webhook (C) e por conciliação (D), a nota é emitida no ERP (E) e chega ao
cliente (F). O encerramento do contrato no ERP (G) foi **descartado** — é feito à mão —, e a migração
(H) é operação. Em código resta só a etapa **I** (WhatsApp), sem prioridade.

⚠️ **Escrito não é testado.** As etapas B a F estão implementadas e com as guardas verificadas, mas
nenhuma foi exercitada de ponta a ponta contra os serviços reais — ver *Situação de teste* abaixo.

### O que mudou desde 19/08/2026

| Data | O quê | Por quê |
|---|---|---|
| **30/08** | ✅ **CNPJ da empresa corrigido** — o cadastro local passou a `22863460000186`, o mesmo do ERP | Era o bloqueador da etapa E: a nota sai no CNPJ da empresa do Bom Controle, e a divergência faria toda nota sair errada. |
| **30/08** | 🆕 **Etapa J — conta a receber no ERP na liquidação** (migration 046) | Seção própria abaixo. |

E, antes disso, o que mudou foi **a proteção do que já existia**, com um defeito real encontrado no
caminho.

| Data | O quê | Por quê |
|---|---|---|
| **30/08** | **Reajustes e Notificações ao cliente somem quando "Quem fatura" é o Bom Controle** (classe `bloco-cdw`) | Os avisos saem das rotinas daqui, e **nenhuma olha contrato do ERP** — os campos à mostra prometiam um aviso que não aconteceria. **Esconder não apaga**: os inputs continuam sendo enviados, então o `notification_config` sobrevive à virada da chave e volta intacto (a regra da migration 033). |
| **30/08** | **GERAR FATURA · LANÇAR COBRANÇA · AVISAR REAJUSTE** também reagem ao select, não só ao estado salvo | Eles já sumiam pelo `if` do PHP quando o contrato estava salvo como `bomcontrole`, mas continuavam à mostra enquanto o select era trocado **sem salvar** — oferecendo uma ação que o servidor ia recusar. |
| **30/08** | 🐛 **Faltava a guarda de `billing_source` em `Contratos::json_postavisarreajuste`** | Achado ao conferir as três. O `Adjustment_model` declara que só toca contrato `cdwfinance` e as **filas do cron** filtram assim — mas o **botão manual** entrega o contrato pronto ao model, pulando o filtro. Um POST direto mandaria ao cliente **um aviso de reajuste que este sistema não aplicaria**: quem reajusta contrato do ERP é o ERP. Corrigido. |

**A lição, que vale para o resto do módulo**: esconder botão não protege endpoint. As três ações
recusam no servidor — `Invoice_model::generateNow`, `Charge_model::lancar` e
`Contratos::json_postavisarreajuste` —, e a tela é só a primeira camada. Verificado: com
`billing_source = 'bomcontrole'` as três recusam com mensagem própria; de volta a `cdwfinance`, as
três voltam a passar.

---

## Resumo — todos os passos e o que já foi feito

Situação em **30/08/2026** · faturamento na **migration 040** (o `migration_version` global está em 45, com as 041–045 de outros módulos) · legenda: ✅ pronto · 🟡 parcial · ⬜ não feito · ❌ descartado

> ⚠️ **As contagens deste documento vêm do banco de DESENVOLVIMENTO**, que é base de teste e não
> reflete a produção. Servem para exercitar código, nunca para dimensionar decisão. Em produção a
> migração para `cdwfinance` **já está em andamento**, e parte da carteira já recebe boleto + NF no
> mesmo e-mail (hoje emitidos pelo Bom Controle).

| # | Passo | O que entrega | Onde vive | |
|---|---|---|---|---|
| **1** | Cliente espelhado no ERP | Cadastro daqui vira cliente no Bom Controle, adotando o que já existir lá | migration 023 · `Bomcontrole_model::sincronizarCliente()` | ✅ |
| **2** | Contrato + serviço do ERP | Contrato local, com o serviço do ERP vinculado (o que a NF exige) | migrations 009 e 025 | ✅ |
| **3** | Fatura recorrente | Motor retomável a partir de `next_competence`; UNIQUE impede cobrança dupla | migration 024 · `Invoice_model` · `cron_gerar_faturas` | ✅ |
| **3a** | Parcelamento e cobrança avulsa | Competência dividida em N parcelas; venda pontual dentro do contrato | migration 031 · `Charge_model` | ✅ |
| **3b** | Reajuste anual por índice | Acumulado composto de 12 meses, aviso prévio ao cliente | migration 024 · `Adjustment_model` · `cron_reajustar_contratos` | 🟡 sem índices lançados |
| **3c** | Motivos de cancelamento | Catálogo global do porquê do encerramento | migration 032 | ✅ |
| **3d** | Destinatários de aviso | Quem notificar sobre boleto, NF e reajuste, por contrato | migration 033 · `Notification_model` (resolvedor único) | ✅ |
| **4** | **Cobrança no PSP** | **A fatura vira boleto + PIX de verdade** — detalhe abaixo | migrations 034–036 · `Psp_provider` · `Psp_inter` · `Psp_model` | ✅ |
| **5** | Envio do boleto por e-mail | Cliente recebe o boleto **anexo**. Destrava a migração dos **35%** que não emitem nota; `com_boleto` fica represado até a E | migration 037 · `Notification_model` · `Invoice_model::enviarFatura()` · `cron_enviar_faturas` · `emails/billing/invoice.php` | ✅ |
| **6** | Webhook de liquidação | Pagamento reconhecido em segundos, dá baixa e enfileira a NF. **Único caminho de baixa** — a manual está escondida | `Webhook.php` (público) · `csrf_exclude_uris` · `crm_psp_webhook_events` | ✅ *(sem teste real: exige URL pública)* |
| **6a** | Conciliação por pull | Recupera o que o webhook perdeu e o que ficou sem registrar | migration 038 · `Psp_model::conciliarPeriodo()` · `cron_conciliar_cobrancas` | ✅ *(sem teste real: sandbox fora do ar)* |
| **7** | Emissão da NF no ERP | `CriarVendaProdutoServico` → `Venda/Obter` → `EfeturarPagamento`, em fila. Destrava os **65%** que emitem nota | migration 039 · `Bomcontrole_model::emitirNota()` · `cron_emitir_notas` | ✅ *(nunca exercitado contra o ERP — ver riscos)* |
| **8** | Envio da NF ao cliente | PDF e XML anexos. Em `com_boleto` vai junto do boleto; em `pos_compensacao`, e-mail próprio | migration 040 · `Invoice_model::enviarNota()` · `cron_enviar_notas` · `emails/billing/nota_fiscal.php` | ✅ *(sem teste real: depende de uma nota emitida)* |
| **9** | Encerrar o contrato no ERP | **Feito à mão no Bom Controle**, por decisão (19/08/2026) | a trava de confirmação na tela do contrato é o controle | ❌ **etapa G descartada** |
| **10** | Migração dos contratos | Operação, não código — **gradual, em produção**. Contrato que recebe NF só migra depois de E e F validadas, senão o cliente perde a nota | filtro de acompanhamento pronto (migration 026) | 🟡 **em andamento** |
| **11** | **Conta a receber no ERP** | Cobrança liquidada vira título no financeiro do BC, para o crédito do extrato bancário ter contra o que ser conciliado. **Só para `nao_emitir`** — quem emite nota já ganha o título pela venda | migration 046 · `Bomcontrole_model::enfileirarRecebimento()` · `cron_criar_recebimentos` | ✅ **etapa J** *(sem teste real)* |
| **12** | Notificação por WhatsApp | O mesmo aviso do e-mail, por WhatsApp, na mesma fila | — | ⬜ **etapa I** · sem prioridade |

### O passo 4 em detalhe — o que a etapa A entregou

| Peça | O que faz | |
|---|---|---|
| Migration **034** | `crm_contracts.psp` · 10 colunas de cobrança em `crm_invoices` · `crm_psp_accounts` · `crm_psp_webhook_events` | ✅ |
| Migration **035** | `crm_invoices_v.registration` — *"existe boleto para pagar?"*, derivada | ✅ |
| Migration **036** | `crm_invoices_boletos` — o PDF guardado no banco | ✅ |
| `Psp_provider` + `Psp_inter` | 10 operações; PSP novo é **uma library + uma linha** na allowlist | ✅ |
| `Psp_model` | Orquestrador único, 25 métodos públicos | ✅ |
| Credencial por tenant **e** por PSP | Aba **Cobrança (PSP)** em Empresas: OAuth + mTLS, valida o par cert/chave, TESTAR CONEXÃO | ✅ |
| PSP **por contrato** | Select no bloco Faturamento, exigido para ativar o faturamento | ✅ |
| Registro da cobrança | Regra única (`processarPendentes`) por **três vias**: cron, GERAR FATURA e botão da fatura | ✅ |
| Boleto em PDF | Busca sob demanda, guarda no banco, modal XL com baixar | ✅ |
| Troca de PSP por fatura | Cancela no provedor antigo antes; falha aborta | ✅ |
| Cancelamento da fatura | Derruba a cobrança no banco primeiro; falha mantém a fatura aberta | ✅ |
| Adoção de cobrança órfã | Depois de um envio ambíguo, **procura antes de criar** (casa pelo `seuNumero`) | 🟡 sem teste ponta a ponta |

### Situação de teste — o que falta exercitar

O código das etapas B a F está pronto e com as guardas verificadas. O que **não** foi feito é o teste
de ponta a ponta contra os serviços reais, e cada um trava por um motivo diferente:

| Etapa | O que falta | Por quê |
|---|---|---|
| **B** — envio do boleto | disparar `cron_enviar_faturas` com fatura real | nada trava: dá para testar quando quiser |
| **C** — webhook | receber uma chamada do banco | exige **URL pública HTTPS**; o ambiente local não recebe |
| **D** — conciliação | rodar `cron_conciliar_cobrancas` | exige o **sandbox do Inter aberto** (08h–20h) |
| **E** — emissão da NF | rodar `cron_emitir_notas` | ⚠️ **escreve documento fiscal em produção** — ver riscos |
| **F** — envio da nota | rodar `cron_enviar_notas` | depende de uma **nota emitida** (etapa E). O caminho degradado — link no corpo quando o download falha — está verificado |
| **J** — conta a receber | rodar `cron_criar_recebimentos` | ⚠️ **escreve no financeiro do ERP** (não emite nota nem boleto). Falta cadastrar conta e categoria em Empresas › Bom Controle — sem isso a fila falha na guarda, antes de qualquer chamada |
| — | adoção de cobrança órfã | precisa criar uma cobrança e simular o 500; o mecanismo (`seuNumero` na listagem) está confirmado |

**Ordem sugerida, da mais barata para a mais cara de errar**: B (e-mail é reversível) → D (com o
sandbox aberto) → E por último, num contrato de valor baixo e com a saída do cron à vista.

### O caminho crítico daqui

Com a etapa G descartada, a H sendo operação e a **J** entregue, resta em código apenas a **I**
(WhatsApp), que não tem prioridade. Tudo o mais é **validação** e **migração**.

| O que falta | Natureza |
|---|---|
| Validar B, C, D, E, F e J contra os serviços reais | teste |
| Cadastrar conta e categoria financeiras em Empresas › Bom Controle | operação (pré-requisito da J) |
| Vincular o serviço do ERP em cada contrato que emite nota | operação |
| Encerrar o `VendaContrato` no BC, contrato a contrato | operação |
| Virar os contratos para `cdwfinance`, gradualmente | operação |
| Etapa I — WhatsApp | código, sem prioridade |

A carteira se divide em `com_boleto` **15%**, `pos_compensacao` **50%** e `nao_emitir` **35%**. Os
**35%** dependem só de B validada; os outros **65%** dependem de E e F, e por isso do trabalho de
vínculo de serviço.

**O roteiro de virada de cada contrato**, agora que G é manual:

1. vincular o serviço do ERP no contrato (aba do contrato);
2. **encerrar o `VendaContrato` no painel do Bom Controle**;
3. na tela do contrato, escolher o PSP, marcar a confirmação de que o ERP foi encerrado e salvar.

O passo 3 recusa sem a marcação do passo 2 — é o que impede a cobrança dupla, e é definitivo.
---

## Placar do ROADMAP — o que está pronto e o que falta

Situação verificada no código em **18/08/2026** (migration_version = **36**).

Legenda: ✅ pronto · 🟡 parcial · ⬜ não implementado

### O fluxo desenhado, passo a passo

| # | Passo do ROADMAP | O que faz | Onde vive hoje | Situação |
|---|---|---|---|---|
| **1** | **Cadastro do cliente com integração BC** | Cliente é criado aqui e espelhado no ERP. Resolve o Id por `bomcontrole_customer_id` → busca por documento (**adota** o que existir) → só então cria. Documento repetido no ERP **recusa**, em vez de escolher o primeiro. | `Bomcontrole_model::sincronizarCliente()`, migration 023 (`bomcontrole_customer_id`, `bomcontrole_synced`, `state_registration`), botão **SINCRONIZAR CADASTRO** em `clientes/info` | ✅ |
| **2** | **Contrato no cdwfinance** | Contrato local (ciclo, valor, tipos de serviço, espaço). Sem `VendaContrato` no ERP — **mas com** o serviço do ERP vinculado, que é o que a NF do passo 7 exige. | migration 009 (`crm_contracts`), migration 025 (`bomcontrole_service_id` + `_name`), `Bomcontrole_model::vincularServico()` (revalida o Id no `Servico/Obter`) | ✅ |
| **3** | **Fatura recorrente** | Motor retomável: gera as competências pendentes uma a uma a partir de `next_competence`, teto de 12 por rodada — o **cron gera mês a mês** (antecedência de 10 dias), e o teto alto serve só para contrato atrasado se recuperar. O botão GERAR FATURA gera **uma** competência e recusa o futuro. UNIQUE `(id_contract, id_charge, competence, installment_number)` impede cobrança dupla no banco, não em PHP. | migration 024 (`crm_invoices`), `Invoice_model` (`getBillableContracts`, `generateForContract`, `generateNow`), `Cron::cron_gerar_faturas`, tela `faturas`, botão **GERAR FATURA** | ✅ |
| **3b** | *(bônus)* **Reajuste anual por índice** | Acumulado composto sobre os 12 meses encerrados; janela incompleta **não** reajusta. Aviso ao cliente 30 dias antes, com marcadores editáveis. | migration 024 (`crm_adjustment_indexes`, `crm_contracts_adjustments`), `Adjustment_model`, `Cron::cron_reajustar_contratos`, tela `indices`, `views/emails/billing/adjustment.php` | ✅ |
| **4** | **Integra invoice com o banco/PSP** | Registrar a cobrança (boleto + PIX) no PSP e guardar `psp_charge_id`, `link_boleto`, `link_pix`. **O PSP é escolha do contrato** (`crm_contracts.psp`), com snapshot de roteamento na fatura. | migration 034, `Psp_provider` + `Psp_inter`, `Psp_model` (`registrarCobranca`, `sincronizarCobranca`, `processarPendentes`), aba **Cobrança (PSP)** em `empresas/info`, select no bloco Faturamento, fase 2 do `cron_gerar_faturas`, coluna **Cobrança** na tela de Faturas | ✅ Banco Inter, exercitado no sandbox |
| **5** | **Envia boleto ao cliente por e-mail** | Template + gatilho depois do registro da cobrança. O boleto vai **anexo** — o Inter não publica URL. | **nada** ainda, mas as peças estão prontas: `Psp_model::obterBoleto()` devolve o PDF, `crm_invoices.sent_at` existe (034) e a fila `cron_enviar_email` é pré-existente | ⬜ |
| **6** | **Webhook de liquidação** | Endpoint público, reconsulta a cobrança, dá baixa e **enfileira** a NF. | **nada**. A baixa manual **foi escondida** (o código segue comentado) porque o pagamento passa a ser automático — um "marcar como paga" ao lado disso criaria segunda verdade sobre o mesmo dinheiro. Prontos: `interpretarWebhook()`, `crm_psp_webhook_events`, `crm_psp_accounts.webhook_token` e as colunas `paid_*` | ⬜ |
| **6.5** | **Conciliação por pull** *(acrescentado)* | Cron que varre as cobranças em aberto no PSP e concilia o que o webhook perdeu — e que também recupera fatura **sem cobrança registrada**. | **nada**. `Psp_model::processarPendentes()` já faz a metade do registro; falta a metade do pagamento | ⬜ |
| **7** | **Emite NF pelo ERP** | `Venda/CriarVendaProdutoServico` → `Venda/Obter` (pegar o `IdFatura`) → `Fatura/EfeturarPagamento`. **Em fila**, nunca no webhook. | **nada**. A library `Bom_controle` não tem nenhum método de `Venda/*` nem de `Fatura/*` | ⬜ |
| **8** | **Envia NF ao cliente** | `Fatura/Obter/{id}` devolve **PDF e XML**; os dois vão ao cliente. | **nada** | ⬜ |
| **9** | **Encerrar o `VendaContrato` no ERP** *(acrescentado)* | Automatizar o encerramento na virada do contrato para `cdwfinance`. | **nada**. Hoje é **confirmação manual** na tela: o usuário declara que encerrou no painel do ERP | 🟡 tem trava manual |
| **10** | **Migração dos contratos** | Operação, não código. Painel de acompanhamento: filtro *"contrato sem vínculo com o Bom Controle"*. | migration 026 (`bomcontrole_unlinked_contracts_count`), switch no offcanvas de clientes | ✅ ferramenta pronta, operação não começou |

### O que já existe e vai ser reaproveitado

Não é passo do ROADMAP, mas é infraestrutura paga que as etapas seguintes usam:

| Peça | Estado |
|---|---|
| Library `Bom_controle` (cURL, nunca lança exceção, retry em 429, `normalizarLista()`) | ✅ 11 métodos |
| Config do BC por tenant (chave cifrada, olho, TESTAR CONEXÃO) | ✅ migration 019 |
| Extrato Bom Controle ao vivo (contrato e cliente), vínculo `VendaContrato` | ✅ migrations 019/023 |
| Contador **"(N) faturas em aberto"** no menu | ✅ `Invoice_model::countOverdue()` |
| Fila genérica de e-mail (`cron_enviar_email`) | ✅ pré-existente |
| `Secret_crypto` (AES-256-GCM) para a credencial do PSP | ✅ pré-existente |

### O que está cadastrado mas **inerte**

Campos que já se preenchem na tela e ainda não movem nada — é exatamente o que as etapas A–F destravam:

| Campo | Onde | Por que está inerte |
|---|---|---|
| `invoice_policy = 'pos_compensacao'` | `crm_contracts` + snapshot em `crm_invoices` | Sem baixa automática de pagamento, não há gatilho |
| `invoice_policy = 'com_boleto'` | idem | Ninguém emite NF ainda |
| `crm_adjustment_indexes` | tela GESTÃO › Índices | Tabela **vazia**: sem os 12 meses da janela, nenhum contrato reajusta |
| `crm_contracts.notification_config` | bloco Faturamento do contrato (migration 033) | **Ninguém lê ainda.** É o cadastro de quem avisar sobre boleto, NF e reajuste — vira insumo da etapa B, e é lá que a regra "contrato x cascata do cliente" precisa ser decidida |

### O que falta no código, em detalhe

**Métodos que a library `Bom_controle` ainda não tem** (6, contra 11 implementados):

| Método a criar | Endpoint | Etapa |
|---|---|---|
| `criarVendaProdutoServico()` | `POST Venda/CriarVendaProdutoServico` | E |
| `obterVenda()` | `GET Venda/Obter/{id}` | E |
| `efeturarPagamentoFatura()` *(sic)* | `PUT Fatura/EfeturarPagamento/{id}` | E |
| `obterFatura()` | `GET Fatura/Obter/{id}` | F |
| ~~`encerrarVendaContrato()`~~ | ~~`DELETE VendaContrato/Encerrar/{id}`~~ | ❌ **não será escrito** — etapa G descartada |
| `pesquisarEmpresas()` | `GET Empresa/Pesquisar` | E (resolver o `IdEmpresa`) |

**Colunas que não existem** — hoje a `crm_invoices` tem 18 colunas (a 031 acrescentou origem e parcela) e **nenhuma** delas guarda cobrança, pagamento ou nota:

| Migration | Tabela | Colunas | Etapa |
|---|---|---|---|
| **034** | `crm_invoices` | `psp_charge_id`, `psp_status`, `link_boleto`, `link_pix`, `paid_at`, `paid_amount`, `paid_method`, `sent_at` | A–C |
| **034** | `crm_psp_accounts` (nova) | credencial por tenant **e** por PSP — ver PLANO-PSP-COBRANCA.md | A |
| **035** | `crm_invoices` | `bomcontrole_sale_id`, `bomcontrole_invoice_id`, `nf_status`, `nf_attempts`, `nf_last_error`, `nf_issued_at`, `link_nota_fiscal`, `link_nota_fiscal_xml`, `nf_sent_at` | E–F |
| **035** | `crm_companies` | `bomcontrole_company_id` (o `IdEmpresa`) | E |
| — | *(a avaliar)* | `crm_psp_webhook_events` para idempotência e auditoria do recebido | C |

> **A migration 031 mudou a unidade da emissão.** Com o parcelamento, a fatura deixou de ser
> 1:1 com a venda: uma competência anual em 2× são duas faturas, e uma cobrança avulsa em 4×
> são quatro. O `Venda/CriarVendaProdutoServico` já modela isso com `QuatidadeParcelas`, então
> **uma venda no ERP = uma competência parcelada OU uma cobrança avulsa**, e não uma fatura.
> Consequência para a etapa E: `bomcontrole_sale_id` precisa ficar no agrupador
> (`crm_contracts_charges` para a avulsa; o par contrato+competência para a recorrência), e só
> `bomcontrole_invoice_id` desce até a linha da fatura. Emitir por fatura geraria quatro vendas
> onde o ERP espera uma.

Em ambas, **recriar a `crm_invoices_v`** na mesma migration — senão a tela não reflete o que foi
gravado.

**Rotinas e endpoints novos:**

| Peça | Tipo | Etapa |
|---|---|---|
| Library `Psp_<nome>` no padrão do `Bom_controle` | library | A |
| `Psp_model` (orquestrador único, `sessao_suspender()` em volta da rede) | model | A |
| `Webhook.php` — controller **público**, sem sessão, fora do `MY_Controller` | controller | C |
| `Cron::cron_conciliar_cobrancas` | cron | D |
| `Cron::cron_emitir_notas` (consome a fila) | cron | E |
| `Cron::cron_enviar_faturas` | cron | B/F |
| `views/emails/billing/invoice.php` e `.../nota_fiscal.php` | views | B/F |

---

## Arquitetura de cobrança (decidida em 17/08/2026)

A cobrança sai do Bom Controle e passa a ser do CDW Finance. **O ERP vira serviço de nota fiscal, não
motor de cobrança.**

```
  ┌─────────────────────────────────────────── CDW Finance ───────────────────────────────────────────┐
  │                                                                                                   │
  │  1. cliente ──sincroniza──▶ [BC Cliente/Criar]         ← já pronto (migration 023)                 │
  │  2. contrato (local) + vínculo do serviço do ERP        ← já pronto (migration 025)                │
  │  3. cron_gerar_faturas ──▶ crm_invoices                 ← já pronto (migration 024)                │
  │                                │                                                                  │
  │  4.                            ├──▶ PSP/banco: cria cobrança (boleto + PIX)                        │
  │  5.                            └──▶ e-mail ao cliente com o boleto                                 │
  │                                                                                                   │
  │  6. PSP ──webhook──▶ /webhook/psp/...  → baixa + ENFILEIRA emissão                                 │
  │  6.5 cron_conciliar_cobrancas (pull)   → pega o que o webhook perdeu                               │
  │                                │                                                                  │
  │  7. cron_emitir_notas ─────────┴──▶ [BC Venda/CriarVendaProdutoServico] → [BC Fatura/Efeturar…]    │
  │  8. e-mail ao cliente com PDF + XML da NF                                                          │
  │  9. encerrar o VendaContrato NO PAINEL DO BC — passo manual do roteiro de migração                 │
  └───────────────────────────────────────────────────────────────────────────────────────────────────┘
```

### Por que tirar a cobrança do ERP

Não é limitação de API: o Bom Controle **emite boleto** (`Fatura/GerarBoleto`, e o objeto
`FormaPagamento.Boleto` do `Venda/Criar`). O motivo é outro, e é único:

> **O Bom Controle não tem webhook.** O collection inteiro — 70+ endpoints — não tem nenhum
> `webhook`, `callback` ou `notificacao`. Toda leitura é *pull*.

Saber que o cliente pagou é o evento que dispara a NF (`invoice_policy = 'pos_compensacao'`), o
e-mail de confirmação e a baixa da fatura. Com pull, isso vira um cron que descobre o pagamento
horas depois, gastando quota de uma API cujo rate limit já devolve 429 em ~12 chamadas seguidas
(seriam ~400 contratos consultados por rodada). Com o PSP, o pagamento chega em segundos, de graça,
por push.

O acoplamento com o ERP fica em **dois pontos estreitos, ambos já modelados**:
`crm_customers.bomcontrole_customer_id` (023) e `crm_contracts.bomcontrole_service_id` (025).

### O endpoint da emissão mudou: `CriarVendaProdutoServico`

A etapa 2b anterior previa `Venda/Criar`. Com a cobrança fora do ERP, o endpoint correto é o
**`Venda/CriarVendaProdutoServico`** — e o que era plano B virou plano A:

| | `Venda/Criar` | `Venda/CriarVendaProdutoServico` |
|---|---|---|
| Emissão de NF | pela **presença** do objeto `NotaFiscal` (sem flag) | `NotaFiscalServico { **Emite**: true, NotaFiscalTotal }` explícito |
| Itens | `Servicos[]` (só serviço) | `Itens[]` (`IdProduto` **ou** `IdServico`, + `Descricao`) |
| PIX | — | `FormaPagamento.Pix { EmiteQrCodePix }` |

Emitir **sem** gerar boleto no ERP é simplesmente **omitir `FormaPagamento.Boleto`** (o objeto é
opcional, e a doc diz "informar apenas quando houver emissão"). Cobrança dupla — boleto do PSP e
boleto do BC para a mesma fatura — é o acidente que essa omissão evita.

`Itens[].Descricao` é bônus real: a descrição congelada na `crm_invoices` vai para a nota, em vez do
nome genérico do catálogo.

### A venda no ERP nasce paga

O `CriarVendaProdutoServico` cria a venda **e as parcelas** no financeiro do BC. Como o dinheiro já
entrou pelo PSP, essa parcela é um recebível que nunca será quitado lá — e o financeiro do ERP
passaria a mostrar títulos em aberto fantasmas, crescendo um por mês por contrato.

Por isso a emissão são **duas chamadas encadeadas**, não uma:

1. `POST Venda/CriarVendaProdutoServico` → devolve o **Id da Venda** (inteiro puro no corpo, igual ao
   `Cliente/Criar`);
2. `GET Venda/Obter/{id}` → tira dali o **Id da Fatura** (o `Criar` não devolve);
3. `PUT Fatura/EfeturarPagamento/{idFatura}` com `ValorLiquido`, `DataQuitacao`, `DataConciliacao` e
   `GerarResiduo: false` — a data de quitação é a **do PSP**, não a de hoje.

⚠️ **O path tem erro de digitação na própria API**: `EfeturarPagamento`, não `EfetuarPagamento`
(o `Financeiro/EfetuarPagamento` é o grafado certo — são endpoints diferentes). Escrever o correto
devolve 404.

Como são três chamadas em sequência e a segunda depende da primeira, a emissão **não pode** morar no
handler do webhook. Ver a etapa E.

---

## Próximas etapas

Numeração seguindo o fluxo acima. O caminho crítico é **B → C+D → E+F**: 65% da carteira depende de
nota fiscal, e o gatilho da nota de metade dela é o pagamento. **D deixou de ser "proteção"** — é
requisito, tanto para o gatilho quanto porque nenhum contrato migrado tem hoje caminho de baixa na
tela. **G** segue independente.

| # | Etapa | O que entrega | Depende de | Decisão pendente |
|---|---|---|---|---|
| ~~**A**~~ | ~~**Integração com o PSP/banco**~~ | **PRONTA** (18/08/2026). Migration **034**; `Psp_provider` abstrata + `Psp_inter`; `Psp_model` como orquestrador único; PSP **selecionável por contrato**, com allowlist em `Psp_model::providers()` — PSP novo é uma library + uma linha. Ver [PLANO-PSP-COBRANCA.md](PLANO-PSP-COBRANCA.md). | Etapa 3 (**pronta**) | **Resolvida: Banco Inter** |
| ~~**B**~~ | ~~**Envio do boleto por e-mail**~~ | `cron_enviar_faturas` manda o boleto **anexo** (não há URL a mandar) e carimba `sent_at`. Só envia fatura com `registration = 'registrada'`. Nasce com **lista de anexos** e com o **portão por política**: `com_boleto` fica represado até a nota existir, porque boleto e NF vão no **mesmo e-mail** (requisito). | A (**pronta**) | **Quem notificar** e texto do e-mail |
| ~~**C**~~ | ~~**Webhook de liquidação**~~ | `Webhook.php` público, sem sessão, em `webhook/psp/<slug>/<token>`. Reconsulta a cobrança, grava `paid_at`/`paid_amount`/`paid_method`, vira o status para `paga` e **enfileira** a NF. | A (**pronta**) | — |
| ~~**D**~~ | ~~**Conciliação por pull**~~ | `cron_conciliar_cobrancas`: varre o PSP e concilia o que o webhook não trouxe. **Deve entrar junto com C**, não depois — o ambiente local não recebe webhook, então sem ela a etapa C não tem como ser verificada aqui. | A (**pronta**) | Cadência (sugerido: 1×/dia) |
| ~~**E**~~ | ~~**Emissão da NF no ERP (com fila)**~~ | `cron_emitir_notas` consome a fila: `Venda/CriarVendaProdutoServico` → `Venda/Obter` → `Fatura/EfeturarPagamento`. Migration com `bomcontrole_company_id`, `bomcontrole_sale_id`, `bomcontrole_invoice_id`, `nf_status`, `nf_attempts`, `nf_last_error`, `nf_issued_at`. **Destrava o `com_boleto` da etapa B.** | C (para `pos_compensacao`) + **402 vínculos de serviço**, que são operação e podem começar já | **`IdEmpresa`** e **gatilho por política** |
| ~~**F**~~ | ~~**Envio da NF por e-mail**~~ | `Fatura/Obter/{id}` devolve **PDF e XML** da nota. Vale **só para `pos_compensacao`**, em que a nota sai depois do pagamento — no `com_boleto` ela já foi junto do boleto, no e-mail da etapa B. | E | — |
| ❌ **G** | ~~**Encerrar o contrato no ERP ao migrar**~~ | **DESCARTADA em 19/08/2026.** O encerramento do `VendaContrato` é feito **à mão no Bom Controle**, por decisão de quem opera a migração. Ver o porquê abaixo. | — | **Resolvida: não implementar** |
| **H** | **Migração dos contratos** | Operação, não código: virar os contratos do ERP para cá, um a um. **Já em andamento em produção.** O filtro *"contrato sem vínculo com o Bom Controle"* da listagem de clientes (migration 026) é o painel de acompanhamento. | B para a maioria; **E para quem recebe NF** | Ritmo da virada |
| ~~**J**~~ | ~~**Conta a receber no ERP na liquidação**~~ | `cron_criar_recebimentos` cria o título no financeiro do Bom Controle quando a cobrança é liquidada, para o crédito do extrato bancário ter contra o que ser conciliado. **Só para `nao_emitir`** — ver a seção abaixo. Migration **046**. | C ou D (é a baixa que dispara) | **Resolvida: só `nao_emitir`, título em aberto** |
| **I** | **Envio de notificação via WhatsApp** | Mesmo aviso que sai por e-mail (boleto, reajuste, NF), também por WhatsApp. Trabalha no **mesmo formato de enfileiramento** das mensagens — a fila e o provedor serão definidos depois. | B (o texto e os destinatários) | **Sem prioridade agora** · provedor e formato da fila |

### O que a etapa A deixou pronto para B, C e D

Nada abaixo precisa ser criado — foi tudo entregue na etapa A e está à espera de quem consuma:

| Peça | Onde | Serve a |
|---|---|---|
| `Psp_model::obterBoleto()` | devolve o PDF em base64, do banco ou buscando | **B** (anexo do e-mail) |
| `crm_invoices.sent_at` | migration 034 | **B** (não reenviar) |
| `crm_invoices_v.registration` | migration 035 | **B** (só envia `registrada`) |
| `Psp_provider::interpretarWebhook()` | devolve **só** `charge_id` e tipo do evento | **C** |
| `crm_psp_webhook_events` | migration 034 | **C** (auditoria do recebido) |
| `crm_psp_accounts.webhook_token` | migration 034, UNIQUE global | **C** (resolve tenant+PSP pela URL) |
| `paid_at`, `paid_amount`, `paid_method` | migration 034 | **C** e **D** |
| `Psp_inter::registrarWebhook()` | `PUT /cobranca/v3/cobrancas/webhook` | **C** |
| `Psp_inter::listarCobrancas()` | envelope com `ultimaPagina` como critério de parada | **D** |
| `Psp_model::processarPendentes()` | regra única, já com escopo e orçamento | **D** (a metade do registro) |

**A regra de segurança que C precisa herdar**: `interpretarWebhook()` devolve só o `charge_id`
justamente para forçar a reconsulta — **nunca acreditar no valor que vem no corpo**. A assinatura do
webhook do Inter **não foi confirmada**, e a defesa real é essa reconsulta, não a URL secreta.

**O que D recupera além do pagamento**: fatura aberta com `psp_charge_id` vazio é a fila de registro,
e a mesma rodada deve tratá-la. Hoje isso só acontece no `cron_gerar_faturas`.

### Pendências de verificação (o sandbox do Inter caiu no meio)

| O quê | Situação |
|---|---|
| **Adoção de cobrança órfã** | Implementada e **não testada ponta a ponta**. É o conserto do HTTP 500: uma emissão que falha de forma ambígua marca `FALHA_ENVIO`, e a tentativa seguinte **procura antes de criar**, casando pelo `seuNumero`. O mecanismo está confirmado (o `seuNumero` volta na listagem em 19/19 itens); falta exercitar o caminho inteiro, que exige criar uma cobrança e simular o 500. |
| **Assinatura do webhook** | Desconhecida. Confirmar ao registrar o webhook na etapa C. |
| **Credencial de produção** | Só há sandbox. Boleto registrado costuma exigir homologação com o banco. |

### Boleto e NF vão no MESMO e-mail — e o que isso faz com a ordem das etapas

**Requisito de negócio, não preferência técnica** (definido em 18/08/2026): o cliente que recebe
boleto **com** nota fiscal recebe **um único e-mail**, com os dois anexos. Duas razões, as duas do
lado de fora do código:

- **os clientes exigem** — boleto e nota chegando separados geram dúvida sobre o que pagar e
  retrabalho de atendimento;
- **produção usa o Brevo**, e cada disparo é custo: juntar os dois corta pela metade o volume de
  e-mails dos contratos que emitem nota.

> Correção a uma versão anterior deste documento, que tratava o envio conjunto como *"otimização de
> conveniência"*. **Não é.** É requisito, e o desenho da etapa B tem de nascer com ele.

**O que isso muda**: para `invoice_policy = 'com_boleto'`, a fatura **não pode ser enviada antes de a
nota existir** — mandar só o boleto e a nota depois é justamente o que o requisito proíbe.

#### ⚠️ Os números do banco local NÃO descrevem a produção

Uma versão anterior desta seção usou as contagens do banco de desenvolvimento como se fossem a
realidade, e concluiu que `com_boleto` "não afeta contrato nenhum". **Está errado.**

| | Banco local (teste) | Produção |
|---|---|---|
| Contratos em `com_boleto` | 0 de 403 | **existem** — não são a maioria, mas são reais |
| Contratos já migrados para `cdwfinance` | 1 | **a migração está em andamento** |
| Serviço do ERP vinculado | 1 de 403 | não medido daqui |

O banco local é uma **base de teste**: as contagens dele servem para exercitar código, não para
dimensionar decisão. Qualquer número de produção precisa ser levantado lá.

#### O que a produção muda no problema

Hoje, quem recebe **boleto + NF no mesmo e-mail** já recebe assim — pelo **Bom Controle**, que emite
os dois. Ou seja, o envio conjunto não é funcionalidade nova a construir: é **comportamento existente
que a migração não pode regredir**.

Isso reposiciona a etapa E. Ela deixa de ser "um passo adiante no fluxo" e passa a ser
**pré-requisito de migração para uma parte da carteira**:

> Um contrato que hoje recebe boleto + NF **não pode ser virado para `cdwfinance` antes da etapa E**.
> Se for, o cliente passa a receber só o boleto — regressão que ele percebe no primeiro mês.

O `billing_source` por contrato já protege isso: esses contratos simplesmente **continuam no ERP**
até E existir. Não há risco de regressão acidental, desde que quem conduz a migração saiba disso —
por isso está escrito aqui.

#### A ordem recomendada continua B → E, e agora por um motivo mais forte

Não é mais "o `com_boleto` não afeta ninguém". É:

- **B destrava a migração da maioria** — os contratos sem NF podem ser virados assim que o envio do
  boleto existir, e hoje eles não são virados porque ninguém receberia a cobrança;
- **E destrava a minoria** que recebe NF, e é ela que tira esses contratos do ERP;
- **E é bloqueada por trabalho operacional**, não por código: o vínculo do serviço do ERP em cada
  contrato, feito à mão (o catálogo do BC tem 119 serviços e não casa por nome). A tela já existe
  (`Contratos::json_postvincularservicobc`), então esse trabalho **corre em paralelo com a B**.

Também sobrevive a qualquer ordem: `com_boleto` emite a nota **antes** do pagamento, então a baixa no
ERP tem de vir depois, disparada por C ou D. **`com_boleto` completo depende de E + C + D.**

#### A carteira de produção, e o que cada grupo precisa para migrar

Distribuição informada em 18/08/2026:

| Política | Fatia | O que o contrato precisa para sair do ERP |
|---|---|---|
| `com_boleto` — NF junto do boleto | **15%** | B + **E** |
| `pos_compensacao` — NF depois do pagamento | **50%** | B + **C** + **D** + **E** + **F** |
| `nao_emitir` — só boleto | **35%** | **B** |

**65% da carteira depende da nota fiscal.** Só os 35% de `nao_emitir` migram com a B sozinha.

#### A conclusão que os números mudam: depois da B vem C+D, não E

Eu vinha tratando **D** como "proteção" e **C** como o passo do pagamento. Com esta distribuição, os
dois viram **pré-requisito universal**:

- **`pos_compensacao` são 50% da carteira, e o gatilho da nota deles é o PAGAMENTO.** Sem C e D não
  há como saber que o cliente pagou, então a etapa E **não tem o que emitir** para metade da base.
  Antecipar E serviria só aos 15% de `com_boleto`.
- **Todo contrato migrado precisa de C+D, inclusive os 35% que não emitem nota.** Sem elas a fatura
  paga fica marcada como vencida para sempre: a baixa manual **está escondida na tela** (o botão
  segue comentado, à espera da automação), então hoje **não há caminho de interface para marcar uma
  fatura como paga**. Um contrato migrado hoje nunca vê a fatura quitada.

> Ordem recomendada: **B → C+D → E+F**.
> A B destrava o envio para 100% e a migração para 35%; C+D destravam o reconhecimento do pagamento
> para 100% e o gatilho da nota para 50%; E+F destravam os 65% que emitem nota.

Antecipar E para antes de C+D inverteria a prioridade: entregaria a nota aos 15% e deixaria os 50%
esperando o gatilho, com todos os migrados ainda sem baixa.

#### O que corre em paralelo, sem código

Os **vínculos de serviço do ERP** continuam sendo o gargalo operacional da E, e não dependem de
nenhuma linha nova — a tela já existe. Priorizar nesta ordem:

1. os **15% de `com_boleto`**, que são os primeiros a poder migrar depois da E (o gatilho deles é a
   geração da fatura, não o pagamento);
2. os **50% de `pos_compensacao`**, que só migram com a cadeia inteira pronta.
#### Como a B nasce já pronta para o envio conjunto

O requisito entra no **desenho da B**, não numa reforma futura. Três decisões, todas baratas agora e
caras depois:

| Decisão | Por quê |
|---|---|
| A rotina de envio monta uma **lista de anexos**, não um anexo | Acrescentar a nota (PDF + XML) depois vira uma linha, não uma reescrita |
| A fila de envio tem um **portão por política**: `com_boleto` só fica elegível quando a nota estiver emitida | É o que garante o e-mail único. Sem o portão, a B mandaria só o boleto — a regressão exata que o requisito proíbe |
| `nao_emitir` e `pos_compensacao` enviam **na hora** | Não há nota a esperar. `pos_compensacao` recebe a nota depois, em mensagem própria (etapa F) — ali o e-mail separado é o correto, porque o pagamento já aconteceu |

Com isso, a etapa E **não toca na B**: quando a nota passa a ser emitida, os contratos `com_boleto`
ficam elegíveis e saem com os dois anexos.


### Etapa J — a conta a receber no ERP (migration 046)

O dinheiro cai na conta e alguém precisa conciliá-lo, no Bom Controle, contra um título. Para parte
da carteira esse título **não existia**, e o crédito chegava sem contrapartida.

#### A regra que decide tudo: só `nao_emitir` ganha título aqui

**Quem emite nota já tem título no ERP, e criar outro dobraria a receita.** O
`CriarVendaProdutoServico` da etapa E cria a venda **e as parcelas do financeiro** — tanto que aquela
rotina termina no `Fatura/EfeturarPagamento`, sem o qual "o BC acumula recebíveis fantasmas, um por
fatura". Somar um `CriarOutroRecebimento` a isso faria a mesma fatura contar duas vezes.

Sobra `invoice_policy = 'nao_emitir'`: nenhuma venda é criada, o ERP não sabe que a fatura existe.
Na distribuição de produção são os **35%**.

Os dois erros não custam o mesmo, e é isso que fixa o default: título a **menos** aparece na
conciliação como crédito órfão e se lança à mão; título a **mais** infla a receita em silêncio e só
aparece no fechamento. A decisão mora em `enfileirarRecebimento()`, ponto único — o webhook e a
conciliação chegam pelo mesmo `aplicarBaixa()` e não têm como divergir. `criarRecebimento()` repete
a checagem como defesa em profundidade, para quem chegar por outro caminho não dobrar a receita.

#### Como a fatura daqui é identificada no ERP

Vínculo de **mão dupla**, e cada ponta serve a um lado da conciliação:

| Direção | Onde | Observação |
|---|---|---|
| daqui → ERP | **`NumeroDocumento` = `crm_invoices.id`** | O campo é documentado como **Inteiro**, então a PK cabe sem conversão. Volta no `Financeiro/Pesquisar` e no `PesquisaDetalhada` (verificado no collection) |
| ERP → daqui | `bomcontrole_movement_id` e `bomcontrole_installment_id` | **GUIDs** (a doc os descreve como Texto) — daí `varchar(36)`, não `int`. A parcela é a chave do `Financeiro/Obter` |
| para o humano | `Observacao` = "Fatura N — CDW Finance — …" | É o que aparece na tela do ERP. Sem ela o título seria "um recebimento de R$ X" no meio de dezenas iguais |

⚠️ **`NumeroDocumento` não é filtro de busca em endpoint nenhum** — o `textoPesquisa` procura pelo
*nome da parcela*. Achar um título pelo id da fatura significa varrer a janela de datas do
`Financeiro/Pesquisar` e casar no PHP, que é o padrão do `conciliarPeriodo()`. Por isso o GUID
gravado aqui é a âncora real, e o `NumeroDocumento` é o que salva quando ela se perde — e é o que a
mensagem de erro manda procurar quando o ERP aceita sem devolver o Id.

#### O título nasce EM ABERTO, não quitado

`CriarOutroRecebimento` cria a movimentação com a parcela **prevista**; quitar é o
`Financeiro/EfetuarPagamento`, que esta etapa **não** chama. É deliberado: o título existe
justamente para o crédito do extrato ter contra o que ser conciliado, e um título já quitado não
aparece como pendência de conciliação. Quitar aqui também assumiria que o dinheiro já caiu na conta
— o PSP confirma o **pagamento**, e a liquidação bancária vem depois.

> Se a operação preferir o contrário (título já quitado, restando só conciliar), é **uma chamada a
> mais** — o `Financeiro/EfetuarPagamento` está documentado e a library já tem o molde. É decisão de
> quem concilia, não técnica.

#### O resto

- **O valor é o que ENTROU** (`paid_amount`), não o que foi cobrado: o cliente pode ter pago com
  juros ou desconto, e o título tem de bater com a linha do extrato. Mesma regra do `aplicarBaixa()`.
- **`FormaPagamento.Boleto` é OMITIDO**, como no `CriarVendaProdutoServico` e pelo mesmo motivo: a
  doc diz *"informar apenas quando houver emissão"*, e informá-lo mandaria uma **segunda cobrança**
  ao cliente. O que se usa é `Outros.Nome`, que apenas ROTULA.
- **A lista de formas de pagamento da ESCRITA não tem PIX**; ele vira `TransferenciaBancaria`, que é
  o que ele é. Valor desconhecido cai em `Outros` em vez de chutar.
- **`QuantidadeParcelas` é sempre 1**: o parcelamento já aconteceu aqui, e cada parcela nossa é uma
  fatura com o seu próprio título.
- **`receivable_status` repete a máquina de estados do `nf_status`**, e pelo mesmo motivo: GUID
  gravado significa "não criar de novo". O GUID é gravado **antes de qualquer outra coisa** — sem
  isso, uma falha de rede depois do POST faria a retentativa criar um segundo título.
- **`IdContaFinanceira` e `IdCategoriaFinanceira` são obrigatórios, e moram em lugares diferentes**:
  a **CONTA** é do tenant (Empresas › Bom Controle) porque é a conta bancária onde o dinheiro entra;
  a **CATEGORIA** é do **CONTRATO** (ao lado do serviço do ERP) porque classifica a receita, e isso
  varia por contrato — é como a operação já faz hoje direto no Bom Controle. A listagem de contas
  **exige o IdEmpresa**, então a 039 é pré-requisito da 046. O id é a verdade e o **nome é retrato**
  — gravado junto para a tela não ir à rede, lido do ERP e nunca do POST.
- **Não há padrão de categoria por tenant, de propósito.** Um default silencioso jogaria a receita do
  contrato numa classificação errada, e isso só apareceria no fechamento do mês. Sem categoria a fila
  **recusa e diz o motivo** — mesmo comportamento do `bomcontrole_service_id` na emissão da nota.
- **As guardas de configuração rodam antes de qualquer chamada** (medido: 1,6 ms para recusar sem
  conta cadastrada), e viram `falha` definitiva com o motivo na tela — gastar requisição para
  descobrir que falta cadastro é desperdício.

```
0 6 * * * php index.php cron cron_criar_recebimentos
```

Depois da conciliação, que é uma das duas vias que produzem a baixa.

### Por que a etapa G foi descartada

Encerrar o `VendaContrato` no ERP continua sendo **obrigatório** na virada de cada contrato — o que
mudou é quem faz: **uma pessoa, no painel do Bom Controle**, e não este sistema.

A razão é a assimetria do erro. Automatizar o encerramento significa dar a um cron o poder de
**desligar a cobrança de um contrato em produção**. Se ele errar o alvo, o cliente deixa de ser
cobrado e ninguém percebe até o fechamento do mês — enquanto o erro oposto (esquecer de encerrar) é
barulhento: o cliente recebe duas cobranças e reclama no mesmo dia. Com a migração sendo **gradual e
supervisionada**, o ganho de automatizar é pequeno e o risco não é.

**O que fica no lugar** — e passa a ser permanente, não um degrau temporário:

> Ligar o faturamento num contrato que ainda tem `bomcontrole_contract_id` **exige a confirmação
> explícita** de que ele foi encerrado no ERP (`Contratos::montarFaturamentoAtivo`). Sem a marcação,
> o SALVAR recusa.

Essa trava era descrita como provisória, "até a etapa G existir". **Não é mais**: ela é o controle
definitivo contra cobrança dupla na virada.

**Consequência para a library**: `encerrarVendaContrato()` não precisa ser escrito. É o único método
do fluxo que sai da lista.
### Por que a emissão é fila, e não chamada no webhook (etapa E)

Quatro razões, todas com consequência concreta:

- **A prefeitura cai.** A NFS-e depende do serviço municipal. Emissão síncrona no webhook falha com o
  serviço fora do ar e o evento se perde — o PSP já respondeu 200 e não reenvia.
- **O PSP retenta.** Se o handler demora ou erra, o PSP reenvia o mesmo evento. Handler que emite
  direto emite **duas notas** para a mesma fatura. Com fila, a idempotência é uma UNIQUE em
  `id_invoice` e o webhook responde 200 em milissegundos.
- **São três chamadas ao BC**, encadeadas, num rate limit que devolve 429 em ~12 seguidas. No cron
  isso é enfileirado e espaçado; no webhook, é timeout.
- **Nota fiscal não se cancela de graça.** Errar para menos custa uma rodada de cron; errar para mais
  custa carta de correção.

O `nf_status` precisa distinguir **falha temporária** (prefeitura fora, 429 → retenta) de
**definitiva** (cliente sem inscrição, serviço inválido → para e avisa na tela). Retentar em laço um
erro definitivo queima quota e esconde o problema.

### Por que a conciliação por pull existe (etapa D)

Webhook é entrega *best-effort*: o PSP fora do ar na hora do pagamento, o servidor daqui em deploy,
uma mudança de URL — qualquer um desses perde o evento em silêncio. O sintoma é o pior possível:
**fatura paga marcada como vencida**, cobrança de quem já pagou.

Um cron diário que lista as cobranças em aberto no PSP e concilia por `psp_charge_id` custa uma
requisição por rodada e fecha esse buraco. É a mesma lógica do `INTERVALO_DIAS_PADRAO` do WHOIS: a
consulta ao vivo é a exceção, o retrato periódico é a base.

---

## Catálogo de serviços do ERP — testado com a chave real (16/08/2026)

**É possível listar e guardar do nosso lado.** O que a consulta real mostrou:

| Fato | Detalhe |
|---|---|
| **Endpoint** | `GET /integracao/Servico/Pesquisar` — e **não** o `ProdutoServico/Pesquisar` que a doc sugere para listar sem filtro: aquele devolve `{Itens:[], TotalItens:0}` com `produto=false&servico=true` e **HTTP 500** com ambos `true`. |
| **`nome` não é obrigatório na prática** | A doc marca como obrigatório, mas `nome=` vazio devolve o catálogo inteiro. |
| **Paginação funciona** (embora não documentada aqui) | `paginacao.itensPorPagina` / `paginacao.numeroDaPagina`. Sem elas vêm 50; com 100 por página são 2 requisições. |
| **Tamanho do catálogo hoje** | **119 serviços** (`TotalItens: 119`). |
| **Resposta vem em envelope** | `{Itens, TotalItens}`, e não array puro — a mesma divergência do `VendaContrato/Pesquisar`, que o `normalizarLista()` da library **já trata**. |
| **Campos de cada item** | `Id`, `Nome`, `Observacao`, `Valor` (vem `null`), `IdTipoServico`, `NomeTipoServico`. |
| **`NomeTipoServico` é o código fiscal** (LC 116) | Ex.: "Armazenamento ou hospedagem de dados…", "Programação", "Assessoria e consultoria em informática". **É ele que determina a tributação da NFS-e** — escolher o serviço é escolher o enquadramento fiscal. |
| **Rate limit é agressivo** | Uma sequência de ~12 consultas seguidas já devolveu `429 Too Many Requests`. A tela de de-para deve buscar **sob demanda**, nunca varrer o catálogo a cada abertura. |

Os nomes **não casam automaticamente** com os nossos tipos — o catálogo do ERP é bem mais granular:

| Nosso `crm_service_types` | Candidato no ERP |
|---|---|
| CDWChat | `121` SUPORTE/HOSPEDAGEM CDWCHAT - MENSAL |
| Site institucional | `123` SUPORTE TÉCNICO PARA SITE - MENSAL |
| Sistema | `122` SUPORTE TÉCNICO PARA SISTEMA - MENSAL |
| Gerenciamento de Domínios | `89` GERENCIAMENTO DE DOMÍNIO - RENOVAÇÃO ANUAL |
| E-mails, Loja virtual, Landing page… | há vários candidatos (HOSPEDAGEM …, CRIAÇÃO E DESENVOLVIMENTO …) |

Ou seja: a seleção precisa ser **humana**, uma vez, numa tela — não dá para casar por nome.

## Decisões que ficaram em aberto

| Assunto | Por que importa | Opções |
|---|---|---|
| **Fatura cancelada pode reabrir?** | Cancelar é terminal: não há transição `cancelada → aberta`, e a linha cancelada segue ocupando a UNIQUE — **a competência nunca mais é gerada** (verificado). Cancelar por engano custa aquele mês para sempre. | Permitir `cancelada → aberta` reusa tudo o que existe: a fatura volta com `psp_charge_id` vazio, cai sozinha na fila e o cron emite um boleto novo. Sem migration. É decisão de negócio, não técnica. |
| **Expurgo dos boletos guardados** | O PDF vive no banco em base64 (+33%): ~92 KB por boleto, da ordem de **440 MB/ano** a 4.800 faturas. | Apagar o PDF de fatura paga há N meses. O arquivo é reconstituível pela API enquanto a cobrança existir lá, então o expurgo não perde informação — só o atalho. Decidir quando o volume incomodar, não antes. |

**Resolvidas pelas etapas B e E** (19/08/2026) — estavam listadas acima como pendentes e saíram
daqui porque o código já respondeu:

| Era a dúvida | Como ficou |
|---|---|
| **Quem notificar o cliente** | `Notification_model` é o **resolvedor único**: o contrato vence quando tem ao menos um `destinatario`; senão cai na cascata do cliente (contato `financeiro` → qualquer contato com e-mail → `crm_customers.email`). Lista vazia é "não configurado", **não** "não avisar" — é o que mantém os contratos existentes funcionando sem ninguém preencher nada. As **cópias do contrato são preservadas** mesmo quando o "para" veio da cascata. |
| **Gatilho da emissão por política** | A fila da NF tem **duas portas**, e o `invoice_policy` decide qual: `com_boleto` enfileira na **geração** (a nota precisa existir antes do envio, porque vai no mesmo e-mail) e `pos_compensacao` enfileira na **baixa**. `nao_emitir` não entra. A decisão mora só em `enfileirarNota()` — quem chama não precisa conhecer a política. |
| **`IdEmpresa` no ERP** | `crm_companies.bomcontrole_company_id` (migration 039), com botão na aba Bom Controle. Duas descobertas da conta real moldaram o método: **`Empresa/Pesquisar` não filtra por documento** (a chamada usa termo vazio e a chave já escopa o tenant) e **o CNPJ do ERP pode divergir do cadastro local** — por isso o documento é **conferência, não âncora**, e a divergência sai como aviso amarelo. |

**Resolvida** (18/08/2026): **qual PSP** — ficou o **Banco Inter**, exercitado contra o sandbox real.
O desenho não amarra a decisão: o PSP é escolha do contrato e a allowlist de
`Psp_model::providers()` aceita outro provedor com uma library e uma linha.

**Resolvidas** (migration 025): o vínculo do serviço ficou no **contrato**, não no tipo de serviço.
Isso derrubou as duas questões que estavam aqui — o escopo por tenant vem de graça (o contrato já
tem `id_company`) e não há rateio a decidir, porque um serviço por contrato significa **um item com
o valor da fatura**, mesmo nos 215 contratos que têm dois tipos de serviço.

## Riscos conhecidos

| Risco | Detalhe |
|---|---|
| ~~Chave escopada por módulo~~ | **Descartado**: a chave é escopada por tenant e tem todos os escopos necessários. Confirmado em consulta real ao `Servico/Pesquisar` (HTTP 200). |
| ~~NF depois da compensação pode não ser possível pela API~~ | **Resolvido pela arquitetura**: com a cobrança fora do ERP, a venda só é criada **depois** do pagamento, e o `CriarVendaProdutoServico` tem o booleano `NotaFiscalServico.Emite` explícito. "Emitir depois da compensação" deixou de ser um modo especial e virou apenas *chamar mais tarde*. |
| **`Venda/CriarVendaProdutoServico` nunca foi exercitado com chave real** | Todo o desenho da etapa E depende dele. Testar **antes** de escrever a fila, num contrato de valor baixo — nota fiscal emitida errado se cancela com carta de correção, não com `DELETE`. Conferir também se `Venda/Obter` traz o `IdFatura` já na resposta imediata ao `Criar` (pode haver processamento assíncrono no lado do ERP). |
| ~~Venda no ERP fica em aberto se a baixa falhar~~ | **Endereçado na etapa E**: `nf_status` tem o estado intermediário `venda_criada`, e `bomcontrole_sale_id` é gravado **imediatamente** após o passo 1. A retentativa **não recria a venda** — id preenchido significa nota já emitida. Continua sendo o ponto mais delicado da fila, e é por isso que a primeira execução tem de ser supervisionada. |
| **Webhook é superfície pública** | Endpoint sem sessão, fora do `MY_Controller`. **A defesa principal está feita**: o corpo nunca é acreditado (`interpretarWebhook()` devolve só o `charge_id`), a verdade vem da reconsulta, e um POST forjado dizendo `RECEBIDO` **não baixa a fatura** quando a reconsulta falha (verificado). O que **falta** é a validação de assinatura — a do Inter não foi confirmada, e a URL secreta é só a primeira barreira. |
| **Rate limit na emissão em lote** | Três chamadas por nota × faturas do dia, contra um limite que devolve 429 em ~12 seguidas (o do Inter é pior: ~6). A fila tem espaçamento próprio e teto por rodada, e 429 volta para a fila em vez de virar `falha`. **Não medido em lote real** — o teto pode precisar de ajuste na primeira rodada de verdade. |
| **Cobrança dupla na virada** | Contrato ativo nos dois lados cobra o cliente duas vezes. A tela **bloqueia** a ativação até a confirmação manual de que foi encerrado no ERP — e esse é o controle **definitivo**, já que a etapa G foi descartada. Encerrar no Bom Controle é passo obrigatório do roteiro de migração. |
| **Receita dobrada no ERP (etapa J)** | Se a etapa J passasse a criar título para contrato que **emite nota**, a mesma fatura contaria duas vezes no financeiro do BC — a venda da etapa E já cria a parcela. A guarda está em `enfileirarRecebimento()` e repetida em `criarRecebimento()`, mas é a regra mais frágil desta etapa: **qualquer mudança na política de NF precisa reler as duas**. O erro é silencioso e só aparece no fechamento do mês. |
| **Boleto duplicado (PSP + ERP)** | O `CriarVendaProdutoServico` gera boleto se o objeto `FormaPagamento.Boleto` for informado. Na etapa E ele é **omitido**, sempre. Um `Boleto: {}` copiado do exemplo do collection manda uma segunda cobrança ao cliente. |

## Pendências operacionais (não são código)

| Item | Situação |
|---|---|
| **Índices de reajuste** | A tabela está **vazia**. Sem os 12 meses da janela, nenhum contrato é reajustado — a rotina pula e registra os meses faltando. Lançar em Gestão › Índices de reajuste. IGP-M e IPCA têm série no SGS do Banco Central (189 e 433); o **ICTI** não, então é lançamento manual de qualquer forma. |
| ~~Conferir o CNPJ da empresa~~ | ✅ **Resolvido em 30/08/2026**: o cadastro local passou a `22863460000186`, o mesmo do ERP. Era o bloqueador da etapa E — a nota sai no CNPJ da empresa **do ERP**, e a divergência faria toda nota emitida sair errada. |
| ~~Levantar em PRODUÇÃO quantos contratos recebem NF~~ | **Levantado (19/08/2026)**: `com_boleto` **15%**, `pos_compensacao` **50%**, `nao_emitir` **35%**. Foi o número que definiu a ordem — os 35% dependem só de B; os **65%** restantes dependem de E e F, e por isso do trabalho de vínculo de serviço. |
| **Conta financeira do tenant** | Pré-requisito da etapa J: em **Empresas › Bom Controle**, CARREGAR DO BOM CONTROLE e escolher a conta que recebe. Uma vez por empresa. Exige o **Id da empresa** já resolvido. |
| **Categoria financeira por contrato** | Pré-requisito da etapa J, e é **por contrato** — na tela de cada um, ao lado do serviço do ERP. Só importa nos contratos `nao_emitir` (os 35%); sem ela a fila recusa na guarda, sem gastar requisição, e a fatura vira `falha` com o motivo na tela. É o mesmo trabalho manual do vínculo de serviço, e pode começar já. |
| **Vínculos de serviço do ERP** | Caminho crítico da etapa E, e é **trabalho humano**: o catálogo do BC tem 119 serviços granulares e não casa por nome. A tela já existe (aba do contrato) e **não depende de código novo** — pode correr em paralelo com a B, e encurta a E quando ela chegar. Priorizar os contratos que recebem NF, que são os bloqueados para migrar. |
| **Política de NF por contrato** | Na base **local** o `attributes.billing.needs_invoice` está vazio em todos os clientes (a importação do gestor-interno não trouxe o dado), então ali o padrão efetivo é `nao_emitir`. **Em produção a distribuição é outra** (15/50/35 acima) e cada contrato precisa da política definida à mão antes de migrar — errar aqui faz o cliente deixar de receber a nota, ou recebê-la quando não devia. |
| **Credencial de PRODUÇÃO do Inter** | Só existe sandbox cadastrado. Gerar a integração de produção no internet banking (com os escopos `boleto-cobranca.read/write` e `webhook.read/write`), enviar o par .crt/.key na aba **Cobrança (PSP)** da empresa e virar o ambiente para `producao`. Boleto registrado costuma exigir homologação com o banco e não é imediato. |
| **URL pública HTTPS para o webhook** | O Inter exige HTTPS com certificado válido, e o ambiente local (MAMP na 8081) não recebe. É pré-requisito da etapa C — e a razão de a etapa D entrar junto. |
| **Validade do certificado** | O certificado do Inter expira, e expirado **para toda cobrança do tenant de uma vez**. A aba mostra a data e avisa a 30 dias; renovar é reenviar o par pela tela. |
| **Parâmetros de faturamento** | Conferir em Parâmetros gerais › Faturamento: dias de antecedência da geração (10), dia de vencimento sugerido (10), antecedência do aviso de reajuste (30) e o texto do e-mail. |
| **Crontab** | O reajuste roda **antes** da geração, para a fatura do dia sair com o valor novo:<br>`0 5 * * * php index.php cron cron_reajustar_contratos`<br>`30 5 * * * php index.php cron cron_gerar_faturas`<br>Com as etapas D, E e J, entram ainda a conciliação, a fila de emissão e a de contas a receber — **depois** da geração:<br>`0 6 * * * php index.php cron cron_criar_recebimentos` |

## Comandos

```bash
cd /Applications/mampstack-7.4.33-0/apache2/htdocs/cdwfinance && /Applications/mampstack-7.4.33-0/php/bin/php index.php cron cron_gerar_faturas
cd /Applications/mampstack-7.4.33-0/apache2/htdocs/cdwfinance && /Applications/mampstack-7.4.33-0/php/bin/php index.php cron cron_reajustar_contratos
```

As duas rotinas são **CLI-only** — o botão EXECUTAR do painel Gestão › Cron não funciona para elas,
de propósito (uma rodada varre a base inteira e pelo navegador morreria no `max_execution_time`).
Para um contrato só, o botão **GERAR FATURA** da tela do contrato antecipa **uma** competência — a do
mês corrente, ou a mais antiga em atraso. Ele **não** faz o mesmo que a rodada: recusa competência
além do mês corrente, porque cada competência gerada virou um boleto registrado no banco do cliente.

Desde a etapa A o `cron_gerar_faturas` tem **duas fases**: gera as competências e depois **registra
as cobranças pendentes** no PSP, com espaçamento de 1,2 s (o rate limit do Inter estoura em ~6
chamadas seguidas) e orçamento de tempo. A segunda fase é a mesma regra dos botões da tela —
`Psp_model::processarPendentes()`, só com escopo e orçamento diferentes.

**Crontab, com o que existe hoje:**

```
0 5 * * *  php index.php cron cron_reajustar_contratos
30 5 * * * php index.php cron cron_gerar_faturas
```

O reajuste vem antes para a fatura do dia sair com o valor novo. Com as etapas B e D entram ainda o
envio e a conciliação, **depois** da geração:

```
0 8 * * *  php index.php cron cron_enviar_faturas        # etapa B
0 9 * * *  php index.php cron cron_conciliar_cobrancas   # etapa D
```

As duas já existem e estão registradas em `crm_cron_logs` (migrations 037 e 038).

⚠️ As duas rotinas novas precisam da linha correspondente em **`crm_cron_logs`**, criada na migration
que as introduzir — sem ela não aparecem no painel Gestão › Cron e o `isCronActive()` as trata como
inexistentes. A 034 **não** registrou `cron_conciliar_cobrancas` de propósito: uma rotina cadastrada
mas inexistente ofereceria um botão EXECUTAR que derruba a requisição.

## Referência dos endpoints do ERP usados no fluxo

Todos verificados no collection `docs/bomcontrole.postman_collection.json`.

| Etapa | Endpoint | Verbo | Retorno |
|---|---|---|---|
| 1 | `Cliente/Pesquisar`, `Cliente/Criar`, `Cliente/Alterar`, `Cliente/AlterarEndereco` | GET/POST/PUT | Id (inteiro puro) no `Criar`; **204 sem corpo** nos `Alterar` |
| 2 | `Servico/Pesquisar`, `Servico/Obter/{id}` | GET | `{Itens, TotalItens}` |
| E | `Venda/CriarVendaProdutoServico` | POST | **Id da Venda** (inteiro) |
| E | `Venda/Obter/{id}` | GET | venda + parcelas + **Id da Fatura** |
| E | `Fatura/EfeturarPagamento/{id}` *(sic)* | PUT | — |
| F | `Fatura/Obter/{id}` | GET | links do **PDF e do XML** da NF |
| G | `VendaContrato/Encerrar/{id}` | DELETE | — |
| — | `Empresa/Pesquisar?pesquisa=` | GET | `[{Id, Documento, Nome, Padrao}]` — **não filtra por documento**, ver abaixo |


### `Empresa/Pesquisar` — medido na conta real (19/08/2026)

Três fatos que contrariam o que este documento supunha, e que moldaram o
`resolverIdEmpresa()`:

| Fato | Consequência |
|---|---|
| **A busca NÃO filtra por documento.** `pesquisa=<CNPJ>`, com ou sem máscara, devolve **lista vazia** | O CNPJ não serve de critério de busca; a chamada é feita com o termo **vazio**, que lista tudo — e a chave da API já escopa o resultado ao tenant |
| Termo vazio devolve todas as empresas da conta | Na conta da CDW há **uma só**, com `Padrao: true` |
| **O CNPJ no ERP diverge do cadastrado aqui** (`22863460000186` contra `18436107000142`) | O documento deixou de ser âncora e virou **conferência**: é mostrado ao usuário, e a divergência sai como **aviso amarelo**, nunca como erro que trave |

Com uma empresa só na conta, ela é adotada — mas a resposta sempre diz qual nome
e qual documento foram gravados, para a divergência ficar visível em vez de
silenciosa. Com várias e nenhuma casando pelo documento, o método **recusa e
lista as candidatas**: gravar a empresa errada faria toda nota futura sair no
CNPJ errado, e isso é problema fiscal, não de tela.

⚠️ **Pendência operacional**: conferir qual CNPJ está certo — o do cadastro local
ou o do ERP. Enquanto divergirem, a nota sai no da empresa do Bom Controle.
**Não usados de propósito**: `Fatura/GerarBoleto` e `FormaPagamento.Boleto` (o boleto é do PSP),
`VendaContrato/Criar` (o contrato é local), `Venda/Criar` (sem o booleano `Emite`).
