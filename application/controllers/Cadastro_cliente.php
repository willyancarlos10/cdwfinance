<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cadastro público de clientes finais (crm_customers), em wizard, acessado
 * pelo link da tela de login. Não cria usuário nem senha: o cliente final não
 * acessa o sistema — só o registro é criado, e a equipe revisa os dados em
 * clientes/info.
 *
 * O cadastro não tem status (migration 015): não existe "em análise" nem
 * ativação. O que diz se o cliente está ativo é ter contrato vigente, e isso
 * vive em crm_contracts.
 *
 * Não estende MY_Controller de propósito: a página é pública e estender o
 * MY_Controller carregaria a sessão de painel e redirecionaria para o login
 * (mesmo padrão de Login e Api_docs).
 *
 * As consultas de CNPJ e CEP reusam os endpoints públicos já existentes do
 * Login (json_getsefaz/json_getcep) via alias de rota em config/routes.php —
 * nada de lógica de consulta duplicada aqui.
 */
class Cadastro_cliente extends CI_Controller
{
  /**
   * Tenant que recebe os cadastros do link genérico (empresa master) — o link
   * da tela de login não carrega token. Cada tenant tem o próprio link:
   * /cadastro-cliente/<crm_companies.token> (botão COPIAR LINK na listagem).
   */
  const ID_COMPANY_PADRAO = 1;

  /** Tenant resolvido para esta requisição (token da rota ou o padrão). */
  private $idCompany = self::ID_COMPANY_PADRAO;

  /** Envio mais rápido que isto (segundos) é tratado como robô. */
  const TEMPO_MINIMO_ENVIO = 10;

  const MSG_SUCESSO = 'Cadastro recebido com sucesso! Nossa equipe vai revisar os dados e entrar em contato para dar sequência ao seu contrato.';

  public function __construct()
  {
    parent::__construct();

    // A sessão saiu do autoload (ver application/config/autoload.php). Aqui
    // ela é necessária para o CSRF das telas públicas, flashdata e o carimbo
    // de tempo anti-spam.
    $this->load->library('session');
  }

  public function index($token = NULL)
  {
    $this->resolverTenant($token);

    if (strtolower((string) $this->input->method()) !== 'post') {
      $this->session->set_userdata('customer_wizard_started', time());
      $this->renderWizard();
      return;
    }

    // Anti-spam. Honeypot: campo invisível para humanos — preenchido = robô.
    // Envio rápido demais idem. Nos dois casos a resposta é a mesma mensagem
    // de sucesso, sem gravar nada, para não dar sinal ao robô.
    $honeypot = trim((string) $this->input->post('website_confirm'));
    $inicio = (int) $this->session->userdata('customer_wizard_started');
    $rapidoDemais = ($inicio > 0 && (time() - $inicio) < self::TEMPO_MINIMO_ENVIO);
    if ($honeypot !== '' || $rapidoDemais) {
      redirect(base_url('login?success=' . self::MSG_SUCESSO));
    }

    $this->setFormRules();

    if ($this->form_validation->run() == FALSE) {
      $this->data['passo_com_erro'] = $this->primeiroPassoComErro();
      $this->renderWizard();
      return;
    }

    $this->gravar();
  }

  // ------------------------------------------------------------------
  // Endpoints AJAX públicos do wizard
  // ------------------------------------------------------------------

  /**
   * Cidades de um estado, para o select encadeado do wizard. Espelho público
   * do MY_Controller::json_get_cities_by_id (que exige login).
   */
  public function json_get_cities_by_id($id = NULL)
  {
    header('Content-Type: application/x-json; charset=utf-8');
    echo json_encode($this->global_model->getWhereOrderBy_off('crm_country_cities_v', ['id_state' => (int) $id], 'name', 'asc', FALSE));
  }

