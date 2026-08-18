# Bom Controle — cadastro do cliente: campos disponíveis × campos informados

Levantamento de **17/08/2026**. Fonte: `docs/bomcontrole.postman_collection.json` (endpoints
`Cliente/*`) confrontado com `Bomcontrole_model::montarPayloadCliente()`,
`montarEnderecoCliente()` e `contatosParaBomControle()`.

## Resumo

O `Cliente/Criar` tem **23 campos** no corpo. Enviamos **21**. Os dois que ficam de fora são
`PessoaFisica.Sexo` e `PessoaJuridica.InscricaoMunicipal` — nenhum dos dois existe no cadastro daqui.

**A lacuna relevante não é campo faltando: é contato descartado.** A regra do ERP (nome + e-mail +
telefone, os três obrigatórios) derruba **101 dos 683 contatos** da base, e deixa **21 clientes
chegando ao ERP sem nenhum contato** — 9 deles com e-mail preenchido em `crm_customers.email`, que a
sincronização nunca consulta. Detalhe na seção *O buraco real*.

---

## 1. `POST Cliente/Criar` — campo a campo

✅ enviado · ⚠️ condicional · ❌ não enviado

| Campo do ERP | Situação | Origem daqui | Observação |
|---|---|---|---|
| `Endereco.TipoLogradouro` | ⚠️ | derivado de `address` | `separarTipoLogradouro()`; **omitido** quando não reconhece o prefixo — o ERP assume "Rua" sozinho. Enum: Rua, Avenida, Travessa, Alameda, Estrada, Rodovia, PSG, Quadra |
| `Endereco.Logradouro` | ✅ | `address` (sem o tipo) | |
| `Endereco.Numero` | ✅ | `address_number` | |
| `Endereco.Complemento` | ✅ | `address_complement` | |
| `Endereco.Bairro` | ✅ | `address_district` | |
| `Endereco.Cep` | ✅ | `address_zip` | convertido de `00.000-000` para `00000-000` |
| `Endereco.Cidade` | ✅ | `city_name` | LEFT JOIN na view: vai vazio se a cidade não resolveu |
| `Endereco.Uf` | ✅ | `state_uf` | idem |
| `Contatos[].Nome` | ✅ | `crm_customers_contacts.name` | truncado em 150 |
| `Contatos[].Email` | ✅ | `.email` | minúsculas |
| `Contatos[].Telefone` | ✅ | `.phone` | só dígitos |
| `Contatos[].Padrao` | ✅ | derivado: `type = 'financeiro'` | |
| `Contatos[].Cobranca` | ✅ | idem | |
| `PessoaFisica.Documento` | ✅ | `document` (11 dígitos) | |
| `PessoaFisica.Nome` | ✅ | `name` | |
| **`PessoaFisica.Sexo`** | ❌ | — | **Não coletamos.** Opcional no ERP. Enum: M, MASCULINO, F, FEMININO, O, OUTROS |
| `PessoaJuridica.Documento` | ✅ | `document` (14 dígitos) | |
| `PessoaJuridica.RazaoSocial` | ✅ | `name` | |
| `PessoaJuridica.NomeFantasia` | ✅ | `byname` | obrigatório no ERP e NULLable aqui: **cai para `name`** quando vazio |
| `PessoaJuridica.IsentoInscricaoEstadual` | ✅ | derivado de `state_registration` | o trio anda junto |
| `PessoaJuridica.InscricaoEstadual` | ✅ | `state_registration` | `NULL` quando isento |
| `PessoaJuridica.UFInscricaoEstadual` | ✅ | `state_uf` do endereço comercial | `NULL` quando isento |
| **`PessoaJuridica.InscricaoMunicipal`** | ❌ | — | **Não coletamos.** Opcional no ERP |

### Sobre os dois não informados

| Campo | Vale a pena coletar? |
|---|---|
| `PessoaFisica.Sexo` | **Não.** Atinge 33 dos 387 clientes (8,5%), é opcional no ERP e não entra em NFS-e nem em cobrança. Coletar sexo num cadastro público pede base legal de LGPD para um dado que não muda nada. |
| `PessoaJuridica.InscricaoMunicipal` | **Talvez, mais adiante.** Atinge os 354 PJ. Não é exigida para tomador de NFS-e (quem emite é a CDW), mas alguns municípios a usam na retenção de ISS. Se aparecer necessidade fiscal, é uma coluna física ao lado de `state_registration`, com a mesma justificativa da migration 023. |

**Nenhum dos dois bloqueia a emissão de nota fiscal** — são campos de cadastro, não da NFS-e.

---

## 2. Campos que a API mostra mas **não deixa escrever**

Aparecem só no `GET Cliente/Obter`. Não estão no `Criar` nem no `Alterar` — não é omissão nossa, é
limite da API. Preenchê-los exige o painel do Bom Controle.

| Campo | Onde aparece | Escrita pela API? |
|---|---|---|
| `PaisOrigem` | `Obter` | ❌ inexistente |
| `PessoaFisica.DataNascimento` | `Obter` | ❌ inexistente |
| `PessoaJuridica.RamoAtividade` | `Obter` | ❌ inexistente |
| `Bloqueado` | `Obter` | ⚠️ só pelo `Cliente/AlterarBloqueio` (endpoint próprio) |
| `DataCriacao` | `Obter` | ❌ do sistema |
| `Endereco.Id`, `Contatos[].Id` | `Obter` | ❌ do sistema |

> **Correção ao que está no CLAUDE.md.** Lá o `RamoAtividade` está listado junto de `Sexo` e
> `InscricaoMunicipal` como "campo que este cadastro não coleta e por isso não é enviado". São coisas
> diferentes: os dois primeiros são **omissão nossa**; o `RamoAtividade` **não tem como ser enviado**
> por nenhum endpoint. Coletá-lo aqui não mudaria nada no ERP.

