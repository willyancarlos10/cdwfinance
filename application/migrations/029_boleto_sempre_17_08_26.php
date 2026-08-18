<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Boleto passa a ser sempre emitido: sai o `issue_boleto`.
 *
 * A 024 nasceu com a pergunta "este contrato emite boleto?" porque o
 * faturamento ainda seria feito pelo Bom Controle, onde a emissão é opcional
 * por venda (o objeto `FormaPagamento.Boleto` do `Venda/Criar` só entra quando
 * há emissão). Com a arquitetura decidida em 17/08/2026 — cobrança no CDW
 * Finance via PSP, ERP só como emissor de nota —, **toda fatura gerada aqui
 * vira uma cobrança no PSP**, com boleto e PIX no mesmo registro. Não existe
 * mais o caso "faturar sem boleto": a fatura É o boleto.
 *
 * A coluna sai em vez de virar constante 1 por três razões:
 *
 * 1. **Configuração inerte mente.** Um select que oferece "Não" e não muda
 *    nada é pior que campo nenhum — o usuário configura, confere na tela e
 *    acredita. Um DEFAULT 1 que ninguém lê tem o mesmo defeito pela porta dos
 *    fundos: alguém edita por SQL e o sistema ignora, sem aviso.
 * 2. **Ela sustentava uma regra cruzada que também morre.** `invoice_policy =
 *    'com_boleto'` exigia `issue_boleto = 1`, e a checagem existia nos dois
 *    lados (PHP e JS). Com boleto sempre presente, a condição é
 *    universalmente verdadeira — validação que nunca reprova é ruído que o
 *    próximo leitor vai tentar entender.
 * 3. **O snapshot em `crm_invoices` congela o que valia na geração**, e é
 *    consultado para decidir o que emitir. Congelar um valor que não tem mais
 *    variação não descreve nada.
 *
 * Custo real da remoção, medido antes: 403 contratos, dos quais **402 com
 * `issue_boleto = 0`** e 1 com 1 (o contrato de teste), e **1 fatura gerada**.
 * Não há histórico a preservar — e os 402 zeros seriam justamente os errados
 * sob a regra nova, porque todos passam a emitir.
 *
 * `invoice_policy` FICA, e o rótulo "Emitir junto com o boleto" continua
 * correto: ele responde QUANDO a nota sai (na geração da fatura ou depois da
 * compensação), que é pergunta independente e continua tendo duas respostas.
 *
 * As duas views são recriadas porque a coluna aparece nas duas: a
 * `crm_contracts_v` (recriada por último na 025) e a `crm_invoices_v` (024).
 *
 * @see docs/ROADMAP-FATURAMENTO.md — arquitetura de cobrança
 */
class Migration_Boleto_sempre_17_08_26 extends CI_Migration
{
  public function up()
  {
    if ($this->db->field_exists('issue_boleto', 'crm_contracts')) {
      $this->dbforge->drop_column('crm_contracts', 'issue_boleto');
    }

    if ($this->db->field_exists('issue_boleto', 'crm_invoices')) {
      $this->dbforge->drop_column('crm_invoices', 'issue_boleto');
    }

    $this->db->query($this->viewContratos(FALSE));
    $this->db->query($this->viewFaturas(FALSE));
  }

  public function down()
  {
    if (!$this->db->field_exists('issue_boleto', 'crm_contracts')) {
      $this->dbforge->add_column('crm_contracts', [
        'issue_boleto' => [
          'type' => 'TINYINT',
          'constraint' => 1,
          'null' => FALSE,
          'default' => 0,
          'comment' => 'Emite boleto para este contrato.',
          'after' => 'next_competence',
        ],
      ]);
    }

    if (!$this->db->field_exists('issue_boleto', 'crm_invoices')) {
      $this->dbforge->add_column('crm_invoices', [
        'issue_boleto' => [
          'type' => 'TINYINT',
          'constraint' => 1,
          'null' => FALSE,
          'default' => 0,
          'comment' => 'Snapshot do contrato.',
          'after' => 'description',
        ],
      ]);
    }

    $this->db->query($this->viewContratos(TRUE));
    $this->db->query($this->viewFaturas(TRUE));
  }

  /**
   * A crm_contracts_v da 025, com ou sem a coluna.
   *
   * @param  bool $comBoleto
   * @return string
   */
  private function viewContratos($comBoleto)
  {
    $boleto = $comBoleto ? "  `crm_contracts`.`issue_boleto` AS `issue_boleto`,\n" : '';

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
" . $boleto . "  `crm_contracts`.`invoice_policy` AS `invoice_policy`,
  `crm_contracts`.`adjustment_index` AS `adjustment_index`,
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

  /**
   * A crm_invoices_v da 024, com ou sem a coluna.
   *
   * O `situation` continua DERIVADO aqui: "vencida" muda sozinha com o passar
   * do dia e não é estado que alguém grave.
   *
   * @param  bool $comBoleto
   * @return string
   */
  private function viewFaturas($comBoleto)
  {
    $boleto = $comBoleto ? "  `crm_invoices`.`issue_boleto` AS `issue_boleto`,\n" : '';

    return "
CREATE OR REPLACE VIEW `crm_invoices_v` AS
SELECT
  `crm_invoices`.`id` AS `id`,
  `crm_invoices`.`id_company` AS `id_company`,
  `crm_invoices`.`id_customer` AS `id_customer`,
  `crm_invoices`.`id_contract` AS `id_contract`,
  `crm_invoices`.`competence` AS `competence`,
  `crm_invoices`.`due_date` AS `due_date`,
  `crm_invoices`.`value` AS `value`,
  `crm_invoices`.`status` AS `status`,
  `crm_invoices`.`description` AS `description`,
" . $boleto . "  `crm_invoices`.`invoice_policy` AS `invoice_policy`,
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
  CASE
    WHEN `crm_invoices`.`status` = 'cancelada' THEN 'cancelada'
    WHEN `crm_invoices`.`status` = 'paga' THEN 'paga'
    WHEN `crm_invoices`.`due_date` < CURDATE() THEN 'vencida'
    ELSE 'a_vencer'
  END AS `situation`
FROM `crm_invoices`
INNER JOIN `crm_customers` ON `crm_customers`.`id` = `crm_invoices`.`id_customer`
INNER JOIN `crm_contracts` ON `crm_contracts`.`id` = `crm_invoices`.`id_contract`
LEFT JOIN `crm_users` ON `crm_users`.`id` = `crm_invoices`.`created_by`
";
  }
}
