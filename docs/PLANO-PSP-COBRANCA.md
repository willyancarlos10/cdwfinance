# Plano de implementação — cobrança via PSP, com PSP selecionável por contrato

Fecha a etapa **A** do [ROADMAP-FATURAMENTO.md](ROADMAP-FATURAMENTO.md) e substitui a premissa de PSP
único do [PSP-BANCO-INTER-VIABILIDADE.md](PSP-BANCO-INTER-VIABILIDADE.md).

**Requisito novo (18/08/2026):** o PSP é **escolha do contrato**. Começa só com o Banco Inter, mas
outro banco ou fintech entra depois sem reescrever nada — e os dois convivem na mesma base, ao mesmo
tempo.

---

## O que mudou desde o estudo do Inter

O código andou entre 17 e 18/08. Verificado agora, direto no banco e nos arquivos:

| Item | Como estava no estudo | Como está hoje |
|---|---|---|
| Última migration | 030 | **033** (`migration_version = 33`) → a do PSP é a **034** |
| `crm_invoices` | 16 colunas | **18**: ganhou `id_charge`, `installment_number`, `installments_total` |
| `Invoice_model::criarFatura()` | privada, 4 argumentos | **pública**, e recebe `array $parcela` |
| Destinatários por contrato | não existia | **`crm_contracts.notification_config`** (033), inerte |
| Base | 402 contratos, 1 fatura | 402 `bomcontrole` · **1** `cdwfinance` · **0 faturas** |

> ⚠️ **Correção**: o estudo do Inter e o ROADMAP diziam migration **031** para o PSP. A 031 já é o
> parcelamento. É a **034** — corrigido nos dois documentos.

