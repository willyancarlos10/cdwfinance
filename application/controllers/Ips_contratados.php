<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * IPs contratados — inventário dos endereços IP da operação.
 *
 * Cadastro mínimo (endereço, cliente OPCIONAL e observações) e a tela que responde às
 * duas perguntas do dia a dia: quantos IPs ainda estão livres, e de quem é este IP.
 *
 * **"Alocado" é TER CLIENTE VINCULADO** — não há coluna de situação, e por isso não há
 * como os cartões do topo discordarem da tabela logo abaixo. Ver o docblock da
 * migration 043.
 *
 * Diferente de Tipos_servicos e Motivos_cancelamento, que são catálogos GLOBAIS e por
 * isso travados na empresa master, o IP é dado operacional do TENANT: o escopo vem de
 * getCurrentCompanyId() em todas as consultas e o módulo fica no MENU PRINCIPAL.
 *
 * URLs com hífen resolvem sem rota: translate_uri_dashes = TRUE em config/routes.php
 * ('ips-contratados' -> Ips_contratados).
 */
class Ips_contratados extends MY_Controller
{
  /** Registros por página na listagem. */
  const PER_PAGE = 30;

  /**
   * Ordenação numérica pelo endereço.
   *
   * `ip_long` é `INET_ATON(ip)` derivado na `crm_ips_v`. Ordenar o varchar puro põe
   * `10.0.0.10` antes de `10.0.0.9`, e escrever a função aqui não resolve: o
   * `protect_identifiers` do CI3 quebra a string do order_by e escaparia
   * `INET_ATON(ip)` como se fosse nome de coluna.
   */
  const ORDENACAO = 'ip_long asc';

  /** Chave de sessão do filtro da listagem. */
  const FILTRO = 'f_ips';

  /** Teto da coluna `comments`, repetido no maxlength do formulário. */
  const TAMANHO_OBSERVACOES = 500;

  public function __construct()
  {
    parent::__construct();
    $this->data['menu'] = 'ips-contratados';
  }

  // ------------------------------------------------------------------
  // Listagem
  // ------------------------------------------------------------------

  public function index()
  {
    $this->listagem();
  }

  /**
   * A URL precisa ter DOIS segmentos (`ips-contratados/listagem`): a paginação lê o
   * offset de `$this->uri->segment(3)`, e com um segmento só o número da página cairia
   * no segmento do método.
   */
  public function listagem()
  {
    $this->setDefaultIpFilter();

    $where = 'id_company = ' . (int) $this->getCurrentCompanyId();

    $config = $this->paginationConfig(
      base_url('ips-contratados/listagem'),
      $this->global_model->getCountW($where, 'crm_ips_v', self::FILTRO)
    );
    $this->pagination->initialize($config);

    $this->data['results'] = $this->global_model->getListW(
      $where,
      'crm_ips_v',
      self::FILTRO,
      self::ORDENACAO,
      '',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['total_results'] = $config['total_rows'];
    $this->data['resumo'] = $this->contarIps();
    $this->data['situacoes'] = $this->situacoes();
    $this->data['customers'] = $this->clientesDoTenant();

    $this->load->view('header', $this->data);
    $this->load->view('ips/list', $this->data);
    $this->load->view('footer', $this->data);
  }

  public function editar()
  {
    $id = (int) $this->input->get('id');

    $this->data['result'] = $this->global_model->getWhere_off('crm_ips_v', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($this->data['result'])) {
      $this->session->set_flashdata('warning', 'IP não encontrado.');
      redirect(base_url('ips-contratados'));
    }

    $this->data['customers'] = $this->clientesDoTenant();

    $this->load->view('header', $this->data);
    $this->load->view('ips/edit', $this->data);
    $this->load->view('footer', $this->data);
  }

  // ------------------------------------------------------------------
  // Filtro
  // ------------------------------------------------------------------

  public function post_filtrar()
  {
    if ($this->input->post('acao') === 'limpar') {
      $this->session->unset_userdata(self::FILTRO);
      redirect(base_url('ips-contratados'));
    }

    $filtro = (array) $this->input->post(self::FILTRO);
    if (empty($filtro)) redirect(base_url('ips-contratados'));

    // Situação vem de allowlist, como a situação contratual em Clientes::post_filtrar:
    // valor desconhecido viraria `WHERE situation = 'qualquer coisa'` e zeraria a tela.
    if (array_key_exists('situation', $filtro)) {
      $situacao = trim((string) $filtro['situation']);
      $filtro['situation'] = array_key_exists($situacao, $this->situacoes()) ? $situacao : '';
    }

    $array = array_merge((array) $this->session->userdata(self::FILTRO), $filtro);
    $this->session->set_userdata(self::FILTRO, $array);

    redirect(base_url('ips-contratados'));
  }

  /**
   * A chave precisa EXISTIR antes de getCountW/getListW: o Global_model::getFilter()
   * faz `foreach ($this->session->userdata($filter) ...)` e uma chave inexistente vira
   * foreach sobre NULL.
   *
   * `situation` é chave simples e o getFilter() já a traduz em `WHERE situation = x` —
   * é para caber aqui que ela é coluna derivada da view.
   *
   * ATENÇÃO: NÃO copiar daqui a normalização de documento de Clientes::post_filtrar.
   * Lá, keyword que case /^[0-9.\/\- ]+$/ é reduzida a dígitos porque
   * crm_customers.document guarda só dígitos — e um IPv4 casa exatamente esse regex:
   * "10.0.0.1" viraria "10001" e a busca por IP, que é a busca principal desta tela,
   * pararia de funcionar.
   */
  private function setDefaultIpFilter()
  {
    $filtros = $this->session->userdata(self::FILTRO);
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('keyword', $filtros)) $filtros['keyword'] = '';
    if (!array_key_exists('situation', $filtros)) $filtros['situation'] = '';

    $filtros['keyword_search'] = ['ip', 'comments', 'customer_name', 'customer_byname', 'customer_document'];

    $this->session->set_userdata(self::FILTRO, $filtros);
  }

