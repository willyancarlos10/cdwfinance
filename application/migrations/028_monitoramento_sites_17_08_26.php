<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Monitoramento dos sites dos clientes: DNS ao vivo e estado da home.
 *
 * Até aqui o sistema só sabia o que o REGISTRADOR declara: o WHOIS/RDAP grava
 * `whois_nameservers` e detecta troca de NS, mas roda semanal, gasta quota de
 * API paga e só enxerga domínio que está num servidor nosso. Ninguém nunca abriu
 * o site do cliente. Consequência: cliente que apontou o domínio para outro
 * lugar, ou cuja home foi substituída ou derrubada, só era descoberto quando
 * alguém reclamava.
 *
 * Esta migration cria o estado e o histórico de uma rotina diária que responde
 * duas perguntas por domínio de contrato vigente — o NS mudou? a home mudou ou
 * quebrou? — e, de brinde, o que sai na mesma requisição: site fora do ar,
 * certificado vencendo e página de erro/suspensão.
 *
 * ------------------------------------------------------------------
 * Por que uma tabela nova, e não colunas em crm_servers_domains
 * ------------------------------------------------------------------
 * O sujeito é outro. `crm_servers_domains` modela CONTA DE HOSPEDAGEM: o mesmo
 * nome aparece uma vez por servidor, e `foo.com` e `www.foo.com` são duas
 * linhas. NS e título são propriedades do NOME, não da conta — monitorar por
 * linha faria a mesma consulta duas ou três vezes e geraria o mesmo evento em
 * duplicata. Além disso, a população daqui inclui domínio SEM vínculo de
 * servidor (hospedado fora), que não tem linha lá nenhuma — e é justamente onde
 * a troca de NS costuma acontecer.
 *
 * ------------------------------------------------------------------
 * Por que DOIS nomes na mesma linha (`domain` e `apex`)
 * ------------------------------------------------------------------
 * As duas checagens têm alvos diferentes:
 *
 * - DNS é do APEX. `dns_get_record('www.exemplo.com.br', DNS_NS)` volta vazio —
 *   NS só existe no ápice da zona. Pior, o comportamento varia entre zonas: há
 *   resolvedor que devolve o NS da zona-pai e há quem devolva nada, então
 *   consultar o host cadastrado acerta por acidente ou erra em silêncio.
 * - HTTP é do HOST. Se o contrato tem `loja.cliente.com.br`, o site a abrir é
 *   esse; reduzir ao apex checaria a home errada. E ~18% da base é subdomínio,
 *   então não é caso raro.
 *
 * `check_host` é a terceira peça: muitos sites só servem em `www.`, com o apex
 * sem registro A. A rotina descobre na primeira checagem qual host responde e
 * grava aqui, para as rodadas seguintes começarem pelo caminho certo.
 *
 * ------------------------------------------------------------------
 * O recorte da população: crm_service_types.monitor_site
 * ------------------------------------------------------------------
 * Nem todo domínio de contrato vigente tem site. Há contrato só de `E-mails` (o
 * caso comum de site num painel e e-mail em outro) e de `Gerenciamento de
 * Domínios`, que é só o registro. Sem recorte, cada um viraria um "site fora do
 * ar" PERMANENTE no resumo diário — e um e-mail com dezenas de falsos positivos
 * faz a rotina ser desligada na primeira semana.
 *
 * O flag mora no tipo de serviço, e não numa lista de exceções, porque a
 * pergunta "este contrato tem site?" já é respondida pelo que foi contratado. O
 * catálogo é global (sem `id_company`), então o flag é global também.
 *
 * Contrato com DOIS tipos entra se PELO MENOS UM tiver o flag: "E-mails + Site
 * institucional" tem site, e 215 dos contratos vigentes têm dois tipos.
 *
 * ------------------------------------------------------------------
 * Campos que existem para NÃO gerar alarme falso
 * ------------------------------------------------------------------
 * - `ns_pending`: durante uma troca de NS o resolvedor devolve o conjunto antigo
 *   e o novo alternadamente por até 48h. O conjunto novo fica aqui aguardando se
 *   repetir na rodada seguinte antes de virar evento — senão uma troca só
 *   geraria três alarmes (mudou, voltou, mudou de novo).
 * - `consecutive_failures` + `down_since`: `site_fora` só na SEGUNDA rodada com
 *   falha. Duas tentativas dentro da mesma rodada não são redundância — é a
 *   mesma máquina, o mesmo resolvedor e o mesmo caminho de rede, medindo duas
 *   vezes. Isso também mata o flapping do site lento que responde dia sim, dia
 *   não.
 * - `down_notified`: `site_restabelecido` só é emitido se o `site_fora`
 *   correspondente chegou a sair. Sem isso chega um "resolvido" de um problema
 *   que ninguém soube que existiu.
 * - `ssl_notified_for` guarda PARA QUAL data o aviso saiu, e não um booleano —
 *   mesma razão do `adjustment_notified_for` do faturamento. Let's Encrypt vale
 *   90 dias e o certbot renova aos ~30: sem isso, e com limiar de 30 dias, toda
 *   a base entraria em "vencendo" a cada ciclo, para sempre.
 * - `check_status = 'nunca_respondeu'` separa "o site caiu" de "nunca houve site
 *   aqui". O segundo é cadastro a revisar, não incidente.
 *
 * `title` é varchar(255) numa coluna utf8 de 3 bytes: o model remove os
 * caracteres de 4 bytes (emoji) antes de gravar, porque o MySQL REJEITA o INSERT
 * em vez de truncar — e com `db_debug = FALSE` isso falharia em silêncio,
 * deixando o título nunca gravado justamente nos sites que usam emoji no título.
 *
 * ------------------------------------------------------------------
 * Eventos à parte do estado
 * ------------------------------------------------------------------
 * `crm_domains_monitor_events` segue o molde de `crm_domains_whois_history`
 * (migration 012), inclusive o `domain` denormalizado e o `ON DELETE SET NULL`:
 * a mudança que já aconteceu não pode ser apagada porque o domínio saiu do
 * contrato.
 *
 * `notified` é o que torna o e-mail idempotente — o resumo manda o que está em 0
 * e marca 1, e só DEPOIS de o envio ser confirmado. `acknowledged` é o "ciente"
 * da tela, para o feed não ficar eternamente vermelho.
 *
 * O cliente NÃO é denormalizado no evento: um domínio pode estar em mais de um
 * contrato, de clientes diferentes. A tela e o e-mail resolvem em uma query
 * agregada, como o `Servidores::clientesPorDominio()` já faz.
 */
