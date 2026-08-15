<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Consulta de domínios .br pelo RDAP do Registro.br.
 *
 * A migration 012 nasceu só com a API Ninjas, que não atende .br, e por isso a
 * `crm_servers_domains_v` marcava todo domínio .br como `nacional` — uma faixa
 * que queria dizer "esta rotina não olha para você". Agora o RDAP olha, então
 * .br passa a receber as mesmas faixas de vencimento dos demais (pendente,
 * erro, vencido, vence_30, ok) e a coluna volta a significar só o estado da
 * consulta, não a origem dela.
 *
 * Sem mudança de tabela: as colunas `whois_*` servem para as duas origens, que
 * gravam no mesmo formato.
 */
class Migration_Whois_rdap_br_14_08_26 extends CI_Migration
{
  public function up()
  {
    $this->criarViewServersDomains(FALSE);

    // Rotina própria, e não um segundo escopo da existente: as origens têm
    // limites bem diferentes (a Ninjas tem quota mensal paga, o RDAP é público
    // com limite por janela), então cada uma precisa de horário, teto e
    // interruptor independentes.
    $existente = $this->db->get_where('crm_cron_logs', ['name' => 'cron_sync_whois_br'])->row();
    if (empty($existente)) {
      $this->db->insert('crm_cron_logs', [
        'name' => 'cron_sync_whois_br',
        'active' => 'S',
      ]);
    }
  }

  public function down()
  {
    $this->criarViewServersDomains(TRUE);

    $this->db->where('name', 'cron_sync_whois_br')->delete('crm_cron_logs');
  }

  /**
   * @param  bool $comFaixaNacional TRUE devolve a view ao formato da 012
   * @return void
   */
  private function criarViewServersDomains($comFaixaNacional)
  {
    $faixaNacional = $comFaixaNacional
      ? "    WHEN `crm_servers_domains`.`domain` LIKE '%.br' THEN 'nacional'\n"
      : '';

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
" . $faixaNacional . "    WHEN `crm_servers_domains`.`whois_last_check` IS NULL THEN 'pendente'
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
}
