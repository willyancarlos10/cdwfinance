<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Parametros_gerais extends MY_Controller
{
  /** Abas aceitas em ?tab= — valor fora daqui deixaria a tela sem aba ativa. */
  const TABS = ['tab_email', 'tab_ninjas', 'tab_rdap', 'tab_faturamento', 'tab_monitoramento'];

  public function __construct()
  {
    parent::__construct();

    if ($this->session->userdata('company')->id != 1) {
      $this->session->set_flashdata('warning', 'Sem permissão de acesso.');
      redirect(base_url());
    }

    $this->data['menu'] = 'parametros_gerais';
    $this->load->model('general_settings_model');
  }

  public function index()
  {
    $this->renderIndex($this->resolveTabDefault());
  }

  public function post_email()
  {
    $input = $this->input->post('email');
    if (!is_array($input)) {
      $input = [];
    }

    $serviceType = !empty($input['mail_service_type']) ? $input['mail_service_type'] : 'smtp_local';

    $this->form_validation->set_rules('email[mail_service_type]', 'Tipo de serviço', 'trim|required|in_list[brevo,smtp_local]');
    $this->form_validation->set_rules('email[mail_smtp_host]', 'Host SMTP', 'trim|required|max_length[255]');
    $this->form_validation->set_rules('email[mail_smtp_port]', 'Porta SMTP', 'trim|required|integer|greater_than[0]|less_than[65536]');
    $this->form_validation->set_rules('email[mail_sender]', 'E-mail remetente', 'trim|required|valid_email|max_length[255]');

    if ($serviceType === 'brevo') {
      $this->form_validation->set_rules('email[brevo_api_key]', 'Brevo API Key', 'trim|max_length[255]');
    }

    if ($this->form_validation->run() === FALSE) {
      $this->renderIndex('tab_email');
      return;
    }

    $current = $this->general_settings_model->getGroup('email');

    $values = [
      'mail_active' => !empty($input['mail_active']) ? '1' : '0',
      'mail_service_type' => $serviceType,
      'mail_smtp_host' => trim($input['mail_smtp_host']),
      'mail_smtp_port' => (string) (int) $input['mail_smtp_port'],
      'mail_sender' => trim($input['mail_sender']),
      'brevo_api_key' => $current['brevo_api_key'] ?? '',
      'mail_smtp_pass' => $current['mail_smtp_pass'] ?? '',
    ];

    if ($serviceType === 'brevo') {
      $values['mail_smtp_pass'] = '';
      if (!empty($input['brevo_api_key'])) {
        $values['brevo_api_key'] = trim($input['brevo_api_key']);
      }
    } else {
      $values['brevo_api_key'] = '';
      if (!empty($input['mail_smtp_pass'])) {
        $values['mail_smtp_pass'] = trim($input['mail_smtp_pass']);
      }
    }

    if (!$this->general_settings_model->saveGroup(
      'email',
      $values,
      (int) $this->session->userdata('user')->id
    )) {
      $this->session->set_flashdata('error', 'Não foi possível salvar as configurações de e-mail.');
      redirect(base_url('parametros_gerais?tab=tab_email'));
    }

    $this->session->set_flashdata('success', 'Configurações de e-mail salvas com sucesso.');
    redirect(base_url('parametros_gerais?tab=tab_email'));
  }

  /**
   * Chave da API Ninjas (WHOIS). Cifrada com Secret_crypto antes de ir para o
   * banco — diferente dos campos de e-mail desta mesma tela, que são legado em
   * texto puro.
   */
  public function post_ninjas()
  {
    $input = $this->input->post('ninjas');
    if (!is_array($input)) {
      $input = [];
    }

    $values = [
      'ninjas_active' => !empty($input['ninjas_active']) ? '1' : '0',
    ];

    // A chave vem FORA do array `ninjas[...]` para poder ser lida sem xss_clean
    // (segundo parâmetro FALSE) — o filtro reescreveria silenciosamente uma
    // chave que contivesse uma sequência suspeita, e a corrupção só apareceria
    // na primeira chamada à API. Mesmo motivo do post('secret', FALSE) em
    // Servidores.php.
    $chave = trim((string) $this->input->post('ninjas_api_key', FALSE));

    if ($chave !== '') {
      if (mb_strlen($chave) > 255) {
        $this->session->set_flashdata('error', 'A chave da API Ninjas deve ter no máximo 255 caracteres.');
        redirect(base_url('parametros_gerais?tab=tab_ninjas'));
      }

      $this->load->library('secret_crypto');
      $cifrada = $this->secret_crypto->encrypt($chave);

      if ($cifrada === FALSE) {
        $this->session->set_flashdata('error', 'A chave de criptografia (secret_crypto_key) não está configurada. A chave da API Ninjas não foi salva.');
        redirect(base_url('parametros_gerais?tab=tab_ninjas'));
      }

      $values['ninjas_api_key'] = $cifrada;
    }
    // Campo em branco = manter a chave atual: a chave simplesmente não entra em
    // $values, então o saveGroup não encosta na linha dela.

    $this->general_settings_model->saveGroup(
      'ninjas',
      $values,
      (int) $this->session->userdata('user')->id
    );

    // A mensagem declara o ESTADO RESULTANTE do interruptor e o que aconteceu
    // com a chave. Um "salvo com sucesso" genérico, com o campo voltando em
    // branco, não distingue "gravou" de "não gravou nada" — foi o que fez esta
    // tela parecer quebrada.
    $this->session->set_flashdata('success', sprintf(
      'Consulta de WHOIS %s. %s',
      $values['ninjas_active'] === '1' ? 'ATIVADA' : 'DESATIVADA',
      $chave !== ''
        ? 'Chave da API Ninjas gravada — o campo volta em branco porque a chave nunca é devolvida para a tela.'
        : 'A chave cadastrada foi mantida.'
    ));
    redirect(base_url('parametros_gerais?tab=tab_ninjas'));
  }

  /**
   * Consulta de domínios .br pelo RDAP do Registro.br.
   *
   * Serviço público: não há chave a guardar, só o interruptor da integração.
   */
  public function post_rdap()
  {
    $input = $this->input->post('rdap');
    if (!is_array($input)) {
      $input = [];
    }

    $ativo = !empty($input['rdap_active']) ? '1' : '0';

    $this->general_settings_model->saveGroup('rdap', [
      'rdap_active' => $ativo,
    ], (int) $this->session->userdata('user')->id);

    $this->session->set_flashdata(
      'success',
      'Consulta de domínios .br ' . ($ativo === '1' ? 'ATIVADA.' : 'DESATIVADA.')
    );
    redirect(base_url('parametros_gerais?tab=tab_rdap'));
  }

  /** Confere se o RDAP do Registro.br está no ar. */
  public function json_posttestarrdap()
  {
    header('Content-Type: application/json; charset=utf-8');

    $this->load->library('rdap_registrobr');

    // Requisição web com sessão em volta de I/O de rede: solta o GET_LOCK do
    // MySQL durante a chamada. Não escrever na sessão entre os dois.
    $sessao = sessao_suspender();
    try {
      $resultado = $this->rdap_registrobr->test();
    } catch (Throwable $e) {
      $resultado = ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage()];
    } finally {
      sessao_retomar($sessao);
    }

    echo json_encode($this->jsonNinjas(!empty($resultado['success']), $resultado['message']));
  }

  /**
   * Testa a chave JÁ CADASTRADA contra um domínio .com — que o plano gratuito
   * atende —, para o teste não falhar por limitação de plano.
   */
  public function json_posttestarninjas()
  {
    header('Content-Type: application/json; charset=utf-8');

    $this->load->model('whois_model');
    $chave = $this->whois_model->getApiKey();

    if ($chave === FALSE) {
      echo json_encode($this->jsonNinjas(FALSE, 'A chave cadastrada está ilegível. Recadastre-a — a chave de criptografia pode ter sido trocada.'));
      return;
    }

    if ($chave === '') {
      echo json_encode($this->jsonNinjas(FALSE, 'Nenhuma chave cadastrada. Salve a chave antes de testar.'));
      return;
    }

    $this->load->library('ninjas_whois');

    // Requisição web com sessão em volta de I/O de rede: solta o GET_LOCK do
    // MySQL durante a chamada. Não escrever na sessão entre os dois.
    $sessao = sessao_suspender();
    try {
      $resultado = $this->ninjas_whois->test($chave);
    } catch (Throwable $e) {
      $resultado = ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage()];
    } finally {
      sessao_retomar($sessao);
    }

    echo json_encode($this->jsonNinjas(!empty($resultado['success']), $resultado['message']));
  }

  /**
   * Devolve a chave da API Ninjas em texto, sob demanda.
   *
   * Mesmo padrão (e mesmas razões) do Servidores::json_postrevelar: a chave não
   * viaja no HTML da tela — quem quiser conferi-la pede aqui, e a leitura fica
   * registrada no log da aplicação, que é a única trilha de quem leu o quê.
   * O construtor deste controller já restringe a tela à empresa 1.
   */
  public function json_postrevelarninjas()
  {
    header('Content-Type: application/json; charset=utf-8');
    // Resposta com segredo não pode ficar em cache de navegador ou proxy.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $this->load->model('whois_model');
    $chave = $this->whois_model->getApiKey();

    if ($chave === FALSE) {
      echo json_encode($this->jsonNinjas(FALSE, 'Não foi possível decifrar a chave. A chave de criptografia (secret_crypto_key) pode ter sido trocada — recadastre a chave da API.'));
      return;
    }

    if ($chave === '') {
      echo json_encode($this->jsonNinjas(FALSE, 'Nenhuma chave cadastrada.'));
      return;
    }

    $usuario = $this->session->userdata('user');
    log_message('error', sprintf(
      '[CREDENCIAL] Usuário %d (%s) visualizou a chave da API Ninjas a partir do IP %s.',
      (int) $usuario->id,
      isset($usuario->name) ? $usuario->name : '?',
      $this->input->ip_address()
    ));

    $retorno = $this->jsonNinjas(TRUE, 'Chave exibida.');
    $retorno['data'] = ['ninjas_api_key' => $chave];
    echo json_encode($retorno);
  }

  /**
   * @param  bool   $sucesso
   * @param  string $mensagem
   * @return array
   */
  private function jsonNinjas($sucesso, $mensagem)
  {
    return [
      'success' => (bool) $sucesso,
      'return' => (bool) $sucesso,
      'message' => (string) $mensagem,
      'data' => NULL,
      'errors' => $sucesso ? [] : ['ninjas' => (string) $mensagem],
    ];
  }

  /**
   * Parâmetros do faturamento próprio: antecedência da geração, dia de
   * vencimento sugerido e o texto do aviso de reajuste.
   *
   * O corpo do e-mail é texto livre com marcadores (`{cliente}`,
   * `{percentual}`...) — a substituição acontece no Adjustment_model, e
   * marcador desconhecido permanece literal, para que um erro de digitação
   * apareça no e-mail em vez de sumir em silêncio.
   */
  public function post_faturamento()
  {
    $input = $this->input->post('faturamento');
    if (!is_array($input)) {
      $input = [];
    }

    $antecedencia = isset($input['faturamento_dias_antecedencia']) ? (int) $input['faturamento_dias_antecedencia'] : 0;
    if ($antecedencia < 1 || $antecedencia > 90) {
      $this->session->set_flashdata('warning', 'A antecedência da geração deve estar entre 1 e 90 dias.');
      redirect(base_url('parametros_gerais?tab=tab_faturamento'));
    }

    $diaPadrao = isset($input['faturamento_dia_padrao']) ? (int) $input['faturamento_dia_padrao'] : 0;
    if ($diaPadrao < 1 || $diaPadrao > 31) {
      $this->session->set_flashdata('warning', 'O dia de vencimento padrão deve estar entre 1 e 31.');
      redirect(base_url('parametros_gerais?tab=tab_faturamento'));
    }

    $diasAviso = isset($input['reajuste_dias_aviso']) ? (int) $input['reajuste_dias_aviso'] : 0;
    if ($diasAviso < 1 || $diasAviso > 180) {
      $this->session->set_flashdata('warning', 'A antecedência do aviso de reajuste deve estar entre 1 e 180 dias.');
      redirect(base_url('parametros_gerais?tab=tab_faturamento'));
    }

    $assunto = trim((string) ($input['reajuste_email_assunto'] ?? ''));
    $corpo = trim((string) ($input['reajuste_email_corpo'] ?? ''));

    if ($assunto === '' || $corpo === '') {
      $this->session->set_flashdata('warning', 'Informe o assunto e o corpo do e-mail de reajuste.');
      redirect(base_url('parametros_gerais?tab=tab_faturamento'));
    }

    // O corpo do aviso de faturamento vem do Froala, então é HTML.
    //
    // O `global_xss_filtering` do projeto está DESLIGADO hoje, então o POST já
    // chega cru e este FALSE não muda nada agora. Ele está aqui para o dia em
    // que alguém ligar o filtro: o xss_clean do CI3 reescreve atributos e
    // quebra a marcação em silêncio, e o estrago só apareceria no e-mail já
    // enviado ao cliente. Mesmo cuidado do `post('secret', FALSE)` das
    // credenciais.
    $faturamentoCru = $this->input->post('faturamento', FALSE);
    if (!is_array($faturamentoCru)) {
      $faturamentoCru = [];
    }

    $assuntoFatura = trim((string) ($faturamentoCru['fatura_email_assunto'] ?? ''));
    $corpoFatura = trim((string) ($faturamentoCru['fatura_email_corpo'] ?? ''));

    if ($assuntoFatura === '' || $corpoFatura === '') {
      $this->session->set_flashdata('warning', 'Informe o assunto e o corpo do e-mail de faturamento.');
      redirect(base_url('parametros_gerais?tab=tab_faturamento'));
    }

    $assuntoNota = trim((string) ($faturamentoCru['nota_email_assunto'] ?? ''));
    $corpoNota = trim((string) ($faturamentoCru['nota_email_corpo'] ?? ''));

    if ($assuntoNota === '' || $corpoNota === '') {
      $this->session->set_flashdata('warning', 'Informe o assunto e o corpo do e-mail da nota fiscal.');
      redirect(base_url('parametros_gerais?tab=tab_faturamento'));
    }

    $this->general_settings_model->saveGroup('faturamento', [
      'faturamento_dias_antecedencia' => (string) $antecedencia,
      'faturamento_dia_padrao' => (string) $diaPadrao,
      'reajuste_dias_aviso' => (string) $diasAviso,
      'reajuste_email_assunto' => mb_substr($assunto, 0, 200),
      'reajuste_email_corpo' => $corpo,
      'fatura_email_assunto' => mb_substr($assuntoFatura, 0, 200),
      'fatura_email_corpo' => $corpoFatura,
      'nota_email_assunto' => mb_substr($assuntoNota, 0, 200),
      'nota_email_corpo' => $corpoNota,
    ], (int) $this->session->userdata('user')->id);

    $this->session->set_flashdata('success', 'Parâmetros de faturamento salvos.');
    redirect(base_url('parametros_gerais?tab=tab_faturamento'));
  }

  public function post_monitoramento()
  {
    $input = $this->input->post('monitoramento');
    if (!is_array($input)) {
      $input = [];
    }

    $intervalo = isset($input['monitoramento_intervalo_horas']) ? (int) $input['monitoramento_intervalo_horas'] : 0;
    if ($intervalo < 1 || $intervalo > 168) {
      $this->session->set_flashdata('warning', 'O intervalo entre checagens deve estar entre 1 e 168 horas.');
      redirect(base_url('parametros_gerais?tab=tab_monitoramento'));
    }

    $timeout = isset($input['monitoramento_timeout']) ? (int) $input['monitoramento_timeout'] : 0;
    if ($timeout < 3 || $timeout > 60) {
      $this->session->set_flashdata('warning', 'O tempo limite deve estar entre 3 e 60 segundos.');
      redirect(base_url('parametros_gerais?tab=tab_monitoramento'));
    }

    $diasSsl = isset($input['monitoramento_ssl_dias_aviso']) ? (int) $input['monitoramento_ssl_dias_aviso'] : 0;
    if ($diasSsl < 1 || $diasSsl > 90) {
      $this->session->set_flashdata('warning', 'A antecedência do aviso de SSL deve estar entre 1 e 90 dias.');
      redirect(base_url('parametros_gerais?tab=tab_monitoramento'));
    }

    // Allowlist de e-mails: o campo aceita lista separada por vírgula, e endereço
    // inválido é DESCARTADO com aviso em vez de ir para a fila — um destinatário
    // quebrado faria todo resumo falhar no cron_enviar_email.
    $bruto = (string) ($input['monitoramento_email_destinatarios'] ?? '');
    $validos = [];
    $invalidos = [];
    foreach (preg_split('/[;,\s]+/', $bruto) as $email) {
      $email = trim($email);
      if ($email === '') continue;
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) $validos[] = $email; else $invalidos[] = $email;
    }

    $this->general_settings_model->saveGroup('monitoramento', [
      'monitoramento_intervalo_horas' => (string) $intervalo,
      'monitoramento_timeout' => (string) $timeout,
      'monitoramento_ssl_dias_aviso' => (string) $diasSsl,
      'monitoramento_email_destinatarios' => mb_substr(implode(', ', array_unique($validos)), 0, 500),
    ], (int) $this->session->userdata('user')->id);

    if (!empty($invalidos)) {
      $this->session->set_flashdata('warning', 'Parâmetros salvos, mas estes endereços foram descartados por não serem válidos: '
        . implode(', ', $invalidos) . '.');
    } else {
      $this->session->set_flashdata('success', 'Parâmetros de monitoramento salvos.');
    }

    redirect(base_url('parametros_gerais?tab=tab_monitoramento'));
  }

  private function resolveTabDefault()
  {
    $tab = (string) $this->input->get('tab');
    return in_array($tab, self::TABS, TRUE) ? $tab : 'tab_email';
  }

  private function renderIndex($tabDefault = 'tab_email')
  {
    $this->data['tabs_default'] = $tabDefault;
    $this->data['email_settings'] = $this->general_settings_model->getGroup('email');

    // A chave cifrada NÃO vai para a view — mesmo princípio da coluna `secret`,
    // que não existe na crm_servers_v. A tela só precisa saber se há chave e
    // desde quando: como o campo sempre renderiza em branco, sem essa data o
    // salvamento bem-sucedido é indistinguível de um que não gravou nada.
    $ninjas = $this->general_settings_model->getGroup('ninjas');
    $this->data['ninjas_key_set'] = !empty($ninjas['ninjas_api_key']);
    $this->data['ninjas_key_modified'] = '';

    if ($this->data['ninjas_key_set']) {
      $meta = $this->general_settings_model->getSettingMeta('ninjas', 'ninjas_api_key');
      $this->data['ninjas_key_modified'] = !empty($meta->modified) ? $meta->modified : '';
    }

    unset($ninjas['ninjas_api_key']);
    $this->data['ninjas_settings'] = $ninjas;
    $this->data['rdap_settings'] = $this->general_settings_model->getGroup('rdap');

    // Faturamento: os models carregam os defaults, para a tela nunca abrir com
    // campo vazio antes do primeiro salvamento (o grupo só nasce no saveGroup).
    $this->load->model('invoice_model');
    $this->load->model('adjustment_model');

    $this->data['faturamento_settings'] = $this->general_settings_model->getGroup('faturamento');
    $this->data['faturamento_defaults'] = [
      'faturamento_dias_antecedencia' => $this->invoice_model->diasAntecedencia(),
      'faturamento_dia_padrao' => $this->invoice_model->diaPadrao(),
      'reajuste_dias_aviso' => $this->adjustment_model->diasAviso(),
      'reajuste_email_assunto' => $this->adjustment_model->assuntoConfigurado(),
      'reajuste_email_corpo' => $this->adjustment_model->corpoConfigurado(),
      'fatura_email_assunto' => $this->invoice_model->assuntoConfigurado(),
      'fatura_email_corpo' => $this->invoice_model->corpoConfigurado(),
      'nota_email_assunto' => $this->invoice_model->assuntoNotaConfigurado(),
      'nota_email_corpo' => $this->invoice_model->corpoNotaConfigurado(),
    ];
    $this->data['reajuste_marcadores'] = $this->adjustment_model->marcadoresDisponiveis();
    $this->data['fatura_marcadores'] = $this->invoice_model->marcadoresDisponiveis();


    // Monitoramento: mesmo padrão — os defaults vêm do model.
    $this->load->model('site_monitor_model');
    $this->data['monitoramento_settings'] = $this->general_settings_model->getGroup('monitoramento');
    $this->data['monitoramento_defaults'] = [
      'monitoramento_intervalo_horas' => $this->site_monitor_model->intervaloHoras(),
      'monitoramento_timeout' => $this->site_monitor_model->timeoutChecagem(),
      'monitoramento_ssl_dias_aviso' => $this->site_monitor_model->diasAvisoSsl(),
      'monitoramento_email_destinatarios' => '',
    ];

    $this->load->library('secret_crypto');
    $this->data['crypto_ready'] = $this->secret_crypto->isReady();

    $this->load->view('header', $this->data);
    $this->load->view('parametros_gerais/index', $this->data);
    $this->load->view('footer', $this->data);
  }
}