class Migration_Monitoramento_sites_17_08_26 extends CI_Migration
{
  /**
   * Tipos de serviço que implicam "existe uma home para abrir".
   *
   * Semeado só na criação da coluna: numa rodada seguinte a migration não pode
   * desfazer a escolha que o usuário tiver feito na tela de Tipos de serviços.
   * Fora da lista ficam `E-mails` e `Gerenciamento de Domínios`, que são
   * exatamente os que não têm site.
   */
  private $tiposComSite = [
    'Site institucional',
    'Loja virtual',
    'Landing page',
    'Sistema',
    'Gestorcar',
    'Gestorsolar',
    'Gestorfarma',
    'Enerhub',
    'CDWChat',
    'Pág de Links / Espera',
  ];

  public function up()
  {
    $this->colunaMonitorSite();
    $this->criarTabelaMonitor();
    $this->criarTabelaEventos();
    $this->criarViewMonitor();
    $this->criarViewEventos();
    $this->criarViewServiceTypes(TRUE);

    // Registra a rotina no painel de CRON (Painel::cron lê de crm_cron_logs).
    // Sem esta linha a rotina não aparece na tela e o isCronActive() a trata
    // como inexistente.
    $existente = $this->db->get_where('crm_cron_logs', ['name' => 'cron_monitorar_sites'])->row();
    if (empty($existente)) {
      $this->db->insert('crm_cron_logs', ['name' => 'cron_monitorar_sites', 'active' => 'S']);
    }
  }

