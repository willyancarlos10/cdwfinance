<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fundação da cobrança via PSP, com o PSP selecionável POR CONTRATO.
 *
 * Até aqui a fatura era um registro local: o `cron_gerar_faturas` grava a linha
 * em `crm_invoices` e ninguém consegue pagá-la. Esta migration abre espaço para
 * a cobrança de verdade — boleto e PIX registrados num PSP —, e faz isso já
 * preparada para MAIS DE UM PSP convivendo na mesma base.
 *
 * Sobre `crm_contracts.psp`:
 *  - é SLUG, e não FK, no idioma de `status`, `cycle`, `billing_source`,
 *    `invoice_policy`, `adjustment_index` e `ended_reason` — todos atributos de
 *    contrato com catálogo à parte. O catálogo aqui é a allowlist
 *    `Psp_model::providers()`, e slug desconhecido é ERRO, nunca "usa o
 *    padrão".
 *  - NÃO é valor de `billing_source`, e a distinção é o que evita um acidente
 *    concreto: `billing_source` responde "quem é dono da fatura" (o ERP ou
 *    nós) e `psp` responde "quem registra a cobrança". Empilhadas num campo só
 *    ('bomcontrole' | 'inter' | 'asaas'), trocar de banco passaria pelo ramo
 *    else do `Contratos::post_faturamento`, que ZERA `next_competence` — e a
 *    troca de PSP apagaria a âncora do motor, gerando faturas retroativas.
 *  - default '' (não definido) pelo mesmo motivo do `billing_day = 0`: a base
 *    inteira nasce sem PSP, e o contrato só fatura aqui depois de alguém
 *    escolher. Desde a migration 029 "a fatura É o boleto", então faturar sem
 *    PSP produziria uma fatura que ninguém consegue pagar.
 *
 * Sobre `crm_invoices.psp` — a coluna que parece redundante e não é:
 *
 *    É SNAPSHOT DE ROTEAMENTO, congelado na geração ao lado de `value`,
 *    `description` e `invoice_policy`.
 *
 *  Se o contrato migrar de PSP em março, as cobranças de janeiro e fevereiro
 *  continuam vivas no PSP ANTIGO. Quem precisa saber disso é o webhook (achar
 *  a fatura a partir de um charge_id que só existe num dos PSPs), a
 *  conciliação (perguntar ao PSP certo), o cancelamento e o link do boleto que
 *  a tela mostra. Ler o PSP do contrato na hora de consultar daria a resposta
 *  errada exatamente DEPOIS de uma troca — o momento em que ninguém está
 *  olhando.
 *
 * Sobre `crm_psp_accounts` ser TABELA, e não colunas em `crm_companies` como o
 * `bomcontrole_*`: se o PSP é escolha do contrato, um mesmo tenant precisa de
 * credenciais de VÁRIOS PSPs ativas ao mesmo tempo. Em colunas, cada PSP novo
 * acrescentaria um jogo inteiro à `crm_companies` e obrigaria a recriar a
 * `crm_companies_v`; em tabela, PSP novo é LINHA, não DDL. Continuam valendo as
 * duas regras de credencial do projeto: valor cifrado com `Secret_crypto` e
 * FORA de qualquer view — por isso esta tabela não ganha `_v`.
 *
 * `cert_path`/`key_path` guardam CAMINHO, e não o PEM cifrado, porque o
 * `CURLOPT_SSLCERT_BLOB` não existe no PHP 7.4 (foi exposto no 8.1): o cURL só
 * aceita arquivo, e materializar a chave privada em disco a cada requisição
 * seria pior que guardá-la uma vez fora do webroot.
 *
 * `crm_psp_webhook_events` é auditoria do recebido. Com webhook possivelmente
 * NÃO assinado (risco aberto do estudo do Inter), guardar o corpo cru é o que
 * permite reconstruir o que aconteceu — e `processed` é o que distingue
 * "chegou" de "foi aplicado".
 *
 * NÃO registra `cron_conciliar_cobrancas` em `crm_cron_logs` de propósito: a
 * rotina é da etapa D e ainda não existe. A linha faria o painel de CRON
 * oferecer um botão EXECUTAR que derruba a requisição.
 *
 * @see docs/PLANO-PSP-COBRANCA.md
 */
