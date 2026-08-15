<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Faixa `livre` do WHOIS: o domínio NÃO está registrado.
 *
 * Até aqui, "o registrador não conhece este domínio" era indistinguível de
 * "a consulta falhou": o 404 do RDAP e o da API Ninjas caíam em
 * `whois_status = 'erro'`, ao lado de timeout, SSL e chave inválida. Só que as
 * duas coisas pedem reações opostas — erro de consulta é problema NOSSO (chave,
 * rede, quota), enquanto domínio livre é problema do CLIENTE e urgente: o site
 * está hospedado no nosso servidor e o registro caiu, então qualquer um pode
 * tomar o domínio.
 *
 * O 404 vira um estado próprio (`whois_status = 'livre'`), gravado pelas
 * libraries via flag `livre` do retorno. Isso alimenta o indicador "Domínios
 * disponíveis" do Dashboard e a faixa nova do filtro da tela de domínios.
 *
 * Sem mudança de tabela: `whois_status` já é varchar(20) e o catálogo dele
 * ('sucesso | sem_dados | premium | erro') é convenção da aplicação, não
 * constraint — 'livre' entra sem DDL. O que muda é a view, que é de onde as
 * telas leem a faixa.
 *
 * Na CASE, `livre` fica DEPOIS de `pendente` e ANTES da comparação com
 * 'sucesso': domínio nunca consultado continua sendo 'pendente', e sem preceder
 * o `<> 'sucesso'` o novo estado seria engolido por 'erro' — exatamente o que
 * esta migration existe para desfazer.
 *
 * `sem_dados` NÃO virou `livre`, embora o comentário da 012 diga que a resposta
 * vazia "acontece em domínio livre ou sem WHOIS público". É essa ambiguidade
 * que impede: um TLD sem WHOIS público entraria no indicador como domínio
 * disponível, e um número inflado num card de urgência é pior que um número
 * conservador. Só o 404 — resposta explícita de "não existe" — vira 'livre'.
 */
class Migration_Whois_livre_14_08_26 extends CI_Migration
{
  public function up()
  {
    $this->criarViewServersDomains(TRUE);
  }

  public function down()
  {
    $this->criarViewServersDomains(FALSE);
  }

  /**
   * @param  bool $comFaixaLivre FALSE devolve a view ao formato da 014
   * @return void
   */
  private function criarViewServersDomains($comFaixaLivre)
  {
    $faixaLivre = $comFaixaLivre
      ? "    WHEN `crm_servers_domains`.`whois_status` = 'livre' THEN 'livre'\n"
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
    WHEN `crm_servers_domains`.`whois_last_check` IS NULL THEN 'pendente'
" . $faixaLivre . "    WHEN `crm_servers_domains`.`whois_status` <> 'sucesso' THEN 'erro'
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
