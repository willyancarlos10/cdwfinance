<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Parametros_gerais extends MY_Controller
{
  /** Abas aceitas em ?tab= — valor fora daqui deixaria a tela sem aba ativa. */
  const TABS = ['tab_email', 'tab_ninjas', 'tab_rdap'];

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

    $this->load->library('secret_crypto');
    $this->data['crypto_ready'] = $this->secret_crypto->isReady();

    $this->load->view('header', $this->data);
    $this->load->view('parametros_gerais/index', $this->data);
    $this->load->view('footer', $this->data);
  }
}
