<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'core/Api_Controller.php';

/**
 * API pública de CONSULTA, versionada sob /api/v1.
 *
 * Todos os endpoints são GET e somente leitura. A empresa (tenant) sai da
 * chave Bearer, nunca do request — nenhum método aceita `id_company` por
 * query, corpo ou rota.
 *
 * Um controller só, com um método público por endpoint, como no projeto de
 * referência (platagorma-painel-v3). É seguro porque as rotas são declaradas
 * uma a uma em config/routes.php: método público novo não vira URL sozinho.
 *
 * O `require_once` do core é necessário — o CI3 só autocarrega classes de
 * core com o prefixo `MY_`.
 */
class Api_v1 extends Api_Controller
{
  public function __construct()
  {
    parent::__construct();
    $this->load->model('api_company_model');
    $this->load->model('api_customer_model');
    $this->load->model('api_contract_model');
    $this->load->model('api_server_model');
  }

  /**
   * GET /api/v1/companies
   *
   * Listagem paginada que, por construção, devolve um único item: a empresa
   * da chave. É listagem — e não um `/company` singular — para o consumidor
   * ter um formato só de resposta em todos os recursos.
   */
  public function companies()
  {
    $this->requireGet();
    $this->requireScope('companies:read');
    $pagination = $this->pagination();

    $total = $this->api_company_model->countCompanies($this->api_company_id);
    $companies = $this->api_company_model->getCompanies(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit']
    );

    $data = array_map(function ($company) {
      return $this->api_company_model->formatCompany($company);
    }, $companies);

    $this->respond(200, TRUE, 'Empresas consultadas com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/companies/{id}
   *
   * Pedir o id de outro tenant devolve 404, igual a pedir um id inexistente:
   * um 403 confirmaria que aquela empresa existe.
   */
  public function company($idCompany = 0)
  {
    $this->requireGet();
    $this->requireScope('companies:read');
    $idCompany = (int) $idCompany;

    if ($idCompany <= 0) {
      $this->respond(422, FALSE, 'ID da empresa inválido.', NULL, ['id' => 'Informe um ID de empresa válido.']);
    }

    $company = $this->api_company_model->getCompany($this->api_company_id, $idCompany);
    if (empty($company)) {
      $this->respond(404, FALSE, 'Empresa não encontrada.', NULL, ['id' => 'Nenhuma empresa foi encontrada para este ID.']);
    }

    $this->respond(200, TRUE, 'Empresa consultada com sucesso.', $this->api_company_model->formatCompany($company));
  }

  /**
   * GET /api/v1/customers
   */
  public function customers()
  {
    $this->requireGet();
    $this->requireScope('customers:read');
    $pagination = $this->pagination();

    $filters = [
      'search' => $this->optionalSearch('search'),
      'type' => $this->optionalEnum('type', ['F', 'J']),
      'document' => $this->optionalSearch('document', 20),
      'has_active_contract' => $this->optionalBoolean('has_active_contract'),
    ];

    $total = $this->api_customer_model->countCustomers($this->api_company_id, $filters);
    $customers = $this->api_customer_model->getCustomers(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit'],
      $filters
    );

    $data = array_map(function ($customer) {
      return $this->api_customer_model->formatCustomer($customer);
    }, $customers);

    $this->respond(200, TRUE, 'Clientes consultados com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/customers/{id}
   */
  public function customer($idCustomer = 0)
  {
    $this->requireGet();
    $this->requireScope('customers:read');
    $idCustomer = (int) $idCustomer;

    if ($idCustomer <= 0) {
      $this->respond(422, FALSE, 'ID do cliente inválido.', NULL, ['id' => 'Informe um ID de cliente válido.']);
    }

    $customer = $this->api_customer_model->getCustomer($this->api_company_id, $idCustomer);
    if (empty($customer)) {
      $this->respond(404, FALSE, 'Cliente não encontrado.', NULL, ['id' => 'Nenhum cliente foi encontrado para este ID.']);
    }

    $this->respond(200, TRUE, 'Cliente consultado com sucesso.', $this->api_customer_model->formatCustomer($customer));
  }

  /**
   * GET /api/v1/contacts
   *
   * Recurso de topo com filtro, e não sub-recurso de cliente: assim responde
   * tanto "os contatos deste cliente" (`customer_id`) quanto "todos os
   * contatos financeiros do tenant" (`type`), que num caminho aninhado não
   * teria endpoint.
   */
  public function contacts()
  {
    $this->requireGet();
    $this->requireScope('contacts:read');
    $pagination = $this->pagination();

    $filters = [
      'customer_id' => $this->optionalPositiveInteger('customer_id'),
      'type' => $this->optionalEnum('type', array_keys(Api_customer_model::TIPOS_CONTATO)),
    ];

    $total = $this->api_customer_model->countContacts($this->api_company_id, $filters);
    $contacts = $this->api_customer_model->getContacts(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit'],
      $filters
    );

    $data = array_map(function ($contact) {
      return $this->api_customer_model->formatContact($contact);
    }, $contacts);

    $this->respond(200, TRUE, 'Contatos consultados com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/attachments
   *
   * Só metadados: o caminho do arquivo não sai, então não há URL de download.
   */
  public function attachments()
  {
    $this->requireGet();
    $this->requireScope('attachments:read');
    $pagination = $this->pagination();

    $filters = ['customer_id' => $this->optionalPositiveInteger('customer_id')];

    $total = $this->api_customer_model->countFiles($this->api_company_id, $filters);
    $files = $this->api_customer_model->getFiles(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit'],
      $filters
    );

    $data = array_map(function ($file) {
      return $this->api_customer_model->formatFile($file);
    }, $files);

    $this->respond(200, TRUE, 'Anexos consultados com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/contracts
   *
   * A listagem traz os tipos de serviço (uma consulta em lote para a página
   * inteira), mas NÃO os domínios: seriam centenas de linhas por página. Quem
   * quer domínios usa /contracts/{id} ou /contract-domains.
   */
  public function contracts()
  {
    $this->requireGet();
    $this->requireScope('contracts:read');
    $pagination = $this->pagination();

    $filters = [
      'customer_id' => $this->optionalPositiveInteger('customer_id'),
      'status' => $this->optionalEnum('status', ['vigente', 'suspenso', 'encerrado']),
      'cycle' => $this->optionalEnum('cycle', array_keys(Api_contract_model::CICLOS)),
      'billing_source' => $this->optionalEnum('billing_source', ['bomcontrole', 'cdwfinance']),
    ];

    $total = $this->api_contract_model->countContracts($this->api_company_id, $filters);
    $contracts = $this->api_contract_model->getContracts(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit'],
      $filters
    );

    $services = $this->api_contract_model->getContractServices(
      array_map(function ($contract) { return (int) $contract->id; }, $contracts),
      $this->api_company_id
    );

    $data = array_map(function ($contract) use ($services) {
      return $this->api_contract_model->formatContract($contract, $services[(int) $contract->id] ?? []);
    }, $contracts);

    $this->respond(200, TRUE, 'Contratos consultados com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/contracts/{id}
   *
   * A visão geral do contrato: dados, tipos de serviço, domínios e os
   * TÍTULOS — as faturas geradas aqui (`invoices`) e o extrato do Bom
   * Controle (`bomcontrole`), a mesma composição da tela do contrato.
   *
   * Os títulos só entram quando a chave tem `invoices:read`; sem o escopo, os
   * dois blocos são omitidos e o resto da resposta é servido normalmente.
   * Isso é deliberado: negar a requisição inteira tiraria da chave dados do
   * contrato que ela pode ver.
   *
   * O extrato do ERP é a única consulta desta API que vai à REDE, e só
   * acontece quando o contrato está vinculado — 383 dos 403 contratos estão.
   * Falha lá não derruba a resposta: o bloco sai com `available: false`.
   */
  public function contract($idContract = 0)
  {
    $this->requireGet();
    $this->requireScope('contracts:read');
    $idContract = (int) $idContract;

    if ($idContract <= 0) {
      $this->respond(422, FALSE, 'ID do contrato inválido.', NULL, ['id' => 'Informe um ID de contrato válido.']);
    }

    $contract = $this->api_contract_model->getContract($this->api_company_id, $idContract);
    if (empty($contract)) {
      $this->respond(404, FALSE, 'Contrato não encontrado.', NULL, ['id' => 'Nenhum contrato foi encontrado para este ID.']);
    }

    $services = $this->api_contract_model->getContractServices([$idContract], $this->api_company_id);
    $domains = $this->api_contract_model->getContractDomains(
      $this->api_company_id,
      Api_contract_model::MAX_DOMINIOS_NO_DETALHE,
      0,
      ['contract_id' => $idContract]
    );

    $data = $this->api_contract_model->formatContract(
      $contract,
      $services[$idContract] ?? [],
      array_map(function ($domain) {
        return $this->api_contract_model->formatContractDomain($domain);
      }, $domains),
      $this->api_contract_model->blocosDeTitulos($idContract, $this->api_company_id, $this->hasScope('invoices:read'))
    );

    $this->respond(200, TRUE, 'Contrato consultado com sucesso.', $data);
  }

  /**
   * GET /api/v1/contract-domains
   *
   * Cadastro COMERCIAL de domínios. Para disco e servidor, /server-domains.
   */
  public function contract_domains()
  {
    $this->requireGet();
    $this->requireScope('contract-domains:read');
    $pagination = $this->pagination();

    $filters = [
      'contract_id' => $this->optionalPositiveInteger('contract_id'),
      'customer_id' => $this->optionalPositiveInteger('customer_id'),
      'domain' => $this->optionalSearch('domain', 255),
      'contract_status' => $this->optionalEnum('contract_status', ['vigente', 'suspenso', 'encerrado']),
      'managed_cdw' => $this->optionalBoolean('managed_cdw'),
      'due_before' => $this->optionalDate('due_before'),
      'due_after' => $this->optionalDate('due_after'),
    ];

    $total = $this->api_contract_model->countContractDomains($this->api_company_id, $filters);
    $domains = $this->api_contract_model->getContractDomains(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit'],
      $filters
    );

    $data = array_map(function ($domain) {
      return $this->api_contract_model->formatContractDomain($domain);
    }, $domains);

    $this->respond(200, TRUE, 'Domínios contratados consultados com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/servers
   */
  public function servers()
  {
    $this->requireGet();
    $this->requireScope('servers:read');
    $pagination = $this->pagination();

    $filters = [
      'type' => $this->optionalEnum('type', ['whm', 'directadmin', 'cloudpanel', 'carbonio']),
      'active' => $this->optionalBoolean('active'),
    ];

    $total = $this->api_server_model->countServers($this->api_company_id, $filters);
    $servers = $this->api_server_model->getServers(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit'],
      $filters
    );

    $data = array_map(function ($server) {
      return $this->api_server_model->formatServer($server);
    }, $servers);

    $this->respond(200, TRUE, 'Servidores consultados com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/servers/{id}
   */
  public function server($idServer = 0)
  {
    $this->requireGet();
    $this->requireScope('servers:read');
    $idServer = (int) $idServer;

    if ($idServer <= 0) {
      $this->respond(422, FALSE, 'ID do servidor inválido.', NULL, ['id' => 'Informe um ID de servidor válido.']);
    }

    $server = $this->api_server_model->getServer($this->api_company_id, $idServer);
    if (empty($server)) {
      $this->respond(404, FALSE, 'Servidor não encontrado.', NULL, ['id' => 'Nenhum servidor foi encontrado para este ID.']);
    }

    $this->respond(200, TRUE, 'Servidor consultado com sucesso.', $this->api_server_model->formatServer($server));
  }

  /**
   * GET /api/v1/server-domains
   *
   * INVENTÁRIO: uma linha por conta de hospedagem, com disco e WHOIS. Para o
   * domínio contratado (vencimento cadastrado, registrador), /contract-domains.
   */
  public function server_domains()
  {
    $this->requireGet();
    $this->requireScope('server-domains:read');
    $pagination = $this->pagination();

    $filters = [
      'server_id' => $this->optionalPositiveInteger('server_id'),
      'domain' => $this->optionalSearch('domain', 255),
      'search' => $this->optionalSearch('search', 255),
      'status' => $this->optionalEnum('status', ['ativo', 'suspenso']),
      'source' => $this->optionalEnum('source', ['whm', 'directadmin', 'cloudpanel', 'carbonio', 'manual']),
      'whois_bucket' => $this->optionalEnum('whois_bucket', Api_server_model::FAIXAS_WHOIS),
    ];

    $total = $this->api_server_model->countServerDomains($this->api_company_id, $filters);
    $domains = $this->api_server_model->getServerDomains(
      $this->api_company_id,
      $pagination['limit'],
      ($pagination['page'] - 1) * $pagination['limit'],
      $filters
    );

    $data = array_map(function ($domain) {
      return $this->api_server_model->formatServerDomain($domain);
    }, $domains);

    $this->respond(200, TRUE, 'Contas de hospedagem consultadas com sucesso.', $data, [], [
      'page' => $pagination['page'],
      'limit' => $pagination['limit'],
      'total' => $total,
    ]);
  }

  /**
   * GET /api/v1/service-types
   *
   * Catálogo GLOBAL: `crm_service_types` não tem `id_company` — o mesmo
   * vocabulário vale para todos os tenants, como já valia no painel. Por isso
   * é o único endpoint sem recorte por empresa, e não paginado: são 12 itens.
   * A spec e a descrição da tool dizem isso, senão parece falha de escopo.
   */
  public function service_types()
  {
    $this->requireGet();
    $this->requireScope('service-types:read');

    $rows = $this->db->select('id, id_status, name, monitor_site')
      ->from('crm_service_types')
      ->order_by('name', 'asc')
      ->get()
      ->result();

    $data = array_map(function ($row) {
      return [
        'id' => (int) $row->id,
        'name' => $row->name,
        'active' => (int) $row->id_status === 1,
        // Diz se contratos deste tipo entram no monitoramento diário de site.
        'monitor_site' => (bool) $row->monitor_site,
      ];
    }, $rows);

    $this->respond(200, TRUE, 'Tipos de serviço consultados com sucesso.', $data);
  }

  /**
   * Inteiro positivo opcional vindo da query string. Responde 422 e encerra
   * quando o valor veio e não é válido; devolve NULL quando não veio.
   */
  private function optionalPositiveInteger($name)
  {
    $value = $this->input->get($name);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!preg_match('/^[1-9][0-9]*$/', (string) $value)) {
      $this->respond(422, FALSE, 'Parâmetro ' . $name . ' inválido.', NULL, [$name => 'Informe um inteiro positivo.']);
    }
    return (int) $value;
  }

  /**
   * Valor de query restrito a um catálogo. O valor NUNCA entra no SQL sem
   * passar por aqui — allowlist, e não sanitização, é o que garante isso.
   */
  private function optionalEnum($name, array $allowed)
  {
    $value = $this->input->get($name);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!in_array((string) $value, $allowed, TRUE)) {
      $this->respond(422, FALSE, 'Parâmetro ' . $name . ' inválido.', NULL, [
        $name => 'Valores aceitos: ' . implode(', ', $allowed) . '.',
      ]);
    }
    return (string) $value;
  }

  /**
   * Data opcional em `YYYY-MM-DD`. Confere que a data EXISTE, e não só que
   * casa o formato: `2026-02-31` passa no regex e o MySQL a compararia como
   * data inválida, devolvendo lista vazia sem explicação.
   */
  private function optionalDate($name)
  {
    $value = $this->input->get($name);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $value = (string) $value;
    $data = DateTime::createFromFormat('Y-m-d', $value);
    if ($data === FALSE || $data->format('Y-m-d') !== $value) {
      $this->respond(422, FALSE, 'Parâmetro ' . $name . ' inválido.', NULL, [
        $name => 'Informe uma data no formato AAAA-MM-DD.',
      ]);
    }
    return $value;
  }

  /**
   * Booleano opcional. Devolve TRUE/FALSE quando veio e NULL quando não veio —
   * a distinção importa: "não filtrar" é diferente de "filtrar por falso".
   */
  private function optionalBoolean($name)
  {
    $value = $this->input->get($name);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!in_array((string) $value, ['0', '1', 'true', 'false'], TRUE)) {
      $this->respond(422, FALSE, 'Parâmetro ' . $name . ' inválido.', NULL, [
        $name => 'Informe 0 ou 1.',
      ]);
    }
    return in_array((string) $value, ['1', 'true'], TRUE);
  }

  /**
   * Texto livre de busca, com teto de tamanho. O valor é escapado no model
   * (Global_model::likeInsensitive); o teto existe para uma keyword enorme
   * não virar um LIKE caro sobre a base inteira.
   */
  private function optionalSearch($name, $maxLength = 100)
  {
    $value = $this->input->get($name);
    if ($value === NULL || trim((string) $value) === '') {
      return NULL;
    }
    $value = trim((string) $value);
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
      $this->respond(422, FALSE, 'Parâmetro ' . $name . ' inválido.', NULL, [
        $name => 'Informe no máximo ' . $maxLength . ' caracteres.',
      ]);
    }
    return $value;
  }
}
