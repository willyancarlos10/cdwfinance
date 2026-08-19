# Banco Inter como PSP — estudo de viabilidade

Responde à decisão **"Qual PSP"** da etapa **A** do [ROADMAP-FATURAMENTO.md](ROADMAP-FATURAMENTO.md).
Análise feita em **17/08/2026**.

## Veredito

**Viável, e é o candidato mais aderente ao que a etapa A pede.** A API de Cobrança v3 do Inter emite
**boleto e PIX na mesma cobrança** — que é exatamente o registro único que a `crm_invoices` precisa —,
tem webhook de liquidação e tem endpoint de listagem para a conciliação da etapa D. Os três critérios
do ROADMAP são atendidos pelo mesmo produto.

O ambiente local suporta a integração **sem dependência nova**: o mTLS que o Inter exige é feito com
três opções de cURL que o PHP 7.4 do MAMP já tem. Não entra `phpseclib`, não entra curva elíptica —
o que evita de saída o segfault de GMP conhecido neste MAMP.

O custo real não está no protocolo. Está em **três consequências de desenho** que o Inter impõe e que
os outros candidatos não impõem (seção "O que muda no desenho"), sendo a primeira delas a que mexe em
código já existente: **a credencial deixa de ser uma string**, e o `bomcontrole_secret` não serve de
molde.

> **A decisão continua sendo de negócio, não técnica.** O próprio ROADMAP já dizia: *"se a conta da
> CDW já é em algum desses, isso decide sozinho"*. Este documento mostra que, **se a conta for Inter,
> não há impedimento técnico**. Se não for, abrir relacionamento bancário para isso é um custo que
> Asaas/Cora/Iugu não cobram — todos entregam boleto+PIX por API com credencial de string simples.

---

## O que foi verificado, e como

O portal `developers.inter.co` é renderizado em JS (mesmo problema do collection do Bom Controle) e a
**referência completa exige login no internet banking**. Por isso a tabela abaixo separa o que eu
conferi de fato do que veio de fonte secundária. **Nada aqui foi testado com conta real** — não há
credencial do Inter neste projeto.

### Verificado por mim, nesta máquina

| Fato | Como | Resultado |
|---|---|---|
| Hosts existem e respondem | `curl` HEAD nos dois `/oauth/v2/token` | **HTTP 405** (existe, espera POST), `curl_errno = 0` |
| Cadeia TLS válida sem CA extra | `SSL_VERIFYPEER = TRUE` na sonda | OK nos dois — mesma config do `Bom_controle` |
| Handshake fecha **sem** certificado de cliente | a sonda não mandou cert e completou | falha de credencial virá como **HTTP**, não como erro de TLS (bom para diagnóstico) |
| PHP/cURL/OpenSSL | `curl_version()` | PHP 7.4.33 · cURL 7.83.0 · OpenSSL 1.1.1s |
| Opções de mTLS disponíveis | `defined()` | `CURLOPT_SSLCERT`, `SSLKEY`, `SSLCERTTYPE`, `KEYPASSWD` — **todas presentes** |
| Certificado em memória | `defined()` | **`CURLOPT_SSLCERT_BLOB` e `SSLKEY_BLOB` AUSENTES** ← decide o desenho da credencial |
| Estado do código | `grep` em `application/` | **zero** ocorrência de PSP/webhook/Inter (só ruído do vendor do Google) |
| Próxima migration | `config/migration.php` + pasta | `migration_version = 33`, arquivos até `033` → **a do PSP é a 034** |

> ⚠️ **Correção ao ROADMAP e ao CLAUDE.md**: os dois diziam que as colunas de PSP entram na
> **migration 030**. Ela já existe, e o código andou até a **033** (parcelamento, motivos de
> cancelamento, notificações). A migration do PSP é a **034**, e a da NF passa a ser a **035**.

### Vindo do portal do Inter (público, mas não da referência completa)

| Fato | Fonte |
|---|---|
| Existe "Cobrança": *"emitir uma cobrança com código de barras e QRCode"* | descrição na home do portal |
| A operação de emissão se chama **`emitirCobrancaAsync`** | âncora da própria referência: `/references/cobranca-bolepix#tag/Cobranca/operation/emitirCobrancaAsync` |
| Autenticação é **OAuth 2.0 sobre mTLS** | portal + página de integração do Inter |
| Certificados saem do internet banking, por integração, com escopos escolhidos na criação | fluxo "Nova integração" documentado |

