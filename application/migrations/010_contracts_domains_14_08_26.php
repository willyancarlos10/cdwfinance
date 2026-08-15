<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Domínios do contrato (seção Domínios da visão geral do contrato).
 *
 * O domínio do contrato é um cadastro PRÓPRIO (domínio, vencimento, local de
 * registro, gerenciado CDW, observações) que PODE apontar para um domínio
 * sincronizado de servidor (crm_servers_domains) — o vínculo é resolvido pela
 * busca no modal e revalidado no servidor ao salvar.
 *
 * Decisões:
 *  - `id_server_domain` é NULLable com **ON DELETE SET NULL**: a sincronização
 *    de servidores PODA domínios ausentes (upsert autoritativo da 002); se o
 *    vínculo cascateasse, a poda apagaria o domínio do contrato. Com SET NULL
 *    ele só volta a ficar "sem vínculo".
 *  - UNIQUE (id_contract, domain): o mesmo domínio não entra duas vezes no
 *    mesmo contrato (pode existir em contratos diferentes).
 *  - A FK de `id_contract` NÃO cascateia: a exclusão do contrato remove os
 *    domínios explicitamente no controller (Contratos::post_excluir), junto
 *    com os documentos.
 *  - A soma de uso do contrato considera SÓ os domínios com vínculo
 *    (disk_used_mb do crm_servers_domains) — domínio sem correspondência não
 *    entra na soma.
 */
class Migration_Contracts_domains_14_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_contracts_domains')) {
      $query = "
CREATE TABLE `crm_contracts_domains` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_contract` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do contrato para evitar JOIN nas checagens.',
  `id_server_domain` int(11) unsigned DEFAULT NULL COMMENT 'Vínculo com o domínio sincronizado; NULL = sem vínculo.',
  `domain` varchar(255) NOT NULL COMMENT 'Normalizado: minúsculo, sem esquema/caminho.',
  `due_date` date DEFAULT NULL COMMENT 'Data de vencimento do registro do domínio.',
  `registrar` varchar(150) DEFAULT NULL COMMENT 'Local de registro (Registro.br, GoDaddy...).',
  `managed_cdw` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = gerenciado pela CDW.',
  `comments` text DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contracts_domains` (`id_contract`,`domain`),
  KEY `id_company` (`id_company`),
  KEY `id_server_domain` (`id_server_domain`),
  KEY `domain` (`domain`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_contracts_domains_ibfk_1` FOREIGN KEY (`id_contract`) REFERENCES `crm_contracts` (`id`),
  CONSTRAINT `crm_contracts_domains_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_contracts_domains_ibfk_3` FOREIGN KEY (`id_server_domain`) REFERENCES `crm_servers_domains` (`id`) ON DELETE SET NULL,
  CONSTRAINT `crm_contracts_domains_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_contracts_domains_ibfk_5` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    // LEFT JOIN no domínio do servidor: domínio sem vínculo continua na
    // listagem, com as colunas server_* em NULL.
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

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_contracts_domains_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_contracts_domains`");
  }
}
