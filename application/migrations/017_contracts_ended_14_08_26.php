<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Encerramento de contrato — a âncora temporal da SAÍDA.
 *
 * O card "Movimento de contratos" do dashboard soma, no mês, o valor dos
 * contratos que entraram e o dos que saíram. A entrada já era obtenível
 * (`created` responde "quando entrou"); a saída não existia em lugar nenhum:
 *  - `status` só tinha 'vigente' e 'suspenso', e suspensão é pausa temporária,
 *    não perda — cancelamento definitivo e inadimplência eram o mesmo estado;
 *  - `post_status` alternava os dois status SEM carimbar data, e
 *    `modified`/`modified_by` são compartilhados com `post_salvar`: qualquer
 *    edição de valor sobrescreveria o carimbo, então `modified` nunca serviria
 *    de proxy do encerramento.
 *
 * Daí `ended`: é ela, e não o `status`, que responde "quando saiu". O status
 * ganha o valor 'encerrado' (por isso o MODIFY do COMMENT abaixo), mas quem o
 * gráfico consulta é a data.
 *
 * Sobre as colunas:
 *  - `ended` é DATETIME (não DATE) para ficar na mesma régua de `created`: a
 *    janela do card é `>= dia 1 00:00:00 AND < dia 1 do mês seguinte`, uma
 *    forma só de comparação para as duas barras. NULL = nunca encerrado — é a
 *    única representação que não exige um `0000-00-00` (que quebra em sql_mode
 *    estrito) nem uma data mágica que o SUM(CASE) teria de conhecer. E como
 *    `NULL >= '...'` devolve NULL (que não é verdadeiro), contrato vigente fica
 *    de fora da barra de saídas sozinho, sem precisar de `IS NOT NULL`;
 *  - `ended_reason` guarda o slug do catálogo (Contratos::endReasons()), mesmo
 *    padrão de `status` e `cycle`. Slug e não texto livre porque a pergunta
 *    seguinte do negócio é "por que perdemos", e isso pede GROUP BY. 30 e não
 *    20: 'encerramento_atividades' tem 23 caracteres e seria truncado em
 *    silêncio;
 *  - `ended_comments` é SEPARADO de `comments` de propósito. O `comments` é
 *    editável no form de dados gerais; guardar a justificativa da perda lá
 *    faria a próxima edição apagá-la;
 *  - `ended_by` é nullable e sem cascade, como `modified_by`: contrato nunca
 *    encerrado não tem autor, e apagar usuário não pode apagar contrato.
 *
 * NÃO existe snapshot do valor (`ended_value`). Ele criaria duas verdades sobre
 * quanto o contrato valia — o mesmo problema que motivou a remoção do
 * `id_status` do cliente na migration 015. Quem protege o número do gráfico é o
 * `Contratos::post_salvar`, que recusa editar contrato encerrado.
 *
 * `crm_contracts_services_v` e `crm_contracts_domains_v` NÃO são recriadas de
 * propósito: elas expõem `crm_contracts`.`status` como `contract_status`, e é a
 * mesma coluna — o que muda é o conjunto de valores que trafega por ela, não a
 * definição da view. Recriá-las seria um no-op que só cria mais um lugar para o
 * texto divergir. Quem precisa saber do novo status é o código que consome — e
 * os dois consumidores (a aba Domínios do cliente e o gráfico por tipo de
 * serviço) já filtram por `= 'vigente'`, filtro positivo, que exclui 'encerrado'
 * sozinho.
 */
