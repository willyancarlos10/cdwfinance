<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Orquestrador do monitoramento de sites.
 *
 * O cron e os controllers só chamam daqui — a library `Site_monitor` mede, este
 * model decide o que a medição SIGNIFICA e o que vira evento. É o mesmo desenho
 * do `Whois_model` sobre os providers de WHOIS.
 *
 * ------------------------------------------------------------------
 * A regra que governa tudo: medição ruim nunca vira mudança
 * ------------------------------------------------------------------
 * Título vazio, DNS que não respondeu, charset ilegível ou certificado ausente
 * são "não medido", e não "mudou para nada": o valor anterior é preservado e
 * nenhum evento nasce. É a mesma regra do `Whois_model::registrarHistorico()`
 * ("valor anterior vazio é primeira leitura, não troca"), e é o que separa uma
 * rotina útil de um gerador diário de 400 falsos positivos.
 *
 * ------------------------------------------------------------------
 * Como a rodada se protege de uma queda da NOSSA rede
 * ------------------------------------------------------------------
 * Uma piscada na saída de internet faz TODOS os domínios falharem ao mesmo
 * tempo. Duas guardas, em camadas:
 *
 * 1. CANÁRIO: antes de qualquer coisa, uma URL sabidamente no ar. Se ela falhar,
 *    a rodada é inconclusiva e termina sem gravar nada.
 *
 * 2. DISJUNTOR: se a proporção de falhas passar de PROPORCAO_FALHA_ABORTA (com
 *    amostra mínima), a rodada para. Uma queda simultânea dessa ordem é
 *    incidente do nosso lado, e o aviso certo é um só, não trezentos.
 *
 * Só os eventos de `site_fora` ficam retidos em memória até o fim da rodada —
 * são os únicos que uma falha de rede consegue disparar em massa. Os demais
 * exigem uma medição BEM-SUCEDIDA para existir, então são gravados na hora.
 * Como `down_since` e `consecutive_failures` são gravados de qualquer forma, um
 * disjuntor que descarte os eventos não perde a detecção: na rodada saudável
 * seguinte o domínio ainda está com as falhas acumuladas e o evento sai ali.
 */
class Site_monitor_model extends CI_Model
{
  /** Intervalo mínimo entre checagens do mesmo domínio. */
  const INTERVALO_HORAS_PADRAO = 20;

  /** Teto de domínios por rodada — trava de sanidade, não política. */
  const LIMITE_POR_RODADA = 2000;

  /**
   * Orçamento de tempo da rodada.
   *
   * Estourou, a rodada para e o resto fica para a próxima. Como a seleção é por
   * `last_check` mais antigo, a fila gira e nenhum domínio fica eternamente sem
   * checagem — sem essa ordenação, o orçamento faria os mesmos primeiros N serem
   * checados todo dia e a cauda nunca.
   */
  const ORCAMENTO_SEGUNDOS = 1800;

  /** Respiro entre domínios, para não parecer varredura. */
  const PAUSA_ENTRE_DOMINIOS_US = 120000;

  /**
   * Limiar do aviso de SSL.
   *
   * 14 dias, e não 30: Let's Encrypt vale 90 dias e o certbot renova aos ~30
   * restantes. Com 30, toda a base entraria em "vencendo" a cada ciclo, para
   * sempre. Chegando a 14, a renovação automática já falhou de verdade.
   */
  const LIMIAR_SSL_DIAS = 14;

  const PROPORCAO_FALHA_ABORTA = 0.30;
  const MINIMO_AMOSTRA_DISJUNTOR = 20;
  const URL_CANARIO = 'https://www.google.com/';

  /** Falhas consecutivas antes de anunciar que o site caiu. */
  const FALHAS_PARA_ANUNCIAR = 2;

  /** Grupo em crm_general_settings. */
  const GRUPO = 'monitoramento';

  /** Teto de linhas por seção do e-mail. */
  const LIMITE_LINHAS_RESUMO = 40;

  public function __construct()
  {
    parent::__construct();
    $this->load->library('site_monitor');
    // registrableDomain() vive lá e já carrega a lista de sufixos públicos:
    // uma segunda implementação divergiria da primeira no primeiro TLD novo.
    $this->load->model('whois_model');
    $this->load->model('general_settings_model');
  }

  // ------------------------------------------------------------------
  // Parâmetros gerais
  // ------------------------------------------------------------------

  /** Intervalo mínimo entre checagens do mesmo domínio, em horas. */
  public function intervaloHoras()
  {
    $valor = (int) $this->general_settings_model->getGroupValue(self::GRUPO, 'monitoramento_intervalo_horas', '');
    return ($valor > 0) ? $valor : self::INTERVALO_HORAS_PADRAO;
  }

  /** Timeout de cada requisição HTTP, em segundos. */
  public function timeoutChecagem()
  {
    $valor = (int) $this->general_settings_model->getGroupValue(self::GRUPO, 'monitoramento_timeout', '');
    return ($valor > 0) ? $valor : Site_monitor::TIMEOUT_PADRAO;
  }

  /** Antecedência do aviso de certificado SSL, em dias. */
  public function diasAvisoSsl()
  {
    $valor = (int) $this->general_settings_model->getGroupValue(self::GRUPO, 'monitoramento_ssl_dias_aviso', '');
    return ($valor > 0) ? $valor : self::LIMIAR_SSL_DIAS;
  }

  /**
   * Destinatários do resumo, em cascata: parâmetro geral → e-mail do tenant.
   *
   * Nunca hardcoded, ao contrário do `cron_notificaerrosagencia`: o destino de
   * um aviso operacional tem de ser configurável sem deploy.
   *
   * @param  object|null $empresa linha de crm_companies
   * @return array
   */
  public function destinatariosResumo($empresa = NULL)
  {
    $bruto = (string) $this->general_settings_model->getGroupValue(self::GRUPO, 'monitoramento_email_destinatarios', '');

    $lista = [];
    foreach (preg_split('/[;,\s]+/', $bruto) as $email) {
      $email = trim($email);
      if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $lista[] = $email;
    }

    if (empty($lista) && !empty($empresa->email) && filter_var($empresa->email, FILTER_VALIDATE_EMAIL)) {
      $lista[] = (string) $empresa->email;
    }

    return array_values(array_unique($lista));
  }

