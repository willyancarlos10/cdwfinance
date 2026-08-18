<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Faturas geradas pelo CDW Finance.
 *
 * Só aparecem aqui as faturas dos contratos com `billing_source = 'cdwfinance'`
 * — os demais continuam sendo cobrados pelo ERP Bom Controle, e o que se vê
 * deles é o extrato ao vivo na tela do contrato.
 *
 * Não há criação manual de fatura fora do contrato: a cobrança nasce do
 * contrato (pelo cron ou pelo botão GERAR FATURA), porque é ele que define
 * valor, ciclo e vencimento. Aqui é listagem e manutenção.
 */
class Faturas extends MY_Controller
{
  /** Registros por página. */
  const PER_PAGE = 30;

  /**
   * Filtros que o Global_model NÃO sabe aplicar.
   *
   * Situação e intervalo de vencimento precisam de comparação e BETWEEN, e o
   * getFilter() traduz cada chave de `f_invoices` num `WHERE <chave> = <valor>`
   * — gravados lá, virariam comparação com coluna inexistente e derrubariam a
   * listagem. É a mesma armadilha documentada no filtro de contratos.
   */
  const FILTRO_AVANCADO = 'f_invoices_avancado';

  /** Ordenação da listagem; o id desempata para a paginação não "piscar". */
  const ORDENACAO_LISTAGEM = 'due_date desc, id desc';

  public function __construct()
  {
    parent::__construct();
    $this->data['menu'] = 'faturas';
    $this->load->model('invoice_model');
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
    $this->setDefaultInvoiceFilter();

    $where = $this->montarWhereListagem();

    $config = $this->paginationConfig(
      base_url('faturas/listagem'),
      $this->global_model->getCountW($where, 'crm_invoices_v', 'f_invoices')
    );
    $this->pagination->initialize($config);

    // Ordenação composta vai no CAMPO com direção vazia: o order_by do CI3
    // quebra a string na vírgula e escaparia "id desc" como nome de coluna.
    $this->data['results'] = $this->global_model->getListW(
      $where,
      'crm_invoices_v',
      'f_invoices',
      self::ORDENACAO_LISTAGEM,
      '',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['total_results'] = $config['total_rows'];
    $this->data['est_count'] = $config['total_rows'];
    $this->data['situations'] = $this->situations();
    $this->data['totais'] = $this->totaisDoFiltro($where);

    // A chave vai por variável, e não como `Faturas::FILTRO_AVANCADO` na view:
    // referenciar a constante amarra o arquivo a este controller estar
    // carregado, e a view quebraria se outra tela a renderizasse.
    $this->data['filtro_avancado'] = self::FILTRO_AVANCADO;

    $this->load->view('header', $this->data);
    $this->load->view('invoices/list', $this->data);
    $this->load->view('footer', $this->data);
  }

  /**
   * Dois formulários escrevem no mesmo filtro (a busca visível e o offcanvas),
   * então o array_merge é o que impede um de zerar o outro.
   *
   * O avançado só é reescrito quando a chave vem no POST: o form da busca
   * rápida não a envia, e zerar ali desfaria em silêncio um filtro que continua
   * marcado no offcanvas e anunciado nos chips.
   */
  public function post_filtrar()
  {
    if ($this->input->post('acao') === 'limpar') {
      $this->session->unset_userdata('f_invoices');
      $this->session->unset_userdata(self::FILTRO_AVANCADO);
      redirect(base_url('faturas'));
    }

    if ($this->input->post('f_invoices') !== NULL) {
      $array = array_merge((array) $this->session->userdata('f_invoices'), (array) $this->input->post('f_invoices'));
      $this->session->set_userdata('f_invoices', $array);
    }

    if ($this->input->post(self::FILTRO_AVANCADO) !== NULL) {
      $avancado = (array) $this->input->post(self::FILTRO_AVANCADO);

      $situacao = isset($avancado['situation']) ? (string) $avancado['situation'] : '';
      if (!array_key_exists($situacao, $this->situations())) $situacao = '';

      $this->session->set_userdata(self::FILTRO_AVANCADO, [
        'situation' => $situacao,
        'vencimento_de' => $this->dataFiltro(isset($avancado['vencimento_de']) ? $avancado['vencimento_de'] : ''),
        'vencimento_ate' => $this->dataFiltro(isset($avancado['vencimento_ate']) ? $avancado['vencimento_ate'] : ''),
      ]);
    }

    redirect(base_url('faturas'));
  }

  /**
   * Situação derivada na view `crm_invoices_v` — a mesma que colore o badge.
   *
   * @return array slug => rótulo
   */
  private function situations()
  {
    return [
      'a_vencer' => 'A vencer',
      'vencida' => 'Vencida',
      'paga' => 'Paga',
      'cancelada' => 'Cancelada',
    ];
  }

  /**
   * @return void
   */
  private function setDefaultInvoiceFilter()
  {
    $filtros = $this->session->userdata('f_invoices');
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('keyword', $filtros)) $filtros['keyword'] = '';

    // Reatribuído sempre (e não sob array_key_exists): mudar os campos de busca
    // no código passa a valer na hora, sem depender de limpar a sessão.
    $filtros['keyword_search'] = ['customer_name', 'customer_byname', 'customer_document', 'description'];

    $this->session->set_userdata('f_invoices', $filtros);
  }

