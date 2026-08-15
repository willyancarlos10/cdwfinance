<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Remove o status do cadastro do cliente final (`crm_customers.id_status`).
 *
 * O cliente final não tem estado próprio no financeiro: o que interessa é se
 * ele tem CONTRATO VIGENTE — informação que vive em `crm_contracts.status` e
 * já alimenta os indicadores do dashboard ("Clientes vigentes" =
 * COUNT(DISTINCT id_customer) com contrato vigente). Manter um ativo/inativo
 * ao lado disso criava duas verdades sobre o mesmo cliente e um fluxo de
 * "ativação" que não decidia nada: o cadastro nascia "em análise" (4) e
 * precisava ser promovido a 1 só para deixar de exibir um aviso.
 *
 * O que sai junto:
 *  - o wizard público grava o cliente direto, sem status inicial;
 *  - `Clientes::post_ativarcadastro` e o aviso "aguardando ativação" deixaram
 *    de existir;
 *  - `crm_customers` saiu da lista branca de `json_posttoggle_status`, e o
 *    switch "Ativo" e a coluna "Status" saíram da listagem;
 *  - o filtro `f_customers[id_status]` saiu da tela e é descartado da sessão
 *    em `Clientes::setDefaultCustomerFilter` — o filtro gravado é aplicado
 *    como `WHERE <campo> = <valor>` pelo Global_model, então um valor antigo
 *    apontaria para uma coluna que não existe mais.
 *
 * A view é recriada ANTES do drop da coluna (sem `id_status`, `status_name`,
 * `status_color` e sem o JOIN em `crm_status`): view apontando para coluna
 * inexistente só quebra na hora do SELECT, e nesse intervalo toda tela de
 * cliente cairia.
 *
 * Nada mais no schema depende dessa coluna — `crm_contracts_v` faz JOIN em
 * `crm_customers` só por `name`/`byname`.
 */
class Migration_Customers_drop_status_14_08_26 extends CI_Migration
{
  public function up()
  {
    $this->db->query($this->viewSemStatus());

    if ($this->db->field_exists('id_status', 'crm_customers')) {
      // A FK precisa cair antes da coluna. O nome vem do information_schema
      // (e não fixo como `crm_customers_ibfk_2`) porque bancos criados fora
      // da migration 003 podem ter numeração diferente.
      $constraints = $this->db->query("
        SELECT `CONSTRAINT_NAME`
        FROM `information_schema`.`KEY_COLUMN_USAGE`
        WHERE `TABLE_SCHEMA` = DATABASE()
          AND `TABLE_NAME` = 'crm_customers'
          AND `COLUMN_NAME` = 'id_status'
          AND `REFERENCED_TABLE_NAME` IS NOT NULL
      ")->result();

      foreach ($constraints as $constraint) {
        $this->db->query('ALTER TABLE `crm_customers` DROP FOREIGN KEY `' . $constraint->CONSTRAINT_NAME . '`');
      }

      // O índice `id_status` cai junto com a coluna (é de coluna única).
      $this->dbforge->drop_column('crm_customers', 'id_status');
    }
  }

  public function down()
  {
    if (!$this->db->field_exists('id_status', 'crm_customers')) {
      $this->dbforge->add_column('crm_customers', [
        'id_status' => [
          'type' => 'INT',
          'constraint' => 11,
          'unsigned' => TRUE,
          'null' => FALSE,
          'default' => 1,
          'comment' => '1 ativo | 2 inativo | 3 suspenso | 4 em análise (wizard entra com 4).',
          'after' => 'id_company',
        ],
      ]);
      $this->db->query('ALTER TABLE `crm_customers` ADD KEY `id_status` (`id_status`)');
      $this->db->query('ALTER TABLE `crm_customers` ADD CONSTRAINT `crm_customers_ibfk_2` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`)');
    }

    $this->db->query($this->viewComStatus());
  }

  /**
   * `crm_customers_v` sem status — os demais campos e JOINs são os da
   * migration 009 (que acrescentou `contracts_count`).
   *
   * @return string
   */
  private function viewSemStatus()
  {
    return "
CREATE OR REPLACE VIEW `crm_customers_v` AS
SELECT
  `crm_customers`.`id` AS `id`,
  `crm_customers`.`id_company` AS `id_company`,
  `crm_customers`.`type` AS `type`,
  `crm_customers`.`document` AS `document`,
  `crm_customers`.`name` AS `name`,
  `crm_customers`.`byname` AS `byname`,
  `crm_customers`.`email` AS `email`,
  `crm_customers`.`address` AS `address`,
  `crm_customers`.`address_number` AS `address_number`,
  `crm_customers`.`address_complement` AS `address_complement`,
  `crm_customers`.`address_district` AS `address_district`,
  `crm_customers`.`address_zip` AS `address_zip`,
  `crm_customers`.`id_state` AS `id_state`,
  `crm_customers`.`id_city` AS `id_city`,
  `crm_customers`.`attributes` AS `attributes`,
  `crm_customers`.`created` AS `created`,
  `crm_customers`.`created_by` AS `created_by`,
  `crm_customers`.`modified` AS `modified`,
  `crm_customers`.`modified_by` AS `modified_by`,
  `crm_companies`.`byname` AS `company_byname`,
  `crm_country_cities`.`name` AS `city_name`,
  `crm_country_states`.`name` AS `state_name`,
  `crm_country_states`.`uf` AS `state_uf`,
  `crm_users`.`name` AS `created_user`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `contracts_count`
FROM ((( `crm_customers`
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_customers`.`id_company`))
  LEFT JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_customers`.`id_city`))
  LEFT JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_customers`.`id_state`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers`.`created_by`)
";
  }

  /**
   * `crm_customers_v` como estava na migration 009, para o down().
   *
   * @return string
   */
  private function viewComStatus()
  {
    return "
CREATE OR REPLACE VIEW `crm_customers_v` AS
SELECT
  `crm_customers`.`id` AS `id`,
  `crm_customers`.`id_company` AS `id_company`,
  `crm_customers`.`id_status` AS `id_status`,
  `crm_customers`.`type` AS `type`,
  `crm_customers`.`document` AS `document`,
  `crm_customers`.`name` AS `name`,
  `crm_customers`.`byname` AS `byname`,
  `crm_customers`.`email` AS `email`,
  `crm_customers`.`address` AS `address`,
  `crm_customers`.`address_number` AS `address_number`,
  `crm_customers`.`address_complement` AS `address_complement`,
  `crm_customers`.`address_district` AS `address_district`,
  `crm_customers`.`address_zip` AS `address_zip`,
  `crm_customers`.`id_state` AS `id_state`,
  `crm_customers`.`id_city` AS `id_city`,
  `crm_customers`.`attributes` AS `attributes`,
  `crm_customers`.`created` AS `created`,
  `crm_customers`.`created_by` AS `created_by`,
  `crm_customers`.`modified` AS `modified`,
  `crm_customers`.`modified_by` AS `modified_by`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`,
  `crm_companies`.`byname` AS `company_byname`,
  `crm_country_cities`.`name` AS `city_name`,
  `crm_country_states`.`name` AS `state_name`,
  `crm_country_states`.`uf` AS `state_uf`,
  `crm_users`.`name` AS `created_user`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `contracts_count`
FROM (((( `crm_customers`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_customers`.`id_status`))
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_customers`.`id_company`))
  LEFT JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_customers`.`id_city`))
  LEFT JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_customers`.`id_state`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers`.`created_by`)
";
  }
}
