<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Parcelamento das faturas e cobranca avulsa.
 *
 * A 024 cobria UM caso: contrato recorrente, uma fatura por competencia, valor
 * cheio do ciclo. Dois casos reais nao cabem nesse molde:
 *
 *  1. cobranca avulsa parcelada — R$ 1.000 de um servico X em 4x de R$ 250,
 *     cobranca UNICA, num cliente que segue com a recorrencia mensal de R$ 75;
 *  2. recorrencia parcelada — contrato ANUAL de R$ 500 cobrado em 2x de R$ 250
 *     mensais, todo ano.
 *
 * Os dois esbarram na mesma pedra: a UNIQUE (id_contract, competence), que
 * proibe duas faturas do mesmo contrato no mesmo mes.
 *
 * O PRINCIPIO desta migration:
 *
 *     `competence` e o periodo da OBRIGACAO.
 *     `installment_number` e a fatia do PAGAMENTO.
 *
 * Todas as parcelas de uma competencia COMPARTILHAM a competencia e se
 * distinguem pelo numero da parcela. O contrato anual de R$ 500 em 2x tem
 * competencia 2027-01-01 nas duas parcelas, vencendo em janeiro e fevereiro, e
 * `next_competence` continua avancando 12 meses — o motor segue com UM ponteiro
 * so, que e o que o torna retomavel.
 *
 * E como o ERP ja pensa: `Venda/CriarVendaProdutoServico` recebe
 * `PrimeiroVencimento` + `QuatidadeParcelas` e cria N faturas de UMA venda.
 *
 * ---
 *
 * A SENTINELA 0 em `id_charge` e o ponto delicado, e a razao e especifica: numa
 * UNIQUE do MySQL, NULL nunca colide com NULL. Com `id_charge` NULLable, as
 * linhas da recorrencia (todas NULL naquela coluna) deixariam de ser protegidas
 * pela chave, e a garantia anti-cobranca-dupla — que e o motivo de a UNIQUE
 * existir — sumiria EM SILENCIO. E o mesmo defeito ja documentado no "sem
 * vinculo" duplicado dos dominios de contrato (migration 022).
 *
 * O preco e nao haver FK em `id_charge`. E aceitavel porque cobranca lancada
 * NUNCA e apagada, so cancelada — mesma filosofia das FKs RESTRICT de
 * `crm_invoices`: fatura e registro financeiro e nao some junto com o contrato.
 *
 * A UNIQUE nova e criada ANTES de a velha ser derrubada, e nao depois: a
 * `uk_invoices_contract_competence` e o unico indice que comeca em
 * `id_contract`, e o MySQL recusa dropar um indice de que uma FK depende. Como
 * a nova tambem comeca por `id_contract`, ela assume o papel antes.
 *
 * `crm_contracts_charges` segue a convencao das demais tabelas filhas
 * (`crm_contracts_adjustments`, `_domains`, `_files`, `_services`) e assim deixa
 * visivel no schema a decisao de a cobranca avulsa ser SEMPRE de um contrato —
 * o que preserva a FK e o INNER JOIN da `crm_invoices_v`.
 *
 * Backfill: os DEFAULTs resolvem as linhas existentes (recorrencia = id_charge
 * 0, parcela 1 de 1). Nao ha historico a preservar — a base tinha 1 fatura.
 *
 * @see docs/ROADMAP-FATURAMENTO.md
 */
