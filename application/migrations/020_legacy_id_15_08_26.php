<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * `legacy_id` — a chave de origem da importação do gestor-interno.
 *
 * A base do sistema anterior (gestor-interno, PostgreSQL) entra no CDW Finance
 * por um comando CLI que lê o dump. O problema é reexecutar essa importação:
 * nos ensaios ela roda várias vezes, e depois de corrigir qualquer coisa ela
 * roda de novo.
 *
 * Cliente até tem chave natural (a UNIQUE `id_company` + `document`), mas
 * CONTRATO NÃO TEM NENHUMA — nem número, nem data, nem par de campos que o
 * identifique. Sem guardar o id de origem, a segunda execução não teria como
 * saber que aquele contrato já entrou e criaria 254 duplicatas. O mesmo vale
 * para contato, domínio e documento de contrato.
 *
 * Daí `legacy_id`: o id que a linha tinha no gestor-interno. Com ele a
 * importação vira upsert de verdade (localiza por `id_company` + `legacy_id`,
 * atualiza se achar, insere se não), e a conferência final consegue casar
 * origem × destino linha a linha em vez de comparar só totais.
 *
 * A UNIQUE é (`id_company`, `legacy_id`) e não `legacy_id` sozinho porque o id
 * de origem só é único dentro da base de origem: um segundo tenant que venha a
 * importar a base dele teria os mesmos ids 1, 2, 3. É o mesmo desenho da
 * UNIQUE de `crm_customers` (`id_company`, `document`).
 *
 * NULL é permitido e é o estado normal de todo cadastro feito pelas telas —
 * `legacy_id` só é preenchido pela importação. Em MySQL, valores NULL não
 * colidem numa UNIQUE, então quantas linhas manuais existirem não há problema.
 *
 * Cinco tabelas, só as que de fato recebem dados:
 *  - `crm_customers`, `crm_contracts`, `crm_customers_contacts`,
 *    `crm_contracts_files`, `crm_contracts_domains`
 *
 * Fora daqui de propósito:
 *  - `crm_customers_files` e `crm_customers_notes` — as tabelas de origem
 *    correspondentes (`clientesAnexos`, `clientesAtividades`) estão VAZIAS;
 *    coluna sem dado é peso morto.
 *  - `crm_contracts_services` — na origem a PK é composta (`contratoId`,
 *    `tipoServicoId`), a linha não tem id próprio para guardar; a
 *    idempotência dela já vem da UNIQUE `uk_contracts_services`.
 *
 * As cinco views `_v` são recriadas com a coluna nova, como manda a regra do
 * projeto para tabela que tem view. Cada uma parte da ÚLTIMA definição vigente
 * (não da original): `crm_customers_v` da 018, `crm_contracts_v` da 019,
 * `crm_contracts_domains_v` da 011, `crm_customers_contacts_v` da 008 e
 * `crm_contracts_files_v` da 009. Sem DEFINER, senão o deploy quebra em outro
 * servidor.
 */
class Migration_Legacy_id_15_08_26 extends CI_Migration
{
  /**
   * Definição da coluna, idêntica nas cinco tabelas.
   *
   * @var array
   */
  private $coluna = [
    'legacy_id' => [
      'type' => 'INT',
      'constraint' => 11,
      'unsigned' => TRUE,
      'null' => TRUE,
      'comment' => 'Id da linha no gestor-interno (importacao). NULL = cadastro feito no proprio sistema.',
    ],
  ];

  /**
   * Tabela => nome do índice UNIQUE.
   *
   * @var array
   */
  private $tabelas = [
    'crm_customers' => 'uk_customers_company_legacy',
    'crm_contracts' => 'uk_contracts_company_legacy',
    'crm_customers_contacts' => 'uk_customers_contacts_company_legacy',
    'crm_contracts_files' => 'uk_contracts_files_company_legacy',
    'crm_contracts_domains' => 'uk_contracts_domains_company_legacy',
  ];

  public function up()
  {
    foreach ($this->tabelas as $tabela => $indice) {
      if (!$this->db->field_exists('legacy_id', $tabela)) {
        $this->dbforge->add_column($tabela, $this->coluna);
      }

      if (!$this->indiceExiste($tabela, $indice)) {
        $this->db->query("ALTER TABLE `{$tabela}` ADD UNIQUE KEY `{$indice}` (`id_company`, `legacy_id`)");
      }
    }

    $this->db->query($this->viewClientes());
    $this->db->query($this->viewContratos());
    $this->db->query($this->viewContatos());
    $this->db->query($this->viewArquivosContrato());
    $this->db->query($this->viewDominiosContrato());
  }

