<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Servidores de hospedagem e os domínios que cada um hospeda.
 *
 * `crm_servers` guarda a conexão com o painel (WHM, DirectAdmin ou CloudPanel);
 * `crm_servers_domains` guarda o retrato dos domínios trazido pela última
 * sincronização.
 *
 * Sobre `crm_servers_domains`:
 *  - a UNIQUE (id_server, domain) é a chave do upsert da sincronização — o mesmo
 *    domínio pode existir em dois servidores diferentes, mas nunca duas vezes no
 *    mesmo;
 *  - `status` é o estado do domínio NO PAINEL (ativo/suspenso), reportado pelo
 *    servidor. Não existe `id_status` aqui de propósito: domínio não é
 *    ativado/desativado dentro do CDW, ele reflete o que veio de fora;
 *  - não há vínculo com cliente ainda. Quando a entidade de cliente do
 *    financeiro existir, entra numa migration nova.
 */
class Migration_Servers_domains_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_servers')) {
      $query = "
CREATE TABLE `crm_servers` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(11) unsigned NOT NULL,
  `id_status` int(11) unsigned NOT NULL DEFAULT 1,
  `name` varchar(150) NOT NULL,
  `type` varchar(20) NOT NULL COMMENT 'whm | directadmin | cloudpanel',
  `host` varchar(255) NOT NULL,
  `port` smallint(5) unsigned DEFAULT NULL COMMENT 'Porta SSH do CloudPanel. WHM usa 2087 e DirectAdmin 2222.',
  `username` varchar(150) NOT NULL,
  `secret` text DEFAULT NULL COMMENT 'Credencial cifrada (Secret_crypto): token WHM, login key DA, senha ou chave SSH.',
  `auth_type` varchar(20) DEFAULT NULL COMMENT 'password | key — somente CloudPanel',
  `verify_ssl` tinyint(1) NOT NULL DEFAULT 1,
  `timeout_seconds` int(11) unsigned NOT NULL DEFAULT 30,
  `last_test` datetime DEFAULT NULL,
  `last_test_status` varchar(20) DEFAULT NULL COMMENT 'conectado | erro',
  `last_test_message` varchar(500) DEFAULT NULL,
  `last_test_ms` int(11) unsigned DEFAULT NULL,
  `last_sync` datetime DEFAULT NULL,
  `last_sync_status` varchar(20) DEFAULT NULL COMMENT 'sucesso | parcial | erro',
  `last_sync_message` varchar(500) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_servers_company_name` (`id_company`,`name`),
  KEY `id_company` (`id_company`),
  KEY `id_status` (`id_status`),
  KEY `type` (`type`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_servers_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_servers_ibfk_2` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
  CONSTRAINT `crm_servers_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_servers_ibfk_4` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    if (!$this->db->table_exists('crm_servers_domains')) {
      $query = "
CREATE TABLE `crm_servers_domains` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_server` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do servidor para evitar JOIN nas listagens.',
  `domain` varchar(255) NOT NULL,
  `owner_username` varchar(150) DEFAULT NULL,
  `plan` varchar(150) DEFAULT NULL,
  `disk_used_mb` decimal(12,2) DEFAULT NULL,
  `disk_limit_mb` decimal(12,2) DEFAULT NULL COMMENT 'NULL = ilimitado',
  `ip` varchar(45) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ativo' COMMENT 'ativo | suspenso — estado no painel',
  `source` varchar(20) NOT NULL COMMENT 'whm | directadmin | cloudpanel | manual',
  `contact_email` varchar(255) DEFAULT NULL,
  `suspension_reason` varchar(500) DEFAULT NULL,
  `last_sync` datetime DEFAULT NULL,
  `sync_status` varchar(20) DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_servers_domains_server_domain` (`id_server`,`domain`),
  KEY `id_company` (`id_company`),
  KEY `domain` (`domain`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_servers_domains_ibfk_1` FOREIGN KEY (`id_server`) REFERENCES `crm_servers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `crm_servers_domains_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_servers_domains_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    // A view NÃO expõe `secret`: as telas leem daqui, então a credencial cifrada
    // nunca chega perto de uma listagem ou de um formulário.
    $query = "
CREATE OR REPLACE VIEW `crm_servers_v` AS
SELECT
  `crm_servers`.`id` AS `id`,
  `crm_servers`.`id_company` AS `id_company`,
  `crm_servers`.`id_status` AS `id_status`,
  `crm_servers`.`name` AS `name`,
  `crm_servers`.`type` AS `type`,
  `crm_servers`.`host` AS `host`,
  `crm_servers`.`port` AS `port`,
  `crm_servers`.`username` AS `username`,
  `crm_servers`.`auth_type` AS `auth_type`,
  `crm_servers`.`verify_ssl` AS `verify_ssl`,
  `crm_servers`.`timeout_seconds` AS `timeout_seconds`,
  `crm_servers`.`last_test` AS `last_test`,
  `crm_servers`.`last_test_status` AS `last_test_status`,
  `crm_servers`.`last_test_message` AS `last_test_message`,
  `crm_servers`.`last_test_ms` AS `last_test_ms`,
  `crm_servers`.`last_sync` AS `last_sync`,
  `crm_servers`.`last_sync_status` AS `last_sync_status`,
  `crm_servers`.`last_sync_message` AS `last_sync_message`,
  `crm_servers`.`created` AS `created`,
  `crm_servers`.`created_by` AS `created_by`,
  `crm_servers`.`modified` AS `modified`,
  `crm_servers`.`modified_by` AS `modified_by`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`,
  `crm_companies`.`byname` AS `company_byname`,
  `crm_users`.`name` AS `created_user`,
  (SELECT COUNT(*) FROM `crm_servers_domains` WHERE `crm_servers_domains`.`id_server` = `crm_servers`.`id`) AS `domains_count`
FROM ((`crm_servers`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_servers`.`id_status`))
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_servers`.`id_company`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_servers`.`created_by`)
";
    $this->db->query($query);

    $query = "
CREATE OR REPLACE VIEW `crm_servers_domains_v` AS
SELECT
  `crm_servers_domains`.`id` AS `id`,
  `crm_servers_domains`.`id_server` AS `id_server`,
  `crm_servers_domains`.`id_company` AS `id_company`,
  `crm_servers_domains`.`domain` AS `domain`,
  `crm_servers_domains`.`owner_username` AS `owner_username`,
  `crm_servers_domains`.`plan` AS `plan`,
  `crm_servers_domains`.`disk_used_mb` AS `disk_used_mb`,
  `crm_servers_domains`.`disk_limit_mb` AS `disk_limit_mb`,
  `crm_servers_domains`.`ip` AS `ip`,
  `crm_servers_domains`.`status` AS `status`,
  `crm_servers_domains`.`source` AS `source`,
  `crm_servers_domains`.`contact_email` AS `contact_email`,
  `crm_servers_domains`.`suspension_reason` AS `suspension_reason`,
  `crm_servers_domains`.`last_sync` AS `last_sync`,
  `crm_servers_domains`.`sync_status` AS `sync_status`,
  `crm_servers_domains`.`created` AS `created`,
  `crm_servers_domains`.`created_by` AS `created_by`,
  `crm_servers_domains`.`modified` AS `modified`,
  `crm_servers_domains`.`modified_by` AS `modified_by`,
  `crm_servers`.`name` AS `server_name`,
  `crm_servers`.`type` AS `server_type`,
  `crm_servers`.`host` AS `server_host`,
  `crm_companies`.`byname` AS `company_byname`
FROM ((`crm_servers_domains`
  JOIN `crm_servers` ON(`crm_servers`.`id` = `crm_servers_domains`.`id_server`))
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_servers_domains`.`id_company`))
";
    $this->db->query($query);

    // Registra a rotina no painel de CRON (Painel::cron lê de crm_cron_logs).
    $existente = $this->db->get_where('crm_cron_logs', ['name' => 'cron_sync_servers'])->row();
    if (empty($existente)) {
      $this->db->insert('crm_cron_logs', [
        'name' => 'cron_sync_servers',
        'active' => 'S',
      ]);
    }

    // Sobras da remoção do CMS: as rotinas não existem mais em Cron.php, mas as
    // linhas continuavam aparecendo no painel de CRON.
    $this->db->where_in('name', ['cron_warm_analytics', 'cron_publish_scheduled_articles'])
      ->delete('crm_cron_logs');
  }

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_servers_domains_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_servers_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_servers_domains`");
    $this->db->query("DROP TABLE IF EXISTS `crm_servers`");

    $this->db->where('name', 'cron_sync_servers')->delete('crm_cron_logs');
  }
}
