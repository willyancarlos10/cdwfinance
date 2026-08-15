<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contratos do cliente (seção Contratos da visão geral do cliente + página
 * própria de visão geral do contrato).
 *
 * Sobre `crm_contracts`:
 *  - `status` é varchar ('vigente' | 'suspenso') e NÃO usa crm_status de
 *    propósito, como em crm_servers_domains: é estado de negócio do contrato,
 *    não o ativo/inativo genérico do painel;
 *  - `cycle` guarda o slug do ciclo de pagamento (catálogo em
 *    Contratos::cycles(): mensal, bimestral, trimestral, quadrimestral,
 *    semestral, anual);
 *  - a FK de `id_customer` NÃO cascateia: a exclusão do CLIENTE é bloqueada
 *    no controller enquanto houver contrato (junto com anexos e contatos).
 *
 * `crm_contracts_services` é o N:N com o catálogo crm_service_types (o
 * vínculo que a migration 004 anunciou). A UNIQUE evita o mesmo tipo duas
 * vezes no contrato; cascateia a partir do contrato. A FK para o tipo NÃO
 * cascateia — e Tipos_servicos::post_excluir passa a bloquear a exclusão de
 * tipo vinculado.
 *
 * `crm_contracts_files` são os documentos do contrato (padrão dos anexos do
 * cliente). Sem cascade: a exclusão do CONTRATO apaga documentos e arquivos
 * físicos explicitamente no controller.
 */
class Migration_Contracts_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_contracts')) {
      $query = "
CREATE TABLE `crm_contracts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_customer` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do cliente para evitar JOIN nas checagens.',
  `status` varchar(20) NOT NULL DEFAULT 'vigente' COMMENT 'vigente | suspenso',
  `cycle` varchar(20) NOT NULL COMMENT 'Slug do ciclo de pagamento (catálogo em Contratos::cycles()).',
  `value` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor do ciclo, em reais.',
  `space_gb` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Espaço contratado, em Gb.',
  `comments` text DEFAULT NULL COMMENT 'Observações do contrato.',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_customer` (`id_customer`),
  KEY `id_company` (`id_company`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_contracts_ibfk_1` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`),
  CONSTRAINT `crm_contracts_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_contracts_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_contracts_ibfk_4` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    if (!$this->db->table_exists('crm_contracts_services')) {
      $query = "
CREATE TABLE `crm_contracts_services` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_contract` int(11) unsigned NOT NULL,
  `id_service_type` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contracts_services` (`id_contract`,`id_service_type`),
  KEY `id_service_type` (`id_service_type`),
  CONSTRAINT `crm_contracts_services_ibfk_1` FOREIGN KEY (`id_contract`) REFERENCES `crm_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `crm_contracts_services_ibfk_2` FOREIGN KEY (`id_service_type`) REFERENCES `crm_service_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    if (!$this->db->table_exists('crm_contracts_files')) {
      $query = "
CREATE TABLE `crm_contracts_files` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_contract` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do contrato para evitar JOIN nas checagens.',
  `name` varchar(150) NOT NULL COMMENT 'Observação/rótulo do documento.',
  `file` varchar(255) NOT NULL COMMENT 'Caminho relativo (images/contracts/ano/mes/arquivo).',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_contract` (`id_contract`),
  KEY `id_company` (`id_company`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_contracts_files_ibfk_1` FOREIGN KEY (`id_contract`) REFERENCES `crm_contracts` (`id`),
  CONSTRAINT `crm_contracts_files_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_contracts_files_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    $query = "
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
  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `crm_users`.`name` AS `created_user`
FROM ((`crm_contracts`
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_contracts`.`id_customer`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts`.`created_by`))
";
    $this->db->query($query);

    // id_customer/id_company na view do pivot: os tipos de todos os contratos
    // de um cliente saem em uma query só (sem N+1 na visão geral do cliente).
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

    $query = "
CREATE OR REPLACE VIEW `crm_contracts_files_v` AS
SELECT
  `crm_contracts_files`.`id` AS `id`,
  `crm_contracts_files`.`id_contract` AS `id_contract`,
  `crm_contracts_files`.`id_company` AS `id_company`,
  `crm_contracts_files`.`name` AS `name`,
  `crm_contracts_files`.`file` AS `file`,
  `crm_contracts_files`.`created` AS `created`,
  `crm_contracts_files`.`created_by` AS `created_by`,
  `crm_users`.`name` AS `created_user`
FROM (`crm_contracts_files`
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts_files`.`created_by`))
";
    $this->db->query($query);

    // A coluna "Contratos" da listagem de clientes deixa de ser placeholder:
    // recria a crm_customers_v com o contador (tabela com _v = recriar a view
    // na mesma migration da mudança).
    $query = "
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
    $this->db->query($query);
  }

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_contracts_files_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_contracts_services_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_contracts_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_contracts_files`");
    $this->db->query("DROP TABLE IF EXISTS `crm_contracts_services`");
    $this->db->query("DROP TABLE IF EXISTS `crm_contracts`");

    // Devolve a crm_customers_v ao formato da migration 003 (sem contador).
    $query = "
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
  `crm_users`.`name` AS `created_user`
FROM (((( `crm_customers`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_customers`.`id_status`))
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_customers`.`id_company`))
  LEFT JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_customers`.`id_city`))
  LEFT JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_customers`.`id_state`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers`.`created_by`)
";
    $this->db->query($query);
  }
}