class Migration_Contracts_ended_14_08_26 extends CI_Migration
{
  private $colunas = [
    'ended' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Quando o contrato foi encerrado; NULL = vigente ou suspenso. Âncora da barra de saídas do dashboard.',
      'after' => 'comments',
    ],
    'ended_reason' => [
      'type' => 'VARCHAR',
      'constraint' => 30,
      'null' => TRUE,
      'comment' => 'Slug do motivo do encerramento (catálogo em Contratos::endReasons()).',
      'after' => 'ended',
    ],
    'ended_comments' => [
      'type' => 'VARCHAR',
      'constraint' => 500,
      'null' => TRUE,
      'comment' => 'Detalhe livre do encerramento. Separado de comments, que a edição de dados gerais sobrescreve.',
      'after' => 'ended_reason',
    ],
    'ended_by' => [
      'type' => 'INT',
      'constraint' => 11,
      'unsigned' => TRUE,
      'null' => TRUE,
      'comment' => 'Quem encerrou.',
      'after' => 'ended_comments',
    ],
  ];

  public function up()
  {
    foreach ($this->colunas as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->add_column('crm_contracts', [$nome => $definicao]);
      }
    }

    // dbforge->add_column não cria FK: a constraint sai em ALTER cru, com
    // guarda no information_schema porque a migration roda a cada requisição.
    $this->criarChaveEstrangeira('crm_contracts', 'ended_by', 'crm_users', 'id');

    // A query do card é `WHERE id_company = ? AND ended >= ? AND ended < ?`.
    // O índice de coluna única `id_company` que já existe resolve só a metade.
    $this->criarIndice('crm_contracts', 'idx_contracts_company_ended', ['id_company', 'ended']);

    // O COMMENT do status passou a mentir. MODIFY reescreve a definição
    // INTEIRA: omitir NOT NULL ou o DEFAULT aqui os apagaria em silêncio, por
    // isso os três atributos são repetidos exatamente como na migration 009.
    $this->db->query("
ALTER TABLE `crm_contracts`
  MODIFY COLUMN `status` varchar(20) NOT NULL DEFAULT 'vigente'
  COMMENT 'vigente | suspenso | encerrado'
");

    $this->db->query($this->viewComEncerramento());
  }

  public function down()
  {
    // A view volta ao formato da 009 ANTES das colunas caírem: view apontando
    // para coluna inexistente só quebra na hora do SELECT, e nesse intervalo
    // toda tela de contrato cai (mesma ordem que a 015 usou com o id_status).
    $this->db->query($this->viewOriginal());

    $this->removerChaveEstrangeira('crm_contracts', 'ended_by');
    $this->removerIndice('crm_contracts', 'idx_contracts_company_ended');

    foreach (array_keys($this->colunas) as $nome) {
      if ($this->db->field_exists($nome, 'crm_contracts')) {
        $this->dbforge->drop_column('crm_contracts', $nome);
      }
    }

    $this->db->query("
ALTER TABLE `crm_contracts`
  MODIFY COLUMN `status` varchar(20) NOT NULL DEFAULT 'vigente'
  COMMENT 'vigente | suspenso'
");
  }

  /**
   * A crm_contracts_v com as colunas do encerramento e `ended_user`.
   *
   * Os DOIS joins de crm_users passam a ter alias. Sem isso, o segundo join
   * torna `crm_users`.`name` ambíguo e derruba de uma vez a tela do contrato E
   * a visão geral do cliente — ambas leem desta view.
   *
   * `ended_user` existe pelo mesmo motivo do `created_user` na 009: a tela diz
   * "Encerrado em X por Fulano" sem uma segunda query.
   *
   * @return string
   */
  private function viewComEncerramento()
  {
    return "
CREATE OR REPLACE VIEW `crm_contracts_v` AS
SELECT
  `crm_contracts`.`id` AS `id`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`,
  `crm_contracts`.`status` AS `status`,
  `crm_contracts`.`cycle` AS `cycle`,
  `crm_contracts`.`value` AS `value`,
  `crm_contracts`.`space_gb` AS `space_gb`,
  `crm_contracts`.`comments` AS `comments`,
  `crm_contracts`.`ended` AS `ended`,
  `crm_contracts`.`ended_reason` AS `ended_reason`,
  `crm_contracts`.`ended_comments` AS `ended_comments`,
  `crm_contracts`.`ended_by` AS `ended_by`,
  `crm_contracts`.`created` AS `created`,
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
   * A crm_contracts_v exatamente como a migration 009 a deixou.
   *
   * @return string
   */
  private function viewOriginal()
  {
    return "
CREATE OR REPLACE VIEW `crm_contracts_v` AS
SELECT
  `crm_contracts`.`id` AS `id`,
  `crm_contracts`.`id_customer` AS `id_customer`,
  `crm_contracts`.`id_company` AS `id_company`,
  `crm_contracts`.`status` AS `status`,
  `crm_contracts`.`cycle` AS `cycle`,
  `crm_contracts`.`value` AS `value`,
  `crm_contracts`.`space_gb` AS `space_gb`,
  `crm_contracts`.`comments` AS `comments`,
  `crm_contracts`.`created` AS `created`,
  `crm_contracts`.`created_by` AS `created_by`,
  `crm_contracts`.`modified` AS `modified`,
  `crm_contracts`.`modified_by` AS `modified_by`,
  `crm_customers`.`name` AS `customer_name`,
  `crm_customers`.`byname` AS `customer_byname`,
  `crm_users`.`name` AS `created_user`
FROM ((`crm_contracts`
  JOIN `crm_customers` ON(`crm_customers`.`id` = `crm_contracts`.`id_customer`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_contracts`.`created_by`))