### Confirmado contra o SANDBOX REAL (18/08/2026)

Com a credencial de sandbox da CDW, exercitado pela `Psp_inter`. O que era fonte
secundária deixou de ser suposição:

| Item | Valor encontrado |
|---|---|
| Token | `POST /oauth/v2/token`, `grant_type=client_credentials`, form-urlencoded, TTL **3600s** |
| Escopos | `boleto-cobranca.read`, `boleto-cobranca.write`, `webhook.read`, `webhook.write` (e `extrato.read` para saldo/extrato) |
| Base da cobrança | `/cobranca/v3/` |
| Host produção | `https://cdpj.partners.bancointer.com.br` |
| Host sandbox | `https://cdpj-sandbox.partners.uatinter.co` |
| Operações | emitir · recuperar · PDF · cancelar/baixar · pesquisar · webhook (criar/consultar/excluir) + listagem de callbacks |
| Header de conta | `x-conta-corrente`, quando o mesmo `client_id` atende mais de uma conta |

**Corrigido pelo sandbox** — o que a fonte secundária tinha errado:

| Item | Suposto | Real |
|---|---|---|
| **Caminho do webhook** | `/cobranca/v3/webhook` | **`/cobranca/v3/cobrancas/webhook`** |
| **Envelope da listagem** | `paginacao.quantidadeTotalDeItens` | **topo**: `totalPaginas`, `totalElementos`, `tamanhoPagina`, `primeiraPagina`, `ultimaPagina`, `numeroDeElementos`, `cobrancas[]` |

A distinção que resolveu o caminho do webhook: `/cobranca/v3/cobrancas/webhook`
devolve **404 em JSON** (`{"title":"Webhook não existe"...}`) — recurso ainda não
criado —, enquanto os caminhos inexistentes devolvem **404 em texto puro**
(`404 page not found`), que é o gateway. Um diz "ainda não há", o outro "não
existe rota".

**Comportamento medido, que molda o código:**

| Fato | Consequência |
|---|---|
| `GET /cobranca/v3/cobrancas` **sem** `dataInicial`/`dataFinal` devolve **400** | as datas são obrigatórias; `filtrarDataPor` **não** é |
| `paginaAtual` é **0-based** e funciona | a conversão de 1-based fica na library, não vaza para o model |
| **~6 chamadas seguidas já devolvem 429**, com **corpo vazio** | rate limit ainda mais agressivo que o do Bom Controle (~12). Pesa na etapa A2: contrato anual em 12× emite 12 cobranças numa rodada |
| O **500** do Inter diz *"Tente novamente mais tarde"* | é transitório. Entrou no retry — **só em GET**, porque repetir um POST emitiria o boleto duas vezes |
| Token: `expires_in` 3600, `access_token` de 36 chars | o cache por conta se paga já na primeira rodada |
| **`Accept: application/json` faz o cancelamento responder 406** | o `POST .../cancelar` devolve **202 sem corpo**, e exigir JSON o faz recusar. A library manda **`Accept: */*`** — os endpoints que devolvem JSON continuam devolvendo |
| O cancelamento responde **202**, não 200/204 | é **assíncrono**, como a emissão: o banco aceita o pedido e a cobrança sai do ar em instantes |
| `POST .../cancelar` só aceita **POST** | `DELETE` e `PUT` devolvem `405 method not allowed` |
| **`GET .../pdf` devolve base64 dentro de JSON** (chave `pdf`) | confirmado: decodifica em PDF válido de ~69 KB (assinatura `%PDF-`). Não há URL pública do boleto |
| **`seuNumero` volta na listagem**, em 19/19 itens | é o que permite **adotar** uma cobrança órfã depois de um POST ambíguo, sem depender do `psp_charge_id` perdido |

> ⚠️ **O SANDBOX DO INTER SÓ ATENDE DAS 08:00 ÀS 20:00.** Fora dessa janela as chamadas falham com
> timeout ou HTTP 500 — que é indistinguível de instabilidade real, e foi o que atrapalhou vários
> testes desta implementação. **Antes de investigar uma falha do sandbox, confira a hora.**
> (Nem toda falha foi isso: os 500 registrados no log às 16:23–16:32 estavam dentro da janela e
> foram instabilidade de verdade — o retry em GET existe por causa deles.)
>
> A produção não tem essa restrição.

