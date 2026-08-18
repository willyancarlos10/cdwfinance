<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * `registration`: a situação do REGISTRO da cobrança, derivada na
 * `crm_invoices_v`.
 *
 * É pergunta diferente da que `situation` responde, e as duas convivem na
 * mesma linha sem se sobrepor:
 *
 *   situation     → o CLIENTE já pagou? (a_vencer | vencida | paga | cancelada)
 *   registration  → existe boleto para ele pagar?
 *
 * Uma fatura pode estar "a vencer" e sem boleto nenhum — que é exatamente o
 * caso que ninguém vê até o cliente reclamar, e o motivo desta coluna existir.
 *
 * Derivada na VIEW, e não no controller, pelo mesmo motivo do `situation` e do
 * `whois_bucket` da crm_servers_domains_v: listagem, cor do badge e o filtro
 * que venha depois precisam do MESMO limiar. Calculado em três lugares, um
 * deles discorda — e aqui a discordância seria "a tela diz que tem boleto e a
 * fila diz que falta registrar".
 *
 * Os quatro estados espelham exatamente o que a fila de `processarPendentes()`
 * procura, e é isso que os torna verificáveis:
 *
 *   sem_psp        contrato sem provedor definido — não entra na fila
 *   nao_registrada `psp_charge_id` vazio  → fase 'registrar' da fila
 *   registrando    tem cobrança, falta boleto/PIX → fase 'sincronizar'
 *   registrada     tem linha digitável ou PIX  → nada a fazer
 *
 * `registrando` é estado NORMAL, não falha: a emissão do Banco Inter é
 * assíncrona (o POST devolve só o `codigoSolicitacao`), então existe uma
 * janela legítima entre registrar e ter o boleto. Tratá-la como erro mandaria
 * o usuário tentar de novo sem necessidade.
 *
 * Fatura **cancelada ou paga** não é classificada por aqui: cai em
 * `nao_registrada` se nunca teve cobrança, e é a `situation` que manda na
 * tela. Registro é pergunta sobre a cobrança, não sobre o dinheiro.
 *
 * Não há coluna física nova: tudo já está em `psp`, `psp_charge_id`,
 * `linha_digitavel` e `link_pix` (migration 034). Gravar o estado seria uma
 * segunda verdade sobre o mesmo dado, e exigiria um cron para mantê-la — o
 * mesmo argumento que manteve 'vencida' fora de `crm_invoices.status`.
 *
 * @see docs/PLANO-PSP-COBRANCA.md
 */
class Migration_Registro_cobranca_18_08_26 extends CI_Migration
{
  public function up()
  {
    $this->db->query($this->viewFaturas(TRUE));
  }

  public function down()
  {
    $this->db->query($this->viewFaturas(FALSE));
  }

  /**
   * A crm_invoices_v da 034, com ou sem a coluna derivada.
   *
   * @param  bool $comRegistro
   * @return string
   */
  private function viewFaturas($comRegistro)
  {
    $registro = $comRegistro
      ? "  CASE\n"
      . "    WHEN `crm_invoices`.`psp` = '' THEN 'sem_psp'\n"
      . "    WHEN `crm_invoices`.`psp_charge_id` IS NULL OR `crm_invoices`.`psp_charge_id` = '' THEN 'nao_registrada'\n"
      . "    WHEN (`crm_invoices`.`linha_digitavel` IS NULL OR `crm_invoices`.`linha_digitavel` = '')\n"
      . "     AND (`crm_invoices`.`link_pix` IS NULL OR `crm_invoices`.`link_pix` = '') THEN 'registrando'\n"
      . "    ELSE 'registrada'\n"
      . "  END AS `registration`,\n"
      : '';

    return "
CREATE OR REPLACE VIEW `crm_invoices_v` AS
SELECT
  `crm_invoices`.`id` AS `id`,
  `crm_invoices`.`id_company` AS `id_company`,
  `crm_invoices`.`id_customer` AS `id_customer`,
  `crm_invoices`.`id_contract` AS `id_contract`,
  `crm_invoices`.`id_charge` AS `id_charge`,
  `crm_invoices`.`installment_number` AS `installment_number`,
  `crm_invoices`.`installments_total` AS `installments_total`,
  `crm_contracts_charges`.`description` AS `charge_description`,
  `crm_contracts_charges`.`status` AS `charge_status`,
  `crm_invoices`.`competence` AS `competence`,
  `crm_invoices`.`due_date` AS `due_date`,
  `crm_invoices`.`value` AS `value`,
  `crm_invoices`.`status` AS `status`,
  `crm_invoices`.`description` AS `description`,
  `crm_invoices`.`invoice_policy` AS `invoice_policy`,
  `crm_invoices`.`psp` AS `psp`,
  `crm_invoices`.`psp_charge_id` AS `psp_charge_id`,
  `crm_invoices`.`psp_status` AS `psp_status`,
  `crm_invoices`.`link_boleto` AS `link_boleto`,
  `crm_invoices`.`linha_digitavel` AS `linha_digitavel`,
  `crm_invoices`.`link_pix` AS `link_pix`,
  `crm_invoices`.`paid_at` AS `paid_at`,
  `crm_invoices`.`paid_amount` AS `paid_amount`,
  `crm_invoices`.`paid_method` AS `paid_method`,
  `crm_invoices`.`sent_at` AS `sent_at`,
  `crm_invoices`.`comments` AS `comments`,
  `crm_invoices`.`created` AS `created`,
  `crm_invoices`.`created_by` AS `created_by`,
  `crm_invoices`.`modified` AS `modified`,
  `crm_invoices`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `crm_customers`.`document` AS `customer_document`,
  `crm_contracts`.`cycle` AS `contract_cycle`,
  `crm_contracts`.`status` AS `contract_status`,
  `crm_users`.`name` AS `created_user`,
{$registro}  CASE
    WHEN `crm_invoices`.`status` = 'cancelada' THEN 'cancelada'
    WHEN `crm_invoices`.`status` = 'paga' THEN 'paga'
    WHEN `crm_invoices`.`due_date` < CURDATE() THEN 'vencida'
    ELSE 'a_vencer'
  END AS `situation`
FROM `crm_invoices`
INNER JOIN `crm_customers` ON `crm_customers`.`id` = `crm_invoices`.`id_customer`
INNER JOIN `crm_contracts` ON `crm_contracts`.`id` = `crm_invoices`.`id_contract`
LEFT JOIN `crm_contracts_charges` ON `crm_contracts_charges`.`id` = `crm_invoices`.`id_charge`
LEFT JOIN `crm_users` ON `crm_users`.`id` = `crm_invoices`.`created_by`
";
  }
}
