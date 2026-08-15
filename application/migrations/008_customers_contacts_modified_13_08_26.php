<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Auditoria de edição nos contatos do cliente.
 *
 * A 006 criou crm_customers_contacts sem modified/modified_by porque o
 * contato só era incluído/excluído. Com a edição pelo modal
 * (Clientes::post_salvarcontato), as colunas entram para manter a trilha:
 * "Incluído por" continua sendo o criador; a view passa a expor também
 * modified/modified_user para a tela indicar quando houve edição.
 */
class Migration_Customers_contacts_modified_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->field_exists('modified', 'crm_customers_contacts')) {
      $this->db->query("
ALTER TABLE `crm_customers_contacts`
  ADD COLUMN `modified` datetime DEFAULT NULL AFTER `created_by`,
  ADD COLUMN `modified_by` int(11) unsigned DEFAULT NULL AFTER `modified`,
  ADD KEY `modified_by` (`modified_by`),
  ADD CONSTRAINT `crm_customers_contacts_ibfk_4` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
");
    }

    // Tabela com view _v: coluna nova = recriar a view na mesma migration,
    // senão a tela não reflete o valor salvo.
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
  `crm_customers_contacts`.`modified` AS `modified`,
  `crm_customers_contacts`.`modified_by` AS `modified_by`,
  `crm_users`.`name` AS `created_user`,
  `modified_user_join`.`name` AS `modified_user`
FROM ((`crm_customers_contacts`
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers_contacts`.`created_by`))
  LEFT JOIN `crm_users` `modified_user_join` ON(`modified_user_join`.`id` = `crm_customers_contacts`.`modified_by`))
";
    $this->db->query($query);
  }

  public function down()
  {
    if ($this->db->field_exists('modified', 'crm_customers_contacts')) {
      $this->db->query("ALTER TABLE `crm_customers_contacts` DROP FOREIGN KEY `crm_customers_contacts_ibfk_4`");
      $this->db->query("ALTER TABLE `crm_customers_contacts` DROP COLUMN `modified_by`, DROP COLUMN `modified`");
    }

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
}
