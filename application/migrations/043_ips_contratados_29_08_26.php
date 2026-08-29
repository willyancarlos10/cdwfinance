<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * IPs contratados: o inventário dos endereços IP que a operação contrata e repassa.
 *
 * Até aqui esse controle vivia fora do sistema, e as duas perguntas que aparecem no
 * dia a dia — "quantos IPs ainda estão livres?" e "de quem é este IP?" — não tinham
 * resposta consultável. O cadastro é deliberadamente mínimo: o endereço, um vínculo
 * OPCIONAL com um cliente da base, e observações.
 *
 * DECISÕES
 *
 *  - **"Alocado" é TER CLIENTE VINCULADO, e não uma coluna de situação.** A tentação
 *    era gravar `situation` ('disponivel' | 'alocado' | 'reservado'), e ela foi
 *    recusada pelo mesmo motivo da migration 015, que tirou `id_status` de
 *    `crm_customers`: quem diz se o cliente está ativo é ter contrato vigente, e um
 *    ativo/inativo no cadastro criava uma segunda verdade sobre o mesmo cliente. Aqui
 *    seria idêntico — um IP marcado "disponível" com cliente vinculado, ou o inverso,
 *    e os cartões do topo passariam a poder discordar da tabela logo abaixo. O vínculo
 *    é a única verdade, e `situation` existe só como coluna DERIVADA da view.
 *
 *  - **`ON DELETE SET NULL` no cliente, e o IP NÃO entra na trava de exclusão de
 *    cliente.** `Clientes::post_excluir` bloqueia a exclusão de cliente que tenha
 *    contratos, anexos ou contatos — todos registros que PERTENCEM ao cliente. O IP
 *    não pertence: ele é inventário nosso, apenas emprestado, e continua existindo (e
 *    sendo pago ao provedor) depois que o cliente sai. Com CASCADE, apagar o cliente
 *    apagaria o IP junto e o inventário encolheria sozinho; com SET NULL ele volta
 *    para "disponível", que é exatamente o estado correto. Por isso também não se
 *    acrescenta IP à trava do `post_excluir` do cliente: não há nada a proteger.
 *
 *  - **Sem `id_status`.** Alocado/disponível já sai do vínculo; um ativo/inativo por
 *    cima é a segunda verdade que a primeira decisão evita, e deixaria os cartões
 *    ambíguos ("IP inativo entra no total?"). Consequência assumida: `crm_ips` NÃO
 *    entra na allowlist de `MY_Controller::json_posttoggle_status()` e a listagem não
 *    tem switch — fora da lista, o endpoint genérico responde "Dados inválidos".
 *
 *  - **`ip` é varchar(15) e a ORDENAÇÃO sai de `ip_long`, derivado na view.** Ordenar
 *    o texto puro põe `10.0.0.10` antes de `10.0.0.9`, que é a primeira coisa que
 *    alguém nota numa lista de IPs. O `INET_ATON()` fica na VIEW, e não escrito no
 *    `order_by()` do controller, pela armadilha já registrada no projeto: o
 *    `protect_identifiers` do CI3 quebra a string do order_by em vírgulas e escaparia
 *    a expressão como se fosse nome de coluna (é a mesma razão de
 *    `crm_domains_monitor_v.situation_order` existir, na migration 030).
 *
 *    Não é coluna FÍSICA de propósito: duas colunas descrevendo o mesmo endereço podem
 *    divergir num UPDATE que esqueça uma delas, e aí a ordenação passaria a mentir sem
 *    nada na tela denunciando. O preço é um filesort na ordenação — irrelevante na
 *    ordem de grandeza de IPs de um tenant. Se um dia incomodar, promover a coluna
 *    física indexada é mudança isolada nesta view.
 *
 *  - **UNIQUE (id_company, ip), e não UNIQUE (ip).** O mesmo endereço pode aparecer em
 *    tenants diferentes (cada um contrata do seu provedor); o que não pode é repetir
 *    dentro do mesmo. É o banco que garante isso — a checagem do controller só troca o
 *    erro seco 1062 por uma mensagem na tela.
 *
 *  - **Sem `legacy_id`.** Não há origem no gestor-interno para importar: este cadastro
 *    nasce vazio.
 *
 * Só IPv4, por decisão de escopo. O `filter_var(..., FILTER_FLAG_IPV4)` do controller é
 * quem normaliza e recusa o resto — inclusive o octeto com zero à esquerda
 * (`010.1.1.1`), que o MySQL aceitaria como texto e criaria uma segunda linha para o
 * mesmo endereço, furando a UNIQUE por dentro.
 */