  /** Catálogo dos tipos de evento: rótulo e severidade. */
  public function tiposEvento()
  {
    return [
      'site_fora' => ['rotulo' => 'Site fora do ar', 'severity' => 'critico'],
      'marcador_detectado' => ['rotulo' => 'Problema na página inicial', 'severity' => 'critico'],
      'redirecionamento_externo' => ['rotulo' => 'Redireciona para outro domínio', 'severity' => 'critico'],
      'ns_alterado' => ['rotulo' => 'Nameservers alterados', 'severity' => 'alerta'],
      'ssl_vencendo' => ['rotulo' => 'Certificado SSL', 'severity' => 'alerta'],
      'site_restabelecido' => ['rotulo' => 'Site restabelecido', 'severity' => 'info'],
      'titulo_alterado' => ['rotulo' => 'Título da home alterado', 'severity' => 'info'],
    ];
  }

  /** Rótulos dos marcadores de problema encontrados no título. */
  public function rotulosMarcador()
  {
    return [
      'suspenso' => 'Página de conta suspensa',
      'index_of' => 'Listagem de diretório (site sem index)',
      'padrao_servidor' => 'Página padrão do servidor (site não publicado)',
      'parking' => 'Página de domínio estacionado / em construção',
      'sem_titulo' => 'Página sem título (possivelmente em branco ou com erro)',
    ];
  }

  /**
   * Tipos que entram no resumo por e-mail.
   *
   * `titulo_alterado` fica DE FORA de propósito: título muda sozinho o tempo
   * todo (contador de carrinho, promoção da semana, plugin de SEO, teste A/B) e
   * dominaria o resumo até ninguém mais ler — e aí o site fora do ar de verdade
   * passaria batido. Ele continua sendo gravado, com histórico, e vive no feed
   * da tela. O caso que realmente importa (título virou "Account Suspended") é
   * pego pelo `marcador_detectado`, que é o sinal preciso.
   *
   * @return array
   */
  public function tiposNoResumo()
  {
    return ['site_fora', 'marcador_detectado', 'redirecionamento_externo', 'ns_alterado', 'ssl_vencendo', 'site_restabelecido'];
  }

  // ------------------------------------------------------------------
  // População
  // ------------------------------------------------------------------

  /**
   * Reconstrói quem deve ser monitorado neste tenant.
   *
   * Entra domínio de contrato VIGENTE cujo contrato tenha pelo menos um tipo de
   * serviço com `monitor_site = 1`. Contrato com dois tipos ("E-mails + Site
   * institucional") entra pelo tipo que tem site.
   *
   * @param  int $idCompany
   * @param  int $idUser
   * @return array relatório da sincronização
   */
  public function sincronizarPopulacao($idCompany, $idUser)
  {
    $idCompany = (int) $idCompany;
    $agora = date('Y-m-d H:i:s');

    $sql = 'SELECT DISTINCT `cd`.`domain`
              FROM `crm_contracts_domains` `cd`
              JOIN `crm_contracts` `c` ON `c`.`id` = `cd`.`id_contract` AND `c`.`status` = ?
             WHERE `cd`.`id_company` = ?
               AND EXISTS (SELECT 1 FROM `crm_contracts_services` `cs`
                             JOIN `crm_service_types` `st` ON `st`.`id` = `cs`.`id_service_type`
                            WHERE `cs`.`id_contract` = `c`.`id` AND `st`.`monitor_site` = 1)';

    $linhas = $this->db->query($sql, ['vigente', $idCompany])->result();

    $elegiveis = [];
    $descartados = [];

    foreach ($linhas as $linha) {
      $host = $this->normalizarDominio($linha->domain);

      if ($host === '') {
        $descartados[] = (string) $linha->domain;
        continue;
      }

      $apex = $this->apexDe($host);
      if ($apex === '') {
        $descartados[] = (string) $linha->domain;
        continue;
      }

      $elegiveis[$host] = $apex;
    }

    // Domínio que volta ao monitoramento começa do ZERO. Sem isso, o cliente que
    // saiu e voltou geraria "título alterado" comparando com um retrato de meses
    // atrás, e um "restabelecido" de uma queda que ninguém acompanhou.
    $reativados = $this->zerarReativados($idCompany, array_keys($elegiveis), $agora, $idUser);

    $novos = 0;
    foreach ($elegiveis as $host => $apex) {
      if ($this->upsertMonitor($idCompany, $host, $apex, $agora, $idUser)) $novos++;
    }

    $desativados = $this->desativarAusentes($idCompany, array_keys($elegiveis), $agora, $idUser);

    return [
      'elegiveis' => count($elegiveis),
      'novos' => $novos,
      'reativados' => $reativados,
      'desativados' => $desativados,
      // Contado e reportado de propósito: descarte silencioso numa rotina de
      // vigilância é indistinguível de "está tudo bem".
      'descartados' => $descartados,
    ];
  }