> A armadilha do 406 custa caro no diagnóstico: a mensagem que volta é a genérica
> *"verifique se os dados informados estão de acordo com a documentação"*, que aponta para o
> **payload** — e não para o cabeçalho, que é onde o problema está. Foram seis valores de
> `motivoCancelamento` testados antes de a variação de cabeçalho revelar a causa.

---

## Aderência aos três critérios da etapa A

O ROADMAP fixou o critério: *"webhook confiável + boleto **e** PIX na mesma cobrança + API de listagem
para a conciliação (etapa D)"*.

| Critério | Inter | Observação |
|---|---|---|
| **Boleto e PIX na mesma cobrança** | ✅ | É o produto "Boleto com PIX" (bolepix). Uma cobrança → `linhaDigitavel` **e** `pixCopiaECola`. A `crm_invoices` guarda `link_boleto` e `link_pix` da mesma linha, sem conciliar duas cobranças |
| **Webhook de liquidação** | ✅ com ressalva | Caminho confirmado no sandbox (`/cobranca/v3/cobrancas/webhook`). **A assinatura não foi confirmada** — ver risco abaixo |
| **Listagem para conciliação** | ✅ | Pesquisa de cobranças por período/situação, com paginação — é o insumo do `cron_conciliar_cobrancas` |

O casamento com o desenho já pronto é direto:

```
crm_invoices (já existe)          Cobrança v3 do Inter
─────────────────────────────────────────────────────────────
id                          →     seuNumero        (âncora da conciliação)
value                       →     valorNominal
due_date                    →     dataVencimento
crm_customers.document      →     pagador.cpfCnpj + tipoPessoa
description                 →     mensagem
psp_charge_id (a criar)     ←     codigoSolicitacao (UUID)
```

`seuNumero` receber o **`crm_invoices.id`** é o detalhe que faz a conciliação sobreviver a um
`psp_charge_id` perdido: mesmo sem o UUID gravado, a listagem devolve de qual fatura é cada cobrança.
*(Confirmar o limite de tamanho do campo — os SDKs sugerem 15 caracteres; a base tem id de 4 dígitos,
então cabe com folga, mas o truncamento silencioso seria o pior dos mundos.)*

---

## O que muda no desenho por causa do Inter

Três consequências, em ordem de impacto. **A primeira é a única que mexe em código já existente.**

### 1. A credencial deixa de ser uma string — e o `bomcontrole_secret` não serve de molde

Hoje toda credencial do projeto é uma string cifrada com `Secret_crypto` (`encrypt`/`decrypt`, texto
entra, texto sai) numa coluna fora da view. O Inter precisa de **quatro** credenciais, e duas delas
são **arquivos**: `client_id`, `client_secret`, o certificado `.crt` e a chave `.key`.

E o `CURLOPT_SSLCERT_BLOB` **não existe no PHP 7.4** — foi exposto só no PHP 8.1. **Verificado nesta
máquina.** Logo, não há como passar o certificado em memória: o cURL só aceita **caminho de arquivo**.

Isso força uma decisão que as outras integrações do projeto nunca tiveram:

| Opção | Custo | Risco |
|---|---|---|
| **Cifrar o PEM no banco e materializar em arquivo temporário a cada requisição** | escrita em disco por chamada | a chave privada existe em claro no disco durante a chamada; exige `0600`, fora do webroot e remoção garantida em `finally` |
| **Guardar os arquivos fora do webroot, só o caminho no banco** | mais simples e mais rápido | o segredo sai do modelo do `Secret_crypto`; backup do banco deixa de conter tudo o que a integração precisa |

Recomendação: **a segunda**, com os arquivos em `application/certs/inter/<id_company>/` e a pasta
protegida — é menos código, não escreve chave privada a cada boleto, e o "backup incompleto" é
resolvido documentando que o certificado se rebaixa pelo internet banking a qualquer momento. Mas
**é decisão a tomar antes de escrever a library**, não durante.

Consequência de UI: a aba de configuração não é a do Bom Controle. Lá é um campo de chave com olho;
aqui são dois campos de texto **e dois uploads**, com validação de que a chave casa com o certificado
e com a **data de expiração à vista** — certificado do Inter expira, e o sintoma de expirado é toda
cobrança do tenant parar de uma vez.

### 2. A emissão é assíncrona — o boleto não existe no instante da fatura