  public function down()
  {
    // As views voltam ANTES de as colunas/tabelas caírem: view apontando para
    // coluna inexistente derruba toda tela que a lê.
    $this->criarViewServiceTypes(FALSE);
    $this->db->query('DROP VIEW IF EXISTS `crm_domains_monitor_events_v`');
    $this->db->query('DROP VIEW IF EXISTS `crm_domains_monitor_v`');

    // Eventos antes do monitor: a FK aponta para lá.
    $this->dbforge->drop_table('crm_domains_monitor_events', TRUE);
    $this->dbforge->drop_table('crm_domains_monitor', TRUE);

    if ($this->db->field_exists('monitor_site', 'crm_service_types')) {
      $this->dbforge->drop_column('crm_service_types', 'monitor_site');
    }

    $this->db->where('name', 'cron_monitorar_sites')->delete('crm_cron_logs');
  }

  // ------------------------------------------------------------------
  // Recorte da população
  // ------------------------------------------------------------------

  private function colunaMonitorSite()
  {
    if ($this->db->field_exists('monitor_site', 'crm_service_types')) return;

    $this->dbforge->add_column('crm_service_types', [
      'monitor_site' => [
        'type' => 'TINYINT',
        'constraint' => 1,
        'null' => FALSE,
        'default' => 0,
        'comment' => '1 = contrato deste tipo tem site, e seus dominios entram no monitoramento.',
        'after' => 'name',
      ],
    ]);

    // Semeadura only-on-create: liga o flag nos tipos que hoje têm home.
    $this->db->where_in('name', $this->tiposComSite)->update('crm_service_types', ['monitor_site' => 1]);
  }

  // ------------------------------------------------------------------
  // Tabelas
  // ------------------------------------------------------------------

