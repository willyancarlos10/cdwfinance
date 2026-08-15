<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Integração Bom Controle — vínculo do contrato e credenciais por tenant.
 *
 * O extrato financeiro do contrato vem do ERP Bom Controle. A ponte entre os
 * dois sistemas tem duas pontas, e cada uma ganha colunas aqui:
 *
 *  - `crm_contracts.bomcontrole_contract_id` guarda o Id do VendaContrato no
 *    Bom Controle (NULL = sem vínculo). Só o Id: os dados financeiros são
 *    consultados ao vivo na API — um snapshot local criaria uma segunda
 *    verdade sobre faturas que mudam lá o tempo todo (mesmo princípio que
 *    tirou o `id_status` do cliente na 015). `bomcontrole_linked` carimba
 *    quando o vínculo foi feito.
 *
 *  - `crm_companies` ganha a config POR TENANT (`bomcontrole_active`,
 *    `bomcontrole_base_url`, `bomcontrole_secret`): cada empresa tem a própria
 *    conta no Bom Controle, então a chave não pode viver em Parâmetros Gerais,
 *    que é parametrização global da empresa 1. Mesmo desenho de
 *    `crm_servers.secret`: credencial cifrada com Secret_crypto na linha do
 *    dono.
 *
 * A `crm_companies_v` é recriada COM `bomcontrole_active`/`bomcontrole_base_url`
 * e SEM `bomcontrole_secret` — as telas leem da view, então a credencial
 * cifrada nunca chega ao navegador (o mesmo motivo de `secret` não existir na
 * `crm_servers_v`). Quem precisa dela decifra direto da tabela, num ponto só
 * (Bomcontrole_model::getApiKey()).
 *
 * Sem FK nem índice novos: `bomcontrole_contract_id` é lido sempre a partir do
 * `id` do contrato (PK), e o id remoto não referencia tabela local.
 */
class Migration_Bomcontrole_15_08_26 extends CI_Migration
{
  private $colunasContrato = [
    'bomcontrole_contract_id' => [
      'type' => 'INT',
      'constraint' => 11,
      'unsigned' => TRUE,
      'null' => TRUE,
      'comment' => 'Id do VendaContrato no ERP Bom Controle; NULL = sem vinculo.',
      'after' => 'ended_by',
    ],
    'bomcontrole_linked' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Quando o vinculo com o Bom Controle foi feito.',
      'after' => 'bomcontrole_contract_id',
    ],
  ];

  private $colunasEmpresa = [
    'bomcontrole_active' => [
      'type' => 'TINYINT',
      'constraint' => 1,
      'unsigned' => TRUE,
      'null' => FALSE,
      'default' => 0,
      'comment' => 'Integracao Bom Controle ativa para esta empresa (1) ou nao (0).',
      'after' => 'last_login',
    ],
    'bomcontrole_base_url' => [
      'type' => 'VARCHAR',
      'constraint' => 255,
      'null' => TRUE,
      'comment' => 'Base URL da API Bom Controle; vazio = URL padrao da library.',
      'after' => 'bomcontrole_active',
    ],
    'bomcontrole_secret' => [
      'type' => 'TEXT',
      'null' => TRUE,
      'comment' => 'API key do Bom Controle cifrada (Secret_crypto). Fora da crm_companies_v de proposito.',
      'after' => 'bomcontrole_base_url',
    ],
  ];

  public function up()
  {
    foreach ($this->colunasContrato as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->add_column('crm_contracts', [$nome => $definicao]);
      }
    }

    foreach ($this->colunasEmpresa as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_companies')) {
        $this->dbforge->add_column('crm_companies', [$nome => $definicao]);
      }
    }

    $this->db->query($this->viewContratosComBomcontrole());
    $this->db->query($this->viewEmpresasComBomcontrole());
  }

  public function down()
  {
    // Views voltam ao formato anterior ANTES das colunas caírem: view
    // apontando para coluna inexistente derruba toda tela que a lê (mesma
    // ordem da 015 e da 017).
    $this->db->query($this->viewContratosOriginal());
    $this->db->query($this->viewEmpresasOriginal());

    foreach (array_keys($this->colunasContrato) as $nome) {
      if ($this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->drop_column('crm_contracts', $nome);
      }
    }

    foreach (array_keys($this->colunasEmpresa) as $nome) {
      if ($this->db->field_exists($nome, 'crm_companies')) {
        $this->dbforge->drop_column('crm_companies', $nome);
      }
    }
  }

  /**
   * A crm_contracts_v da 017 + as duas colunas do vínculo Bom Controle.
   *
   * @return string
   */
  private function viewContratosComBomcontrole()
  {
    return "
CREATE OR REPLACE VIEW `crm_contracts_v` AS
SELECT
  `crm_contracts`.`id` AS `id`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`,
  `crm_contracts`.`status` AS `status`,
  `crm_contracts`.`cycle` AS `cycle`,
  `crm_contracts`.`value` AS `value`,
  `crm_contracts`.`space_gb` AS `space_gb`,
  `crm_contracts`.`comments` AS `comments`,
  `crm_contracts`.`ended` AS `ended`,
  `crm_contracts`.`ended_reason` AS `ended_reason`,
  `crm_contracts`.`ended_comments` AS `ended_comments`,
  `crm_contracts`.`ended_by` AS `ended_by`,
  `crm_contracts`.`bomcontrole_contract_id` AS `bomcontrole_contract_id`,
  `crm_contracts`.`bomcontrole_linked` AS `bomcontrole_linked`,
  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `u_created`.`name` AS `created_user`,
  `u_ended`.`name` AS `ended_user`
FROM (((`crm_contracts`
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_contracts`.`id_customer`))
  LEFT JOIN `crm_users` `u_created` ON(`u_created`.`id` = `crm_contracts`.`created_by`))
  LEFT JOIN `crm_users` `u_ended` ON(`u_ended`.`id` = `crm_contracts`.`ended_by`))