  /**
   * CEP com resolução de id_state/id_city para os selects do wizard — espelho
   * público do MY_Controller::json_getcep (a versão do Login::json_getcep não
   * resolve os ids). Consulta primeiro os CEPs cadastrados manualmente
   * (crm_ceps) e só então o ViaCEP (com cache em crm_viacep).
   */
  public function json_getcep($address_zip = NULL)
  {
    header('Content-Type: application/x-json; charset=utf-8');
    $resposta = array();

    if (empty($address_zip)) {
      $resposta['return'] = FALSE;
      $resposta['erro'] = '';
      echo json_encode($resposta);
      return;
    }

    $cep_custom = $this->global_model->getWhere_off('crm_ceps', ['id_status' => 1, 'cep' => $address_zip], TRUE);
    if (!empty($cep_custom)) {
      $resposta['return'] = TRUE;
      $resposta['localidade'] = $cep_custom->address_city;
      $resposta['uf'] = $cep_custom->address_uf;

      $where = ['UF' => $cep_custom->address_uf, 'name' => $cep_custom->address_city];
      $crm_country_cities_v = $this->global_model->getWhere_off('crm_country_cities_v', $where, TRUE);
      $resposta['id_city'] = !empty($crm_country_cities_v) ? $crm_country_cities_v->id : 0;
      $resposta['id_state'] = !empty($crm_country_cities_v) ? $crm_country_cities_v->id_state : 0;
    } else {
      $this->load->library('viacep');
      if ($this->viacep->consultar(str_replace('.', '', $address_zip))) {
        $resposta['return'] = TRUE;
        $resposta['cep'] = $this->viacep->getCEP();
        $resposta['logradouro'] = $this->viacep->getLogradouro();
        $resposta['complemento'] = $this->viacep->getComplemento();
        $resposta['bairro'] = $this->viacep->getBairro();
        $resposta['localidade'] = $this->viacep->getLocalidade();
        $resposta['uf'] = $this->viacep->getUF();

        $where = ['UF' => $this->viacep->getUF(), 'name' => mb_strtoupper(trim(remover_acentos($this->viacep->getLocalidade())))];
        $crm_country_cities_v = $this->global_model->getWhere_off('crm_country_cities_v', $where, TRUE);
        $resposta['id_city'] = !empty($crm_country_cities_v) ? $crm_country_cities_v->id : 0;
        $resposta['id_state'] = !empty($crm_country_cities_v) ? $crm_country_cities_v->id_state : 0;
      } else {
        $resposta['return'] = FALSE;
        $resposta['erro'] = $this->viacep->getUltimoErro();
      }
    }

    echo json_encode($resposta);
  }

  // ------------------------------------------------------------------
  // Callbacks de validação
  // ------------------------------------------------------------------

  public function callb_validador_documento($string)
  {
    $tipo = $this->tipoInformado();
    $documento = sonumero((string) $string);

    if ($tipo === 'F') {
      if (strlen($documento) !== 11 || !valida_cpf($documento)) {
        $this->form_validation->set_message('callb_validador_documento', 'CPF não é válido, verifique.');
        return FALSE;
      }
    } else {
      if (strlen($documento) !== 14 || !valida_cnpj($documento)) {
        $this->form_validation->set_message('callb_validador_documento', 'CNPJ não é válido, verifique.');
        return FALSE;
      }
    }

    $existente = $this->global_model->getWhere_off('crm_customers', [
      'id_company' => $this->idCompany,
      'document' => $documento,
    ], TRUE);
    if (!empty($existente)) {
      $this->form_validation->set_message('callb_validador_documento', 'Documento já cadastrado. Se você já é cliente, fale com nosso atendimento.');
      return FALSE;
    }

    return TRUE;
  }

  public function callb_validador_cidade($string)
  {
    $customer = (array) $this->input->post('customer');
    $idState = isset($customer['id_state']) ? (int) $customer['id_state'] : 0;

    $cidade = $this->global_model->getWhere_off('crm_country_cities', ['id' => (int) $string], TRUE);
    if (empty($cidade) || (int) $cidade->id_state !== $idState) {
      $this->form_validation->set_message('callb_validador_cidade', 'Selecione o estado e a cidade corretamente.');
      return FALSE;
    }
    return TRUE;
  }

