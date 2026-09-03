<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Conta a receber no ERP quando a cobrança é liquidada (etapa J).
 *
 * O dinheiro entra pela conta bancária e alguém precisa conciliá-lo, no Bom
 * Controle, contra um título. Hoje esse título **não existe** para parte da
 * carteira, e é esse buraco que a etapa fecha.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * A REGRA QUE DECIDE TUDO: SÓ `nao_emitir` GANHA TÍTULO AQUI
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Quem emite nota **já tem título no ERP**, e criar outro dobraria a receita.
 * O `CriarVendaProdutoServico` da etapa E cria a venda **e as parcelas no
 * financeiro** — é exatamente por isso que existe o terceiro passo daquela
 * rotina, o `Fatura/EfeturarPagamento`, sem o qual "o BC acumula recebíveis
 * fantasmas, um por fatura". Somar um `CriarOutroRecebimento` a isso faria a
 * mesma fatura aparecer duas vezes no financeiro do ERP.
 *
 * Sobra `invoice_policy = 'nao_emitir'`: nenhuma venda é criada, o ERP não
 * sabe que a fatura existe, e o crédito no extrato bancário chega sem
 * contrapartida. Na distribuição de produção informada em 19/08/2026 são os
 * **35%** da carteira.
 *
 * O erro tem lados MUITO diferentes de custo, e é o que fixa o default:
 * título a menos aparece na conciliação como crédito sem contrapartida e se
 * lança à mão; título a mais infla a receita do ERP em silêncio e só é
 * percebido no fechamento. A guarda mora em `enfileirarRecebimento()`, ponto
 * único, no molde do `enfileirarNota()`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * COMO A FATURA DAQUI É IDENTIFICADA NO ERP
 * ─────────────────────────────────────────────────────────────────────────
 *
 * O vínculo é de **mão dupla**, e cada ponta serve a um lado da conciliação:
 *
 *  - **daqui para lá**: `NumeroDocumento` recebe o `crm_invoices.id`. O campo
 *    é documentado como **Inteiro** — a PK cabe nele sem conversão —, e volta
 *    no `Financeiro/Pesquisar` e no `PesquisaDetalhada` (verificado no
 *    collection). É o que permite achar o título a partir da fatura;
 *  - **de lá para cá**: `IdMovimentacaoFinanceira` e
 *    `IdMovimentacaoFinanceiraParcela` são gravados em `crm_invoices`. São
 *    **GUIDs** (a doc os descreve como Texto), daí `varchar(36)` e não `int`.
 *    A parcela é a chave do `Financeiro/Obter`.
 *
 * `NumeroDocumento` NÃO é filtro de busca em endpoint nenhum — o
 * `textoPesquisa` procura pelo *nome da parcela*. Achar um título pelo id da
 * fatura significa varrer a janela de datas e casar no PHP, que é o mesmo
 * padrão do `conciliarPeriodo()`. Por isso o GUID gravado aqui é a âncora
 * real, e o `NumeroDocumento` é o que salva quando ele se perde.
 *
 * A `Observacao` leva o mesmo id em texto: é o que o operador enxerga na tela
 * do ERP, e sem ele o título seria só "um recebimento de R$ X" no meio de
 * dezenas iguais.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * O TÍTULO NASCE EM ABERTO, NÃO QUITADO
 * ─────────────────────────────────────────────────────────────────────────
 *
 * `CriarOutroRecebimento` cria a movimentação com a parcela **prevista**; a
 * quitação é o `Financeiro/EfetuarPagamento`, que esta etapa **não** chama.
 * É deliberado: o título existe justamente para o crédito do extrato ter
 * contra o que ser conciliado, e um título já quitado não aparece como
 * pendência de conciliação. Quitar aqui também assumiria que o dinheiro já
 * caiu na conta, e o PSP confirma o PAGAMENTO — a liquidação bancária vem
 * depois.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * O RESTO
 * ─────────────────────────────────────────────────────────────────────────
 *
 * `receivable_status` repete a máquina de estados do `nf_status` pelo mesmo
 * motivo: `criado` com o GUID gravado significa "não criar de novo". Sem esse
 * estado, uma falha de rede depois do POST faria a retentativa criar um
 * segundo título — o mesmo defeito que o `bomcontrole_sale_id` evita na nota.
 *
 * `IdContaFinanceira` e `IdCategoriaFinanceira` são **obrigatórios** no
 * payload e não têm como ser adivinhados — mas eles NÃO moram no mesmo lugar,
 * e a diferença é de natureza:
 *
 *  - **a CONTA é do tenant** (`crm_companies`), ao lado do
 *    `bomcontrole_company_id` da 039: é a conta bancária da empresa, onde o
 *    dinheiro entra. Não varia de contrato para contrato;
 *  - **a CATEGORIA é do CONTRATO** (`crm_contracts`), ao lado do
 *    `bomcontrole_service_id` da 025. Ela classifica a RECEITA, e isso varia
 *    por contrato — é como a operação já faz hoje, direto no Bom Controle.
 *
 * Um padrão por tenant foi descartado de propósito. Ele pareceria conveniente
 * (contrato sem categoria continuaria gerando título), mas seria um conceito
 * que a operação não tem, e o efeito de esquecer de preencher seria jogar a
 * receita daquele contrato numa categoria errada **em silêncio** — o mesmo
 * tipo de erro que só aparece no fechamento do mês. Sem categoria no
 * contrato, a fila **recusa e diz o motivo**, que é o comportamento do
 * `bomcontrole_service_id` na emissão da nota.
 *
 * Os dois guardam o nome junto do id pelo motivo do
 * `bomcontrole_service_name`: a tela mostra o vínculo sem ir à rede, e **o id
 * é a verdade, o nome é retrato**.
 *
 * As TRÊS views são recriadas porque as colunas aparecem nas três
 * (`crm_companies_v`, `crm_contracts_v` e `crm_invoices_v`) — a armadilha que
 * a 039 quase repetiu ao esquecer a `crm_companies_v`.
 *
 * @see docs/ROADMAP-FATURAMENTO.md — etapa J
 */
