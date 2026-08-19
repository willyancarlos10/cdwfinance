<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Registra `cron_conciliar_cobrancas` em `crm_cron_logs`.
 *
 * Sem mudança de schema: as colunas da baixa (`paid_at`, `paid_amount`,
 * `paid_method`) e a tabela de auditoria (`crm_psp_webhook_events`) vieram na
 * 034, e o `webhook_token` que identifica a conta na URL pública também.
 *
 * A 034 deixou esta linha de fora de propósito, porque a rotina ainda não
 * existia — rotina cadastrada e inexistente ofereceria no painel Gestão › Cron
 * um botão EXECUTAR que derruba a requisição. Agora ela existe.
 */
class Migration_Cron_conciliar_cobrancas_19_08_26 extends CI_Migration
{
  /**
   * @var string
   */
  private $rotina = 'cron_conciliar_cobrancas';

  public function up()
  {
    $existente = $this->db->get_where('crm_cron_logs', ['name' => $this->rotina])->row();

    if (empty($existente)) {
      $this->db->insert('crm_cron_logs', ['name' => $this->rotina, 'active' => 'S']);
    }
  }

  public function down()
  {
    $this->db->where('name', $this->rotina)->delete('crm_cron_logs');
  }
}
