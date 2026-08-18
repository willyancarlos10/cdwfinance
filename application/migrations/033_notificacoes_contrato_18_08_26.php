<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Destinatarios de notificacao do contrato (e-mail e WhatsApp).
 *
 * O bloco Faturamento passa a guardar PARA QUEM avisar sobre este contrato:
 * boleto emitido, nota fiscal, aviso de reajuste. Hoje o unico aviso que existe
 * (o de reajuste, no Adjustment_model) resolve o destinatario por uma cascata
 * fixa — contato `financeiro` -> qualquer contato com e-mail -> o e-mail do
 * cliente. Isso responde "para quem mandar" no nivel do CLIENTE, e nao do
 * contrato: um cliente com tres contratos manda tudo para a mesma pessoa.
 *
 * Estes campos existem para o caso oposto, que e real: o contrato de
 * hospedagem avisa o TI e o de licenca avisa o financeiro. Enquanto o sistema
 * de notificacao nao existe, eles sao so cadastro — nada os le.
 *
 * ---
 *
 * COLUNA JSON, e nao tabela filha, ao contrario das outras `crm_contracts_*`.
 * A diferenca e o que a linha significa: `crm_contracts_adjustments` e
 * historico, `_charges` e dinheiro, `_domains` e inventario, `_files` sao
 * anexos — todas sao REGISTROS, com vida propria. Destinatario de aviso e
 * CONFIGURACAO: e salvo inteiro junto com o formulario (o repeater substitui a
 * lista toda), nao tem ciclo de vida por linha e nao ha o que preservar entre
 * duas gravacoes. Numa tabela, cada save viraria delete-all + insert, trocando
 * os ids de linhas que ninguem referencia.
 *
 * O formato espelha o `Form_model` do painel-v3, de onde veio o desenho do
 * repeater, para o codigo do envio portar direto quando existir:
 *
 *   {
 *     "emails":    [{"email": "x@y.com", "type": "destinatario|copia|copia_oculta"}],
 *     "whatsapps": [{"phone": "45999999999"}]
 *   }
 *
 * O WhatsApp NAO tem `type`: no envio cada destinatario recebe a sua propria
 * mensagem, entao copia e copia oculta nao significam nada ali — um select que
 * nao muda nada e pior que campo nenhum, porque alguem um dia o preenche
 * acreditando que muda.
 *
 * `longtext` como `crm_customers.attributes`, e nao o tipo JSON nativo: o
 * projeto inteiro le e grava esse tipo de campo com json_encode/json_decode, e
 * o MariaDB 10.4 trata JSON como alias de longtext de qualquer forma.
 *
 * @see docs/ROADMAP-FATURAMENTO.md — etapas B e F (envio de fatura e de NF)
 */
class Migration_Notificacoes_contrato_18_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->field_exists('notification_config', 'crm_contracts')) {
      $this->dbforge->add_column('crm_contracts', [
        'notification_config' => [
          'type' => 'LONGTEXT',
          'null' => TRUE,
          'comment' => 'JSON: {emails:[{email,type}], whatsapps:[{phone}]}. Schema no docblock da migration 033.',
          'after' => 'invoice_policy',
        ],
      ]);
    }

    $this->db->query($this->viewContratos(TRUE));
  }

  public function down()
  {
    // A view volta ao formato sem a coluna ANTES de ela sumir: view que
    // referencia coluna inexistente quebra em toda consulta.
    $this->db->query($this->viewContratos(FALSE));

    if ($this->db->field_exists('notification_config', 'crm_contracts')) {
      $this->dbforge->drop_column('crm_contracts', 'notification_config');
    }
  }

  /**
   * A crm_contracts_v da 031, com ou sem `notification_config`.
   *
   * @param  bool $comNotificacao
   * @return string
   */
  private function viewContratos($comNotificacao)
  {
    $notificacao = $comNotificacao
      ? "  `crm_contracts`.`notification_config` AS `notification_config`,\n"
      : '';

    return "
CREATE OR REPLACE VIEW `crm_contracts_v` AS
SELECT
  `crm_contracts`.`id` AS `id`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`,
  `crm_contracts`.`legacy_id` AS `legacy_id`,
  `crm_contracts`.`status` AS `status`,
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
  `crm_contracts`.`billing_source` AS `billing_source`,
  `crm_contracts`.`billing_day` AS `billing_day`,
  `crm_contracts`.`next_competence` AS `next_competence`,
  `crm_contracts`.`installments` AS `installments`,
  `crm_contracts`.`invoice_policy` AS `invoice_policy`,
" . $notificacao . "  `crm_contracts`.`adjustment_index` AS `adjustment_index`,
  `crm_contracts`.`next_adjustment` AS `next_adjustment`,
  `crm_contracts`.`adjustment_notified_for` AS `adjustment_notified_for`,
  `crm_contracts`.`bomcontrole_service_id` AS `bomcontrole_service_id`,
  `crm_contracts`.`bomcontrole_service_name` AS `bomcontrole_service_name`,
  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `u_created`.`name` AS `created_user`,
  `u_ended`.`name` AS `ended_user`
FROM `crm_contracts`
INNER JOIN `crm_customers` ON `crm_customers`.`id` = `crm_contracts`.`id_customer`
LEFT JOIN `crm_users` `u_created` ON `u_created`.`id` = `crm_contracts`.`created_by`
LEFT JOIN `crm_users` `u_ended` ON `u_ended`.`id` = `crm_contracts`.`ended_by`
";
  }
}