class Migration_Recebimento_erp_30_08_26 extends CI_Migration
{
  /**
   * @var string
   */
  private $rotina = 'cron_criar_recebimentos';

  public function up()
  {
    $colunasEmpresa = [
      'bomcontrole_account_id' => [
        'type' => 'INT',
        'constraint' => 11,
        'unsigned' => TRUE,
        'null' => TRUE,
        'comment' => 'IdContaFinanceira do BC: em qual conta o recebimento entra.',
        'after' => 'bomcontrole_company_id',
      ],
      'bomcontrole_account_name' => [
        'type' => 'VARCHAR',
        'constraint' => 120,
        'null' => TRUE,
        'comment' => 'Retrato do nome da conta; o id é a verdade.',
        'after' => 'bomcontrole_account_id',
      ],
    ];

    foreach ($colunasEmpresa as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_companies')) {
        $this->dbforge->add_column('crm_companies', [$nome => $definicao]);
      }
    }

    // A categoria é do CONTRATO, ao lado do serviço do ERP — ela classifica a
    // receita, e isso varia por contrato.
    $colunasContrato = [
      'bomcontrole_category_id' => [
        'type' => 'INT',
        'constraint' => 11,
        'unsigned' => TRUE,
        'null' => TRUE,
        'comment' => 'IdCategoriaFinanceira do BC (receita) deste contrato.',
        'after' => 'bomcontrole_service_name',
      ],
      'bomcontrole_category_name' => [
        'type' => 'VARCHAR',
        'constraint' => 120,
        'null' => TRUE,
        'comment' => 'Retrato do nome da categoria; o id é a verdade.',
        'after' => 'bomcontrole_category_id',
      ],
    ];

