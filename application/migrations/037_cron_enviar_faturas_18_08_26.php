<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Registra `cron_enviar_faturas` em `crm_cron_logs`.
 *
 * Não há mudança de schema: as colunas de que a etapa B precisa já existem
 * (`crm_invoices.sent_at`, da 034) e o estado do registro já é derivado
 * (`crm_invoices_v.registration`, da 035).
 *
 * A linha em `crm_cron_logs` é obrigatória mesmo assim: sem ela a rotina não
 * aparece no painel Gestão › Cron e o `isCronActive()` a trata como
 * inexistente — ou seja, ela nunca roda. É o mesmo motivo pelo qual a 034 NÃO
 * registrou `cron_conciliar_cobrancas`: rotina cadastrada e inexistente
 * ofereceria um botão EXECUTAR que derruba a requisição.
 */
class Migration_Cron_enviar_faturas_18_08_26 extends CI_Migration
{
  /**
   * @var string
   */
  private $rotina = 'cron_enviar_faturas';

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
