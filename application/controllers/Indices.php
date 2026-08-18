<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Lançamento mensal dos índices de reajuste (IGP-M, IPCA, ICTI).
 *
 * Catálogo GLOBAL, sem `id_company`: o IGP-M de março é o mesmo para todo
 * mundo. Por isso a tela vive em GESTÃO e só a empresa master acessa, no mesmo
 * desenho de Tipos de serviços.
 *
 * A alimentação é manual porque não há origem automática que cubra os três:
 * IGP-M e IPCA têm série pública no SGS do Banco Central, mas o **ICTI** não —
 * e uma tela que só servisse para dois dos três índices deixaria o terceiro
 * sem caminho nenhum.
 *
 * Guarda a variação MENSAL, nunca o acumulado: a janela de doze meses varia por
 * contrato (o aniversário de cada um cai num mês), então é o dado mensal que
 * permite calcular qualquer janela.
 */
class Indices extends MY_Controller
{
  /** Registros por página. */
  const PER_PAGE = 36;

  public function __construct()
  {
    parent::__construct();

    if ($this->session->userdata('company')->id != 1) {
      $this->session->set_flashdata('warning', 'Sem permissão de acesso.');
      redirect(base_url());
    }

    $this->data['menu'] = 'indices';
    $this->load->model('adjustment_model');
  }

  public function index()
  {
    $this->listagem();
  }

  public function listagem()
  {
    $indices = $this->adjustment_model->indexes();
    $this->setDefaultIndexFilter();

    $filtros = (array) $this->session->userdata('f_indices');
    $filtro = isset($filtros['index_slug']) ? (string) $filtros['index_slug'] : '';

    // `index_slug` é chave simples no filtro de sessão, então o Global_model já
    // a traduz em `WHERE index_slug = <valor>` — não precisa de where à mão.
    $where = '1 = 1';

    $config = $this->paginationConfig(
      base_url('indices/listagem'),
      $this->global_model->getCountW($where, 'crm_adjustment_indexes', 'f_indices')
    );
    $this->pagination->initialize($config);

    // Ordenação composta no campo, com direção vazia: o order_by do CI3 quebra
    // a string na vírgula e escaparia "index_slug asc" como nome de coluna.
    $this->data['results'] = $this->global_model->getListW(
      $where,
      'crm_adjustment_indexes',
      'f_indices',
      'competence desc, index_slug asc',
      '',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['total_results'] = $config['total_rows'];
    $this->data['indexes'] = $indices;
    $this->data['filtro_slug'] = $filtro;
    $this->data['lacunas'] = $this->lacunasEmUso($indices);

    $this->load->view('header', $this->data);
    $this->load->view('indexes/list', $this->data);
    $this->load->view('footer', $this->data);
  }

  public function post_filtrar()
  {
    $slug = (string) $this->input->post('index_slug');
    if (!array_key_exists($slug, $this->adjustment_model->indexes())) $slug = '';

    $this->session->set_userdata('f_indices', ['index_slug' => $slug]);
    redirect(base_url('indices'));
  }

  /**
   * @return void
   */
  private function setDefaultIndexFilter()
  {
    $filtros = $this->session->userdata('f_indices');
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('index_slug', $filtros)) $filtros['index_slug'] = '';

    $this->session->set_userdata('f_indices', $filtros);
  }

