<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * O monitoramento deixa de conferir certificado SSL.
 *
 * A checagem foi retirada por decisão, depois de uma rodada em produção em que
 * "o certificado não cobre este domínio" saiu para dezenas de sites que estavam
 * perfeitos — e a investigação mostrou que o alarme era estrutural, não pontual.
 *
 * O QUE ESTAVA ERRADO
 *
 *  - `Site_monitor::certificadoCobreHost()` lia `$folha['Subject Alternative
 *    Name']`, mas o cURL expõe as extensões X.509 pelo nome longo do OpenSSL:
 *    a chave é **`X509v3 Subject Alternative Name`**. O SAN, portanto, NUNCA era
 *    lido, e a conferência caía sempre no fallback do CN — que existia só para
 *    certificado antigo, sem SAN.
 *
 *  - Com um nome só para comparar, dois formatos comuns viravam alarme: o site
 *    que redireciona para o `www.` com CN no apex, e o certificado do AutoSSL do
 *    cPanel, cujo CN é um nome qualquer da lista (`mail.`, `cpanel.`,
 *    `webdisk.`, `autodiscover.`). Medido numa amostra de 30 domínios marcados
 *    como divergentes: **22 falsos positivos e 1 divergência real**.
 *
 *  - A regra "curinga cobre o apex", escrita para conter o primeiro surto de
 *    alarmes, era sintoma do mesmo defeito: `*.foo.com` contra `foo.com` só é
 *    problema porque o SAN — que traz o apex junto — não estava sendo lido.
 *
 * POR QUE REMOVER EM VEZ DE CORRIGIR A CHAVE
 *
 * A correção seria de uma linha, e foi oferecida. A decisão foi tirar o controle:
 * a operação já acompanha vencimento de certificado por fora, o Let's Encrypt
 * renova sozinho, e o `ssl_notified_for` é ancorado na DATA de vencimento — ou
 * seja, mesmo funcionando, o aviso voltaria a cada renovação, a cada ~90 dias,
 * para a base inteira. Ficam o "Site fora do ar" e a "Página com problema", que
 * são os dois sinais que exigem ação de quem opera.
 *
 * O QUE ESTA MIGRATION FAZ
 *
 *  1. Recria a `crm_domains_monitor_v` sem as colunas de SSL e sem os dois
 *     degraus de `situation`/`situation_order` (`ssl_problema`, `ssl_vencendo`).
 *     A view vem ANTES do drop: enquanto ela citar a coluna, o SELECT quebra no
 *     instante em que o `ALTER` passar.
 *  2. Derruba as quatro colunas de SSL de `crm_domains_monitor`.
 *  3. **APAGA os eventos `ssl_vencendo`** de `crm_domains_monitor_events`.
 *  4. Apaga o parâmetro `monitoramento_ssl_dias_aviso`.
 *  5. Atualiza o COMMENT de `crm_domains_monitor_events.type`, para a tabela não
 *     continuar descrevendo um tipo de evento que o catálogo não emite mais —
 *     mesmo cuidado que a 041 teve com os tipos de servidor.
 *
 * ⚠️ O passo 3 é IRREVERSÍVEL e é o único que toca histórico. Ele existe porque
 * o tipo sai do catálogo de `tiposEvento()`: sem apagar, o feed continuaria
 * abrindo nos "não vistos" com dezenas de linhas rotuladas pelo slug cru
 * `ssl_vencendo`, sobre uma checagem que não existe mais e que ninguém pode
 * resolver. Para PRESERVAR esse histórico, comentar a chamada de
 * `apagarEventosSsl()` no `up()` antes de subir o arquivo — o resto da migration
 * funciona igual, e o único efeito é o feed carregar essas linhas antigas.
 *
 * O `down()` recria as colunas VAZIAS e devolve a view ao formato da 030. Os
 * retratos de certificado e os eventos apagados não voltam: eram medição, e a
 * rodada seguinte os reconstituiria se a checagem voltasse a existir.
 */
