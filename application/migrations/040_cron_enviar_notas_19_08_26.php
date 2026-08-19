<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Registra `cron_enviar_notas` em `crm_cron_logs` (etapa F).
 *
 * Sem mudança de schema: `nf_sent_at`, `link_nota_fiscal` e
 * `link_nota_fiscal_xml` vieram na 039, já pensadas para este passo.
 *
 * A linha é obrigatória mesmo assim — sem ela a rotina não aparece no painel
 * Gestão › Cron e o `isCronActive()` a trata como inexistente, ou seja, ela
 * nunca roda.
 */
class Migration_Cron_enviar_notas_19_08_26 extends CI_Migration
{
  /**
   * @var string
   */
  private $rotina = 'cron_enviar_notas';

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
