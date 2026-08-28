<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Servidor MCP (Model Context Protocol) — JSON-RPC 2.0 sobre HTTP.
 *
 * Atende em POST /api/v1/mcp, e o caminho é escolha deliberada: o CSRF do
 * projeto está ligado e a única lista de exclusão VERSIONADA que cobre este
 * controller é `api/v1/.*`. Publicá-lo em `/mcp`, como no projeto de
 * referência, exigiria acrescentar 'mcp' ao `csrf_exclude_uris` de
 * application/config/config.php — que está no .gitignore e não sobe no
 * deploy. Verificado por HTTP: POST /mcp responde 403 e POST /api/v1/mcp
 * passa. É o mesmo raciocínio já registrado em routes.php para POST /api/v1.
 *
 * NÃO estende Api_Controller de propósito: o construtor de lá autentica
 * respondendo no envelope REST, e aqui todo erro precisa sair em JSON-RPC.
 * A autenticação é repetida inline — mesma chave, mesmo hash, mesmo rate
 * limiter, mesmos escopos.
 *
 * As ferramentas chamam os MESMOS models e os MESMOS formatters do Api_v1.
 * É isso que impede REST e MCP de divergirem: quem muda o formato de uma
 * entidade muda nos dois de uma vez, porque só existe um lugar.
 */
class Mcp extends CI_Controller
{
  const PROTOCOL_VERSION = '2025-03-26';
  const SERVER_NAME = 'cdw-finance';
  const SERVER_VERSION = '1.0.0';

  /** Teto de itens por chamada de ferramenta, espelhando o REST. */
  const LIMIT_PADRAO = 20;
  const LIMIT_MAXIMO = 100;

  protected $api_key;
  protected $api_company_id;

  public function __construct()
  {
    parent::__construct();
    $this->output->set_content_type('application/json', 'utf-8');
    $this->load->model('api_key_model');
    $this->load->model('api_company_model');
    $this->load->model('api_customer_model');
    $this->load->model('api_contract_model');
    $this->load->model('api_server_model');
    $this->load->library('api_rate_limiter');
  }

  public function index()
  {
    if ($this->input->method(TRUE) !== 'POST') {
      $this->output->set_header('Allow: POST');
      $this->sendError(NULL, -32600, 'Use POST para o servidor MCP.', 405);
    }

    $request = $this->parseRequest();
    $requestId = array_key_exists('id', $request) ? $request['id'] : NULL;
    $this->authenticate($requestId);

    $method = $request['method'] ?? '';
    $params = !empty($request['params']) && is_array($request['params']) ? $request['params'] : [];

    if ($method === 'initialize') {
      $this->sendResult($requestId, [
        'protocolVersion' => self::PROTOCOL_VERSION,
        'capabilities' => ['tools' => []],
        'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
        'instructions' => 'Consulta aos dados de gestão do CDW Finance da empresa vinculada à chave: '
          . 'clientes, contratos, contatos, anexos, servidores e domínios. Somente leitura.',
      ]);
    }

    // Notificação não tem resposta em JSON-RPC — só o 202.
    if ($method === 'notifications/initialized') {
      $this->output->set_status_header(202)->set_output('');
      return;
    }

    if ($method === 'tools/list') {
      $this->sendResult($requestId, ['tools' => $this->availableTools()]);
    }

    if ($method === 'tools/call') {
      $this->handleToolCall($requestId, $params);
    }

    $this->sendError($requestId, -32601, 'Método MCP não encontrado.');
  }

