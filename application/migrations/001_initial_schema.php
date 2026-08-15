<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Schema inicial do CDW Finance.
 *
 * Gerado a partir do banco já limpo: contém apenas as tabelas e views que
 * sobraram depois da remoção dos módulos do CMS. Qualquer alteração de
 * schema daqui em diante entra em uma migration nova (002, 003, ...), não
 * neste arquivo.
 *
 * ATENÇÃO: não há seed de dados aqui. Um banco criado do zero por esta
 * migration sobe com as tabelas vazias — crm_status, crm_user_permissions e
 * o primeiro usuário precisam ser populados à parte, senão o login não
 * funciona e as views que fazem JOIN em crm_status não retornam linhas.
 */
class Migration_initial_schema extends CI_Migration
{
  public function up()
  {
    // As tabelas são criadas em ordem alfabética e várias têm FK para outras
    // que só aparecem depois (crm_ceps -> crm_users). Desligar a checagem
    // durante a criação evita depender da ordem.
    $this->db->query("SET FOREIGN_KEY_CHECKS = 0");

    //-- Create syntax for TABLE 'crm_api_keys'
    $query = "
CREATE TABLE `crm_api_keys` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_company` int(11) unsigned NOT NULL,
    `name` varchar(100) NOT NULL,
    `key_prefix` varchar(40) NOT NULL,
    `secret_hash` varchar(255) NOT NULL,
    `scopes` text NOT NULL,
    `last_used_at` datetime DEFAULT NULL,
    `revoked_at` datetime DEFAULT NULL,
    `revoked_by` int(11) unsigned DEFAULT NULL,
    `created` datetime NOT NULL,
    `created_by` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `key_prefix` (`key_prefix`),
    KEY `company_revoked` (`id_company`,`revoked_at`),
    KEY `created_by` (`created_by`),
    KEY `revoked_by` (`revoked_by`),
    CONSTRAINT `crm_api_keys_company_fk` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `crm_api_keys_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_api_keys_revoked_by_fk` FOREIGN KEY (`revoked_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_api_rate_limits'
    $query = "
CREATE TABLE `crm_api_rate_limits` (
    `id_api_key` int(11) unsigned NOT NULL,
    `window_started` datetime NOT NULL,
    `request_count` smallint(5) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_api_key`,`window_started`),
    CONSTRAINT `crm_api_rate_limits_key_fk` FOREIGN KEY (`id_api_key`) REFERENCES `crm_api_keys` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_ceps'
    $query = "
CREATE TABLE `crm_ceps` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `cep` varchar(15) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
    `address_uf` char(2) NOT NULL DEFAULT '',
    `address_city` varchar(150) NOT NULL DEFAULT '',
    `id_status` int(11) unsigned NOT NULL,
    `created_by` int(11) unsigned NOT NULL,
    `created` datetime NOT NULL,
    `modified_by` int(11) unsigned NOT NULL DEFAULT 0,
    `modified` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `created_by` (`created_by`),
    KEY `modified_by` (`modified_by`),
    KEY `id_status` (`id_status`),
    CONSTRAINT `crm_ceps_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_ceps_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_ceps_ibfk_4` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_companies'
    $query = "
CREATE TABLE `crm_companies` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_status` int(11) unsigned NOT NULL DEFAULT 0,
    `cnpj` varchar(14) NOT NULL,
    `name` varchar(150) NOT NULL,
    `byname` varchar(150) NOT NULL,
    `address` varchar(200) DEFAULT NULL,
    `address_number` varchar(20) DEFAULT NULL,
    `address_complement` varchar(200) DEFAULT NULL,
    `address_district` varchar(150) DEFAULT NULL,
    `address_zip` varchar(20) DEFAULT NULL,
    `id_state` int(11) unsigned NOT NULL,
    `id_city` int(11) unsigned NOT NULL,
    `phone` varchar(45) DEFAULT NULL,
    `owner` varchar(100) DEFAULT NULL,
    `owner_cellphone` varchar(45) DEFAULT NULL,
    `alias` varchar(150) DEFAULT NULL,
    `email` varchar(150) DEFAULT NULL,
    `token` varchar(45) DEFAULT NULL,
    `last_login` datetime DEFAULT NULL,
    `lead_statuses` longtext DEFAULT NULL,
    `created` datetime DEFAULT NULL,
    `created_by` int(11) unsigned NOT NULL,
    `modified` datetime DEFAULT NULL,
    `modified_by` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    KEY `id_status` (`id_status`),
    KEY `id_state` (`id_state`),
    KEY `id_city` (`id_city`),
    KEY `created_by` (`created_by`),
    CONSTRAINT `crm_companies_ibfk_1` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
    CONSTRAINT `crm_companies_ibfk_3` FOREIGN KEY (`id_state`) REFERENCES `crm_country_states` (`id`),
    CONSTRAINT `crm_companies_ibfk_4` FOREIGN KEY (`id_city`) REFERENCES `crm_country_cities` (`id`),
    CONSTRAINT `crm_companies_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_companies_files'
    $query = "
CREATE TABLE `crm_companies_files` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_company` int(11) unsigned NOT NULL,
    `name` varchar(150) DEFAULT NULL,
    `file` varchar(150) DEFAULT NULL,
    `created` datetime NOT NULL,
    `created_by` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    KEY `id_company` (`id_company`),
    KEY `created_by` (`created_by`),
    CONSTRAINT `crm_companies_files_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
    CONSTRAINT `crm_companies_files_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_companies_logs'
    $query = "
CREATE TABLE `crm_companies_logs` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_company` int(11) unsigned NOT NULL,
    `description` text DEFAULT NULL,
    `created` datetime DEFAULT current_timestamp(),
    `created_by` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    KEY `id_company` (`id_company`),
    KEY `created_by` (`created_by`),
    CONSTRAINT `crm_companies_logs_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
    CONSTRAINT `crm_companies_logs_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_companies_notes'
    $query = "
CREATE TABLE `crm_companies_notes` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_company` int(11) unsigned NOT NULL,
    `description` text DEFAULT NULL,
    `created` datetime DEFAULT current_timestamp(),
    `created_by` int(11) unsigned NOT NULL,
    `modified` datetime DEFAULT current_timestamp(),
    `modified_by` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    KEY `id_company` (`id_company`),
    KEY `created_by` (`created_by`),
    CONSTRAINT `crm_companies_notes_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
    CONSTRAINT `crm_companies_notes_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_companies_relationship'
    $query = "
CREATE TABLE `crm_companies_relationship` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_company` int(11) unsigned NOT NULL,
    `id_related_company` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `company_related_company` (`id_company`,`id_related_company`),
    KEY `id_company` (`id_company`),
    KEY `id_related_company` (`id_related_company`),
    CONSTRAINT `crm_companies_relationship_company_fk` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `crm_companies_relationship_related_company_fk` FOREIGN KEY (`id_related_company`) REFERENCES `crm_companies` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_country_areas'
    $query = "
CREATE TABLE `crm_country_areas` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_country` int(11) DEFAULT 31,
    `id_state` int(10) unsigned NOT NULL,
    `name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
    `ddd` int(10) unsigned DEFAULT NULL,
    `name_ddd` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `zonas_FKIndex1` (`id_state`),
    CONSTRAINT `crm_country_areas_ibfk_1` FOREIGN KEY (`id_state`) REFERENCES `crm_country_states` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_country_cities'
    $query = "
CREATE TABLE `crm_country_cities` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_state` int(10) unsigned NOT NULL,
    `name` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
    `ddd` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
    PRIMARY KEY (`id`),
    KEY `cidades_FKIndex1` (`id_state`),
    CONSTRAINT `crm_country_cities_ibfk_1` FOREIGN KEY (`id_state`) REFERENCES `crm_country_states` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_country_regions'
    $query = "
CREATE TABLE `crm_country_regions` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_country_states'
    $query = "
CREATE TABLE `crm_country_states` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_country` int(10) unsigned NOT NULL,
    `id_region` int(10) unsigned NOT NULL,
    `uf` varchar(2) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
    `name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL DEFAULT '',
    `id_autoline` int(10) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `estados_FKIndex1` (`id_country`),
    KEY `estados_FKIndex2` (`id_region`),
    CONSTRAINT `crm_country_states_ibfk_1` FOREIGN KEY (`id_region`) REFERENCES `crm_country_regions` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_cron'
    $query = "
CREATE TABLE `crm_cron` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `service` varchar(80) NOT NULL,
    `created` datetime NOT NULL,
    `parameters` longtext DEFAULT NULL,
    `error` text DEFAULT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_cron_logs'
    $query = "
CREATE TABLE `crm_cron_logs` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL DEFAULT '',
    `active` char(1) NOT NULL DEFAULT 'S',
    `modified` datetime DEFAULT NULL,
    `parameters` text DEFAULT NULL,
    `errors` text DEFAULT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_general_settings'
    $query = "
CREATE TABLE `crm_general_settings` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `setting_group` varchar(50) NOT NULL,
    `setting_key` varchar(100) NOT NULL,
    `setting_value` text DEFAULT NULL,
    `modified` datetime DEFAULT NULL,
    `modified_by` int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `group_key` (`setting_group`,`setting_key`),
    KEY `modified_by` (`modified_by`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_login_attempts'
    $query = "
CREATE TABLE `crm_login_attempts` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `ip` varchar(45) NOT NULL,
    `email` varchar(255) DEFAULT NULL,
    `created` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ip_created` (`ip`,`created`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_sales_channels'
    $query = "
CREATE TABLE `crm_sales_channels` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL,
    `id_status` int(11) unsigned NOT NULL,
    `created` datetime NOT NULL,
    `created_by` int(11) unsigned NOT NULL,
    `modified` datetime DEFAULT NULL,
    `modified_by` int(11) unsigned DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `id_status` (`id_status`),
    KEY `created_by` (`created_by`),
    KEY `modified_by` (`modified_by`),
    CONSTRAINT `crm_sales_channels_ibfk_1` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
    CONSTRAINT `crm_sales_channels_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_sales_channels_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_sefaz_queries'
    $query = "
CREATE TABLE `crm_sefaz_queries` (
    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `cnpj` varchar(20) DEFAULT '0',
    `name` varchar(150) DEFAULT NULL,
    `byname` varchar(150) DEFAULT NULL,
    `founded` date DEFAULT NULL,
    `capital` decimal(10,2) DEFAULT 0.00,
    `email` varchar(150) DEFAULT NULL,
    `phone` varchar(25) DEFAULT NULL,
    `situation` date DEFAULT NULL,
    `status` varchar(50) DEFAULT NULL,
    `status_date` date DEFAULT NULL,
    `status_reason` varchar(255) DEFAULT NULL,
    `address` varchar(150) DEFAULT NULL,
    `address_number` varchar(50) DEFAULT NULL,
    `address_complement` varchar(150) DEFAULT NULL,
    `address_zip` varchar(25) DEFAULT NULL,
    `address_district` varchar(150) DEFAULT NULL,
    `address_city` varchar(150) DEFAULT NULL,
    `address_uf` char(2) DEFAULT NULL,
    `primary_activity` text DEFAULT NULL,
    `secondary_activity` text DEFAULT NULL,
    `membership` text DEFAULT NULL,
    `id_state` int(11) DEFAULT 0,
    `id_city` int(11) DEFAULT 0,
    `created` datetime DEFAULT current_timestamp(),
    `ie` varchar(30) DEFAULT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_sessions'
    $query = "
CREATE TABLE `crm_sessions` (
    `id` varchar(40) NOT NULL,
    `ip_address` varchar(45) NOT NULL DEFAULT '',
    `data` mediumblob NOT NULL,
    `timestamp` int(10) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `crm_sessions_timestamp` (`timestamp`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_status'
    $query = "
CREATE TABLE `crm_status` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(50) DEFAULT NULL,
    `order` int(3) DEFAULT 0,
    `color` varchar(25) DEFAULT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_users'
    $query = "
CREATE TABLE `crm_users` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_company` int(11) unsigned NOT NULL,
    `id_permission` int(11) unsigned NOT NULL,
    `id_group` int(11) unsigned DEFAULT NULL,
    `id_status` int(11) unsigned NOT NULL DEFAULT 1,
    `name` varchar(200) DEFAULT NULL,
    `email` varchar(150) DEFAULT NULL,
    `password` varchar(70) DEFAULT NULL,
    `cellphone` varchar(50) DEFAULT NULL,
    `image` varchar(150) DEFAULT NULL,
    `last_login` datetime DEFAULT NULL,
    `ip_login` varchar(20) DEFAULT NULL,
    `forgot_token` varchar(100) DEFAULT NULL,
    `created` datetime NOT NULL DEFAULT current_timestamp(),
    `created_by` int(11) unsigned NOT NULL,
    `modified` datetime DEFAULT NULL,
    `modified_by` int(11) unsigned DEFAULT NULL,
    `preferences` text DEFAULT NULL,
    `view_all_quotes` tinyint(1) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `id_company` (`id_company`),
    KEY `id_permission` (`id_permission`),
    KEY `id_group` (`id_group`),
    KEY `id_status` (`id_status`),
    KEY `created_by` (`created_by`),
    KEY `modified_by` (`modified_by`),
    CONSTRAINT `crm_users_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
    CONSTRAINT `crm_users_ibfk_2` FOREIGN KEY (`id_permission`) REFERENCES `crm_user_permissions` (`id`),
    CONSTRAINT `crm_users_ibfk_4` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
    CONSTRAINT `crm_users_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_users_ibfk_6` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_users_logins'
    $query = "
CREATE TABLE `crm_users_logins` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_user` int(11) DEFAULT NULL,
    `user_agent` varchar(250) DEFAULT NULL,
    `server_addr` varchar(20) DEFAULT NULL,
    `remote_host` varchar(100) DEFAULT NULL,
    `referer` varchar(100) DEFAULT NULL,
    `created` datetime NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_user_groups'
    $query = "
CREATE TABLE `crm_user_groups` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL DEFAULT '',
    `companies` text NOT NULL,
    `id_status` int(11) unsigned NOT NULL DEFAULT 1,
    `created` datetime NOT NULL DEFAULT current_timestamp(),
    `created_by` int(11) unsigned NOT NULL DEFAULT 1,
    `modified` datetime NOT NULL DEFAULT current_timestamp(),
    `modified_by` int(11) unsigned DEFAULT 1,
    `phone` varchar(20) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `id_status` (`id_status`),
    KEY `created_by` (`created_by`),
    KEY `modified_by` (`modified_by`),
    CONSTRAINT `crm_user_groups_ibfk_1` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
    CONSTRAINT `crm_user_groups_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_user_groups_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_user_permissions'
    $query = "
CREATE TABLE `crm_user_permissions` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_company` int(11) unsigned NOT NULL DEFAULT 0,
    `name` varchar(50) NOT NULL DEFAULT '',
    `permissions` text NOT NULL,
    `id_status` int(11) unsigned NOT NULL DEFAULT 1,
    `created` datetime NOT NULL DEFAULT current_timestamp(),
    `created_by` int(11) unsigned NOT NULL DEFAULT 1,
    `modified` datetime DEFAULT current_timestamp(),
    `modified_by` int(11) unsigned DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `id_company` (`id_company`),
    KEY `id_status` (`id_status`),
    KEY `created_by` (`created_by`),
    KEY `modified_by` (`modified_by`),
    CONSTRAINT `crm_user_permissions_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
    CONSTRAINT `crm_user_permissions_ibfk_2` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
    CONSTRAINT `crm_user_permissions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_user_permissions_ibfk_4` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_user_permissions_items'
    $query = "
CREATE TABLE `crm_user_permissions_items` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `allow_company` tinyint(1) NOT NULL DEFAULT 1,
    `id_status` int(11) unsigned NOT NULL,
    `created` datetime NOT NULL DEFAULT current_timestamp(),
    `created_by` int(11) unsigned NOT NULL,
    `modified` datetime DEFAULT current_timestamp(),
    `modified_by` int(11) unsigned DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `id_status` (`id_status`),
    KEY `created_by` (`created_by`),
    KEY `modified_by` (`modified_by`),
    CONSTRAINT `crm_user_permissions_items_ibfk_1` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
    CONSTRAINT `crm_user_permissions_items_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
    CONSTRAINT `crm_user_permissions_items_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_user_permissions_logs'
    $query = "
CREATE TABLE `crm_user_permissions_logs` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `id_user_permission` int(11) unsigned NOT NULL,
    `description` longtext NOT NULL DEFAULT '1',
    `created` datetime NOT NULL,
    `created_by` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'crm_viacep'
    $query = "
CREATE TABLE `crm_viacep` (
    `id` int(9) NOT NULL AUTO_INCREMENT,
    `cep` varchar(9) NOT NULL,
    `logradouro` varchar(250) NOT NULL,
    `complemento` varchar(50) NOT NULL,
    `bairro` varchar(150) NOT NULL,
    `localidade` varchar(100) NOT NULL,
    `uf` varchar(2) NOT NULL,
    `ibge` varchar(10) NOT NULL,
    `gia` varchar(10) NOT NULL,
    `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
    `ddd` int(11) DEFAULT NULL,
    `siafi` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `cep` (`cep`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for TABLE 'migrations'
    $query = "
CREATE TABLE `migrations` (
    `version` bigint(20) NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    //-- Create syntax for VIEW 'crm_companies_logs_v'
    $query = "
CREATE OR REPLACE VIEW `crm_companies_logs_v` AS select `crm_companies_logs`.`id` AS `id`,`crm_companies_logs`.`id_company` AS `id_company`,`crm_companies_logs`.`description` AS `description`,`crm_companies_logs`.`created` AS `created`,`crm_companies_logs`.`created_by` AS `created_by`,`crm_users`.`name` AS `created_user`,`crm_companies`.`byname` AS `created_company_byname` from ((`crm_companies_logs` join `crm_users` on(`crm_users`.`id` = `crm_companies_logs`.`created_by`)) join `crm_companies` on(`crm_companies`.`id` = `crm_users`.`id_company`))
";
    $this->db->query($query);

    //-- Create syntax for VIEW 'crm_companies_notes_v'
    $query = "
CREATE OR REPLACE VIEW `crm_companies_notes_v` AS select `crm_companies_notes`.`id` AS `id`,`crm_companies_notes`.`id_company` AS `id_company`,`crm_companies_notes`.`description` AS `description`,`crm_companies_notes`.`created` AS `created`,`crm_companies_notes`.`created_by` AS `created_by`,`crm_companies_notes`.`modified` AS `modified`,`crm_companies_notes`.`modified_by` AS `modified_by`,`crm_users`.`name` AS `created_user`,`crm_companies`.`byname` AS `created_company_byname` from ((`crm_companies_notes` join `crm_users` on(`crm_users`.`id` = `crm_companies_notes`.`created_by`)) join `crm_companies` on(`crm_companies`.`id` = `crm_users`.`id_company`))
";
    $this->db->query($query);

    //-- Create syntax for VIEW 'crm_companies_v'
    $query = "
CREATE OR REPLACE VIEW `crm_companies_v` AS select `crm_companies`.`id` AS `id`,`crm_companies`.`id_status` AS `id_status`,`crm_companies`.`cnpj` AS `cnpj`,`crm_companies`.`name` AS `name`,`crm_companies`.`byname` AS `byname`,`crm_companies`.`address` AS `address`,`crm_companies`.`address_number` AS `address_number`,`crm_companies`.`address_complement` AS `address_complement`,`crm_companies`.`address_district` AS `address_district`,`crm_companies`.`address_zip` AS `address_zip`,`crm_companies`.`id_state` AS `id_state`,`crm_companies`.`id_city` AS `id_city`,`crm_companies`.`phone` AS `phone`,`crm_companies`.`owner` AS `owner`,`crm_companies`.`owner_cellphone` AS `owner_cellphone`,`crm_companies`.`alias` AS `alias`,`crm_companies`.`email` AS `email`,`crm_companies`.`token` AS `token`,`crm_companies`.`last_login` AS `last_login`,`crm_companies`.`created` AS `created`,`crm_companies`.`created_by` AS `created_by`,`crm_companies`.`modified` AS `modified`,`crm_companies`.`modified_by` AS `modified_by`,`crm_country_cities`.`name` AS `city_name`,`crm_country_states`.`name` AS `state_name`,`crm_country_states`.`uf` AS `state_uf`,`crm_status`.`name` AS `status_name`,`crm_status`.`color` AS `status_color` from (((`crm_companies` join `crm_status` on(`crm_status`.`id` = `crm_companies`.`id_status`)) join `crm_country_cities` on(`crm_country_cities`.`id` = `crm_companies`.`id_city`)) join `crm_country_states` on(`crm_country_states`.`id` = `crm_companies`.`id_state`))
";
    $this->db->query($query);

    //-- Create syntax for VIEW 'crm_country_cities_v'
    $query = "
CREATE OR REPLACE VIEW `crm_country_cities_v` AS select `crm_country_cities`.`id` AS `id`,`crm_country_cities`.`id_state` AS `id_state`,`crm_country_cities`.`name` AS `name`,`crm_country_cities`.`ddd` AS `ddd`,`a`.`uf` AS `UF`,`a`.`name` AS `state` from (`crm_country_cities` join `crm_country_states` `a` on(`a`.`id` = `crm_country_cities`.`id_state`))
";
    $this->db->query($query);

    //-- Create syntax for VIEW 'crm_users_v'
    $query = "
CREATE OR REPLACE VIEW `crm_users_v` AS select `crm_users`.`id` AS `id`,`crm_users`.`id_company` AS `id_company`,`crm_users`.`id_permission` AS `id_permission`,`crm_users`.`id_group` AS `id_group`,`crm_users`.`id_status` AS `id_status`,`crm_users`.`name` AS `name`,`crm_users`.`email` AS `email`,`crm_users`.`password` AS `password`,`crm_users`.`cellphone` AS `cellphone`,`crm_users`.`image` AS `image`,`crm_users`.`last_login` AS `last_login`,`crm_users`.`ip_login` AS `ip_login`,`crm_users`.`forgot_token` AS `forgot_token`,`crm_users`.`created` AS `created`,`crm_users`.`created_by` AS `created_by`,`crm_users`.`modified` AS `modified`,`crm_users`.`modified_by` AS `modified_by`,`crm_users`.`preferences` AS `preferences`,`crm_companies`.`byname` AS `company_byname`,`crm_companies`.`byname` AS `company_name`,`crm_status`.`name` AS `status_name`,`crm_status`.`color` AS `status_color`,`crm_user_permissions`.`name` AS `permission_name` from (((`crm_users` join `crm_companies` on(`crm_companies`.`id` = `crm_users`.`id_company`)) join `crm_status` on(`crm_status`.`id` = `crm_users`.`id_status`)) join `crm_user_permissions` on(`crm_user_permissions`.`id` = `crm_users`.`id_permission`))
";
    $this->db->query($query);

    //-- Create syntax for VIEW 'crm_user_groups_v'
    $query = "
CREATE OR REPLACE VIEW `crm_user_groups_v` AS select `crm_user_groups`.`id` AS `id`,`crm_user_groups`.`name` AS `name`,`crm_user_groups`.`companies` AS `companies`,`crm_user_groups`.`id_status` AS `id_status`,`crm_user_groups`.`created` AS `created`,`crm_user_groups`.`created_by` AS `created_by`,`crm_user_groups`.`modified` AS `modified`,`crm_user_groups`.`modified_by` AS `modified_by`,`crm_status`.`name` AS `status_name`,`crm_status`.`color` AS `status_color`,`crm_users`.`name` AS `modified_user` from ((`crm_user_groups` join `crm_status` on(`crm_status`.`id` = `crm_user_groups`.`id_status`)) left join `crm_users` on(`crm_users`.`id` = `crm_user_groups`.`modified_by`))
";
    $this->db->query($query);

    //-- Create syntax for VIEW 'crm_user_permissions_v'
    $query = "
CREATE OR REPLACE VIEW `crm_user_permissions_v` AS select `crm_user_permissions`.`id` AS `id`,`crm_user_permissions`.`id_company` AS `id_company`,`crm_user_permissions`.`name` AS `name`,`crm_user_permissions`.`permissions` AS `permissions`,`crm_user_permissions`.`id_status` AS `id_status`,`crm_user_permissions`.`created` AS `created`,`crm_user_permissions`.`created_by` AS `created_by`,`crm_user_permissions`.`modified` AS `modified`,`crm_user_permissions`.`modified_by` AS `modified_by`,`crm_users`.`name` AS `created_user`,`b`.`name` AS `modified_user`,`crm_status`.`name` AS `status`,`crm_status`.`color` AS `status_color` from (((`crm_user_permissions` join `crm_users` on(`crm_users`.`id` = `crm_user_permissions`.`created_by`)) join `crm_users` `b` on(`b`.`id` = `crm_user_permissions`.`modified_by`)) join `crm_status` on(`crm_status`.`id` = `crm_user_permissions`.`id_status`))
";
    $this->db->query($query);

    $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
  }

  public function down()
  {
    $this->db->query("SET FOREIGN_KEY_CHECKS = 0");

    $this->db->query("DROP VIEW IF EXISTS `crm_companies_logs_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_companies_notes_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_companies_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_country_cities_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_users_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_user_groups_v`");
    $this->db->query("DROP VIEW IF EXISTS `crm_user_permissions_v`");

    $this->db->query("DROP TABLE IF EXISTS `crm_api_keys`");
    $this->db->query("DROP TABLE IF EXISTS `crm_api_rate_limits`");
    $this->db->query("DROP TABLE IF EXISTS `crm_ceps`");
    $this->db->query("DROP TABLE IF EXISTS `crm_companies`");
    $this->db->query("DROP TABLE IF EXISTS `crm_companies_files`");
    $this->db->query("DROP TABLE IF EXISTS `crm_companies_logs`");
    $this->db->query("DROP TABLE IF EXISTS `crm_companies_notes`");
    $this->db->query("DROP TABLE IF EXISTS `crm_companies_relationship`");
    $this->db->query("DROP TABLE IF EXISTS `crm_country_areas`");
    $this->db->query("DROP TABLE IF EXISTS `crm_country_cities`");
    $this->db->query("DROP TABLE IF EXISTS `crm_country_regions`");
    $this->db->query("DROP TABLE IF EXISTS `crm_country_states`");
    $this->db->query("DROP TABLE IF EXISTS `crm_cron`");
    $this->db->query("DROP TABLE IF EXISTS `crm_cron_logs`");
    $this->db->query("DROP TABLE IF EXISTS `crm_general_settings`");
    $this->db->query("DROP TABLE IF EXISTS `crm_login_attempts`");
    $this->db->query("DROP TABLE IF EXISTS `crm_sales_channels`");
    $this->db->query("DROP TABLE IF EXISTS `crm_sefaz_queries`");
    $this->db->query("DROP TABLE IF EXISTS `crm_sessions`");
    $this->db->query("DROP TABLE IF EXISTS `crm_status`");
    $this->db->query("DROP TABLE IF EXISTS `crm_users`");
    $this->db->query("DROP TABLE IF EXISTS `crm_users_logins`");
    $this->db->query("DROP TABLE IF EXISTS `crm_user_groups`");
    $this->db->query("DROP TABLE IF EXISTS `crm_user_permissions`");
    $this->db->query("DROP TABLE IF EXISTS `crm_user_permissions_items`");
    $this->db->query("DROP TABLE IF EXISTS `crm_user_permissions_logs`");
    $this->db->query("DROP TABLE IF EXISTS `crm_viacep`");
    $this->db->query("DROP TABLE IF EXISTS `migrations`");

    $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
  }
}