  public function callb_validador_cidade_rep($string)
  {
    $attr = (array) $this->input->post('attr');
    $idState = isset($attr['representative']['address']['id_state']) ? (int) $attr['representative']['address']['id_state'] : 0;

    $cidade = $this->global_model->getWhere_off('crm_country_cities', ['id' => (int) $string], TRUE);
    if (empty($cidade) || (int) $cidade->id_state !== $idState) {
      $this->form_validation->set_message('callb_validador_cidade_rep', 'Selecione o estado e a cidade do endereço residencial corretamente.');
      return FALSE;
    }
    return TRUE;
  }

  public function callb_validador_byname($string)
  {
    if ($this->tipoInformado() === 'J' && trim((string) $string) === '') {
      $this->form_validation->set_message('callb_validador_byname', 'O Nome Fantasia deve ser preenchido.');
      return FALSE;
    }
    return TRUE;
  }

  public function callb_validador_lgpd($string)
  {
    if ($string !== 'S') {
      $this->form_validation->set_message('callb_validador_lgpd', 'É necessário aceitar a Política de Privacidade para enviar o cadastro.');
      return FALSE;
    }
    return TRUE;
  }

  // ------------------------------------------------------------------
  // Apoio
  // ------------------------------------------------------------------

  /**
   * Resolve o tenant dono deste cadastro. Sem token = tenant padrão (link
   * genérico da tela de login). Com token, precisa casar com uma empresa
   * ATIVA — token inválido não pode cair em silêncio no tenant errado, então
   * volta para o login com erro.
   *
   * @param string|null $token
   */
  private function resolverTenant($token)
  {
    $this->data['token'] = '';
    $this->data['tenant'] = NULL;

    $token = trim((string) $token);
    if ($token === '') return;

    $company = NULL;
    if (strlen($token) >= 6) {
      $company = $this->global_model->getFieldsWhereSingle_off(
        'crm_companies',
        ['id', 'byname', 'token'],
        ['token' => $token, 'id_status' => 1],
        TRUE
      );
    }

    if (empty($company)) {
      redirect(base_url('login?error=Link de cadastro inválido ou expirado. Confirme o endereço com quem enviou o link.'));
    }

    $this->idCompany = (int) $company->id;
    $this->data['token'] = $token;
    $this->data['tenant'] = $company;
  }

  private function tipoInformado()
  {
    $customer = (array) $this->input->post('customer');
    return (isset($customer['type']) && $customer['type'] === 'F') ? 'F' : 'J';
  }