  /**
   * Lança ou atualiza a variação de um mês.
   *
   * Upsert pela UNIQUE (index_slug, competence): relançar o mesmo mês corrige o
   * valor em vez de estourar erro de chave duplicada — índice é republicado com
   * frequência.
   */
  public function post_salvar()
  {
    $post = (array) $this->input->post('indice');

    $slug = isset($post['index_slug']) ? (string) $post['index_slug'] : '';
    if (!array_key_exists($slug, $this->adjustment_model->indexes())) {
      $this->session->set_flashdata('warning', 'Selecione um índice válido.');
      redirect(base_url('indices'));
    }

    $competencia = $this->competenciaDoPost(isset($post['competence']) ? $post['competence'] : '');
    if ($competencia === FALSE) {
      redirect(base_url('indices'));
    }

    $texto = trim((string) (isset($post['rate']) ? $post['rate'] : ''));
    if ($texto === '') {
      $this->session->set_flashdata('warning', 'Informe a variação do mês.');
      redirect(base_url('indices'));
    }

    // Aceita vírgula (é como se digita em PT-BR) e o sinal negativo, porque
    // deflação existe e recusá-la obrigaria a inventar um número.
    $rate = (float) str_replace(',', '.', str_replace('%', '', $texto));
    if ($rate < -100 || $rate > 100) {
      $this->session->set_flashdata('warning', 'A variação mensal deve estar entre -100% e 100%.');
      redirect(base_url('indices'));
    }

    $idUser = (int) $this->session->userdata('user')->id;
    $agora = date('Y-m-d H:i:s');

    $existente = $this->global_model->getFieldsWhereSingle_off(
      'crm_adjustment_indexes',
      'id',
      ['index_slug' => $slug, 'competence' => $competencia],
      TRUE
    );

    if (!empty($existente)) {
      $this->global_model->edit('crm_adjustment_indexes', [
        'rate' => $rate,
        'modified' => $agora,
        'modified_by' => $idUser,
      ], 'id', (int) $existente->id);

      $this->session->set_flashdata('success', 'Variação atualizada.');
    } else {
      $this->global_model->add('crm_adjustment_indexes', [
        'index_slug' => $slug,
        'competence' => $competencia,
        'rate' => $rate,
        'created' => $agora,
        'created_by' => $idUser,
      ]);

      $this->session->set_flashdata('success', 'Variação lançada.');
    }

    redirect(base_url('indices'));
  }

  public function post_excluir()
  {
    $id = (int) $this->input->post('id');

    $linha = $this->global_model->getWhere_off('crm_adjustment_indexes', ['id' => $id], TRUE);
    if (empty($linha)) {
      $this->session->set_flashdata('warning', 'Lançamento não encontrado.');
      redirect(base_url('indices'));
    }

    $this->global_model->delete('crm_adjustment_indexes', 'id', $id);

    $this->session->set_flashdata('success', 'Lançamento excluído.');
    redirect(base_url('indices'));
  }

  /**
   * Meses faltando nos índices que algum contrato ativo usa.
   *
   * O reajuste PULA o contrato quando a janela está incompleta, e sem este
   * aviso a lacuna só apareceria no log do cron — depois de o reajuste não ter
   * acontecido.
   *
   * @param  array $indices
   * @return array slug => ['rotulo', 'faltando' => [...]]
   */
  private function lacunasEmUso(array $indices)
  {
    $consulta = $this->db->query(
      "SELECT DISTINCT adjustment_index
         FROM crm_contracts
        WHERE status = 'vigente'
          AND adjustment_index <> 'nenhum'
          AND billing_source = 'cdwfinance'"
    );

    if ($consulta === FALSE) return [];

    $lacunas = [];

    foreach ($consulta->result() as $linha) {
      $slug = (string) $linha->adjustment_index;
      if (!array_key_exists($slug, $indices)) continue;

      // A janela de quem reajustar hoje: os doze meses já encerrados.
      $resultado = $this->adjustment_model->acumulado($slug, date('Y-m-d'));
      if (!empty($resultado['faltando'])) {
        $lacunas[$slug] = [
          'rotulo' => $indices[$slug],
          'faltando' => $resultado['faltando'],
        ];
      }
    }

    return $lacunas;
  }

  /**
   * mm/aaaa → primeiro dia do mês.
   *
   * @param  string $texto
   * @return string|bool FALSE quando inválido (flashdata já definido)
   */
  private function competenciaDoPost($texto)
  {
    $texto = trim((string) $texto);

    if (!preg_match('/^(\d{2})\/(\d{4})$/', $texto, $partes)) {
      $this->session->set_flashdata('warning', 'Informe a competência no formato mm/aaaa.');
      return FALSE;
    }

    $mes = (int) $partes[1];
    $ano = (int) $partes[2];

    if ($mes < 1 || $mes > 12 || $ano < 2000 || $ano > ((int) date('Y') + 1)) {
      $this->session->set_flashdata('warning', 'Competência fora do intervalo aceito.');
      return FALSE;
    }

    return sprintf('%04d-%02d-01', $ano, $mes);
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
