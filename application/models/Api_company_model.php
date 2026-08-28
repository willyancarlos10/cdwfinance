<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Empresa (TENANT) exposta pela API pública.
 *
 * O escopo NUNCA vem do request: quem chama passa o `$idCompany` que o
 * Api_Controller derivou da chave Bearer. Por isso a listagem devolve
 * sempre um único registro — o dono da chave.
 *
 * Fora do JSON, de propósito:
 *  - `token`: é o segredo do link público de cadastro de clientes
 *    (/cadastro-cliente/<token>); publicá-lo deixaria qualquer portador da
 *    chave abrir cadastros no tenant.
 *  - `bomcontrole_*`: configuração de integração interna. O `base_url` diz
 *    para onde o financeiro conversa e não é assunto de quem consulta dados.
 */
class Api_company_model extends CI_Model
{
  /**
   * Colunas lidas da view. Lista explícita em vez de `*` para que uma coluna
   * nova na crm_companies_v (a view já foi recriada nas migrations 019 e 039)
   * não passe a vazar pela API sozinha.
   */
  const CAMPOS = 'id, id_status, cnpj, name, byname, alias, email, phone, owner, owner_cellphone,
    address, address_number, address_complement, address_district, address_zip,
    city_name, state_name, state_uf, last_login, created, modified,
    status_name';

  public function countCompanies($idCompany)
  {
    $this->applyFilters($idCompany);
    return (int) $this->db->count_all_results();
  }

  public function getCompanies($idCompany, $limit, $offset)
  {
    $this->db->select(self::CAMPOS);
    $this->applyFilters($idCompany);

    return $this->db->order_by('id', 'asc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  /**
   * Detalhe pelo id. Reusa a listagem para o filtro nunca divergir, e o
   * `$idCompany` continua sendo o da chave: pedir o id de outro tenant não
   * devolve nada, e o controller traduz isso em 404 (nunca 403, que
   * confirmaria a existência do registro).
   */
  public function getCompany($idCompany, $idRequested)
  {
    if ((int) $idRequested !== (int) $idCompany) {
      return NULL;
    }

    $rows = $this->getCompanies($idCompany, 1, 0);
    return !empty($rows) ? $rows[0] : NULL;
  }

  public function formatCompany($company)
  {
    return [
      'id' => (int) $company->id,
      'active' => (int) $company->id_status === 1,
      'status' => $company->status_name,
      'document' => $company->cnpj,
      'name' => $company->name,
      'byname' => $company->byname,
      'alias' => $company->alias,
      'email' => $company->email,
      'phone' => $company->phone,
      'owner' => [
        'name' => $company->owner,
        'cellphone' => $company->owner_cellphone,
      ],
      'address' => [
        'street' => $company->address,
        'number' => $company->address_number,
        'complement' => $company->address_complement,
        'district' => $company->address_district,
        'zip' => $company->address_zip,
        // A crm_companies_v junta cidade/estado por INNER JOIN, então na
        // prática nunca vêm nulos — mas o formatter não assume isso, para
        // não quebrar caso a view passe a usar LEFT JOIN como a de clientes.
        'city' => $company->city_name,
        'state' => $company->state_name,
        'uf' => $company->state_uf,
      ],
      'last_login' => $company->last_login,
      'created_at' => $company->created,
      'updated_at' => $company->modified,
    ];
  }

  /**
   * Condições da consulta, num método à parte porque `count_all_results()`
   * RESETA o query builder do CI3 — a contagem e a busca precisam montar o
   * WHERE cada uma por sua vez.
   */
  private function applyFilters($idCompany)
  {
    $this->db->from('crm_companies_v');
    $this->db->where('id', (int) $idCompany);
  }
}
