<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Faturamento próprio: faturas geradas pelo CDW Finance e reajuste anual por
 * índice.
 *
 * Até aqui, quem cobrava era o ERP Bom Controle: cada contrato tem lá um
 * `VendaContrato` que emite a primeira fatura na criação e segue gerando pela
 * periodicidade, e o CDW Finance só lia esse extrato ao vivo. A partir desta
 * migration o sistema passa a ter o próprio motor de cobrança.
 *
 * A TRANSIÇÃO É LONGA E OS DOIS CONVIVEM. Não há virada de chave: contrato a
 * contrato, o faturamento migra do ERP para cá. É por isso que a coluna central
 * é `crm_contracts.billing_source`, e é por isso que o DEFAULT dela é
 * 'bomcontrole' — a base inteira nasce marcada como "cobrada pelo ERP", e o
 * motor só enxerga quem for explicitamente virado. Com o default invertido, a
 * primeira execução do cron geraria 401 faturas para contratos que já estão
 * sendo cobrados lá, e o cliente receberia duas cobranças do mesmo serviço.
 *
 * Sobre `crm_invoices`:
 *  - a UNIQUE (`id_contract`, `competence`) é o que torna o motor idempotente.
 *    Rodar o cron duas vezes no mesmo dia, ou reprocessar um mês, não pode
 *    gerar cobrança em duplicidade — e uma checagem só em PHP não sobrevive a
 *    duas execuções concorrentes.
 *  - `status` NÃO guarda 'vencida': vencida é `due_date < hoje` numa fatura
 *    aberta, muda sozinha com o tempo e não é estado que alguém grava. Gravá-la
 *    exigiria um cron só para reescrever linhas toda madrugada, e qualquer
 *    atraso dele deixaria a tela mentindo. A `crm_invoices_v` deriva isso em
 *    `situation`, no mesmo espírito do `whois_bucket` da crm_servers_domains_v:
 *    listagem, filtro e cor do badge usando o mesmo limiar.
 *  - valor, descrição e política de NF são CONGELADOS na fatura, não lidos do
 *    contrato na hora de exibir: um reajuste em março não pode reescrever o que
 *    foi cobrado em janeiro (mesmo motivo pelo qual post_salvar recusa editar
 *    contrato encerrado).
 *  - a FK do contrato NÃO cascateia: fatura é registro financeiro e não some
 *    junto com o contrato.
 *
 * Sobre `crm_adjustment_indexes` (IGPM/IPCA/ICTI):
 *  - guarda a variação MENSAL, e não o acumulado de 12 meses, porque a janela
 *    do acumulado varia por contrato (aniversário em março usa março-a-
 *    fevereiro; em agosto usa outra). Com o dado mensal qualquer janela é
 *    calculável; com o acumulado, só a que foi gravada serve — e ele mudaria
 *    todo mês de qualquer forma, então nem economizaria lançamentos.
 *  - catálogo GLOBAL, sem `id_company`: o IGPM de março é o mesmo para todo
 *    mundo (mesmo critério de crm_service_types).
 *  - DECIMAL(8,4) porque índice se publica com duas a quatro casas; arredondar
 *    na entrada propaga erro no composto de doze meses. Aceita negativo —
 *    deflação existe.
 *
 * Sobre `crm_contracts_adjustments`: existe porque "por que o valor deste
 * contrato mudou de R$ 200 para R$ 214,60" é a primeira pergunta que o cliente
 * faz, e a resposta precisa sobreviver ao reajuste seguinte. Guardar a JANELA
 * (competence_start/end) junto do percentual é o que permite reconferir a conta
 * anos depois, mesmo que algum índice seja corrigido na tabela.
 *
 * As duas rotinas são registradas em `crm_cron_logs` (padrão da 002): sem a
 * linha, elas não aparecem no painel de CRON e `isCronActive()` as trata como
 * inexistentes.
 */