  /**
   * Catálogo das ferramentas. O escopo de cada uma é o MESMO exigido pelo
   * endpoint REST equivalente, e os rótulos vêm de Empresas::API_SCOPES.
   *
   * As descrições dos dois recursos de domínio se referenciam de propósito:
   * é o que evita o agente pedir disco em `contract_domains` ou vencimento
   * cadastral em `server_domains`.
   */
  private function tools()
  {
    return [
      'list_companies' => [
        'scope' => 'companies:read',
        'description' => 'Dados cadastrais da empresa (tenant) vinculada à chave.',
        'schema' => $this->schema([]),
      ],
      'list_customers' => [
        'scope' => 'customers:read',
        'description' => 'Lista os clientes finais da empresa. Use `search` para nome, nome fantasia ou e-mail.',
        'schema' => $this->schema([
          'search' => ['type' => 'string', 'description' => 'Busca por nome, nome fantasia ou e-mail.'],
          'document' => ['type' => 'string', 'description' => 'CPF ou CNPJ, com ou sem pontuação.'],
          'type' => ['type' => 'string', 'enum' => ['F', 'J'], 'description' => 'F = pessoa física, J = jurídica.'],
          'has_active_contract' => ['type' => 'boolean', 'description' => 'Somente clientes com (ou sem) contrato vigente.'],
        ]),
      ],
      'get_customer' => [
        'scope' => 'customers:read',
        'description' => 'Um cliente pelo ID.',
        'schema' => $this->schema(['id' => ['type' => 'integer', 'minimum' => 1]], ['id']),
      ],
      'list_contacts' => [
        'scope' => 'contacts:read',
        'description' => 'Contatos dos clientes (financeiro, sócio, jurídico...). Sem `customer_id`, lista os do tenant inteiro.',
        'schema' => $this->schema([
          'customer_id' => ['type' => 'integer', 'minimum' => 1],
          'type' => ['type' => 'string', 'enum' => array_keys(Api_customer_model::TIPOS_CONTATO)],
        ]),
      ],
      'list_attachments' => [
        'scope' => 'attachments:read',
        'description' => 'Anexos dos clientes — apenas metadados (nome, tipo, data). O arquivo não é servido pela API.',
        'schema' => $this->schema(['customer_id' => ['type' => 'integer', 'minimum' => 1]]),
      ],
      'list_contracts' => [
        'scope' => 'contracts:read',
        'description' => 'Contratos da empresa, com valor, ciclo, situação e tipos de serviço.',
        'schema' => $this->schema([
          'customer_id' => ['type' => 'integer', 'minimum' => 1],
          'status' => ['type' => 'string', 'enum' => ['vigente', 'suspenso', 'encerrado']],
          'cycle' => ['type' => 'string', 'enum' => array_keys(Api_contract_model::CICLOS)],
          'billing_source' => ['type' => 'string', 'enum' => ['bomcontrole', 'cdwfinance'], 'description' => 'Quem emite a cobrança do contrato.'],
        ]),
      ],
      'get_contract' => [
        'scope' => 'contracts:read',
        'description' => 'Visão geral de um contrato: dados, tipos de serviço, domínios e os títulos. '
          . 'Com o escopo `invoices:read`, inclui `invoices` (faturas emitidas pelo CDW Finance) e `bomcontrole` '
          . '(extrato do ERP, consultado ao vivo e só quando o contrato está vinculado a um contrato de venda lá).',
        'schema' => $this->schema(['id' => ['type' => 'integer', 'minimum' => 1]], ['id']),
      ],
      'list_contract_domains' => [
        'scope' => 'contract-domains:read',
        'description' => 'Domínios CONTRATADOS (cadastro comercial): vencimento cadastrado, registrador e se é gerenciado pela CDW. '
          . 'Para disco, plano ou em qual servidor o domínio está hospedado, use list_server_domains.',
        'schema' => $this->schema([
          'contract_id' => ['type' => 'integer', 'minimum' => 1],
          'customer_id' => ['type' => 'integer', 'minimum' => 1],
          'domain' => ['type' => 'string', 'description' => 'Nome exato do domínio.'],
          'contract_status' => ['type' => 'string', 'enum' => ['vigente', 'suspenso', 'encerrado']],
          'managed_cdw' => ['type' => 'boolean'],
          'due_before' => ['type' => 'string', 'description' => 'Vence até esta data (AAAA-MM-DD).'],
          'due_after' => ['type' => 'string', 'description' => 'Vence a partir desta data (AAAA-MM-DD).'],
        ]),
      ],
      'list_servers' => [
        'scope' => 'servers:read',
        'description' => 'Servidores de hospedagem e e-mail, com situação da última sincronização. '
          . 'Endereço e usuário de acesso não são expostos.',
        'schema' => $this->schema([
          'type' => ['type' => 'string', 'enum' => ['whm', 'directadmin', 'cloudpanel', 'carbonio']],
          'active' => ['type' => 'boolean'],
        ]),
      ],
      'get_server' => [
        'scope' => 'servers:read',
        'description' => 'Um servidor pelo ID.',
        'schema' => $this->schema(['id' => ['type' => 'integer', 'minimum' => 1]], ['id']),
      ],
      'list_server_domains' => [
        'scope' => 'server-domains:read',
        'description' => 'INVENTÁRIO de contas de hospedagem: uma linha por conta em um servidor, com disco usado, plano, IP e o retrato de WHOIS '
          . '(vencimento observado no registro, nameservers). O mesmo domínio pode ter duas contas — site num painel e e-mail em outro. '
          . 'Para o vencimento CADASTRADO no contrato, use list_contract_domains.',
        'schema' => $this->schema([
          'server_id' => ['type' => 'integer', 'minimum' => 1],
          'domain' => ['type' => 'string', 'description' => 'Nome exato do domínio.'],
          'search' => ['type' => 'string', 'description' => 'Busca parcial no nome do domínio.'],
          'status' => ['type' => 'string', 'enum' => ['ativo', 'suspenso']],
          'source' => ['type' => 'string', 'enum' => ['whm', 'directadmin', 'cloudpanel', 'carbonio', 'manual']],
          'whois_bucket' => [
            'type' => 'string',
            'enum' => Api_server_model::FAIXAS_WHOIS,
            'description' => 'Faixa de vencimento do registro: vencido, vence_30 (até 30 dias), ok, livre (não registrado), pendente, erro.',
          ],
        ]),
      ],
      'list_service_types' => [
        'scope' => 'service-types:read',
        'description' => 'Catálogo GLOBAL de tipos de serviço (não é por empresa). Traduz os ids de service_type_ids do cliente.',
        'schema' => $this->schema([]),
      ],
    ];
  }

