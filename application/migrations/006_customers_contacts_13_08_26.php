<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contatos do cliente (visão geral do cliente).
 *
 * Mesmo desenho de crm_customers_files:
 *  - `id_company` copiado do cliente: escopo por tenant sem JOIN;
 *  - SEM `ON DELETE CASCADE` a partir do cliente: a exclusão do cliente é
 *    bloqueada no controller enquanto houver contato (junto com anexos e,
 *    futuramente, contratos).
 *
 * `type` guarda o slug do tipo de contato (padrão do projeto: slugs PT sem
 * acento). O catálogo slug => rótulo vive em Clientes::contactTypes():
 *   financeiro | socio_proprietario | gestor_trafego | juridico | marketing |
 *   diretor | outros
 */
class Migration_Customers_contacts_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_customers_contacts')) {
      $query = "
CREATE TABLE `crm_customers_contacts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_customer` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do cliente para evitar JOIN nas checagens.',
  `type` varchar(30) NOT NULL COMMENT 'Slug do tipo de contato (catálogo em Clientes::contactTypes()).',
  `name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(45) DEFAULT NULL COMMENT 'Telefone/WhatsApp, com máscara.',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_customer` (`id_customer`),
  KEY `id_company` (`id_company`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_customers_contacts_ibfk_1` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`),
  CONSTRAINT `crm_customers_contacts_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_customers_contacts_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    // created_user na view evita o N+1 do nome do usuário na listagem.
    $query = "
CREATE OR REPLACE VIEW `crm_customers_contacts_v` AS
SELECT
  `crm_customers_contacts`.`id` AS `id`,
  `crm_customers_contacts`.`id_customer` AS `id_customer`,
  `crm_customers_contacts`.`id_company` AS `id_company`,
  `crm_customers_contacts`.`type` AS `type`,
  `crm_customers_contacts`.`name` AS `name`,
  `crm_customers_contacts`.`email` AS `email`,
  `crm_customers_contacts`.`phone` AS `phone`,
  `crm_customers_contacts`.`created` AS `created`,
  `crm_customers_contacts`.`created_by` AS `created_by`,
  `crm_users`.`name` AS `created_user`
FROM (`crm_customers_contacts`
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers_contacts`.`created_by`))
";
    $this->db->query($query);
  }

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_customers_contacts_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_customers_contacts`");
  }
}
