<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Monitoramento dos sites dos clientes.
 *
 * Duas telas, com papéis distintos:
 *
 * - `index` é o FEED de eventos — o que se lê todo dia. Só mostra o que mudou,
 *   com o botão CIENTE para tirar da fila o que já foi tratado.
 * - `dominios` é o ESTADO atual de cada domínio monitorado, para consulta e
 *   para silenciar o que não deve mais avisar.
 *
 * O escopo é sempre `getCurrentCompanyId()`, como nas demais telas, e a
 * população é decidida pela rotina: domínio de contrato vigente cujo tipo de
 * serviço esteja marcado como "tem site" em GESTÃO › Tipos de serviços.
 */
class Monitoramento extends MY_Controller
{
  /** Registros por página. */
  const PER_PAGE = 30;

  /** Feed: mais recente primeiro, com o id como desempate estável. */
  const ORDENACAO_EVENTOS = 'detected desc, id desc';

  /**
   * Chave de sessão do filtro "visto / não visto".
   *
   * SEPARADA de `f_monitor_events` porque o `Global_model::getFilter()` pula
   * toda chave cujo valor seja vazio — e `empty('0')` é TRUE em PHP. Gravado no
   * array do filtro genérico, "somente os não vistos" seria descartado em
   * silêncio e o feed abriria mostrando também o que já foi tratado, que é o
   * oposto do que a tela existe para fazer. Mesmo desenho do
   * `Clientes::FILTRO_AVANCADO`, traduzido para a string de `$where`.
   */
  const FILTRO_VISTO = 'f_monitor_events_visto';

  /**
   * Estado: os problemas primeiro.
   *
   * `situation_order` é coluna derivada da view (migration 030), e não um
   * `FIELD(situation, ...)` escrito aqui: o `order_by()` do CI3 quebra a string
   * em cada vírgula e escapa cada pedaço como nome de coluna, então o FIELD
   * sairia estilhaçado. Mesma armadilha que a 027 contornou no vencimento.
   */
  const ORDENACAO_DOMINIOS = 'situation_order asc, domain asc';

  public function __construct()
  {
    parent::__construct();
    $this->data['menu'] = 'monitoramento';
    $this->load->model('site_monitor_model');
  }

  public function index()
  {
    $this->eventos();
  }

  // ------------------------------------------------------------------
  // Feed de eventos
  // ------------------------------------------------------------------