    foreach ($colunasContrato as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->add_column('crm_contracts', [$nome => $definicao]);
      }
    }

    // Auto-correção: uma versão anterior desta migration pôs a categoria em
    // `crm_companies`. Quem já a aplicou tem as colunas lá, e duas verdades
    // sobre a mesma configuração é pior que nenhuma.
    foreach (['bomcontrole_category_name', 'bomcontrole_category_id'] as $obsoleta) {
      if ($this->db->field_exists($obsoleta, 'crm_companies')) {
        $this->dbforge->drop_column('crm_companies', $obsoleta);
      }
    }

    $colunasFatura = [
      'receivable_status' => [
        'type' => 'VARCHAR',
        'constraint' => 20,
        'null' => FALSE,
        'default' => '',
        'comment' => 'Vazio = fora da fila. pendente | criado | falha.',
        'after' => 'nf_sent_at',
      ],
      'receivable_attempts' => [
        'type' => 'TINYINT',
        'constraint' => 3,
        'unsigned' => TRUE,
        'null' => FALSE,
        'default' => 0,
        'comment' => 'Tentativas de criação; teto vira falha.',
        'after' => 'receivable_status',
      ],
      'receivable_last_error' => [
        'type' => 'VARCHAR',
        'constraint' => 255,
        'null' => TRUE,
        'comment' => 'Motivo da última falha, para a tela.',
        'after' => 'receivable_attempts',
      ],
      'receivable_created_at' => [
        'type' => 'DATETIME',
        'null' => TRUE,
        'comment' => 'Quando o título foi criado no ERP.',
        'after' => 'receivable_last_error',
      ],
      'bomcontrole_movement_id' => [
        'type' => 'VARCHAR',
        'constraint' => 36,
        'null' => TRUE,
        'comment' => 'IdMovimentacaoFinanceira (GUID). Preenchido = já criado.',
        'after' => 'receivable_created_at',
      ],
      'bomcontrole_installment_id' => [
        'type' => 'VARCHAR',
        'constraint' => 36,
        'null' => TRUE,
        'comment' => 'IdMovimentacaoFinanceiraParcela (GUID): chave do Financeiro/Obter.',
        'after' => 'bomcontrole_movement_id',
      ],
    ];

    foreach ($colunasFatura as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_invoices')) {
        $this->dbforge->add_column('crm_invoices', [$nome => $definicao]);
      }
    }

    // A fila é "aberta pela coluna", como todas as outras deste módulo: não há
    // tabela de fila, e o índice é o que impede a varredura completa.
    $this->indice(
      'crm_invoices',
      'idx_invoices_receivable',
      'CREATE INDEX `idx_invoices_receivable` ON `crm_invoices` (`receivable_status`, `id`)'
    );

    $this->db->query($this->viewEmpresas(TRUE));
    $this->db->query($this->viewContratos(TRUE));
    $this->db->query($this->viewFaturas(TRUE));

    $existente = $this->db->get_where('crm_cron_logs', ['name' => $this->rotina])->row();

    if (empty($existente)) {
      $this->db->insert('crm_cron_logs', ['name' => $this->rotina, 'active' => 'S']);
    }
  }

  public function down()
  {
    // As views voltam ANTES do DROP: enquanto elas citarem a coluna, o SELECT
    // quebra no instante em que o ALTER passa (lição da 044).
    $this->db->query($this->viewEmpresas(FALSE));
    $this->db->query($this->viewContratos(FALSE));
    $this->db->query($this->viewFaturas(FALSE));

    foreach (['bomcontrole_installment_id', 'bomcontrole_movement_id', 'receivable_created_at',
              'receivable_last_error', 'receivable_attempts', 'receivable_status'] as $coluna) {
      if ($this->db->field_exists($coluna, 'crm_invoices')) {
        $this->dbforge->drop_column('crm_invoices', $coluna);
      }
    }

    foreach (['bomcontrole_category_name', 'bomcontrole_category_id'] as $coluna) {
      if ($this->db->field_exists($coluna, 'crm_contracts')) {
        $this->dbforge->drop_column('crm_contracts', $coluna);
      }
    }

    foreach (['bomcontrole_account_name', 'bomcontrole_account_id'] as $coluna) {
      if ($this->db->field_exists($coluna, 'crm_companies')) {
        $this->dbforge->drop_column('crm_companies', $coluna);
      }
    }

    $this->db->where('name', $this->rotina)->delete('crm_cron_logs');
  }

  /**
   * Cria o índice só se ainda não existir (a migration é idempotente).
   *
   * @param  string $tabela
   * @param  string $nome
   * @param  string $sql
   * @return void
   */
  private function indice($tabela, $nome, $sql)
  {
    $existente = $this->db->query(
      'SELECT COUNT(*) AS `n` FROM `information_schema`.`statistics`
        WHERE `table_schema` = DATABASE() AND `table_name` = ? AND `index_name` = ?',
      [$tabela, $nome]
    )->row();

    if (empty($existente) || (int) $existente->n === 0) {
      $this->db->query($sql);
    }
  }

  /**
   * A crm_companies_v da 039, com ou sem as quatro colunas novas.
   *
   * @param  bool $comColunas
   * @return string
   */
  private function viewEmpresas($comColunas)
  {
    $novas = $comColunas
      ? "  `crm_companies`.`bomcontrole_account_id` AS `bomcontrole_account_id`,\n"
      . "  `crm_companies`.`bomcontrole_account_name` AS `bomcontrole_account_name`,\n"
      : '';

    return "
CREATE OR REPLACE VIEW `crm_companies_v` AS
SELECT
  `crm_companies`.`id` AS `id`,
  `crm_companies`.`id_status` AS `id_status`,
  `crm_companies`.`cnpj` AS `cnpj`,
  `crm_companies`.`name` AS `name`,
  `crm_companies`.`byname` AS `byname`,
  `crm_companies`.`address` AS `address`,
  `crm_companies`.`address_number` AS `address_number`,
  `crm_companies`.`address_complement` AS `address_complement`,
  `crm_companies`.`address_district` AS `address_district`,
  `crm_companies`.`address_zip` AS `address_zip`,
  `crm_companies`.`id_state` AS `id_state`,
  `crm_companies`.`id_city` AS `id_city`,
  `crm_companies`.`phone` AS `phone`,
  `crm_companies`.`owner` AS `owner`,
  `crm_companies`.`owner_cellphone` AS `owner_cellphone`,
  `crm_companies`.`alias` AS `alias`,
  `crm_companies`.`email` AS `email`,
  `crm_companies`.`token` AS `token`,
  `crm_companies`.`last_login` AS `last_login`,
  `crm_companies`.`bomcontrole_active` AS `bomcontrole_active`,
  `crm_companies`.`bomcontrole_base_url` AS `bomcontrole_base_url`,
  `crm_companies`.`bomcontrole_company_id` AS `bomcontrole_company_id`,
" . $novas . "  `crm_companies`.`created` AS `created`,
  `crm_companies`.`created_by` AS `created_by`,
  `crm_companies`.`modified` AS `modified`,
  `crm_companies`.`modified_by` AS `modified_by`,
  `crm_country_cities`.`name` AS `city_name`,
  `crm_country_states`.`name` AS `state_name`,
  `crm_country_states`.`uf` AS `state_uf`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`
FROM `crm_companies`
JOIN `crm_status` ON `crm_status`.`id` = `crm_companies`.`id_status`
JOIN `crm_country_cities` ON `crm_country_cities`.`id` = `crm_companies`.`id_city`
JOIN `crm_country_states` ON `crm_country_states`.`id` = `crm_companies`.`id_state`
";
  }

  /**
   * A crm_contracts_v da 031, com ou sem as duas colunas da categoria.
   *
   * Ela não é recriada desde a 031 e tem 35 colunas — por isso vem transcrita
   * inteira, e não montada por diff: um SELECT * aqui congelaria a ordem das
   * colunas de um jeito que a próxima migration teria de adivinhar.
   *
   * @param  bool $comColunas
   * @return string
   */
  private function viewContratos($comColunas)
  {
    $novas = $comColunas
      ? "  `crm_contracts`.`bomcontrole_category_id` AS `bomcontrole_category_id`,\n"
      . "  `crm_contracts`.`bomcontrole_category_name` AS `bomcontrole_category_name`,\n"
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
  `crm_contracts`.`psp` AS `psp`,
  `crm_contracts`.`billing_day` AS `billing_day`,
  `crm_contracts`.`next_competence` AS `next_competence`,
  `crm_contracts`.`installments` AS `installments`,
  `crm_contracts`.`invoice_policy` AS `invoice_policy`,
  `crm_contracts`.`notification_config` AS `notification_config`,
  `crm_contracts`.`adjustment_index` AS `adjustment_index`,
  `crm_contracts`.`next_adjustment` AS `next_adjustment`,
  `crm_contracts`.`adjustment_notified_for` AS `adjustment_notified_for`,
  `crm_contracts`.`bomcontrole_service_id` AS `bomcontrole_service_id`,
  `crm_contracts`.`bomcontrole_service_name` AS `bomcontrole_service_name`,
" . $novas . "  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `u_created`.`name` AS `created_user`,
  `u_ended`.`name` AS `ended_user`
FROM `crm_contracts`
JOIN `crm_customers` ON `crm_customers`.`id` = `crm_contracts`.`id_customer`
LEFT JOIN `crm_users` `u_created` ON `u_created`.`id` = `crm_contracts`.`created_by`
LEFT JOIN `crm_users` `u_ended` ON `u_ended`.`id` = `crm_contracts`.`ended_by`
";
  }

  /**
   * A crm_invoices_v da 039, com ou sem as seis colunas novas.
   *
   * `registration` e `situation` continuam derivadas aqui pelo motivo de
   * sempre: badge, fila e filtro precisam do mesmo limiar.
   *
   * @param  bool $comColunas
   * @return string
   */
  private function viewFaturas($comColunas)
  {
    $novas = $comColunas
      ? "  `crm_invoices`.`receivable_status` AS `receivable_status`,\n"
      . "  `crm_invoices`.`receivable_attempts` AS `receivable_attempts`,\n"
      . "  `crm_invoices`.`receivable_last_error` AS `receivable_last_error`,\n"
      . "  `crm_invoices`.`receivable_created_at` AS `receivable_created_at`,\n"
      . "  `crm_invoices`.`bomcontrole_movement_id` AS `bomcontrole_movement_id`,\n"
      . "  `crm_invoices`.`bomcontrole_installment_id` AS `bomcontrole_installment_id`,\n"
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
  `crm_invoices`.`nf_status` AS `nf_status`,
  `crm_invoices`.`nf_attempts` AS `nf_attempts`,
  `crm_invoices`.`nf_last_error` AS `nf_last_error`,
  `crm_invoices`.`nf_issued_at` AS `nf_issued_at`,
  `crm_invoices`.`bomcontrole_sale_id` AS `bomcontrole_sale_id`,
  `crm_invoices`.`bomcontrole_invoice_id` AS `bomcontrole_invoice_id`,
  `crm_invoices`.`link_nota_fiscal` AS `link_nota_fiscal`,
  `crm_invoices`.`link_nota_fiscal_xml` AS `link_nota_fiscal_xml`,
  `crm_invoices`.`nf_sent_at` AS `nf_sent_at`,
" . $novas . "  `crm_invoices`.`comments` AS `comments`,
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
    WHEN `crm_invoices`.`psp` = '' THEN 'sem_psp'
    WHEN `crm_invoices`.`psp_charge_id` IS NULL OR `crm_invoices`.`psp_charge_id` = '' THEN 'nao_registrada'
    WHEN (`crm_invoices`.`linha_digitavel` IS NULL OR `crm_invoices`.`linha_digitavel` = '')
     AND (`crm_invoices`.`link_pix` IS NULL OR `crm_invoices`.`link_pix` = '') THEN 'registrando'
    ELSE 'registrada'
  END AS `registration`,
  CASE
    WHEN `crm_invoices`.`status` = 'cancelada' THEN 'cancelada'
    WHEN `crm_invoices`.`status` = 'paga' THEN 'paga'
    WHEN `crm_invoices`.`due_date` < CURDATE() THEN 'vencida'
    ELSE 'a_vencer'
  END AS `situation`
FROM `crm_invoices`
JOIN `crm_customers` ON `crm_customers`.`id` = `crm_invoices`.`id_customer`
JOIN `crm_contracts` ON `crm_contracts`.`id` = `crm_invoices`.`id_contract`
LEFT JOIN `crm_contracts_charges` ON `crm_contracts_charges`.`id` = `crm_invoices`.`id_charge`
LEFT JOIN `crm_users` ON `crm_users`.`id` = `crm_invoices`.`created_by`
";
  }
}
