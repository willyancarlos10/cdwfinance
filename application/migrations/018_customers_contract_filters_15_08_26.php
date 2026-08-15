<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Filtros de contrato na listagem de clientes (tipo de serviço e situação
 * contratual).
 *
 * A `crm_customers_v` ganha duas colunas derivadas, no mesmo espírito do
 * `whois_bucket` da `crm_servers_domains_v`: a pergunta é sobre CONTRATO, mas
 * a listagem consulta a view do CLIENTE, e sem elas cada filtro exigiria um
 * subselect solto no WHERE — que o `Global_model` (e o Query Builder por baixo
 * dele) não sabe montar sem escapar o subselect como se fosse identificador.
 *
 *  - `active_contracts_count`: contratos com `status = 'vigente'`. É a
 *    resposta da "situação contratual" (`> 0` = cliente vigente, `= 0` =
 *    cliente sem contrato vigente) e também alimenta a coluna Situação da
 *    listagem — o mesmo número que o KPI "Clientes vigentes" do dashboard
 *    conta por cliente. Fica separado de `contracts_count` (migration 009), que
 *    é o total de qualquer status: um cliente com 3 contratos encerrados tem
 *    contrato, mas não é cliente vigente.
 *  - `service_type_ids`: ids dos tipos de serviço de TODOS os contratos do
 *    cliente (qualquer status), em lista separada por vírgula para o
 *    `FIND_IN_SET` do filtro. Inclui contrato encerrado de propósito: a
 *    pergunta do filtro é "quem já contratou este serviço", e esconder o
 *    histórico faria o cliente sumir da busca no dia em que o contrato fosse
 *    encerrado. Recortar por vigente é papel do filtro de situação, que é um
 *    eixo independente — combinar os dois responde "tem algum contrato vigente"
 *    E "tem algum contrato deste tipo", não necessariamente o mesmo contrato.
 *
 * `GROUP_CONCAT(DISTINCT ...)` sem separador explícito usa vírgula, que é o
 * que o `FIND_IN_SET` espera; cliente sem contrato devolve NULL, e
 * `FIND_IN_SET(x, NULL)` é NULL — ou seja, fica de fora do filtro sozinho.
 *
 * Sem mudança de tabela: só a view.
 */
class Migration_Customers_contract_filters_15_08_26 extends CI_Migration
{
  public function up()
  {
    $this->db->query($this->viewComFiltros());
  }

  public function down()
  {
    $this->db->query($this->viewSemFiltros());
  }

  /**
   * `crm_customers_v` com as colunas derivadas de contrato — os demais campos
   * e JOINs são os da migration 015 (sem status do cliente).
   *
   * @return string
   */
  private function viewComFiltros()
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
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `contracts_count`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id` AND `crm_contracts`.`status` = 'vigente') AS `active_contracts_count`,
  (SELECT GROUP_CONCAT(DISTINCT `crm_contracts_services`.`id_service_type`)
     FROM `crm_contracts_services`
     JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_services`.`id_contract`)
    WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `service_type_ids`
FROM ((( `crm_customers`
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_customers`.`id_company`))
  LEFT JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_customers`.`id_city`))
  LEFT JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_customers`.`id_state`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers`.`created_by`)
";
  }

  /**
   * `crm_customers_v` como estava na migration 015, para o down().
   *
   * @return string
   */
  private function viewSemFiltros()
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
}