class Migration_Faturamento_16_08_26 extends CI_Migration
{
  /**
   * @var array
   */
  private $colunasContrato = [
    'billing_source' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => 'bomcontrole',
      'comment' => 'Quem fatura este contrato: bomcontrole | cdwfinance.',
      'after' => 'bomcontrole_linked',
    ],
    'billing_day' => [
      'type' => 'TINYINT',
      'constraint' => 3,
      'unsigned' => TRUE,
      'null' => FALSE,
      'default' => 0,
      'comment' => 'Dia do vencimento (1-31); 0 = nao definido.',
      'after' => 'billing_source',
    ],
    'next_competence' => [
      'type' => 'DATE',
      'null' => TRUE,
      'comment' => 'Proxima competencia a faturar (dia 1); NULL = nao fatura aqui.',
      'after' => 'billing_day',
    ],
    'issue_boleto' => [
      'type' => 'TINYINT',
      'constraint' => 1,
      'null' => FALSE,
      'default' => 0,
      'comment' => 'Emite boleto na cobranca (usado na etapa de emissao).',
      'after' => 'next_competence',
    ],
    'invoice_policy' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => 'nao_emitir',
      'comment' => 'Politica de NF: nao_emitir | com_boleto | pos_compensacao.',
      'after' => 'issue_boleto',
    ],
    'adjustment_index' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => 'nenhum',
      'comment' => 'Indice de reajuste: nenhum | igpm | ipca | icti.',
      'after' => 'invoice_policy',
    ],
    'next_adjustment' => [
      'type' => 'DATE',
      'null' => TRUE,
      'comment' => 'Data do proximo reajuste (aniversario do contrato).',
      'after' => 'adjustment_index',
    ],
    'adjustment_notified_for' => [
      'type' => 'DATE',
      'null' => TRUE,
      'comment' => 'Para qual next_adjustment o aviso ja foi enviado.',
      'after' => 'next_adjustment',
    ],
  ];

  /**
   * @var array
   */
  private $rotinas = ['cron_gerar_faturas', 'cron_reajustar_contratos'];

  public function up()
  {
    foreach ($this->colunasContrato as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->add_column('crm_contracts', [$nome => $definicao]);
      }
    }

    if (!$this->db->table_exists('crm_invoices')) {
      $this->db->query($this->tabelaFaturas());
    }

    if (!$this->db->table_exists('crm_adjustment_indexes')) {
      $this->db->query($this->tabelaIndices());
    }

    if (!$this->db->table_exists('crm_contracts_adjustments')) {
      $this->db->query($this->tabelaReajustes());
    }

    $this->db->query($this->viewContratos(TRUE));
    $this->db->query($this->viewFaturas());
    $this->db->query($this->viewReajustes());

    foreach ($this->rotinas as $rotina) {
      $existente = $this->db->get_where('crm_cron_logs', ['name' => $rotina])->row();
      if (empty($existente)) {
        $this->db->insert('crm_cron_logs', ['name' => $rotina, 'active' => 'S']);
      }
    }
  }

  public function down()
  {
    foreach ($this->rotinas as $rotina) {
      $this->db->where('name', $rotina)->delete('crm_cron_logs');
    }

    $this->db->query('DROP VIEW IF EXISTS `crm_contracts_adjustments_v`');
    $this->db->query('DROP VIEW IF EXISTS `crm_invoices_v`');

    // As tabelas filhas primeiro: a FK de crm_contracts_adjustments aponta para
    // crm_contracts, que continua existindo — mas a ordem evita surpresa se
    // alguma FK for acrescentada depois.
    $this->dbforge->drop_table('crm_contracts_adjustments', TRUE);
    $this->dbforge->drop_table('crm_adjustment_indexes', TRUE);
    $this->dbforge->drop_table('crm_invoices', TRUE);

    // A view do contrato volta ao formato anterior ANTES de as colunas caírem:
    // view apontando para coluna inexistente derruba toda tela que a lê (mesma
    // ordem da 015, da 017, da 019, da 020 e da 023).
    $this->db->query($this->viewContratos(FALSE));

    foreach (array_keys($this->colunasContrato) as $nome) {
      if ($this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->drop_column('crm_contracts', $nome);
      }
    }
  }

  /**
   * @return string
   */
  private function tabelaFaturas()
  {
    return "
CREATE TABLE `crm_invoices` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Tenant dono da fatura.',
  `id_customer` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do contrato para evitar JOIN nas checagens.',
  `id_contract` int(11) unsigned NOT NULL,
  `competence` date NOT NULL COMMENT 'Mes de competencia, sempre dia 1.',
  `due_date` date NOT NULL COMMENT 'Vencimento.',
  `value` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor do ciclo congelado na geracao.',
  `status` varchar(20) NOT NULL DEFAULT 'aberta' COMMENT 'aberta | paga | cancelada. Vencida e DERIVADA (due_date < hoje).',
  `description` varchar(255) DEFAULT NULL COMMENT 'O que esta sendo cobrado, congelado na geracao.',
  `issue_boleto` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Snapshot do contrato.',
  `invoice_policy` varchar(20) NOT NULL DEFAULT 'nao_emitir' COMMENT 'Snapshot da politica de NF do contrato.',
  `comments` text DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoices_contract_competence` (`id_contract`,`competence`),
  KEY `id_company` (`id_company`),
  KEY `id_customer` (`id_customer`),
  KEY `status` (`status`),
  KEY `due_date` (`due_date`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_invoices_ibfk_1` FOREIGN KEY (`id_contract`) REFERENCES `crm_contracts` (`id`),
  CONSTRAINT `crm_invoices_ibfk_2` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`),
  CONSTRAINT `crm_invoices_ibfk_3` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_invoices_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_invoices_ibfk_5` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
  }

  /**
   * @return string
   */
  private function tabelaIndices()
  {
    return "
CREATE TABLE `crm_adjustment_indexes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `index_slug` varchar(20) NOT NULL COMMENT 'igpm | ipca | icti.',
  `competence` date NOT NULL COMMENT 'Mes de referencia, sempre dia 1.',
  `rate` decimal(8,4) NOT NULL COMMENT 'Variacao percentual DO MES; negativo = deflacao.',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_indexes_slug_competence` (`index_slug`,`competence`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_adjustment_indexes_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_adjustment_indexes_ibfk_2` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
  }

  /**
   * @return string
   */
  private function tabelaReajustes()
  {
    return "
CREATE TABLE `crm_contracts_adjustments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_contract` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado do contrato.',
  `applied_at` date NOT NULL COMMENT 'Data em que o reajuste passou a valer.',
  `index_slug` varchar(20) NOT NULL,
  `rate` decimal(8,4) NOT NULL COMMENT 'Percentual ACUMULADO aplicado.',
  `competence_start` date NOT NULL COMMENT 'Primeiro mes da janela de 12 usada.',
  `competence_end` date NOT NULL COMMENT 'Ultimo mes da janela de 12 usada.',
  `value_before` decimal(12,2) NOT NULL,
  `value_after` decimal(12,2) NOT NULL,
  `notified` datetime DEFAULT NULL COMMENT 'Quando o aviso ao cliente foi enfileirado.',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_contract` (`id_contract`),
  KEY `id_company` (`id_company`),
  KEY `applied_at` (`applied_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_contracts_adjustments_ibfk_1` FOREIGN KEY (`id_contract`) REFERENCES `crm_contracts` (`id`),
  CONSTRAINT `crm_contracts_adjustments_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_contracts_adjustments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
  }

  /**
   * A crm_contracts_v da 020, com ou sem as colunas de faturamento.
   *
   * @param  bool $comFaturamento
   * @return string
   */
  private function viewContratos($comFaturamento)
  {
    $faturamento = $comFaturamento
      ? "  `crm_contracts`.`billing_source` AS `billing_source`,\n"
      . "  `crm_contracts`.`billing_day` AS `billing_day`,\n"
      . "  `crm_contracts`.`next_competence` AS `next_competence`,\n"
      . "  `crm_contracts`.`issue_boleto` AS `issue_boleto`,\n"
      . "  `crm_contracts`.`invoice_policy` AS `invoice_policy`,\n"
      . "  `crm_contracts`.`adjustment_index` AS `adjustment_index`,\n"
      . "  `crm_contracts`.`next_adjustment` AS `next_adjustment`,\n"
      . "  `crm_contracts`.`adjustment_notified_for` AS `adjustment_notified_for`,\n"
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
{$faturamento}  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `u_created`.`name` AS `created_user`,
  `u_ended`.`name` AS `ended_user`
FROM (((`crm_contracts`
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_contracts`.`id_customer`))
  LEFT JOIN `crm_users` `u_created` ON(`u_created`.`id` = `crm_contracts`.`created_by`))
  LEFT JOIN `crm_users` `u_ended` ON(`u_ended`.`id` = `crm_contracts`.`ended_by`))