";
  }

  /**
   * A crm_contracts_v exatamente como a migration 017 a deixou.
   *
   * @return string
   */
  private function viewContratosOriginal()
  {
    return "
CREATE OR REPLACE VIEW `crm_contracts_v` AS
SELECT
  `crm_contracts`.`id` AS `id`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`,
  `crm_contracts`.`status` AS `status`,
  `crm_contracts`.`cycle` AS `cycle`,
  `crm_contracts`.`value` AS `value`,
  `crm_contracts`.`space_gb` AS `space_gb`,
  `crm_contracts`.`comments` AS `comments`,
  `crm_contracts`.`ended` AS `ended`,
  `crm_contracts`.`ended_reason` AS `ended_reason`,
  `crm_contracts`.`ended_comments` AS `ended_comments`,
  `crm_contracts`.`ended_by` AS `ended_by`,
  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `u_created`.`name` AS `created_user`,
  `u_ended`.`name` AS `ended_user`
FROM (((`crm_contracts`
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_contracts`.`id_customer`))
  LEFT JOIN `crm_users` `u_created` ON(`u_created`.`id` = `crm_contracts`.`created_by`))
  LEFT JOIN `crm_users` `u_ended` ON(`u_ended`.`id` = `crm_contracts`.`ended_by`))
";
  }

  /**
   * A crm_companies_v da 001 + `bomcontrole_active` e `bomcontrole_base_url`.
   *
   * `bomcontrole_secret` NÃO entra: as telas leem da view e a credencial não
   * pode chegar ao navegador. Os INNER JOINs de cidade/estado/status ficam
   * como estão (a armadilha deles é conhecida — ver docblock da 003 — mas
   * mudar o comportamento da view não é assunto desta migration).
   *
   * @return string
   */
  private function viewEmpresasComBomcontrole()
  {
    return "
CREATE OR REPLACE VIEW `crm_companies_v` AS
SELECT
  `crm_companies`.`id` AS `id`,
  `crm_companies`.`id_status` AS `id_status`,
  `crm_companies`.`cnpj` AS `cnpj`,
  `crm_companies`.`name` AS `name`,
  `crm_companies`.`byname` AS `byname`,
  `crm_companies`.`address` AS `address`,
  `crm_companies`.`address_number` AS `address_number`,
  `crm_companies`.`address_complement` AS `address_complement`,
  `crm_companies`.`address_district` AS `address_district`,
  `crm_companies`.`address_zip` AS `address_zip`,
  `crm_companies`.`id_state` AS `id_state`,
  `crm_companies`.`id_city` AS `id_city`,
  `crm_companies`.`phone` AS `phone`,
  `crm_companies`.`owner` AS `owner`,
  `crm_companies`.`owner_cellphone` AS `owner_cellphone`,
  `crm_companies`.`alias` AS `alias`,
  `crm_companies`.`email` AS `email`,
  `crm_companies`.`token` AS `token`,
  `crm_companies`.`last_login` AS `last_login`,
  `crm_companies`.`bomcontrole_active` AS `bomcontrole_active`,
  `crm_companies`.`bomcontrole_base_url` AS `bomcontrole_base_url`,
  `crm_companies`.`created` AS `created`,
  `crm_companies`.`created_by` AS `created_by`,
  `crm_companies`.`modified` AS `modified`,
  `crm_companies`.`modified_by` AS `modified_by`,
  `crm_country_cities`.`name` AS `city_name`,
  `crm_country_states`.`name` AS `state_name`,
  `crm_country_states`.`uf` AS `state_uf`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`
FROM (((`crm_companies`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_companies`.`id_status`))
  JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_companies`.`id_city`))
  JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_companies`.`id_state`))
";
  }

  /**
   * A crm_companies_v exatamente como a migration 001 a deixou.
   *
   * @return string
   */
  private function viewEmpresasOriginal()
  {
    return "
CREATE OR REPLACE VIEW `crm_companies_v` AS
SELECT
  `crm_companies`.`id` AS `id`,
  `crm_companies`.`id_status` AS `id_status`,
  `crm_companies`.`cnpj` AS `cnpj`,
  `crm_companies`.`name` AS `name`,
  `crm_companies`.`byname` AS `byname`,
  `crm_companies`.`address` AS `address`,
  `crm_companies`.`address_number` AS `address_number`,
  `crm_companies`.`address_complement` AS `address_complement`,
  `crm_companies`.`address_district` AS `address_district`,
  `crm_companies`.`address_zip` AS `address_zip`,
  `crm_companies`.`id_state` AS `id_state`,
  `crm_companies`.`id_city` AS `id_city`,
  `crm_companies`.`phone` AS `phone`,
  `crm_companies`.`owner` AS `owner`,
  `crm_companies`.`owner_cellphone` AS `owner_cellphone`,
  `crm_companies`.`alias` AS `alias`,
  `crm_companies`.`email` AS `email`,
  `crm_companies`.`token` AS `token`,
  `crm_companies`.`last_login` AS `last_login`,
  `crm_companies`.`created` AS `created`,
  `crm_companies`.`created_by` AS `created_by`,
  `crm_companies`.`modified` AS `modified`,
  `crm_companies`.`modified_by` AS `modified_by`,
  `crm_country_cities`.`name` AS `city_name`,
  `crm_country_states`.`name` AS `state_name`,
  `crm_country_states`.`uf` AS `state_uf`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`
FROM (((`crm_companies`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_companies`.`id_status`))
  JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_companies`.`id_city`))
  JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_companies`.`id_state`))
";
  }
}