";
  }

  /**
   * Cria o índice (simples ou composto) só quando ainda não existe — a
   * migration roda automática a cada requisição e um CREATE INDEX repetido é
   * erro fatal.
   *
   * @param  string $tabela
   * @param  string $nome
   * @param  array  $colunas
   * @return void
   */
  private function criarIndice($tabela, $nome, $colunas)
  {
    $existente = $this->db->query(
      'SHOW INDEX FROM `' . $tabela . '` WHERE `Key_name` = ?',
      [$nome]
    )->row();

    if (empty($existente)) {
      $this->db->query(
        'CREATE INDEX `' . $nome . '` ON `' . $tabela . '` (`' . implode('`, `', $colunas) . '`)'
      );
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
      'SHOW INDEX FROM `' . $tabela . '` WHERE `Key_name` = ?',
      [$nome]
    )->row();

    if (!empty($existente)) {
      $this->db->query('ALTER TABLE `' . $tabela . '` DROP INDEX `' . $nome . '`');
    }
  }

  /**
   * Cria a FK só quando a coluna ainda não tem nenhuma — o nome não é fixado
   * porque bancos criados fora da migration 009 podem ter numeração diferente
   * (mesmo motivo pelo qual a 015 busca o nome para derrubar).
   *
   * @param  string $tabela
   * @param  string $coluna
   * @param  string $tabelaRef
   * @param  string $colunaRef
   * @return void
   */
  private function criarChaveEstrangeira($tabela, $coluna, $tabelaRef, $colunaRef)
  {
    if (!$this->db->field_exists($coluna, $tabela)) return;

    if (!empty($this->chavesEstrangeiras($tabela, $coluna))) return;

    $this->db->query(
      'ALTER TABLE `' . $tabela . '` ADD CONSTRAINT `' . $tabela . '_fk_' . $coluna . '`'
        . ' FOREIGN KEY (`' . $coluna . '`) REFERENCES `' . $tabelaRef . '` (`' . $colunaRef . '`)'
    );
  }

  /**
   * @param  string $tabela
   * @param  string $coluna
   * @return void
   */
  private function removerChaveEstrangeira($tabela, $coluna)
  {
    if (!$this->db->field_exists($coluna, $tabela)) return;

    foreach ($this->chavesEstrangeiras($tabela, $coluna) as $constraint) {
      $this->db->query('ALTER TABLE `' . $tabela . '` DROP FOREIGN KEY `' . $constraint->CONSTRAINT_NAME . '`');
    }
  }

  /**
   * @param  string $tabela
   * @param  string $coluna
   * @return array
   */
  private function chavesEstrangeiras($tabela, $coluna)
  {
    return $this->db->query("
      SELECT `CONSTRAINT_NAME`
      FROM `information_schema`.`KEY_COLUMN_USAGE`
      WHERE `TABLE_SCHEMA` = DATABASE()
        AND `TABLE_NAME` = ?
        AND `COLUMN_NAME` = ?
        AND `REFERENCED_TABLE_NAME` IS NOT NULL
    ", [$tabela, $coluna])->result();
  }
}