  /** Catálogo das situações — derivadas do vínculo, nunca gravadas. */
  private function situacoes()
  {
    return [
      'alocado' => 'Alocados',
      'disponivel' => 'Disponíveis',
    ];
  }

  // ------------------------------------------------------------------
  // Cartões do topo
  // ------------------------------------------------------------------

  /**
   * Query direta com bind, e NÃO pelos helpers do Global_model, de propósito: eles
   * aplicariam o filtro gravado na sessão e os cartões passariam a refletir a última
   * busca em vez do inventário — o mesmo motivo de Monitoramento::contarPorSituacao()
   * e dos indicadores do Dashboard.
   *
   * As duas pontas são AFIRMATIVAS, nunca `total - alocados`: uma subtração absorve em
   * silêncio qualquer estado novo, que é o defeito que o contador de contratos
   * suspensos do Dashboard teve.
   *
   * @return array total | alocados | disponiveis
   */
  private function contarIps()
  {
    $linha = $this->db->query(
      'SELECT COUNT(*) AS `total`,
              SUM(CASE WHEN `id_customer` IS NOT NULL THEN 1 ELSE 0 END) AS `alocados`,
              SUM(CASE WHEN `id_customer` IS NULL THEN 1 ELSE 0 END) AS `disponiveis`
         FROM `crm_ips` WHERE `id_company` = ?',
      [(int) $this->getCurrentCompanyId()]
    )->row();

    return [
      'total' => empty($linha) ? 0 : (int) $linha->total,
      'alocados' => empty($linha) ? 0 : (int) $linha->alocados,
      'disponiveis' => empty($linha) ? 0 : (int) $linha->disponiveis,
    ];
  }

  // ------------------------------------------------------------------
  // Gravação
  // ------------------------------------------------------------------

