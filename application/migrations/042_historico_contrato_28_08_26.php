<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Histórico do contrato: a trilha de quem mudou o estado, quando e por onde.
 *
 * A aba "Históricos" da tela do contrato era placeholder desde a migration 009.
 * O que a fez existir foi um relato de campo: contratos apareciam suspensos e
 * reativados "sozinhos", e não havia como responder à pergunta mais básica —
 * QUANDO mudou e QUEM mudou.
 *
 * E não havia mesmo. A trilha existente era `modified`/`modified_by` na própria
 * `crm_contracts`, que tem dois defeitos fatais para esta pergunta:
 *
 *   1. **Guarda só a ÚLTIMA alteração.** Suspender e reativar no mesmo dia
 *      deixa uma linha só, dizendo "reativado" — a suspensão desaparece, e com
 *      ela a evidência de que algo aconteceu.
 *   2. **A importação do gestor-interno REESCREVE os dois campos com valores da
 *      ORIGEM** (`Import_gestor_model::upsert()` grava `modified` =
 *      `$origem['updatedAt']`). Ou seja: a linha era reescrita hoje e o carimbo
 *      dizia que a alteração foi há duas semanas. A trilha apontava para o
 *      passado justamente quando alguém ia investigar.
 *
 * Medido nesta base antes da migration: 399 dos 402 contratos com
 * `modified_by = 3 (PROCESSOS AUTOMÁTICOS)` — o usuário da importação. A
 * "automação misteriosa" era o upsert da importação, que grava `status` junto
 * dos demais campos e devolve o contrato ao estado do dump a cada execução.
 *
 * DECISÕES
 *
 *  - **Tabela filha, e não colunas na `crm_contracts`.** É a mesma distinção da
 *    migration 033: `notification_config` é CONFIGURAÇÃO (a lista inteira é
 *    salva de uma vez, nada tem vida própria) e virou coluna JSON; isto aqui é
 *    REGISTRO, uma linha por acontecimento, que nasce e nunca mais muda —
 *    exatamente como `crm_contracts_adjustments`. Não há "gravar o histórico
 *    de novo".
 *
 *  - **`ON DELETE SET NULL` no contrato, com o rótulo DENORMALIZADO.** É o
 *    molde de `crm_domains_monitor_events` (028), e aqui o motivo é ainda mais
 *    forte: a exclusão do contrato É um dos eventos registrados. Com CASCADE, a
 *    linha que diz "o contrato #1379 foi excluído por fulano" seria apagada
 *    pelo próprio DELETE que ela documenta — o histórico sumiria no exato
 *    evento em que ele mais importa. Por isso `contract_label` e `id_customer`
 *    são cópias, não JOINs.
 *
 *  - **`status_from`/`status_to` ao lado do `event`, e não no lugar dele.**
 *    Parecem redundantes ("suspenso" já diz vigente→suspenso), mas não são:
 *    `criado` e `excluido` não têm um dos dois lados, e a IMPORTAÇÃO não tem
 *    ação nenhuma — ela tem uma transição observada. Sem o par, o evento vindo
 *    do upsert não teria como ser descrito.
 *
 *  - **`origin` é o que responde à pergunta que originou esta migration.**
 *    `painel` | `importacao` | `cron` | `api`. Uma trilha que diz "suspenso por
 *    PROCESSOS AUTOMÁTICOS" não distingue o cron da importação e deixa o
 *    usuário exatamente onde estava; `origin = 'importacao'` nomeia o culpado.
 *    É varchar com catálogo em PHP, e não ENUM, pelo idioma do projeto
 *    (`status`, `cycle`, `billing_source` são todos assim) — origem nova não
 *    pede ALTER TABLE.
 *
 *  - **`notified` é datetime, não booleano**, como em `crm_contracts_adjustments`:
 *    "quando o aviso foi ENFILEIRADO" responde a mais perguntas que "se foi", e
 *    é o que permite ver, no histórico, um evento antigo que nunca chegou a
 *    virar e-mail porque o aviso estava desligado na época.
 *
 *  - **A tabela NÃO entra na allowlist do `json_posttoggle_status`**: linha de
 *    histórico não se ativa nem se inativa — e não se edita nem se apaga pela
 *    tela. Uma trilha de auditoria editável não é trilha de auditoria.
 *
 * O grupo `contratos` de `crm_general_settings` (destinatários e quais eventos
 * avisam) NÃO é semeado aqui: `getGroupValue()` já recebe o default do
 * chamador, e o seed da 032 existia porque um catálogo vazio deixava o modal de
 * encerramento inoperante — aqui o vazio é um estado válido ("ninguém
 * configurou ainda", que cai no e-mail da empresa).
 *
 * A `crm_contracts_v` NÃO é recriada: nenhuma coluna dela muda.
 */
class Migration_Historico_contrato_28_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_contracts_history')) {
      $this->db->query(
        "CREATE TABLE `crm_contracts_history` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
          `id_contract` int(11) unsigned DEFAULT NULL COMMENT 'NULL depois que o contrato e excluido — o evento sobrevive a ele.',
          `id_company` int(11) unsigned NOT NULL,
          `id_customer` int(11) unsigned DEFAULT NULL COMMENT 'Denormalizado do contrato, para o evento nao depender do JOIN.',
          `contract_label` varchar(255) NOT NULL COMMENT 'Denormalizado: identifica o contrato depois de ele deixar de existir.',
          `event` varchar(20) NOT NULL COMMENT 'criado | suspenso | reativado | encerrado | reaberto | excluido',
          `status_from` varchar(20) DEFAULT NULL COMMENT 'Estado antes. NULL em criado.',
          `status_to` varchar(20) DEFAULT NULL COMMENT 'Estado depois. NULL em excluido.',
          `origin` varchar(20) NOT NULL DEFAULT 'painel' COMMENT 'painel | importacao | cron | api — de onde partiu a mudanca.',
          `reason` varchar(50) DEFAULT NULL COMMENT 'Slug do motivo, no encerramento.',
          `comments` varchar(500) DEFAULT NULL COMMENT 'Observacao de quem executou.',
          `detail` varchar(500) DEFAULT NULL COMMENT 'O que aconteceu nos paineis (contas suspensas, bloqueadas).',
          `notified` datetime DEFAULT NULL COMMENT 'Quando o aviso por e-mail foi ENFILEIRADO. NULL = nao avisado.',
          `created` datetime NOT NULL,
          `created_by` int(11) unsigned NOT NULL,
          PRIMARY KEY (`id`),
          KEY `id_company` (`id_company`),
          KEY `created_by` (`created_by`),
          KEY `id_customer` (`id_customer`),
          KEY `idx_contracts_history_contrato` (`id_contract`,`created`),
          KEY `idx_contracts_history_feed` (`id_company`,`created`),
          CONSTRAINT `crm_contracts_history_ibfk_1` FOREIGN KEY (`id_contract`) REFERENCES `crm_contracts` (`id`) ON DELETE SET NULL,
          CONSTRAINT `crm_contracts_history_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
          CONSTRAINT `crm_contracts_history_ibfk_3` FOREIGN KEY (`id_customer`) REFERENCES `crm_customers` (`id`) ON DELETE SET NULL,
          CONSTRAINT `crm_contracts_history_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci"
      );
    }

    // Os JOINs são todos LEFT: com o contrato excluído as duas pontas somem, e
    // um INNER faria o evento mais importante do histórico desaparecer da view
    // (a mesma armadilha que a crm_customers_v evita com id_city).
    $this->db->query(
      "CREATE OR REPLACE VIEW `crm_contracts_history_v` AS
       SELECT
         `h`.`id`,
         `h`.`id_contract`,
         `h`.`id_company`,
         `h`.`id_customer`,
         `h`.`contract_label`,
         `h`.`event`,
         `h`.`status_from`,
         `h`.`status_to`,
         `h`.`origin`,
         `h`.`reason`,
         `h`.`comments`,
         `h`.`detail`,
         `h`.`notified`,
         `h`.`created`,
         `h`.`created_by`,
         `u`.`name` AS `created_user`,
         `cu`.`name` AS `customer_name`,
         `c`.`status` AS `contract_status`
       FROM `crm_contracts_history` `h`
       LEFT JOIN `crm_users` `u` ON `u`.`id` = `h`.`created_by`
       LEFT JOIN `crm_customers` `cu` ON `cu`.`id` = `h`.`id_customer`
       LEFT JOIN `crm_contracts` `c` ON `c`.`id` = `h`.`id_contract`"
    );
  }

  public function down()
  {
    $this->db->query('DROP VIEW IF EXISTS `crm_contracts_history_v`');
    $this->db->query('DROP TABLE IF EXISTS `crm_contracts_history`');
  }
}
