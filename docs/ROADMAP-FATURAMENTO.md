# Faturamento — próximas etapas

Situação em 17/08/2026, depois da **fundação** (migration 024) e do **vínculo de serviço do ERP**
(migration 025).

## O que já está pronto

O CDW Finance gera as próprias faturas, reajusta contratos por índice e avisa o cliente do reajuste
por e-mail. Tudo **local**: a geração de fatura não faz nenhuma chamada externa.

`crm_contracts.billing_source` decide quem cobra cada contrato (`bomcontrole` | `cdwfinance`), e o
default é `bomcontrole` — a base inteira continua sendo cobrada pelo ERP até alguém virar contrato a
contrato.

---

## Placar do ROADMAP — o que está pronto e o que falta

Situação verificada no código em **17/08/2026** (migration_version = 26).

Legenda: ✅ pronto · 🟡 parcial · ⬜ não implementado

### O fluxo desenhado, passo a passo

| # | Passo do ROADMAP | O que faz | Onde vive hoje | Situação |
|---|---|---|---|---|
| **1** | **Cadastro do cliente com integração BC** | Cliente é criado aqui e espelhado no ERP. Resolve o Id por `bomcontrole_customer_id` → busca por documento (**adota** o que existir) → só então cria. Documento repetido no ERP **recusa**, em vez de escolher o primeiro. | `Bomcontrole_model::sincronizarCliente()`, migration 023 (`bomcontrole_customer_id`, `bomcontrole_synced`, `state_registration`), botão **SINCRONIZAR CADASTRO** em `clientes/info` | ✅ |
| **2** | **Contrato no cdwfinance** | Contrato local (ciclo, valor, tipos de serviço, espaço). Sem `VendaContrato` no ERP — **mas com** o serviço do ERP vinculado, que é o que a NF do passo 7 exige. | migration 009 (`crm_contracts`), migration 025 (`bomcontrole_service_id` + `_name`), `Bomcontrole_model::vincularServico()` (revalida o Id no `Servico/Obter`) | ✅ |
| **3** | **Fatura recorrente** | Motor retomável: gera as competências pendentes uma a uma a partir de `next_competence`, teto de 12 por rodada. UNIQUE `(id_contract, competence)` impede cobrança dupla no banco, não em PHP. | migration 024 (`crm_invoices`), `Invoice_model` (`getBillableContracts`, `generateForContract`, `generateNow`), `Cron::cron_gerar_faturas`, tela `faturas`, botão **GERAR FATURA** | ✅ |
| **3b** | *(bônus)* **Reajuste anual por índice** | Acumulado composto sobre os 12 meses encerrados; janela incompleta **não** reajusta. Aviso ao cliente 30 dias antes, com marcadores editáveis. | migration 024 (`crm_adjustment_indexes`, `crm_contracts_adjustments`), `Adjustment_model`, `Cron::cron_reajustar_contratos`, tela `indices`, `views/emails/billing/adjustment.php` | ✅ |
| **4** | **Integra invoice com o banco/PSP** | Registrar a cobrança (boleto + PIX) no PSP e guardar `psp_charge_id`, `link_boleto`, `link_pix`. | **nada** — zero ocorrências de `webhook`, `pix`, `asaas`, `iugu`, `cora`, `inter` no `application/` | ⬜ |
| **5** | **Envia boleto ao cliente por e-mail** | Template + gatilho na geração da fatura. | **nada**. Só existe o e-mail de reajuste. Reusável: fila `cron_enviar_email`, `Adjustment_model::destinatario()` (cascata financeiro → qualquer contato → `crm_customers.email`) e o padrão de marcadores | ⬜ |
| **6** | **Webhook de liquidação** | Endpoint público, valida assinatura, reconsulta a cobrança, dá baixa e **enfileira** a NF. | **nada**. Hoje a baixa é **manual**: `Faturas::post_status` com a ação `pagar` (allowlist de transições `aberta→paga`, `paga→aberta`, `aberta→cancelada`) | 🟡 baixa existe, mas manual |
| **6.5** | **Conciliação por pull** *(acrescentado)* | Cron que varre as cobranças em aberto no PSP e concilia o que o webhook perdeu. | **nada** | ⬜ |
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

### O que falta no código, em detalhe

**Métodos que a library `Bom_controle` ainda não tem** (6, contra 11 implementados):

