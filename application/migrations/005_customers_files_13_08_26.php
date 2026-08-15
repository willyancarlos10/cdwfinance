<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Anexos do cliente (visão geral do cliente).
 *
 * Espelho de `crm_companies_files`, com duas diferenças de propósito:
 *  - `id_company` copiado do cliente (padrão de crm_servers_domains): o escopo
 *    por tenant nas checagens de upload/exclusão sai sem JOIN;
 *  - SEM `ON DELETE CASCADE` a partir do cliente: a exclusão do cliente é
 *    BLOQUEADA no controller enquanto houver anexo (a legenda da tela avisa
 *    "remova antes os contratos, anexos e contatos"). Cascatear apagaria as
 *    linhas mas deixaria os arquivos físicos órfãos em images/customers/.
 *
 * O arquivo físico vai para images/customers/<ano>/<mês>/ via
 * MY_Controller::uploadFileFtp() (teto de UPLOAD_MAX_SIZE_PADRAO validado no
 * servidor); a coluna `file` guarda o caminho relativo.
 */
class Migration_Customers_files_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_customers_files')) {
      $query = "
CREATE TABLE `crm_customers_files` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_customer` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do cliente para evitar JOIN nas checagens.',
  `name` varchar(150) NOT NULL,
  `file` varchar(255) NOT NULL COMMENT 'Caminho relativo (images/customers/ano/mes/arquivo).',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_customer` (`id_customer`),
  KEY `id_company` (`id_company`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_customers_files_ibfk_1` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`),
  CONSTRAINT `crm_customers_files_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_customers_files_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    // created_user na view evita o N+1 de buscar o nome do usuário por anexo
    // na listagem (como acontece na tela antiga de empresas).
    $query = "
CREATE OR REPLACE VIEW `crm_customers_files_v` AS
SELECT
  `crm_customers_files`.`id` AS `id`,
  `crm_customers_files`.`id_customer` AS `id_customer`,
  `crm_customers_files`.`id_company` AS `id_company`,
  `crm_customers_files`.`name` AS `name`,
  `crm_customers_files`.`file` AS `file`,
  `crm_customers_files`.`created` AS `created`,
  `crm_customers_files`.`created_by` AS `created_by`,
  `crm_users`.`name` AS `created_user`
FROM (`crm_customers_files`
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers_files`.`created_by`))
";
    $this->db->query($query);
  }

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_customers_files_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_customers_files`");
  }
}
