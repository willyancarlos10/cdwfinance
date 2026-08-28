<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Clientes finais e suas duas filhas (contatos e anexos) na API pública.
 *
 * Os três moram no mesmo model porque são o mesmo agregado e repetem o mesmo
 * recorte por tenant — separá-los em três arquivos triplicaria o `applyFilters`
 * sem separar responsabilidade nenhuma.
 *
 * O que NÃO sai daqui, e por quê:
 *  - `attributes.representative`: RG, CPF e endereço RESIDENCIAL do sócio. É o
 *    dado mais sensível do cadastro e não responde nenhuma pergunta de gestão.
 *  - `attributes.billing`: contato financeiro — quem quer contato usa o
 *    endpoint de contatos, que é o cadastro de verdade.
 *  - `attributes.consent`: IP e user-agent do aceite de LGPD.
 *  - `attributes.source`: procedência interna do cadastro.
 *  - `crm_customers_files.file`: o caminho do arquivo, que viraria URL de
 *    download. O anexo sai só como metadado.
 */
class Api_customer_model extends CI_Model
{
  /** Colunas do cliente. Lista explícita para coluna nova não vazar sozinha. */
  const CAMPOS_CLIENTE = 'id, type, document, name, byname, state_registration, email,
    address, address_number, address_complement, address_district, address_zip,
    city_name, state_name, state_uf, attributes,
    contracts_count, active_contracts_count, service_type_ids,
    created, modified';

  const CAMPOS_CONTATO = 'id, id_customer, type, name, email, phone, created, modified';

  /** A crm_customers_files_v NÃO tem `modified` — o anexo não tem updated_at. */
  const CAMPOS_ANEXO = 'id, id_customer, name, file, created, created_user';

  // ---------------------------------------------------------------- CLIENTES