";
  }

  /**
   * `situation` é derivada aqui, e não no controller, pelo mesmo motivo do
   * `whois_bucket` da crm_servers_domains_v: listagem, filtro e cor do badge
   * precisam do MESMO limiar. Calcular em três lugares é garantir que um deles
   * discorde.
   *
   * @return string
   */
  private function viewFaturas()
  {
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
  `crm_invoices`.`issue_boleto` AS `issue_boleto`,
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
  (CASE
     WHEN `crm_invoices`.`status` = 'cancelada' THEN 'cancelada'
     WHEN `crm_invoices`.`status` = 'paga' THEN 'paga'
     WHEN `crm_invoices`.`due_date` < CURDATE() THEN 'vencida'
     ELSE 'a_vencer'
   END) AS `situation`
FROM (((`crm_invoices`
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_invoices`.`id_customer`))
  JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_invoices`.`id_contract`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_invoices`.`created_by`))
";
  }

  /**
   * @return string
   */
  private function viewReajustes()
  {
    return "
CREATE OR REPLACE VIEW `crm_contracts_adjustments_v` AS
SELECT
  `crm_contracts_adjustments`.`id` AS `id`,
  `crm_contracts_adjustments`.`id_contract` AS `id_contract`,
  `crm_contracts_adjustments`.`id_company` AS `id_company`,
  `crm_contracts_adjustments`.`applied_at` AS `applied_at`,
  `crm_contracts_adjustments`.`index_slug` AS `index_slug`,
  `crm_contracts_adjustments`.`rate` AS `rate`,
  `crm_contracts_adjustments`.`competence_start` AS `competence_start`,
  `crm_contracts_adjustments`.`competence_end` AS `competence_end`,
  `crm_contracts_adjustments`.`value_before` AS `value_before`,
  `crm_contracts_adjustments`.`value_after` AS `value_after`,
  `crm_contracts_adjustments`.`notified` AS `notified`,
  `crm_contracts_adjustments`.`created` AS `created`,
  `crm_contracts_adjustments`.`created_by` AS `created_by`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_users`.`name` AS `created_user`
FROM (((`crm_contracts_adjustments`
  JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_adjustments`.`id_contract`))
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_contracts`.`id_customer`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts_adjustments`.`created_by`))
";
  }
}