  /**
   * Cria e edita: com `id` no POST é edição, como em Contratos::post_salvardominio.
   */
  public function post_salvar()
  {
    $id = (int) $this->input->post('id');
    $idCompany = (int) $this->getCurrentCompanyId();

    // Na criação o erro volta para a ABA do formulário (#tab-2), e não para a
    // listagem: o campo a corrigir está lá. A âncora é lida pelo JS da list.php.
    $volta = ($id > 0)
      ? base_url('ips-contratados/editar?id=' . $id)
      : base_url('ips-contratados') . '#tab-2';

    // Em edição, o registro tem de ser deste tenant — o id do POST nunca é fonte de
    // verdade sobre a quem a linha pertence.
    if ($id > 0) {
      $existe = $this->global_model->getWhere_off('crm_ips', ['id' => $id, 'id_company' => $idCompany], TRUE);
      if (empty($existe)) {
        $this->session->set_flashdata('warning', 'IP não encontrado.');
        redirect(base_url('ips-contratados'));
      }
    }

    // filter_var normaliza e devolve o endereço; grava-se o RETORNO, nunca o POST.
    // Ele recusa 10.0.0.256, 1.2.3, IPv6, hostname e o octeto com zero à esquerda
    // (010.1.1.1) — que entraria no varchar como texto diferente e criaria uma segunda
    // linha para o mesmo endereço, furando a UNIQUE por dentro.
    $ip = filter_var(trim((string) $this->input->post('ip')), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($ip === FALSE) {
      $this->session->set_flashdata('warning', 'Informe um endereço IPv4 válido, de 0.0.0.0 a 255.255.255.255. Não use zero à esquerda: digite 10.0.0.9, e não 10.0.0.09.');
      redirect($volta);
    }

    // A UNIQUE (id_company, ip) é a garantia; esta checagem só troca o erro seco 1062
    // por uma mensagem na tela — mesmo desenho de Tipos_servicos.
    $existente = $this->global_model->getWhere_off('crm_ips', ['id_company' => $idCompany, 'ip' => $ip], TRUE);
    if (!empty($existente) && (int) $existente->id !== $id) {
      $this->session->set_flashdata('warning', 'O IP ' . $ip . ' já está cadastrado nesta empresa.');
      redirect($volta);
    }

    // Vínculo OPCIONAL, mas revalidado no servidor contra o tenant: id de outro tenant
    // é ERRO, e nunca "grava sem vínculo em silêncio" — o operador precisa saber que o
    // cliente que ele escolheu não foi gravado.
    $idCustomer = (int) $this->input->post('id_customer');
    if ($idCustomer > 0) {
      $cliente = $this->global_model->getWhere_off('crm_customers', ['id' => $idCustomer, 'id_company' => $idCompany], TRUE);
      if (empty($cliente)) {
        $this->session->set_flashdata('error', 'Cliente inválido para esta empresa.');
        redirect($volta);
      }
    }

    $agora = date('Y-m-d H:i:s');
    $idUser = (int) $this->session->userdata('user')->id;

    $campos = [
      'ip' => $ip,
      'id_customer' => ($idCustomer > 0) ? $idCustomer : NULL,
      'comments' => mb_substr(trim((string) $this->input->post('comments')), 0, self::TAMANHO_OBSERVACOES),
      'modified' => $agora,
      'modified_by' => $idUser,
    ];

    if ($id > 0) {
      $this->global_model->edit('crm_ips', $campos, 'id', $id);
      $this->session->set_flashdata('success', 'IP atualizado com sucesso.');
      redirect(base_url('ips-contratados'));
    }

    $this->global_model->add('crm_ips', array_merge($campos, [
      'id_company' => $idCompany,
      'created' => $agora,
      'created_by' => $idUser,
    ]));

    $this->session->set_flashdata('success', 'IP cadastrado com sucesso.');
    redirect(base_url('ips-contratados'));
  }

  /**
   * Exclusão definitiva. Não há vínculo a proteger: nenhuma tabela referencia o IP, e
   * o cliente aponta para cá, não o contrário.
   */
  public function post_excluir()
  {
    $id = (int) $this->input->post('id');

    $ip = $this->global_model->getWhere_off('crm_ips', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($ip)) {
      $this->session->set_flashdata('warning', 'IP não encontrado.');
      redirect(base_url('ips-contratados'));
    }

    $this->global_model->delete('crm_ips', 'id', $id);

    $this->session->set_flashdata('success', 'IP ' . $ip->ip . ' excluído com sucesso.');
    redirect(base_url('ips-contratados'));
  }

  // ------------------------------------------------------------------
  // Apoio
  // ------------------------------------------------------------------

  /**
   * Clientes do tenant para o select do cadastro.
   *
   * `crm_customers` não tem id_status desde a migration 015 — quem diz se o cliente
   * está ativo é ter contrato vigente —, então não há recorte a fazer aqui.
   */
  private function clientesDoTenant()
  {
    return $this->global_model->getFieldsWhere_off(
      'crm_customers',
      'id, name, byname, document, type',
      ['id_company' => (int) $this->getCurrentCompanyId()],
      'name',
      'asc',
      FALSE
    );
  }

  /**
   * Paginação no padrão Bootstrap 5 das demais listagens.
   *
   * Os `*_tag_open` das páginas navegáveis fecham no `<li>` e NÃO abrem um
   * `<span class="page-link">`: o CI3 já emite o `<a>` com a classe vinda de
   * `attributes`, e envolvê-lo num span de mesma classe aninha dois `.page-link` —
   * borda e padding duplicados. `cur_tag_*` é a única exceção legítima, porque a
   * página atual não vira link e precisa de alguém para carregar a classe.
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
