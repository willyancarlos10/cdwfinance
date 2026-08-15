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