Duas dessas mudanças são boas notícias para este plano: **a `crm_invoices` está vazia**, então nenhuma
coluna nova precisa de backfill; e **`notification_config` está preenchido em zero contratos**, o que
abre uma janela para decidir a regra de destinatário sem quebrar nada (seção "Decisões que este plano
fecha").

---

## A decisão nova: o PSP é escolha do contrato

### Onde mora a escolha

**`crm_contracts.psp`**, varchar(20), slug, default `''`.

É o idioma do projeto para atributo de contrato: `status`, `cycle`, `billing_source`,
`invoice_policy`, `adjustment_index` e `ended_reason` são **todos** slug com catálogo à parte. Não é
FK pelo mesmo motivo do `ended_reason`: o valor precisa sobreviver ao catálogo.

**`psp` não é valor de `billing_source`.** São perguntas diferentes: `billing_source` responde *quem é
dono da fatura* (o ERP ou nós), `psp` responde *quem registra a cobrança*. Empilhar as duas num campo
só (`bomcontrole | inter | asaas`) faria "trocar de banco" parecer "voltar a ser cobrado pelo ERP" — e
o `post_faturamento` zera `next_competence` quando volta para `bomcontrole`, o que numa troca de PSP
apagaria a âncora do motor e geraria faturas retroativas.

**Vazio é estado válido e bloqueia a virada.** `montarFaturamentoAtivo()` passa a exigir o PSP no
mesmo lugar em que já exige `billing_day` — porque desde a migration 029 *"a fatura É o boleto"*, e
faturar sem PSP produziria uma fatura que ninguém consegue pagar.

### Por que a fatura carrega o próprio PSP

**`crm_invoices.psp`** é snapshot, congelado na geração — ao lado de `value`, `description` e
`invoice_policy`, pelo mesmo motivo que já vale para eles.

Só que aqui o snapshot não é histórico: é **roteamento**. Se o contrato migrar do Inter para outro
PSP em março, as cobranças de janeiro e fevereiro continuam vivas **no Inter**. Quem precisa saber
disso:

- o **webhook**, para achar a fatura a partir de um `charge_id` que só existe num dos PSPs;
- a **conciliação**, para perguntar ao PSP certo;
- o **cancelamento**, quando `Faturas::post_status` cancelar uma fatura já registrada;
- o **link do boleto**, que a tela mostra.

Ler o PSP do contrato na hora de consultar daria a resposta errada exatamente depois de uma troca —
o momento em que ninguém está olhando.

### Por que a credencial vira tabela, e não colunas em `crm_companies`

O molde do Bom Controle (`bomcontrole_active`, `bomcontrole_base_url`, `bomcontrole_secret`) **não
serve**, e o motivo é o requisito novo:

> Se o PSP é escolha do contrato, um mesmo tenant precisa de **credenciais de vários PSPs ativas ao
> mesmo tempo**.

Em colunas, cada PSP novo acrescentaria um jogo inteiro (`inter_*`, `asaas_*`, …) à `crm_companies` e
obrigaria a recriar a `crm_companies_v` a cada um. Em tabela, PSP novo é **linha**, não DDL.

**`crm_psp_accounts`**, UNIQUE `(id_company, psp)`. Mantém as duas regras que o projeto já aplica a
credencial: valor cifrado com `Secret_crypto` e **fora de qualquer view** — a tela lê da view e o
segredo nunca chega ao navegador.

O certificado do Inter é o caso que não cabe em coluna cifrada: o `CURLOPT_SSLCERT_BLOB` **não existe
no PHP 7.4** (verificado nesta máquina; foi exposto no 8.1), então o cURL só aceita **caminho de
arquivo**. Por isso a tabela guarda `cert_path`/`key_path` apontando para fora do webroot, e não o PEM
— decisão já discutida no estudo do Inter. O campo `extra` (JSON) absorve o que for específico de cada
PSP sem migration nova, no mesmo espírito do `attributes` do cliente.

### O registro de provedores

`Psp_model::providers()` é a **allowlist**, no idioma de `statusTransicoes()`, `endReasons()` e
`faixasDominio()`: ela alimenta o select da tela **e** valida o POST, e slug desconhecido é **erro**,
nunca "usa o padrão".

```php
// Acrescentar um PSP = uma library + esta linha + uma linha em crm_psp_accounts.
'inter' => ['classe' => 'Psp_inter', 'nome' => 'Banco Inter', 'ativo' => TRUE],
```

A abstração segue **`Whois_provider`**, que já resolve o mesmo problema no projeto (duas origens,
regras idênticas depois da resposta):

```php
abstract class Psp_provider
{
    abstract public function slug();
    abstract public function nome();
    abstract public function test(array $config);
    abstract public function criarCobranca(array $config, array $cobranca);
    abstract public function consultarCobranca(array $config, $chargeId);
    abstract public function cancelarCobranca(array $config, $chargeId, $motivo);
    abstract public function listarCobrancas(array $config, array $filtros);
    abstract public function registrarWebhook(array $config, $url);
    abstract public function interpretarWebhook(array $cabecalhos, $corpoCru);
}
```

Retorno padronizado em todos, **sem nunca lançar exceção** (regra do `Bom_controle`):
`['success', 'message', 'data', 'http_code', 'transient']`. O `transient` é o que separa "tente de
novo" de "não adianta" — mesma flag do `Whois_provider`, e é o que impede a fila de queimar
retentativa num 422.

Em `data`, **chave ausente = a origem não informou**, e o model não grava a coluna. É a regra do
CloudPanel com plano/IP/cota: sobrescrever com NULL apaga dado bom.

**`interpretarWebhook()` devolve só o `charge_id` e o tipo do evento — nunca valor ou status.** A
forma da interface é onde a regra de segurança do ROADMAP fica gravada: *"nunca confiar no valor que
vem no corpo"*. O trabalho do intérprete é responder **qual cobrança reconsultar**, e a verdade vem da
reconsulta.

---

## Migration 034 — o schema

Idempotente (`field_exists`, `table_exists`, `CREATE OR REPLACE VIEW`), sem `DEFINER`.

**`crm_contracts`** — 1 coluna:

| Coluna | Tipo | Observação |
|---|---|---|
| `psp` | varchar(20) NOT NULL DEFAULT `''` | slug; vazio = não definido, e bloqueia ativar o faturamento |

**`crm_invoices`** — 10 colunas:

| Coluna | Tipo | Observação |
|---|---|---|
| `psp` | varchar(20) NOT NULL DEFAULT `''` | **snapshot de roteamento** |
| `psp_charge_id` | varchar(100) NULL | id da cobrança no PSP. **Vazio numa fatura aberta = ainda não registrada** |
| `psp_status` | varchar(30) NOT NULL DEFAULT `''` | status cru do PSP, para diagnóstico |
| `link_boleto` | varchar(255) NULL | |
| `linha_digitavel` | varchar(60) NULL | o cliente copia sem abrir PDF |
| `link_pix` | text NULL | copia-e-cola é longo demais para varchar curto |
| `paid_at` / `paid_amount` / `paid_method` | datetime / decimal(12,2) / varchar(20) | `paid_at` preenchido = **guarda de idempotência** da baixa |
| `sent_at` | datetime NULL | quando o boleto foi enviado ao cliente |

Índices: `psp_charge_id` (a busca do webhook) e `(psp, psp_status)` (a varredura da conciliação).

**`crm_psp_accounts`** (nova) — credencial por tenant **e** por PSP:

`id`, `id_company`, `psp`, `active`, `environment` (`sandbox|producao`), `client_id`,
`client_secret` (cifrado), `cert_path`, `key_path`, `cert_expires_at`, `webhook_token`, `extra`
(JSON), auditoria. UNIQUE `(id_company, psp)` e UNIQUE `webhook_token`.

**`crm_psp_webhook_events`** (nova) — auditoria do recebido: `id_company`, `psp`, `charge_id`,
`event_type`, `payload`, `received`, `processed`, `id_invoice`. Com webhook possivelmente **não
assinado** (risco aberto do estudo do Inter), guardar o corpo cru é o que permite reconstruir o que
aconteceu.

**Views a recriar na mesma migration** — senão a tela não reflete o gravado:

| View | Base a copiar |
|---|---|
| `crm_contracts_v` | a da **033** (última a recriá-la) |
| `crm_invoices_v` | a da **031** (parcelamento) |

`crm_psp_accounts` **não ganha view** de propósito: a única coisa que a tela precisa mostrar são
`active`/`environment`/`cert_expires_at`, e uma view arrastaria as credenciais para perto do
navegador sem necessidade.

---

## As camadas

| Peça | Arquivo | Papel |
|---|---|---|
| Contrato comum | `application/libraries/Psp_provider.php` | abstrata; normaliza dinheiro, documento e data |
| Inter | `application/libraries/Psp_inter.php` | cURL + mTLS + OAuth com cache de token |
| Orquestrador | `application/models/Psp_model.php` | **ponto único**: resolve o provider pelo slug, carrega credencial, `sessao_suspender()` em volta de **toda** rede, normaliza |
| Webhook | `application/controllers/Webhook.php` | público, sem sessão, **fora do `MY_Controller`** |
| Config | aba **PSP** em `empresas/info` | ao lado da aba Bom Controle |

`Psp_model` é o único que os controllers chamam — mesma regra do `Bomcontrole_model` e do
`Server_model`. Nenhum controller instancia `Psp_inter`.

**Ponto de entrada no código existente**: `Invoice_model::criarFatura()`, o funil único por onde toda
fatura nasce (verificado: `generateNow` → `generateForContract` → `criarFatura`). A cobrança é criada
**depois do commit** da fatura, nunca dentro da transação — falha de rede não pode desfazer uma fatura
que a UNIQUE `(id_contract, id_charge, competence, installment_number)` já registrou.

---

## As etapas, na ordem

| # | Entrega | Depende de |
|---|---|---|
| **A1** | Migration 034 + `Psp_provider` + `Psp_inter` + `Psp_model` + aba de credenciais com **TESTAR CONEXÃO** | — |
| **A2** | Select do PSP no bloco Faturamento + criação da cobrança na geração da fatura + links na tela | A1 |
| **B** | E-mail com boleto/PIX (`cron_enviar_faturas`, `views/emails/billing/invoice.php`) | A2 |
| **C+D** | Webhook de liquidação **e** `cron_conciliar_cobrancas` | A2 |

**C e D entram juntos, não em sequência.** Dois motivos concretos:

1. O ambiente local (MAMP na porta 8081) **não recebe webhook** — sem a conciliação, A2 não tem como
   ser verificada aqui.
2. A emissão do Inter é **assíncrona** (`emitirCobrancaAsync`): o POST devolve o `codigoSolicitacao`,
   não a linha digitável. Quem preenche `link_boleto` é a consulta — ou seja, **a conciliação faz
   parte do caminho feliz**, não é só a rede de proteção que o ROADMAP previa.

---

## Três decisões que este plano fecha

### 1. Quem notifica: `notification_config` (033) ou a cascata do cliente?

O CLAUDE.md deixou isso explicitamente em aberto: *"Quando o envio existir, decidir explicitamente
qual vence — não deixar as duas convivendo sem regra."* O envio é a etapa B. Logo, decide-se agora.

**Regra proposta:** o `notification_config` do **contrato** vence quando tem ao menos um
`destinatario`; senão, cai na cascata do cliente (`financeiro` → qualquer contato com e-mail →
`crm_customers.email`).

Por quê: a lista do contrato é a resposta **mais específica** e foi preenchida de propósito. Vazia
significa "não configurado", **não** "não avisar" — e cair na cascata é o que mantém os 403 contratos
funcionando sem ninguém preencher nada.

Onde: um resolvedor único, usado **pelo boleto e pelo aviso de reajuste**, em vez de duas regras. Isso
muda o comportamento do e-mail de reajuste — mas só para contratos com `notification_config`
preenchido, que hoje são **zero** (verificado). É a janela mais barata que vai existir para unificar.

### 2. A fila de retentativa não precisa de tabela

Fatura **aberta** com `psp_charge_id` vazio **é** a fila: significa "gerada, ainda sem cobrança". A
conciliação da etapa D varre exatamente esse recorte e tenta de novo.

É o mesmo raciocínio que torna o motor de faturas retomável — o ponteiro (`next_competence`) é o
estado, não uma tabela de trabalho à parte. Uma `crm_psp_queue` seria uma segunda verdade sobre
"o que falta cobrar", capaz de discordar da `crm_invoices`.

### 3. Parcelamento: N boletos de uma vez

Desde a 031 as parcelas nascem **todas juntas** quando a competência abre, e cada parcela é uma linha
de `crm_invoices` — portanto **uma cobrança própria no PSP**. Um contrato anual em 12× gera 12
cobranças numa rodada.

Consequências para A2: **teto por rodada e espaçamento** na criação das cobranças, e nada de abortar a
rodada quando uma falha — as que falharem ficam com `psp_charge_id` vazio e a etapa D as recupera
(decisão 2). Hoje o maior parcelamento configurado é **1** (verificado), então o risco é futuro, não
imediato — mas o teto entra desde já, pelo mesmo motivo do `ORCAMENTO_SUSPENSAO_SEGUNDOS`.

---

## Passos finais

- **Rotas**: `webhook/psp/(:any)/(:any)` em `config/routes.php` → `Webhook`. O primeiro segmento é o
  slug do PSP; o segundo é o `webhook_token` do tenant. **Não reusar `crm_companies.token`** — aquele
  é semipúblico (vai no link de cadastro de cliente).
- **Menu/permissões**: nada novo. A tela de PSP é aba de `empresas/info`; o select é do contrato.
- **Migration**: criar a 034 **e** subir `migration_version` para 34.
- **Crontab** — a conciliação **depois** da geração:
  ```
  0 5 * * *  php index.php cron cron_reajustar_contratos
  30 5 * * * php index.php cron cron_gerar_faturas
  0 7 * * *  php index.php cron cron_conciliar_cobrancas
  ```
- **`crm_cron_logs`**: inserir a linha de `cron_conciliar_cobrancas` na migration — sem ela a rotina
  não aparece no painel e `isCronActive()` a trata como inexistente.
- **Certificados**: `application/certs/` fora do webroot, `0600`, com `.gitignore`.

## Como testar

- [ ] Migration sobe e desce sem erro; as duas views voltam ao formato da 033/031 no `down()`
- [ ] Tenant sem credencial de PSP: a tela do contrato **não** deixa ativar o faturamento
- [ ] `TESTAR CONEXÃO` com credencial errada devolve mensagem, **não** exceção
- [ ] Select do PSP com slug forjado no POST → **erro**, e não "usa o padrão"
- [ ] Gerar fatura → cobrança criada, `psp_charge_id` gravado
- [ ] Simular falha de rede na criação → fatura existe, `psp_charge_id` vazio, conciliação recupera
- [ ] Trocar o PSP do contrato → **as faturas antigas continuam apontando para o PSP antigo**
- [ ] Webhook com corpo forjado (valor alterado) → reconsulta e **não** dá baixa
- [ ] Webhook repetido do mesmo pagamento → baixa uma vez só (`paid_at`)
- [ ] Cancelar fatura na tela → cobrança cancelada no PSP
- [ ] Nenhuma credencial aparece em resposta JSON ou HTML

Antes de tudo isso, o **roteiro de 8 testes de sandbox** do estudo do Inter — em especial a latência
da emissão assíncrona, que é o número que decide o desenho da etapa B.

## O que fica de fora

Etapas **E–G** do ROADMAP (emissão da NF pelo ERP, envio da NF, encerramento do `VendaContrato`) e a
**H** (migrar os 402 contratos), que é operação. O gatilho da NF nasce na etapa C, mas a fila é
trabalho à parte.

---

## Coluna "Boleto" — plano (18/08/2026)

### O que a API do Inter entrega

**Não existe URL pública do boleto.** O PDF sai de um endpoint **autenticado**
(`GET /cobranca/v3/cobrancas/{codigoSolicitacao}/pdf`) e vem como **base64 dentro de um JSON**,
não como binário direto. A library já tem `Psp_inter::obterPdf()`, que decodifica e devolve os bytes
— mas **esse método nunca foi exercitado contra a conta real**, e o sandbox já corrigiu outras
suposições da mesma fonte (o caminho do webhook, o envelope da listagem, o `Accept`). **Confirmar é
o passo 1.**

É por isso que `link_boleto` fica vazia no Inter: não há link a guardar. A coluna continua fazendo
sentido para PSPs que publiquem URL — a tela deve preferir a URL quando houver e cair no arquivo
quando não.

### A consequência que decide o desenho

Como o PDF só se obtém autenticado, **a tela não pode apontar para o banco**: é preciso um endpoint
nosso que busque (ou sirva o arquivo já guardado) e faça o streaming.

### Guardar ou buscar a cada clique?

**Guardar.** Dois motivos, e o segundo é decisivo:

1. **O boleto é imutável depois de emitido** — o PDF de hoje é o mesmo de amanhã. Rebuscar é pagar
   uma chamada por uma resposta que não muda, contra um rate limit que estoura em ~6 seguidas.
2. **A etapa B precisa do arquivo de qualquer forma**: o e-mail leva o boleto **anexo** (não há link
   para mandar). Se o envio já vai baixar o PDF, buscar de novo no clique da tela é trabalho
   duplicado.

**Buscar sob demanda, guardar depois** — e não baixar no ato do registro: a maioria dos boletos nunca
é aberta na tela, e baixar todos na rodada do cron gastaria quota para encher disco.

### Onde guardar — e o que NÃO fazer

⚠️ **Não pode ir em `images/`.** A pasta é servida pelo Apache e não tem `.htaccess`; um boleto ali
fica acessível por URL, e o PDF traz **nome, documento, endereço e valor** do cliente. Nome
"difícil de adivinhar" não resolve: basta o link vazar de um e-mail encaminhado.

Vai para **`application/boletos/<id_company>/<ano>/<mes>/`**, que o `.htaccess` do `application/` já
nega por inteiro — mesma proteção dos certificados do PSP, e pelo mesmo motivo.

O acesso é por um controller que **confere o tenant** e faz o streaming. O caminho **nunca** vem da
requisição: só o `id` da fatura, e o path sai da linha do banco (mesma regra da exclusão de anexos,
que localiza pelo id e nunca pelo path do POST).

### Peças

| Peça | O quê |
|---|---|
| **Migration 036** | `crm_invoices.boleto_path` (caminho **relativo**, como o do certificado — absoluto quebra ao trocar de servidor) + recriar a `crm_invoices_v` |
| `Psp_model::obterBoleto($idInvoice, $idCompany)` | devolve o arquivo: usa o guardado; senão baixa pelo provedor da **fatura** (`crm_invoices.psp`), grava e devolve |
| `Faturas::boleto($id)` | streaming com `Content-Type: application/pdf` e `Content-Disposition: inline`, escopado por `getCurrentCompanyId()` |
| Coluna **Boleto** | nas três telas (listagem, aba do contrato, aba do cliente), só quando `registration = 'registrada'` |

### Regras que já dá para fixar

- **Só fatura `registrada` tem boleto.** Em `registrando` o arquivo ainda não existe no banco, e o
  botão levaria a um erro — o estado já diz para esperar.
- **Cancelar a cobrança apaga o arquivo**, junto com a linha digitável e o PIX: ele descreve um
  boleto que não existe mais, e é o mesmo motivo pelo qual aqueles campos já são limpos.
- **Falha no download não vira erro de tela em branco**: a coluna mostra o aviso e mantém a linha
  digitável, que é o que permite pagar sem o PDF.
- O nome do arquivo entregue ao usuário sai da fatura (`boleto-<id>-<competencia>.pdf`), não do id
  interno do PSP — quem baixa quer reconhecer o arquivo depois.

### Ordem sugerida

1. Confirmar o formato real do `/pdf` no sandbox (base64 em JSON? binário? outro nome de chave?).
2. Migration 036 + `obterBoleto()` + o endpoint de streaming.
3. A coluna nas três telas.
4. A etapa B (e-mail) reusa `obterBoleto()` para anexar — sem baixar de novo.
