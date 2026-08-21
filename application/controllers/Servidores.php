<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Servidores de hospedagem (WHM, DirectAdmin e CloudPanel) e os domínios que
 * cada um hospeda.
 *
 * O escopo é sempre a empresa selecionada no filtro do topo
 * (getCurrentCompanyId), como em Usuarios — a empresa master troca de contexto
 * pelo seletor da sidebar em vez de ver tudo de uma vez.
 *
 * A credencial nunca volta para a tela: as listagens leem de crm_servers_v, que
 * não expõe a coluna `secret`, e o formulário de edição sempre chega com o
 * campo vazio.
 */
class Servidores extends MY_Controller
{
  /** Registros por página nas listagens. */
  const PER_PAGE = 30;

  /**
   * Ordenações oferecidas na listagem de domínios.
   *
   * Allowlist, e não string livre da tela: o valor vai direto para o
   * `order_by()` do CI3. A chave é o que trafega no POST e fica na sessão; o
   * valor é a ordenação, sempre com `id desc` de desempate — `domain` não é
   * único (a UNIQUE é `id_server, domain`), então sem critério estável o mesmo
   * domínio em dois servidores troca de lugar entre uma página e outra, sumindo
   * das duas ou aparecendo nas duas.
   *
   * `whois_expiration_sort` é coluna derivada da `crm_servers_domains_v`
   * (migration 027) e não a data crua: NULL ordena primeiro no ASC e 81% da base
   * está sem vencimento, e expressão no ORDER BY seria escapada pelo
   * `protect_identifiers` do CI3 como se fosse nome de coluna.
   *
   * `last_sync asc` traz NULL primeiro de propósito: nunca sincronizado É o mais
   * atrasado de todos. Nas duas `desc`, o MySQL já joga NULL para o fim.
   */
  const ORDENACOES_DOMINIOS = [
    'domain' => 'domain asc, id desc',
    'vencimento' => 'whois_expiration_sort asc, id desc',
    'sync_antigo' => 'last_sync asc, id desc',
    'sync_recente' => 'last_sync desc, id desc',
    'disco' => 'disk_used_mb desc, id desc',
  ];

  /** Rótulos das ordenações, na ordem em que aparecem no select. */
  const ORDENACOES_DOMINIOS_ROTULOS = [
    'domain' => 'Domínio (A-Z)',
    'vencimento' => 'Vencimento mais próximo',
    'sync_antigo' => 'Sincronizado há mais tempo',
    'sync_recente' => 'Sincronizado mais recente',
    'disco' => 'Maior uso de disco',
  ];

  /** Ordenação usada quando a sessão está vazia ou com valor desconhecido. */
  const ORDENACAO_DOMINIOS_PADRAO = 'domain';

  /**
   * Chave de sessão da ordenação da listagem de domínios.
   *
   * SEPARADA de `f_server_domains` de propósito: o `Global_model::getFilter()`
   * transforma cada chave daquele array em `WHERE <chave> = <valor>`, então uma
   * chave `ordem` ali viraria `WHERE ordem = 'vencimento'` e derrubaria a
   * listagem inteira — o mesmo acidente que a migration 015 evitou descartando o
   * `id_status`. E o valor aqui é ESCALAR: esta chave nunca pode ser passada
   * como `$filter` para o Global_model, que faria `foreach` sobre string.
   */
  const SESSAO_ORDENACAO_DOMINIOS = 'f_server_domains_ordem';

  public function __construct()
  {
    parent::__construct();
    $this->data['menu'] = 'servidores';
    $this->load->model('server_model');
  }

  // ------------------------------------------------------------------
  // Listagem
  // ------------------------------------------------------------------

  public function index()
  {
    $this->listagem();
  }