A própria referência nomeia a operação **`emitirCobrancaAsync`**. O `POST` devolve o
`codigoSolicitacao`, **não** a linha digitável: é preciso consultar depois para ter boleto e PIX.

Isso não quebra nada, mas **desmonta a sequência óbvia** "gerar fatura → criar cobrança → mandar
e-mail" numa única passagem do `cron_gerar_faturas`. O e-mail da etapa B passa a depender de a
cobrança estar pronta, o que faz o `sent_at` da migration 031 valer por si e não como carimbo do
mesmo passo.

O desenho que cabe no que já existe: `cron_gerar_faturas` cria a fatura e a cobrança e grava o
`codigoSolicitacao`; **`cron_enviar_faturas` (etapa B) só envia o que já tem `link_boleto`**, e quem
preenche o `link_boleto` é a consulta — feita pelo próprio envio ou pela conciliação da etapa D, que
já vai existir. Ou seja: **a etapa D deixa de ser opcional e vira parte do caminho feliz.**

*(Falta medir a latência real. Se for de segundos, uma consulta logo após o POST resolve; se for de
minutos, tem de ser rodada separada. Só o sandbox responde isso.)*

### 3. O token tem validade de 1 hora — e precisa de cache

O `Bom_controle` autentica por header fixo (`Authorization: ApiKey`), sem estado. O Inter exige um
`POST /oauth/v2/token` antes das chamadas. Sem cache, uma rodada de 400 faturas faria **400 chamadas
de token** além das 400 de cobrança — e o rate limit é o primeiro a reclamar.

O token vale para o tenant e dura 3600s. Cache em arquivo, com margem de expiração e **nunca em
sessão** (o cron não tem sessão, e este projeto trata isso com rigor).

---

## Risco aberto: a autenticidade do webhook

O ROADMAP assumiu que *"todos os candidatos assinam"* o webhook. **Não consegui confirmar isso para o
Inter em fonte pública** — o que encontrei foi uma página do portal chamada *"Validar webhook pela
URL"*, o que sugere um modelo de validação **por URL**, e não por assinatura HMAC no corpo.

Se for esse o caso, a proteção do endpoint público passa a depender de:

1. **URL secreta e longa** (o caminho vira credencial);
2. **reconsulta obrigatória da cobrança antes da baixa** — que o ROADMAP **já exige** de qualquer
   forma (*"nunca confiar no valor que vem no corpo"*), pela mesma regra do `bomcontrole_contract_id`
   revalidado no `Obter`;
3. **endpoint de callbacks** do Inter, que lista o que ele tentou entregar — insumo de auditoria que
   os outros PSPs nem sempre têm.

Com a reconsulta obrigatória, **a ausência de assinatura deixa de ser exploração e vira só ruído**: um
POST forjado faz o sistema consultar o Inter e descobrir que a cobrança não está paga. O custo é uma
chamada à toa. **Confirmar mesmo assim** — se houver assinatura, ela é barata e entra.

Vale registrar o que já era verdade: o webhook exige **URL pública HTTPS com certificado válido**.
O ambiente local (MAMP na porta 8081) não recebe webhook — em desenvolvimento, a conciliação por pull
da etapa D é o único caminho, o que reforça implementar **D junto com C**, não depois.

---

## Plano de implementação

Segue o padrão do `Bom_controle`, que já provou funcionar neste projeto.

| Peça | Arquivo | Observação |
|---|---|---|
| Library | `application/libraries/Psp_inter.php` | cURL, **nunca lança exceção**, sempre `['success','message','data','http_code']`. Herda do `Bom_controle` o retry (429 e rede, **só em GET**) e acrescenta mTLS + cache de token |
| Orquestrador | `application/models/Psp_model.php` | decifra credencial, `sessao_suspender()` em volta de **toda** rede, normaliza a resposta. Ponto único, como o `Bomcontrole_model` |
| Migration | `application/migrations/034_psp_cobranca_*.php` | `crm_invoices`: `psp_charge_id`, `psp_status`, `link_boleto`, `link_pix`, `paid_at`, `paid_amount`, `paid_method`, `sent_at` · `crm_companies`: `psp_active`, `psp_environment`, `psp_client_id`, `psp_client_secret`, credenciais do certificado. **Recriar a `crm_invoices_v` na mesma migration** |
| Webhook | `application/controllers/Webhook.php` | público, sem sessão, **fora do `MY_Controller`**. Responde 200 rápido e reconsulta antes da baixa |
| Conciliação | `Cron::cron_conciliar_cobrancas` | etapa D — e, pelo item 2 acima, também é quem preenche `link_boleto` do que ficou pendente |
| Idempotência | `crm_psp_webhook_events` | o ROADMAP já marcava "a avaliar". Com webhook sem assinatura, **passa a valer a pena**: guarda o recebido cru para auditar |