class Migration_Parcelamento_17_08_26 extends CI_Migration
{
  public function up()
  {
    // --- contrato: em quantas parcelas o valor do ciclo e dividido ---
    if (!$this->db->field_exists('installments', 'crm_contracts')) {
      $this->dbforge->add_column('crm_contracts', [
        'installments' => [
          'type' => 'TINYINT',
          'constraint' => 3,
          'unsigned' => TRUE,
          'null' => FALSE,
          'default' => 1,
          'comment' => 'Parcelas mensais em que o valor do ciclo e dividido. 1 = sem parcelamento.',
          'after' => 'next_competence',
        ],
      ]);
    }

    // --- cobranca avulsa ---
    $this->db->query('CREATE TABLE IF NOT EXISTS `crm_contracts_charges` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(11) unsigned NOT NULL COMMENT \'Tenant dono da cobranca.\',
  `id_customer` int(11) unsigned NOT NULL COMMENT \'Escopo, copiado do contrato.\',
  `id_contract` int(11) unsigned NOT NULL COMMENT \'Cobranca avulsa e SEMPRE de um contrato.\',
  `description` varchar(255) NOT NULL COMMENT \'O que esta sendo cobrado; vai para a descricao das faturas.\',
  `value` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT \'Valor TOTAL da cobranca, antes de dividir.\',
  `installments` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT \'Parcelas mensais.\',
  `competence` date NOT NULL COMMENT \'Periodo da obrigacao, sempre dia 1; compartilhado por todas as parcelas.\',
  `billing_day` tinyint(3) unsigned NOT NULL COMMENT \'Dia do vencimento das parcelas (1-31).\',
  `invoice_policy` varchar(20) NOT NULL DEFAULT \'nao_emitir\' COMMENT \'Snapshot da politica de NF, herdada do contrato no lancamento.\',
  `status` varchar(20) NOT NULL DEFAULT \'lancada\' COMMENT \'lancada | cancelada. Nunca e apagada: e registro financeiro.\',
  `comments` text DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_company` (`id_company`),
  KEY `id_customer` (`id_customer`),
  KEY `id_contract` (`id_contract`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_contracts_charges_ibfk_1` FOREIGN KEY (`id_contract`) REFERENCES `crm_contracts` (`id`),
  CONSTRAINT `crm_contracts_charges_ibfk_2` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`),
  CONSTRAINT `crm_contracts_charges_ibfk_3` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_contracts_charges_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_contracts_charges_ibfk_5` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci');

    // --- fatura: origem e parcela ---
    if (!$this->db->field_exists('id_charge', 'crm_invoices')) {
      $this->dbforge->add_column('crm_invoices', [
        'id_charge' => [
          'type' => 'INT',
          'constraint' => 11,
          'unsigned' => TRUE,
          'null' => FALSE,
          'default' => 0,
          'comment' => '0 = recorrencia do contrato; > 0 = crm_contracts_charges.id. Sentinela, nao NULL: NULL nao colide em UNIQUE.',
          'after' => 'id_contract',
        ],
      ]);
    }

    if (!$this->db->field_exists('installment_number', 'crm_invoices')) {
      $this->dbforge->add_column('crm_invoices', [
        'installment_number' => [
          'type' => 'TINYINT',
          'constraint' => 3,
          'unsigned' => TRUE,
          'null' => FALSE,
          'default' => 1,
          'comment' => 'Numero da parcela dentro da competencia, 1-based.',
          'after' => 'value',
        ],
      ]);
    }

    if (!$this->db->field_exists('installments_total', 'crm_invoices')) {
      $this->dbforge->add_column('crm_invoices', [
        'installments_total' => [
          'type' => 'TINYINT',
          'constraint' => 3,
          'unsigned' => TRUE,
          'null' => FALSE,
          'default' => 1,
          'comment' => 'Total de parcelas, congelado na geracao como o valor.',
          'after' => 'installment_number',
        ],
      ]);
    }

    // Nova PRIMEIRO (ela passa a servir a FK de id_contract), velha depois.
    if (!$this->indiceExiste('crm_invoices', 'uk_invoices_origem')) {
      $this->db->query('ALTER TABLE `crm_invoices`
        ADD UNIQUE KEY `uk_invoices_origem` (`id_contract`,`id_charge`,`competence`,`installment_number`)');
    }

    if ($this->indiceExiste('crm_invoices', 'uk_invoices_contract_competence')) {
      $this->db->query('ALTER TABLE `crm_invoices` DROP INDEX `uk_invoices_contract_competence`');
    }

    if (!$this->indiceExiste('crm_invoices', 'id_charge')) {
      $this->db->query('ALTER TABLE `crm_invoices` ADD KEY `id_charge` (`id_charge`)');
    }

    $this->db->query($this->viewContratos(TRUE));
    $this->db->query($this->viewFaturas(TRUE));
    $this->db->query($this->viewCobrancas());
  }

  public function down()
  {
    $this->db->query('DROP VIEW IF EXISTS `crm_contracts_charges_v`');

    if (!$this->indiceExiste('crm_invoices', 'uk_invoices_contract_competence')) {
      $this->db->query('ALTER TABLE `crm_invoices`
        ADD UNIQUE KEY `uk_invoices_contract_competence` (`id_contract`,`competence`)');
    }

    if ($this->indiceExiste('crm_invoices', 'uk_invoices_origem')) {
      $this->db->query('ALTER TABLE `crm_invoices` DROP INDEX `uk_invoices_origem`');
    }

    if ($this->indiceExiste('crm_invoices', 'id_charge')) {
      $this->db->query('ALTER TABLE `crm_invoices` DROP INDEX `id_charge`');
    }

    // As views voltam ao formato sem as colunas ANTES de elas sumirem: view que
    // referencia coluna inexistente quebra em toda consulta.
    $this->db->query($this->viewContratos(FALSE));
    $this->db->query($this->viewFaturas(FALSE));

    foreach (['id_charge', 'installment_number', 'installments_total'] as $coluna) {
      if ($this->db->field_exists($coluna, 'crm_invoices')) {
        $this->dbforge->drop_column('crm_invoices', $coluna);
      }
    }

    $this->dbforge->drop_table('crm_contracts_charges', TRUE);

    if ($this->db->field_exists('installments', 'crm_contracts')) {
      $this->dbforge->drop_column('crm_contracts', 'installments');
    }
  }

  /**
   * O CI3 nao tem helper de indice, e um ALTER repetido derruba a requisicao.
   *
   * @param  string $tabela
   * @param  string $indice
   * @return bool
   */
  private function indiceExiste($tabela, $indice)
  {
    $consulta = $this->db->query(
      'SELECT COUNT(*) AS total
         FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
      [$tabela, $indice]
    );

    if ($consulta === FALSE) return FALSE;

    $linha = $consulta->row();
    return !empty($linha) && (int) $linha->total > 0;
  }

  /**
   * A crm_contracts_v da 029, com ou sem `installments`.
   *
   * @param  bool $comParcelas
   * @return string
   */
  private function viewContratos($comParcelas)
  {
    $parcelas = $comParcelas ? "  `crm_contracts`.`installments` AS `installments`,\n" : '';

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
" . $parcelas . "  `crm_contracts`.`invoice_policy` AS `invoice_policy`,
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
   * A crm_invoices_v da 029, com ou sem as colunas de origem e parcela.
   *
   * `charge_description` vem por LEFT JOIN porque a fatura de recorrencia tem
   * `id_charge = 0`, que nao casa com nenhuma cobranca — INNER JOIN aqui
   * apagaria da view toda a recorrencia, que e a maioria das linhas.
   *
   * @param  bool $comParcelas
   * @return string
   */
  private function viewFaturas($comParcelas)
  {
    if (!$comParcelas) {
      $colunas = '';
      $join = '';
    } else {
      $colunas = "  `crm_invoices`.`id_charge` AS `id_charge`,\n"
        . "  `crm_invoices`.`installment_number` AS `installment_number`,\n"
        . "  `crm_invoices`.`installments_total` AS `installments_total`,\n"
        . "  `crm_contracts_charges`.`description` AS `charge_description`,\n"
        . "  `crm_contracts_charges`.`status` AS `charge_status`,\n";
      $join = "LEFT JOIN `crm_contracts_charges` ON `crm_contracts_charges`.`id` = `crm_invoices`.`id_charge`\n";
    }

    return "
CREATE OR REPLACE VIEW `crm_invoices_v` AS
SELECT
  `crm_invoices`.`id` AS `id`,
  `crm_invoices`.`id_company` AS `id_company`,
  `crm_invoices`.`id_customer` AS `id_customer`,
  `crm_invoices`.`id_contract` AS `id_contract`,
" . $colunas . "  `crm_invoices`.`competence` AS `competence`,
  `crm_invoices`.`due_date` AS `due_date`,
  `crm_invoices`.`value` AS `value`,
  `crm_invoices`.`status` AS `status`,
  `crm_invoices`.`description` AS `description`,
  `crm_invoices`.`invoice_policy` AS `invoice_policy`,
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
" . $join . "LEFT JOIN `crm_users` ON `crm_users`.`id` = `crm_invoices`.`created_by`
";
  }

  /**
   * Cobrancas avulsas com nome do cliente e do autor, para a listagem da tela
   * do contrato nao fazer N+1 — mesmo motivo das demais views `_v`.
   *
   * @return string
   */
  private function viewCobrancas()
  {
    return "
CREATE OR REPLACE VIEW `crm_contracts_charges_v` AS
SELECT
  `crm_contracts_charges`.`id` AS `id`,
  `crm_contracts_charges`.`id_company` AS `id_company`,
  `crm_contracts_charges`.`id_customer` AS `id_customer`,
  `crm_contracts_charges`.`id_contract` AS `id_contract`,
  `crm_contracts_charges`.`description` AS `description`,
  `crm_contracts_charges`.`value` AS `value`,
  `crm_contracts_charges`.`installments` AS `installments`,
  `crm_contracts_charges`.`competence` AS `competence`,
  `crm_contracts_charges`.`billing_day` AS `billing_day`,
  `crm_contracts_charges`.`invoice_policy` AS `invoice_policy`,
  `crm_contracts_charges`.`status` AS `status`,
  `crm_contracts_charges`.`comments` AS `comments`,
  `crm_contracts_charges`.`created` AS `created`,
  `crm_contracts_charges`.`created_by` AS `created_by`,
  `crm_contracts_charges`.`modified` AS `modified`,
  `crm_contracts_charges`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_users`.`name` AS `created_user`,
  (SELECT COUNT(*) FROM `crm_invoices`
    WHERE `crm_invoices`.`id_charge` = `crm_contracts_charges`.`id`) AS `invoices_count`,
  (SELECT COUNT(*) FROM `crm_invoices`
    WHERE `crm_invoices`.`id_charge` = `crm_contracts_charges`.`id`
      AND `crm_invoices`.`status` = 'paga') AS `invoices_paid_count`
FROM `crm_contracts_charges`
INNER JOIN `crm_customers` ON `crm_customers`.`id` = `crm_contracts_charges`.`id_customer`
LEFT JOIN `crm_users` ON `crm_users`.`id` = `crm_contracts_charges`.`created_by`
";
  }
}
