<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Card "Contratos vigentes por tipo de serviço" do dashboard.
 *
 * Recria a `crm_contracts_services_v` acrescentando `contract_status` (vindo
 * de crm_contracts) — mesmo motivo da migration 011 na
 * `crm_contracts_domains_v`: o indicador agrupa por tipo de serviço contando
 * SÓ os contratos vigentes, e sem o status na view a consulta teria de juntar
 * a `crm_contracts` de novo por fora.
 *
 * Sem mudança de tabela — só a view. As demais colunas permanecem exatamente
 * como na migration 009.
 */
class Migration_Contracts_services_status_14_08_26 extends CI_Migration
{
  public function up()
  {
    $query = "
CREATE OR REPLACE VIEW `crm_contracts_services_v` AS
SELECT
  `crm_contracts_services`.`id` AS `id`,
  `crm_contracts_services`.`id_contract` AS `id_contract`,
  `crm_contracts_services`.`id_service_type` AS `id_service_type`,
  `crm_service_types`.`name` AS `service_type_name`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`,
  `crm_contracts`.`status` AS `contract_status`
FROM ((`crm_contracts_services`
  JOIN `crm_service_types` ON(`crm_service_types`.`id` = `crm_contracts_services`.`id_service_type`))
  JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_services`.`id_contract`))
";
    $this->db->query($query);
  }

  public function down()
  {
    // Devolve a view ao formato da migration 009 (sem o status do contrato).
    $query = "
CREATE OR REPLACE VIEW `crm_contracts_services_v` AS
SELECT
  `crm_contracts_services`.`id` AS `id`,
  `crm_contracts_services`.`id_contract` AS `id_contract`,
  `crm_contracts_services`.`id_service_type` AS `id_service_type`,
  `crm_service_types`.`name` AS `service_type_name`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`
FROM ((`crm_contracts_services`
  JOIN `crm_service_types` ON(`crm_service_types`.`id` = `crm_contracts_services`.`id_service_type`))
  JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_services`.`id_contract`))
";
    $this->db->query($query);
  }
}