  /**
   * Domínios de contrato vigente cujo contrato NÃO tem tipo monitorável.
   *
   * Não entram na vigilância — é decisão registrada —, mas a contagem vai para o
   * log da rodada para a lacuna não ficar invisível: contrato sem tipo de serviço
   * cadastrado é cadastro a completar, não domínio sem site.
   *
   * @param  int $idCompany
   * @return array ['sem_tipo' => int, 'tipo_sem_site' => int]
   */
  public function foraDoRecorte($idCompany)
  {
    $sql = 'SELECT
              SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM `crm_contracts_services` `cs`
                                         WHERE `cs`.`id_contract` = `c`.`id`) THEN 1 ELSE 0 END) AS `sem_tipo`,
              SUM(CASE WHEN EXISTS (SELECT 1 FROM `crm_contracts_services` `cs`
                                     WHERE `cs`.`id_contract` = `c`.`id`)
                        AND NOT EXISTS (SELECT 1 FROM `crm_contracts_services` `cs2`
                                          JOIN `crm_service_types` `st` ON `st`.`id` = `cs2`.`id_service_type`
                                         WHERE `cs2`.`id_contract` = `c`.`id` AND `st`.`monitor_site` = 1)
                       THEN 1 ELSE 0 END) AS `tipo_sem_site`
            FROM `crm_contracts_domains` `cd`
            JOIN `crm_contracts` `c` ON `c`.`id` = `cd`.`id_contract` AND `c`.`status` = ?
           WHERE `cd`.`id_company` = ?';

    $linha = $this->db->query($sql, ['vigente', (int) $idCompany])->row();

    return [
      'sem_tipo' => empty($linha->sem_tipo) ? 0 : (int) $linha->sem_tipo,
      'tipo_sem_site' => empty($linha->tipo_sem_site) ? 0 : (int) $linha->tipo_sem_site,
    ];
  }

  /**
   * @return bool TRUE quando a linha foi criada agora
   */
  private function upsertMonitor($idCompany, $host, $apex, $agora, $idUser)
  {
    // ON DUPLICATE KEY UPDATE, e não SELECT-depois-INSERT: é o banco que resolve
    // a corrida, e o precedente do projeto é o Server_model::upsertDomain(). Uma
    // checagem em PHP estouraria 1062 no meio da rodada — e com db_debug ligado
    // isso mata o processo antes de gravar o crm_cron_logs, deixando o painel de
    // CRON dizendo que a rotina nunca rodou.
    $sql = 'INSERT INTO `crm_domains_monitor`
              (`id_company`, `domain`, `apex`, `active`, `created`, `created_by`)
            VALUES (?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
              `apex` = VALUES(`apex`),
              `active` = 1,
              `modified` = VALUES(`created`),
              `modified_by` = VALUES(`created_by`)';

    $this->db->query($sql, [(int) $idCompany, $host, $apex, $agora, (int) $idUser]);

    // affected_rows: 1 = inseriu, 2 = atualizou, 0 = já estava igual.
    return ($this->db->affected_rows() === 1);
  }

  /**
   * Zera o retrato dos domínios que estavam inativos e voltaram.
   *
   * @return int
   */
  private function zerarReativados($idCompany, array $hosts, $agora, $idUser)
  {
    if (empty($hosts)) return 0;

    $total = 0;
    foreach (array_chunk($hosts, 400) as $bloco) {
      $marcadores = implode(',', array_fill(0, count($bloco), '?'));
      $sql = 'UPDATE `crm_domains_monitor`
                 SET `ns_list` = NULL, `ns1` = NULL, `ns2` = NULL, `ns_status` = NULL,
                     `ns_pending` = NULL, `ns_message` = NULL,
                     `http_status` = NULL, `http_result` = NULL, `http_final_url` = NULL,
                     `http_message` = NULL, `title` = NULL, `flag` = NULL,
                     `down_since` = NULL, `down_notified` = 0, `consecutive_failures` = 0,
                     `ssl_expiration_date` = NULL, `ssl_issuer` = NULL, `ssl_status` = NULL,
                     `ssl_notified_for` = NULL, `check_host` = NULL,
                     `last_check` = NULL, `last_success` = NULL, `check_status` = NULL,
                     `modified` = ?, `modified_by` = ?
               WHERE `id_company` = ? AND `active` = 0 AND `domain` IN (' . $marcadores . ')';

      $this->db->query($sql, array_merge([$agora, (int) $idUser, (int) $idCompany], $bloco));
      $total += $this->db->affected_rows();
    }

    return $total;
  }

  /**
   * Desliga quem saiu do recorte, em BLOCO.
   *
   * Nunca linha a linha por contrato: o mesmo domínio pode estar num contrato
   * encerrado e noutro vigente, e um laço por contrato desligaria o
   * monitoramento ou não conforme a ordem em que o SELECT devolvesse as linhas.
   *
   * Desligar, e não apagar: o histórico de eventos ficaria órfão.
   *
   * @return int
   */
  private function desativarAusentes($idCompany, array $hosts, $agora, $idUser)
  {
    $idCompany = (int) $idCompany;

    if (empty($hosts)) {
      $this->db->query(
        'UPDATE `crm_domains_monitor` SET `active` = 0, `modified` = ?, `modified_by` = ?
          WHERE `id_company` = ? AND `active` = 1',
        [$agora, (int) $idUser, $idCompany]
      );
      return $this->db->affected_rows();
    }

    $total = 0;
    // NOT IN com muitos itens é quebrado em blocos, mas "não está em NENHUM
    // bloco" exige a interseção — então a desativação roda uma vez com a lista
    // inteira. Com ~500 domínios o IN é folgado para o MySQL.
    $marcadores = implode(',', array_fill(0, count($hosts), '?'));
    $sql = 'UPDATE `crm_domains_monitor` SET `active` = 0, `modified` = ?, `modified_by` = ?
             WHERE `id_company` = ? AND `active` = 1 AND `domain` NOT IN (' . $marcadores . ')';

    $this->db->query($sql, array_merge([$agora, (int) $idUser, $idCompany], $hosts));
    $total += $this->db->affected_rows();

    return $total;
  }

  // ------------------------------------------------------------------
  // Seleção
  // ------------------------------------------------------------------

  /**
   * Domínios a checar nesta rodada, do mais atrasado para o mais recente.
   *
   * A ordenação é o que torna a rodada retomável: com orçamento de tempo, os
   * que sobraram ontem vêm primeiro hoje. Ordenar por id faria os mesmos
   * primeiros N serem checados sempre, e a cauda nunca — em silêncio.
   *
   * @param  int      $idCompany
   * @param  int|null $limite
   * @param  int|null $horas
   * @return array
   */
  public function getPendentes($idCompany, $limite = NULL, $horas = NULL)
  {
    $limite = ($limite === NULL) ? self::LIMITE_POR_RODADA : (int) $limite;
    $horas = ($horas === NULL) ? $this->intervaloHoras() : (int) $horas;

    if ($limite <= 0) return [];

    $sql = 'SELECT * FROM `crm_domains_monitor`
             WHERE `id_company` = ? AND `active` = 1
               AND (`last_check` IS NULL OR `last_check` <= DATE_SUB(NOW(), INTERVAL ? HOUR))
             ORDER BY (`last_check` IS NULL) DESC, `last_check` ASC, `id` ASC
             LIMIT ' . (int) $limite;

    return $this->db->query($sql, [(int) $idCompany, $horas])->result();
  }

  // ------------------------------------------------------------------
  // Rodada
  // ------------------------------------------------------------------

  /**
   * Executa a rodada completa de um tenant.
   *
   * @param  int $idCompany
   * @param  int $idUser
   * @return array relatório
   */
  public function executarRodada($idCompany, $idUser)
  {
    $idCompany = (int) $idCompany;
    $idUser = (int) $idUser;

    $relatorio = [
      'abortada' => FALSE,
      'motivo' => '',
      'checados' => 0,
      'ok' => 0,
      'falhas' => 0,
      'eventos' => 0,
      'linhas' => [],
      'populacao' => NULL,
      'fora_do_recorte' => $this->foraDoRecorte($idCompany),
    ];

    $relatorio['populacao'] = $this->sincronizarPopulacao($idCompany, $idUser);

    if (!$this->canarioNoAr()) {
      $relatorio['abortada'] = TRUE;
      $relatorio['motivo'] = 'Rodada inconclusiva: o canário não respondeu, então a saída de internet do servidor está fora. Nenhum evento foi gerado.';
      log_message('error', '[MONITOR] Rodada abortada no canário — tenant ' . $idCompany . '.');
      return $relatorio;
    }

    $pendentes = $this->getPendentes($idCompany);
    $inicio = time();
    $falhasDeRede = 0;

    // Uma consulta de DNS por APEX por rodada: dois hosts da mesma zona
    // (foo.com e loja.foo.com) têm o mesmo NS, e consultar duas vezes só
    // gastaria tempo e produziria o mesmo evento em duplicata.
    $dnsPorApex = [];

    // Retidos até o fim: ver o docblock da classe.
    $foraDoAr = [];

    foreach ($pendentes as $linha) {
      if ((time() - $inicio) > self::ORCAMENTO_SEGUNDOS) {
        $relatorio['motivo'] = 'Orçamento de tempo da rodada esgotado; o restante entra na próxima execução.';
        break;
      }

      $resultado = $this->checarLinha($linha, $idUser, $dnsPorApex);

      $relatorio['checados']++;
      if ($resultado['ok']) $relatorio['ok']++; else $relatorio['falhas']++;
      if ($resultado['falha_de_rede']) $falhasDeRede++;
      $relatorio['eventos'] += count($resultado['eventos']);
      $relatorio['linhas'][] = $resultado['resumo'];

      if ($resultado['fora_do_ar'] !== NULL) $foraDoAr[] = $resultado['fora_do_ar'];

      // Disjuntor: proporção alta de falhas DE REDE é incidente nosso, não queda
      // simultânea de centenas de clientes.
      //
      // Conta só falha de transporte (DNS, timeout, conexão). Um HTTP 404 ou 502
      // é PROVA de que a rede está funcionando — foi o servidor do cliente que
      // respondeu. Misturar os dois faz o disjuntor soar numa base que
      // simplesmente tem muitos sites quebrados, e aí ele passa a esconder
      // exatamente o que deveria denunciar (medido: 8 falhas nos 20 primeiros
      // domínios, das quais 5 eram 404/502).
      if ($relatorio['checados'] >= self::MINIMO_AMOSTRA_DISJUNTOR
          && ($falhasDeRede / $relatorio['checados']) > self::PROPORCAO_FALHA_ABORTA) {
        $relatorio['abortada'] = TRUE;
        $relatorio['motivo'] = 'Rodada interrompida pelo disjuntor: ' . $falhasDeRede
          . ' de ' . $relatorio['checados'] . ' domínios não foram alcançados por falha de rede,'
          . ' o que indica problema na saída de internet do servidor e não queda simultânea dos sites.'
          . ' Nenhum aviso de site fora foi gerado.';
        log_message('error', '[MONITOR] Disjuntor acionado — tenant ' . $idCompany
          . ', ' . $falhasDeRede . '/' . $relatorio['checados'] . ' falhas de rede.');
        break;
      }

      usleep(self::PAUSA_ENTRE_DOMINIOS_US);
    }

    // Só agora os "site fora" viram evento. Se o disjuntor tiver soado, eles são
    // descartados — mas `down_since` e `consecutive_failures` já foram gravados,
    // então na próxima rodada saudável o aviso sai normalmente.
    if (!$relatorio['abortada']) {
      foreach ($foraDoAr as $pendente) {
        $this->registrarEvento($pendente['linha'], 'site_fora', NULL, NULL, $pendente['detalhe'], $idUser);
        $this->atualizarMonitor($pendente['linha']->id, ['down_notified' => 1], $idUser);
        $relatorio['eventos']++;
      }
    }

    return $relatorio;
  }

  /**
   * Checa um domínio pelo id (botão da tela).
   *
   * @param  int $idMonitor
   * @param  int $idCompany escopo do tenant, resolvido no servidor
   * @param  int $idUser
   * @return array
   */
  public function checarUm($idMonitor, $idCompany, $idUser)
  {
    $linha = $this->db->query(
      'SELECT * FROM `crm_domains_monitor` WHERE `id` = ? AND `id_company` = ?',
      [(int) $idMonitor, (int) $idCompany]
    )->row();

    if (empty($linha)) {
      return ['success' => FALSE, 'message' => 'Domínio não encontrado neste tenant.', 'data' => NULL];
    }

    $dnsPorApex = [];
    $resultado = $this->checarLinha($linha, $idUser, $dnsPorApex);

    // No caminho manual não há disjuntor: é uma medição só, pedida por alguém
    // que está olhando a tela, e o "site fora" pode sair na hora.
    if ($resultado['fora_do_ar'] !== NULL) {
      $pendente = $resultado['fora_do_ar'];
      $this->registrarEvento($pendente['linha'], 'site_fora', NULL, NULL, $pendente['detalhe'], $idUser);
      $this->atualizarMonitor($pendente['linha']->id, ['down_notified' => 1], $idUser);
    }

    return [
      'success' => TRUE,
      'message' => $resultado['resumo']['mensagem'],
      'data' => $resultado['resumo'],
    ];
  }

  // ------------------------------------------------------------------
  // O coração: medir, comparar, decidir
  // ------------------------------------------------------------------

  /**
   * @param  object $linha      registro de crm_domains_monitor
   * @param  int    $idUser
   * @param  array  $dnsPorApex memória de DNS da rodada, por referência
   * @return array
   */
  private function checarLinha($linha, $idUser, array &$dnsPorApex)
  {
    $agora = date('Y-m-d H:i:s');
    $atualiza = ['last_check' => $agora];
    $eventos = [];
    $foraDoAr = NULL;

    // sessao_suspender() em volta de TODA a rede: no cron (CLI) é no-op, mas no
    // botão da tela é o que solta o GET_LOCK do MySQL durante a espera.
    $sessao = sessao_suspender();
    try {
      $dns = $this->dnsDoApex($linha->apex, $dnsPorApex);
      $http = $this->site_monitor->checarHttp($linha->domain, $linha->http_final_url, $this->timeoutChecagem());
    } catch (Throwable $e) {
      $dns = ['success' => FALSE, 'message' => $e->getMessage(), 'data' => NULL, 'transient' => TRUE];
      $http = ['success' => FALSE, 'message' => $e->getMessage(), 'data' => NULL, 'transient' => TRUE];
    } finally {
      sessao_retomar($sessao);
    }

    $eventos = array_merge($eventos, $this->compararDns($linha, $dns, $atualiza, $agora));

    $resultadoHttp = $this->compararHttp($linha, $http, $atualiza, $agora);
    $eventos = array_merge($eventos, $resultadoHttp['eventos']);
    $foraDoAr = $resultadoHttp['fora_do_ar'];

    $atualiza['check_status'] = $this->situacaoDaChecagem($linha, $dns, $http);

    // Transação por DOMÍNIO, nunca por rodada: uma falha no 399º não pode
    // desfazer 398 checagens boas.
    $this->db->trans_start();
    $this->atualizarMonitor($linha->id, $atualiza, $idUser);
    foreach ($eventos as $evento) {
      $this->registrarEvento($linha, $evento['type'], $evento['old'], $evento['new'], $evento['detail'], $idUser);
    }
    $this->db->trans_complete();

    $ok = !empty($http['success']);
    $resultadoDoHttp = isset($http['data']['http_result']) ? $http['data']['http_result'] : 'nao_checado';

    return [
      'ok' => $ok,
      // Só transporte: 404/502 provam que a rede foi até lá e voltou.
      'falha_de_rede' => in_array($resultadoDoHttp, ['dns', 'timeout', 'conexao'], TRUE),
      'eventos' => $eventos,
      'fora_do_ar' => $foraDoAr,
      'resumo' => [
        'domain' => $linha->domain,
        'ok' => $ok,
        'http_status' => isset($http['data']['http_status']) ? (int) $http['data']['http_status'] : 0,
        'http_result' => isset($http['data']['http_result']) ? $http['data']['http_result'] : 'nao_checado',
        'eventos' => array_column($eventos, 'type'),
        'mensagem' => $http['message'],
      ],
    ];
  }

  /**
   * Consulta de DNS memoizada por apex dentro da rodada.
   */
  private function dnsDoApex($apex, array &$memoria)
  {
    $chave = (string) $apex;
    if (!array_key_exists($chave, $memoria)) {
      $memoria[$chave] = $this->site_monitor->checarDns($chave);
    }
    return $memoria[$chave];
  }

  /**
   * Compara os nameservers e decide se houve troca.
   *
   * O conjunto novo precisa se REPETIR numa rodada seguinte antes de virar
   * evento: durante uma troca de NS o resolvedor devolve o conjunto antigo e o
   * novo alternadamente por até 48h, e sem essa confirmação uma única troca
   * geraria três alarmes (mudou, voltou, mudou de novo).
   *
   * @return array eventos
   */
  private function compararDns($linha, array $dns, array &$atualiza, $agora)
  {
    if (empty($dns['success']) || empty($dns['data']['ns_list'])) {
      // Medição ruim: preserva o que estava gravado e não gera evento.
      $atualiza['ns_status'] = 'nao_checado';
      $atualiza['ns_message'] = mb_substr((string) $dns['message'], 0, 500);
      return [];
    }

    $novo = (string) $dns['data']['ns_list'];
    $anterior = (string) $linha->ns_list;

    $atualiza['ns_status'] = 'ok';
    $atualiza['ns_message'] = NULL;

    // Primeira leitura é linha de base, nunca troca.
    if ($anterior === '') {
      $atualiza['ns_list'] = $novo;
      $atualiza['ns1'] = $dns['data']['ns1'];
      $atualiza['ns2'] = $dns['data']['ns2'];
      $atualiza['ns_pending'] = NULL;
      return [];
    }

    if ($novo === $anterior) {
      $atualiza['ns_pending'] = NULL;
      return [];
    }

    if ((string) $linha->ns_pending !== $novo) {
      // Primeira vez que este conjunto aparece: fica aguardando confirmação.
      $atualiza['ns_pending'] = $novo;
      return [];
    }

    // Confirmado em duas rodadas: agora é troca de verdade.
    $atualiza['ns_list'] = $novo;
    $atualiza['ns1'] = $dns['data']['ns1'];
    $atualiza['ns2'] = $dns['data']['ns2'];
    $atualiza['ns_pending'] = NULL;
    $atualiza['ns_changed'] = $agora;

    return [[
      'type' => 'ns_alterado',
      'old' => $anterior,
      'new' => $novo,
      'detail' => 'Confirmado em duas checagens seguidas.',
    ]];
  }

  /**
   * Compara o estado da home e decide o que virou evento.
   *
   * @return array ['eventos' => array, 'fora_do_ar' => array|null]
   */
  private function compararHttp($linha, array $http, array &$atualiza, $agora)
  {
    $eventos = [];
    $foraDoAr = NULL;
    $dados = is_array($http['data']) ? $http['data'] : [];

    $atualiza['http_status'] = isset($dados['http_status']) ? (int) $dados['http_status'] : 0;
    $atualiza['http_result'] = isset($dados['http_result']) ? $dados['http_result'] : 'nao_checado';
    $atualiza['http_message'] = empty($http['success']) ? mb_substr((string) $http['message'], 0, 500) : NULL;

    if ($this->estaFora($dados)) {
      $falhas = (int) $linha->consecutive_failures + 1;
      $atualiza['consecutive_failures'] = $falhas;
      if (empty($linha->down_since)) $atualiza['down_since'] = $agora;

      // O evento só nasce na SEGUNDA falha seguida. Duas tentativas dentro da
      // mesma rodada não seriam redundância — é a mesma máquina, o mesmo
      // resolvedor e o mesmo caminho de rede medindo duas vezes.
      if ($falhas >= self::FALHAS_PARA_ANUNCIAR && empty($linha->down_notified)) {
        $foraDoAr = ['linha' => $linha, 'detalhe' => (string) $http['message']];
      }

      // Nada de título, marcador ou SSL: não foram medidos.
      return ['eventos' => $eventos, 'fora_do_ar' => $foraDoAr];
    }

    $atualiza['consecutive_failures'] = 0;
    $atualiza['last_success'] = $agora;
    if (!empty($dados['check_host'])) $atualiza['check_host'] = $dados['check_host'];
    if (!empty($dados['http_final_url'])) $atualiza['http_final_url'] = $dados['http_final_url'];

    if (!empty($linha->down_since)) {
      // Só anuncia o restabelecimento se a QUEDA chegou a ser anunciada — senão
      // chega um "resolvido" de um problema que ninguém soube que existiu.
      if (!empty($linha->down_notified)) {
        $eventos[] = [
          'type' => 'site_restabelecido',
          'old' => NULL,
          'new' => NULL,
          'detail' => 'Fora do ar desde ' . date('d/m/Y H:i', strtotime($linha->down_since)) . '.',
        ];
      }
      $atualiza['down_since'] = NULL;
      $atualiza['down_notified'] = 0;
    }

    $eventos = array_merge($eventos, $this->compararTitulo($linha, $dados, $atualiza, $agora));
    $eventos = array_merge($eventos, $this->compararMarcador($linha, $dados, $atualiza));
    $eventos = array_merge($eventos, $this->compararRedirecionamento($linha, $dados));
    $eventos = array_merge($eventos, $this->compararSsl($linha, $dados, $atualiza));

    return ['eventos' => $eventos, 'fora_do_ar' => $foraDoAr];
  }

  /**
   * "Fora do ar" é falha de transporte ou erro do servidor.
   *
   * 401/403/429 NÃO entram: é WAF recusando bot, o site está no ar para o
   * visitante, e como é característica estável viraria alarme diário permanente.
   *
   * @param  array $dados
   * @return bool
   */
  private function estaFora(array $dados)
  {
    $resultado = isset($dados['http_result']) ? $dados['http_result'] : 'nao_checado';
    $status = isset($dados['http_status']) ? (int) $dados['http_status'] : 0;

    if ($resultado === 'bloqueado') return FALSE;
    if (in_array($resultado, ['dns', 'timeout', 'conexao', 'redirect_loop', 'nao_checado'], TRUE)) return TRUE;

    return ($status >= 400 || $status === 0);
  }

  private function compararTitulo($linha, array $dados, array &$atualiza, $agora)
  {
    $novo = isset($dados['title']) ? $dados['title'] : NULL;

    // NULL = não foi possível medir. Preserva e não gera evento.
    if ($novo === NULL || $novo === '') return [];

    $anterior = (string) $linha->title;

    if ($anterior === '') {
      $atualiza['title'] = $novo;
      return [];
    }

    if ($this->mesmoTexto($anterior, $novo)) return [];

    $atualiza['title'] = $novo;
    $atualiza['title_changed'] = $agora;

    return [[
      'type' => 'titulo_alterado',
      'old' => mb_substr($anterior, 0, 500),
      'new' => mb_substr($novo, 0, 500),
      'detail' => NULL,
    ]];
  }

  private function compararMarcador($linha, array $dados, array &$atualiza)
  {
    $novo = isset($dados['flag']) ? $dados['flag'] : NULL;
    $anterior = empty($linha->flag) ? NULL : (string) $linha->flag;

    if ($novo === $anterior) return [];

    $atualiza['flag'] = $novo;

    // Marcador que sumiu não gera evento: o site voltou ao normal e isso já
    // aparece na tela. Só a DETECÇÃO é notícia.
    if ($novo === NULL) return [];

    $rotulos = $this->rotulosMarcador();

    return [[
      'type' => 'marcador_detectado',
      'old' => $anterior,
      'new' => $novo,
      'detail' => isset($rotulos[$novo]) ? $rotulos[$novo] : $novo,
    ]];
  }

  /**
   * Home que passou a redirecionar para OUTRO domínio registrável.
   *
   * É o sinal mais forte de que o cliente migrou ou de que o domínio foi tomado.
   * A comparação é por APEX, e não por host: `foo.com` → `www.foo.com` é o
   * comportamento normal de metade dos sites e não pode virar alarme.
   */
  private function compararRedirecionamento($linha, array $dados)
  {
    if (empty($dados['http_final_url'])) return [];

    // Primeira checagem é LINHA DE BASE, nunca alarme: domínio que sempre
    // redirecionou para o site principal da mesma empresa é configuração
    // intencional, não incidente. Na base real isso apareceu logo de cara —
    // quatro domínios de um mesmo cliente apontando para o site principal
    // teriam virado quatro eventos críticos no primeiro dia.
    if (empty($linha->http_final_url)) return [];

    $hostFinal = parse_url((string) $dados['http_final_url'], PHP_URL_HOST);
    if (!is_string($hostFinal) || $hostFinal === '') return [];

    $apexFinal = $this->apexDe($this->normalizarDominio($hostFinal));
    if ($apexFinal === '' || $apexFinal === (string) $linha->apex) return [];

    // Já redirecionava para o mesmo lugar na checagem anterior? Então não é
    // novidade — o evento sai uma vez, não todo dia.
    $hostAntes = parse_url((string) $linha->http_final_url, PHP_URL_HOST);
    if (is_string($hostAntes) && $this->apexDe($this->normalizarDominio($hostAntes)) === $apexFinal) return [];

    return [[
      'type' => 'redirecionamento_externo',
      'old' => (string) $linha->apex,
      'new' => $apexFinal,
      'detail' => 'A home passou a redirecionar para ' . mb_substr((string) $dados['http_final_url'], 0, 300) . '.',
    ]];
  }

  /**
   * Certificado vencido, de nome divergente ou perto de vencer.
   *
   * `ssl_notified_for` guarda PARA QUAL vencimento o aviso saiu, e não um
   * booleano: quando o certificado é renovado a data muda e o aviso volta a
   * ficar disponível, sem nunca repetir o mesmo alerta em rodadas seguidas.
   */
  private function compararSsl($linha, array $dados, array &$atualiza)
  {
    $status = isset($dados['ssl_status']) ? $dados['ssl_status'] : NULL;
    $vencimento = isset($dados['ssl_expiration_date']) ? $dados['ssl_expiration_date'] : NULL;

    // Não medido: preserva.
    if ($status === NULL) return [];

    $atualiza['ssl_status'] = $status;
    $atualiza['ssl_expiration_date'] = $vencimento;
    $atualiza['ssl_issuer'] = isset($dados['ssl_issuer']) ? $dados['ssl_issuer'] : NULL;

    // Site em http puro não tem certificado a vencer — e isso não é incidente.
    if ($status === 'ausente') {
      $atualiza['ssl_notified_for'] = NULL;
      return [];
    }

    $limite = date('Y-m-d', strtotime('+' . $this->diasAvisoSsl() . ' days'));
    $detalhe = '';

    if ($status === 'vencido') {
      $detalhe = 'Certificado vencido' . ($vencimento ? ' em ' . date('d/m/Y', strtotime($vencimento)) : '') . '.';
    } elseif ($status === 'nome_divergente') {
      $detalhe = 'O certificado não cobre este domínio — o navegador do visitante mostra aviso de segurança.';
    } elseif ($vencimento !== NULL && $vencimento <= $limite) {
      $detalhe = 'Certificado vence em ' . date('d/m/Y', strtotime($vencimento)) . '.';
    } else {
      $atualiza['ssl_notified_for'] = NULL;
      return [];
    }

    $chave = ($vencimento !== NULL) ? $vencimento : date('Y-m-d');
    if ((string) $linha->ssl_notified_for === $chave) return [];

    $atualiza['ssl_notified_for'] = $chave;

    return [[
      'type' => 'ssl_vencendo',
      'old' => empty($linha->ssl_expiration_date) ? NULL : (string) $linha->ssl_expiration_date,
      'new' => $vencimento,
      'detail' => $detalhe,
    ]];
  }

  /**
   * `nunca_respondeu` separa "o site caiu" de "nunca houve site aqui".
   */
  private function situacaoDaChecagem($linha, array $dns, array $http)
  {
    if (!empty($http['success']) && !empty($dns['success'])) return 'ok';
    if (!empty($http['success']) || !empty($dns['success'])) return 'parcial';
    if (empty($linha->last_success)) return 'nunca_respondeu';

    return 'erro';
  }

  // ------------------------------------------------------------------
  // Resumo por e-mail
  // ------------------------------------------------------------------

  /**
   * Monta e enfileira o resumo dos eventos ainda não avisados deste tenant.
   *
   * Rodada sem evento não manda nada: e-mail diário de "nenhuma novidade" treina
   * quem recebe a ignorar a caixa, e aí o dia do incidente passa despercebido.
   *
   * @param  object $empresa linha de crm_companies
   * @return array ['enviado' => bool, 'eventos' => int, 'message' => string]
   */
  public function enviarResumo($empresa)
  {
    $idCompany = (int) $empresa->id;
    $eventos = $this->getEventosParaResumo($idCompany);

    if (empty($eventos)) {
      return ['enviado' => FALSE, 'eventos' => 0, 'message' => 'Nenhum evento novo para avisar.'];
    }

    $destinatarios = $this->destinatariosResumo($empresa);
    if (empty($destinatarios)) {
      log_message('error', '[MONITOR] ' . count($eventos) . ' evento(s) sem destinatário — tenant ' . $idCompany
        . '. Cadastre o e-mail em Parâmetros gerais > Monitoramento.');
      return [
        'enviado' => FALSE,
        'eventos' => count($eventos),
        'message' => 'Há ' . count($eventos) . ' evento(s) para avisar, mas nenhum destinatário está cadastrado em Parâmetros gerais.',
      ];
    }

    $clientes = $this->clientesPorDominio($idCompany, array_unique(array_column($eventos, 'domain')));
    $agrupados = $this->agruparPorSeveridade($eventos);
    $contagem = $this->contarPorSeveridade($eventos);

    $assunto = $this->assuntoResumo($empresa, $contagem);

    $corpo = $this->global_model->body_email('emails/monitoring/digest', [
      'title' => $assunto,
      'empresa' => (string) $empresa->byname,
      'grupos' => $agrupados,
      'clientes' => $clientes,
      'contagem' => $contagem,
      'rotulos' => $this->tiposEvento(),
      'limite' => self::LIMITE_LINHAS_RESUMO,
      'url_painel' => base_url('monitoramento'),
    ]);

    $enfileirado = $this->global_model->send_email($assunto, $corpo, $destinatarios, [], [], NULL);

    if (!$enfileirado) {
      log_message('error', '[MONITOR] Falha ao enfileirar o resumo — tenant ' . $idCompany . '.');
      return ['enviado' => FALSE, 'eventos' => count($eventos), 'message' => 'Falha ao enfileirar o e-mail do resumo.'];
    }

    // Só DEPOIS do enfileiramento confirmado, e pela lista exata de ids que
    // entrou no corpo. Marcar antes faria os eventos do dia sumirem para sempre
    // se o envio falhasse; marcar por "notified = 0" reavaliado agora poderia
    // engolir um evento criado entre a leitura e a escrita (o botão de checagem
    // avulsa da tela cria eventos a qualquer hora). Mesmo cuidado que o
    // cron_notificaerrosagencia toma antes de apagar o log rotacionado.
    $this->marcarNotificados(array_column($eventos, 'id'));

    return [
      'enviado' => TRUE,
      'eventos' => count($eventos),
      'message' => 'Resumo com ' . count($eventos) . ' evento(s) enfileirado para ' . implode(', ', $destinatarios) . '.',
    ];
  }

  /**
   * Eventos ainda não avisados, dos tipos que entram no resumo.
   *
   * Exclui domínio silenciado: `muted` é a decisão de parar de ser avisado, e
   * sem este filtro um site sabidamente quebrado poluiria todo resumo diário.
   *
   * @param  int $idCompany
   * @return array
   */
  public function getEventosParaResumo($idCompany)
  {
    $tipos = $this->tiposNoResumo();
    $marcadores = implode(',', array_fill(0, count($tipos), '?'));

    $sql = 'SELECT `e`.*, `m`.`muted`
              FROM `crm_domains_monitor_events` `e`
              LEFT JOIN `crm_domains_monitor` `m` ON `m`.`id` = `e`.`id_monitor`
             WHERE `e`.`id_company` = ? AND `e`.`notified` = 0
               AND `e`.`type` IN (' . $marcadores . ')
               AND COALESCE(`m`.`muted`, 0) = 0
             ORDER BY FIELD(`e`.`severity`, ?, ?, ?), `e`.`detected` ASC';

    $binds = array_merge([(int) $idCompany], $tipos, ['critico', 'alerta', 'info']);

    return $this->db->query($sql, $binds)->result_array();
  }

  /**
   * Cliente de cada domínio, em UMA query agregada.
   *
   * O cliente não é denormalizado no evento porque um domínio pode estar em mais
   * de um contrato, de clientes diferentes. Mesmo padrão do
   * `Servidores::clientesPorDominio()`, que existe para não fazer N+1.
   *
   * @param  int   $idCompany
   * @param  array $dominios
   * @return array ['dominio' => 'Cliente A, Cliente B']
   */
  public function clientesPorDominio($idCompany, array $dominios)
  {
    if (empty($dominios)) return [];

    $porDominio = [];

    foreach (array_chunk(array_values($dominios), 300) as $bloco) {
      $marcadores = implode(',', array_fill(0, count($bloco), '?'));

      // O domínio do contrato pode estar gravado com `www.`, e o monitor guarda
      // sem — a comparação despreza o prefixo dos dois lados.
      $sql = 'SELECT DISTINCT
                LOWER(TRIM(LEADING "www." FROM LOWER(`cd`.`domain`))) AS `chave`,
                `cu`.`name` AS `cliente`
                FROM `crm_contracts_domains` `cd`
                JOIN `crm_contracts` `c` ON `c`.`id` = `cd`.`id_contract`
                JOIN `crm_customers` `cu` ON `cu`.`id` = `c`.`id_customer`
               WHERE `cd`.`id_company` = ?
                 AND LOWER(TRIM(LEADING "www." FROM LOWER(`cd`.`domain`))) IN (' . $marcadores . ')';

      $linhas = $this->db->query($sql, array_merge([(int) $idCompany], $bloco))->result();

      foreach ($linhas as $linha) {
        if (!isset($porDominio[$linha->chave])) $porDominio[$linha->chave] = [];
        $porDominio[$linha->chave][] = (string) $linha->cliente;
      }
    }

    foreach ($porDominio as $chave => $nomes) {
      $porDominio[$chave] = implode(', ', array_unique($nomes));
    }

    return $porDominio;
  }

  private function agruparPorSeveridade(array $eventos)
  {
    $grupos = ['critico' => [], 'alerta' => [], 'info' => []];

    foreach ($eventos as $evento) {
      $severidade = isset($grupos[$evento['severity']]) ? $evento['severity'] : 'info';
      $grupos[$severidade][] = $evento;
    }

    return array_filter($grupos, function ($linhas) {
      return !empty($linhas);
    });
  }

  private function contarPorSeveridade(array $eventos)
  {
    $contagem = ['critico' => 0, 'alerta' => 0, 'info' => 0];

    foreach ($eventos as $evento) {
      $severidade = isset($contagem[$evento['severity']]) ? $evento['severity'] : 'info';
      $contagem[$severidade]++;
    }

    return $contagem;
  }

  /**
   * Assunto que já entrega o veredito.
   *
   * "3 fora do ar, 12 avisos" é o que faz o e-mail continuar sendo aberto no
   * sexto mês; um assunto genérico vira ruído de caixa de entrada.
   */
  private function assuntoResumo($empresa, array $contagem)
  {
    $partes = [];
    if ($contagem['critico'] > 0) $partes[] = $contagem['critico'] . ' crítico(s)';
    if ($contagem['alerta'] > 0) $partes[] = $contagem['alerta'] . ' alerta(s)';
    if ($contagem['info'] > 0) $partes[] = $contagem['info'] . ' aviso(s)';

    return '[Monitoramento] ' . $empresa->byname . ': ' . implode(', ', $partes);
  }

  private function marcarNotificados(array $ids)
  {
    $ids = array_filter(array_map('intval', $ids));
    if (empty($ids)) return;

    foreach (array_chunk($ids, 400) as $bloco) {
      $marcadores = implode(',', array_fill(0, count($bloco), '?'));
      $this->db->query(
        'UPDATE `crm_domains_monitor_events` SET `notified` = 1 WHERE `id` IN (' . $marcadores . ')',
        $bloco
      );
    }
  }

  // ------------------------------------------------------------------
  // Escrita
  // ------------------------------------------------------------------

  private function atualizarMonitor($idMonitor, array $campos, $idUser)
  {
    if (empty($campos)) return;

    $campos['modified'] = date('Y-m-d H:i:s');
    $campos['modified_by'] = (int) $idUser;

    $this->db->where('id', (int) $idMonitor)->update('crm_domains_monitor', $campos);
  }

  private function registrarEvento($linha, $tipo, $antes, $depois, $detalhe, $idUser)
  {
    $catalogo = $this->tiposEvento();
    $severidade = isset($catalogo[$tipo]) ? $catalogo[$tipo]['severity'] : 'info';

    $this->db->insert('crm_domains_monitor_events', [
      'id_monitor' => (int) $linha->id,
      'id_company' => (int) $linha->id_company,
      'domain' => (string) $linha->domain,
      'type' => $tipo,
      'severity' => $severidade,
      'old_value' => ($antes === NULL) ? NULL : mb_substr((string) $antes, 0, 500),
      'new_value' => ($depois === NULL) ? NULL : mb_substr((string) $depois, 0, 500),
      'detail' => ($detalhe === NULL) ? NULL : mb_substr((string) $detalhe, 0, 500),
      'detected' => date('Y-m-d H:i:s'),
      'notified' => 0,
      'created_by' => (int) $idUser,
    ]);
  }

  // ------------------------------------------------------------------
  // Auxiliares
  // ------------------------------------------------------------------

  /**
   * O canário responde? Se não, o problema é a nossa saída de internet.
   */
  private function canarioNoAr()
  {
    $sessao = sessao_suspender();
    try {
      $resposta = $this->site_monitor->checarHttp(
        parse_url(self::URL_CANARIO, PHP_URL_HOST),
        self::URL_CANARIO
      );
    } catch (Throwable $e) {
      $resposta = ['success' => FALSE];
    } finally {
      sessao_retomar($sessao);
    }

    return !empty($resposta['success']);
  }

  /**
   * Host normalizado e VALIDADO como hostname.
   *
   * O cadastro de domínios do contrato tem nomes de CONTA de servidor vindos do
   * CloudPanel — `certicais.com.br--bkp2`, `gop.org.br_bkp`, `public_html-...`,
   * `dist`. Não resolvem em DNS e não são sites. Sem esta validação, cada um
   * viraria um "site fora do ar" PERMANENTE no resumo diário.
   *
   * @param  string $valor
   * @return string '' quando não é um host utilizável
   */
  private function normalizarDominio($valor)
  {
    $host = mb_strtolower(trim((string) $valor));
    $host = preg_replace('#^[a-z]+://#', '', $host);
    $host = explode('/', $host)[0];
    $host = explode('?', $host)[0];
    $host = explode(':', $host)[0];
    $host = rtrim(trim($host), '.');
    $host = preg_replace('/^www\./', '', $host);

    if ($host === '' || mb_strlen($host) > 253) return '';
    if (strpos($host, '.') === FALSE) return '';

    // Rótulos: letras, dígitos e hífen, sem hífen nas pontas. `--bkp2` e `_bkp`
    // caem aqui, que é o objetivo.
    foreach (explode('.', $host) as $rotulo) {
      if (!preg_match('/^(?!-)[a-z0-9\x{00a1}-\x{ffff}-]{1,63}(?<!-)$/u', $rotulo)) return '';
    }

    // O último rótulo é o TLD: só letras.
    $partes = explode('.', $host);
    if (!preg_match('/^[a-z\x{00a1}-\x{ffff}]{2,}$/u', end($partes))) return '';

    return $host;
  }

  /**
   * Domínio registrável, em ASCII.
   *
   * `Whois_model::normalizarHost()` recusa qualquer coisa fora de `[a-z0-9.-]`,
   * então domínio acentuado voltaria FALSE e sumiria do monitoramento em
   * silêncio. A conversão para punycode acontece ANTES da chamada.
   *
   * @param  string $host
   * @return string '' quando não reduz
   */
  private function apexDe($host)
  {
    if ($host === '') return '';

    if (!preg_match('/^[a-z0-9.\-]+$/', $host) && function_exists('idn_to_ascii')) {
      $convertido = @idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
      if (is_string($convertido) && $convertido !== '') $host = mb_strtolower($convertido);
    }

    $apex = $this->whois_model->registrableDomain($host);

    return ($apex === FALSE || $apex === '') ? '' : (string) $apex;
  }

  /**
   * Comparação de título tolerante a maiúsculas e espaço.
   *
   * A limpeza de emoji e whitespace já aconteceu na library, dos DOIS lados —
   * comparar e gravar com a mesma função é o que impede o título de "mudar"
   * sozinho a cada rodada.
   */
  private function mesmoTexto($a, $b)
  {
    return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
  }
}