O ponto de entrada no código existente é pequeno: `Invoice_model::criarFatura()` já é o funil único
por onde toda fatura nasce (`generateForContract` e `generateNow` passam por ele). A cobrança é criada
**depois** do commit da fatura, nunca dentro da transação — falha de rede não pode desfazer uma fatura
que a UNIQUE `(id_contract, competence)` já registrou.

---

## O que testar no sandbox, antes de escrever a library

Na ordem, e **antes** de qualquer código de produção — é o mesmo cuidado que o ROADMAP exige para o
`Venda/CriarVendaProdutoServico`:

1. **Token com mTLS**: `client_credentials` + `.crt`/`.key` responde 200 e traz `expires_in` 3600?
2. **Emitir uma cobrança** de valor baixo e cronometrar **quanto tempo até a linha digitável existir**
   — é o número que decide o desenho da etapa B.
3. **Formato do valor**: `valorNominal` em reais decimais (`100.00`) e não em centavos.
4. **`seuNumero`**: tamanho máximo real e o que acontece ao estourar (erro ou truncamento silencioso).
5. **Pagador PF e PJ**: o cadastro daqui tem os dois, e a inscrição estadual `ISENTO` da maioria da
   base não pode reprovar a emissão.
6. **Webhook**: registrar, provocar uma liquidação e **inspecionar o corpo recebido** — é isso que
   encerra a dúvida da assinatura.
7. **Pesquisa por período**: paginação e limites, que é o que a etapa D vai consumir.
8. **Cancelamento** de cobrança já emitida — o `post_status` da tela de Faturas já tem
   `aberta → cancelada`, e hoje isso só muda uma linha local.

---

## Pendências que não são código

| Item | Situação |
|---|---|
| **Conta PJ no Inter** | Pré-requisito de tudo. O ROADMAP já listava "abrir/habilitar a conta e o convênio de cobrança **antes** da etapa A" |
| **Custo por boleto** | Não é público e varia por plano/conta. **É o que pode derrubar a escolha por fora da técnica** — 400 boletos/mês em conta errada custa mais que a mensalidade de um PSP |
| **Homologação** | Boleto registrado costuma exigir homologação com o banco e não é imediato |
| **Certificado expira** | Precisa de aviso antes do vencimento — expirado, **toda** cobrança do tenant para de uma vez, e o sintoma no log seria só HTTP 401 em série |
| **URL pública HTTPS** | Para o webhook. Não existe no ambiente local |

---

## Comparação rápida com os outros candidatos

| | **Inter** | Asaas / Cora / Iugu |
|---|---|---|
| Boleto + PIX na mesma cobrança | ✅ | ✅ nos três |
| Credencial | 4 itens, **2 são arquivos**; mTLS | uma chave de API (string) — cabe no `Secret_crypto` sem mudar nada |
| Emissão | **assíncrona** | síncrona (boleto sai na resposta) |
| Token | OAuth, 1h, precisa de cache | header fixo, sem estado |
| Webhook assinado | **não confirmado** | assinatura documentada |
| Relacionamento | precisa de **conta bancária** no Inter | cadastro de conta digital, sem banco novo |
| Custo | tarifa bancária, varia por plano | por boleto emitido/pago, público em tabela |

**Leitura**: tecnicamente o Inter entrega tudo que a etapa A pede, mas é o **mais caro em código** dos
quatro — os três itens da seção "o que muda no desenho" são custo que os outros não cobram. Isso se
paga **se a CDW já é cliente Inter**: tarifa bancária de quem já tem conta tende a bater qualquer
PSP, e o dinheiro cai na conta da empresa sem repasse.

Se **não** for cliente Inter, o critério do ROADMAP se inverte: abrir conta bancária, homologar
convênio e pagar três consequências de desenho para chegar no mesmo boleto+PIX que um Asaas entrega
com uma string de credencial é trabalho que precisa de justificativa **financeira**, não técnica.
