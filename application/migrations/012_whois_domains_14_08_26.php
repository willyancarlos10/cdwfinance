<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * WHOIS dos domínios internacionais (API Ninjas).
 *
 * Os painéis de hospedagem contam o que acontece DENTRO do servidor (conta,
 * plano, disco, IP). Quem registra o domínio é outra história: vencimento real
 * e nameservers só o registrador sabe. Estas colunas guardam esse retrato.
 *
 * `whois_nameservers` guarda a lista COMPLETA (ordenada alfabeticamente) e é o
 * campo que a rotina compara para detectar troca — `whois_ns1`/`whois_ns2` são
 * derivados dela só para a tela. Guardar apenas dois nameservers perderia a
 * troca do terceiro, e comparar sem ordenar geraria falso positivo toda vez que
 * o registrador devolvesse a mesma lista embaralhada.
 *
 * `whois_last_check` é a base da política de reconsulta, e a regra de quando
 * gravá-lo é o detalhe que faz a rotina se comportar: erro DO DOMÍNIO (400, 404,
 * TLD premium, WHOIS sem dados) marca a tentativa, porque reconsultar amanhã
 * daria o mesmo resultado e queimaria o teto de chamadas; erro de INFRAESTRUTURA
 * (429, timeout, 5xx, DNS) não marca, senão uma noite de rede ruim empurraria a
 * base inteira para a frente e o vencimento envelheceria em silêncio.
 *
 * A tabela de histórico existe porque a pergunta do negócio é "quando o NS foi
 * trocado?", e isso não cabe numa coluna do registro atual.
 */
class Migration_Whois_domains_14_08_26 extends CI_Migration
{
  /** Colunas acrescentadas em crm_servers_domains. */
  private $colunas = [
    'whois_expiration_date' => [
      'type' => 'DATE',
      'null' => TRUE,
      'comment' => 'Vencimento do registro do domínio, segundo o WHOIS.',
    ],
    'whois_nameservers' => [
      'type' => 'VARCHAR',
      'constraint' => 500,
      'null' => TRUE,
      'comment' => 'Lista completa de nameservers, minúscula e ordenada, separada por vírgula. É o campo comparado para detectar troca.',
    ],
    'whois_ns1' => [
      'type' => 'VARCHAR',
      'constraint' => 255,
      'null' => TRUE,
      'comment' => 'Primeiro nameserver da lista ordenada (exibição).',
    ],
    'whois_ns2' => [
      'type' => 'VARCHAR',
      'constraint' => 255,
      'null' => TRUE,
      'comment' => 'Segundo nameserver da lista ordenada (exibição).',
    ],
    'whois_registrar' => [
      'type' => 'VARCHAR',
      'constraint' => 150,
      'null' => TRUE,
      'comment' => 'Registrador informado pelo WHOIS.',
    ],
    'whois_last_check' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Última tentativa de consulta (sucesso ou erro). Base da política de reconsulta.',
    ],
    'whois_status' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => TRUE,
      'comment' => 'sucesso | sem_dados | premium | erro',
    ],
    'whois_message' => [
      'type' => 'VARCHAR',
      'constraint' => 500,
      'null' => TRUE,
      'comment' => 'Motivo do erro da última consulta.',
    ],
    'whois_ns_changed' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Quando a última troca de nameservers foi detectada. Denormalizado para a listagem não consultar o histórico.',
    ],
  ];

  public function up()
  {
    foreach ($this->colunas as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_servers_domains')) {
        $this->dbforge->add_column('crm_servers_domains', [$nome => $definicao]);
      }
    }

    // Índices das duas colunas que entram em WHERE/ORDER BY: a seleção dos
    // pendentes ordena por whois_last_check e os filtros da tela varrem
    // whois_expiration_date.
    $this->criarIndice('crm_servers_domains', 'whois_last_check');
    $this->criarIndice('crm_servers_domains', 'whois_expiration_date');

    $query = "