  private function availableTools()
  {
    $available = [];
    foreach ($this->tools() as $name => $tool) {
      if ($this->hasScope($tool['scope'])) {
        $available[] = [
          'name' => $name,
          'description' => $tool['description'],
          'inputSchema' => $tool['schema'],
        ];
      }
    }
    return $available;
  }

  private function handleToolCall($requestId, array $params)
  {
    $name = $params['name'] ?? '';
    $arguments = !empty($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
    $tools = $this->tools();

    if (!isset($tools[$name])) {
      $this->sendToolResult($requestId, 'Ferramenta não encontrada.', TRUE);
    }
    if (!$this->hasScope($tools[$name]['scope'])) {
      $this->sendToolResult($requestId, 'A chave não possui o escopo necessário: ' . $tools[$name]['scope'] . '.', TRUE);
    }

    $page = $this->inteiro($arguments['page'] ?? 1, 1);
    $limit = $this->inteiro($arguments['limit'] ?? self::LIMIT_PADRAO, 1);
    if ($page === FALSE || $limit === FALSE || $limit > self::LIMIT_MAXIMO) {
      $this->sendToolResult($requestId, 'Parâmetros de paginação inválidos (limit vai de 1 a ' . self::LIMIT_MAXIMO . ').', TRUE);
    }
    $offset = ($page - 1) * $limit;
    $empresa = $this->api_company_id;

    if ($name === 'list_companies') {
      $rows = $this->api_company_model->getCompanies($empresa, $limit, $offset);
      $this->responderLista($requestId, $rows, [$this->api_company_model, 'formatCompany'],
        $this->api_company_model->countCompanies($empresa), $page, $limit);
    }

    if ($name === 'list_customers') {
      $filtros = [
        'search' => $this->texto($arguments['search'] ?? NULL),
        'document' => $this->texto($arguments['document'] ?? NULL),
        'type' => $this->texto($arguments['type'] ?? NULL),
        'has_active_contract' => $this->booleano($arguments['has_active_contract'] ?? NULL),
      ];
      $rows = $this->api_customer_model->getCustomers($empresa, $limit, $offset, $filtros);
      $this->responderLista($requestId, $rows, [$this->api_customer_model, 'formatCustomer'],
        $this->api_customer_model->countCustomers($empresa, $filtros), $page, $limit);
    }

    if ($name === 'get_customer') {
      $id = $this->inteiro($arguments['id'] ?? NULL, 1);
      if ($id === FALSE) $this->sendToolResult($requestId, 'Informe um ID de cliente válido.', TRUE);
      $row = $this->api_customer_model->getCustomer($empresa, $id);
      if (empty($row)) $this->sendToolResult($requestId, 'Cliente não encontrado para esta empresa.', TRUE);
      $this->sendToolResult($requestId, ['data' => $this->api_customer_model->formatCustomer($row)]);
    }

    if ($name === 'list_contacts') {
      $filtros = [
        'customer_id' => $this->inteiro($arguments['customer_id'] ?? NULL, 1) ?: NULL,
        'type' => $this->texto($arguments['type'] ?? NULL),
      ];
      $rows = $this->api_customer_model->getContacts($empresa, $limit, $offset, $filtros);
      $this->responderLista($requestId, $rows, [$this->api_customer_model, 'formatContact'],
        $this->api_customer_model->countContacts($empresa, $filtros), $page, $limit);
    }

    if ($name === 'list_attachments') {
      $filtros = ['customer_id' => $this->inteiro($arguments['customer_id'] ?? NULL, 1) ?: NULL];
      $rows = $this->api_customer_model->getFiles($empresa, $limit, $offset, $filtros);
      $this->responderLista($requestId, $rows, [$this->api_customer_model, 'formatFile'],
        $this->api_customer_model->countFiles($empresa, $filtros), $page, $limit);
    }

    if ($name === 'list_contracts') {
      $filtros = [
        'customer_id' => $this->inteiro($arguments['customer_id'] ?? NULL, 1) ?: NULL,
        'status' => $this->texto($arguments['status'] ?? NULL),
        'cycle' => $this->texto($arguments['cycle'] ?? NULL),
        'billing_source' => $this->texto($arguments['billing_source'] ?? NULL),
      ];
      $rows = $this->api_contract_model->getContracts($empresa, $limit, $offset, $filtros);
      $services = $this->api_contract_model->getContractServices(
        array_map(function ($c) { return (int) $c->id; }, $rows), $empresa
      );
      $data = array_map(function ($c) use ($services) {
        return $this->api_contract_model->formatContract($c, $services[(int) $c->id] ?? []);
      }, $rows);
      $this->sendToolResult($requestId, [
        'data' => $data,
        'meta' => ['page' => $page, 'limit' => $limit, 'total' => $this->api_contract_model->countContracts($empresa, $filtros)],
      ]);
    }

    if ($name === 'get_contract') {
      $id = $this->inteiro($arguments['id'] ?? NULL, 1);
      if ($id === FALSE) $this->sendToolResult($requestId, 'Informe um ID de contrato válido.', TRUE);
      $row = $this->api_contract_model->getContract($empresa, $id);
      if (empty($row)) $this->sendToolResult($requestId, 'Contrato não encontrado para esta empresa.', TRUE);
      $services = $this->api_contract_model->getContractServices([$id], $empresa);
      $domains = $this->api_contract_model->getContractDomains($empresa, Api_contract_model::MAX_DOMINIOS_NO_DETALHE, 0, ['contract_id' => $id]);
      $this->sendToolResult($requestId, ['data' => $this->api_contract_model->formatContract(
        $row,
        $services[$id] ?? [],
        array_map(function ($d) { return $this->api_contract_model->formatContractDomain($d); }, $domains),
        // Mesma regra do REST, do mesmo model: os títulos entram quando a
        // chave tem `invoices:read` e são omitidos quando não tem.
        $this->api_contract_model->blocosDeTitulos($id, $empresa, $this->hasScope('invoices:read'))
      )]);
    }

    if ($name === 'list_contract_domains') {
      $filtros = [
        'contract_id' => $this->inteiro($arguments['contract_id'] ?? NULL, 1) ?: NULL,
        'customer_id' => $this->inteiro($arguments['customer_id'] ?? NULL, 1) ?: NULL,
        'domain' => $this->texto($arguments['domain'] ?? NULL),
        'contract_status' => $this->texto($arguments['contract_status'] ?? NULL),
        'managed_cdw' => $this->booleano($arguments['managed_cdw'] ?? NULL),
        'due_before' => $this->texto($arguments['due_before'] ?? NULL),
        'due_after' => $this->texto($arguments['due_after'] ?? NULL),
      ];
      $rows = $this->api_contract_model->getContractDomains($empresa, $limit, $offset, $filtros);
      $this->responderLista($requestId, $rows, [$this->api_contract_model, 'formatContractDomain'],
        $this->api_contract_model->countContractDomains($empresa, $filtros), $page, $limit);
    }

    if ($name === 'list_servers') {
      $filtros = [
        'type' => $this->texto($arguments['type'] ?? NULL),
        'active' => $this->booleano($arguments['active'] ?? NULL),
      ];
      $rows = $this->api_server_model->getServers($empresa, $limit, $offset, $filtros);
      $this->responderLista($requestId, $rows, [$this->api_server_model, 'formatServer'],
        $this->api_server_model->countServers($empresa, $filtros), $page, $limit);
    }

    if ($name === 'get_server') {
      $id = $this->inteiro($arguments['id'] ?? NULL, 1);
      if ($id === FALSE) $this->sendToolResult($requestId, 'Informe um ID de servidor válido.', TRUE);
      $row = $this->api_server_model->getServer($empresa, $id);
      if (empty($row)) $this->sendToolResult($requestId, 'Servidor não encontrado para esta empresa.', TRUE);
      $this->sendToolResult($requestId, ['data' => $this->api_server_model->formatServer($row)]);
    }

    if ($name === 'list_server_domains') {
      $filtros = [
        'server_id' => $this->inteiro($arguments['server_id'] ?? NULL, 1) ?: NULL,
        'domain' => $this->texto($arguments['domain'] ?? NULL),
        'search' => $this->texto($arguments['search'] ?? NULL),
        'status' => $this->texto($arguments['status'] ?? NULL),
        'source' => $this->texto($arguments['source'] ?? NULL),
        'whois_bucket' => $this->texto($arguments['whois_bucket'] ?? NULL),
      ];
      $rows = $this->api_server_model->getServerDomains($empresa, $limit, $offset, $filtros);
      $this->responderLista($requestId, $rows, [$this->api_server_model, 'formatServerDomain'],
        $this->api_server_model->countServerDomains($empresa, $filtros), $page, $limit);
    }

    if ($name === 'list_service_types') {
      $rows = $this->db->select('id, id_status, name, monitor_site')
        ->from('crm_service_types')->order_by('name', 'asc')->get()->result();
      $this->sendToolResult($requestId, ['data' => array_map(function ($r) {
        return [
          'id' => (int) $r->id,
          'name' => $r->name,
          'active' => (int) $r->id_status === 1,
          'monitor_site' => (bool) $r->monitor_site,
        ];
      }, $rows)]);
    }

    $this->sendToolResult($requestId, 'Ferramenta não implementada.', TRUE);
  }

  /** Monta o resultado paginado no mesmo formato do envelope REST. */
  private function responderLista($requestId, array $rows, callable $formatter, $total, $page, $limit)
  {
    $this->sendToolResult($requestId, [
      'data' => array_map($formatter, $rows),
      'meta' => ['page' => $page, 'limit' => $limit, 'total' => (int) $total],
    ]);
  }

  /** Esqueleto de inputSchema com a paginação, que toda ferramenta aceita. */
  private function schema(array $properties, array $required = [])
  {
    $schema = [
      'type' => 'object',
      'properties' => array_merge([
        'page' => ['type' => 'integer', 'minimum' => 1],
        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIMIT_MAXIMO],
      ], $properties),
    ];
    if (!empty($required)) {
      $schema['required'] = $required;
    }
    return $schema;
  }

