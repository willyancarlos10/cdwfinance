<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contratos, seus tipos de serviço e seus domínios na API pública.
 *
 * Os domínios de CONTRATO ficam aqui, e não no model de servidores, porque são
 * o cadastro comercial (vencimento, registrador, gerenciado CDW) — coisa do
 * contrato. O inventário de contas de hospedagem é outro conceito e vive em
 * Api_server_model. Ver o docblock de cada formatter.
 *
 * `notification_config` NÃO é exposto: é a lista de e-mails e telefones de
 * quem recebe aviso de boleto e reajuste — configuração com dado pessoal, que
 * não responde pergunta de gestão.
 */
class Api_contract_model extends CI_Model
{
  const CAMPOS_CONTRATO = 'id, id_customer, status, cycle, value, space_gb, comments,
    ended, ended_reason, ended_comments, ended_user,
    billing_source, billing_day, next_competence, installments, invoice_policy,
    adjustment_index, next_adjustment,
    bomcontrole_contract_id, bomcontrole_linked, bomcontrole_service_name,
    customer_name, customer_byname, created, modified';

  const CAMPOS_DOMINIO = 'id, id_contract, id_customer, id_server_domain, domain,
    due_date, registrar, managed_cdw, comments,
    contract_status, contract_cycle,
    server_domain, server_domain_status, server_disk_used_mb, server_name, server_type,
    created, modified';

  /**
   * Teto de domínios embutidos no detalhe do contrato, que não é paginado.
   * Na base atual o maior contrato tem 65 domínios, então 200 é folga larga —
   * mas continua sendo um teto: quem precisa de tudo paginado usa
   * /contract-domains?contract_id=N.
   *
   * Mora no model, e não no controller, porque REST e MCP montam o mesmo
   * detalhe e precisam do mesmo teto — a classe do controller não está
   * carregada quando o Mcp responde.
   */
  const MAX_DOMINIOS_NO_DETALHE = 200;

  /** Espelha Contratos::cycles(), que é privado no controller. */
  const CICLOS = [
    'mensal' => 'Mensal',
    'trimestral' => 'Trimestral',
    'semestral' => 'Semestral',
    'anual' => 'Anual',
  ];

  // --------------------------------------------------------------- CONTRATOS

