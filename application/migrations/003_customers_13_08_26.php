<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Clientes finais do financeiro.
 *
 * `crm_companies` é o TENANT — a empresa que usa o sistema (CDW Tech e afins),
 * dona de servidores, contratos e clientes. `crm_customers` são os clientes de
 * cada tenant, o "cliente do cliente". Eles NÃO acessam o sistema: o cadastro
 * público (/cadastro-cliente) só cria o registro, sem usuário/senha.
 *
 * Sobre `crm_customers`:
 *  - campos físicos = o que identifica e localiza o cliente (tipo F/J,
 *    documento, nomes, e-mail do contrato e endereço comercial). Todo o resto
 *    do formulário vive em `attributes` (JSON em longtext, como
 *    crm_user_permissions.permissions), no formato:
 *      {
 *        "representative": { name, nationality (brasileira|outra),
 *          marital_status (solteiro|casado|separado|divorciado|viuvo),
 *          profession, rg, cpf (só dígitos), whatsapp,
 *          address: { street, number, complement, district, zip,
 *            id_state, id_city, city, uf — city/uf são DERIVADOS do id_city
 *            no servidor, nunca aceitos do POST } },
 *        "billing": { name, email, whatsapp, needs_invoice (S|N) },
 *        "domains": { primary, secondary },
 *        "contract": { comments },
 *        "consent": { lgpd_accepted, accepted_at, ip, user_agent },
 *        "source": { channel (wizard_publico|painel), cnpj_autofill }
 *      }
 *    Forma de pagamento, parcelas e data de início do contrato NÃO fazem
 *    parte do cadastro — pertencem ao módulo de Contratos (futuro).
 *    `consent` e `source` são gravados só pelo sistema — a tela de edição do
 *    painel nunca os sobrescreve (whitelist no controller Clientes).
 *  - a UNIQUE (id_company, document) deduplica por tenant: o mesmo cliente
 *    pode existir em dois tenants, nunca duas vezes no mesmo. `document`
 *    guarda só dígitos (CNPJ 14 / CPF 11).
 *  - `id_state`/`id_city` são NULLable e a view usa LEFT JOIN de propósito:
 *    o wizard público não pode ser bloqueado — nem o cliente sumir da
 *    listagem, como acontece na crm_companies_v (INNER JOIN) — quando a
 *    cidade informada não for resolvida em crm_country_cities.
 *  - cadastro vindo do wizard entra com id_status = 4 (em análise) e é
 *    ativado em clientes/info; pelo painel entra direto com id_status = 1.
 */
class Migration_Customers_13_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_customers')) {
      $query = "
CREATE TABLE `crm_customers` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Tenant dono do cliente (crm_companies).',
  `id_status` int(11) unsigned NOT NULL DEFAULT 1 COMMENT '1 ativo | 2 inativo | 3 suspenso | 4 em análise (wizard entra com 4).',
  `type` char(1) NOT NULL DEFAULT 'J' COMMENT 'J = pessoa jurídica | F = pessoa física',
  `document` varchar(14) NOT NULL COMMENT 'CNPJ (14) ou CPF (11), só dígitos.',
  `name` varchar(150) NOT NULL COMMENT 'Razão social (PJ) ou nome completo (PF).',
  `byname` varchar(150) DEFAULT NULL COMMENT 'Nome fantasia.',
  `email` varchar(150) DEFAULT NULL COMMENT 'E-mail para envio/assinatura do contrato digital.',
  `address` varchar(200) DEFAULT NULL,
  `address_number` varchar(20) DEFAULT NULL,
  `address_complement` varchar(200) DEFAULT NULL,
  `address_district` varchar(150) DEFAULT NULL,
  `address_zip` varchar(20) DEFAULT NULL COMMENT 'Formato 00.000-000, como em crm_companies.',
  `id_state` int(11) unsigned DEFAULT NULL COMMENT 'NULL quando a cidade não foi resolvida — a view usa LEFT JOIN.',
  `id_city` int(11) unsigned DEFAULT NULL,
  `attributes` longtext DEFAULT NULL COMMENT 'JSON: representante, financeiro, domínios, contrato, consentimento (schema no docblock da migration 003).',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customers_company_document` (`id_company`,`document`),
  KEY `id_company` (`id_company`),
  KEY `id_status` (`id_status`),
  KEY `type` (`type`),
  KEY `name` (`name`),
  KEY `email` (`email`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_customers_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_customers_ibfk_2` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
  CONSTRAINT `crm_customers_ibfk_3` FOREIGN KEY (`id_state`) REFERENCES `crm_country_states` (`id`),
  CONSTRAINT `crm_customers_ibfk_4` FOREIGN KEY (`id_city`) REFERENCES `crm_country_cities` (`id`),
  CONSTRAINT `crm_customers_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_customers_ibfk_6` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    // LEFT JOIN em cidade/estado/usuário de propósito (ver docblock): cliente
    // com cidade não resolvida continua aparecendo nas telas, com city_name
    // NULL. Status e tenant são NOT NULL com FK, então o JOIN é seguro.
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

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_customers_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_customers`");
  }
}