class Migration_Monitoramento_sem_ssl_29_08_26 extends CI_Migration
{
  /** Colunas de SSL da 028, na ordem em que nasceram. */
  private $colunas = [
    'ssl_expiration_date' => ['type' => 'DATE', 'null' => TRUE, 'comment' => 'Vencimento do certificado'],
    'ssl_issuer' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => TRUE, 'comment' => 'Emissor do certificado'],
    'ssl_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => TRUE, 'comment' => 'ok | vencido | nome_divergente | ausente'],
    'ssl_notified_for' => ['type' => 'DATE', 'null' => TRUE, 'comment' => 'Vencimento para o qual o aviso já saiu'],
  ];

  public function up()
  {
    $this->criarView(FALSE);
    $this->dropColunas();
    $this->apagarEventosSsl();
    $this->apagarParametro();
    $this->comentarTipos(FALSE);
  }

  public function down()
  {
    $this->recriarColunas();
    $this->criarView(TRUE);
    $this->comentarTipos(TRUE);
  }

  // ------------------------------------------------------------------
  // Colunas
  // ------------------------------------------------------------------

  private function dropColunas()
  {
    foreach (array_keys($this->colunas) as $coluna) {
      if ($this->db->field_exists($coluna, 'crm_domains_monitor')) {
        $this->dbforge->drop_column('crm_domains_monitor', $coluna);
      }
    }
  }

  private function recriarColunas()
  {
    foreach ($this->colunas as $coluna => $definicao) {
      if (!$this->db->field_exists($coluna, 'crm_domains_monitor')) {
        $this->dbforge->add_column('crm_domains_monitor', [$coluna => $definicao]);
      }
    }
  }

  // ------------------------------------------------------------------
  // Resíduos
  // ------------------------------------------------------------------

  /**
   * Comentar a chamada no `up()` para preservar o histórico — ver o docblock.
   */
  private function apagarEventosSsl()
  {
    $this->db->query('DELETE FROM `crm_domains_monitor_events` WHERE `type` = ?', ['ssl_vencendo']);
  }

  private function apagarParametro()
  {
    $this->db->query(
      'DELETE FROM `crm_general_settings` WHERE `setting_group` = ? AND `setting_key` = ?',
      ['monitoramento', 'monitoramento_ssl_dias_aviso']
    );
  }

  /**
   * @param  bool $comSsl TRUE devolve o COMMENT ao texto da 028
   * @return void
   */
  private function comentarTipos($comSsl)
  {
    $tipos = 'ns_alterado | site_fora | site_restabelecido | titulo_alterado | marcador_detectado | '
      . ($comSsl ? 'ssl_vencendo | ' : '') . 'redirecionamento_externo';

    $this->db->query(
      "ALTER TABLE `crm_domains_monitor_events`
        MODIFY `type` varchar(30) NOT NULL COMMENT " . $this->db->escape($tipos)
    );
  }

  // ------------------------------------------------------------------
  // View
  // ------------------------------------------------------------------

  /**
   * @param  bool $comSsl TRUE devolve a view ao formato da 030
   * @return void
   */
  private function criarView($comSsl)
  {
    $colunasSsl = $comSsl
      ? "  `crm_domains_monitor`.`ssl_expiration_date` AS `ssl_expiration_date`,\n"
      . "  `crm_domains_monitor`.`ssl_issuer` AS `ssl_issuer`,\n"
      . "  `crm_domains_monitor`.`ssl_status` AS `ssl_status`,\n"
      . "  `crm_domains_monitor`.`ssl_notified_for` AS `ssl_notified_for`,\n"
      : '';

    $degrauSituacao = $comSsl
      ? "    WHEN `crm_domains_monitor`.`ssl_status` IN ('vencido', 'nome_divergente') THEN 'ssl_problema'\n"
      . "    WHEN `crm_domains_monitor`.`ssl_expiration_date` IS NOT NULL\n"
      . "     AND `crm_domains_monitor`.`ssl_expiration_date` <= CURDATE() + INTERVAL 14 DAY THEN 'ssl_vencendo'\n"
      : '';

    $degrauOrdem = $comSsl
      ? "    WHEN `crm_domains_monitor`.`ssl_status` IN ('vencido', 'nome_divergente') THEN 30\n"
      . "    WHEN `crm_domains_monitor`.`ssl_expiration_date` IS NOT NULL\n"
      . "     AND `crm_domains_monitor`.`ssl_expiration_date` <= CURDATE() + INTERVAL 14 DAY THEN 40\n"
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
" . $colunasSsl . "  `crm_domains_monitor`.`last_check` AS `last_check`,
  `crm_domains_monitor`.`last_success` AS `last_success`,
  `crm_domains_monitor`.`check_status` AS `check_status`,
  CASE
    WHEN `crm_domains_monitor`.`muted` = 1 THEN 'silenciado'
    WHEN `crm_domains_monitor`.`active` = 0 THEN 'inativo'
    WHEN `crm_domains_monitor`.`last_check` IS NULL THEN 'pendente'
    WHEN `crm_domains_monitor`.`check_status` = 'nunca_respondeu' THEN 'nunca_respondeu'
    WHEN `crm_domains_monitor`.`down_since` IS NOT NULL THEN 'fora'
    WHEN `crm_domains_monitor`.`flag` IS NOT NULL THEN 'marcador'
" . $degrauSituacao . "    WHEN `crm_domains_monitor`.`http_result` = 'bloqueado' THEN 'bloqueado'
    ELSE 'ok'
  END AS `situation`,
  CASE
    WHEN `crm_domains_monitor`.`muted` = 1 THEN 90
    WHEN `crm_domains_monitor`.`active` = 0 THEN 95
    WHEN `crm_domains_monitor`.`last_check` IS NULL THEN 70
    WHEN `crm_domains_monitor`.`check_status` = 'nunca_respondeu' THEN 50
    WHEN `crm_domains_monitor`.`down_since` IS NOT NULL THEN 10
    WHEN `crm_domains_monitor`.`flag` IS NOT NULL THEN 20
" . $degrauOrdem . "    WHEN `crm_domains_monitor`.`http_result` = 'bloqueado' THEN 60
    ELSE 80
  END AS `situation_order`,
  CASE
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
