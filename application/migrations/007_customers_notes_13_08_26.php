<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Atividades do cliente (aba "Atividades" da visão geral) — observações
 * internas em texto livre, exibidas como timeline (mais recente primeiro).
 *
 * Espelho de crm_companies_notes, com `id_company` copiado do cliente para o
 * escopo por tenant sem JOIN.
 *
 * AQUI a FK do cliente CASCATEIA — diferente de anexos/contatos, de
 * propósito: atividade é observação interna, não vínculo que deva bloquear a
 * exclusão do cadastro (a legenda da tela fala em "contratos, anexos e
 * contatos"), e não há arquivo físico para ficar órfão.
 */
class Migration_Customers_notes_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_customers_notes')) {
      $query = "
CREATE TABLE `crm_customers_notes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_customer` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do cliente para evitar JOIN nas checagens.',
  `description` text NOT NULL COMMENT 'Observações em texto livre (escapadas na exibição).',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_customer` (`id_customer`),
  KEY `id_company` (`id_company`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_customers_notes_ibfk_1` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `crm_customers_notes_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_customers_notes_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    // created_user na view evita o N+1 do nome do usuário na timeline.
    $query = "
CREATE OR REPLACE VIEW `crm_customers_notes_v` AS
SELECT
  `crm_customers_notes`.`id` AS `id`,
  `crm_customers_notes`.`id_customer` AS `id_customer`,
  `crm_customers_notes`.`id_company` AS `id_company`,
  `crm_customers_notes`.`description` AS `description`,
  `crm_customers_notes`.`created` AS `created`,
  `crm_customers_notes`.`created_by` AS `created_by`,
  `crm_users`.`name` AS `created_user`
FROM (`crm_customers_notes`
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers_notes`.`created_by`))
";
    $this->db->query($query);
  }

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_customers_notes_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_customers_notes`");
  }
}
