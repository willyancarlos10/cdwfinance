<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CDW Finance — Servidor MCP</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.6; max-width: 62rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
    h1 { margin-bottom: .25rem; }
    h2 { margin-top: 2.25rem; border-bottom: 1px solid rgba(128, 128, 128, .3); padding-bottom: .3rem; }
    .lead { color: #666; margin-top: 0; }
    code { background: rgba(128, 128, 128, .15); padding: .1rem .35rem; border-radius: 3px; font-size: .9em; }
    pre { background: rgba(128, 128, 128, .12); padding: 1rem; border-radius: 6px; overflow-x: auto; }
    pre code { background: none; padding: 0; }
    table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
    th, td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid rgba(128, 128, 128, .25); vertical-align: top; }
    th { font-size: .82rem; text-transform: uppercase; letter-spacing: .03em; color: #666; }
    .aviso { border-left: 4px solid #d9822b; background: rgba(217, 130, 43, .1); padding: .75rem 1rem; border-radius: 0 4px 4px 0; margin: 1.25rem 0; }
  </style>
</head>

<body>
  <h1>Servidor MCP — CDW Finance</h1>
  <p class="lead">Consulta aos dados de gestão por agentes de IA, via Model Context Protocol.</p>

  <h2>Endereço e autenticação</h2>
  <table>
    <tr>
      <th>Endpoint</th>
      <td><code>POST <?php echo base_url('api/v1/mcp'); ?></code></td>
    </tr>
    <tr>
      <th>Protocolo</th>
      <td>JSON-RPC 2.0 — versão <code>2025-03-26</code></td>
    </tr>
    <tr>
      <th>Autenticação</th>
      <td><code>Authorization: Bearer cdwf_live_&lt;prefixo&gt;_&lt;segredo&gt;</code></td>
    </tr>
    <tr>
      <th>Limite</th>
      <td>120 requisições por minuto, por chave. Ao estourar, a resposta traz o cabeçalho <code>Retry-After</code>.</td>
    </tr>
  </table>

  <p>
    As chaves são geradas no painel, em <strong>Empresas &rsaquo; Chaves de API</strong>, e valem para
    <strong>uma empresa só</strong> — a empresa é derivada da chave no servidor, nunca informada na
    chamada. Cada chave carrega uma lista de permissões, e <code>tools/list</code> devolve apenas as
    ferramentas que a chave pode usar.
  </p>

  <div class="aviso">
    <strong>A chave é exibida uma única vez</strong>, na criação. O sistema guarda apenas o hash. Se
    ela se perder, gere outra e revogue a anterior. Use sempre a partir do servidor — nunca no
    JavaScript de um navegador.
  </div>

  <h2>Ferramentas</h2>
  <table>
    <tr><th>Ferramenta</th><th>Permissão</th><th>O que devolve</th></tr>
    <tr><td><code>list_companies</code></td><td><code>companies:read</code></td><td>Dados cadastrais da empresa da chave</td></tr>
    <tr><td><code>list_customers</code> · <code>get_customer</code></td><td><code>customers:read</code></td><td>Clientes finais</td></tr>
    <tr><td><code>list_contacts</code></td><td><code>contacts:read</code></td><td>Contatos dos clientes</td></tr>
    <tr><td><code>list_attachments</code></td><td><code>attachments:read</code></td><td>Anexos dos clientes (só metadados)</td></tr>
    <tr><td><code>list_contracts</code> · <code>get_contract</code></td><td><code>contracts:read</code></td><td>Contratos, com valor, ciclo e serviços</td></tr>
    <tr><td><code>get_contract</code> (blocos de título)</td><td><code>invoices:read</code></td><td>Faturas do CDW Finance e extrato do Bom Controle</td></tr>
    <tr><td><code>list_contract_domains</code></td><td><code>contract-domains:read</code></td><td>Domínios <strong>contratados</strong></td></tr>
    <tr><td><code>list_servers</code> · <code>get_server</code></td><td><code>servers:read</code></td><td>Servidores</td></tr>
    <tr><td><code>list_server_domains</code></td><td><code>server-domains:read</code></td><td>Contas de <strong>hospedagem</strong></td></tr>
    <tr><td><code>list_service_types</code></td><td><code>service-types:read</code></td><td>Catálogo global de tipos de serviço</td></tr>
  </table>

  <h2>Os títulos do contrato</h2>
  <p>
    Com o escopo <code>invoices:read</code>, o <code>get_contract</code> devolve dois blocos de
    título, de origens diferentes:
  </p>
  <table>
    <tr><th>Bloco</th><th>Origem</th></tr>
    <tr>
      <td><code>invoices</code></td>
      <td>As faturas que o <strong>CDW Finance</strong> gerou. Vêm do banco local.</td>
    </tr>
    <tr>
      <td><code>bomcontrole_invoices</code></td>
      <td>
        O extrato do <strong>ERP Bom Controle</strong>, consultado <strong>ao vivo</strong>. Só é
        buscado quando o contrato está vinculado a um contrato de venda de lá — sem vínculo, o bloco
        volta com <code>linked: false</code> e nenhuma chamada de rede acontece.
      </td>
    </tr>
  </table>
  <p>
    Um contrato normalmente tem só uma das duas origens preenchida: <code>billing.source</code> diz
    quem fatura, e é exclusivo. As duas cheias são o retrato de uma transição.
  </p>
  <div class="aviso">
    Antes de ler <code>bomcontrole_invoices.items</code>, confira <code>available</code>. Se o ERP
    estiver fora do ar, ele vem <code>false</code> com o motivo em <code>message</code> — e o resto
    do contrato continua válido, porque é dado local. Note também que
    <code>bomcontrole_invoices</code> (o extrato) é diferente de <code>bomcontrole</code> (o
    <strong>vínculo</strong> do contrato com o ERP), que está sempre presente.
  </div>

  <h2>Os dois tipos de domínio</h2>
  <p>
    Existem duas ferramentas de domínio porque existem dois cadastros diferentes, e a pergunta
    decide qual usar:
  </p>
  <table>
    <tr><th>Use</th><th>Quando a pergunta é</th></tr>
    <tr>
      <td><code>list_contract_domains</code></td>
      <td>“que domínios o cliente contratou?”, “quando vence o registro cadastrado?”, “quem é o registrador?”, “é gerenciado pela CDW?”</td>
    </tr>
    <tr>
      <td><code>list_server_domains</code></td>
      <td>“quanto disco a conta usa?”, “em qual servidor está hospedado?”, “a conta está suspensa?”, “quais são os nameservers?”</td>
    </tr>
  </table>
  <p>
    O mesmo domínio pode aparecer <strong>mais de uma vez</strong> em <code>list_server_domains</code>:
    é comum o site estar num painel e as contas de e-mail em outro, e nesse caso são duas contas
    distintas, cada uma com seu disco.
  </p>

  <h2>Exemplos</h2>

  <p>Descobrir as ferramentas disponíveis para a chave:</p>
  <pre><code>curl -X POST <?php echo base_url('api/v1/mcp'); ?> \
  -H "Authorization: Bearer $CDWF_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</code></pre>

  <p>Consultar clientes com contrato vigente:</p>
  <pre><code>curl -X POST <?php echo base_url('api/v1/mcp'); ?> \
  -H "Authorization: Bearer $CDWF_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
       "params":{"name":"list_customers",
                 "arguments":{"has_active_contract":true,"limit":20}}}'</code></pre>

  <p>
    Toda listagem aceita <code>page</code> e <code>limit</code> (de 1 a 100, padrão 20) e devolve
    <code>data</code> junto de <code>meta</code> com <code>page</code>, <code>limit</code> e
    <code>total</code>.
  </p>

  <h2>API REST</h2>
  <p>
    Os mesmos dados estão disponíveis por REST, com as mesmas permissões e o mesmo formato de cada
    entidade. O contrato completo está em <a href="<?php echo base_url('api/docs'); ?>">/api/docs</a>.
  </p>
</body>

</html>
