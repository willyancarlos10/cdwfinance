<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * `legacy_id` em `crm_customers_notes` — completa a migration 020.
 *
 * A 020 deixou esta tabela de fora com um motivo explícito: a tabela de origem
 * correspondente (`clientesAtividades`) estava VAZIA no dump usado então, e
 * coluna sem dado é peso morto.
 *
 * O dump seguinte do gestor-interno trouxe 8 atividades. Como a importação é
 * upsert por (`id_company`, `legacy_id`), sem esta coluna as atividades seriam
 * o único conjunto não idempotente da rotina: cada reexecução criaria 8
 * observações repetidas na aba Atividades do cliente.
 *
 * Coluna nova em vez de edição da 020 porque migration publicada não se
 * reescreve — quem já rodou a 020 não veria a mudança.
 */
class Migration_Legacy_id_notes_15_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->field_exists('legacy_id', 'crm_customers_notes')) {
      $this->dbforge->add_column('crm_customers_notes', [
        'legacy_id' => [
          'type' => 'INT',
          'constraint' => 11,
          'unsigned' => TRUE,
          'null' => TRUE,
          'comment' => 'Id da linha no gestor-interno (importacao). NULL = cadastro feito no proprio sistema.',
        ],
      ]);
    }

    if (!$this->indiceExiste('crm_customers_notes', 'uk_customers_notes_company_legacy')) {
      $this->db->query(
        'ALTER TABLE `crm_customers_notes` ADD UNIQUE KEY `uk_customers_notes_company_legacy` (`id_company`, `legacy_id`)'
      );
    }

    $this->db->query($this->view(TRUE));
  }

  public function down()
  {
    // View volta ao formato anterior ANTES de a coluna cair (mesma ordem da 020).
    $this->db->query($this->view(FALSE));

    if ($this->indiceExiste('crm_customers_notes', 'uk_customers_notes_company_legacy')) {
      $this->db->query('ALTER TABLE `crm_customers_notes` DROP INDEX `uk_customers_notes_company_legacy`');
    }

    if ($this->db->field_exists('legacy_id', 'crm_customers_notes')) {
      $this->dbforge->drop_column('crm_customers_notes', 'legacy_id');
    }
  }

  /**
   * O CI3 não tem `index_exists()`; sem a checagem, rodar duas vezes aborta
   * com "Duplicate key name".
   *
   * @param  string $tabela
   * @param  string $indice
   * @return bool
   */
  private function indiceExiste($tabela, $indice)
  {
    $consulta = $this->db->query("SHOW INDEX FROM `{$tabela}` WHERE `Key_name` = ?", [$indice]);

    return ($consulta !== FALSE && $consulta->num_rows() > 0);
  }

  /**
   * A crm_customers_notes_v da migration 007, com ou sem `legacy_id`.
   *
   * @param  bool $comLegacy
   * @return string
   */
  private function view($comLegacy)
  {
    $legacy = $comLegacy ? "  `crm_customers_notes`.`legacy_id` AS `legacy_id`,\n" : '';

    return "
CREATE OR REPLACE VIEW `crm_customers_notes_v` AS
SELECT
  `crm_customers_notes`.`id` AS `id`,
  `crm_customers_notes`.`id_customer` AS `id_customer`,
  `crm_customers_notes`.`id_company` AS `id_company`,
{$legacy}  `crm_customers_notes`.`description` AS `description`,
  `crm_customers_notes`.`created` AS `created`,
  `crm_customers_notes`.`created_by` AS `created_by`,
  `crm_users`.`name` AS `created_user`
FROM (`crm_customers_notes`
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers_notes`.`created_by`))
";
  }
}