  // ------------------------------------------------------------- ENTRADA

  private function parseRequest()
  {
    $raw = trim((string) $this->input->raw_input_stream);
    $request = json_decode($raw, TRUE);
    if (!is_array($request) || ($request['jsonrpc'] ?? NULL) !== '2.0' || empty($request['method']) || !is_string($request['method'])) {
      $this->sendError(NULL, -32600, 'Requisição JSON-RPC inválida.', 400);
    }
    return $request;
  }

  /**
   * Mesma cadeia do Api_Controller: header → formato → prefixo → hash →
   * rate limit. Só o formato do erro muda.
   */
  private function authenticate($requestId)
  {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
      $headers = getallheaders();
      $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
      $this->sendError($requestId, -32001, 'Chave Bearer ausente ou inválida.', 401);
    }

    $token = trim($matches[1]);
    if (!preg_match('/^(cdwf_live_[a-f0-9]{12})_[A-Za-z0-9_-]{43}$/', $token, $parts)) {
      $this->sendError($requestId, -32001, 'Chave Bearer inválida.', 401);
    }

    $apiKey = $this->api_key_model->findActiveByPrefix($parts[1]);
    if (empty($apiKey) || !password_verify($token, $apiKey->secret_hash)) {
      $this->sendError($requestId, -32001, 'Chave Bearer inválida.', 401);
    }