  private function setFormRules()
  {
    // Passo 1 — Identificação
    $this->form_validation->set_rules('customer[type]', 'Tipo de pessoa', 'required|in_list[J,F]');
    $this->form_validation->set_rules('customer[document]', 'Documento', 'trim|required|callback_callb_validador_documento');
    $this->form_validation->set_rules('customer[name]', $this->tipoInformado() === 'F' ? 'Nome completo' : 'Razão Social', 'trim|required|max_length[150]');
    $this->form_validation->set_rules('customer[byname]', 'Nome Fantasia', 'trim|max_length[150]|callback_callb_validador_byname');
    // Opcional: em branco vira ISENTO na gravação (e PF nem exibe o campo).
    $this->form_validation->set_rules('customer[state_registration]', 'Inscrição Estadual', 'trim|max_length[20]');
    $this->form_validation->set_rules('customer[email]', 'E-mail para envio do contrato', 'trim|required|valid_email|max_length[150]');

    // Passo 2 — Endereço comercial
    $this->form_validation->set_rules('customer[address_zip]', 'CEP', 'trim|required|min_length[9]');
    $this->form_validation->set_rules('customer[address]', 'Endereço', 'trim|required|max_length[200]');
    $this->form_validation->set_rules('customer[address_number]', 'Número', 'trim|required|max_length[20]');
    $this->form_validation->set_rules('customer[address_district]', 'Bairro', 'trim|required|max_length[150]');
    $this->form_validation->set_rules('customer[id_state]', 'Estado', 'required|is_natural_no_zero');
    $this->form_validation->set_rules('customer[id_city]', 'Cidade', 'required|is_natural_no_zero|callback_callb_validador_cidade');

    // Passo 3 — Representante
    $this->form_validation->set_rules('attr[representative][name]', 'Nome do representante', 'trim|required|max_length[150]');
    $this->form_validation->set_rules('attr[representative][nationality]', 'Nacionalidade', 'required|in_list[brasileira,outra]');
    $this->form_validation->set_rules('attr[representative][marital_status]', 'Estado civil', 'required|in_list[solteiro,casado,separado,divorciado,viuvo]');
    $this->form_validation->set_rules('attr[representative][profession]', 'Profissão', 'trim|required|max_length[100]');
    $this->form_validation->set_rules('attr[representative][rg]', 'RG', 'trim|required|max_length[20]');
    $this->form_validation->set_rules('attr[representative][cpf]', 'CPF do representante', 'trim|required|callback_callb_validador_cpf_rep');
    $this->form_validation->set_rules('attr[representative][whatsapp]', 'WhatsApp do representante', 'trim|required|min_length[14]');
    $this->form_validation->set_rules('attr[representative][address][zip]', 'CEP residencial', 'trim|required|min_length[9]');
    $this->form_validation->set_rules('attr[representative][address][street]', 'Rua / Avenida (residencial)', 'trim|required|max_length[200]');
    $this->form_validation->set_rules('attr[representative][address][number]', 'Número (residencial)', 'trim|required|max_length[20]');
    $this->form_validation->set_rules('attr[representative][address][district]', 'Bairro (residencial)', 'trim|required|max_length[150]');
    $this->form_validation->set_rules('attr[representative][address][id_state]', 'Estado (residencial)', 'required|is_natural_no_zero');
    $this->form_validation->set_rules('attr[representative][address][id_city]', 'Cidade (residencial)', 'required|is_natural_no_zero|callback_callb_validador_cidade_rep');

    // Passo 4 — Informações complementares
    $this->form_validation->set_rules('attr[domains][primary]', 'Domínio principal', 'trim|max_length[255]');
    $this->form_validation->set_rules('attr[domains][secondary]', 'Domínio secundário', 'trim|max_length[255]');
    $this->form_validation->set_rules('attr[billing][name]', 'Nome do responsável financeiro', 'trim|required|max_length[150]');
    $this->form_validation->set_rules('attr[billing][email]', 'E-mail do responsável financeiro', 'trim|required|valid_email|max_length[150]');
    $this->form_validation->set_rules('attr[billing][whatsapp]', 'WhatsApp do responsável financeiro', 'trim|required|min_length[14]');
    $this->form_validation->set_rules('attr[billing][needs_invoice]', 'Necessita nota fiscal', 'required|in_list[S,N]');
    $this->form_validation->set_rules('attr[contract][comments]', 'Comentários', 'trim|max_length[1000]');

    // Passo 5 — Aceite
    $this->form_validation->set_rules('lgpd', 'Política de Privacidade', 'callback_callb_validador_lgpd');

    $this->form_validation->set_message('required', 'O campo {field} deve ser preenchido.');
    $this->form_validation->set_message('min_length', 'O campo {field} deve ter pelo menos {param} caracteres.');
    $this->form_validation->set_message('exact_length', 'O campo {field} deve ter exatamente {param} caracteres.');
    $this->form_validation->set_message('valid_email', 'O campo {field} deve conter um e-mail válido.');
    $this->form_validation->set_message('in_list', 'O campo {field} tem um valor inválido.');
  }