  public function down()
  {
    // Views voltam ao formato anterior ANTES de a coluna cair: view apontando
    // para coluna inexistente derruba toda tela que a lê (mesma ordem da 015,
    // da 017 e da 019).
    $this->db->query($this->viewClientesOriginal());
    $this->db->query($this->viewContratosOriginal());
    $this->db->query($this->viewContatosOriginal());
    $this->db->query($this->viewArquivosContratoOriginal());
    $this->db->query($this->viewDominiosContratoOriginal());

    foreach ($this->tabelas as $tabela => $indice) {
      if ($this->indiceExiste($tabela, $indice)) {
        $this->db->query("ALTER TABLE `{$tabela}` DROP INDEX `{$indice}`");
      }

      if ($this->db->field_exists('legacy_id', $tabela)) {
        $this->dbforge->drop_column($tabela, 'legacy_id');
      }
    }
  }

  /**
   * O CI3 não tem `index_exists()`; sem esta checagem, rodar a migration duas
   * vezes numa base que já tem o índice aborta com "Duplicate key name".
   *
   * @param string $tabela
   * @param string $indice
   * @return bool
   */
  private function indiceExiste($tabela, $indice)
  {
    $consulta = $this->db->query(
      "SHOW INDEX FROM `{$tabela}` WHERE `Key_name` = ?",
      [$indice]
    );

    return ($consulta !== FALSE && $consulta->num_rows() > 0);
  }

  /**
   * A crm_customers_v da 018 + `legacy_id`.
   *
   * @return string
   */
  private function viewClientes()
  {
    return $this->montarViewClientes(TRUE);
  }

  /**
   * A crm_customers_v exatamente como a migration 018 a deixou.
   *
   * @return string
   */
  private function viewClientesOriginal()
  {
    return $this->montarViewClientes(FALSE);
  }

  /**
   * @param bool $comLegacy
   * @return string
   */
  private function montarViewClientes($comLegacy)
  {
    $legacy = $comLegacy ? "  `crm_customers`.`legacy_id` AS `legacy_id`,\n" : '';

    return "
CREATE OR REPLACE VIEW `crm_customers_v` AS
SELECT
  `crm_customers`.`id` AS `id`,
  `crm_customers`.`id_company` AS `id_company`,
{$legacy}  `crm_customers`.`type` AS `type`,
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
  `crm_companies`.`byname` AS `company_byname`,
  `crm_country_cities`.`name` AS `city_name`,
  `crm_country_states`.`name` AS `state_name`,
  `crm_country_states`.`uf` AS `state_uf`,
  `crm_users`.`name` AS `created_user`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `contracts_count`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id` AND `crm_contracts`.`status` = 'vigente') AS `active_contracts_count`,
  (SELECT GROUP_CONCAT(DISTINCT `crm_contracts_services`.`id_service_type`)
     FROM `crm_contracts_services`
     JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_services`.`id_contract`)
    WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `service_type_ids`
FROM ((( `crm_customers`
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_customers`.`id_company`))
  LEFT JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_customers`.`id_city`))
  LEFT JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_customers`.`id_state`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers`.`created_by`)
";
  }

  /**
   * A crm_contracts_v da 019 + `legacy_id`.
   *
   * @return string
   */
  private function viewContratos()
  {
    return $this->montarViewContratos(TRUE);
  }

  /**
   * A crm_contracts_v exatamente como a migration 019 a deixou.
   *
   * @return string
   */
  private function viewContratosOriginal()
  {
    return $this->montarViewContratos(FALSE);
  }

  /**
   * @param bool $comLegacy
   * @return string
   */
  private function montarViewContratos($comLegacy)
  {
    $legacy = $comLegacy ? "  `crm_contracts`.`legacy_id` AS `legacy_id`,\n" : '';

    return "
CREATE OR REPLACE VIEW `crm_contracts_v` AS
SELECT
  `crm_contracts`.`id` AS `id`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`,
{$legacy}  `crm_contracts`.`status` AS `status`,
  `crm_contracts`.`cycle` AS `cycle`,
  `crm_contracts`.`value` AS `value`,
  `crm_contracts`.`space_gb` AS `space_gb`,
  `crm_contracts`.`comments` AS `comments`,
  `crm_contracts`.`ended` AS `ended`,
  `crm_contracts`.`ended_reason` AS `ended_reason`,
  `crm_contracts`.`ended_comments` AS `ended_comments`,
  `crm_contracts`.`ended_by` AS `ended_by`,
  `crm_contracts`.`bomcontrole_contract_id` AS `bomcontrole_contract_id`,
  `crm_contracts`.`bomcontrole_linked` AS `bomcontrole_linked`,
  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `u_created`.`name` AS `created_user`,
  `u_ended`.`name` AS `ended_user`
FROM (((`crm_contracts`
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_contracts`.`id_customer`))
  LEFT JOIN `crm_users` `u_created` ON(`u_created`.`id` = `crm_contracts`.`created_by`))
  LEFT JOIN `crm_users` `u_ended` ON(`u_ended`.`id` = `crm_contracts`.`ended_by`))