  private function criarTabelaMonitor()
  {
    $query = "
CREATE TABLE IF NOT EXISTS `crm_domains_monitor` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_company` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL COMMENT 'Host monitorado: minusculo, sem esquema/caminho e sem o www. inicial.',
  `apex` varchar(255) NOT NULL COMMENT 'Dominio registravel (eTLD+1) em ASCII/punycode. E onde o NS e consultado.',
  `check_host` varchar(255) DEFAULT NULL COMMENT 'Host que de fato respondeu (pode ser www.); NULL = ainda nao descoberto.',
  `active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Gerido pela rotina: 1 enquanto houver contrato vigente com tipo monitoravel.',
  `muted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Decisao humana: 1 para de avisar sobre este dominio.',
  `ns_list` varchar(500) DEFAULT NULL COMMENT 'Conjunto de NS do DNS ao vivo: minusculo, unico e ORDENADO. Mesmo formato de whois_nameservers.',
  `ns1` varchar(255) DEFAULT NULL COMMENT 'Primeiro NS da lista ordenada (exibicao).',
  `ns2` varchar(255) DEFAULT NULL COMMENT 'Segundo NS da lista ordenada (exibicao).',
  `ns_status` varchar(20) DEFAULT NULL COMMENT 'ok | sem_registro | nao_checado',
  `ns_pending` varchar(500) DEFAULT NULL COMMENT 'Conjunto novo aguardando confirmacao na proxima rodada (anti-flapping de propagacao).',
  `ns_message` varchar(500) DEFAULT NULL,
  `ns_changed` datetime DEFAULT NULL COMMENT 'Quando a ultima troca de NS foi confirmada.',
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `http_result` varchar(20) DEFAULT NULL COMMENT 'ok | bloqueado | http_erro | timeout | dns | conexao | redirect_loop | nao_checado',
  `http_final_url` varchar(500) DEFAULT NULL COMMENT 'URL final apos os redirects; e dela que sai o redirecionamento externo.',
  `http_message` varchar(500) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL COMMENT 'Titulo da home, normalizado e SEM caracteres de 4 bytes (a coluna e utf8 de 3).',
  `title_changed` datetime DEFAULT NULL,
  `flag` varchar(30) DEFAULT NULL COMMENT 'Marcador de problema no titulo: suspenso | index_of | padrao_servidor | parking | sem_titulo',
  `down_since` datetime DEFAULT NULL COMMENT 'Primeira falha da sequencia atual; NULL = esta no ar.',
  `down_notified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = o evento site_fora ja saiu, entao o restabelecido pode sair.',
  `consecutive_failures` int(11) unsigned NOT NULL DEFAULT 0,
  `ssl_expiration_date` date DEFAULT NULL,
  `ssl_issuer` varchar(150) DEFAULT NULL,
  `ssl_status` varchar(20) DEFAULT NULL COMMENT 'ok | nome_divergente | vencido | ausente',
  `ssl_notified_for` date DEFAULT NULL COMMENT 'Para QUAL vencimento o aviso ja saiu; renovacao muda a data e reabilita o aviso.',
  `last_check` datetime DEFAULT NULL,
  `last_success` datetime DEFAULT NULL,
  `check_status` varchar(20) DEFAULT NULL COMMENT 'ok | parcial | erro | nunca_respondeu',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `modified` datetime DEFAULT NULL,
  `modified_by` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domains_monitor` (`id_company`,`domain`),
  KEY `apex` (`apex`),
  KEY `created_by` (`created_by`),
  KEY `modified_by` (`modified_by`),
  CONSTRAINT `crm_domains_monitor_ibfk_1` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_domains_monitor_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_domains_monitor_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    // Índice da seleção dos pendentes: a rodada é retomável e varre por
    // "quem foi checado há mais tempo" dentro do tenant, entre os ativos.
    $this->criarIndice('crm_domains_monitor', 'idx_monitor_company_active_check', '`id_company`,`active`,`last_check`');
  }

  private function criarTabelaEventos()
  {
    $query = "
CREATE TABLE IF NOT EXISTS `crm_domains_monitor_events` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_monitor` int(11) unsigned DEFAULT NULL COMMENT 'Linha de origem; NULL quando o dominio saiu do monitoramento.',
  `id_company` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL COMMENT 'Denormalizado para o evento sobreviver a exclusao do monitor.',
  `type` varchar(30) NOT NULL COMMENT 'ns_alterado | site_fora | site_restabelecido | titulo_alterado | marcador_detectado | ssl_vencendo | redirecionamento_externo',
  `severity` varchar(10) NOT NULL COMMENT 'critico | alerta | info',
  `old_value` varchar(500) DEFAULT NULL,
  `new_value` varchar(500) DEFAULT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `detected` datetime NOT NULL,
  `notified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ja entrou num resumo enviado. E o que impede o reenvio.',
  `acknowledged` tinyint(1) NOT NULL DEFAULT 0,
  `acknowledged_by` int(11) unsigned DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_monitor` (`id_monitor`),
  KEY `domain` (`domain`),
  KEY `detected` (`detected`),
  KEY `acknowledged_by` (`acknowledged_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_domains_monitor_events_ibfk_1` FOREIGN KEY (`id_monitor`) REFERENCES `crm_domains_monitor` (`id`) ON DELETE SET NULL,
  CONSTRAINT `crm_domains_monitor_events_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_domains_monitor_events_ibfk_3` FOREIGN KEY (`acknowledged_by`) REFERENCES `crm_users` (`id`),
  CONSTRAINT `crm_domains_monitor_events_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
    $this->db->query($query);

    // O resumo por e-mail busca "o que ainda não foi avisado neste tenant"; o
    // feed da tela busca "o que está em aberto, mais recente primeiro".
    $this->criarIndice('crm_domains_monitor_events', 'idx_monitor_events_pendentes', '`id_company`,`notified`');
    $this->criarIndice('crm_domains_monitor_events', 'idx_monitor_events_feed', '`id_company`,`acknowledged`,`detected`');
  }

  // ------------------------------------------------------------------
  // Views
  // ------------------------------------------------------------------

  /**
   * `situation` é coluna derivada pelo mesmo motivo do `whois_bucket`: o
   * `Global_model::getFilter()` só sabe montar `WHERE campo = valor`, e a faixa
   * (IS NULL, intervalo de data, combinação de flags) não caberia nele. Assim o
   * filtro da tela, a cor do badge e o card usam os MESMOS limiares.
   *
   * A ordem do CASE é a da urgência, e as faixas são mutuamente exclusivas —
   * silenciado e inativo vêm primeiro justamente para não aparecerem como
   * incidente.
   */
  private function criarViewMonitor()
  {
    $query = "
CREATE OR REPLACE VIEW `crm_domains_monitor_v` AS
SELECT
  `crm_domains_monitor`.`id` AS `id`,
  `crm_domains_monitor`.`id_company` AS `id_company`,
  `crm_domains_monitor`.`domain` AS `domain`,
  `crm_domains_monitor`.`apex` AS `apex`,
  `crm_domains_monitor`.`check_host` AS `check_host`,
  `crm_domains_monitor`.`active` AS `active`,
  `crm_domains_monitor`.`muted` AS `muted`,
  `crm_domains_monitor`.`ns_list` AS `ns_list`,
  `crm_domains_monitor`.`ns1` AS `ns1`,
  `crm_domains_monitor`.`ns2` AS `ns2`,
  `crm_domains_monitor`.`ns_status` AS `ns_status`,
  `crm_domains_monitor`.`ns_pending` AS `ns_pending`,
  `crm_domains_monitor`.`ns_message` AS `ns_message`,
  `crm_domains_monitor`.`ns_changed` AS `ns_changed`,
  `crm_domains_monitor`.`http_status` AS `http_status`,
  `crm_domains_monitor`.`http_result` AS `http_result`,
  `crm_domains_monitor`.`http_final_url` AS `http_final_url`,
  `crm_domains_monitor`.`http_message` AS `http_message`,
  `crm_domains_monitor`.`title` AS `title`,
  `crm_domains_monitor`.`title_changed` AS `title_changed`,
  `crm_domains_monitor`.`flag` AS `flag`,
  `crm_domains_monitor`.`down_since` AS `down_since`,
  `crm_domains_monitor`.`down_notified` AS `down_notified`,
  `crm_domains_monitor`.`consecutive_failures` AS `consecutive_failures`,
  `crm_domains_monitor`.`ssl_expiration_date` AS `ssl_expiration_date`,
  `crm_domains_monitor`.`ssl_issuer` AS `ssl_issuer`,
  `crm_domains_monitor`.`ssl_status` AS `ssl_status`,
  `crm_domains_monitor`.`ssl_notified_for` AS `ssl_notified_for`,
  `crm_domains_monitor`.`last_check` AS `last_check`,
  `crm_domains_monitor`.`last_success` AS `last_success`,
  `crm_domains_monitor`.`check_status` AS `check_status`,
  CASE
    WHEN `crm_domains_monitor`.`muted` = 1 THEN 'silenciado'
    WHEN `crm_domains_monitor`.`active` = 0 THEN 'inativo'
    WHEN `crm_domains_monitor`.`last_check` IS NULL THEN 'pendente'
    WHEN `crm_domains_monitor`.`check_status` = 'nunca_respondeu' THEN 'nunca_respondeu'
    WHEN `crm_domains_monitor`.`down_since` IS NOT NULL THEN 'fora'
    WHEN `crm_domains_monitor`.`flag` IS NOT NULL THEN 'marcador'
    WHEN `crm_domains_monitor`.`ssl_status` IN ('vencido', 'nome_divergente') THEN 'ssl_problema'
    WHEN `crm_domains_monitor`.`ssl_expiration_date` IS NOT NULL
     AND `crm_domains_monitor`.`ssl_expiration_date` <= CURDATE() + INTERVAL 14 DAY THEN 'ssl_vencendo'
    WHEN `crm_domains_monitor`.`http_result` = 'bloqueado' THEN 'bloqueado'
    ELSE 'ok'
  END AS `situation`,
  CASE
    WHEN `crm_domains_monitor`.`down_since` IS NULL THEN NULL
    ELSE TIMESTAMPDIFF(DAY, `crm_domains_monitor`.`down_since`, NOW())
  END AS `days_down`,
  `crm_domains_monitor`.`created` AS `created`,
  `crm_domains_monitor`.`created_by` AS `created_by`,
  `crm_domains_monitor`.`modified` AS `modified`,
  `crm_domains_monitor`.`modified_by` AS `modified_by`,
  `crm_companies`.`byname` AS `company_byname`
FROM (`crm_domains_monitor`
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_domains_monitor`.`id_company`))
";
    $this->db->query($query);
  }

  /**
   * LEFT JOIN no monitor de propósito: o evento sobrevive à exclusão do domínio
   * monitorado (`ON DELETE SET NULL`), e com INNER a mudança que já aconteceu
   * sumiria da tela — o oposto do que um histórico serve para fazer.
   */
  private function criarViewEventos()
  {
    $query = "
CREATE OR REPLACE VIEW `crm_domains_monitor_events_v` AS
SELECT
  `crm_domains_monitor_events`.`id` AS `id`,
  `crm_domains_monitor_events`.`id_monitor` AS `id_monitor`,
  `crm_domains_monitor_events`.`id_company` AS `id_company`,
  `crm_domains_monitor_events`.`domain` AS `domain`,
  `crm_domains_monitor_events`.`type` AS `type`,
  `crm_domains_monitor_events`.`severity` AS `severity`,
  `crm_domains_monitor_events`.`old_value` AS `old_value`,
  `crm_domains_monitor_events`.`new_value` AS `new_value`,
  `crm_domains_monitor_events`.`detail` AS `detail`,
  `crm_domains_monitor_events`.`detected` AS `detected`,
  `crm_domains_monitor_events`.`notified` AS `notified`,
  `crm_domains_monitor_events`.`acknowledged` AS `acknowledged`,
  `crm_domains_monitor_events`.`acknowledged_by` AS `acknowledged_by`,
  `crm_domains_monitor_events`.`acknowledged_at` AS `acknowledged_at`,
  `crm_domains_monitor_events`.`created_by` AS `created_by`,
  `crm_domains_monitor`.`apex` AS `apex`,
  `crm_domains_monitor`.`check_host` AS `check_host`,
  `crm_domains_monitor`.`muted` AS `muted`,
  `crm_users`.`name` AS `acknowledged_user`,
  `crm_companies`.`byname` AS `company_byname`
FROM (((`crm_domains_monitor_events`
  LEFT JOIN `crm_domains_monitor` ON(`crm_domains_monitor`.`id` = `crm_domains_monitor_events`.`id_monitor`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_domains_monitor_events`.`acknowledged_by`))
  JOIN `crm_companies` ON(`crm_companies`.`id` = `crm_domains_monitor_events`.`id_company`))
";
    $this->db->query($query);
  }

  /**
   * @param  bool $comMonitorSite FALSE devolve a view ao formato da 004
   * @return void
   */
  private function criarViewServiceTypes($comMonitorSite)
  {
    $coluna = $comMonitorSite
      ? "  `crm_service_types`.`monitor_site` AS `monitor_site`,\n"
      : '';

    $query = "
CREATE OR REPLACE VIEW `crm_service_types_v` AS
SELECT
  `crm_service_types`.`id` AS `id`,
  `crm_service_types`.`id_status` AS `id_status`,
  `crm_service_types`.`name` AS `name`,
" . $coluna . "  `crm_service_types`.`created` AS `created`,
  `crm_service_types`.`created_by` AS `created_by`,
  `crm_service_types`.`modified` AS `modified`,
  `crm_service_types`.`modified_by` AS `modified_by`,
  `crm_status`.`name` AS `status_name`,
  `crm_status`.`color` AS `status_color`,
  `crm_users`.`name` AS `modified_user`
FROM ((`crm_service_types`
  JOIN `crm_status` ON(`crm_status`.`id` = `crm_service_types`.`id_status`))
  LEFT JOIN `crm_users` ON(`crm_users`.`id` = `crm_service_types`.`modified_by`))
";
    $this->db->query($query);
  }

  // ------------------------------------------------------------------
  // Auxiliar
  // ------------------------------------------------------------------

  /**
   * Não existe `index_exists()` no CI3, e a migration roda a CADA requisição —
   * um `CREATE INDEX` repetido é erro fatal.
   *
   * @param  string $tabela
   * @param  string $indice nome da chave
   * @param  string $colunas lista já escapada, ex.: '`a`,`b`'
   * @return void
   */
  private function criarIndice($tabela, $indice, $colunas)
  {
    $existente = $this->db->query('SHOW INDEX FROM `' . $tabela . '` WHERE `Key_name` = ?', [$indice])->row();
    if (empty($existente)) {
      $this->db->query('CREATE INDEX `' . $indice . '` ON `' . $tabela . '` (' . $colunas . ')');
    }
  }
}
