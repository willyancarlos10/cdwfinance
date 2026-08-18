<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Vínculo do contrato com o serviço do catálogo do Bom Controle.
 *
 * A emissão da cobrança no ERP (`Venda/Criar`) exige `Servicos[].IdServico` — o
 * Id de um serviço do catálogo DE LÁ, que não tem relação com o nosso
 * `crm_service_types`. O catálogo do ERP é bem mais granular (119 serviços
 * contra os nossos 12 tipos) e os nomes não casam automaticamente: o nosso
 * "Site institucional" corresponde a "SUPORTE TÉCNICO PARA SITE - MENSAL" lá.
 *
 * O vínculo mora no CONTRATO, e não no tipo de serviço, por duas razões:
 *
 *  - `crm_service_types` é catálogo GLOBAL (sem `id_company`), mas o catálogo do
 *    ERP é por tenant — cada empresa tem a própria conta e os próprios ids.
 *    Vincular lá exigiria uma tabela de ligação com `id_company`; aqui o escopo
 *    já vem de graça, porque o contrato tem tenant.
 *
 *  - A fatura tem UM valor, mas 215 dos 392 contratos têm dois tipos de serviço
 *    e nove têm três. Se o vínculo fosse por tipo, a venda no ERP precisaria de
 *    vários itens e de um rateio do valor entre eles — rateio que ninguém
 *    informou e que apareceria na nota fiscal como se fosse real. Com um
 *    serviço por contrato, a venda tem um item só, com o valor da fatura.
 *
 * `bomcontrole_service_name` guarda o nome do serviço no momento do vínculo.
 * Não é redundância: a tela precisa exibir o que está vinculado, e o rate limit
 * do ERP é agressivo o bastante para inviabilizar uma consulta por exibição
 * (uma dúzia de chamadas seguidas já devolve 429). O Id é a verdade; o nome é
 * retrato para leitura, e é reescrito a cada revínculo.
 *
 * Não há coluna de data do vínculo, diferente do `bomcontrole_linked` da 019:
 * lá ela responde "desde quando este contrato lê extrato do ERP", que é
 * informação de diagnóstico; aqui não haveria pergunta que ela respondesse.
 */
class Migration_Contrato_servico_bc_16_08_26 extends CI_Migration
{
  /**
   * @var array
   */
  private $colunas = [
    'bomcontrole_service_id' => [
      'type' => 'INT',
      'constraint' => 11,
      'unsigned' => TRUE,
      'null' => TRUE,
      'comment' => 'Id do Servico no catalogo do ERP Bom Controle; NULL = sem vinculo.',
      'after' => 'adjustment_notified_for',
    ],
    'bomcontrole_service_name' => [
      'type' => 'VARCHAR',
      'constraint' => 200,
      'null' => TRUE,
      'comment' => 'Nome do servico no momento do vinculo (retrato para a tela; o Id e a verdade).',
      'after' => 'bomcontrole_service_id',
    ],
  ];

  public function up()
  {
    foreach ($this->colunas as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->add_column('crm_contracts', [$nome => $definicao]);
      }
    }

    $this->db->query($this->viewContratos(TRUE));
  }

  public function down()
  {
    // A view volta ao formato anterior ANTES de as colunas caírem: view
    // apontando para coluna inexistente derruba toda tela que a lê.
    $this->db->query($this->viewContratos(FALSE));

    foreach (array_keys($this->colunas) as $nome) {
      if ($this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->drop_column('crm_contracts', $nome);
      }
    }
  }

  /**
   * A crm_contracts_v da 024, com ou sem as colunas do serviço.
   *
   * @param  bool $comServico
   * @return string
   */
  private function viewContratos($comServico)
  {
    $servico = $comServico
      ? "  `crm_contracts`.`bomcontrole_service_id` AS `bomcontrole_service_id`,\n"
      . "  `crm_contracts`.`bomcontrole_service_name` AS `bomcontrole_service_name`,\n"
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
  `crm_contracts`.`issue_boleto` AS `issue_boleto`,
  `crm_contracts`.`invoice_policy` AS `invoice_policy`,
  `crm_contracts`.`adjustment_index` AS `adjustment_index`,
  `crm_contracts`.`next_adjustment` AS `next_adjustment`,
  `crm_contracts`.`adjustment_notified_for` AS `adjustment_notified_for`,
{$servico}  `crm_contracts`.`created` AS `created`,
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
}