";
  }

  /**
   * A crm_customers_contacts_v da 008 + `legacy_id`.
   *
   * @return string
   */
  private function viewContatos()
  {
    return $this->montarViewContatos(TRUE);
  }

  /**
   * A crm_customers_contacts_v exatamente como a migration 008 a deixou.
   *
   * @return string
   */
  private function viewContatosOriginal()
  {
    return $this->montarViewContatos(FALSE);
  }

  /**
   * @param bool $comLegacy
   * @return string
   */
  private function montarViewContatos($comLegacy)
  {
    $legacy = $comLegacy ? "  `crm_customers_contacts`.`legacy_id` AS `legacy_id`,\n" : '';

    return "
CREATE OR REPLACE VIEW `crm_customers_contacts_v` AS
SELECT
  `crm_customers_contacts`.`id` AS `id`,
  `crm_customers_contacts`.`id_customer` AS `id_customer`,
  `crm_customers_contacts`.`id_company` AS `id_company`,
{$legacy}  `crm_customers_contacts`.`type` AS `type`,
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
  }

  /**
   * A crm_contracts_files_v da 009 + `legacy_id`.
   *
   * @return string
   */
  private function viewArquivosContrato()
  {
    return $this->montarViewArquivosContrato(TRUE);
  }

  /**
   * A crm_contracts_files_v exatamente como a migration 009 a deixou.
   *
   * @return string
   */
  private function viewArquivosContratoOriginal()
  {
    return $this->montarViewArquivosContrato(FALSE);
  }

  /**
   * @param bool $comLegacy
   * @return string
   */
  private function montarViewArquivosContrato($comLegacy)
  {
    $legacy = $comLegacy ? "  `crm_contracts_files`.`legacy_id` AS `legacy_id`,\n" : '';

    return "
CREATE OR REPLACE VIEW `crm_contracts_files_v` AS
SELECT
  `crm_contracts_files`.`id` AS `id`,
  `crm_contracts_files`.`id_contract` AS `id_contract`,
  `crm_contracts_files`.`id_company` AS `id_company`,
{$legacy}  `crm_contracts_files`.`name` AS `name`,
  `crm_contracts_files`.`file` AS `file`,
  `crm_contracts_files`.`created` AS `created`,
  `crm_contracts_files`.`created_by` AS `created_by`,
  `crm_users`.`name` AS `created_user`
FROM (`crm_contracts_files`
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts_files`.`created_by`))
";
  }

  /**
   * A crm_contracts_domains_v da 011 + `legacy_id`.
   *
   * @return string
   */
  private function viewDominiosContrato()
  {
    return $this->montarViewDominiosContrato(TRUE);
  }

  /**
   * A crm_contracts_domains_v exatamente como a migration 011 a deixou.
   *
   * @return string
   */
  private function viewDominiosContratoOriginal()
  {
    return $this->montarViewDominiosContrato(FALSE);
  }

  /**
   * @param bool $comLegacy
   * @return string
   */
  private function montarViewDominiosContrato($comLegacy)
  {
    $legacy = $comLegacy ? "  `crm_contracts_domains`.`legacy_id` AS `legacy_id`,\n" : '';

    return "
CREATE OR REPLACE VIEW `crm_contracts_domains_v` AS
SELECT
  `crm_contracts_domains`.`id` AS `id`,
  `crm_contracts_domains`.`id_contract` AS `id_contract`,
  `crm_contracts_domains`.`id_company` AS `id_company`,
{$legacy}  `crm_contracts_domains`.`id_server_domain` AS `id_server_domain`,
  `crm_contracts_domains`.`domain` AS `domain`,
  `crm_contracts_domains`.`due_date` AS `due_date`,
  `crm_contracts_domains`.`registrar` AS `registrar`,
  `crm_contracts_domains`.`managed_cdw` AS `managed_cdw`,
  `crm_contracts_domains`.`comments` AS `comments`,
  `crm_contracts_domains`.`created` AS `created`,
  `crm_contracts_domains`.`created_by` AS `created_by`,
  `crm_contracts_domains`.`modified` AS `modified`,
  `crm_contracts_domains`.`modified_by` AS `modified_by`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`status` AS `contract_status`,
  `crm_contracts`.`cycle` AS `contract_cycle`,
  `crm_servers_domains`.`domain` AS `server_domain`,
  `crm_servers_domains`.`status` AS `server_domain_status`,
  `crm_servers_domains`.`disk_used_mb` AS `server_disk_used_mb`,
  `crm_servers`.`name` AS `server_name`,
  `crm_servers`.`type` AS `server_type`,
  `crm_users`.`name` AS `created_user`
FROM ((((`crm_contracts_domains`
  JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_domains`.`id_contract`))
  LEFT JOIN `crm_servers_domains` ON(`crm_servers_domains`.`id` = `crm_contracts_domains`.`id_server_domain`))
  LEFT JOIN `crm_servers` ON(`crm_servers`.`id` = `crm_servers_domains`.`id_server`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts_domains`.`created_by`))
";
  }
}