    if (!$this->api_rate_limiter->consume($apiKey->id)) {
      $retryAfter = $this->api_rate_limiter->retryAfter();
      $this->output->set_header('Retry-After: ' . $retryAfter);
      $this->sendError($requestId, -32002, 'Limite de requisições excedido.', 429, ['retry_after' => $retryAfter]);
    }

    $this->api_key = $apiKey;
    $this->api_company_id = (int) $apiKey->id_company;
    $this->api_key_model->touchLastUsed($apiKey->id);
  }

  private function hasScope($scope)
  {
    $scopes = json_decode($this->api_key->scopes, TRUE);
    return is_array($scopes) && in_array($scope, $scopes, TRUE);
  }

  /** Aceita inteiro ou string numérica; FALSE quando veio algo inválido. */
  private function inteiro($value, $min)
  {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if ((!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/', $value))) || (int) $value < $min) {
      return FALSE;
    }
    return (int) $value;
  }

  private function texto($value)
  {
    if ($value === NULL || !is_scalar($value)) {
      return NULL;
    }
    $value = trim((string) $value);
    return $value === '' ? NULL : $value;
  }

  private function booleano($value)
  {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_bool($value)) {
      return $value;
    }
    return in_array((string) $value, ['1', 'true'], TRUE);
  }

  // ------------------------------------------------------------- SAÍDA

  private function sendToolResult($requestId, $result, $isError = FALSE)
  {
    $text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $this->sendResult($requestId, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => (bool) $isError]);
  }

  private function sendResult($id, array $result)
  {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  private function sendError($id, $code, $message, $httpStatus = 200, array $data = [])
  {
    $error = ['code' => (int) $code, 'message' => $message];
    if (!empty($data)) {
      $error['data'] = $data;
    }
    $this->output->set_status_header($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}
