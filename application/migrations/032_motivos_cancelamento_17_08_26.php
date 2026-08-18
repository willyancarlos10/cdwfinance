<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Motivos de cancelamento: o catálogo sai do código e vira tabela.
 *
 * Até aqui os motivos de encerramento viviam num array de
 * `Contratos::endReasons()`: incluir "Redução de custos" exigia editar PHP e
 * publicar. Com o card de cancelamentos do Dashboard lendo esses mesmos
 * motivos, a lista deixa de ser detalhe de formulário e passa a ser eixo de
 * análise — e eixo de análise que só o desenvolvedor consegue mudar é eixo que
 * não muda.
 *
 * Catálogo GLOBAL, sem `id_company`, como `crm_service_types` (004): a pergunta
 * "por que o cliente saiu" é a mesma em qualquer tenant, e comparar motivos
 * entre empresas só faz sentido com vocabulário único. Quem administra é a
 * empresa master, como no resto da seção GESTÃO.
 *
 * DECISÕES
 *
 *  - **`crm_contracts.ended_reason` continua guardando o SLUG, e não vira FK.**
 *    Três razões, na ordem de peso:
 *      1. O motivo gravado é CARIMBO HISTÓRICO. Se o catálogo perder um motivo,
 *         o contrato encerrado não pode perder junto o porquê — e a tela do
 *         contrato já sabe cair no próprio slug quando não acha o rótulo.
 *      2. É o idioma do projeto para atributo de contrato: `status`, `cycle`,
 *         `billing_source`, `invoice_policy` e `adjustment_index` são todos
 *         slug com catálogo à parte.
 *      3. FK obrigaria recriar a `crm_contracts_v` (33 colunas), que já foi
 *         redefinida nas migrations 024, 029 e 031 — cada recriação é uma
 *         chance de as definições divergirem, sem ganho nenhum aqui.
 *    O preço assumido é não haver integridade referencial: quem protege é o
 *    `post_excluir` da tela, que recusa apagar motivo já usado.
 *
 *  - **`slug` é imutável depois de criado** (a tela só deixa editar o rótulo).
 *    Renomear "Inadimplência" para "Falta de pagamento" reetiqueta o histórico
 *    junto, que é o desejado; mudar o slug ORFANARIA os contratos gravados.
 *
 *  - **`color` é hex, e não o nome contextual do Bootstrap** que
 *    `crm_status.color` guarda ("success", "danger"). São coisas diferentes:
 *    lá é classe CSS, aqui é a cor da fatia da pizza e da barra do Dashboard, e
 *    o ApexCharts recebe a cor como valor, não como classe. Os sete hex do seed
 *    saem da própria paleta do tema (theme/css/light.css).
 *
 *  - **Esta é a primeira migration do projeto que POPULA dados**, e é
 *    deliberado: catálogo vazio deixaria o modal de encerrar sem nenhuma opção
 *    e o encerramento inoperante no primeiro deploy. Os sete valores são
 *    exatamente os que o código já usava, com os mesmos slugs — nenhum contrato
 *    existente muda de significado. O seed é idempotente por slug.
 *
 *  - **`ended_comments` cai de 500 para 300 caracteres**, o limite pedido para
 *    a observação do cancelamento. O corte vale nos três lugares (banco, POST e
 *    textarea) para não haver duas verdades sobre o mesmo campo. Conferido
 *    antes: nenhuma linha acima de 300 — a base não tem contrato encerrado.
 *
 * A `crm_contracts_v` NÃO é recriada: `ended_comments` é coluna de passagem e o
 * MySQL expande a view na consulta, então o tamanho novo aparece sozinho.
 */
class Migration_Motivos_cancelamento_17_08_26 extends CI_Migration
{
  /**
   * Catálogo inicial — os mesmos slugs que Contratos::endReasons() usava, para
   * o histórico (quando houver) continuar casando.
   *
   * @return array
   */
  private function motivosPadrao()
  {
    return [
      ['slug' => 'cancelamento',   'name' => 'Cancelamento pelo cliente',              'color' => '#3f80ea', 'sort_order' => 1],
      ['slug' => 'inadimplencia',  'name' => 'Inadimplência',                          'color' => '#d9534f', 'sort_order' => 2],
      ['slug' => 'concorrente',    'name' => 'Migração para concorrente',              'color' => '#fd7e14', 'sort_order' => 3],
      ['slug' => 'fim_atividades', 'name' => 'Encerramento das atividades do cliente', 'color' => '#6f42c1', 'sort_order' => 4],
      ['slug' => 'fim_prazo',      'name' => 'Fim do prazo contratual',                'color' => '#20c997', 'sort_order' => 5],
      ['slug' => 'substituicao',   'name' => 'Substituído por outro contrato',         'color' => '#1f9bcf', 'sort_order' => 6],
      ['slug' => 'outros',         'name' => 'Outros',                                 'color' => '#6c757d', 'sort_order' => 7],
    ];
  }