  public function countContracts($idCompany, array $filters = [])
  {
    $this->applyContractFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getContracts($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS_CONTRATO);
    $this->applyContractFilters($idCompany, $filters);

    return $this->db->order_by('id', 'desc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function getContract($idCompany, $idContract)
  {
    $rows = $this->getContracts($idCompany, 1, 0, ['id' => (int) $idContract]);
    return !empty($rows) ? $rows[0] : NULL;
  }

  /**
   * Tipos de serviço de VÁRIOS contratos numa consulta só, indexados pelo id
   * do contrato. Em lote de propósito: um SELECT por contrato numa listagem de
   * 20 seriam 20 idas ao banco para montar uma coluna.
   */
  public function getContractServices(array $idContracts, $idCompany)
  {
    if (empty($idContracts)) {
      return [];
    }

    $rows = $this->db->select('id_contract, id_service_type, service_type_name')
      ->from('crm_contracts_services_v')
      ->where_in('id_contract', $idContracts)
      // O tenant entra também na filha: defesa em profundidade, não confia
      // que o id do pai já foi escopado.
      ->where('id_company', (int) $idCompany)
      ->order_by('service_type_name', 'asc')
      ->get()
      ->result();

    $servicos = [];
    foreach ($rows as $row) {
      $servicos[(int) $row->id_contract][] = [
        'id' => (int) $row->id_service_type,
        'name' => $row->service_type_name,
      ];
    }
    return $servicos;
  }

  /**
   * Monta os blocos de TÍTULOS do detalhe do contrato — as faturas geradas
   * aqui e o extrato do ERP.
   *
   * Vive no model, e não no controller, porque REST e MCP montam o mesmo
   * detalhe: duplicar isso faria os dois divergirem na primeira mudança.
   *
   * O escopo controla a PRESENÇA dos blocos, não o sucesso da requisição:
   * uma chave com `contracts:read` e sem `invoices:read` recebe o contrato
   * normalmente, sem os títulos. Responder 403 negaria também os dados do
   * contrato, que ela pode ver.
   *
   * @param  int  $idContract
   * @param  int  $idCompany
   * @param  bool $temEscopo chave tem `invoices:read`
   * @return array vazio quando não há escopo
   */
  public function blocosDeTitulos($idContract, $idCompany, $temEscopo)
  {
    if (!$temEscopo) {
      return [];
    }

    $this->load->model('api_invoice_model');
    $filtros = ['contract_id' => (int) $idContract];

    $faturas = $this->api_invoice_model->getInvoices(
      $idCompany,
      Api_invoice_model::MAX_FATURAS_NO_DETALHE,
      0,
      $filtros
    );

    return [
      'invoices' => array_map(
        [$this->api_invoice_model, 'formatInvoice'],
        $faturas
      ),
      // A chave é `bomcontrole_invoices`, e NÃO `bomcontrole`: esta última já
      // é o bloco de VÍNCULO do contrato com o ERP, montado em
      // formatContract(). Reusar o nome sobrescrevia o vínculo com o extrato
      // e o fazia sumir da resposta sempre que a chave tinha `invoices:read`.
      // Os dois nomes também ficam simétricos com `invoices`: são as duas
      // origens de título.
      //
      // A rede só acontece se o contrato estiver vinculado — a guarda está
      // dentro do Bomcontrole_model, que devolve `vinculado: FALSE` sem
      // chamar o ERP.
      'bomcontrole_invoices' => $this->api_invoice_model->extratoBomControle($idContract, $idCompany),
    ];
  }

  public function formatContract($contract, array $services = [], array $domains = NULL, array $titulos = [])
  {
    $dados = [
      'id' => (int) $contract->id,
      'customer' => [
        'id' => (int) $contract->id_customer,
        'name' => $contract->customer_name,
        'byname' => $contract->customer_byname,
      ],
      'status' => $contract->status,
      'cycle' => $contract->cycle,
      'cycle_label' => self::CICLOS[$contract->cycle] ?? $contract->cycle,
      // String decimal crua: converter para float perderia precisão de
      // centavo no JSON. Documentado na spec como decimal em string.
      'value' => $contract->value,
      'space_gb' => $contract->space_gb !== NULL ? (float) $contract->space_gb : NULL,
      'comments' => $contract->comments,
      'services' => $services,
      'billing' => [
        'source' => $contract->billing_source,
        'day' => (int) $contract->billing_day,
        'next_competence' => $contract->next_competence,
        'installments' => (int) $contract->installments,
        'invoice_policy' => $contract->invoice_policy,
        'adjustment_index' => $contract->adjustment_index,
        'next_adjustment' => $contract->next_adjustment,
      ],
      'bomcontrole' => [
        'linked' => !empty($contract->bomcontrole_contract_id),
        'contract_id' => $contract->bomcontrole_contract_id !== NULL ? (int) $contract->bomcontrole_contract_id : NULL,
        'linked_at' => $contract->bomcontrole_linked,
        'service_name' => $contract->bomcontrole_service_name,
      ],
      // `ended` só existe em contrato encerrado — o bloco inteiro é nulo nos
      // demais, em vez de quatro campos nulos soltos.
      'ended' => empty($contract->ended) ? NULL : [
        'at' => $contract->ended,
        'reason' => $contract->ended_reason,
        'comments' => $contract->ended_comments,
        'by_name' => $contract->ended_user,
      ],
      'created_at' => $contract->created,
      'updated_at' => $contract->modified,
    ];

    // Só o detalhe carrega os domínios; a listagem os omite para não fazer
    // uma consulta grande por página.
    if ($domains !== NULL) {
      $dados['domains'] = $domains;
    }

    // Idem para os títulos, que ainda por cima custam uma ida ao ERP.
    //
    // Bloco que colidiria com chave já montada acima é ERRO de programação, e
    // não algo a resolver em silêncio: foi assim que `bomcontrole_invoices`
    // nasceu chamando-se `bomcontrole` e apagou o bloco de vínculo do
    // contrato. Falhar aqui faz o próximo acidente aparecer no primeiro teste.
    foreach ($titulos as $chave => $valor) {
      if (array_key_exists($chave, $dados)) {
        log_message('error', '[API] formatContract: bloco "' . $chave . '" colide com campo já existente do contrato.');
        continue;
      }
      $dados[$chave] = $valor;
    }

    return $dados;
  }

  // ------------------------------------------------- DOMÍNIOS DE CONTRATO

  public function countContractDomains($idCompany, array $filters = [])
  {
    $this->applyDomainFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getContractDomains($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS_DOMINIO);
    $this->applyDomainFilters($idCompany, $filters);

    return $this->db->order_by('domain', 'asc')
      ->order_by('id', 'asc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function formatContractDomain($domain)
  {
    return [
      'id' => (int) $domain->id,
      'contract' => [
        'id' => (int) $domain->id_contract,
        'status' => $domain->contract_status,
        'cycle' => $domain->contract_cycle,
      ],
      'customer_id' => (int) $domain->id_customer,
      'domain' => $domain->domain,
      // Vencimento CADASTRADO. O observado no registro fica em
      // /server-domains, no bloco `whois`.
      'due_date' => $domain->due_date,
      'registrar' => $domain->registrar,
      'managed_cdw' => (bool) $domain->managed_cdw,
      'comments' => $domain->comments,
      // Vínculo opcional com a conta de hospedagem. Nulo quando o domínio não
      // está em nenhum servidor nosso — o que é normal, não é erro.
      // `server_host` existe na view e NÃO sai aqui, mesma razão de
      // /servers não expor host.
      'hosting_account' => empty($domain->id_server_domain) ? NULL : [
        'id' => (int) $domain->id_server_domain,
        'domain' => $domain->server_domain,
        'status' => $domain->server_domain_status,
        'disk_used_mb' => $domain->server_disk_used_mb !== NULL ? (float) $domain->server_disk_used_mb : NULL,
        'server' => [
          'name' => $domain->server_name,
          'type' => $domain->server_type,
        ],
      ],
      'created_at' => $domain->created,
      'updated_at' => $domain->modified,
    ];
  }

  // ------------------------------------------------------------- FILTROS

  private function applyContractFilters($idCompany, array $filters)
  {
    $this->db->from('crm_contracts_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['customer_id'])) {
      $this->db->where('id_customer', (int) $filters['customer_id']);
    }
    if (!empty($filters['status'])) {
      $this->db->where('status', (string) $filters['status']);
    }
    if (!empty($filters['cycle'])) {
      $this->db->where('cycle', (string) $filters['cycle']);
    }
    if (!empty($filters['billing_source'])) {
      $this->db->where('billing_source', (string) $filters['billing_source']);
    }
  }

  private function applyDomainFilters($idCompany, array $filters)
  {
    $this->db->from('crm_contracts_domains_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['contract_id'])) {
      $this->db->where('id_contract', (int) $filters['contract_id']);
    }
    if (!empty($filters['customer_id'])) {
      $this->db->where('id_customer', (int) $filters['customer_id']);
    }
    if (!empty($filters['domain'])) {
      $this->db->where('domain', (string) $filters['domain']);
    }
    if (!empty($filters['contract_status'])) {
      $this->db->where('contract_status', (string) $filters['contract_status']);
    }
    if (array_key_exists('managed_cdw', $filters) && $filters['managed_cdw'] !== NULL) {
      $this->db->where('managed_cdw', $filters['managed_cdw'] ? 1 : 0);
    }
    if (!empty($filters['due_before'])) {
      $this->db->where('due_date <=', (string) $filters['due_before']);
    }
    if (!empty($filters['due_after'])) {
      $this->db->where('due_date >=', (string) $filters['due_after']);
    }
  }
}
