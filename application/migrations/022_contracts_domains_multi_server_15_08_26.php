<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * O mesmo domínio pode entrar mais de uma vez no contrato, desde que em
 * SERVIDORES diferentes.
 *
 * A UNIQUE (id_contract, domain) da 010 partia de que o domínio existe num
 * servidor só. Na prática não é o caso: é comum a hospedagem do site estar num
 * painel e as contas de e-mail em outro (WHM x DirectAdmin), e cada uma é uma
 * conta própria, com disco próprio, que precisa aparecer no contrato como um
 * cadastro próprio. Com a chave antiga, o segundo cadastro era recusado com
 * "já está cadastrado neste contrato" e o disco daquela conta ficava fora da
 * barra de uso do contrato.
 *
 * A chave passa a ser (id_contract, domain, id_server_domain): o que não pode
 * repetir é o PAR domínio+servidor — o mesmo domínio no mesmo servidor duas
 * vezes continua sendo a mesma conta contada duas vezes, e dobraria o disco.
 *
 * Decisões:
 *  - **A troca é ampliação da chave, nunca falha por dado existente**: toda
 *    linha que satisfazia a chave de 2 colunas satisfaz a de 3.
 *  - **A nova UNIQUE entra ANTES de a antiga sair**, em duas instruções. A FK
 *    `crm_contracts_domains_ibfk_1` (id_contract) precisa de um índice com
 *    `id_contract` à esquerda; a antiga era esse índice, e derrubá-la primeiro
 *    daria erro 1553. A nova também começa por `id_contract` e assume o posto.
 *  - **O caso "sem vínculo" (id_server_domain NULL) fica fora do alcance da
 *    chave**: no MySQL/MariaDB NULL nunca colide com NULL numa UNIQUE, então
 *    nada impede duas linhas (contrato, dominio, NULL). Quem barra isso é
 *    `Contratos::post_salvardominio`. Não é descuido: a alternativa seria uma
 *    coluna gerada com COALESCE(id_server_domain, 0) só para a chave, e o
 *    preço é uma coluna fantasma no cadastro para cobrir um caso que o
 *    formulário já cobre. Vale saber que a poda da sincronização de servidores
 *    (ON DELETE SET NULL) pode CRIAR esse par: dois domínios iguais vinculados
 *    a servidores que sumam viram duas linhas sem vínculo, e a limpeza é
 *    manual.
 *  - A view `crm_contracts_domains_v` não muda — nenhuma coluna entrou ou
 *    saiu, só o índice.
 *
 * O `down()` pode falhar de verdade: se já houver o mesmo domínio duas vezes
 * no mesmo contrato, a chave de 2 colunas não cabe mais nos dados. É o
 * comportamento correto — reverter aqui é perder cadastro.
 */
class Migration_Contracts_domains_multi_server_15_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_contracts_domains')) {
      return;
    }

    if (!$this->indiceExiste('crm_contracts_domains', 'uk_contracts_domains_server')) {
      $this->db->query("ALTER TABLE `crm_contracts_domains` ADD UNIQUE KEY `uk_contracts_domains_server` (`id_contract`,`domain`,`id_server_domain`)");
    }

    if ($this->indiceExiste('crm_contracts_domains', 'uk_contracts_domains')) {
      $this->db->query("ALTER TABLE `crm_contracts_domains` DROP INDEX `uk_contracts_domains`");
    }
  }

  public function down()
  {
    if (!$this->db->table_exists('crm_contracts_domains')) {
      return;
    }

    if (!$this->indiceExiste('crm_contracts_domains', 'uk_contracts_domains')) {
      $this->db->query("ALTER TABLE `crm_contracts_domains` ADD UNIQUE KEY `uk_contracts_domains` (`id_contract`,`domain`)");
    }

    if ($this->indiceExiste('crm_contracts_domains', 'uk_contracts_domains_server')) {
      $this->db->query("ALTER TABLE `crm_contracts_domains` DROP INDEX `uk_contracts_domains_server`");
    }
  }

  /**
   * @param  string $tabela
   * @param  string $indice
   * @return bool
   */
  private function indiceExiste($tabela, $indice)
  {
    $query = $this->db->query("SHOW INDEX FROM `" . $tabela . "` WHERE `Key_name` = ?", [$indice]);

    return $query !== FALSE && $query->num_rows() > 0;
  }
}