  public function callb_validador_cpf_rep($string)
  {
    $cpf = sonumero((string) $string);
    if (strlen($cpf) !== 11 || !valida_cpf($cpf)) {
      $this->form_validation->set_message('callb_validador_cpf_rep', 'O CPF do representante não é válido, verifique.');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Mapeia os campos com erro para o passo do wizard, para o JS reabrir o
   * formulário no primeiro passo com problema.
   *
   * @return int
   */
  private function primeiroPassoComErro()
  {
    $mapa = [
      'customer[type]' => 1,
      'customer[document]' => 1,
      'customer[name]' => 1,
      'customer[byname]' => 1,
      'customer[email]' => 1,
      'customer[address_zip]' => 2,
      'customer[address]' => 2,
      'customer[address_number]' => 2,
      'customer[address_district]' => 2,
      'customer[id_state]' => 2,
      'customer[id_city]' => 2,
      'lgpd' => 5,
    ];

    $passo = 5;
    foreach ($this->form_validation->error_array() as $campo => $mensagem) {
      if (isset($mapa[$campo])) {
        $candidato = $mapa[$campo];
      } elseif (strpos($campo, 'attr[representative]') === 0) {
        $candidato = 3;
      } elseif (strpos($campo, 'attr[') === 0) {
        $candidato = 4;
      } else {
        $candidato = 5;
      }
      if ($candidato < $passo) $passo = $candidato;
    }

    return $passo;
  }

  private function renderWizard()
  {
    $this->data['states'] = $this->global_model->getWhereOrderBy_off('crm_country_states', 'id > 0', 'name', 'asc', FALSE);

    // Cidades dos estados postados, para o set_value repopular os selects
    // encadeados quando a validação devolve o formulário com erro.
    $customerPost = (array) $this->input->post('customer');
    $this->data['cities'] = [];
    if (!empty($customerPost['id_state'])) {
      $this->data['cities'] = $this->global_model->getWhereOrderBy_off('crm_country_cities', ['id_state' => (int) $customerPost['id_state']], 'name', 'asc', FALSE);
    }

    $attrPost = (array) $this->input->post('attr');
    $this->data['rep_cities'] = [];
    if (!empty($attrPost['representative']['address']['id_state'])) {
      $this->data['rep_cities'] = $this->global_model->getWhereOrderBy_off('crm_country_cities', ['id_state' => (int) $attrPost['representative']['address']['id_state']], 'name', 'asc', FALSE);
    }
    $this->data['marital_statuses'] = [
      'solteiro' => 'Solteiro(a)',
      'casado' => 'Casado(a)',
      'separado' => 'Separado(a)',
      'divorciado' => 'Divorciado(a)',
      'viuvo' => 'Viúvo(a)',
    ];
    if (!isset($this->data['passo_com_erro'])) $this->data['passo_com_erro'] = 0;

    $this->load->view('customers/wizard', $this->data);
  }

  private function gravar()
  {
    $customer = (array) $this->input->post('customer');
    $attrPost = (array) $this->input->post('attr');

    $campo = function ($chave) use ($customer) {
      return isset($customer[$chave]) ? trim((string) $customer[$chave]) : '';
    };

    $tipo = $this->tipoInformado();

    // id_state/id_city vêm dos selects encadeados e já passaram pelo
    // callb_validador_cidade (cidade existe e pertence ao estado) — cidade
    // sempre resolvida contra crm_country_cities, requisito da NF-e futura.
    $idState = (int) $campo('id_state');
    $idCity = (int) $campo('id_city');

    $attributes = [
      'representative' => $this->extrairSecao($attrPost, 'representative', ['name', 'nationality', 'marital_status', 'profession', 'rg', 'cpf', 'whatsapp']),
      'billing' => $this->extrairSecao($attrPost, 'billing', ['name', 'email', 'whatsapp', 'needs_invoice']),
      'domains' => $this->extrairSecao($attrPost, 'domains', ['primary', 'secondary']),
      'contract' => $this->extrairSecao($attrPost, 'contract', ['comments']),
    ];

    $attributes['representative']['address'] = $this->extrairSecao(
      isset($attrPost['representative']) ? (array) $attrPost['representative'] : [],
      'address',
      ['street', 'number', 'complement', 'district', 'zip', 'id_state', 'id_city']
    );
    $attributes['representative']['cpf'] = sonumero($attributes['representative']['cpf']);

    // Endereço residencial também usa os selects: guarda os ids e deriva
    // city/uf no servidor (o callb_validador_cidade_rep já garantiu o par).
    $idCityRep = (int) $attributes['representative']['address']['id_city'];
    $cidadeRep = $this->global_model->getWhere_off('crm_country_cities_v', ['id' => $idCityRep], TRUE);
    $attributes['representative']['address']['id_state'] = (int) $attributes['representative']['address']['id_state'];
    $attributes['representative']['address']['id_city'] = $idCityRep;
    $attributes['representative']['address']['city'] = !empty($cidadeRep) ? $cidadeRep->name : '';
    $attributes['representative']['address']['uf'] = !empty($cidadeRep) ? $cidadeRep->UF : '';

    $attributes['consent'] = [
      'lgpd_accepted' => TRUE,
      'accepted_at' => date('Y-m-d H:i:s'),
      'ip' => $this->input->ip_address(),
      'user_agent' => mb_substr((string) $this->input->user_agent(), 0, 255),
    ];
    $attributes['source'] = [
      'channel' => 'wizard_publico',
      'cnpj_autofill' => $this->input->post('cnpj_autofill') === '1',
    ];

    $dados = [
      'id_company' => $this->idCompany,
      'type' => $tipo,
      'document' => sonumero($campo('document')),
      'name' => mb_strtoupper($campo('name')),
      'byname' => mb_strtoupper($campo('byname')),
      // Só PJ tem inscrição estadual; em PF o campo nem aparece na tela e o
      // valor cai no padrão ISENTO.
      'state_registration' => inscricao_estadual($tipo === 'J' ? $campo('state_registration') : ''),
      'email' => mb_strtolower($campo('email')),
      'address' => $campo('address'),
      'address_number' => $campo('address_number'),
      'address_complement' => $campo('address_complement'),
      'address_district' => $campo('address_district'),
      'address_zip' => $campo('address_zip'),
      'id_state' => $idState,
      'id_city' => $idCity,
      'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'created' => date('Y-m-d H:i:s'),
      'created_by' => $this->config->item('id_user_process_auto'),
    ];

    $idCustomer = $this->global_model->add('crm_customers', $dados);
    if (empty($idCustomer)) {
      // A UNIQUE (id_company, document) cobre a corrida entre a validação e o
      // insert: se outra requisição gravou o mesmo documento, cai aqui.
      $this->session->set_flashdata('error', 'Não foi possível concluir o cadastro. Tente novamente ou fale com nosso atendimento.');
      redirect(base_url('cadastro-cliente' . ($this->data['token'] !== '' ? '/' . $this->data['token'] : '')));
    }

    $this->sincronizarBomControle($idCustomer);

    $this->session->unset_userdata('customer_wizard_started');
    redirect(base_url('login?success=' . self::MSG_SUCESSO));
  }

  /**
   * Sobe o cadastro recém-criado para o Bom Controle, quando o tenant tem a
   * integração ligada.
   *
   * Nunca interrompe o cadastro: quem está do outro lado é o cliente final, e o
   * registro local já está gravado — ele é a verdade, o ERP é destino
   * secundário. ERP fora do ar, chave errada ou timeout viram linha no log
   * (o model já registra) e a pessoa segue para a tela de sucesso sem ver nada
   * disso, que não é problema dela nem ela teria como resolver.
   *
   * A escrita na sessão acontece só DEPOIS daqui: o model suspende a sessão em
   * volta da rede, e escrever no meio disso seria descartado na retomada.
   *
   * @param int $idCustomer
   */
  private function sincronizarBomControle($idCustomer)
  {
    try {
      $this->load->model('bomcontrole_model');
      $this->bomcontrole_model->sincronizarCliente(
        (int) $idCustomer,
        (int) $this->idCompany,
        Bomcontrole_model::TIMEOUT_SINCRONIZACAO
      );
    } catch (Throwable $e) {
      log_message('error', sprintf(
        '[BOMCONTROLE] Sincronizacao do cadastro publico falhou — empresa=%d cliente=%d: %s',
        (int) $this->idCompany,
        (int) $idCustomer,
        $e->getMessage()
      ));
    }
  }

  /**
   * @param  array  $post
   * @param  string $secao
   * @param  array  $chaves
   * @return array
   */
  private function extrairSecao($post, $secao, $chaves)
  {
    $resultado = [];
    $origem = isset($post[$secao]) && is_array($post[$secao]) ? $post[$secao] : [];
    foreach ($chaves as $chave) {
      $resultado[$chave] = isset($origem[$chave]) && !is_array($origem[$chave]) ? trim((string) $origem[$chave]) : '';
    }
    return $resultado;
  }

}
