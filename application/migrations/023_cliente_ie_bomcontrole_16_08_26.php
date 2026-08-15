<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Inscrição estadual do cliente e vínculo do cadastro com o Bom Controle.
 *
 * Duas coisas que andam juntas porque a segunda consome a primeira: o cadastro
 * de `crm_customers` passa a subir para o ERP Bom Controle, e o ERP pede a
 * inscrição estadual, que até aqui não era coletada em lugar nenhum.
 *
 * `state_registration` é COLUNA FÍSICA, ao lado de `document`/`name`/`byname`,
 * e não mais uma chave do JSON `attributes`: é identificação fiscal, o mesmo
 * critério que manteve aqueles três fora do JSON — e a emissão futura de NF-e
 * vai precisar dela consultável, não escondida dentro de um longtext.
 *
 * O DEFAULT é 'ISENTO' porque é o caso mais comum no perfil de cliente da CDW
 * (prestador de serviço não contribuinte de ICMS) e porque o dado não existe em
 * nenhuma origem: nem no wizard antigo, nem na base importada do
 * gestor-interno. Consequência assumida: toda a base já cadastrada nasce como
 * isenta. É melhor do que o contrário — o ERP recebe "isento", que é verdade
 * para a maioria, em vez de um número inventado; quem tiver inscrição real é
 * corrigido na edição. Só pessoa jurídica tem inscrição estadual: em cliente
 * `type = 'F'` a coluna fica com o default e nunca é enviada ao ERP.
 *
 * As duas colunas de vínculo espelham o desenho da 019 em `crm_contracts`:
 *
 *  - `bomcontrole_customer_id` guarda o Id do Cliente no Bom Controle (NULL =
 *    sem vínculo). Só o Id — nenhum dado cadastral do ERP é copiado para cá,
 *    pelo mesmo motivo que o extrato financeiro é consultado ao vivo: cópia
 *    local vira segunda verdade.
 *  - `bomcontrole_synced` carimba a última sincronização BEM-SUCEDIDA. Só
 *    sucesso, de propósito: uma coluna que também marcasse tentativa falha
 *    responderia duas perguntas diferentes com o mesmo valor, e a tela diria
 *    "sincronizado 14:32" para um cadastro que não subiu. O diagnóstico de
 *    falha é o log (`[BOMCONTROLE]`), não uma coluna.
 *
 * Sem FK e sem índice novo: o id remoto não referencia tabela local, e ele é
 * sempre lido a partir do `id` do cliente (PK), nunca pesquisado por ele.
 *
 * A `crm_customers_v` é recriada com as três colunas — as telas de edição,
 * listagem e exportação leem da view, e sem isso o valor salvo não voltaria no
 * formulário. Parte da última definição vigente, a da 020. Sem DEFINER, senão
 * o deploy quebra em outro servidor.
 */
class Migration_Cliente_ie_bomcontrole_16_08_26 extends CI_Migration
{
  /**
   * @var array
   */
  private $colunas = [
    'state_registration' => [
      'type' => 'VARCHAR',
      'constraint' => 20,
      'null' => FALSE,
      'default' => 'ISENTO',
      'comment' => 'Inscricao estadual (so PJ). ISENTO = contribuinte isento.',
      'after' => 'byname',
    ],
    'bomcontrole_customer_id' => [
      'type' => 'INT',
      'constraint' => 11,
      'unsigned' => TRUE,
      'null' => TRUE,
      'comment' => 'Id do Cliente no ERP Bom Controle; NULL = sem vinculo.',
      'after' => 'legacy_id',
    ],
    'bomcontrole_synced' => [
      'type' => 'DATETIME',
      'null' => TRUE,
      'comment' => 'Ultima sincronizacao BEM-SUCEDIDA do cadastro com o Bom Controle.',
      'after' => 'bomcontrole_customer_id',
    ],
  ];

  public function up()
  {
    foreach ($this->colunas as $nome => $definicao) {
      if (!$this->db->field_exists($nome, 'crm_customers')) {
        $this->dbforge->add_column('crm_customers', [$nome => $definicao]);
      }
    }

    $this->db->query($this->montarViewClientes(TRUE));
  }

  public function down()
  {
    // A view volta ao formato anterior ANTES de as colunas caírem: view
    // apontando para coluna inexistente derruba toda tela que a lê (mesma
    // ordem da 015, da 017, da 019 e da 020).
    $this->db->query($this->montarViewClientes(FALSE));

    foreach (array_keys($this->colunas) as $nome) {
      if ($this->db->field_exists($nome, 'crm_customers')) {
        $this->dbforge->drop_column('crm_customers', $nome);
      }
    }
  }

  /**
   * A crm_customers_v da 020, com ou sem as colunas desta migration.
   *
   * @param  bool $comColunasNovas
   * @return string
   */
  private function montarViewClientes($comColunasNovas)
  {
    $ie = $comColunasNovas
      ? "  `crm_customers`.`state_registration` AS `state_registration`,\n"
      : '';

    $bomcontrole = $comColunasNovas
      ? "  `crm_customers`.`bomcontrole_customer_id` AS `bomcontrole_customer_id`,\n"
      . "  `crm_customers`.`bomcontrole_synced` AS `bomcontrole_synced`,\n"
      : '';

    return "
CREATE OR REPLACE VIEW `crm_customers_v` AS
SELECT
  `crm_customers`.`id` AS `id`,
  `crm_customers`.`id_company` AS `id_company`,
  `crm_customers`.`legacy_id` AS `legacy_id`,
{$bomcontrole}  `crm_customers`.`type` AS `type`,
  `crm_customers`.`document` AS `document`,
  `crm_customers`.`name` AS `name`,
  `crm_customers`.`byname` AS `byname`,
{$ie}  `crm_customers`.`email` AS `email`,
  `crm_customers`.`address` AS `address`,
  `crm_customers`.`address_number` AS `address_number`,
  `crm_customers`.`address_complement` AS `address_complement`,
  `crm_customers`.`address_district` AS `address_district`,
  `crm_customers`.`address_zip` AS `address_zip`,
  `crm_customers`.`id_state` AS `id_state`,
  `crm_customers`.`id_city` AS `id_city`,
  `crm_customers`.`attributes` AS `attributes`,
  `crm_customers`.`created` AS `created`,
  `crm_customers`.`created_by` AS `created_by`,
  `crm_customers`.`modified` AS `modified`,
  `crm_customers`.`modified_by` AS `modified_by`,
  `crm_companies`.`byname` AS `company_byname`,
  `crm_country_cities`.`name` AS `city_name`,
  `crm_country_states`.`name` AS `state_name`,
  `crm_country_states`.`uf` AS `state_uf`,
  `crm_users`.`name` AS `created_user`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `contracts_count`,
  (SELECT COUNT(*) FROM `crm_contracts` WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id` AND `crm_contracts`.`status` = 'vigente') AS `active_contracts_count`,
  (SELECT GROUP_CONCAT(DISTINCT `crm_contracts_services`.`id_service_type`)
     FROM `crm_contracts_services`
     JOIN `crm_contracts` ON(`crm_contracts`.`id` = `crm_contracts_services`.`id_contract`)
    WHERE `crm_contracts`.`id_customer` = `crm_customers`.`id`) AS `service_type_ids`
FROM ((( `crm_customers`
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_customers`.`id_company`))
  LEFT JOIN `crm_country_cities` ON(`crm_country_cities`.`id` = `crm_customers`.`id_city`))
  LEFT JOIN `crm_country_states` ON(`crm_country_states`.`id` = `crm_customers`.`id_state`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_customers`.`created_by`)
";
  }
}
