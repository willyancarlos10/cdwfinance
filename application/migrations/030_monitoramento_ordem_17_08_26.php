<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Coluna de apoio para ordenar o monitoramento por urgência.
 *
 * A tela de estado precisa abrir com os problemas em cima — site fora, página
 * quebrada, certificado inválido —, e não em ordem alfabética de um slug
 * (`bloqueado` antes de `fora`, `marcador` antes de `ok`), que não tem relação
 * nenhuma com gravidade.
 *
 * O caminho óbvio seria `ORDER BY FIELD(situation, 'fora', 'marcador', ...)`,
 * e ele NÃO funciona por aqui: o `Global_model` repassa a ordenação ao
 * `order_by()` do CI3, que quebra a string em cada VÍRGULA e passa cada pedaço
 * por `protect_identifiers`. O `FIELD(...)` sairia estilhaçado em oito termos de
 * ordenação, cada um escapado como se fosse nome de coluna. É a mesma armadilha
 * que a 027 contornou no vencimento do WHOIS, e a mesma que o CLAUDE.md registra
 * para o `FIND_IN_SET` dentro do `where()`.
 *
 * Por isso a prioridade vira NÚMERO na própria view, derivada do MESMO CASE que
 * produz o `situation` — assim ordenação, filtro e cor do badge não podem
 * divergir, que é o motivo de o `whois_bucket` ter nascido em 012.
 *
 * Os silenciados e os inativos ficam no fim de propósito: não são incidentes, e
 * o que eles não podem é disputar espaço com quem precisa de atenção.
 */
class Migration_Monitoramento_ordem_17_08_26 extends CI_Migration
{
  public function up()
  {
    $this->criarViewMonitor(TRUE);
  }

  public function down()
  {
    $this->criarViewMonitor(FALSE);
  }

  /**
   * @param  bool $comOrdem FALSE devolve a view ao formato da 028
   * @return void
   */
  private function criarViewMonitor($comOrdem)
  {
    $colunaOrdem = $comOrdem
      ? "  CASE\n"
      . "    WHEN `crm_domains_monitor`.`muted` = 1 THEN 90\n"
      . "    WHEN `crm_domains_monitor`.`active` = 0 THEN 95\n"
      . "    WHEN `crm_domains_monitor`.`last_check` IS NULL THEN 70\n"
      . "    WHEN `crm_domains_monitor`.`check_status` = 'nunca_respondeu' THEN 50\n"
      . "    WHEN `crm_domains_monitor`.`down_since` IS NOT NULL THEN 10\n"
      . "    WHEN `crm_domains_monitor`.`flag` IS NOT NULL THEN 20\n"
      . "    WHEN `crm_domains_monitor`.`ssl_status` IN ('vencido', 'nome_divergente') THEN 30\n"
      . "    WHEN `crm_domains_monitor`.`ssl_expiration_date` IS NOT NULL\n"
      . "     AND `crm_domains_monitor`.`ssl_expiration_date` <= CURDATE() + INTERVAL 14 DAY THEN 40\n"
      . "    WHEN `crm_domains_monitor`.`http_result` = 'bloqueado' THEN 60\n"
      . "    ELSE 80\n"
      . "  END AS `situation_order`,\n"
      : '';

    $query = "
CREATE OR REPLACE VIEW `crm_domains_monitor_v` AS
SELECT
  `crm_domains_monitor`.`id` AS `id`,
  `crm_domains_monitor`.`id_company` AS `id_company`,
  `crm_domains_monitor`.`domain` AS `domain`,
  `crm_domains_monitor`.`apex` AS `apex`,
  `crm_domains_monitor`.`check_host` AS `check_host`,
  `crm_domains_monitor`.`active` AS `active`,
  `crm_domains_monitor`.`muted` AS `muted`,
  `crm_domains_monitor`.`ns_list` AS `ns_list`,
  `crm_domains_monitor`.`ns1` AS `ns1`,
  `crm_domains_monitor`.`ns2` AS `ns2`,
  `crm_domains_monitor`.`ns_status` AS `ns_status`,
  `crm_domains_monitor`.`ns_pending` AS `ns_pending`,
  `crm_domains_monitor`.`ns_message` AS `ns_message`,
  `crm_domains_monitor`.`ns_changed` AS `ns_changed`,
  `crm_domains_monitor`.`http_status` AS `http_status`,
  `crm_domains_monitor`.`http_result` AS `http_result`,
  `crm_domains_monitor`.`http_final_url` AS `http_final_url`,
  `crm_domains_monitor`.`http_message` AS `http_message`,
  `crm_domains_monitor`.`title` AS `title`,
  `crm_domains_monitor`.`title_changed` AS `title_changed`,
  `crm_domains_monitor`.`flag` AS `flag`,
  `crm_domains_monitor`.`down_since` AS `down_since`,
  `crm_domains_monitor`.`down_notified` AS `down_notified`,
  `crm_domains_monitor`.`consecutive_failures` AS `consecutive_failures`,
  `crm_domains_monitor`.`ssl_expiration_date` AS `ssl_expiration_date`,
  `crm_domains_monitor`.`ssl_issuer` AS `ssl_issuer`,
  `crm_domains_monitor`.`ssl_status` AS `ssl_status`,
  `crm_domains_monitor`.`ssl_notified_for` AS `ssl_notified_for`,
  `crm_domains_monitor`.`last_check` AS `last_check`,
  `crm_domains_monitor`.`last_success` AS `last_success`,
  `crm_domains_monitor`.`check_status` AS `check_status`,
  CASE
    WHEN `crm_domains_monitor`.`muted` = 1 THEN 'silenciado'
    WHEN `crm_domains_monitor`.`active` = 0 THEN 'inativo'
    WHEN `crm_domains_monitor`.`last_check` IS NULL THEN 'pendente'
    WHEN `crm_domains_monitor`.`check_status` = 'nunca_respondeu' THEN 'nunca_respondeu'
    WHEN `crm_domains_monitor`.`down_since` IS NOT NULL THEN 'fora'
    WHEN `crm_domains_monitor`.`flag` IS NOT NULL THEN 'marcador'
    WHEN `crm_domains_monitor`.`ssl_status` IN ('vencido', 'nome_divergente') THEN 'ssl_problema'
    WHEN `crm_domains_monitor`.`ssl_expiration_date` IS NOT NULL
     AND `crm_domains_monitor`.`ssl_expiration_date` <= CURDATE() + INTERVAL 14 DAY THEN 'ssl_vencendo'
    WHEN `crm_domains_monitor`.`http_result` = 'bloqueado' THEN 'bloqueado'
    ELSE 'ok'
  END AS `situation`,
" . $colunaOrdem . "  CASE
    WHEN `crm_domains_monitor`.`down_since` IS NULL THEN NULL
    ELSE TIMESTAMPDIFF(DAY, `crm_domains_monitor`.`down_since`, NOW())
  END AS `days_down`,
  `crm_domains_monitor`.`created` AS `created`,
  `crm_domains_monitor`.`created_by` AS `created_by`,
  `crm_domains_monitor`.`modified` AS `modified`,
  `crm_domains_monitor`.`modified_by` AS `modified_by`,
  `crm_companies`.`byname` AS `company_byname`
FROM (`crm_domains_monitor`
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_domains_monitor`.`id_company`))
";
    $this->db->query($query);
  }
}