  public function countCustomers($idCompany, array $filters = [])
  {
    $this->applyCustomerFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getCustomers($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS_CLIENTE);
    $this->applyCustomerFilters($idCompany, $filters);

    // Mesma ordenação da listagem do painel (Clientes::ORDENACAO_LISTAGEM):
    // cadastro mais recente primeiro, com `id` de desempate — `created` tem
    // precisão de segundo e a importação gravou blocos no mesmo segundo, que
    // sem critério estável trocariam de página.
    return $this->db->order_by('created', 'desc')
      ->order_by('id', 'desc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function getCustomer($idCompany, $idCustomer)
  {
    $rows = $this->getCustomers($idCompany, 1, 0, ['id' => (int) $idCustomer]);
    return !empty($rows) ? $rows[0] : NULL;
  }

  public function formatCustomer($customer)
  {
    $attrs = json_decode((string) $customer->attributes, TRUE);
    $attrs = is_array($attrs) ? $attrs : [];

    return [
      'id' => (int) $customer->id,
      'type' => $customer->type,
      'type_label' => $customer->type === 'J' ? 'Pessoa jurídica' : 'Pessoa física',
      'document' => $customer->document,
      'name' => $customer->name,
      'byname' => $customer->byname,
      'state_registration' => $customer->state_registration,
      'email' => $customer->email,
      'address' => [
        'street' => $customer->address,
        'number' => $customer->address_number,
        'complement' => $customer->address_complement,
        'district' => $customer->address_district,
        'zip' => $customer->address_zip,
        // LEFT JOIN na view: cliente com cidade não resolvida continua
        // aparecendo, com city/state nulos. Não assumir preenchido.
        'city' => $customer->city_name,
        'state' => $customer->state_name,
        'uf' => $customer->state_uf,
      ],
      // Do JSON só sai o que é de negócio. Ver o docblock da classe.
      'declared_domains' => [
        'primary' => $attrs['domains']['primary'] ?? NULL,
        'secondary' => $attrs['domains']['secondary'] ?? NULL,
      ],
      'contract_notes' => $attrs['contract']['comments'] ?? NULL,
      'contracts_count' => (int) $customer->contracts_count,
      'active_contracts_count' => (int) $customer->active_contracts_count,
      'service_type_ids' => $this->listaDeIds($customer->service_type_ids),
      'created_at' => $customer->created,
      'updated_at' => $customer->modified,
    ];
  }

  // ---------------------------------------------------------------- CONTATOS

  public function countContacts($idCompany, array $filters = [])
  {
    $this->applyContactFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getContacts($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS_CONTATO);
    $this->applyContactFilters($idCompany, $filters);

    return $this->db->order_by('id_customer', 'asc')
      ->order_by('id', 'asc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function formatContact($contact)
  {
    return [
      'id' => (int) $contact->id,
      'customer_id' => (int) $contact->id_customer,
      'type' => $contact->type,
      'type_label' => self::TIPOS_CONTATO[$contact->type] ?? $contact->type,
      'name' => $contact->name,
      'email' => $contact->email,
      'phone' => $contact->phone,
      'created_at' => $contact->created,
      'updated_at' => $contact->modified,
    ];
  }

  /**
   * Catálogo dos tipos de contato. Espelha Clientes::contactTypes(), que é
   * privado no controller e não alcança daqui. Se um tipo for acrescentado
   * lá, acrescente aqui — o `??` do formatter faz o slug aparecer cru em vez
   * de sumir, então a divergência fica visível em vez de silenciosa.
   */
  const TIPOS_CONTATO = [
    'financeiro' => 'Financeiro',
    'socio_proprietario' => 'Sócio proprietário',
    'gestor_trafego' => 'Gestor de tráfego',
    'juridico' => 'Jurídico',
    'marketing' => 'Marketing',
    'diretor' => 'Diretor',
    'outros' => 'Outros',
  ];

  // ----------------------------------------------------------------- ANEXOS

  public function countFiles($idCompany, array $filters = [])
  {
    $this->applyFileFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getFiles($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS_ANEXO);
    $this->applyFileFilters($idCompany, $filters);

    return $this->db->order_by('id', 'desc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function formatFile($file)
  {
    // A extensão sai do caminho e o caminho fica de fora: diz o tipo do
    // arquivo sem entregar por onde baixá-lo.
    $extensao = pathinfo((string) $file->file, PATHINFO_EXTENSION);

    return [
      'id' => (int) $file->id,
      'customer_id' => (int) $file->id_customer,
      'name' => $file->name,
      'extension' => $extensao !== '' ? mb_strtolower($extensao) : NULL,
      'created_at' => $file->created,
      'created_by_name' => $file->created_user,
    ];
  }

  // ------------------------------------------------------------- FILTROS

  /**
   * Todo filtro roda por aqui. `count_all_results()` reseta o query builder do
   * CI3, então contagem e busca chamam este método cada uma por sua vez.
   */
  private function applyCustomerFilters($idCompany, array $filters)
  {
    $this->db->from('crm_customers_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['type'])) {
      $this->db->where('type', (string) $filters['type']);
    }
    if (!empty($filters['document'])) {
      // O documento é gravado só com dígitos.
      $this->db->where('document', preg_replace('/\D/', '', (string) $filters['document']));
    }
    if (array_key_exists('has_active_contract', $filters) && $filters['has_active_contract'] !== NULL) {
      $this->db->where('active_contracts_count ' . ($filters['has_active_contract'] ? '>' : '='), 0);
    }
    if (!empty($filters['search'])) {
      // O group_start/group_end NÃO é estilo: `likeInsensitive(..., TRUE)` emite
      // or_where, e um OR solto ao lado do WHERE id_company produziria
      // `id_company = N AND nome LIKE x OR byname LIKE x` — que por precedência
      // devolve clientes de OUTROS tenants. Medido neste banco: 371 linhas para
      // um tenant inexistente. Os parênteses são o isolamento.
      $termo = (string) $filters['search'];
      $this->db->group_start();
      $this->global_model->likeInsensitive('name', $termo);
      $this->global_model->likeInsensitive('byname', $termo, TRUE);
      $this->global_model->likeInsensitive('email', $termo, TRUE);
      $this->db->group_end();
    }
  }

  private function applyContactFilters($idCompany, array $filters)
  {
    $this->db->from('crm_customers_contacts_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['customer_id'])) {
      $this->db->where('id_customer', (int) $filters['customer_id']);
    }
    if (!empty($filters['type'])) {
      $this->db->where('type', (string) $filters['type']);
    }
  }

  private function applyFileFilters($idCompany, array $filters)
  {
    $this->db->from('crm_customers_files_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['customer_id'])) {
      $this->db->where('id_customer', (int) $filters['customer_id']);
    }
  }

  /** `GROUP_CONCAT` da view vira array de inteiros. */
  private function listaDeIds($csv)
  {
    $csv = trim((string) $csv);
    if ($csv === '') {
      return [];
    }
    return array_values(array_map('intval', array_filter(explode(',', $csv), 'strlen')));
  }
}