class Migration_Psp_cobranca_18_08_26 extends CI_Migration
{
  /**
   * @var array
   */
  private $colunasContrato = [
    'psp' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => '',
      'comment' => 'PSP que registra a cobranca deste contrato; vazio = nao definido.',
      'after' => 'billing_source',
    ],
  ];

  /**
   * @var array
   */
  private $colunasFatura = [
    'psp' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => '',
      'comment' => 'Snapshot do PSP que emitiu; e o que roteia webhook e conciliacao.',
      'after' => 'invoice_policy',
    ],
    'psp_charge_id' => [
      'type' => 'VARCHAR',
      'constraint' => 100,
      'null' => TRUE,
      'comment' => 'Id da cobranca no PSP. Vazio em fatura aberta = ainda nao registrada.',
      'after' => 'psp',
    ],
    'psp_status' => [
      'type' => 'VARCHAR',
      'constraint' => 30,
      'null' => FALSE,
      'default' => '',
      'comment' => 'Status cru devolvido pelo PSP, para diagnostico.',
      'after' => 'psp_charge_id',
    ],
    'link_boleto' => [
      'type' => 'VARCHAR',
      'constraint' => 255,
      'null' => TRUE,
      'after' => 'psp_status',
    ],
    'linha_digitavel' => [
      'type' => 'VARCHAR',
      'constraint' => 60,
      'null' => TRUE,
      'comment' => 'Linha digitavel do boleto; o cliente copia sem abrir o PDF.',
      'after' => 'link_boleto',
    ],
    'link_pix' => [
      'type' => 'TEXT',
      'null' => TRUE,
      'comment' => 'PIX copia-e-cola; longo demais para varchar curto.',
      'after' => 'linha_digitavel',
    ],
    'paid_at' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Data da liquidacao no PSP. Preenchido = guarda de idempotencia da baixa.',
      'after' => 'link_pix',
    ],
    'paid_amount' => [
      'type' => 'DECIMAL',
      'constraint' => '12,2',
      'null' => TRUE,
      'comment' => 'Valor efetivamente pago; pode divergir do cobrado (juros/desconto).',
      'after' => 'paid_at',
    ],
    'paid_method' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => '',
      'comment' => 'boleto | pix | outro.',
      'after' => 'paid_amount',
    ],
    'sent_at' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Quando o boleto foi enviado ao cliente (etapa B).',
      'after' => 'paid_method',
    ],
  ];

  public function up()
  {
    foreach ($this->colunasContrato as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->add_column('crm_contracts', [$nome => $definicao]);
      }
    }

    foreach ($this->colunasFatura as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_invoices')) {
        $this->dbforge->add_column('crm_invoices', [$nome => $definicao]);
      }
    }

    // O índice do charge_id é a busca do webhook (uma linha, por id externo);
    // o composto é a varredura da conciliação.
    $this->criarIndice('crm_invoices', 'idx_invoices_psp_charge', '`psp_charge_id`');
    $this->criarIndice('crm_invoices', 'idx_invoices_psp_status', '`psp`,`psp_status`');

    if (!$this->db->table_exists('crm_psp_accounts')) {
      $this->db->query($this->tabelaContas());
    }

    if (!$this->db->table_exists('crm_psp_webhook_events')) {
      $this->db->query($this->tabelaEventos());
    }

    $this->db->query($this->viewContratos(TRUE));
    $this->db->query($this->viewFaturas(TRUE));
  }

  public function down()
  {
    // As views voltam ao formato anterior ANTES de as colunas caírem: view
    // apontando para coluna inexistente derruba toda tela que a lê (mesma
    // ordem da 015, 017, 019, 020, 023, 024 e 029).
    $this->db->query($this->viewContratos(FALSE));
    $this->db->query($this->viewFaturas(FALSE));

    $this->dbforge->drop_table('crm_psp_webhook_events', TRUE);
    $this->dbforge->drop_table('crm_psp_accounts', TRUE);

    $this->removerIndice('crm_invoices', 'idx_invoices_psp_charge');
    $this->removerIndice('crm_invoices', 'idx_invoices_psp_status');

    foreach (array_keys($this->colunasFatura) as $nome) {
      if ($this->db->field_exists($nome, 'crm_invoices')) {
        $this->dbforge->drop_column('crm_invoices', $nome);
      }
    }

    foreach (array_keys($this->colunasContrato) as $nome) {
      if ($this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->drop_column('crm_contracts', $nome);
      }
    }
  }

  /**
   * Cria índice só se não existir — o MySQL não tem IF NOT EXISTS para índice,
   * e a migration precisa ser reexecutável.
   *
   * @param  string $tabela
   * @param  string $nome
   * @param  string $colunas já entre crases
   * @return void
   */
  private function criarIndice($tabela, $nome, $colunas)
  {
    $existente = $this->db->query(
      'SHOW INDEX FROM `' . $tabela . '` WHERE Key_name = ?',
      [$nome]
    )->row();

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
    $existente = $this->db->query(
      'SHOW INDEX FROM `' . $tabela . '` WHERE Key_name = ?',
      [$nome]
    )->row();

    if (!empty($existente)) {
      $this->db->query('ALTER TABLE `' . $tabela . '` DROP INDEX `' . $nome . '`');
    }
  }

  /**
   * Credencial por tenant E por PSP.
   *
   * A UNIQUE (id_company, psp) é o que permite o mesmo tenant ter Inter e outro
   * PSP ativos ao mesmo tempo — requisito de o PSP ser escolha do contrato.
   *
   * `webhook_token` identifica a conta na URL pública do webhook e é UNIQUE
   * global de propósito: o handler resolve tenant + PSP a partir dele, sem
   * confiar em nada do corpo. NÃO reusa `crm_companies.token`, que é
   * semipúblico (vai no link de cadastro de cliente).
   *
   * @return string
   */
  private function tabelaContas()
  {
    return "
CREATE TABLE `crm_psp_accounts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(11) unsigned NOT NULL,
  `psp` varchar(20) NOT NULL COMMENT 'Slug do provedor; allowlist em Psp_model::providers().',
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `environment` varchar(20) NOT NULL DEFAULT 'sandbox' COMMENT 'sandbox | producao.',
  `client_id` varchar(255) NOT NULL DEFAULT '',
  `client_secret` text DEFAULT NULL COMMENT 'Cifrado com Secret_crypto; nunca em view.',
  `cert_path` varchar(255) NOT NULL DEFAULT '' COMMENT 'Caminho RELATIVO a application/certs (absoluto quebraria ao trocar de servidor).',
  `key_path` varchar(255) NOT NULL DEFAULT '' COMMENT 'Caminho RELATIVO a application/certs.',
  `cert_expires_at` date DEFAULT NULL COMMENT 'Vencimento do certificado; expirado para TODA cobranca do tenant de uma vez.',
  `webhook_token` varchar(64) NOT NULL COMMENT 'Identifica a conta na URL publica do webhook.',
  `extra` longtext DEFAULT NULL COMMENT 'JSON com o que for especifico de cada PSP, sem migration nova.',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_psp_accounts_company_psp` (`id_company`,`psp`),
  UNIQUE KEY `uk_psp_accounts_webhook_token` (`webhook_token`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_psp_accounts_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_psp_accounts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_psp_accounts_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
  }

  /**
   * Auditoria do que o PSP entregou.
   *
   * `id_invoice` é ON DELETE SET NULL no molde de `crm_domains_whois_history`:
   * o evento é registro histórico e não pode sumir junto, mas também não pode
   * bloquear nada.
   *
   * @return string
   */
  private function tabelaEventos()
  {
    return "
CREATE TABLE `crm_psp_webhook_events` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(11) unsigned NOT NULL,
  `psp` varchar(20) NOT NULL,
  `charge_id` varchar(100) NOT NULL DEFAULT '' COMMENT 'Id da cobranca no PSP, extraido do corpo.',
  `event_type` varchar(40) NOT NULL DEFAULT '',
  `payload` longtext DEFAULT NULL COMMENT 'Corpo cru recebido; a unica prova quando o webhook nao e assinado.',
  `id_invoice` int(11) unsigned DEFAULT NULL,
  `received` datetime DEFAULT NULL,
  `processed` datetime DEFAULT NULL COMMENT 'Distingue chegou de foi aplicado.',
  PRIMARY KEY (`id`),
  KEY `charge_id` (`charge_id`),
  KEY `idx_psp_events_psp_received` (`psp`,`received`),
  KEY `id_company` (`id_company`),
  KEY `id_invoice` (`id_invoice`),
  CONSTRAINT `crm_psp_webhook_events_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_psp_webhook_events_ibfk_2` FOREIGN KEY (`id_invoice`) REFERENCES `crm_invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
  }

  /**
   * A crm_contracts_v da 033, com ou sem a coluna `psp`.
   *
   * @param  bool $comPsp
   * @return string
   */
  private function viewContratos($comPsp)
  {
    $psp = $comPsp ? "  `crm_contracts`.`psp` AS `psp`,\n" : '';

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
{$psp}  `crm_contracts`.`billing_day` AS `billing_day`,
  `crm_contracts`.`next_competence` AS `next_competence`,
  `crm_contracts`.`installments` AS `installments`,
  `crm_contracts`.`invoice_policy` AS `invoice_policy`,
  `crm_contracts`.`notification_config` AS `notification_config`,
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
   * A crm_invoices_v da 031, com ou sem as colunas de cobrança.
   *
   * O `situation` continua DERIVADO aqui, e NÃO passa a considerar o
   * `psp_status`: "vencida" é pergunta sobre a data, e misturar o estado do
   * PSP criaria uma segunda verdade sobre a mesma fatura. Quem conta o que o
   * PSP diz é a coluna crua, para diagnóstico.
   *
   * @param  bool $comPsp
   * @return string
   */
  private function viewFaturas($comPsp)
  {
    $psp = $comPsp
      ? "  `crm_invoices`.`psp` AS `psp`,\n"
      . "  `crm_invoices`.`psp_charge_id` AS `psp_charge_id`,\n"
      . "  `crm_invoices`.`psp_status` AS `psp_status`,\n"
      . "  `crm_invoices`.`link_boleto` AS `link_boleto`,\n"
      . "  `crm_invoices`.`linha_digitavel` AS `linha_digitavel`,\n"
      . "  `crm_invoices`.`link_pix` AS `link_pix`,\n"
      . "  `crm_invoices`.`paid_at` AS `paid_at`,\n"
      . "  `crm_invoices`.`paid_amount` AS `paid_amount`,\n"
      . "  `crm_invoices`.`paid_method` AS `paid_method`,\n"
      . "  `crm_invoices`.`sent_at` AS `sent_at`,\n"
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
{$psp}  `crm_invoices`.`comments` AS `comments`,
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
LEFT JOIN `crm_contracts_charges` ON `crm_contracts_charges`.`id` = `crm_invoices`.`id_charge`
LEFT JOIN `crm_users` ON `crm_users`.`id` = `crm_invoices`.`created_by`
";
  }
}