CREATE TABLE IF NOT EXISTS `crm_domains_whois_history` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_server_domain` int(11) unsigned DEFAULT NULL COMMENT 'Linha de origem; NULL quando o domínio já foi podado da sincronização.',
  `id_company` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL COMMENT 'Domínio registrável consultado, minúsculo. Denormalizado para o histórico sobreviver à poda.',
  `field` varchar(20) NOT NULL COMMENT 'nameservers | expiration_date | registrar',
  `old_value` varchar(500) DEFAULT NULL,
  `new_value` varchar(500) DEFAULT NULL,
  `detected` datetime NOT NULL COMMENT 'Quando a mudança foi detectada.',
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_server_domain` (`id_server_domain`),
  KEY `id_company` (`id_company`),
  KEY `domain` (`domain`),
  KEY `detected` (`detected`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_domains_whois_history_ibfk_1` FOREIGN KEY (`id_server_domain`) REFERENCES `crm_servers_domains` (`id`) ON DELETE SET NULL,
  CONSTRAINT `crm_domains_whois_history_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_domains_whois_history_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    // ON DELETE SET NULL de propósito, mesma razão do crm_contracts_domains:
    // Server_model::pruneDomains() é hard delete, e a troca de nameserver que já
    // aconteceu não pode ser apagada porque o domínio saiu do painel. `domain`
    // fica denormalizado justamente para o registro continuar legível depois.
    $this->db->query($query);

    $this->criarViewServersDomains();
    $this->criarViewHistorico();

    // Registra a rotina no painel de CRON (Painel::cron lê de crm_cron_logs).
    $existente = $this->db->get_where('crm_cron_logs', ['name' => 'cron_sync_whois'])->row();
    if (empty($existente)) {
      $this->db->insert('crm_cron_logs', [
        'name' => 'cron_sync_whois',
        'active' => 'S',
      ]);
    }
  }

  public function down()
  {
    $this->db->query("DROP VIEW IF EXISTS `crm_domains_whois_history_v`");
    $this->db->query("DROP TABLE IF EXISTS `crm_domains_whois_history`");

    foreach (array_keys($this->colunas) as $nome) {
      if ($this->db->field_exists($nome, 'crm_servers_domains')) {
        $this->dbforge->drop_column('crm_servers_domains', $nome);
      }
    }

    $this->recriarViewServersDomainsSemWhois();

    $this->db->where('name', 'cron_sync_whois')->delete('crm_cron_logs');
  }

  /**
   * Cria o índice só quando ainda não existe — a migration roda automática a
   * cada requisição e um CREATE INDEX repetido é erro fatal.
   *
   * @param  string $tabela
   * @param  string $coluna
   * @return void
   */
  private function criarIndice($tabela, $coluna)
  {
    $existente = $this->db->query(
      'SHOW INDEX FROM `' . $tabela . '` WHERE `Key_name` = ?',
      [$coluna]
    )->row();

    if (empty($existente)) {
      $this->db->query('CREATE INDEX `' . $coluna . '` ON `' . $tabela . '` (`' . $coluna . '`)');
    }
  }

  /**
   * Recria a crm_servers_domains_v com as colunas de WHOIS. As telas leem da
   * view, então sem isto nada do que a rotina grava aparece.
   *
   * `whois_bucket` é coluna derivada de propósito: o Global_model::getFilter()
   * só sabe montar `where([campo => valor])` — não expressa IS NULL nem faixa de
   * data. Como a faixa vira uma coluna comum do ponto de vista de quem consulta
   * a view, o filtro da tela funciona sem tocar no motor de filtro, e os
   * limiares de vencimento ficam num lugar só, servindo filtro e cor da tela.
   */
  private function criarViewServersDomains()
  {
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
  `crm_servers_domains`.`whois_expiration_date` AS `whois_expiration_date`,
  `crm_servers_domains`.`whois_nameservers` AS `whois_nameservers`,
  `crm_servers_domains`.`whois_ns1` AS `whois_ns1`,
  `crm_servers_domains`.`whois_ns2` AS `whois_ns2`,
  `crm_servers_domains`.`whois_registrar` AS `whois_registrar`,
  `crm_servers_domains`.`whois_last_check` AS `whois_last_check`,
  `crm_servers_domains`.`whois_status` AS `whois_status`,
  `crm_servers_domains`.`whois_message` AS `whois_message`,
  `crm_servers_domains`.`whois_ns_changed` AS `whois_ns_changed`,
  CASE
    WHEN `crm_servers_domains`.`domain` LIKE '%.br' THEN 'nacional'
    WHEN `crm_servers_domains`.`whois_last_check` IS NULL THEN 'pendente'
    WHEN `crm_servers_domains`.`whois_status` <> 'sucesso' THEN 'erro'
    WHEN `crm_servers_domains`.`whois_expiration_date` IS NULL THEN 'sem_vencimento'
    WHEN `crm_servers_domains`.`whois_expiration_date` < CURDATE() THEN 'vencido'
    WHEN `crm_servers_domains`.`whois_expiration_date` <= CURDATE() + INTERVAL 30 DAY THEN 'vence_30'
    ELSE 'ok'
  END AS `whois_bucket`,
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
  }

  /**
   * LEFT JOIN em crm_servers_domains de propósito: id_server_domain é NULLable
   * (a poda zera o vínculo) e com INNER JOIN o histórico desses domínios sumiria
   * das telas — a mesma armadilha que a crm_companies_v tem.
   */
  private function criarViewHistorico()
  {
    $query = "
CREATE OR REPLACE VIEW `crm_domains_whois_history_v` AS
SELECT
  `crm_domains_whois_history`.`id` AS `id`,
  `crm_domains_whois_history`.`id_server_domain` AS `id_server_domain`,
  `crm_domains_whois_history`.`id_company` AS `id_company`,
  `crm_domains_whois_history`.`domain` AS `domain`,
  `crm_domains_whois_history`.`field` AS `field`,
  `crm_domains_whois_history`.`old_value` AS `old_value`,
  `crm_domains_whois_history`.`new_value` AS `new_value`,
  `crm_domains_whois_history`.`detected` AS `detected`,
  `crm_domains_whois_history`.`created_by` AS `created_by`,
  `crm_servers_domains`.`domain` AS `server_domain`,
  `crm_servers`.`name` AS `server_name`,
  `crm_servers`.`type` AS `server_type`,
  `crm_users`.`name` AS `created_user`
FROM (((`crm_domains_whois_history`
  LEFT JOIN `crm_servers_domains` ON(`crm_servers_domains`.`id` = `crm_domains_whois_history`.`id_server_domain`))
  LEFT JOIN `crm_servers` ON(`crm_servers`.`id` = `crm_servers_domains`.`id_server`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_domains_whois_history`.`created_by`))
";
    $this->db->query($query);
  }

  /** Devolve a view ao formato da migration 002. */
  private function recriarViewServersDomainsSemWhois()
  {
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
  }
}