| Método a criar | Endpoint | Etapa |
|---|---|---|
| `criarVendaProdutoServico()` | `POST Venda/CriarVendaProdutoServico` | E |
| `obterVenda()` | `GET Venda/Obter/{id}` | E |
| `efeturarPagamentoFatura()` *(sic)* | `PUT Fatura/EfeturarPagamento/{id}` | E |
| `obterFatura()` | `GET Fatura/Obter/{id}` | F |
| `encerrarVendaContrato()` | `DELETE VendaContrato/Encerrar/{id}` | G |
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
  │  9. [BC VendaContrato/Encerrar] na virada do contrato                                              │
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

Numeração seguindo o fluxo acima. As etapas **A–C** são o caminho crítico; **D** e **G** são
proteções que não dependem delas.

| # | Etapa | O que entrega | Depende de | Decisão pendente |
|---|---|---|---|---|
| **A** | **Integração com o PSP/banco** | A fatura vira cobrança real: boleto + PIX registrados no PSP. Migration nova com `psp_charge_id`, `psp_status`, `link_boleto`, `link_pix`. Library nova no padrão de `Bom_controle` (nunca lança exceção, retorno padronizado). | Etapa 3 (**pronta**) | **Qual PSP** |
| **B** | **Envio do boleto por e-mail** | O cliente recebe o boleto/PIX. Reusa a cascata de destinatário e o modelo de texto editável do `Adjustment_model`. | A | Texto do e-mail |
| **C** | **Webhook de liquidação** | Endpoint público que recebe o aviso de pagamento, dá baixa na `crm_invoices` e **enfileira** a emissão da NF. | A | — |
| **D** | **Conciliação por pull** | `cron_conciliar_cobrancas`: varre as faturas em aberto no PSP e concilia o que o webhook não trouxe. Webhook se perde; sem isso, fatura paga fica marcada como vencida. | A | Cadência (sugerido: 1×/dia) |
| **E** | **Emissão da NF no ERP (com fila)** | `cron_emitir_notas` consome a fila: `Venda/CriarVendaProdutoServico` → `Venda/Obter` → `Fatura/EfeturarPagamento`. Migration com `bomcontrole_sale_id`, `bomcontrole_invoice_id`, `nf_status`, `nf_attempts`, `nf_last_error`, `nf_issued_at`. | C (o gatilho) + 2a (**pronta**) + `bomcontrole_customer_id` (**pronta**) | **`IdEmpresa`** e **gatilho por política** |
| **F** | **Envio da NF por e-mail** | `Fatura/Obter/{id}` devolve **PDF e XML** da nota — os dois vão ao cliente. Campos `link_nota_fiscal` e `link_nota_fiscal_xml`. | E | — |
| **G** | **Encerrar o contrato no ERP ao migrar** | `VendaContrato/Encerrar` automático quando o contrato passa a `billing_source = 'cdwfinance'`. Remove a confirmação manual da tela. | — (camada de escrita já existe) | — |
| **H** | **Migração dos contratos** | Operação, não código: virar os contratos do ERP para cá, um a um. O filtro *"contrato sem vínculo com o Bom Controle"* da listagem de clientes (migration 026) é o painel de acompanhamento. | A–G | Ritmo da virada |

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
| **Qual PSP** | Define a library da etapa A e o formato do webhook da etapa C. O critério é webhook confiável + boleto **e** PIX na mesma cobrança + API de listagem para a conciliação (etapa D). | Asaas, Cora, Iugu, Banco Inter (API PJ). **Se a conta da CDW já é em algum desses, isso decide sozinho** — evita abrir relacionamento bancário novo.<br>O **Banco Inter** foi analisado em [PSP-BANCO-INTER-VIABILIDADE.md](PSP-BANCO-INTER-VIABILIDADE.md): atende os três critérios, mas cobra três consequências de desenho (credencial vira arquivo, emissão assíncrona, token com cache) que os outros três não cobram. |
| **Gatilho da emissão por política** | O `invoice_policy` já prevê três casos, e o ROADMAP descreve só um. `pos_compensacao` emite no webhook (etapa C); **`com_boleto` emite na geração da fatura** — gatilho diferente, mesma fila. `nao_emitir` não entra na fila. | Se `com_boleto` continua valendo para parte da base, a fila da etapa E tem **duas portas de entrada**. Se toda a base vira `pos_compensacao`, a etapa E fica com uma só. |
| **`IdEmpresa` no ERP** | Obrigatório em `Venda/CriarVendaProdutoServico`. Resolvido por `GET Empresa/Pesquisar?pesquisa=<CNPJ ou nome fantasia>` (não há "listar todas"), que devolve `Id`, `Documento`, `Nome`, `Padrao`. | Config nova **por tenant**, ao lado da chave do Bom Controle em `empresas/info` — mesmo lugar e mesmo motivo do `bomcontrole_secret`. Buscar pelo CNPJ da própria `crm_companies` e gravar o `Id`; o campo `Padrao` serve de conferência. |

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
| **Venda no ERP fica em aberto se a baixa falhar** | Se o `Fatura/EfeturarPagamento` falhar depois de a venda ter sido criada, o financeiro do BC guarda um recebível fantasma. A fila precisa tratar os dois passos como estados distintos (`nf_status`: emitida-sem-baixa é diferente de emitida) e retentar **só a baixa**, nunca a venda. |
| **Webhook é superfície pública** | Endpoint sem sessão, fora do `MY_Controller`. Precisa de validação de assinatura do PSP (todos os candidatos assinam), responder 200 rápido, e **nunca** confiar no valor que vem no corpo — reconsultar a cobrança no PSP antes de dar baixa, mesma regra do `bomcontrole_contract_id` que é revalidado no `Obter`. |
| **Rate limit na emissão em lote** | Três chamadas por nota × faturas do dia, contra um limite que devolve 429 em ~12 seguidas. A library já retenta com backoff, mas a fila precisa de espaçamento próprio e teto por rodada. |
| **Cobrança dupla na virada** | Contrato ativo nos dois lados cobra o cliente duas vezes. Hoje a tela **bloqueia** a ativação até a confirmação manual de que foi encerrado no ERP; a etapa G automatiza. |
| **Boleto duplicado (PSP + ERP)** | O `CriarVendaProdutoServico` gera boleto se o objeto `FormaPagamento.Boleto` for informado. Na etapa E ele é **omitido**, sempre. Um `Boleto: {}` copiado do exemplo do collection manda uma segunda cobrança ao cliente. |