  /**
   * As condições que o filtro genérico não sabe montar.
   *
   * @return string
   */
  private function montarWhereListagem()
  {
    $where = 'id_company = ' . (int) $this->getCurrentCompanyId();

    $avancado = (array) $this->session->userdata(self::FILTRO_AVANCADO);

    $situacao = isset($avancado['situation']) ? (string) $avancado['situation'] : '';
    if (array_key_exists($situacao, $this->situations())) {
      $where .= " AND situation = '" . $this->db->escape_str($situacao) . "'";
    }

    $de = isset($avancado['vencimento_de']) ? (string) $avancado['vencimento_de'] : '';
    if ($de !== '') {
      $where .= " AND due_date >= '" . $this->db->escape_str($de) . "'";
    }

    $ate = isset($avancado['vencimento_ate']) ? (string) $avancado['vencimento_ate'] : '';
    if ($ate !== '') {
      $where .= " AND due_date <= '" . $this->db->escape_str($ate) . "'";
    }

    return $where;
  }

  /**
   * Somatório do que o filtro devolveu — a pergunta "quanto tem a receber" é a
   * primeira que se faz numa lista de faturas, e a paginação esconde a resposta.
   *
   * Consulta própria com binds, e não os helpers do Global_model: eles
   * aplicariam de novo o filtro de sessão sobre uma string de WHERE que já o
   * contém.
   *
   * @param  string $where
   * @return array
   */
  private function totaisDoFiltro($where)
  {
    $keyword = '';
    $filtros = (array) $this->session->userdata('f_invoices');
    if (isset($filtros['keyword'])) $keyword = trim((string) $filtros['keyword']);

    $sql = "SELECT COUNT(*) AS quantidade,
                   COALESCE(SUM(value), 0) AS total,
                   COALESCE(SUM(CASE WHEN situation = 'vencida' THEN value ELSE 0 END), 0) AS vencido
              FROM crm_invoices_v
             WHERE " . $where;

    $binds = [];
    if ($keyword !== '') {
      $sql .= " AND (customer_name LIKE ? OR customer_byname LIKE ? OR customer_document LIKE ? OR description LIKE ?)";
      $termo = '%' . $keyword . '%';
      $binds = [$termo, $termo, $termo, $termo];
    }

    $consulta = $this->db->query($sql, $binds);
    if ($consulta === FALSE) {
      return ['quantidade' => 0, 'total' => 0, 'vencido' => 0];
    }

    $linha = $consulta->row();

    return [
      'quantidade' => (int) $linha->quantidade,
      'total' => (float) $linha->total,
      'vencido' => (float) $linha->vencido,
    ];
  }

  // ------------------------------------------------------------------
  // Manutenção
  // ------------------------------------------------------------------

  /**
   * Baixa manual e cancelamento.
   *
   * A baixa automática (conferir no ERP o que foi pago) é etapa futura; até
   * lá, marcar como paga aqui é o único caminho — e por isso a ação existe
   * desde já, mesmo simples.
   */
  public function post_status()
  {
    $id = (int) $this->input->post('id');
    $acao = (string) $this->input->post('acao');

    $fatura = $this->global_model->getWhere_off('crm_invoices', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($fatura)) {
      $this->session->set_flashdata('warning', 'Fatura não encontrada.');
      redirect(base_url('faturas'));
    }

    // Allowlist de transições, no mesmo idioma de Contratos::statusTransicoes():
    // um toggle cego devolveria "cancelada → paga" sem que ninguém pedisse.
    $transicoes = [
      'pagar' => ['de' => 'aberta', 'para' => 'paga', 'ok' => 'Fatura baixada como paga.'],
      'reabrir' => ['de' => 'paga', 'para' => 'aberta', 'ok' => 'Baixa desfeita.'],
      'cancelar' => ['de' => 'aberta', 'para' => 'cancelada', 'ok' => 'Fatura cancelada.'],
    ];

    if (!array_key_exists($acao, $transicoes)) {
      $this->session->set_flashdata('warning', 'Ação inválida.');
      redirect(base_url('faturas'));
    }

    if ((string) $fatura->status !== $transicoes[$acao]['de']) {
      $this->session->set_flashdata('warning', 'Esta fatura não está em situação de "' . $acao . '".');
      redirect(base_url('faturas'));
    }

    $this->global_model->edit('crm_invoices', [
      'status' => $transicoes[$acao]['para'],
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => (int) $this->session->userdata('user')->id,
    ], 'id', $id);

    $this->session->set_flashdata('success', $transicoes[$acao]['ok']);
    redirect(base_url('faturas'));
  }

  // ------------------------------------------------------------------
  // Apoio
  // ------------------------------------------------------------------

  /**
   * dd/mm/aaaa do filtro → Y-m-d. Entrada inválida vira '' (filtro ignorado),
   * e não uma data de 1970 que esvaziaria a listagem sem explicação.
   *
   * @param  string $texto
   * @return string
   */
  private function dataFiltro($texto)
  {
    $texto = trim((string) $texto);
    if ($texto === '') return '';

    $data = DateTime::createFromFormat('d/m/Y', $texto);
    if (!$data || $data->format('d/m/Y') !== $texto) return '';

    return $data->format('Y-m-d');
  }

  /**
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
      'uri_segment' => 3,
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