  public function eventos()
  {
    $this->data['menu'] = 'monitoramento';
    $this->setDefaultEventFilter();

    $where = $this->montarWhereEventos();

    $config = $this->paginationConfig(
      base_url('monitoramento/eventos'),
      $this->global_model->getCountW($where, 'crm_domains_monitor_events_v', 'f_monitor_events')
    );
    $config['uri_segment'] = 3;
    $this->pagination->initialize($config);

    $eventos = $this->global_model->getListW(
      $where,
      'crm_domains_monitor_events_v',
      'f_monitor_events',
      self::ORDENACAO_EVENTOS,
      '',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['results'] = $eventos;
    $this->data['total_results'] = $config['total_rows'];
    $this->data['tipos'] = $this->site_monitor_model->tiposEvento();
    $this->data['marcadores'] = $this->site_monitor_model->rotulosMarcador();
    $this->data['tipos_no_resumo'] = $this->site_monitor_model->tiposNoResumo();
    $this->data['filtro_visto'] = (string) $this->session->userdata(self::FILTRO_VISTO);
    $this->data['filtro_visto_campo'] = self::FILTRO_VISTO;

    // Cliente de cada domínio da página, em UMA query — o mesmo motivo do
    // Servidores::clientesPorDominio(): sem isso seria um SELECT por linha.
    $this->data['customers_by_domain'] = $this->site_monitor_model->clientesPorDominio(
      $this->getCurrentCompanyId(),
      array_unique(array_map(function ($e) {
        return $e->domain;
      }, (array) $eventos))
    );

    $this->data['resumo_situacao'] = $this->contarPorSituacao();

    $this->load->view('header', $this->data);
    $this->load->view('monitoring/events', $this->data);
    $this->load->view('footer', $this->data);
  }

  // ------------------------------------------------------------------
  // Estado dos domínios
  // ------------------------------------------------------------------

  public function dominios()
  {
    $this->data['menu'] = 'monitoramento';
    $this->setDefaultDomainFilter();

    $where = 'id_company = ' . (int) $this->getCurrentCompanyId();

    $config = $this->paginationConfig(
      base_url('monitoramento/dominios'),
      $this->global_model->getCountW($where, 'crm_domains_monitor_v', 'f_monitor_domains')
    );
    $config['uri_segment'] = 3;
    $this->pagination->initialize($config);

    $dominios = $this->global_model->getListW(
      $where,
      'crm_domains_monitor_v',
      'f_monitor_domains',
      self::ORDENACAO_DOMINIOS,
      '',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['results'] = $dominios;
    $this->data['total_results'] = $config['total_rows'];
    $this->data['situacoes'] = $this->situacoes();
    $this->data['marcadores'] = $this->site_monitor_model->rotulosMarcador();
    $this->data['resumo_situacao'] = $this->contarPorSituacao();
    $this->data['customers_by_domain'] = $this->site_monitor_model->clientesPorDominio(
      $this->getCurrentCompanyId(),
      array_unique(array_map(function ($d) {
        return $d->domain;
      }, (array) $dominios))
    );

    $this->load->view('header', $this->data);
    $this->load->view('monitoring/domains', $this->data);
    $this->load->view('footer', $this->data);
  }

  // ------------------------------------------------------------------
  // Ações
  // ------------------------------------------------------------------

  /**
   * Marca um evento como visto.
   *
   * Localiza pelo ID e pelo tenant resolvido NO SERVIDOR — nunca por dados do
   * POST, que é a cicatriz registrada no CLAUDE.md sobre a tela antiga de
   * empresas.
   */
  public function json_postciente()
  {
    $this->output->set_content_type('application/json; charset=utf-8');

    $idEvento = (int) $this->input->post('id_event');
    $idCompany = (int) $this->getCurrentCompanyId();

    $evento = $this->global_model->getWhere('crm_domains_monitor_events', [
      'id' => $idEvento,
      'id_company' => $idCompany,
    ], TRUE);

    if (empty($evento)) {
      echo json_encode(['success' => FALSE, 'message' => 'Evento não encontrado.']);
      return;
    }

    if (!empty($evento->acknowledged)) {
      echo json_encode(['success' => TRUE, 'message' => 'Este evento já estava marcado como visto.']);
      return;
    }

    $this->global_model->edit('crm_domains_monitor_events', [
      'acknowledged' => 1,
      'acknowledged_by' => (int) $this->session->userdata('user')->id,
      'acknowledged_at' => date('Y-m-d H:i:s'),
    ], 'id', $idEvento);

    echo json_encode(['success' => TRUE, 'message' => 'Evento marcado como visto.']);
  }

  /**
   * Liga/desliga o silêncio de um domínio.
   *
   * Diferente de `active`, que é gerido pela rotina: `muted` é a decisão humana
   * de parar de ser avisado sobre um domínio já conhecido. Sem ele, um site
   * sabidamente quebrado poluiria todo resumo diário para sempre.
   */
  public function json_postsilenciar()
  {
    $this->output->set_content_type('application/json; charset=utf-8');

    $idMonitor = (int) $this->input->post('id_monitor');
    $idCompany = (int) $this->getCurrentCompanyId();

    $linha = $this->global_model->getWhere('crm_domains_monitor', [
      'id' => $idMonitor,
      'id_company' => $idCompany,
    ], TRUE);

    if (empty($linha)) {
      echo json_encode(['success' => FALSE, 'message' => 'Domínio não encontrado.']);
      return;
    }

    // A ação vem do POST e é validada, em vez de alternar o valor atual às
    // cegas: o endpoint aceita POST direto, e um toggle cego faria a tela e o
    // banco discordarem quando duas abas agissem sobre a mesma linha.
    $acao = (string) $this->input->post('acao');
    if (!in_array($acao, ['silenciar', 'reativar'], TRUE)) {
      echo json_encode(['success' => FALSE, 'message' => 'Ação inválida.']);
      return;
    }

    $novo = ($acao === 'silenciar') ? 1 : 0;

    $this->global_model->edit('crm_domains_monitor', [
      'muted' => $novo,
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => (int) $this->session->userdata('user')->id,
    ], 'id', $idMonitor);

    echo json_encode([
      'success' => TRUE,
      'message' => $novo ? 'Domínio silenciado: ele não entra mais no resumo.' : 'Domínio reativado no monitoramento.',
      'data' => ['muted' => $novo],
    ]);
  }

  /**
   * Checagem avulsa de um domínio, pedida na tela.
   *
   * `sessao_suspender()` fica dentro do model, em volta da rede — aqui só o
   * escopo do tenant é resolvido.
   */
  public function json_postchecar()
  {
    $this->output->set_content_type('application/json; charset=utf-8');

    $idMonitor = (int) $this->input->post('id_monitor');
    $idUser = (int) $this->session->userdata('user')->id;

    $resultado = $this->site_monitor_model->checarUm($idMonitor, $this->getCurrentCompanyId(), $idUser);

    echo json_encode($resultado);
  }

  // ------------------------------------------------------------------
  // Filtros
  // ------------------------------------------------------------------

  public function post_filtrar_eventos()
  {
    if ($this->input->post('acao') === 'limpar') {
      $this->session->unset_userdata('f_monitor_events');
      $this->session->unset_userdata(self::FILTRO_VISTO);
      redirect(base_url('monitoramento/eventos'));
    }

    // Só reescreve quando a chave VEM no POST. O form do offcanvas sempre envia
    // o select (o "mostrar todos" é opção de valor vazio, não ausência do
    // campo), então a chave só falta no POST da busca rápida — e ali zerar
    // desfaria em silêncio, a cada busca, um filtro que continua marcado no
    // offcanvas e anunciado nos chips.
    if ($this->input->post(self::FILTRO_VISTO) !== NULL) {
      $visto = trim((string) $this->input->post(self::FILTRO_VISTO));
      // Allowlist: o valor entra numa cláusula SQL montada à mão.
      $this->session->set_userdata(self::FILTRO_VISTO, in_array($visto, ['0', '1'], TRUE) ? $visto : '');
    }

    if (empty($this->input->post('f_monitor_events'))) redirect(base_url('monitoramento/eventos'));

    $array = array_merge((array) $this->session->userdata('f_monitor_events'), $this->input->post('f_monitor_events'));
    $this->session->set_userdata('f_monitor_events', $array);
    redirect(base_url('monitoramento/eventos'));
  }

  /**
   * `$where` da listagem de eventos: tenant + o filtro que o motor genérico não
   * consegue expressar.
   */
  private function montarWhereEventos()
  {
    $where = 'id_company = ' . (int) $this->getCurrentCompanyId();

    $visto = (string) $this->session->userdata(self::FILTRO_VISTO);
    if ($visto === '0' || $visto === '1') {
      $where .= ' AND acknowledged = ' . (int) $visto;
    }

    return $where;
  }

  public function post_filtrar_dominios()
  {
    if ($this->input->post('acao') === 'limpar') {
      $this->session->unset_userdata('f_monitor_domains');
      redirect(base_url('monitoramento/dominios'));
    }

    if (empty($this->input->post('f_monitor_domains'))) redirect(base_url('monitoramento/dominios'));

    $array = array_merge((array) $this->session->userdata('f_monitor_domains'), $this->input->post('f_monitor_domains'));
    $this->session->set_userdata('f_monitor_domains', $array);
    redirect(base_url('monitoramento/dominios'));
  }

  private function setDefaultEventFilter()
  {
    $filtros = $this->session->userdata('f_monitor_events');
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('keyword', $filtros)) $filtros['keyword'] = '';
    if (!array_key_exists('type', $filtros)) $filtros['type'] = '';
    if (!array_key_exists('severity', $filtros)) $filtros['severity'] = '';

    $filtros['keyword_search'] = ['domain', 'new_value', 'detail'];

    $this->session->set_userdata('f_monitor_events', $filtros);

    // Abre em "somente os não vistos": o feed existe para mostrar o que ainda
    // precisa de atenção, e abrir com o histórico inteiro esconderia isso. Mora
    // na chave própria — ver o docblock de FILTRO_VISTO.
    if ($this->session->userdata(self::FILTRO_VISTO) === NULL) {
      $this->session->set_userdata(self::FILTRO_VISTO, '0');
    }
  }

  private function setDefaultDomainFilter()
  {
    $filtros = $this->session->userdata('f_monitor_domains');
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('keyword', $filtros)) $filtros['keyword'] = '';
    // `situation` é coluna derivada da view justamente para caber no filtro
    // genérico do Global_model, que só sabe comparar campo = valor.
    if (!array_key_exists('situation', $filtros)) $filtros['situation'] = '';

    $filtros['keyword_search'] = ['domain', 'title', 'ns_list'];

    $this->session->set_userdata('f_monitor_domains', $filtros);
  }

