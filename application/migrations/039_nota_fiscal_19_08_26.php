<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Emissão da nota fiscal pelo ERP (etapa E).
 *
 * O CDW Finance cobra; o Bom Controle emite a nota. São três chamadas
 * encadeadas, e é por isso que a emissão é FILA e não acontece no webhook:
 *
 *   1. POST Venda/CriarVendaProdutoServico  → Id da Venda
 *   2. GET  Venda/Obter/{id}                → Id da Fatura (o Criar não devolve)
 *   3. PUT  Fatura/EfeturarPagamento/{id}   → baixa no financeiro do ERP
 *
 * O passo 3 existe porque `CriarVendaProdutoServico` cria a venda **e as
 * parcelas** no financeiro do BC. Como o dinheiro já entrou pelo PSP, essa
 * parcela é um recebível que nunca seria quitado lá — e o financeiro do ERP
 * passaria a mostrar títulos em aberto fantasmas, um por mês por contrato.
 *
 * SOBRE `nf_status`, e por que ele tem um estado no meio:
 *
 *   pendente      → entrou na fila, ainda não emitida
 *   venda_criada  → passo 1 e 2 OK; falta a baixa no ERP (passo 3)
 *   emitida       → os três passos concluídos
 *   falha         → erro DEFINITIVO: para de tentar e espera gente
 *
 * `venda_criada` não é preciosismo: se a baixa falhar depois de a venda ter
 * sido criada, **a retentativa não pode recriar a venda** — isso emitiria uma
 * SEGUNDA nota fiscal para a mesma fatura, e nota não se cancela com DELETE,
 * se corrige com carta de correção. Com o estado separado, a fila retenta
 * apenas o passo 3.
 *
 * `nf_attempts` + `nf_last_error` distinguem falha TEMPORÁRIA (prefeitura fora
 * do ar, 429 do ERP → retenta) de DEFINITIVA (cliente sem inscrição, serviço
 * inválido → para). Retentar em laço um erro definitivo queima quota e esconde
 * o problema.
 *
 * `crm_companies.bomcontrole_company_id` é o `IdEmpresa`, obrigatório no
 * `CriarVendaProdutoServico`. Fica por TENANT, ao lado da chave do ERP, pelo
 * mesmo motivo dela: é a identidade daquela empresa no Bom Controle.
 *
 * @see docs/ROADMAP-FATURAMENTO.md — etapa E
 */
