<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Coluna derivada para o filtro "clientes com contrato sem vínculo com o Bom
 * Controle" na listagem de clientes.
 *
 * Nenhuma coluna física nasce aqui: a resposta já está em `crm_contracts`, e o
 * que faltava era um lugar de onde a listagem pudesse compará-la. O motivo é o
 * mesmo da migration 018 (`contracts_count`/`active_contracts_count`): o WHERE
 * da listagem é uma STRING passada ao Query Builder do CI3, que quebra a
 * expressão em AND/OR e passa cada lado do operador por `protect_identifiers`
 * — um subselect escrito ali sairia escapado como se fosse nome de coluna.
 * Estando na view, a condição vira `bomcontrole_unlinked_contracts_count > 0`.
 *
 * O que conta como pendência (as três condições são o filtro inteiro):
 *
 *  - `bomcontrole_contract_id IS NULL` (ou 0) é a definição de "sem vínculo".
 *    A âncora é o Id, e não `bomcontrole_linked`: o Id é o que torna a consulta
 *    do extrato possível, e o carimbo é só a data em que o vínculo foi feito.
 *    `desvincular` zera os dois, então eles nunca discordam — mas se um dia
 *    discordarem, quem manda é o que a integração de fato usa.
 *  - `billing_source = 'bomcontrole'`. Contrato migrado para o faturamento
 *    próprio (migration 024) é cobrado AQUI, e não ter vínculo com o ERP é o
 *    estado final correto dele, não uma pendência. Contá-lo encheria o filtro
 *    de ruído que só cresce à medida que a transição avança — e a lista
 *    deixaria de responder "o que falta vincular".
 *  - `status <> 'encerrado'`. Contrato encerrado saiu do ciclo de cobrança;
 *    ele ficaria na lista para sempre, sem ação possível do outro lado.
 *
 * Parte da última definição vigente da `crm_customers_v`, a da migration 023
 * (a 024 e a 025 mexeram em `crm_contracts_v`, não nesta). Sem DEFINER, senão
 * o deploy quebra em outro servidor.
 */
class Migration_Clientes_vinculo_bc_17_08_26 extends CI_Migration
{
  public function up()
  {
    $this->db->query($this->montarViewClientes(TRUE));
  }

  public function down()
  {
    $this->db->query($this->montarViewClientes(FALSE));
  }

  /**
   * A crm_customers_v da 023, com ou sem a coluna derivada desta migration.
   *
   * @param  bool $comColunaNova
   * @return string
   */
  private function montarViewClientes($comColunaNova)
  {
    $pendencia = $comColunaNova
      ? ",\n"
      . "  (SELECT COUNT(*) FROM `crm_contracts`\n"
      . "    WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`\n"
      . "      AND `crm_contracts`.`status` <> 'encerrado'\n"
      . "      AND `crm_contracts`.`billing_source` = 'bomcontrole'\n"
      . "      AND (`crm_contracts`.`bomcontrole_contract_id` IS NULL\n"
      . "        OR `crm_contracts`.`bomcontrole_contract_id` = 0)) AS `bomcontrole_unlinked_contracts_count`"
      : '';

    return "
CREATE OR REPLACE VIEW `crm_customers_v` AS
SELECT
  `crm_customers`.`id` AS `id`,
  `crm_customers`.`id_company` AS `id_company`,
  `crm_customers`.`legacy_id` AS `legacy_id`,
  `crm_customers`.`bomcontrole_customer_id` AS `bomcontrole_customer_id`,
  `crm_customers`.`bomcontrole_synced` AS `bomcontrole_synced`,
  `crm_customers`.`type` AS `type`,
  `crm_customers`.`document` AS `document`,
  `crm_customers`.`name` AS `name`,
  `crm_customers`.`byname` AS `byname`,
  `crm_customers`.`state_registration` AS `state_registration`,
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
    WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `service_type_ids`{$pendencia}
FROM ((( `crm_customers`
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_customers`.`id_company`))
  LEFT JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_customers`.`id_city`))
  LEFT JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_customers`.`id_state`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers`.`created_by`)
";
  }
}