  public function listagem()
  {
    $this->setDefaultServerFilter();

    $where = 'id_company = ' . (int) $this->getCurrentCompanyId();

    $config = $this->paginationConfig(
      base_url('servidores/listagem'),
      $this->global_model->getCountW($where, 'crm_servers_v', 'f_servers')
    );
    $this->pagination->initialize($config);

    $this->data['results'] = $this->global_model->getListW(
      $where,
      'crm_servers_v',
      'f_servers',
      'name',
      'asc',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['total_results'] = $config['total_rows'];
    $this->data['est_count'] = $config['total_rows'];
    $this->data['server_types'] = $this->serverTypes();
    $this->data['crm_status'] = $this->global_model->getWhereOrderBy_off('crm_status', 'id in (1,2)', 'name', 'asc', FALSE);

    $this->load->view('header', $this->data);
    $this->load->view('servers/list', $this->data);
    $this->load->view('footer', $this->data);
  }

  public function post_filtrar()
  {
    if (empty($this->input->post('f_servers'))) redirect(base_url('servidores'));
    $array = array_merge((array) $this->session->userdata('f_servers'), $this->input->post('f_servers'));
    $this->session->set_userdata('f_servers', $array);
    redirect(base_url('servidores'));
  }

  // ------------------------------------------------------------------
  // Cadastro
  // ------------------------------------------------------------------

  public function novo()
  {
    $this->setFormRules(TRUE);

    if ($this->form_validation->run() == FALSE) {
      $this->data['result'] = NULL;
      $this->renderForm();
      return;
    }

    $dados = $this->montarDadosDoPost(TRUE);
    if ($dados === FALSE) {
      $this->data['result'] = NULL;
      $this->renderForm();
      return;
    }

    $idCompany = (int) $this->getCurrentCompanyId();
    $idUser = (int) $this->session->userdata('user')->id;

    if ($this->nomeEmUso($dados['name'], $idCompany, 0)) {
      $this->session->set_flashdata('warning', 'Já existe um servidor com este nome nesta empresa.');
      $this->data['result'] = NULL;
      $this->renderForm();
      return;
    }

    $dados['id_company'] = $idCompany;
    $dados['id_status'] = 1;
    $dados['created'] = date('Y-m-d H:i:s');
    $dados['created_by'] = $idUser;
    $dados['modified'] = date('Y-m-d H:i:s');
    $dados['modified_by'] = $idUser;

    $idServer = $this->global_model->add('crm_servers', $dados);
    if (empty($idServer)) {
      $this->session->set_flashdata('error', 'Não foi possível cadastrar o servidor.');
      redirect(base_url('servidores'));
    }

    $this->session->set_flashdata('success', 'Servidor cadastrado. Use "Testar conexão" para validar as credenciais.');
    redirect(base_url('servidores/editar?id=' . (int) $idServer));
  }

  public function editar()
  {
    $this->setFormRules(FALSE);

    if ($this->form_validation->run() == FALSE) {
      $id = (int) $this->input->get('id');
      $this->data['result'] = $this->carregarServidorDaView($id);
      $this->renderForm();
      return;
    }

    $id = (int) $this->input->post('id');
    $atual = $this->server_model->getServer($id, $this->getCurrentCompanyId());
    if ($atual === NULL) {
      $this->session->set_flashdata('warning', 'Servidor não encontrado.');
      redirect(base_url('servidores'));
    }

    $dados = $this->montarDadosDoPost(FALSE);
    if ($dados === FALSE) {
      $this->data['result'] = $this->carregarServidorDaView($id);
      $this->renderForm();
      return;
    }

    if ($this->nomeEmUso($dados['name'], (int) $atual->id_company, $id)) {
      $this->session->set_flashdata('warning', 'Já existe outro servidor com este nome nesta empresa.');
      $this->data['result'] = $this->carregarServidorDaView($id);
      $this->renderForm();
      return;
    }

    $dados['modified'] = date('Y-m-d H:i:s');
    $dados['modified_by'] = (int) $this->session->userdata('user')->id;

    $this->global_model->edit('crm_servers', $dados, 'id', $id);

    $this->session->set_flashdata('success', 'Servidor atualizado com sucesso.');
    redirect(base_url('servidores/editar?id=' . $id));
  }

  // ------------------------------------------------------------------
  // Ações AJAX
  // ------------------------------------------------------------------

  public function json_posttestar()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    // O teste é I/O de rede e pode encostar no timeout configurado no servidor.
    @set_time_limit(0);

    $resultado = $this->server_model->testConnection($id, $this->getCurrentCompanyId(), (int) $this->session->userdata('user')->id);

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['connection' => $resultado['message']],
    ]);
  }

  /**
   * Devolve a credencial decifrada para exibição na tela de edição.
   *
   * É um endpoint separado de propósito: assim o segredo só trafega quando
   * alguém clica em "ver", em vez de vir embutido no HTML de toda abertura do
   * formulário (onde ficaria no cache do navegador, no histórico e em qualquer
   * "exibir código-fonte").
   *
   * Toda visualização vai para o log da aplicação — é a única trilha de quem
   * leu qual credencial.
   */
  public function json_postrevelar()
  {
    header('Content-Type: application/json; charset=utf-8');
    // Resposta com segredo não pode ficar em cache de navegador ou proxy.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    // getServer aplica o escopo: servidor de outra empresa não é encontrado.
    $servidor = $this->server_model->getServer($id, $this->getCurrentCompanyId());
    if ($servidor === NULL) {
      echo json_encode($this->jsonErro('Servidor não encontrado.', ['id' => 'Servidor não encontrado.']));
      return;
    }

    if (empty($servidor->secret)) {
      echo json_encode($this->jsonErro('Este servidor não tem credencial cadastrada.', ['secret' => 'Sem credencial.']));
      return;
    }

    $this->load->library('secret_crypto');
    $segredo = $this->secret_crypto->decrypt($servidor->secret);

    if ($segredo === FALSE) {
      echo json_encode($this->jsonErro(
        'Não foi possível decifrar a credencial. A chave de criptografia (secret_crypto_key) pode ter sido trocada — recadastre a credencial.',
        ['secret' => 'Credencial ilegível.']
      ));
      return;
    }

    $usuario = $this->session->userdata('user');
    log_message('error', sprintf(
      '[CREDENCIAL] Usuário %d (%s) visualizou a credencial do servidor %d (%s) a partir do IP %s.',
      (int) $usuario->id,
      isset($usuario->name) ? $usuario->name : '?',
      (int) $servidor->id,
      $servidor->name,
      $this->input->ip_address()
    ));

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => 'Credencial exibida.',
      'data' => ['secret' => $segredo],
      'errors' => [],
    ]);
  }

  public function json_postsincronizar()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    // DirectAdmin faz três chamadas por usuário e a varredura do CloudPanel
    // percorre o disco: sem isto o PHP corta no meio e o servidor fica com
    // last_sync desatualizado sem explicação.
    @set_time_limit(0);

    $resultado = $this->server_model->syncDomains($id, $this->getCurrentCompanyId(), (int) $this->session->userdata('user')->id);

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['sync' => $resultado['message']],
    ]);
  }

  // ------------------------------------------------------------------
  // Domínios
  // ------------------------------------------------------------------

  public function dominios()
  {
    $this->data['menu'] = 'servidores/dominios';
    $this->setDefaultDomainFilter();

    $where = 'id_company = ' . (int) $this->getCurrentCompanyId();

    $config = $this->paginationConfig(
      base_url('servidores/dominios'),
      $this->global_model->getCountW($where, 'crm_servers_domains_v', 'f_server_domains')
    );
    $config['uri_segment'] = 3;
    $this->pagination->initialize($config);

    // Ordenação composta no campo, com direção vazia: o order_by do CI3 quebra
    // a string na vírgula e lê o ASC/DESC de cada pedaço, escapando os nomes de
    // coluna — é assim que se ordena por dois campos pelo Global_model.
    $this->data['results'] = $this->global_model->getListW(
      $where,
      'crm_servers_domains_v',
      'f_server_domains',
      self::ORDENACOES_DOMINIOS[$this->ordenacaoDominios()],
      '',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['ordenacao_atual'] = $this->ordenacaoDominios();
    $this->data['ordenacoes'] = self::ORDENACOES_DOMINIOS_ROTULOS;
    $this->data['total_results'] = $config['total_rows'];
    $this->data['est_count'] = $config['total_rows'];
    $this->data['servers'] = $this->global_model->getFieldsWhere_off(
      'crm_servers_v',
      'id, name, type',
      $where,
      'name',
      'asc',
      FALSE
    );
    $this->data['server_types'] = $this->serverTypes();
    $this->data['customers_by_domain'] = $this->clientesPorDominio((array) $this->data['results']);

    $this->load->view('header', $this->data);
    $this->load->view('servers/domains', $this->data);
    $this->load->view('footer', $this->data);
  }

  /**
   * Cliente de cada domínio da página, pelo vínculo do domínio do contrato
   * (`crm_contracts_domains.id_server_domain`).
   *
   * Query agregada à parte, e não coluna nova na `crm_servers_domains_v`: um
   * subselect na view seria pago também por toda contagem de paginação, e a
   * pergunta só existe nesta tela — mesmo motivo da contagem de domínios da
   * exportação de clientes. Só os ids da página entram na consulta.
   *
   * O retorno é uma LISTA por domínio, não um nome só: o mesmo domínio de
   * servidor pode aparecer em contratos de clientes diferentes, e escolher um
   * em silêncio esconderia justamente o cadastro que precisa de conserto. O
   * GROUP BY dedup o cliente que cita o mesmo domínio em mais de um contrato.
   *
   * O documento vem junto porque a base tem cadastros duplicados de mesma razão
   * social (dois clientes, mesmo nome, no mesmo domínio): sem ele, a tela
   * repetiria o nome e pareceria defeito de renderização.
   *
   * @param  array $dominios linhas da listagem
   * @return array id do domínio de servidor => [ ['id' => int, 'name' => string, 'document' => string], ... ]
   */
  private function clientesPorDominio($dominios)
  {
    $ids = [];
    foreach ($dominios as $dominio) $ids[] = (int) $dominio->id;
    if (empty($ids)) return [];

    // Os ids saem da própria listagem e passam por (int) — não há entrada do
    // usuário na string do IN.
    $sql = "SELECT crm_contracts_domains.id_server_domain AS id_server_domain,
                   crm_customers.id AS id_customer,
                   crm_customers.name AS name,
                   crm_customers.document AS document
              FROM crm_contracts_domains
              JOIN crm_contracts ON(crm_contracts.id = crm_contracts_domains.id_contract)
              JOIN crm_customers ON(crm_customers.id = crm_contracts.id_customer)
             WHERE crm_contracts_domains.id_company = ?
               AND crm_contracts_domains.id_server_domain IN (" . implode(',', $ids) . ")
             GROUP BY crm_contracts_domains.id_server_domain, crm_customers.id, crm_customers.name, crm_customers.document
             ORDER BY crm_customers.name ASC";

    $linhas = $this->db->query($sql, [(int) $this->getCurrentCompanyId()])->result();

    $porDominio = [];
    foreach ($linhas as $linha) {
      $porDominio[(int) $linha->id_server_domain][] = [
        'id' => (int) $linha->id_customer,
        'name' => (string) $linha->name,
        'document' => (string) $linha->document,
      ];
    }

    return $porDominio;
  }

  /**
   * Ordenação gravada na sessão, validada contra a allowlist.
   *
   * Valor desconhecido cai no padrão em vez de ir para o SQL: a chave da sessão
   * sobrevive a deploy, e uma ordenação removida numa versão futura derrubaria a
   * listagem de quem estivesse com ela escolhida.
   *
   * @return string chave de ORDENACOES_DOMINIOS
   */
  private function ordenacaoDominios()
  {
    $ordem = (string) $this->session->userdata(self::SESSAO_ORDENACAO_DOMINIOS);

    return array_key_exists($ordem, self::ORDENACOES_DOMINIOS)
      ? $ordem
      : self::ORDENACAO_DOMINIOS_PADRAO;
  }

  public function post_filtrar_dominios()
  {
    // O botão LIMPAR zera a busca E os filtros do offcanvas de uma vez: com o
    // formulário escondido, filtro esquecido lá dentro vira listagem
    // inexplicavelmente vazia. A ordenação NÃO é zerada — ela não é filtro, não
    // recorta nada e não tem chip; perder a ordem escolhida ao limpar uma busca
    // seria efeito colateral sem motivo.
    if ($this->input->post('acao') === 'limpar') {
      $this->session->unset_userdata('f_server_domains');
      redirect(base_url('servidores/dominios'));
    }

    // A ordem é lida ANTES da guarda do filtro: ela viaja fora do array
    // `f_server_domains` (senão viraria WHERE ordem = ...), e um POST que traga
    // só a ordem — o select num form próprio, com submit no onchange — cairia no
    // redirect abaixo e a escolha seria descartada sem nenhuma mensagem.
    $ordem = (string) $this->input->post('ordem');
    if (array_key_exists($ordem, self::ORDENACOES_DOMINIOS)) {
      $this->session->set_userdata(self::SESSAO_ORDENACAO_DOMINIOS, $ordem);
    }

    // O formulário do offcanvas SEMPRE envia os três selects (o "mostrar todos"
    // é opção de valor vazio, não ausência do campo) e o da busca sempre envia a
    // keyword — como as quatro chaves vivem no MESMO array, o array_merge
    // preserva o que não veio no POST. Buscar não derruba o filtro do offcanvas,
    // e filtrar não apaga a busca.
    if (empty($this->input->post('f_server_domains'))) redirect(base_url('servidores/dominios'));
    $array = array_merge((array) $this->session->userdata('f_server_domains'), $this->input->post('f_server_domains'));
    $this->session->set_userdata('f_server_domains', $array);
    redirect(base_url('servidores/dominios'));
  }

  // ------------------------------------------------------------------
  // WHOIS
  // ------------------------------------------------------------------

  /**
   * Consulta o WHOIS de um domínio na hora e devolve o registro completo para o
   * modal. O escopo do tenant é resolvido no servidor: o POST só informa o id.
   *
   * A consulta também GRAVA o resultado. A chamada já foi paga em quota, então
   * descartar o que ela trouxe seria desperdício — e é o mesmo dado que o cron
   * gravaria depois.
   *
   * O sessao_suspender()/retomar em volta da chamada de rede fica dentro do
   * Whois_model::syncDomain(), junto do cURL.
   */
  public function json_postwhois()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('whois_model');
    $resultado = $this->whois_model->syncOne(
      $id,
      $this->getCurrentCompanyId(),
      (int) $this->session->userdata('user')->id
    );

    $dados = NULL;
    if (!empty($resultado['success'])) {
      $dados = $this->montarRegistroWhois($resultado);
      $dados['row'] = $this->montarLinhaWhois($id);
    } elseif (!empty($resultado['livre'])) {
      // "Domínio não existe" não é consulta bem-sucedida (não há registro para
      // exibir), mas o estado FOI gravado — devolver a linha deixa a listagem
      // trocar a data velha pelo selo "Livre" sem esperar um refresh.
      $dados = ['row' => $this->montarLinhaWhois($id)];
    }

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $dados,
      'errors' => $resultado['success'] ? [] : ['whois' => $resultado['message']],
    ]);
  }

  /**
   * Valores já formatados da linha, para a listagem se atualizar no lugar
   * depois da consulta — sem recarregar a página e perder filtro e paginação.
   *
   * Lê da view porque é lá que mora o `whois_bucket`, a faixa que define a cor.
   *
   * @param  int $id
   * @return array|null
   */
  private function montarLinhaWhois($id)
  {
    $linha = $this->global_model->getWhere_off('crm_servers_domains_v', [
      'id' => (int) $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($linha)) {
      return NULL;
    }

    return [
      'id' => (int) $linha->id,
      'bucket' => $linha->whois_bucket,
      'expiration' => !empty($linha->whois_expiration_date) ? date('d/m/Y', strtotime($linha->whois_expiration_date)) : NULL,
      'ns1' => $linha->whois_ns1,
      'ns2' => $linha->whois_ns2,
      'nameservers' => $linha->whois_nameservers,
      'ns_changed' => !empty($linha->whois_ns_changed) ? data($linha->whois_ns_changed) : NULL,
    ];
  }

  /**
   * Achata o registro consultado em pares rótulo/valor prontos para o modal.
   *
   * A formatação fica aqui, e não no JavaScript, para as datas saírem no padrão
   * brasileiro do resto do sistema sem duplicar regra no navegador.
   *
   * @param  array $resultado retorno de Whois_model::syncOne()
   * @return array
   */
  private function montarRegistroWhois(array $resultado)
  {
    $whois = isset($resultado['whois']) && is_array($resultado['whois']) ? $resultado['whois'] : [];
    $normalizado = isset($whois['normalizado']) ? (array) $whois['normalizado'] : [];
    $raw = isset($whois['raw']) ? (array) $whois['raw'] : [];

    $campos = [];
    $incluir = function ($rotulo, $valor) use (&$campos) {
      if ($valor === NULL || $valor === '' || $valor === []) return;
      $campos[] = ['label' => $rotulo, 'value' => (string) $valor];
    };

    $data = function ($valor) {
      return empty($valor) ? NULL : date('d/m/Y', strtotime($valor));
    };

    $incluir('Domínio consultado', isset($whois['domain']) ? $whois['domain'] : NULL);
    $incluir('Vencimento', $data(isset($normalizado['expiration_date']) ? $normalizado['expiration_date'] : NULL));
    $incluir('Registrado em', $data(isset($normalizado['creation_date']) ? $normalizado['creation_date'] : NULL));
    $incluir('Última atualização', $data(isset($normalizado['updated_date']) ? $normalizado['updated_date'] : NULL));
    $incluir('Registrador', isset($normalizado['registrar']) ? $normalizado['registrar'] : NULL);
    $incluir('Site do registrador', isset($raw['registrar_url']) && is_string($raw['registrar_url']) ? $raw['registrar_url'] : NULL);
    $incluir('Servidor WHOIS', isset($raw['whois_server']) && is_string($raw['whois_server']) ? $raw['whois_server'] : NULL);

    // Campos que só o RDAP do Registro.br traz (formato RFC 9083).
    $this->load->library('rdap_registrobr');
    $titular = $this->rdap_registrobr->extrairTitular($raw);
    $incluir('Titular', $titular['nome']);
    $incluir('Documento do titular', $titular['documento']);

    if (!empty($raw['status']) && is_array($raw['status'])) {
      $incluir('Situação no registro', implode(', ', array_filter($raw['status'], 'is_string')));
    }

    // A API Ninjas devolve dnssec como texto; o RDAP, como booleano em secureDNS.
    if (isset($raw['dnssec']) && is_string($raw['dnssec'])) {
      $incluir('DNSSEC', $raw['dnssec']);
    } elseif (isset($raw['secureDNS']['delegationSigned'])) {
      $incluir('DNSSEC', !empty($raw['secureDNS']['delegationSigned']) ? 'Assinado' : 'Não assinado');
    }

    if (!empty($raw['emails'])) {
      $emails = array_filter(array_map('strval', (array) $raw['emails']));
      $incluir('E-mails de contato', implode(', ', $emails));
    }

    $nameservers = isset($normalizado['name_servers']) ? (array) $normalizado['name_servers'] : [];

    return [
      'fields' => $campos,
      'nameservers' => array_values($nameservers),
      'ns_changed' => !empty($resultado['ns_changed']),
      'changes' => (int) $resultado['changes'],
    ];
  }

  /**
   * Histórico de mudanças de WHOIS de um domínio, para o modal da listagem.
   */
  public function json_gethistoricowhois()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->get('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    $linha = $this->global_model->getWhere_off('crm_servers_domains', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($linha)) {
      echo json_encode($this->jsonErro('Domínio não encontrado.', ['id' => 'Domínio não encontrado.']));
      return;
    }

    $this->load->model('whois_model');
    $historico = $this->whois_model->getHistory($linha->domain, $this->getCurrentCompanyId());

    $rotulos = [
      'nameservers' => 'Nameservers',
      'expiration_date' => 'Vencimento',
      'registrar' => 'Registrador',
    ];

    $itens = [];
    foreach ($historico as $registro) {
      $itens[] = [
        'field' => isset($rotulos[$registro->field]) ? $rotulos[$registro->field] : $registro->field,
        'old_value' => $registro->old_value,
        'new_value' => $registro->new_value,
        'detected' => !empty($registro->detected) ? date('d/m/Y H:i', strtotime($registro->detected)) : '',
      ];
    }

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => '',
      'data' => ['domain' => $linha->domain, 'items' => $itens],
      'errors' => [],
    ]);
  }

  // ------------------------------------------------------------------
  // Apoio
  // ------------------------------------------------------------------

  /**
   * @return array alias => rótulo
   */
  private function serverTypes()
  {
    $tipos = [];
    foreach (Server_model::TYPES as $tipo) {
      $tipos[$tipo] = $this->server_model->typeLabel($tipo);
    }
    return $tipos;
  }

  private function setDefaultServerFilter()
  {
    $filtros = $this->session->userdata('f_servers');
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('keyword', $filtros)) $filtros['keyword'] = '';
    if (!array_key_exists('type', $filtros)) $filtros['type'] = '';
    if (!array_key_exists('id_status', $filtros)) $filtros['id_status'] = '';
    $filtros['keyword_search'] = ['name', 'host', 'username'];

    $this->session->set_userdata('f_servers', $filtros);
  }

  private function setDefaultDomainFilter()
  {
    $filtros = $this->session->userdata('f_server_domains');
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('keyword', $filtros)) $filtros['keyword'] = '';
    if (!array_key_exists('id_server', $filtros)) $filtros['id_server'] = '';
    if (!array_key_exists('status', $filtros)) $filtros['status'] = '';
    // whois_bucket é coluna derivada da crm_servers_domains_v: o filtro genérico
    // do Global_model só sabe comparar campo = valor, e a faixa de vencimento
    // (IS NULL, vencido, próximos 30 dias) não caberia nele de outro jeito.
    if (!array_key_exists('whois_bucket', $filtros)) $filtros['whois_bucket'] = '';
    $filtros['keyword_search'] = ['domain', 'owner_username', 'ip'];

    $this->session->set_userdata('f_server_domains', $filtros);
  }

  /**
   * Configuração de paginação no padrão Bootstrap 5 usado no projeto.
   *
   * @param  string $baseUrl
   * @param  int    $totalRows
   * @return array
   */
  private function paginationConfig($baseUrl, $totalRows)
  {
    return [
      'base_url' => $baseUrl,
      'per_page' => self::PER_PAGE,
      'total_rows' => $totalRows,
      'next_link' => 'Próxima',
      'prev_link' => 'Anterior',
      'first_link' => 'Primeira',
      'last_link' => 'Última',
      'full_tag_open' => '<nav><ul class="pagination justify-content-center">',
      'full_tag_close' => '</ul></nav>',
      'num_tag_open' => '<li class="page-item">',
      'num_tag_close' => '</li>',
      'cur_tag_open' => '<li class="page-item active"><span class="page-link">',
      'cur_tag_close' => '</span></li>',
      'prev_tag_open' => '<li class="page-item">',
      'prev_tag_close' => '</li>',
      'next_tag_open' => '<li class="page-item">',
      'next_tag_close' => '</li>',
      'first_tag_open' => '<li class="page-item">',
      'first_tag_close' => '</li>',
      'last_tag_open' => '<li class="page-item">',
      'last_tag_close' => '</li>',
      'attributes' => ['class' => 'page-link'],
    ];
  }

  /**
   * @param  int $id
   * @return object|null
   */
  private function carregarServidorDaView($id)
  {
    if ($id <= 0) {
      $this->session->set_flashdata('warning', 'Servidor não informado.');
      redirect(base_url('servidores'));
    }

    $servidor = $this->global_model->getWhere_off('crm_servers_v', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($servidor)) {
      $this->session->set_flashdata('warning', 'Servidor não encontrado.');
      redirect(base_url('servidores'));
    }

    return $servidor;
  }

  private function renderForm()
  {
    $this->data['server_types'] = $this->serverTypes();
    $this->data['crm_status'] = $this->global_model->getWhereOrderBy_off('crm_status', 'id in (1,2)', 'name', 'asc', FALSE);
    $this->data['crypto_ready'] = $this->secretCryptoReady();

    $this->load->view('header', $this->data);
    $this->load->view('servers/form', $this->data);
    $this->load->view('footer', $this->data);
  }

  /**
   * @return bool
   */
  private function secretCryptoReady()
  {
    $this->load->library('secret_crypto');
    return $this->secret_crypto->isReady();
  }

  /**
   * @param bool $novo
   */
  private function setFormRules($novo)
  {
    $this->form_validation->set_rules('server[name]', 'Nome', 'required|trim|max_length[150]');
    $this->form_validation->set_rules('server[type]', 'Tipo', 'required|trim');
    $this->form_validation->set_rules('server[host]', 'Endereço', 'required|trim|max_length[255]');
    $this->form_validation->set_rules('server[username]', 'Usuário', 'required|trim|max_length[150]');
    $this->form_validation->set_rules('server[timeout_seconds]', 'Timeout', 'required|is_natural_no_zero|less_than_equal_to[600]');

    if ($novo) {
      $this->form_validation->set_rules('secret', 'Credencial', 'required');
    }
  }

  /**
   * Valida o que o form_validation não cobre e monta o array de gravação.
   *
   * @param  bool $novo
   * @return array|bool FALSE quando algo é inválido (flashdata já definido)
   */
  private function montarDadosDoPost($novo)
  {
    $post = (array) $this->input->post('server');
    $tipo = isset($post['type']) ? trim((string) $post['type']) : '';

    if (!$this->server_model->isValidType($tipo)) {
      $this->session->set_flashdata('warning', 'Selecione um tipo de servidor válido.');
      return FALSE;
    }

    $dados = [
      'name' => mb_strtoupper(trim((string) $post['name'])),
      'type' => $tipo,
      'host' => trim((string) $post['host']),
      'username' => trim((string) $post['username']),
      'timeout_seconds' => (int) $post['timeout_seconds'],
    ];

    if ($tipo === 'cloudpanel') {
      $porta = isset($post['port']) ? (int) $post['port'] : 0;
      if ($porta < 1 || $porta > 65535) {
        $this->session->set_flashdata('warning', 'Informe uma porta SSH entre 1 e 65535.');
        return FALSE;
      }

      $auth = isset($post['auth_type']) ? trim((string) $post['auth_type']) : '';
      if (!in_array($auth, ['password', 'key'], TRUE)) {
        $this->session->set_flashdata('warning', 'Selecione o tipo de autenticação do CloudPanel (senha ou chave).');
        return FALSE;
      }

      $dados['port'] = $porta;
      $dados['auth_type'] = $auth;
      // CloudPanel é SSH: a verificação de certificado TLS não se aplica.
      $dados['verify_ssl'] = 0;
    } elseif ($tipo === 'carbonio') {
      // A API administrativa do Carbonio atende numa porta própria (6071 por
      // padrão), mas ela é HTTP: a porta é opcional — em branco, a library usa
      // o padrão — e a verificação de certificado continua valendo.
      $porta = isset($post['port']) ? (int) $post['port'] : 0;
      if ($porta !== 0 && ($porta < 1 || $porta > 65535)) {
        $this->session->set_flashdata('warning', 'Informe uma porta entre 1 e 65535, ou deixe em branco para usar a padrão (6071).');
        return FALSE;
      }

      $dados['port'] = ($porta > 0) ? $porta : NULL;
      $dados['auth_type'] = NULL;
      $dados['verify_ssl'] = !empty($post['verify_ssl']) ? 1 : 0;
    } else {
      $dados['port'] = NULL;
      $dados['auth_type'] = NULL;
      $dados['verify_ssl'] = !empty($post['verify_ssl']) ? 1 : 0;
    }

    // Credencial em branco na edição = manter a que já está gravada.
    $segredo = (string) $this->input->post('secret', FALSE);
    if ($segredo !== '') {
      $this->load->library('secret_crypto');
      $cifrado = $this->secret_crypto->encrypt($segredo);

      if ($cifrado === FALSE) {
        $this->session->set_flashdata('error', 'A chave de criptografia (secret_crypto_key) não está configurada. A credencial não foi salva.');
        return FALSE;
      }
      $dados['secret'] = $cifrado;
    } elseif ($novo) {
      $this->session->set_flashdata('warning', 'Informe a credencial de acesso ao servidor.');
      return FALSE;
    }

    return $dados;
  }

  /**
   * @param  string $nome
   * @param  int    $idCompany
   * @param  int    $ignorarId
   * @return bool
   */
  private function nomeEmUso($nome, $idCompany, $ignorarId)
  {
    $existente = $this->global_model->getFieldsWhereSingle_off(
      'crm_servers',
      ['id'],
      ['name' => $nome, 'id_company' => (int) $idCompany],
      TRUE
    );

    return !empty($existente) && (int) $existente->id !== (int) $ignorarId;
  }

  /**
   * @param  string $mensagem
   * @param  array  $errors
   * @return array
   */
  private function jsonErro($mensagem, $errors = [])
  {
    return [
      'success' => FALSE,
      'return' => FALSE,
      'message' => $mensagem,
      'data' => NULL,
      'errors' => $errors,
    ];
  }
}