class Migration_Nota_fiscal_19_08_26 extends CI_Migration
{
  /**
   * @var array
   */
  private $colunasFatura = [
    'nf_status' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => '',
      'comment' => 'pendente | venda_criada | emitida | falha. Vazio = fora da fila.',
      'after' => 'sent_at',
    ],
    'nf_attempts' => [
      'type' => 'TINYINT',
      'constraint' => 3,
      'unsigned' => TRUE,
      'null' => FALSE,
      'default' => 0,
      'comment' => 'Tentativas da fila; teto separa falha temporaria de definitiva.',
      'after' => 'nf_status',
    ],
    'nf_last_error' => [
      'type' => 'VARCHAR',
      'constraint' => 255,
      'null' => TRUE,
      'comment' => 'Ultimo erro da emissao, para a tela explicar sem abrir o log.',
      'after' => 'nf_attempts',
    ],
    'nf_issued_at' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'after' => 'nf_last_error',
    ],
    'bomcontrole_sale_id' => [
      'type' => 'INT',
      'constraint' => 11,
      'unsigned' => TRUE,
      'null' => TRUE,
      'comment' => 'Id da Venda no ERP. Preenchido = NAO recriar a venda.',
      'after' => 'nf_issued_at',
    ],
    'bomcontrole_invoice_id' => [
      'type' => 'INT',
      'constraint' => 11,
      'unsigned' => TRUE,
      'null' => TRUE,
      'comment' => 'Id da Fatura no ERP, alvo do EfeturarPagamento.',
      'after' => 'bomcontrole_sale_id',
    ],
    'link_nota_fiscal' => [
      'type' => 'VARCHAR',
      'constraint' => 255,
      'null' => TRUE,
      'comment' => 'PDF da NFS-e.',
      'after' => 'bomcontrole_invoice_id',
    ],
    'link_nota_fiscal_xml' => [
      'type' => 'VARCHAR',
      'constraint' => 255,
      'null' => TRUE,
      'comment' => 'XML da NFS-e.',
      'after' => 'link_nota_fiscal',
    ],
    'nf_sent_at' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Quando a nota foi enviada ao cliente (etapa F).',
      'after' => 'link_nota_fiscal_xml',
    ],
  ];

  public function up()
  {
    if (!$this->db->field_exists('bomcontrole_company_id', 'crm_companies')) {
      $this->dbforge->add_column('crm_companies', [
        'bomcontrole_company_id' => [
          'type' => 'INT',
          'constraint' => 11,
          'unsigned' => TRUE,
          'null' => TRUE,
          'comment' => 'IdEmpresa no Bom Controle, obrigatorio na emissao da venda.',
          'after' => 'bomcontrole_secret',
        ],
      ]);
    }

    foreach ($this->colunasFatura as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_invoices')) {
        $this->dbforge->add_column('crm_invoices', [$nome => $definicao]);
      }
    }

    // A fila procura por aqui: status + tentativas.
    $this->criarIndice('crm_invoices', 'idx_invoices_nf', '`nf_status`,`nf_attempts`');

    $this->db->query($this->viewFaturas(TRUE));
    $this->db->query($this->viewEmpresas(TRUE));

    $existente = $this->db->get_where('crm_cron_logs', ['name' => 'cron_emitir_notas'])->row();
    if (empty($existente)) {
      $this->db->insert('crm_cron_logs', ['name' => 'cron_emitir_notas', 'active' => 'S']);
    }
  }

  public function down()
  {
    $this->db->where('name', 'cron_emitir_notas')->delete('crm_cron_logs');

    // A view volta ao formato anterior ANTES de as colunas caírem: view
    // apontando para coluna inexistente derruba toda tela que a lê.
    $this->db->query($this->viewFaturas(FALSE));
    $this->db->query($this->viewEmpresas(FALSE));

    $this->removerIndice('crm_invoices', 'idx_invoices_nf');

    foreach (array_keys($this->colunasFatura) as $nome) {
      if ($this->db->field_exists($nome, 'crm_invoices')) {
        $this->dbforge->drop_column('crm_invoices', $nome);
      }
    }

    if ($this->db->field_exists('bomcontrole_company_id', 'crm_companies')) {
      $this->dbforge->drop_column('crm_companies', 'bomcontrole_company_id');
    }
  }

  /**
   * @param  string $tabela
   * @param  string $nome
   * @param  string $colunas
   * @return void
   */
  private function criarIndice($tabela, $nome, $colunas)
  {
    $existente = $this->db->query('SHOW INDEX FROM `' . $tabela . '` WHERE Key_name = ?', [$nome])->row();

    if (empty($existente)) {
      $this->db->query('ALTER TABLE `' . $tabela . '` ADD INDEX `' . $nome . '` (' . $colunas . ')');
    }
  }

  /**
   * @param  string $tabela
   * @param  string $nome
   * @return void
   */
  private function removerIndice($tabela, $nome)
  {
    $existente = $this->db->query('SHOW INDEX FROM `' . $tabela . '` WHERE Key_name = ?', [$nome])->row();

    if (!empty($existente)) {
      $this->db->query('ALTER TABLE `' . $tabela . '` DROP INDEX `' . $nome . '`');
    }
  }

  /**
   * A crm_invoices_v da 035, com ou sem as colunas de nota fiscal.
   *
   * @param  bool $comNota
   * @return string
   */
  private function viewFaturas($comNota)
  {
    $nota = $comNota
      ? "  `crm_invoices`.`nf_status` AS `nf_status`,\n"
      . "  `crm_invoices`.`nf_attempts` AS `nf_attempts`,\n"
      . "  `crm_invoices`.`nf_last_error` AS `nf_last_error`,\n"
      . "  `crm_invoices`.`nf_issued_at` AS `nf_issued_at`,\n"
      . "  `crm_invoices`.`bomcontrole_sale_id` AS `bomcontrole_sale_id`,\n"
      . "  `crm_invoices`.`bomcontrole_invoice_id` AS `bomcontrole_invoice_id`,\n"
      . "  `crm_invoices`.`link_nota_fiscal` AS `link_nota_fiscal`,\n"
      . "  `crm_invoices`.`link_nota_fiscal_xml` AS `link_nota_fiscal_xml`,\n"
      . "  `crm_invoices`.`nf_sent_at` AS `nf_sent_at`,\n"
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
{$nota}  `crm_invoices`.`comments` AS `comments`,
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
INNER JOIN `crm_customers` ON `crm_customers`.`id` = `crm_invoices`.`id_customer`
INNER JOIN `crm_contracts` ON `crm_contracts`.`id` = `crm_invoices`.`id_contract`
LEFT JOIN `crm_contracts_charges` ON `crm_contracts_charges`.`id` = `crm_invoices`.`id_charge`
LEFT JOIN `crm_users` ON `crm_users`.`id` = `crm_invoices`.`created_by`
";
  }

  /**
   * A crm_companies_v com ou sem o `bomcontrole_company_id`.
   *
   * Recriar a view na MESMA migration que acrescenta a coluna é regra do
   * projeto: as telas leem da view, e sem isto o cadastro da empresa não
   * refletiria o Id resolvido. O `bomcontrole_secret` continua FORA — a
   * credencial nunca chega ao navegador.
   *
   * @param  bool $comIdEmpresa
   * @return string
   */
  private function viewEmpresas($comIdEmpresa)
  {
    return $comIdEmpresa
      ? 'CREATE OR REPLACE VIEW `crm_companies_v` AS select `crm_companies`.`id` AS `id`,`crm_companies`.`id_status` AS `id_status`,`crm_companies`.`cnpj` AS `cnpj`,`crm_companies`.`name` AS `name`,`crm_companies`.`byname` AS `byname`,`crm_companies`.`address` AS `address`,`crm_companies`.`address_number` AS `address_number`,`crm_companies`.`address_complement` AS `address_complement`,`crm_companies`.`address_district` AS `address_district`,`crm_companies`.`address_zip` AS `address_zip`,`crm_companies`.`id_state` AS `id_state`,`crm_companies`.`id_city` AS `id_city`,`crm_companies`.`phone` AS `phone`,`crm_companies`.`owner` AS `owner`,`crm_companies`.`owner_cellphone` AS `owner_cellphone`,`crm_companies`.`alias` AS `alias`,`crm_companies`.`email` AS `email`,`crm_companies`.`token` AS `token`,`crm_companies`.`last_login` AS `last_login`,`crm_companies`.`bomcontrole_active` AS `bomcontrole_active`,`crm_companies`.`bomcontrole_base_url` AS `bomcontrole_base_url`,`crm_companies`.`bomcontrole_company_id` AS `bomcontrole_company_id`,`crm_companies`.`created` AS `created`,`crm_companies`.`created_by` AS `created_by`,`crm_companies`.`modified` AS `modified`,`crm_companies`.`modified_by` AS `modified_by`,`crm_country_cities`.`name` AS `city_name`,`crm_country_states`.`name` AS `state_name`,`crm_country_states`.`uf` AS `state_uf`,`crm_status`.`name` AS `status_name`,`crm_status`.`color` AS `status_color` from (((`crm_companies` join `crm_status` on(`crm_status`.`id` = `crm_companies`.`id_status`)) join `crm_country_cities` on(`crm_country_cities`.`id` = `crm_companies`.`id_city`)) join `crm_country_states` on(`crm_country_states`.`id` = `crm_companies`.`id_state`))'
      : 'CREATE OR REPLACE VIEW `crm_companies_v` AS select `crm_companies`.`id` AS `id`,`crm_companies`.`id_status` AS `id_status`,`crm_companies`.`cnpj` AS `cnpj`,`crm_companies`.`name` AS `name`,`crm_companies`.`byname` AS `byname`,`crm_companies`.`address` AS `address`,`crm_companies`.`address_number` AS `address_number`,`crm_companies`.`address_complement` AS `address_complement`,`crm_companies`.`address_district` AS `address_district`,`crm_companies`.`address_zip` AS `address_zip`,`crm_companies`.`id_state` AS `id_state`,`crm_companies`.`id_city` AS `id_city`,`crm_companies`.`phone` AS `phone`,`crm_companies`.`owner` AS `owner`,`crm_companies`.`owner_cellphone` AS `owner_cellphone`,`crm_companies`.`alias` AS `alias`,`crm_companies`.`email` AS `email`,`crm_companies`.`token` AS `token`,`crm_companies`.`last_login` AS `last_login`,`crm_companies`.`bomcontrole_active` AS `bomcontrole_active`,`crm_companies`.`bomcontrole_base_url` AS `bomcontrole_base_url`,`crm_companies`.`created` AS `created`,`crm_companies`.`created_by` AS `created_by`,`crm_companies`.`modified` AS `modified`,`crm_companies`.`modified_by` AS `modified_by`,`crm_country_cities`.`name` AS `city_name`,`crm_country_states`.`name` AS `state_name`,`crm_country_states`.`uf` AS `state_uf`,`crm_status`.`name` AS `status_name`,`crm_status`.`color` AS `status_color` from (((`crm_companies` join `crm_status` on(`crm_status`.`id` = `crm_companies`.`id_status`)) join `crm_country_cities` on(`crm_country_cities`.`id` = `crm_companies`.`id_city`)) join `crm_country_states` on(`crm_country_states`.`id` = `crm_companies`.`id_state`))';
  }

}
