<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Aba "Domínios" da visão geral do cliente: todos os domínios dos contratos
 * VIGENTES do cliente, em uma query só.
 *
 * Recria a `crm_contracts_domains_v` acrescentando `id_customer` e
 * `contract_status` (vindos de crm_contracts) — mesmo motivo pelo qual a
 * migration 009 colocou id_customer/id_company na `crm_contracts_services_v`:
 * sem isso, a tela do cliente precisaria buscar os contratos e depois os
 * domínios de cada um (N+1).
 *
 * Sem mudança de tabela — só a view. O restante das colunas (inclusive as
 * server_*, com LEFT JOIN para o domínio sem vínculo continuar aparecendo)
 * permanece exatamente como na migration 010.
 */
class Migration_Contracts_domains_customer_14_08_26 extends CI_Migration
{
  public function up()
  {
    $query = "
CREATE OR REPLACE VIEW `crm_contracts_domains_v` AS
SELECT
  `crm_contracts_domains`.`id` AS `id`,
  `crm_contracts_domains`.`id_contract` AS `id_contract`,
  `crm_contracts_domains`.`id_company` AS `id_company`,
  `crm_contracts_domains`.`id_server_domain` AS `id_server_domain`,
  `crm_contracts_domains`.`domain` AS `domain`,
  `crm_contracts_domains`.`due_date` AS `due_date`,
  `crm_contracts_domains`.`registrar` AS `registrar`,
  `crm_contracts_domains`.`managed_cdw` AS `managed_cdw`,
  `crm_contracts_domains`.`comments` AS `comments`,
  `crm_contracts_domains`.`created` AS `created`,
  `crm_contracts_domains`.`created_by` AS `created_by`,
  `crm_contracts_domains`.`modified` AS `modified`,
  `crm_contracts_domains`.`modified_by` AS `modified_by`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`status` AS `contract_status`,
  `crm_contracts`.`cycle` AS `contract_cycle`,
  `crm_servers_domains`.`domain` AS `server_domain`,
  `crm_servers_domains`.`status` AS `server_domain_status`,
  `crm_servers_domains`.`disk_used_mb` AS `server_disk_used_mb`,
  `crm_servers`.`name` AS `server_name`,
  `crm_servers`.`type` AS `server_type`,
  `crm_users`.`name` AS `created_user`
FROM ((((`crm_contracts_domains`
  JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_domains`.`id_contract`))
  LEFT JOIN `crm_servers_domains` ON(`crm_servers_domains`.`id` = `crm_contracts_domains`.`id_server_domain`))
  LEFT JOIN `crm_servers` ON(`crm_servers`.`id` = `crm_servers_domains`.`id_server`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts_domains`.`created_by`))
";
    $this->db->query($query);
  }

  public function down()
  {
    // Devolve a view ao formato da migration 010 (sem as colunas do contrato).
    $query = "
CREATE OR REPLACE VIEW `crm_contracts_domains_v` AS
SELECT
  `crm_contracts_domains`.`id` AS `id`,
  `crm_contracts_domains`.`id_contract` AS `id_contract`,
  `crm_contracts_domains`.`id_company` AS `id_company`,
  `crm_contracts_domains`.`id_server_domain` AS `id_server_domain`,
  `crm_contracts_domains`.`domain` AS `domain`,
  `crm_contracts_domains`.`due_date` AS `due_date`,
  `crm_contracts_domains`.`registrar` AS `registrar`,
  `crm_contracts_domains`.`managed_cdw` AS `managed_cdw`,
  `crm_contracts_domains`.`comments` AS `comments`,
  `crm_contracts_domains`.`created` AS `created`,
  `crm_contracts_domains`.`created_by` AS `created_by`,
  `crm_contracts_domains`.`modified` AS `modified`,
  `crm_contracts_domains`.`modified_by` AS `modified_by`,
  `crm_servers_domains`.`domain` AS `server_domain`,
  `crm_servers_domains`.`status` AS `server_domain_status`,
  `crm_servers_domains`.`disk_used_mb` AS `server_disk_used_mb`,
  `crm_servers`.`name` AS `server_name`,
  `crm_servers`.`type` AS `server_type`,
  `crm_users`.`name` AS `created_user`
FROM (((`crm_contracts_domains`
  LEFT JOIN `crm_servers_domains` ON(`crm_servers_domains`.`id` = `crm_contracts_domains`.`id_server_domain`))
  LEFT JOIN `crm_servers` ON(`crm_servers`.`id` = `crm_servers_domains`.`id_server`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts_domains`.`created_by`))
";
    $this->db->query($query);
  }
}