class Migration_Ips_contratados_29_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_ips')) {
      $this->db->query(
        "CREATE TABLE `crm_ips` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `id_company` int(11) unsigned NOT NULL,
          `id_customer` int(11) unsigned DEFAULT NULL COMMENT 'NULL = IP disponivel. E o vinculo, e nao uma coluna de situacao, que diz se o IP esta alocado.',
          `ip` varchar(15) NOT NULL COMMENT 'IPv4 em notacao decimal pontuada, ja normalizado pelo filter_var.',
          `comments` varchar(500) DEFAULT NULL COMMENT 'Observacoes livres sobre o endereco.',
          `created` datetime NOT NULL,
          `created_by` int(11) unsigned NOT NULL,
          `modified` datetime NOT NULL,
          `modified_by` int(11) unsigned NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_ips_company_ip` (`id_company`,`ip`),
          KEY `id_company` (`id_company`),
          KEY `id_customer` (`id_customer`),
          KEY `created_by` (`created_by`),
          KEY `modified_by` (`modified_by`),
          CONSTRAINT `crm_ips_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
          CONSTRAINT `crm_ips_ibfk_2` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`) ON DELETE SET NULL,
          CONSTRAINT `crm_ips_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
          CONSTRAINT `crm_ips_ibfk_4` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci"
      );
    }

    // Os JOINs são todos LEFT, e no do cliente isso é a regra do módulo, não higiene:
    // com INNER, todo IP DISPONÍVEL — que é justamente o que a tela existe para
    // mostrar — sumiria da listagem. É a armadilha que a crm_companies_v tem com o
    // INNER em cidade e que a crm_customers_v evita de propósito.
    //
    // `situation` e `ip_long` são derivadas aqui porque os dois consumidores não
    // sabem receber expressão: o Global_model::getFilter() só compara `campo = valor`,
    // e o order_by() do CI3 escaparia INET_ATON(ip) como nome de coluna.
    $this->db->query(
      "CREATE OR REPLACE VIEW `crm_ips_v` AS
       SELECT
         `i`.`id`,
         `i`.`id_company`,
         `i`.`id_customer`,
         `i`.`ip`,
         INET_ATON(`i`.`ip`) AS `ip_long`,
         (CASE WHEN `i`.`id_customer` IS NULL THEN 'disponivel' ELSE 'alocado' END) AS `situation`,
         `i`.`comments`,
         `i`.`created`,
         `i`.`created_by`,
         `i`.`modified`,
         `i`.`modified_by`,
         `c`.`name` AS `customer_name`,
         `c`.`byname` AS `customer_byname`,
         `c`.`document` AS `customer_document`,
         `c`.`type` AS `customer_type`,
         `u`.`name` AS `created_user`,
         `m`.`name` AS `modified_user`
       FROM `crm_ips` `i`
       LEFT JOIN `crm_customers` `c` ON `c`.`id` = `i`.`id_customer`
       LEFT JOIN `crm_users` `u` ON `u`.`id` = `i`.`created_by`
       LEFT JOIN `crm_users` `m` ON `m`.`id` = `i`.`modified_by`"
    );
  }

  public function down()
  {
    $this->db->query('DROP VIEW IF EXISTS `crm_ips_v`');
    $this->db->query('DROP TABLE IF EXISTS `crm_ips`');
  }
}
