<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Empresas extends MY_Controller
{
  /**
   * Catálogo de permissões oferecidas na geração de chave de API.
   *
   * É UMA constante, e não duas listas, de propósito: a tela lê os rótulos daqui
   * e o POST valida contra `array_keys()` desta mesma constante. No projeto de
   * referência (platagorma-painel-v3) a lista aparece literalmente duas vezes —
   * no catálogo da tela e na whitelist do POST —, e escopo cadastrado só num
   * dos lados aparece na tela e é descartado em silêncio ao salvar.
   *
   * Os escopos precisam bater com o `requireScope()` de cada endpoint em
   * Api_v1 e com o mapa de tools do Mcp.
   */
  const API_SCOPES = [
    'companies:read' => 'Consultar dados da empresa',
    'customers:read' => 'Consultar clientes',
    'contracts:read' => 'Consultar contratos',
    'invoices:read' => 'Consultar títulos do contrato (faturas e extrato do Bom Controle)',
    'contacts:read' => 'Consultar contatos de clientes',
    'attachments:read' => 'Consultar anexos de clientes',
    'servers:read' => 'Consultar servidores',
    'contract-domains:read' => 'Consultar domínios contratados',
    'server-domains:read' => 'Consultar domínios nos servidores',
    'service-types:read' => 'Consultar tipos de serviço',
  ];

  public function __construct()
  {
    parent::__construct();

    if ($this->session->userdata('company')->id != 1) {
      $this->session->set_flashdata('warning', 'Sem permissão de acesso.');
      redirect(base_url());
    }
    $this->data['menu'] = 'empresas';
    $this->setDefaultCompanyFilter();
    $this->load->model('api_key_model');
  }

  public function api_chaves()
  {
    $idCompany = (int) $this->input->get('id');
    $this->renderApiKeys($idCompany);
  }

  public function post_criar_chave_api()
  {
    $idCompany = (int) $this->input->post('id_company');
    $company = $this->getCompanyForApiKeys($idCompany);

    if (empty($company)) {
      $this->session->set_flashdata('warning', 'Empresa não encontrada.');
      redirect(base_url('empresas'));
    }

    $this->form_validation->set_rules('api_key[name]', 'Nome da chave', 'required|trim|max_length[100]');
    $scopes = $this->normalizeApiKeyScopes($this->input->post('api_key[scopes]'));

    if ($this->form_validation->run() === FALSE || $scopes === FALSE) {
      $this->session->set_flashdata('error', 'Informe um nome e ao menos uma permissão válida para a chave.');
      redirect(base_url('empresas/api_chaves?id=' . $idCompany));
    }

    $apiKeyName = trim($this->input->post('api_key[name]'));
    $result = $this->api_key_model->create(
      $idCompany,
      $apiKeyName,
      $scopes,
      (int) $this->session->userdata('user')->id
    );

    if (empty($result['success'])) {
      $this->session->set_flashdata('error', $result['message']);
      redirect(base_url('empresas/api_chaves?id=' . $idCompany));
    }

    $this->global_model->add('crm_companies_logs', [
      'id_company' => $idCompany,
      'description' => 'Chave de API criada: ' . $result['prefix'] . ' (' . htmlspecialchars($apiKeyName, ENT_QUOTES, 'UTF-8') . ').',
      'created' => date('Y-m-d H:i:s'),
      'created_by' => (int) $this->session->userdata('user')->id,
    ]);

    $this->session->set_flashdata('success', 'Chave de API criada com sucesso. Copie-a agora: ela não será exibida novamente.');
    $this->session->set_flashdata('api_key_plaintext', $result['token']);
    redirect(base_url('empresas/api_chaves?id=' . $idCompany));
  }

  public function post_revogar_chave_api()
  {
    $idCompany = (int) $this->input->post('id_company');
    $idKey = (int) $this->input->post('id_key');
    $company = $this->getCompanyForApiKeys($idCompany);

    if (empty($company)) {
      $this->session->set_flashdata('warning', 'Empresa não encontrada.');
      redirect(base_url('empresas'));
    }

    $result = $this->api_key_model->revoke($idKey, $idCompany, (int) $this->session->userdata('user')->id);
    $this->session->set_flashdata(empty($result['success']) ? 'error' : 'success', $result['message'] ?? 'Chave de API revogada com sucesso.');

    if (!empty($result['success'])) {
      $this->global_model->add('crm_companies_logs', [
        'id_company' => $idCompany,
        'description' => 'Chave de API revogada: ' . $result['api_key']->key_prefix . ' (' . htmlspecialchars($result['api_key']->name, ENT_QUOTES, 'UTF-8') . ').',
        'created' => date('Y-m-d H:i:s'),
        'created_by' => (int) $this->session->userdata('user')->id,
      ]);
    }

    redirect(base_url('empresas/api_chaves?id=' . $idCompany));
  }

  private function renderApiKeys($idCompany)
  {
    $company = $this->getCompanyForApiKeys($idCompany);
    if (empty($company)) {
      $this->session->set_flashdata('warning', 'Empresa não encontrada.');
      redirect(base_url('empresas'));
    }

    $this->data['company'] = $company;
    $this->data['api_keys'] = $this->api_key_model->getByCompany($idCompany);
    $this->data['api_key_plaintext'] = $this->session->flashdata('api_key_plaintext');
    $this->data['api_key_scopes'] = self::API_SCOPES;

    $this->load->view('header', $this->data);
    $this->load->view('companies/api_keys', $this->data);
    $this->load->view('footer', $this->data);
  }

  private function getCompanyForApiKeys($idCompany)
  {
    if ($idCompany <= 0) {
      return NULL;
    }

    return $this->global_model->getWhere_off('crm_companies_v', ['id' => $idCompany], TRUE);
  }

  private function normalizeApiKeyScopes($scopes)
  {
    // Lista branca do POST, tirada do MESMO catálogo que a tela exibe — ver o
    // docblock de API_SCOPES. Chave sem nenhum escopo válido não é criada:
    // ela não autorizaria nada e só ocuparia espaço na listagem.
    $allowedScopes = array_keys(self::API_SCOPES);
    if (!is_array($scopes)) {
      return FALSE;
    }

    $scopes = array_values(array_unique(array_filter($scopes, function ($scope) use ($allowedScopes) {
      return is_string($scope) && in_array($scope, $allowedScopes, TRUE);
    })));

    return empty($scopes) ? FALSE : $scopes;
  }

  protected function setDefaultCompanyFilter()
  {
    if (empty($this->session->userdata('f_companies'))) {
      $this->session->set_userdata('f_companies', [
        'id_status' => "",
        'keyword' => "",
        'keyword_search' => ['name', 'byname', 'cnpj'],
      ]);
    }
  }

  public function index()
  {
    $this->listagem();
  }

  public function listagem()
  {
    $config['base_url'] = base_url('empresas/listagem');
    $config['per_page'] = 30;
    $config['total_rows'] = $this->global_model->getCount("crm_companies_v", "f_companies");
    $config['next_link'] = 'Próxima';
    $config['prev_link'] = 'Anterior';
    $config['full_tag_open'] = '<nav><ul class="pagination justify-content-center">';
    $config['full_tag_close'] = '</ul></nav>';
    $config['num_tag_open'] = '<li class="page-item">';
    $config['num_tag_close'] = '</li>';
    $config['cur_tag_open'] = '<li class="page-item active"><span class="page-link">';
    $config['cur_tag_close'] = '</span></li>';
    $config['prev_tag_open'] = '<li class="page-item">';
    $config['prev_tag_close'] = '</li>';
    $config['next_tag_open'] = '<li class="page-item">';
    $config['next_tag_close'] = '</li>';
    $config['first_link'] = 'Primeira';
    $config['last_link'] = 'Última';
    $config['first_tag_open'] = '<li class="page-item">';
    $config['first_tag_close'] = '</li>';
    $config['last_tag_open'] = '<li class="page-item">';
    $config['last_tag_close'] = '</li>';
    $config['attributes'] = array('class' => 'page-link');
    $this->pagination->initialize($config);
    $this->data['results'] = $this->global_model->getList("crm_companies_v", "f_companies", "id", "desc", $config['per_page'], $this->uri->segment(3));

    $this->data['est_count'] = $config['total_rows'];
    $this->data['total_results'] = $config['total_rows'];

    $this->data['crm_status'] = $this->global_model->getWhereOrderBy_off('crm_status', 'id in (1,2,3,4)', 'name', 'asc', FALSE);

    $this->load->view('header', $this->data);
    $this->load->view('companies/list', $this->data);
    $this->load->view('footer', $this->data);
  }

  public function post_filtrar()
  {
    if (empty($this->input->post('f_companies'))) redirect(base_url('empresas'));
    $array = array_merge($this->session->userdata('f_companies'), $this->input->post('f_companies'));
    $this->session->set_userdata('f_companies', $array);
    redirect(base_url('empresas'));
  }

  public function info()
  {
    $id = 0;
    if (!empty($_GET['id'])) $id = $_GET['id'];

    $this->data['result'] = $this->global_model->getWhere_off('crm_companies_v', ['id' => $id], TRUE);
    if (empty($this->data['result'])) {
      $this->session->set_flashdata('warning', 'Registro não encontrado.');
      redirect(base_url('empresas'));
    }

    $this->setDefaultCompanyFilter();

    $this->data['files'] = $this->global_model->getWhereOrderBy_off('crm_companies_files', ['id_company' => $id], 'id', 'desc', FALSE);
    $this->data['notes'] = $this->global_model->getWhereOrderBy_off('crm_companies_notes_v', ["id_company" => $id], "id", "DESC", FALSE);
    $this->data['logs'] = $this->global_model->getWhereOrderBy_off('crm_companies_logs_v', ["id_company" => $id], "id", "DESC", FALSE);
    $this->data['users'] = $this->global_model->getWhereOrderBy_off('crm_users_v', ["id_company" => $id], "id", "DESC", FALSE);
    $this->data['permissions'] = $this->global_model->getWhereOrderBy_off('crm_user_permissions', ["id_company" => $id, 'id_status' => 1], "name", "asc", FALSE);

    // TRAZER APENAS GRUPOS DE ACESSO VINCULADOS A ESTA EMPRESA
    $allGroups = $this->global_model->getWhereOrderBy_off('crm_user_groups_v', "id_status = 1", 'name', 'asc', FALSE);
    $groups = [];
    foreach ($allGroups as $g) {
      $companies = json_decode($g->companies);
      if (!empty($companies)) {
        if (in_array($id, $companies)) $groups[] = $g;
      } else {
        if ($this->session->userdata('user')->id_company == 1) $groups[] = $g;
      }
    }
    $this->data['crm_user_groups'] = $groups;

    // Aba Bom Controle: active/base_url vêm no $result (crm_companies_v); o
    // secret não existe na view de propósito — daqui só sai o "tem chave?".
    $this->load->model('bomcontrole_model');
    $this->load->library('secret_crypto');
    $this->data['bomcontrole_key_set'] = $this->bomcontrole_model->hasKey((int) $id);
    $this->data['bomcontrole_company_id'] = (int) $this->data['result']->bomcontrole_company_id;
    $this->data['crypto_ready'] = $this->secret_crypto->isReady();

    // Aba PSP: uma seção por provedor da allowlist, para o PSP novo
    // aparecer sozinho. As credenciais NÃO vêm para a tela — daqui sai só
    // o "tem segredo?" e a validade do certificado.
    $this->load->model('psp_model');
    $this->data['psp_providers'] = $this->psp_model->providers();
    $this->data['psp_accounts'] = $this->psp_model->contasDaEmpresa((int) $id);


    $this->load->view('header', $this->data);
    $this->load->view('companies/info', $this->data);
    $this->load->view('footer', $this->data);
  }

  public function post_bomcontrole()
  {
    $idCompany = (int) $this->input->post('id');

    $input = $this->input->post('bomcontrole');
    if (!is_array($input)) {
      $input = [];
    }

    $baseUrl = trim((string) ($input['bomcontrole_base_url'] ?? ''));
    if ($baseUrl !== '') {
      $baseUrl = rtrim($baseUrl, '/');
      if (mb_strlen($baseUrl) > 255 || !filter_var($baseUrl, FILTER_VALIDATE_URL) || stripos($baseUrl, 'https://') !== 0) {
        $this->session->set_flashdata('error', 'A base URL do Bom Controle deve ser uma URL https:// válida — ou ficar em branco para usar a padrão.');
        redirect(base_url('empresas/info?id=' . $idCompany . '#tab_bomcontrole'));
      }
    }

    // A chave vem FORA do array `bomcontrole[...]` para poder ser lida sem
    // xss_clean (segundo parâmetro FALSE) — o filtro reescreveria em silêncio
    // uma chave com sequência suspeita, e a corrupção só apareceria na
    // primeira chamada à API. Mesmo motivo do post('secret', FALSE) em
    // Servidores.php e do post('ninjas_api_key', FALSE) em Parâmetros Gerais.
    $chave = trim((string) $this->input->post('bomcontrole_api_key', FALSE));
    if ($chave !== '' && mb_strlen($chave) > 255) {
      $this->session->set_flashdata('error', 'A chave da API do Bom Controle deve ter no máximo 255 caracteres.');
      redirect(base_url('empresas/info?id=' . $idCompany . '#tab_bomcontrole'));
    }

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->salvarConfig(
      $idCompany,
      !empty($input['bomcontrole_active']),
      $baseUrl,
      $chave !== '' ? $chave : NULL, // em branco = manter a chave atual
      (int) $this->session->userdata('user')->id
    );

    if (!$resultado['success']) {
      $this->session->set_flashdata('error', $resultado['message']);
      redirect(base_url('empresas/info?id=' . $idCompany . '#tab_bomcontrole'));
    }

    // A mensagem declara o ESTADO RESULTANTE — "salvo com sucesso" genérico,
    // com o campo de chave voltando em branco, não distingue "gravou" de "não
    // gravou nada" (a mesma lição da aba Ninjas de Parâmetros Gerais).
    $this->session->set_flashdata('success', sprintf(
      'Integração Bom Controle %s para esta empresa. %s',
      !empty($input['bomcontrole_active']) ? 'ATIVADA' : 'DESATIVADA',
      $chave !== ''
        ? 'Chave gravada — o campo volta em branco porque a chave nunca é devolvida para a tela.'
        : 'A chave cadastrada foi mantida.'
    ));
    redirect(base_url('empresas/info?id=' . $idCompany . '#tab_bomcontrole'));
  }

  public function json_postrevelarbomcontrole()
  {
    header('Content-Type: application/json; charset=utf-8');
    // Resposta com segredo não pode ficar em cache de navegador ou proxy.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $idCompany = (int) $this->input->post('id');
    if ($idCompany <= 0) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Empresa inválida.',
        'data' => NULL,
        'errors' => ['bomcontrole' => 'Empresa inválida.'],
      ]);
      return;
    }

    $this->load->model('bomcontrole_model');
    $chave = $this->bomcontrole_model->getApiKey($idCompany);

    if ($chave === FALSE) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Não foi possível decifrar a chave. A chave de criptografia (secret_crypto_key) pode ter sido trocada — recadastre a chave da API.',
        'data' => NULL,
        'errors' => ['bomcontrole' => 'Chave ilegível.'],
      ]);
      return;
    }

    if ($chave === '') {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Nenhuma chave cadastrada.',
        'data' => NULL,
        'errors' => ['bomcontrole' => 'Nenhuma chave cadastrada.'],
      ]);
      return;
    }

    $usuario = $this->session->userdata('user');
    log_message('error', sprintf(
      '[CREDENCIAL] Usuário %d (%s) visualizou a chave do Bom Controle da empresa %d a partir do IP %s.',
      (int) $usuario->id,
      isset($usuario->name) ? $usuario->name : '?',
      $idCompany,
      $this->input->ip_address()
    ));

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => 'Chave exibida.',
      'data' => ['bomcontrole_api_key' => $chave],
      'errors' => [],
    ]);
  }

  public function json_posttestarbomcontrole()
  {
    header('Content-Type: application/json; charset=utf-8');

    $idCompany = (int) $this->input->post('id');
    if ($idCompany <= 0) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Empresa inválida.',
        'data' => NULL,
        'errors' => ['bomcontrole' => 'Empresa inválida.'],
      ]);
      return;
    }

    // Testa a chave JÁ SALVA da empresa — nunca uma digitada, que pode estar
    // em branco justamente porque o usuário quer manter a atual.
    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->testConnection($idCompany);

    echo json_encode([
      'success' => !empty($resultado['success']),
      'return' => !empty($resultado['success']),
      'message' => $resultado['message'],
      'data' => NULL,
      'errors' => !empty($resultado['success']) ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Salva a credencial de um PSP da empresa.
   *
   * Um form por PSP: o slug vem num hidden e é validado contra a allowlist do
   * Psp_model. Slug fora dela é ERRO, e não "usa o primeiro" — a credencial
   * iria parar no provedor errado.
   */
  public function post_psp()
  {
    $idCompany = (int) $this->input->post('id');
    $psp = trim((string) $this->input->post('psp'));

    $this->load->model('psp_model');
    $providers = $this->psp_model->providers();

    if ($psp === '' || !isset($providers[$psp])) {
      $this->session->set_flashdata('error', 'Provedor de cobrança desconhecido.');
      redirect(base_url('empresas/info?id=' . $idCompany . '#tab_psp'));
    }

    $destino = base_url('empresas/info?id=' . $idCompany . '#tab_psp');

    $input = $this->input->post('psp_config');
    if (!is_array($input)) {
      $input = [];
    }

    // O secret vem FORA do array para ser lido sem xss_clean (segundo
    // parâmetro FALSE): o filtro reescreveria em silêncio um segredo com
    // sequência suspeita, e a corrupção só apareceria na primeira chamada à
    // API. Mesmo motivo do bomcontrole_api_key acima.
    $secret = trim((string) $this->input->post('psp_client_secret', FALSE));
    if ($secret !== '' && mb_strlen($secret) > 255) {
      $this->session->set_flashdata('error', 'O Client Secret deve ter no máximo 255 caracteres.');
      redirect($destino);
    }

    $resultado = $this->psp_model->salvarConfig(
      $idCompany,
      $psp,
      [
        'active' => !empty($input['active']),
        'environment' => (string) ($input['environment'] ?? 'sandbox'),
        'client_id' => (string) ($input['client_id'] ?? ''),
        'conta_corrente' => (string) ($input['conta_corrente'] ?? ''),
        'client_secret' => $secret !== '' ? $secret : NULL, // em branco = manter
      ],
      (int) $this->session->userdata('user')->id
    );

    if (!$resultado['success']) {
      $this->session->set_flashdata('error', $resultado['message']);
      redirect($destino);
    }

    $avisoCert = $this->salvarCertificadoDoPost($idCompany, $psp);
    if ($avisoCert === FALSE) {
      redirect($destino);
    }

    // A mensagem declara o ESTADO RESULTANTE: "salvo com sucesso" genérico,
    // com o campo de secret voltando em branco, não distingue "gravou" de
    // "não gravou nada".
    $this->session->set_flashdata('success', sprintf(
      'Integração %s %s para esta empresa. %s%s',
      $providers[$psp]['nome'],
      !empty($input['active']) ? 'ATIVADA' : 'DESATIVADA',
      $secret !== ''
        ? 'Client Secret gravado.'
        : 'O Client Secret cadastrado foi mantido.',
      $avisoCert
    ));

    redirect($destino);
  }

  /**
   * Lê o par .crt/.key do POST, quando enviado.
   *
   * Os dois andam juntos de propósito: gravar só o certificado deixaria a
   * conta com um par que não casa, e o erro apareceria como falha de TLS
   * obscura na primeira emissão.
   *
   * @param  int    $idCompany
   * @param  string $psp
   * @return string|bool texto a somar ao flash; FALSE quando reprovou
   */
  private function salvarCertificadoDoPost($idCompany, $psp)
  {
    $temCert = !empty($_FILES['psp_cert']['tmp_name']) && is_uploaded_file($_FILES['psp_cert']['tmp_name']);
    $temChave = !empty($_FILES['psp_key']['tmp_name']) && is_uploaded_file($_FILES['psp_key']['tmp_name']);

    if (!$temCert && !$temChave) {
      return ''; // nada enviado: mantém o par atual
    }

    if (!$temCert || !$temChave) {
      $this->session->set_flashdata('error', 'Envie o certificado (.crt) e a chave (.key) juntos — um sem o outro deixaria o par inconsistente.');
      return FALSE;
    }

    $pemCert = (string) @file_get_contents($_FILES['psp_cert']['tmp_name']);
    $pemChave = (string) @file_get_contents($_FILES['psp_key']['tmp_name']);

    $resultado = $this->psp_model->salvarCertificado(
      $idCompany,
      $psp,
      $pemCert,
      $pemChave,
      (int) $this->session->userdata('user')->id
    );

    if (!$resultado['success']) {
      $this->session->set_flashdata('error', $resultado['message']);
      return FALSE;
    }

    return !empty($resultado['expira_em'])
      ? ' Certificado gravado (válido até ' . date('d/m/Y', strtotime($resultado['expira_em'])) . ').'
      : ' Certificado gravado.';
  }

  public function json_postrevelarpsp()
  {
    header('Content-Type: application/json; charset=utf-8');
    // Resposta com segredo não pode ficar em cache de navegador ou proxy.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $idCompany = (int) $this->input->post('id');
    $psp = trim((string) $this->input->post('psp'));

    if ($idCompany <= 0) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Empresa inválida.',
        'data' => NULL,
        'errors' => ['psp' => 'Empresa inválida.'],
      ]);
      return;
    }

    $this->load->model('psp_model');
    $providers = $this->psp_model->providers();

    if ($psp === '' || !isset($providers[$psp])) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Provedor de cobrança desconhecido.',
        'data' => NULL,
        'errors' => ['psp' => 'Provedor desconhecido.'],
      ]);
      return;
    }

    $secret = $this->psp_model->getClientSecret($idCompany, $psp);

    if ($secret === FALSE) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Não foi possível decifrar o Client Secret. A chave de criptografia (secret_crypto_key) pode ter sido trocada — recadastre o segredo.',
        'data' => NULL,
        'errors' => ['psp' => 'Segredo ilegível.'],
      ]);
      return;
    }

    if ($secret === '') {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Nenhum Client Secret cadastrado.',
        'data' => NULL,
        'errors' => ['psp' => 'Nenhum segredo cadastrado.'],
      ]);
      return;
    }

    $usuario = $this->session->userdata('user');
    log_message('error', sprintf(
      '[CREDENCIAL] Usuário %d (%s) visualizou o Client Secret do PSP %s da empresa %d a partir do IP %s.',
      (int) $usuario->id,
      isset($usuario->name) ? $usuario->name : '?',
      $psp,
      $idCompany,
      $this->input->ip_address()
    ));

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => 'Client Secret exibido.',
      'data' => ['psp_client_secret' => $secret],
      'errors' => [],
    ]);
  }

  public function json_posttestarpsp()
  {
    header('Content-Type: application/json; charset=utf-8');

    $idCompany = (int) $this->input->post('id');
    $psp = trim((string) $this->input->post('psp'));

    if ($idCompany <= 0) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Empresa inválida.',
        'data' => NULL,
        'errors' => ['psp' => 'Empresa inválida.'],
      ]);
      return;
    }

    // Testa a credencial JÁ SALVA — nunca uma digitada, que pode estar em
    // branco justamente porque o usuário quer manter a atual.
    $this->load->model('psp_model');
    $resultado = $this->psp_model->testConnection($idCompany, $psp);

    echo json_encode([
      'success' => !empty($resultado['success']),
      'return' => !empty($resultado['success']),
      'message' => $resultado['message'],
      'data' => NULL,
      'errors' => !empty($resultado['success']) ? [] : ['psp' => $resultado['message']],
    ]);
  }

  public function json_postregistrarwebhook()
  {
    header('Content-Type: application/json; charset=utf-8');

    $idCompany = (int) $this->input->post('id');
    $psp = trim((string) $this->input->post('psp'));

    $this->load->model('psp_model');
    $resultado = $this->psp_model->registrarWebhook($idCompany, $psp);

    echo json_encode([
      'success' => !empty($resultado['success']),
      'return' => !empty($resultado['success']),
      'message' => (string) $resultado['message'],
      'data' => isset($resultado['data']) ? $resultado['data'] : NULL,
      'errors' => !empty($resultado['success']) ? [] : ['psp' => (string) $resultado['message']],
    ]);
  }
  /**
   * Resolve o IdEmpresa do tenant no Bom Controle.
   *
   * Leitura pura (`Empresa/Pesquisar`) — não cria nem altera nada no ERP.
   */
  public function json_postresolveridempresa()
  {
    header('Content-Type: application/json; charset=utf-8');

    $idCompany = (int) $this->input->post('id');

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->resolverIdEmpresa(
      $idCompany,
      (int) $this->session->userdata('user')->id
    );

    echo json_encode([
      'success' => !empty($resultado['success']),
      'return' => !empty($resultado['success']),
      'message' => (string) $resultado['message'],
      'data' => isset($resultado['data']) ? $resultado['data'] : NULL,
      'errors' => !empty($resultado['success']) ? [] : ['bomcontrole' => (string) $resultado['message']],
    ]);
  }
  public function post_ativarcadastro()
  {
    $this->data['result'] = $this->global_model->getWhere_off('crm_companies', ["id" => $this->input->post('id')], TRUE);
    if (($this->data['result']->id_status == 4) || ($this->data['result']->id_status == 3)) {
      // OK
    } else {
      $this->session->set_flashdata('error', 'Status deverá estar como SUSPENSO ou NÃO ATIVADO.');
      redirect(base_url('empresas/info?id=') . $this->input->post('id'));
    }

    // Validar se o CNPJ está corretamente preenchido
    $valida = valida_cnpj($this->data['result']->cnpj);
    if (!$valida) {
      $this->session->set_flashdata('error', 'O cadastro não tem um CNPJ válido, favor corrigir o cadastro antes da ativação do mesmo.');
      redirect(base_url('empresas/info?id=') . $this->input->post('id'));
    }

    $message = 'Cadastro reativado';
    if ($this->data['result']->id_status == 4) {
      $message = 'Cadastro ativado';
    }

    // Inserir um log
    $data = [
      'id_company' => $this->input->post('id'),
      'description' => $message,
      'created' => date("Y-m-d H:i:s"),
      'created_by' => $this->session->userdata('user')->id,
    ];
    $this->global_model->add('crm_companies_logs', $data);

    // Atualizar cabeçalho
    $data = array(
      'id_status' => 1,
      'modified' => date("Y-m-d H:i:s"),
      'modified_by' => $this->session->userdata('user')->id
    );
    $this->global_model->edit('crm_companies', $data, 'id', $this->input->post('id'));
    $this->session->set_flashdata('success', 'Cadastro ativado com sucesso.');
    redirect(base_url('empresas/info?id=') . $this->input->post('id'));
  }

  public function json_postchangestatus()
  {
    header('Content-Type: application/x-json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $ativar = $this->input->post('ativar') === '1';

    if ($id <= 0) {
      echo json_encode(['return' => FALSE, 'message' => 'ID inválido.']);
      return;
    }

    $empresa = $this->global_model->getWhere_off('crm_companies', ['id' => $id], FALSE);
    if (empty($empresa)) {
      echo json_encode(['return' => FALSE, 'message' => 'Empresa não encontrada.']);
      return;
    }

    $novoStatus = $ativar ? 1 : 2;
    $userId = $this->session->userdata('user')->id;
    $userNome = $this->session->userdata('user')->name ?? ('ID ' . $userId);
    $acaoTxt = $ativar ? 'ativou' : 'inativou';

    $this->db->trans_begin();

    $this->global_model->edit('crm_companies', [
      'id_status' => $novoStatus,
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => $userId,
    ], 'id', $id);

    $this->global_model->add('crm_companies_logs', [
      'id_company' => $id,
      'description' => 'Usuário ' . $userNome . ' ' . $acaoTxt . ' o cadastro da empresa.',
      'created' => date('Y-m-d H:i:s'),
      'created_by' => $userId,
    ]);

    if ($this->db->trans_status() === FALSE) {
      $this->db->trans_rollback();
      echo json_encode(['return' => FALSE, 'message' => 'Falha ao atualizar status.']);
      return;
    }
    $this->db->trans_commit();

    $crmStatus = $this->global_model->getWhere_off('crm_status', ['id' => $novoStatus], TRUE);

    echo json_encode([
      'return' => TRUE,
      'message' => $ativar ? 'Empresa ativada.' : 'Empresa inativada.',
      'status' => $novoStatus,
      'data' => [
        'id_status' => $novoStatus,
        'status_name' => (!empty($crmStatus) && !empty($crmStatus->name)) ? $crmStatus->name : ($ativar ? 'Ativo' : 'Inativo'),
        'status_color' => (!empty($crmStatus) && !empty($crmStatus->color)) ? $crmStatus->color : ($ativar ? 'success' : 'secondary')
      ],
    ]);
  }

  public function post_novaanotacao()
  {
    $data = array(
      'id_company' => $this->input->post('id_company'),
      'description' => $this->input->post('description'),
      'created' => date("Y-m-d H:i:s"),
      'created_by' => $this->session->userdata('user')->id,
    );

    // Adiciona na tabela
    $this->global_model->add('crm_companies_notes', $data);
    $this->session->set_flashdata('success', 'Atividade inserida.');
    redirect($this->input->post('url'));
  }

  public function post_sendfiles()
  {
    $file = $this->uploadFileLocal('file', 'companies', ["jpg", "jpeg", "png", 'pdf', 'xls', 'xlsx', 'doc', 'docx', "JPG", "JPEG", "PNG", "PDF", "XLS", "XLSX", "DOC", "DOCX"]);
    if (!empty($file)) {
      $array = [
        'id_company' => $this->input->post('id'),
        'name' => $this->input->post('name'),
        'file' => $file,
        'created' => date("Y-m-d H:i:s"),
        'created_by' => $this->session->userdata('user')->id,
      ];
      $this->global_model->add('crm_companies_files', $array);
      $this->session->set_flashdata('success', 'Upload realizado com sucesso.');
    } else {
      $this->session->set_flashdata('error', 'Nenhum anexo enviado.');
    }
    redirect(base_url('empresas/info?id=' . $this->input->post('id')));
  }

  public function json_postdeletefile()
  {
    header('Content-Type: application/x-json; charset=utf-8');
    $file = $this->input->post('file');
    $id_company = $this->input->post('id_company');

    $files_db = $this->global_model->getWhere_off('crm_companies_files', array("id_company" => $id_company, 'file' => $file), TRUE);
    if (!empty($files_db)) {
      // Remover fisicamente
      unlink($file);
      $this->db->delete('crm_companies_files', ['id' => $files_db->id]);
    }

    echo json_encode(array('return' => 'OK'));
  }

  public function editar()
  {
    $this->form_validation->set_rules('company[name]', '', 'required');
    if ($this->form_validation->run() == FALSE) {
      $id = 0;
      if (!empty($_GET['id'])) $id = $_GET['id'];

      $this->data['result'] = $this->global_model->getWhere_off('crm_companies_v', ['id' => $id], TRUE);
      if (empty($this->data['result'])) {
        $this->session->set_flashdata('warning', 'Registro não encontrado.');
        redirect(base_url('empresas'));
      }

      $this->data['states'] = $this->global_model->getWhereOrderBy_off('crm_country_states', "1=1", 'name', 'asc', FALSE);
      $this->data['cities'] = $this->global_model->getWhereOrderBy_off('crm_country_cities', ['id_state' => $this->data['result']->id_state], 'name', 'asc', FALSE);
      $this->data['status'] = $this->global_model->getWhereOrderBy_off('crm_status', "id in (1,2,3,4)", 'name', 'asc', FALSE);
      $this->data['companies'] = $this->global_model->getFieldsWhere_off('crm_companies', 'id, name, byname, cnpj', '1=1', 'byname', 'asc', FALSE);
      $relationships = $this->global_model->getFieldsWhere_off('crm_companies_relationship', 'id_related_company', ['id_company' => (int) $id], 'id_related_company', 'asc', FALSE);
      $this->data['related_company_ids'] = array_map(function ($relationship) {
        return (int) $relationship->id_related_company;
      }, $relationships);

      if ($this->input->method() === 'post' && is_array($this->input->post('companies'))) {
        $this->data['related_company_ids'] = array_values(array_unique(array_map('intval', $this->input->post('companies'))));
      }

      $this->load->view('header', $this->data);
      $this->load->view('companies/edit', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $data = $this->input->post('company');
      $idCompany = (int) $this->input->post('id');
      $relatedCompanyIds = $this->normalizeCompanyRelationshipIds($this->input->post('companies'), $idCompany);
      if ($relatedCompanyIds === FALSE) {
        $this->session->set_flashdata('warning', 'As empresas selecionadas para o grupo são inválidas.');
        redirect(base_url('empresas/editar?id=' . $idCompany));
      }

      $data['name'] = mb_strtoupper(trim($this->input->post('company')['name']));
      $data['byname'] = mb_strtoupper(trim($this->input->post('company')['byname']));
      $data['cnpj'] = sonumero($data['cnpj']);
      $data['modified'] = date("Y-m-d H:i:s");
      $data['modified_by'] = $this->session->userdata('user')->id;
      $data['alias'] = substr(url_title(strtolower(convert_accented_characters($this->input->post('company')['name'], '-', TRUE))), 0, 115);
      $this->db->trans_begin();

      $this->global_model->edit('crm_companies', $data, 'id', $idCompany);
      $relationshipsSynced = $this->syncCompanyRelationships($idCompany, $relatedCompanyIds);
      // $this->syncCompanyServiceStates((int) $this->input->post('id'), $this->input->post('service_states'));

      if (!$relationshipsSynced || $this->db->trans_status() === FALSE) {
        $this->db->trans_rollback();
        $this->session->set_flashdata('error', 'Não foi possível atualizar o grupo de empresas.');
        redirect(base_url('empresas/editar?id=' . $idCompany));
      }

      $this->db->trans_commit();

      $this->session->set_flashdata('success', 'Registro atualizado com sucesso.');
      redirect(base_url('empresas/info?id=' . $idCompany));
    }
  }

  private function normalizeCompanyRelationshipIds($postedIds, $idCompany)
  {
    if ($postedIds === NULL || $postedIds === '') {
      return [];
    }

    if (!is_array($postedIds)) {
      return FALSE;
    }

    $ids = [];
    foreach ($postedIds as $postedId) {
      if (!is_scalar($postedId) || !preg_match('/^\d+$/', (string) $postedId)) {
        return FALSE;
      }

      $relatedCompanyId = (int) $postedId;
      if ($relatedCompanyId <= 0) {
        return FALSE;
      }

      $ids[] = $relatedCompanyId;
    }

    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
      return [];
    }

    $existingCompanies = $this->db
      ->select('id')
      ->where_in('id', $ids)
      ->get('crm_companies')
      ->result();

    if (count($existingCompanies) !== count($ids)) {
      return FALSE;
    }

    return $ids;
  }

  private function syncCompanyRelationships($idCompany, $relatedCompanyIds)
  {
    $this->db->where('id_company', (int) $idCompany);
    $this->db->delete('crm_companies_relationship');

    if ($this->db->trans_status() === FALSE) {
      return FALSE;
    }

    foreach ($relatedCompanyIds as $relatedCompanyId) {
      if (!$this->global_model->add('crm_companies_relationship', [
        'id_company' => (int) $idCompany,
        'id_related_company' => (int) $relatedCompanyId,
      ])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  public function grupos()
  {
    $this->data['menu'] = 'empresas/grupos';
    $this->form_validation->set_rules('name', '', 'required');
    if ($this->form_validation->run() == FALSE) {
      $this->data['crm_user_groups'] = $this->global_model->getWhereOrderBy_off('crm_user_groups_v', "1=1", 'name', 'asc', FALSE);
      $this->data['crm_companies_v'] = $this->global_model->getWhereOrderBy_off('crm_companies_v', "1=1", 'byname', 'asc', FALSE);
      $this->data['crm_users_v'] = $this->global_model->getWhereOrderBy_off('crm_users_v', "id_status = 1", 'name', 'asc', FALSE);
      $this->load->view('header', $this->data);
      $this->load->view('groups/list', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $array = [
        'companies' => json_encode($this->input->post('companies'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'name' => mb_strtoupper($this->input->post('name')),
        'id_status' => 1,
        'created' => date("Y-m-d H:i:s"),
        'created_by' => $this->session->userdata('user')->id,
        'modified' => date("Y-m-d H:i:s"),
        'modified_by' => $this->session->userdata('user')->id,
      ];
      $this->global_model->add('crm_user_groups', $array);
      $this->session->set_flashdata('success', 'Processamento realizado com sucesso.');
      redirect(base_url('empresas/grupos'));
    }
  }

  public function grupos_editar()
  {
    $this->data['menu'] = 'empresas/grupos';
    $this->form_validation->set_rules('name', '', 'required');
    if ($this->form_validation->run() == FALSE) {
      $id = 0;
      if (!empty($_GET['id'])) $id = $_GET['id'];

      $this->data['result'] = $this->global_model->getWhere('crm_user_groups_v', ['id' => $id], TRUE);
      if (empty($this->data['result'])) {
        $this->session->set_flashdata('warning', 'Registro não encontrado.');
        redirect(base_url('empresas/grupos'));
      }

      $this->data['crm_companies_v'] = $this->global_model->getWhereOrderBy_off('crm_companies_v', "1=1", 'byname', 'asc', FALSE);
      $this->data['crm_users_v'] = $this->global_model->getWhereOrderBy_off('crm_users_v', "id_status = 1 and id_group = " . $this->data['result']->id, 'name', 'asc', FALSE);

      $this->load->view('header', $this->data);
      $this->load->view('groups/edit', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $array = [
        'companies' => json_encode($this->input->post('companies'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'name' => mb_strtoupper($this->input->post('name')),
        'modified' => date("Y-m-d H:i:s"),
        'modified_by' => $this->session->userdata('user')->id,
      ];
      $this->global_model->edit('crm_user_groups', $array, 'id', $this->input->post('id'));
      $this->session->set_flashdata('success', 'Processamento realizado com sucesso.');
      redirect(base_url('empresas/grupos'));
    }
  }

}