---

## 3. Endpoints de escrita que existem e não usamos

| Endpoint | O que faria | Por que não usamos |
|---|---|---|
| `PUT Cliente/AlterarContatos/{id}` | atualizar contatos depois do `Criar` | **Decisão consciente**: ele **adiciona**, não substitui nem remove. Chamá-lo a cada sincronização acumularia duplicatas no ERP sem caminho de correção pela API. Consequência assumida: contato editado ou removido aqui não se reflete lá |
| `PUT Cliente/AlterarBloqueio/{id}` | bloquear/desbloquear o cliente no ERP | Nunca chamado. Não há conceito de bloqueio no cadastro daqui — o mais próximo é "sem contrato vigente", que é outra pergunta. **Candidato futuro**: bloquear no ERP quem tem fatura vencida há X dias |

---

## 4. O buraco real: contatos que o ERP descarta

O ERP exige **nome + e-mail + telefone** nos três. Aqui, `email` e `phone` são NULLable —
`contatoBomControle()` devolve `NULL` e o contato é descartado em silêncio.

Medido na base local (387 clientes, 683 contatos):

| Métrica | Quantidade |
|---|---|
| Contatos cadastrados | 683 |
| **Contatos que o ERP descarta** | **101 (14,8%)** |
| — sem telefone | 60 |
| — sem e-mail | 45 |
| — sem nome | 19 |
| — sem e-mail **e** sem telefone | 7 |
| Clientes que perdem ao menos um contato no envio | 82 |
| **Clientes que chegam ao ERP sem nenhum contato** | **21** |
| — desses, com e-mail em `crm_customers.email` | **9** |
| Clientes sem nenhum contato cadastrado | 10 |
| Clientes salvos pelo fallback `attributes.billing` | 1 |

### A inconsistência que os 9 clientes revelam

Existem **duas cascatas de destinatário** no projeto, e elas discordam:

| | `Adjustment_model::destinatario()` | `Bomcontrole_model::contatosParaBomControle()` |
|---|---|---|
| 1º | contato `financeiro` com e-mail | contatos de `crm_customers_contacts` |
| 2º | qualquer contato com e-mail | `attributes.billing` |
| 3º | **`crm_customers.email`** | — **não existe** |

O aviso de reajuste chega nesses 9 clientes; a sincronização com o ERP manda o cadastro sem contato
nenhum. O `crm_customers.email` é justamente o *e-mail do contrato* — o mais qualificado que temos.

**Por que não é conserto de uma linha:** o ERP quer os três campos, e `crm_customers.email` é só o
e-mail. O nome pode sair de `crm_customers.name`; o telefone precisa vir de
`attributes.representative.whatsapp` ou `attributes.billing.whatsapp`. Quando nenhum dos dois tiver
telefone, o contato continua impossível — a alternativa seria mandar o campo vazio, e o ERP recusa.

### Recomendação

1. **Acrescentar o 3º degrau** à cascata do BC: `crm_customers.name` + `crm_customers.email` +
   telefone de `attributes.representative.whatsapp` → `attributes.billing.whatsapp`. Recupera parte
   dos 21.
2. **Registrar o descarte no log** (`[BOMCONTROLE]`, com id do cliente e o que faltou). Hoje 101
   contatos somem sem deixar rastro — pela regra do projeto, integração que falha em silêncio é bug.
3. **Mostrar na tela** quantos contatos foram enviados no resultado do SINCRONIZAR CADASTRO. O
   usuário não tem como saber que o contato dele não chegou.
4. **Não** relaxar a regra dos três campos: quem recusa é o ERP.

---

## 5. Direção inversa: dado daqui que não tem destino no ERP

Não é lacuna a corrigir — é o mapa do que o `Cliente/*` simplesmente não modela.

| Dado daqui | Onde | Destino no ERP |
|---|---|---|
| Representante legal (nome, CPF, RG, nacionalidade, estado civil, profissão, WhatsApp) | `attributes.representative` | **não existe** |
| Endereço residencial do representante | `attributes.representative.address` | **não existe** — o ERP tem um endereço só |
| Domínios (principal e secundário) | `attributes.domains` | **não existe** |
| Observações do contrato | `attributes.contract.comments` | **não existe** |
| Consentimento LGPD (aceite, data, IP, user agent) | `attributes.consent` | **não existe** |
| Origem do cadastro (wizard/painel, autofill de CNPJ) | `attributes.source` | **não existe** |
| `needs_invoice` (S/N) | `attributes.billing` | vive no contrato, via `invoice_policy` |

O representante legal é o mais notável: 1 cliente da base tem CPF de representante preenchido — a
importação do gestor-interno nasceu com `representative` vazio porque **esses campos foram removidos
da origem**. Não há o que sincronizar hoje.

---

## 6. Estado da base (17/08/2026)

| Métrica | Valor |
|---|---|
| Clientes | 387 (33 PF, 354 PJ) |
| **PJ com inscrição estadual real** | **0** — toda a base está `ISENTO`, pelo DEFAULT da migration 023 |
| Clientes já sincronizados com o BC | 1 |

Os dois números juntos dizem que **a carga inicial não começou**, e que ela vai mandar "isento" para
354 PJ. É a consequência assumida na migration 023 — o dado não existia em nenhuma origem —, mas vale
decidir antes da carga em massa se alguma parte da base tem inscrição real a corrigir primeiro.
Depois da carga, corrigir aqui exige um `Cliente/Alterar` por cliente.