  /** Catálogo das situações, na ordem de urgência do CASE da view. */
  private function situacoes()
  {
    return [
      'fora' => 'Fora do ar',
      'marcador' => 'Problema na página inicial',
      'nunca_respondeu' => 'Nunca respondeu',
      'bloqueado' => 'Bloqueado por firewall/WAF',
      'pendente' => 'Ainda não checado',
      'ok' => 'No ar',
      'silenciado' => 'Silenciado',
      'inativo' => 'Fora do monitoramento',
    ];
  }

  /**
   * Contagem por situação, para os cartões do topo.
   *
   * Query direta com bind, e NÃO pelos helpers do Global_model, de propósito:
   * eles aplicariam o filtro gravado na sessão e os cartões passariam a
   * refletir a última busca em vez da base — o mesmo motivo dos indicadores do
   * Dashboard.
   */
  private function contarPorSituacao()
  {
    $linhas = $this->db->query(
      'SELECT `situation`, COUNT(*) AS `total` FROM `crm_domains_monitor_v`
        WHERE `id_company` = ? GROUP BY `situation`',
      [(int) $this->getCurrentCompanyId()]
    )->result();

    $contagem = [];
    foreach ($linhas as $linha) $contagem[$linha->situation] = (int) $linha->total;

    return $contagem;
  }

  /**
   * Paginação no padrão Bootstrap 5 das demais listagens.
   *
   * Os `*_tag_open` das páginas navegáveis fecham no `<li>` e NÃO abrem um
   * `<span class="page-link">`: o CI3 já emite o `<a>` com a classe vinda de
   * `attributes`, e envolvê-lo num span de mesma classe aninha dois
   * `.page-link` — borda e padding duplicados, que é o que deixava o rodapé da
   * listagem com caixas dentro de caixas.
   *
   * `cur_tag_*` é a única exceção legítima, e por isso mantém o span: a página
   * atual não vira link, então precisa de alguém para carregar a classe.
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
}