  public function up()
  {
    if (!$this->db->table_exists('crm_end_reasons')) {
      $query = "
CREATE TABLE `crm_end_reasons` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_status` int(11) unsigned NOT NULL DEFAULT 1,
  `slug` varchar(30) NOT NULL COMMENT 'Chave gravada em crm_contracts.ended_reason. Imutável depois de criado.',
  `name` varchar(150) NOT NULL COMMENT 'Rótulo do modal de encerramento e da legenda do Dashboard.',
  `color` varchar(7) NOT NULL DEFAULT '#6c757d' COMMENT 'Hex da fatia no gráfico de cancelamentos (não é classe do Bootstrap).',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Ordem no select e na legenda.',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_end_reasons_slug` (`slug`),
  UNIQUE KEY `uk_end_reasons_name` (`name`),
  KEY `id_status` (`id_status`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_end_reasons_ibfk_1` FOREIGN KEY (`id_status`) REFERENCES `crm_status` (`id`),
  CONSTRAINT `crm_end_reasons_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_end_reasons_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
      $this->db->query($query);
    }

    // `contracts_count` na view pelo mesmo motivo do `contracts_count` da
    // crm_customers_v: a listagem precisa dizer quantos contratos usam o motivo
    // (é o que decide se dá para excluir), e um COUNT por linha no controller
    // seria N+1.
    $query = "
CREATE OR REPLACE VIEW `crm_end_reasons_v` AS
SELECT
  `crm_end_reasons`.`id` AS `id`,
  `crm_end_reasons`.`id_status` AS `id_status`,
  `crm_end_reasons`.`slug` AS `slug`,
  `crm_end_reasons`.`name` AS `name`,
  `crm_end_reasons`.`color` AS `color`,
  `crm_end_reasons`.`sort_order` AS `sort_order`,
  `crm_end_reasons`.`created` AS `created`,
  `crm_end_reasons`.`created_by` AS `created_by`,
  `crm_end_reasons`.`modified` AS `modified`,
  `crm_end_reasons`.`modified_by` AS `modified_by`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`,
  `crm_users`.`name` AS `modified_user`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`ended_reason` = `crm_end_reasons`.`slug`) AS `contracts_count`
FROM ((`crm_end_reasons`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_end_reasons`.`id_status`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_end_reasons`.`modified_by`))
";
    $this->db->query($query);

    // Seed idempotente: só entra o slug que ainda não existe, para uma segunda
    // execução (ou um banco que já tenha o catálogo) não estourar na UNIQUE nem
    // reescrever rótulo que o usuário editou.
    $agora = date('Y-m-d H:i:s');
    foreach ($this->motivosPadrao() as $motivo) {
      $existe = $this->db->query('SELECT `id` FROM `crm_end_reasons` WHERE `slug` = ?', [$motivo['slug']])->row();
      if (!empty($existe)) continue;

      $this->db->insert('crm_end_reasons', array_merge($motivo, [
        'id_status' => 1,
        'created' => $agora,
        // 3 = PROCESSOS AUTOMÁTICOS, como na importação: a coluna "modificado
        // por" da tela mostra a procedência sozinha.
        'created_by' => 3,
        'modified' => $agora,
        'modified_by' => 3,
      ]));
    }

    if ($this->db->field_exists('ended_comments', 'crm_contracts')) {
      $this->dbforge->modify_column('crm_contracts', [
        'ended_comments' => [
          'name' => 'ended_comments',
          'type' => 'VARCHAR',
          'constraint' => 300,
          'null' => TRUE,
          'comment' => 'Observações do encerramento. Separado de `comments`, que a edição de dados gerais sobrescreveria.',
        ],
      ]);
    }
  }

  public function down()
  {
    $this->db->query('DROP VIEW IF EXISTS `crm_end_reasons_v`');
    $this->db->query('DROP TABLE IF EXISTS `crm_end_reasons`');

    if ($this->db->field_exists('ended_comments', 'crm_contracts')) {
      $this->dbforge->modify_column('crm_contracts', [
        'ended_comments' => [
          'name' => 'ended_comments',
          'type' => 'VARCHAR',
          'constraint' => 500,
          'null' => TRUE,
        ],
      ]);
    }
  }
}
