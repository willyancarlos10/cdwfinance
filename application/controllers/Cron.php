<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Cron extends CI_Controller
{
  public function __construct()
  {
    parent::__construct();

    // O crontab dispara estas rotinas por URL (wget/curl), então não dá para
    // exigir is_cli(). Sem guarda nenhuma, porém, qualquer um na internet
    // chamava /cron/cron_exclude_sessions e disparava um DELETE em lote sobre
    // crm_sessions, ou uma rodada de envio de e-mail, sem autenticação.
    //
    // Falha fechada de propósito: sem $config['cron_token'] na config.php do
    // servidor (que é gitignored, então o token não vai para o repositório),
    // nenhuma rotina roda. Adicionar o token é o primeiro passo do deploy.
    $token = (string) $this->config->item('cron_token');
    if (!is_cli() && ($token === '' || !hash_equals($token, (string) $this->input->get('token')))) {
      show_error('Acesso não autorizado.', 403);
    }

    $this->load->model('general_settings_model');
  }

  public function index()
  {
    redirect(base_url());
  }

  private function isCronActive($name)
  {
    $row = $this->global_model->getWhere_off('crm_cron_logs', ['name' => $name], TRUE);
    if (!empty($row) && isset($row->active) && $row->active === 'N') {
      echo "Tarefa '{$name}' está desativada no painel.";
      return FALSE;
    }
    return TRUE;
  }

  public function cron_exclude_sessions()
  {
    if (!$this->isCronActive('cron_exclude_sessions')) return;

    // Remove apenas sessão já expirada. As linhas ainda dentro do sess_expiration
    // pertencem a quem está logado agora — apagar a tabela inteira desloga todo mundo.
    $expiration = (int) $this->config->item('sess_expiration');
    if ($expiration <= 0) {
      $expiration = 18000;
    }
    $limite = time() - $expiration;

    // Em lotes: um DELETE único sobre centenas de milhares de linhas segura lock
    // de tabela e infla o undo log do InnoDB até estourar em rollback.
    $lote = 5000;
    $total = 0;
    do {
      $this->db->query('DELETE FROM `crm_sessions` WHERE `timestamp` < ? LIMIT ' . (int) $lote, [$limite]);
      $removidas = $this->db->affected_rows();
      $total += $removidas;

      if ($removidas === $lote) {
        usleep(200000); // respiro entre lotes para não monopolizar o banco
      }
    } while ($removidas === $lote);

    echo "Sessões expiradas removidas: {$total}" . PHP_EOL;

    // Update last execution
    $this->global_model->edit('crm_cron_logs', ['modified' => date("Y-m-d H:i:s")], 'name', 'cron_exclude_sessions');
  }

  public function cron_exclude_cache()
  {
    if (!$this->isCronActive('cron_exclude_cache')) return;

    // Excluir cache de consultas
    $this->db->cache_delete_all();

    // Update last execution
    $this->global_model->edit('crm_cron_logs', ['modified' => date("Y-m-d H:i:s")], 'name', 'cron_exclude_cache');
  }

  public function cron_enviar_email()
  {
    if (!$this->isCronActive('cron_enviar_email')) return;

    if (!$this->general_settings_model->isMailActive()) {
      echo 'Envio de e-mail desativado';
      exit;
    }

    $mailConfig = $this->general_settings_model->getSmtpCredentials();
    if (empty($mailConfig['mail_sender']) || empty($mailConfig['smtp_host']) || empty($mailConfig['smtp_pass'])) {
      echo 'Configurações de e-mail incompletas nos parâmetros gerais.';
      exit;
    }

    $results = $this->global_model->getWhereOrderByLimit_off('crm_cron', ['service' => 'EnviarEmail'], 'id', 'asc', 5, FALSE);

    $config['protocol'] = "smtp";
    $config['smtp_host'] = $mailConfig['smtp_host'];
    $config['smtp_user'] = $mailConfig['mail_sender'];
    $config['smtp_pass'] = $mailConfig['smtp_pass'];
    $config['smtp_port'] = $mailConfig['smtp_port'] ?: 587;
    $config['smtp_timeout'] = "5";
    $config['smtp_keepalive'] = false;
    $config['wordwrap'] = true;
    $config['mailtype'] = "html";
    $config['charset'] = 'utf-8';
    $config['validate'] = false;
    $config['crlf'] = "\r\n";
    $config['newline'] = "\r\n";

    $this->load->library('email', $config);

    foreach ($results as $result) {
      $configEmail = json_decode($result->parameters);

      $this->email->clear(TRUE);
      $this->email->set_newline("\r\n");
      $this->email->from($mailConfig['mail_sender'], $this->config->item('title'));

      if (empty($configEmail->reply_to)) {
        $this->email->reply_to($mailConfig['mail_sender']);
      } else {
        $this->email->reply_to($configEmail->reply_to);
      }

      $to  = (array) $configEmail->to;
      $cc  = (array) $configEmail->cc;
      $cco = (array) $configEmail->cco;

      $this->email->to($to);
      $this->email->cc($cc);
      $this->email->bcc($cco);
      $this->email->subject($configEmail->title);
      $this->email->message($configEmail->body);

      // "attach" aceita um caminho solto (comportamento antigo) ou uma lista.
      // Cada item pode ser o caminho ou um objeto {path, name} — os anexos de
      // lead usam a segunda forma, porque em disco o arquivo tem nome aleatório
      // e o destinatário precisa ver o nome original. O clear(TRUE) acima já
      // zerou os anexos da mensagem anterior do lote.
      if (!empty($configEmail->attach)) {
        foreach ((array) $configEmail->attach as $attachment) {
          $path = is_object($attachment) || is_array($attachment)
            ? (string) ((array) $attachment)['path']
            : (string) $attachment;

          if ($path === '') {
            continue;
          }

          $originalName = is_object($attachment) || is_array($attachment)
            ? (string) (((array) $attachment)['name'] ?? '')
            : '';

          if (!$this->email->attach($path, '', $originalName !== '' ? $originalName : NULL)) {
            log_message('error', 'cron_enviar_email: falha ao anexar ' . $path . ' no cron #' . $result->id);
          }
        }
      }

      $level = error_reporting();
      error_reporting(1);

      if ($this->email->send()) {
        echo 'OK ' . $result->service . ' ' . $result->id . '<br/>';
        $this->global_model->delete('crm_cron', 'id', $result->id);
      } else {
        // Antes a linha era apagada também na falha e a notificação se perdia em
        // silêncio — justamente o lead com anexo, que é o mais custoso de
        // perder. Agora a primeira falha guarda o motivo e mantém a linha para
        // uma nova tentativa; a segunda desiste. Sem esse limite, um envio
        // sempre falho travaria a fila para sempre por causa do break.
        $debug = strip_tags($this->email->print_debugger());
        echo $debug;
        echo 'FALHA ' . $result->service . ' ' . $result->id . '<br/>';
        log_message('error', 'cron_enviar_email: falha no envio do cron #' . $result->id);

        if (empty($result->error)) {
          $this->global_model->edit('crm_cron', ['error' => mb_substr($debug, 0, 5000)], 'id', $result->id);
        } else {
          log_message('error', 'cron_enviar_email: descartando cron #' . $result->id . ' após a segunda falha.');
          $this->global_model->delete('crm_cron', 'id', $result->id);
        }

        break;
      }

      error_reporting($level);
    }

    // Update last execution
    $this->global_model->edit('crm_cron_logs', ['modified' => date("Y-m-d H:i:s")], 'name', 'cron_enviar_email');
    echo 'OK ' . count($results) . ' registro(s)';
  }

  public function cron_notificaerrosagencia()
  {
    if (!$this->isCronActive('cron_notificaerrosagencia')) return;

    $filedir = APPPATH . 'logs/log-' . date('Y') . '.php';

    if (file_exists($filedir)) {
      // Rotaciona antes de ler: o log continua recebendo escrita enquanto o e-mail é
      // montado, e entrada gravada entre a leitura e a exclusão seria perdida. O rename
      // é atômico — as próximas entradas já caem num log-<ano>.php novo.
      // O nome mantém a extensão .php de propósito: é ela que faz a linha de proteção
      // do topo valer caso o arquivo seja acessado direto pela web.
      $rotated = APPPATH . 'logs/log-' . date('Y') . '-envio-' . date('YmdHis') . '.php';

      if (!@rename($filedir, $rotated)) {
        echo 'Falha ao rotacionar o log de erros.' . PHP_EOL;
        return;
      }

      $contentFile = @file_get_contents($rotated);

      if ($contentFile === FALSE) {
        echo 'Falha ao ler o log rotacionado: ' . $rotated . PHP_EOL;
        return;
      }

      // Corta a linha de proteção do topo (a tag PHP com o defined('BASEPATH')) que o
      // CI grava ao criar o arquivo. Antes isso era feito dando include() no log por
      // uma chamada HTTP à própria aplicação; ler o arquivo e cortar o cabeçalho chega
      // ao mesmo resultado sem rede e sem executar o conteúdo do log.
      if (strncmp($contentFile, '<?php', 5) === 0 && ($fim = strpos($contentFile, '?>')) !== FALSE) {
        $contentFile = ltrim(substr($contentFile, $fim + 2), "\r\n");
      }

      $this->data['title'] = 'Monitoramento de erros PHP';
      // Escapa antes de trocar o {END}: mensagem de erro pode conter HTML e sairia
      // quebrada (ou injetada) no corpo do e-mail.
      $this->data['message'] = str_replace('{END}', '<br/><br/>', html_escape($contentFile));
      $body = $this->global_model->body_email("emails/phperrors/notify", $this->data);

      // send_email() só enfileira em crm_cron. Sem confirmação de que entrou na fila o
      // log não pode ser apagado, senão o erro some sem ninguém ver.
      if ($this->global_model->send_email($this->data['title'], $body, ['willyan@cdwtech.com.br'], NULL, NULL, NULL)) {
        unlink($rotated);
        echo 'E-mail enfileirado! ';
      } else {
        echo 'Falha ao enfileirar o e-mail; log preservado em ' . $rotated . PHP_EOL;
      }
    }

    // Update last execution
    $this->global_model->edit('crm_cron_logs', ['modified' => date("Y-m-d H:i:s"), 'parameters' => NULL], 'name', 'cron_notificaerrosagencia');
    echo 'OK';
  }

  /**
   * Sincroniza os domínios de todos os servidores ativos.
   *
   * Só CLI (php index.php cron cron_sync_servers): a varredura do CloudPanel
   * pode levar minutos por servidor e o DirectAdmin faz três chamadas por
   * usuário — pelo navegador isso morreria no max_execution_time.
   *
   * No crontab:  0 1 * * * cd /caminho/do/projeto && php index.php cron cron_sync_servers
   */
  public function cron_sync_servers()
  {
    if (!$this->input->is_cli_request()) {
      echo 'Este processo só pode ser executado via CLI.';
      return;
    }

    if (!$this->isCronActive('cron_sync_servers')) return;

    @set_time_limit(0);

    // Lock de arquivo: uma rodada anterior ainda pendurada em um servidor lento
    // não pode ser atropelada pela próxima chamada do crontab.
    $lockFile = APPPATH . 'cache/cron_sync_servers.lock';
    $lockHandle = @fopen($lockFile, 'c');
    if ($lockHandle === FALSE || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
      if (is_resource($lockHandle)) fclose($lockHandle);
      echo "Já existe uma execução de cron_sync_servers em andamento." . PHP_EOL;
      return;
    }

    $this->load->model('server_model');

    $servidores = $this->server_model->getSyncableServers();
    $total = count($servidores);

    echo "cron_sync_servers: {$total} servidor(es) ativo(s)." . PHP_EOL;

    $ok = 0;
    $parciais = 0;
    $falhas = 0;
    $mensagensDeErro = [];

    foreach ($servidores as $servidor) {
      $rotulo = $servidor->name . ' (' . $servidor->type . ')';

      try {
        // idCompany NULL: o cron roda fora de sessão e precisa alcançar os
        // servidores de todas as empresas. created_by/modified_by ficam com o
        // usuário de processos automáticos.
        $resultado = $this->server_model->syncDomains(
          (int) $servidor->id,
          NULL,
          (int) $this->config->item('id_user_process_auto')
        );
      } catch (Throwable $e) {
        $resultado = ['success' => FALSE, 'message' => $e->getMessage(), 'data' => NULL];
      }

      $status = isset($resultado['data']['status']) ? $resultado['data']['status'] : 'erro';

      if (!empty($resultado['success']) && $status === 'sucesso') {
        $ok++;
        echo "  [OK]   {$rotulo}: {$resultado['message']}" . PHP_EOL;
      } elseif (!empty($resultado['success'])) {
        $parciais++;
        echo "  [PARC] {$rotulo}: {$resultado['message']}" . PHP_EOL;
      } else {
        $falhas++;
        $mensagensDeErro[] = $rotulo . ': ' . $resultado['message'];
        echo "  [ERRO] {$rotulo}: {$resultado['message']}" . PHP_EOL;
      }
    }

    $this->global_model->edit('crm_cron_logs', [
      'modified' => date('Y-m-d H:i:s'),
      'errors' => empty($mensagensDeErro) ? NULL : mb_substr(implode(' | ', $mensagensDeErro), 0, 5000),
    ], 'name', 'cron_sync_servers');

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    echo "Concluído: {$ok} ok, {$parciais} parcial(is), {$falhas} com erro." . PHP_EOL;
  }

  /**
   * Consulta o WHOIS dos domínios INTERNACIONAIS (API Ninjas), atualizando
   * vencimento real e nameservers.
   *
   * Domínios .br têm rotina própria (cron_sync_whois_br): esta API não os
   * atende.
   *
   * No crontab (uma hora depois do sync de servidores, para alcançar domínios
   * recém-importados):
   *   30 3 * * * cd /caminho/do/projeto && php index.php cron cron_sync_whois
   */
  public function cron_sync_whois()
  {
    $this->executarWhois('internacional', 'cron_sync_whois');
  }

  /**
   * Consulta o RDAP do Registro.br dos domínios .br.
   *
   * Rotina separada da internacional de propósito: o RDAP é público e sem
   * quota mensal, então o teto por rodada é maior e a pausa entre chamadas é
   * mais folgada — e dá para desligar uma origem sem parar a outra.
   *
   * No crontab (depois da rodada internacional):
   *   0 4 * * * cd /caminho/do/projeto && php index.php cron cron_sync_whois_br
   */
  public function cron_sync_whois_br()
  {
    $this->executarWhois('br', 'cron_sync_whois_br');
  }

  // ------------------------------------------------------------------
  // Faturamento
  //
  // As duas rotinas são separadas de propósito: índice faltando não pode
  // impedir a geração de faturas dos outros contratos, e o painel de CRON
  // precisa ligar e desligar cada uma por conta própria.
  //
  // A ORDEM no crontab importa — o reajuste roda ANTES da geração, para que a
  // fatura do dia já saia com o valor novo:
  //   0 5 * * *  cd /caminho/do/projeto && php index.php cron cron_reajustar_contratos
  //   30 5 * * * cd /caminho/do/projeto && php index.php cron cron_gerar_faturas
  // ------------------------------------------------------------------

  /**
   * Gera as faturas dos contratos faturados pelo CDW Finance.
   *
   * Só CLI, como o sync de servidores: a rodada percorre a base inteira e pelo
   * navegador morreria no max_execution_time com metade do trabalho feito.
   */
  public function cron_gerar_faturas()
  {
    // if (!$this->input->is_cli_request()) {
    //   echo 'Este processo só pode ser executado via CLI.';
    //   return;
    // }

    if (!$this->isCronActive('cron_gerar_faturas')) return;

    @set_time_limit(0);

    $lockHandle = $this->travar('cron_gerar_faturas');
    if ($lockHandle === FALSE) return;

    $this->load->model('invoice_model');

    $idUser = (int) $this->config->item('id_user_process_auto');
    $contratos = $this->invoice_model->getBillableContracts();
    $total = count($contratos);

    echo "cron_gerar_faturas: {$total} contrato(s) elegível(is)." . PHP_EOL;

    $geradas = 0;
    $comFatura = 0;
    $falhas = 0;
    $mensagensDeErro = [];

    foreach ($contratos as $contrato) {
      $rotulo = 'contrato #' . $contrato->id;

      try {
        $resultado = $this->invoice_model->generateForContract($contrato, $idUser);
      } catch (Throwable $e) {
        $resultado = ['success' => FALSE, 'message' => $e->getMessage(), 'data' => ['geradas' => 0]];
      }

      $quantas = (int) $resultado['data']['geradas'];

      if (!$resultado['success']) {
        $falhas++;
        $mensagensDeErro[] = $rotulo . ': ' . $resultado['message'];
        echo "  [ERRO] {$rotulo}: {$resultado['message']}" . PHP_EOL;
        continue;
      }

      if ($quantas > 0) {
        $geradas += $quantas;
        $comFatura++;
        echo "  [OK]   {$rotulo}: {$quantas} fatura(s) gerada(s)." . PHP_EOL;
      }
    }

    // ----------------------------------------------------------------
    // Fase 2: as faturas viram cobrança de verdade no PSP.
    //
    // Separada da geração de propósito. A fatura é gravada em transação
    // própria e protegida pela UNIQUE; a ida ao PSP é rede, e rede falha. Se
    // as duas andassem juntas, uma indisponibilidade do banco impediria a
    // fatura de existir — e o motor perderia a competência.
    //
    // Aqui não há fila em tabela: fatura aberta com `psp_charge_id` vazio JÁ
    // é a lista do que falta. O que não couber no orçamento de tempo fica
    // para a próxima rodada, e a repetição continua de onde parou.
    // ----------------------------------------------------------------
    $this->load->model('psp_model');

    $cobrancas = $this->psp_model->processarPendentes(['id_user' => $idUser]);

    echo sprintf(
      '  cobranças: %d registrada(s), %d atualizada(s), %d falha(s).%s',
      $cobrancas['registradas'],
      $cobrancas['sincronizadas'],
      $cobrancas['falhas'],
      PHP_EOL
    );

    foreach ($cobrancas['mensagens'] as $mensagem) {
      echo '  [COBRANÇA] ' . $mensagem . PHP_EOL;
      $mensagensDeErro[] = $mensagem;
    }

    $this->global_model->edit('crm_cron_logs', [
      'modified' => date('Y-m-d H:i:s'),
      'errors' => empty($mensagensDeErro) ? NULL : mb_substr(implode(' | ', $mensagensDeErro), 0, 5000),
    ], 'name', 'cron_gerar_faturas');

    $this->destravar($lockHandle);

    echo "Concluído: {$geradas} fatura(s) em {$comFatura} contrato(s), {$falhas} com erro." . PHP_EOL;
  }

  /**
   * Avisa e aplica os reajustes anuais.
   *
   * Duas fases na mesma rotina: primeiro os avisos (contratos que entram na
   * janela de antecedência), depois as aplicações (contratos cuja data chegou).
   * Juntas porque compartilham o cálculo do acumulado e a leitura dos índices;
   * separadas do faturamento porque a falha de uma não pode parar o outro.
   */
  public function cron_reajustar_contratos()
  {
    if (!$this->input->is_cli_request()) {
      echo 'Este processo só pode ser executado via CLI.';
      return;
    }

    if (!$this->isCronActive('cron_reajustar_contratos')) return;

    @set_time_limit(0);

    $lockHandle = $this->travar('cron_reajustar_contratos');
    if ($lockHandle === FALSE) return;

    $this->load->model('adjustment_model');

    $idUser = (int) $this->config->item('id_user_process_auto');
    $mensagensDeErro = [];

    // --- Fase 1: avisos prévios ---
    $aNotificar = $this->adjustment_model->getContractsToNotify();
    echo 'cron_reajustar_contratos: ' . count($aNotificar) . ' aviso(s) pendente(s).' . PHP_EOL;

    $avisados = 0;
    foreach ($aNotificar as $contrato) {
      $rotulo = 'contrato #' . $contrato->id;

      try {
        $resultado = $this->adjustment_model->notifyContract($contrato, $idUser);
      } catch (Throwable $e) {
        $resultado = ['success' => FALSE, 'message' => $e->getMessage(), 'data' => NULL];
      }

      if (!empty($resultado['success'])) {
        $avisados++;
        echo "  [AVISO] {$rotulo}: {$resultado['message']}" . PHP_EOL;
      } else {
        $mensagensDeErro[] = 'aviso ' . $rotulo . ': ' . $resultado['message'];
        echo "  [ERRO]  {$rotulo}: {$resultado['message']}" . PHP_EOL;
      }
    }

    // --- Fase 2: aplicação ---
    $aReajustar = $this->adjustment_model->getDueContracts();
    echo 'Reajustes a aplicar: ' . count($aReajustar) . PHP_EOL;

    $aplicados = 0;
    $pulados = 0;

    foreach ($aReajustar as $contrato) {
      $rotulo = 'contrato #' . $contrato->id;

      try {
        $resultado = $this->adjustment_model->applyForContract($contrato, $idUser);
      } catch (Throwable $e) {
        $resultado = ['success' => FALSE, 'message' => $e->getMessage(), 'data' => NULL];
      }

      if (empty($resultado['success'])) {
        // Índice incompleto é o caso comum aqui, e não é falha da rotina: o
        // contrato fica para a próxima rodada, com o valor intacto.
        $pulados++;
        $mensagensDeErro[] = $rotulo . ': ' . $resultado['message'];
        echo "  [PULA]  {$rotulo}: {$resultado['message']}" . PHP_EOL;
        continue;
      }

      $aplicados++;
      $dados = $resultado['data'];
      echo sprintf(
        "  [OK]    %s: %s%% — de %s para %s.%s" . PHP_EOL,
        $rotulo,
        $dados['rate'],
        reais($dados['value_before']),
        reais($dados['value_after']),
        $dados['avisado'] ? '' : ' (sem aviso prévio registrado)'
      );

      if (empty($dados['avisado'])) {
        log_message('error', sprintf(
          '[REAJUSTE] Contrato %d reajustado sem aviso previo ao cliente (%s%%).',
          (int) $contrato->id,
          $dados['rate']
        ));
      }
    }

    $this->global_model->edit('crm_cron_logs', [
      'modified' => date('Y-m-d H:i:s'),
      'errors' => empty($mensagensDeErro) ? NULL : mb_substr(implode(' | ', $mensagensDeErro), 0, 5000),
    ], 'name', 'cron_reajustar_contratos');

    $this->destravar($lockHandle);

    echo "Concluído: {$avisados} aviso(s), {$aplicados} reajuste(s) aplicado(s), {$pulados} pulado(s)." . PHP_EOL;
  }

  /**
   * Monitoramento dos sites dos clientes: DNS ao vivo e estado da home.
   *
   * Só CLI: são centenas de domínios com uma consulta de DNS e pelo menos uma
   * requisição HTTP cada, e a rodada pode passar de vinte minutos.
   *
   * Crontab (depois da sincronização dos servidores e dos WHOIS, para o retrato
   * do dia já refletir a noite):
   *
   *   0 6 * * * cd /caminho/do/projeto && php index.php cron cron_monitorar_sites
   *
   * O envio do resumo por e-mail NÃO acontece aqui — quem despacha a fila é o
   * cron_enviar_email, que já roda.
   */
  public function cron_monitorar_sites()
  {
    if (!$this->input->is_cli_request()) {
      echo 'Este processo só pode ser executado via CLI.';
      return;
    }

    if (!$this->isCronActive('cron_monitorar_sites')) return;

    @set_time_limit(0);

    $lockHandle = $this->travar('cron_monitorar_sites');
    if ($lockHandle === FALSE) return;

    $this->load->model('site_monitor_model');

    $idUser = (int) $this->config->item('id_user_process_auto');
    // `email` entra na seleção porque a cascata de destinatários do resumo cai
    // nele quando não há endereço em Parâmetros gerais.
    $empresas = $this->db->query(
      'SELECT `id`, `byname`, `email` FROM `crm_companies` WHERE `id_status` = 1 ORDER BY `id` ASC'
    )->result();

    $mensagensDeErro = [];
    $totalChecados = 0;
    $totalEventos = 0;

    foreach ($empresas as $empresa) {
      $rotulo = 'empresa #' . $empresa->id . ' (' . $empresa->byname . ')';

      try {
        $relatorio = $this->site_monitor_model->executarRodada($empresa->id, $idUser);
      } catch (Throwable $e) {
        $mensagensDeErro[] = $rotulo . ': ' . $e->getMessage();
        echo "  [ERRO] {$rotulo}: {$e->getMessage()}" . PHP_EOL;
        log_message('error', '[MONITOR] Falha na rodada — ' . $rotulo . ': ' . $e->getMessage());
        continue;
      }

      $pop = $relatorio['populacao'];
      $fora = $relatorio['fora_do_recorte'];

      echo $rotulo . ': ' . $pop['elegiveis'] . ' domínio(s) monitorado(s)'
        . ' (' . $pop['novos'] . ' novo(s), ' . $pop['reativados'] . ' reativado(s), '
        . $pop['desativados'] . ' desativado(s)).' . PHP_EOL;

      // Descarte silencioso numa rotina de vigilância é indistinguível de "está
      // tudo bem" — por isso tudo que ficou de fora é dito em voz alta.
      if (!empty($pop['descartados'])) {
        echo '  [INFO] ' . count($pop['descartados']) . ' nome(s) fora do padrão de host, ignorado(s): '
          . implode(', ', array_slice($pop['descartados'], 0, 10))
          . (count($pop['descartados']) > 10 ? ' ...' : '') . PHP_EOL;
      }
      if ($fora['sem_tipo'] > 0) {
        echo '  [INFO] ' . $fora['sem_tipo'] . ' domínio(s) de contrato SEM tipo de serviço cadastrado'
          . ' ficaram de fora — preencher o tipo do contrato os traz para o monitoramento.' . PHP_EOL;
      }
      if ($fora['tipo_sem_site'] > 0) {
        echo '  [INFO] ' . $fora['tipo_sem_site'] . ' domínio(s) de contrato sem tipo de serviço com site'
          . ' (e-mail/gerenciamento de domínio).' . PHP_EOL;
      }

      foreach ($relatorio['linhas'] as $linha) {
        if (empty($linha['eventos']) && $linha['ok']) continue;
        $marca = $linha['ok'] ? '[OK]  ' : '[FORA]';
        echo '  ' . $marca . ' ' . $linha['domain']
          . ' (' . $linha['http_result'] . ' ' . $linha['http_status'] . ')'
          . (empty($linha['eventos']) ? '' : ' -> ' . implode(', ', $linha['eventos'])) . PHP_EOL;
      }

      $totalChecados += $relatorio['checados'];
      $totalEventos += $relatorio['eventos'];

      if ($relatorio['abortada']) {
        $mensagensDeErro[] = $rotulo . ': ' . $relatorio['motivo'];
        echo '  [STOP] ' . $relatorio['motivo'] . PHP_EOL;
        continue;
      }

      if ($relatorio['motivo'] !== '') echo '  [INFO] ' . $relatorio['motivo'] . PHP_EOL;

      echo '  ' . $relatorio['checados'] . ' checado(s), ' . $relatorio['ok'] . ' no ar, '
        . $relatorio['falhas'] . ' com falha, ' . $relatorio['eventos'] . ' evento(s).' . PHP_EOL;

      // O resumo só é montado quando a rodada foi conclusiva — é o último degrau
      // da proteção contra o e-mail com 300 falsos positivos.
      try {
        $resumo = $this->site_monitor_model->enviarResumo($empresa);
        echo '  [MAIL] ' . $resumo['message'] . PHP_EOL;
        if (!$resumo['enviado'] && $resumo['eventos'] > 0) $mensagensDeErro[] = $rotulo . ': ' . $resumo['message'];
      } catch (Throwable $e) {
        $mensagensDeErro[] = $rotulo . ' (resumo): ' . $e->getMessage();
        echo '  [ERRO] Falha ao montar o resumo: ' . $e->getMessage() . PHP_EOL;
        log_message('error', '[MONITOR] Falha ao montar o resumo — ' . $rotulo . ': ' . $e->getMessage());
      }
    }

    $this->global_model->edit('crm_cron_logs', [
      'modified' => date('Y-m-d H:i:s'),
      'errors' => empty($mensagensDeErro) ? NULL : mb_substr(implode(' | ', $mensagensDeErro), 0, 5000),
    ], 'name', 'cron_monitorar_sites');

    $this->destravar($lockHandle);

    echo "Concluído: {$totalChecados} domínio(s) checado(s), {$totalEventos} evento(s)." . PHP_EOL;
  }

  /**
   * Lock de arquivo — uma rodada anterior ainda em andamento não pode ser
   * atropelada pela próxima chamada do crontab.
   *
   * As guardas de configuração de cada rotina vêm ANTES desta chamada, de
   * propósito: travar primeiro deixaria o arquivo preso em todo cenário de
   * "rotina desativada" ou "não é CLI".
   *
   * @param  string $nome
   * @return resource|bool FALSE quando já existe execução em andamento
   */
  private function travar($nome)
  {
    $lockFile = APPPATH . 'cache/' . $nome . '.lock';
    $lockHandle = @fopen($lockFile, 'c');

    if ($lockHandle === FALSE || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
      if (is_resource($lockHandle)) fclose($lockHandle);
      echo "Já existe uma execução de {$nome} em andamento." . PHP_EOL;

      // O echo sozinho vai para o stdout do cron, que ninguém lê. Numa rotina de
      // vigilância, "a vigilância parou" é o alarme mais importante de todos, e é
      // a tela de CRON que precisa mostrá-lo. UPDATE de uma linha, idempotente.
      $this->global_model->edit('crm_cron_logs', [
        'errors' => 'Execução anterior ainda em andamento em ' . date('d/m/Y H:i') . '; esta chamada não rodou.',
      ], 'name', $nome);

      return FALSE;
    }

    return $lockHandle;
  }

  /**
   * @param resource $lockHandle
   */
  private function destravar($lockHandle)
  {
    if (!is_resource($lockHandle)) return;

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
  }

  /**
   * Corpo comum das duas rotinas de WHOIS.
   *
   * Só CLI: com o teto por rodada e timeout de 20s por chamada, uma rodada ruim
   * passa de meia hora — pelo navegador morreria no max_execution_time com
   * metade do trabalho feito.
   *
   * O escopo chega como string simples, e não como constante do Whois_model,
   * porque os métodos públicos são resolvidos antes de o model ser carregado.
   *
   * @param  string $escopo 'br' | 'internacional'
   * @param  string $nome   nome da rotina em crm_cron_logs
   * @return void
   */
  private function executarWhois($escopo, $nome)
  {
    if (!$this->input->is_cli_request()) {
      echo 'Este processo só pode ser executado via CLI.';
      return;
    }

    if (!$this->isCronActive($nome)) return;

    @set_time_limit(0);

    $this->load->model('whois_model');

    $ehBr = ($escopo === Whois_model::ESCOPO_BR);
    $origem = $ehBr ? 'RDAP .br' : 'Ninjas API';
    $limite = $ehBr ? Whois_model::LIMITE_POR_RODADA_BR : Whois_model::LIMITE_POR_RODADA_PADRAO;
    $pausa = $ehBr ? Whois_model::PAUSA_ENTRE_CHAMADAS_BR_US : Whois_model::PAUSA_ENTRE_CHAMADAS_US;

    // As guardas de configuração vêm ANTES do lock de propósito: são returns
    // antecipados, e tomar o lock antes deixaria o arquivo travado até o fim do
    // processo em todo cenário de configuração faltando.
    if (!$this->whois_model->isActive($escopo)) {
      echo 'Consulta desativada em Parâmetros gerais > ' . $origem . '.' . PHP_EOL;
      return;
    }

    // O RDAP do Registro.br é público; só o escopo internacional tem chave.
    $chave = '';
    if (!$ehBr) {
      $chave = $this->whois_model->getApiKey();

      if ($chave === FALSE) {
        $mensagem = 'Chave da API Ninjas ilegível — a secret_crypto_key mudou ou não está configurada. Recadastre em Parâmetros gerais.';
        echo $mensagem . PHP_EOL;
        $this->registrarCronWhois($nome, [$mensagem]);
        return;
      }

      if ($chave === '') {
        $mensagem = 'Chave da API Ninjas não cadastrada em Parâmetros gerais > Ninjas API.';
        echo $mensagem . PHP_EOL;
        $this->registrarCronWhois($nome, [$mensagem]);
        return;
      }
    }

    $lockFile = APPPATH . 'cache/' . $nome . '.lock';
    $lockHandle = @fopen($lockFile, 'c');
    if ($lockHandle === FALSE || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
      if (is_resource($lockHandle)) fclose($lockHandle);
      echo 'Já existe uma execução de ' . $nome . ' em andamento.' . PHP_EOL;
      return;
    }

    $idUser = (int) $this->config->item('id_user_process_auto');
    $grupos = $this->whois_model->getPendingDomains($escopo, NULL, $limite);
    $total = count($grupos);

    echo "{$nome}: {$total} domínio(s) a consultar em {$origem}." . PHP_EOL;

    $ok = 0;
    $trocasNs = 0;
    $livres = 0;
    $falhas = 0;
    $mensagensDeErro = [];

    foreach ($grupos as $registravel => $grupo) {
      $servidores = count($grupo['linhas']);
      $rotulo = $registravel . ($servidores > 1 ? " ({$servidores} linhas)" : '');

      try {
        $resultado = $this->whois_model->syncDomain($registravel, $grupo, $chave, $idUser);
      } catch (Throwable $e) {
        $resultado = ['success' => FALSE, 'message' => $e->getMessage(), 'abort' => FALSE, 'ns_changed' => FALSE];
      }

      if (!empty($resultado['success']) && !empty($resultado['ns_changed'])) {
        $trocasNs++;
        echo "  [NS]   {$rotulo}: nameservers alterados — {$resultado['message']}" . PHP_EOL;
      } elseif (!empty($resultado['success'])) {
        $ok++;
        echo "  [OK]   {$rotulo}: {$resultado['message']}" . PHP_EOL;
      } elseif (!empty($resultado['livre'])) {
        // Domínio livre não é falha da rodada: a origem respondeu, e a resposta
        // (o registro não existe) foi gravada. Entrasse no balaio de erros, uma
        // base com muitos domínios liberados marcaria o cron como quebrado em
        // Painel > CRON e esconderia o erro de verdade no meio da lista.
        $livres++;
        echo "  [LIVRE] {$rotulo}: {$resultado['message']}" . PHP_EOL;
      } else {
        $falhas++;
        $mensagensDeErro[] = $rotulo . ': ' . $resultado['message'];
        echo "  [ERRO] {$rotulo}: {$resultado['message']}" . PHP_EOL;
      }

      // Chave inválida, quota estourada ou limite do Registro.br é problema
      // global: insistir nos domínios seguintes só gastaria tempo e encheria o
      // log de repetição.
      if (!empty($resultado['abort'])) {
        $mensagensDeErro[] = 'Rodada interrompida: ' . $resultado['message'];
        echo '  [STOP] Rodada interrompida — ' . $resultado['message'] . PHP_EOL;
        break;
      }

      usleep($pausa);
    }

    $this->registrarCronWhois($nome, $mensagensDeErro);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    echo "Concluído: {$ok} ok, {$trocasNs} com troca de NS, {$livres} livre(s), {$falhas} com erro." . PHP_EOL;
  }

  /**
   * @param  string $nome
   * @param  array  $erros
   * @return void
   */
  private function registrarCronWhois($nome, array $erros)
  {
    $this->global_model->edit('crm_cron_logs', [
      'modified' => date('Y-m-d H:i:s'),
      'errors' => empty($erros) ? NULL : mb_substr(implode(' | ', $erros), 0, 5000),
    ], 'name', $nome);
  }
}