## Pendências operacionais (não são código)

| Item | Situação |
|---|---|
| **Índices de reajuste** | A tabela está **vazia**. Sem os 12 meses da janela, nenhum contrato é reajustado — a rotina pula e registra os meses faltando. Lançar em Gestão › Índices de reajuste. IGP-M e IPCA têm série no SGS do Banco Central (189 e 433); o **ICTI** não, então é lançamento manual de qualquer forma. |
| **Política de NF por contrato** | `attributes.billing.needs_invoice` está vazio nos 386 clientes (a importação do gestor-interno não trouxe o dado), então o padrão efetivo é `nao_emitir` e cada contrato precisa ser definido à mão. |
| **Conta no PSP** | Abrir/habilitar a conta, o convênio de cobrança e as credenciais de produção **antes** da etapa A. Boleto registrado depende de homologação com o banco e não é imediato. |
| **Parâmetros de faturamento** | Conferir em Parâmetros gerais › Faturamento: dias de antecedência da geração (10), dia de vencimento sugerido (10), antecedência do aviso de reajuste (30) e o texto do e-mail. |
| **Crontab** | O reajuste roda **antes** da geração, para a fatura do dia sair com o valor novo:<br>`0 5 * * * php index.php cron cron_reajustar_contratos`<br>`30 5 * * * php index.php cron cron_gerar_faturas`<br>Com as etapas D e E, entram ainda a conciliação e a fila de emissão — **depois** da geração. |

## Comandos

```bash
cd /Applications/mampstack-7.4.33-0/apache2/htdocs/cdwfinance && /Applications/mampstack-7.4.33-0/php/bin/php index.php cron cron_gerar_faturas
cd /Applications/mampstack-7.4.33-0/apache2/htdocs/cdwfinance && /Applications/mampstack-7.4.33-0/php/bin/php index.php cron cron_reajustar_contratos
```

As duas rotinas são **CLI-only** — o botão EXECUTAR do painel Gestão › Cron não funciona para elas,
de propósito (uma rodada varre a base inteira e pelo navegador morreria no `max_execution_time`).
Para um contrato só, o botão **GERAR FATURA** da tela do contrato faz o mesmo sem terminal.

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
| — | `Empresa/Pesquisar?pesquisa=` | GET | `[{Id, Documento, Nome, Padrao}]` |

**Não usados de propósito**: `Fatura/GerarBoleto` e `FormaPagamento.Boleto` (o boleto é do PSP),
`VendaContrato/Criar` (o contrato é local), `Venda/Criar` (sem o booleano `Emite`).
