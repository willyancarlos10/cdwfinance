<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tipos de serviço (catálogo de GESTÃO).
 *
 * Catálogo GLOBAL: não tem `id_company` de propósito. Os tipos serão
 * relacionados aos contratos de QUALQUER tenant quando o módulo de Contratos
 * existir, então o cadastro é único e administrado só pela empresa master
 * (padrão da seção GESTÃO do menu).
 *
 * Só nome + status. O vínculo com contrato entra em migration futura, no
 * módulo de Contratos (FK de `crm_contracts` para cá) — quando existir, a
 * exclusão de tipo vinculado passa a ser bloqueada no controller.
 */
class Migration_Service_types_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_service_types')) {
      $query = "
CREATE TABLE `crm_service_types` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_status` int(11) unsigned NOT NULL DEFAULT 1,
  `name` varchar(150) NOT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_service_types_name` (`name`),
  KEY `id_status` (`id_status`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_service_types_ibfk_1` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
  CONSTRAINT `crm_service_types_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_service_types_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    $query = "
CREATE OR REPLACE VIEW `crm_service_types_v` AS
SELECT
  `crm_service_types`.`id` AS `id`,
  `crm_service_types`.`id_status` AS `id_status`,
  `crm_service_types`.`name` AS `name`,
  `crm_service_types`.`created` AS `created`,
  `crm_service_types`.`created_by` AS `created_by`,
  `crm_service_types`.`modified` AS `modified`,
  `crm_service_types`.`modified_by` AS `modified_by`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`,
  `crm_users`.`name` AS `modified_user`
FROM ((`crm_service_types`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_service_types`.`id_status`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_service_types`.`modified_by`))
";
    $this->db->query($query);
  }

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_service_types_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_service_types`");
  }
}
