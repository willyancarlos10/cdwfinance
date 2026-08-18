<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Ordenação da listagem de domínios: coluna de apoio para ordenar por vencimento.
 *
 * A listagem de `servidores/dominios` sempre ordenou por `domain asc` fixo, e as
 * perguntas operacionais do dia a dia — o que vence primeiro, o que não
 * sincroniza há mais tempo, quem ocupa mais disco — só se respondiam paginando à
 * mão. As três viram opção de ordenação na tela.
 *
 * Duas das três não precisam de nada no banco: `last_sync` e `disk_used_mb` são
 * colunas simples, e o MySQL já põe NULL no lado certo (`desc` joga para o fim;
 * `last_sync asc` traz NULL primeiro, que é o que "sincronizado há mais tempo"
 * quer dizer — nunca sincronizado é o mais atrasado de todos).
 *
 * O vencimento é que pede ajuda, por dois motivos:
 *
 * 1. `whois_expiration_date` é NULL em 81% da base (530 de 650 domínios), e NULL
 *    ordena PRIMEIRO no `ASC` — "vencimento mais próximo" abriria com 530 linhas
 *    que não respondem à pergunta. Resolver com `whois_expiration_date IS NULL`
 *    no ORDER BY não é opção: o `Global_model` repassa a ordenação ao
 *    `order_by()` do CI3, que passa o campo por `protect_identifiers` e
 *    escaparia a expressão inteira como se fosse nome de coluna. Ordenação, por
 *    ali, só pode ser nome de coluna — daí a coluna derivada, no mesmo idioma do
 *    `whois_bucket`.
 *
 * 2. A data sozinha MENTE quando a consulta não deu certo. `registrarFalha()` do
 *    `Whois_model` grava o novo status mas NÃO limpa os valores anteriores, de
 *    propósito ("a última informação boa que temos"). Então o caminho mais
 *    provável de todos — domínio vence, não é renovado, o registro é liberado —
 *    deixa a data velha gravada com `whois_status = 'livre'`, e num COALESCE
 *    ingênuo essa linha viria em PRIMEIRO lugar, exibindo "Livre" na coluna de
 *    vencimento. Ordenação e `whois_bucket` passariam a discordar sobre a mesma
 *    pergunta, que é justamente o que a 016 existiu para evitar. Por isso o CASE
 *    aqui espelha o do bucket, em vez de inventar uma segunda regra.
 *
 * Os dois sentinelas são distintos de propósito: `9999-12-30` é "sem data
 * confiável" e `9999-12-31` é "sem data" — misturá-los perderia a distinção no
 * desempate. O `CAST(... AS DATE)` existe porque o `COALESCE` de DATE com
 * literal string devolve VARCHAR: ordenar funcionaria (ISO é lexicográfico), mas
 * qualquer `+ INTERVAL` futuro sobre a coluna faria conversão implícita.
 *
 * A view é recriada a partir da versão da **016**, e não da 012: a 012 ainda tem
 * a faixa `nacional` e não tem a faixa `livre`. Recriar a partir dela desfaria a
 * 016 em silêncio — o card "Domínios disponíveis" do Dashboard zeraria, o filtro
 * "Livres (não registrados)" pararia de retornar linhas e todo `.br` voltaria
 * para um balde que nenhuma tela conhece mais.
 */
class Migration_Ordenacao_dominios_17_08_26 extends CI_Migration
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
   * @param  bool $comOrdenacao FALSE devolve a view ao formato da 016
   * @return void
   */
  private function criarViewServersDomains($comOrdenacao)
  {
    $colunaOrdenacao = $comOrdenacao
      ? "  CASE\n"
      . "    WHEN `crm_servers_domains`.`whois_status` IN ('livre', 'erro', 'sem_dados') THEN CAST('9999-12-30' AS DATE)\n"
      . "    ELSE COALESCE(`crm_servers_domains`.`whois_expiration_date`, CAST('9999-12-31' AS DATE))\n"
      . "  END AS `whois_expiration_sort`,\n"
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
    WHEN `crm_servers_domains`.`whois_status` = 'livre' THEN 'livre'
    WHEN `crm_servers_domains`.`whois_status` <> 'sucesso' THEN 'erro'
    WHEN `crm_servers_domains`.`whois_expiration_date` IS NULL THEN 'sem_vencimento'
    WHEN `crm_servers_domains`.`whois_expiration_date` < CURDATE() THEN 'vencido'
    WHEN `crm_servers_domains`.`whois_expiration_date` <= CURDATE() + INTERVAL 30 DAY THEN 'vence_30'
    ELSE 'ok'
  END AS `whois_bucket`,
" . $colunaOrdenacao . "  `crm_servers_domains`.`created` AS `created`,
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
