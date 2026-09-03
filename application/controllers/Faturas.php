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
    $this->data['origins'] = $this->origins();

    // Catálogo do PSP para o badge de registro e para o modal de troca. Só os
    // provedores com credencial ATIVA entram nas opções — oferecer um sem
    // credencial produziria uma troca que falha na hora de registrar.
    $this->load->model('psp_model');
    $this->data['registration_labels'] = $this->psp_model->registrationLabels();
    $this->data['psp_rotulos'] = $this->psp_model->rotulos();
    $this->data['psp_disponiveis'] = [];
    foreach ($this->psp_model->rotulos() as $pspSlug => $pspNome) {
      if ($this->psp_model->isActive((int) $this->getCurrentCompanyId(), $pspSlug)) {
        $this->data['psp_disponiveis'][$pspSlug] = $pspNome;
      }
    }
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

      // Valor fora da allowlist vira "todas" em vez de entrar no WHERE — o
      // slug é concatenado na string de $where, não passa por bind.
      $origem = isset($avancado['origin']) ? (string) $avancado['origin'] : '';
      if (!array_key_exists($origem, $this->origins())) $origem = '';

      $this->session->set_userdata(self::FILTRO_AVANCADO, [
        'situation' => $situacao,
        'origin' => $origem,
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
   * De onde a fatura veio: da recorrência do contrato ou de uma cobrança
   * avulsa.
   *
   * O dado já existe em `crm_invoices.id_charge` — **sentinela 0** para a
   * recorrência, id da cobrança para a avulsa (migration 031) —, então isto é
   * só o vocabulário da tela e a allowlist do filtro. Não virou coluna
   * derivada na `crm_invoices_v` porque a condição é trivial (`> 0`) e a view
   * já expõe o `id_charge`: uma coluna a mais seria paga por toda listagem e
   * por toda contagem de paginação para não responder nada novo.
   *
   * @return array slug => rótulo
   */
  private function origins()
  {
    return [
      'recorrencia' => 'Recorrência',
      'avulsa' => 'Avulsa',
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

    // `id_charge` precisa de `= 0` e `> 0`, e o `Global_model::getFilter()` só
    // faz `campo = valor` — e descartaria o zero de qualquer forma, porque
    // pula toda chave de valor vazio e `empty('0')` é TRUE. Por isso vive no
    // FILTRO_AVANCADO e é traduzido aqui, como a situação e o período.
    $origem = isset($avancado['origin']) ? (string) $avancado['origin'] : '';
    if ($origem === 'recorrencia') {
      $where .= ' AND id_charge = 0';
    } elseif ($origem === 'avulsa') {
      $where .= ' AND id_charge > 0';
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
  /**
   * Registra ou atualiza a cobrança de uma fatura no PSP, sob demanda.
   *
   * É o mesmo caminho do cron, para uma fatura só — o botão existe porque
   * esperar a próxima rodada para descobrir por que uma cobrança não saiu é
   * caro quando o cliente está no telefone.
   *
   * As duas ações vivem no mesmo endpoint porque, do ponto de vista de quem
   * clica, a pergunta é uma só: "resolve essa cobrança". Qual das duas rodar é
   * decidido pelo estado da fatura, não pelo usuário.
   */
  /**
   * Texto seguro para o flashdata.
   *
   * O header.php injeta a mensagem dentro de uma string JS entre aspas duplas
   * SEM escapar — uma aspa vinda da resposta de um PSP quebraria o alerta
   * justamente no caso em que ele importa. Mesmo desenho do
   * Contratos::textoParaFlash().
   *
   * @param  string $texto
   * @return string
   */
  private function textoParaFlash($texto)
  {
    $limpo = strip_tags((string) $texto);
    $limpo = str_replace(['\\', '"'], ['/', "'"], $limpo);
    $limpo = preg_replace('/\s+/u', ' ', $limpo);

    return trim(mb_substr((string) $limpo, 0, 300));
  }

  public function json_postcobranca()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $idCompany = (int) $this->getCurrentCompanyId();

    $fatura = $this->global_model->getWhere_off('crm_invoices', [
      'id' => $id,
      'id_company' => $idCompany,
    ], TRUE);

    if (empty($fatura)) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Fatura não encontrada.',
        'data' => NULL,
        'errors' => ['fatura' => 'Fatura não encontrada.'],
      ]);
      return;
    }

    $this->load->model('psp_model');

    // MESMA REGRA do cron e do GERAR FATURA, recortada nesta fatura. Registrar
    // e consultar não são escolha de quem clica: a regra decide pelo estado da
    // fatura, e a segunda fase já apanha o que a primeira acabou de registrar
    // — por isso um clique só basta, mesmo com a emissão sendo assíncrona.
    $processo = $this->psp_model->processarPendentes([
      'id_user' => (int) $this->session->userdata('user')->id,
      'id_company' => $idCompany,
      'id_invoice' => $id,
      'limite' => Psp_model::MAX_COBRANCAS_NA_TELA,
      'orcamento' => Psp_model::ORCAMENTO_COBRANCAS_TELA_SEGUNDOS,
    ]);

    // Quem responde "deu certo?" é o ESTADO DA FATURA depois do processo, não
    // o retorno intermediário: é ele que a tela vai mostrar, e é ele que
    // sobrevive a uma falha parcial.
    $atual = $this->global_model->getWhere_off('crm_invoices', [
      'id' => $id,
      'id_company' => $idCompany,
    ], TRUE);

    $pronta = !empty($atual)
      && (trim((string) $atual->linha_digitavel) !== '' || trim((string) $atual->link_pix) !== '');

    if ($pronta) {
      $mensagem = 'Cobrança pronta — boleto e PIX disponíveis.';
    } elseif ((int) $processo['falhas'] > 0) {
      $mensagem = implode('; ', $processo['mensagens']);
    } elseif (!empty($atual) && trim((string) $atual->psp_charge_id) !== '') {
      // Registrada, sem boleto ainda: é o estado normal da emissão assíncrona,
      // e não um erro — dizer "falhou" aqui mandaria o usuário tentar de novo
      // sem necessidade.
      $mensagem = 'Cobrança registrada no banco; o boleto ainda está sendo gerado. Tente atualizar em instantes.';
    } else {
      $mensagem = 'Não foi possível registrar a cobrança agora. A próxima rodada do cron tenta de novo.';
    }

    $sucesso = $pronta || ((int) $processo['falhas'] === 0);

    // Com o que mostrar, a tela recarrega — e um toast disparado pelo JS
    // morreria nesse reload. A mensagem vai por flashdata, o mesmo canal do
    // resto do sistema. O escape é obrigatório: o header.php injeta o texto
    // numa string JS entre aspas duplas SEM escapar, e a resposta de um PSP
    // pode conter aspas.
    if ($pronta) {
      $this->session->set_flashdata('success', $this->textoParaFlash($mensagem));
    }

    echo json_encode([
      'success' => $sucesso,
      'return' => $sucesso,
      'message' => $mensagem,
      'data' => ['pronta' => $pronta],
      'errors' => $sucesso ? [] : ['fatura' => $mensagem],
    ]);
  }
  /**
   * Troca o provedor desta fatura e força o registro da cobrança.
   *
   * Mandar o MESMO provedor é caminho válido e é o "forçar registrar": a regra
   * do model decide o que fazer pelo estado da fatura, então não há duas ações
   * a distinguir na tela.
   */
  public function json_posttrocarpsp()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $psp = trim((string) $this->input->post('psp'));
    $idCompany = (int) $this->getCurrentCompanyId();

    $this->load->model('psp_model');

    $resultado = $this->psp_model->trocarPsp(
      $id,
      $idCompany,
      $psp,
      (int) $this->session->userdata('user')->id
    );

    // O estado da fatura DEPOIS do processo é quem responde "deu certo?" — é
    // ele que a tela vai mostrar e o que sobrevive a uma falha parcial.
    $atual = $this->global_model->getWhere_off('crm_invoices', [
      'id' => $id,
      'id_company' => $idCompany,
    ], TRUE);

    $pronta = !empty($atual)
      && (trim((string) $atual->linha_digitavel) !== '' || trim((string) $atual->link_pix) !== '');

    // Com o que mostrar, a tela recarrega — e um toast morreria nesse reload.
    // textoParaFlash porque o header.php injeta o texto numa string JS entre
    // aspas duplas SEM escapar, e aqui a mensagem carrega a resposta do banco.
    if ($pronta) {
      $this->session->set_flashdata('success', $this->textoParaFlash((string) $resultado['message']));
    }

    echo json_encode([
      'success' => !empty($resultado['success']),
      'return' => !empty($resultado['success']),
      'message' => (string) $resultado['message'],
      'data' => ['pronta' => $pronta],
      'errors' => !empty($resultado['success']) ? [] : ['fatura' => (string) $resultado['message']],
    ]);
  }
  /**
   * Garante que o boleto desta fatura está disponível, buscando no provedor se
   * ainda não estiver guardado.
   *
   * É chamado ANTES de abrir o modal, e não pelo próprio visualizador: se a
   * busca falhasse dentro do iframe, o usuário veria uma página de erro do
   * navegador no lugar do boleto, sem explicação nem caminho de volta. Aqui a
   * falha vira mensagem.
   */
  public function json_postboleto()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $idCompany = (int) $this->getCurrentCompanyId();

    $this->load->model('psp_model');
    $resultado = $this->psp_model->obterBoleto($id, $idCompany, (int) $this->session->userdata('user')->id);

    echo json_encode([
      'success' => !empty($resultado['success']),
      'return' => !empty($resultado['success']),
      'message' => (string) $resultado['message'],
      // O conteúdo NÃO volta no JSON: um PDF de 90 KB viraria ~120 KB de
      // base64 dentro do HTML da página, para depois ser reconvertido em JS.
      // O modal aponta para o endpoint de streaming, que serve do banco.
      'data' => [
        'bytes' => (int) ($resultado['data']['bytes'] ?? 0),
        'do_cache' => !empty($resultado['data']['do_cache']),
      ],
      'errors' => !empty($resultado['success']) ? [] : ['boleto' => (string) $resultado['message']],
    ]);
  }

  /**
   * Entrega o PDF do boleto.
   *
   * Só o ID vem da requisição — o conteúdo sai da linha do banco, escopada
   * pelo tenant. Nunca aceitar caminho ou nome de arquivo do POST/GET é a
   * mesma regra da exclusão de anexos do cliente.
   *
   * @param int $id
   * @param string $modo 'download' força salvar; qualquer outra coisa abre inline
   */
  public function boleto($id = 0, $modo = '')
  {
    $id = (int) $id;
    $idCompany = (int) $this->getCurrentCompanyId();

    $this->load->model('psp_model');
    $resultado = $this->psp_model->obterBoleto($id, $idCompany, (int) $this->session->userdata('user')->id);

    if (empty($resultado['success'])) {
      // Texto puro, e não uma view: quem chega aqui é um iframe ou uma aba
      // nova, e uma tela do painel dentro do visualizador de PDF só confundiria.
      $this->output
        ->set_status_header(404)
        ->set_content_type('text/plain', 'utf-8')
        ->set_output((string) $resultado['message']);
      return;
    }

    $pdf = base64_decode((string) $resultado['data']['content'], TRUE);
    if ($pdf === FALSE || $pdf === '') {
      $this->output
        ->set_status_header(500)
        ->set_content_type('text/plain', 'utf-8')
        ->set_output('O arquivo guardado do boleto está ilegível. Use "registrar / trocar" para reemitir a cobrança.');
      return;
    }

    // Nome pensado para quem baixa: o id do PSP não diz nada depois de salvo
    // na pasta de downloads.
    $fatura = $this->global_model->getWhere_off('crm_invoices', [
      'id' => $id,
      'id_company' => $idCompany,
    ], TRUE);

    $nome = 'boleto-' . $id
      . (!empty($fatura) ? '-' . date('m-Y', strtotime((string) $fatura->competence)) : '')
      . '.pdf';

    $disposicao = ($modo === 'download') ? 'attachment' : 'inline';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposicao . '; filename="' . $nome . '"');
    header('Content-Length: ' . strlen($pdf));
    // Boleto é documento com dado do cliente: não fica em cache de proxy.
    header('Cache-Control: private, no-store');
    header('Pragma: no-cache');

    echo $pdf;
  }
  /**
   * Derruba a cobrança de uma fatura no banco — o passo obrigatório antes de
   * cancelá-la.
   *
   * Extraído porque DUAS vias cancelam: a tela de Faturas (POST + redirect) e
   * as abas do contrato e do cliente (AJAX, que não pode redirecionar para
   * outra tela no meio da conferência). Regra duplicada em duas vias vira duas
   * regras — bastaria uma delas esquecer o cancelamento no banco para o
   * sistema passar a deixar boleto de pé conforme a tela usada.
   *
   * @param  int $id
   * @param  int $idCompany
   * @param  int $idUser
   * @return array success, message (o texto já pronto para a tela)
   */
  private function derrubarCobranca($id, $idCompany, $idUser)
  {
    $this->load->model('psp_model');

    $cobranca = $this->psp_model->cancelarCobranca(
      $id,
      $idCompany,
      'Fatura cancelada no CDW Finance',
      $idUser
    );

    if (empty($cobranca['success'])) {
      return [
        'success' => FALSE,
        'message' => sprintf(
          'A fatura NÃO foi cancelada, porque a cobrança não pôde ser cancelada no banco: %s'
          . ' Cancelar só aqui deixaria o boleto de pé, e o cliente ainda conseguiria pagá-lo.'
          . ' Tente de novo em instantes.',
          $cobranca['message']
        ),
      ];
    }

    // A distinção importa para quem lê: "cancelei um boleto que existia" é
    // diferente de "não havia boleto nenhum", e a segunda não deixa dúvida
    // sobre o que o cliente pode ter em mãos.
    return [
      'success' => TRUE,
      'message' => !empty($cobranca['data']['cancelou'])
        ? ' O boleto foi cancelado no banco.'
        : ' Não havia cobrança registrada no banco.',
    ];
  }

  /**
   * Cancela a fatura a partir das abas do contrato e do cliente.
   *
   * Mesma regra do post_status — derruba a cobrança primeiro e só então muda o
   * status —, mas em AJAX: ali o usuário está no meio da tela do contrato, e
   * um redirect para a lista de Faturas o tiraria do contexto.
   */
  public function json_postcancelar()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $idCompany = (int) $this->getCurrentCompanyId();
    $idUser = (int) $this->session->userdata('user')->id;

    $fatura = $this->global_model->getWhere_off('crm_invoices', [
      'id' => $id,
      'id_company' => $idCompany,
    ], TRUE);

    if (empty($fatura)) {
      echo json_encode([
        'success' => FALSE, 'return' => FALSE,
        'message' => 'Fatura não encontrada.',
        'data' => NULL, 'errors' => ['fatura' => 'Fatura não encontrada.'],
      ]);
      return;
    }

    // Mesma allowlist da outra via: só fatura aberta cancela, e o estado é
    // conferido no servidor — o botão da tela some, mas o endpoint aceita POST
    // direto.
    if ((string) $fatura->status !== 'aberta') {
      echo json_encode([
        'success' => FALSE, 'return' => FALSE,
        'message' => 'Só fatura em aberto pode ser cancelada.',
        'data' => NULL, 'errors' => ['fatura' => 'Situação inválida.'],
      ]);
      return;
    }

    $derrubada = $this->derrubarCobranca($id, $idCompany, $idUser);

    if (empty($derrubada['success'])) {
      echo json_encode([
        'success' => FALSE, 'return' => FALSE,
        'message' => $derrubada['message'],
        'data' => NULL, 'errors' => ['fatura' => $derrubada['message']],
      ]);
      return;
    }

    $this->global_model->edit('crm_invoices', [
      'status' => 'cancelada',
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => $idUser,
    ], 'id', $id);

    echo json_encode([
      'success' => TRUE, 'return' => TRUE,
      'message' => 'Fatura cancelada.' . $derrubada['message'],
      'data' => NULL, 'errors' => [],
    ]);
  }
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

    $idUser = (int) $this->session->userdata('user')->id;
    $complemento = '';

    // CANCELAR A FATURA É CANCELAR A COBRANÇA PRIMEIRO — e só seguir se der
    // certo.
    //
    // A ordem não é preferência: o boleto vive NO BANCO, não aqui. Marcar a
    // fatura como cancelada sem derrubar a cobrança deixaria um boleto
    // perfeitamente pagável em pé — o cliente paga, o dinheiro entra, e do
    // lado de cá a fatura consta cancelada e ninguém concilia. É o mesmo
    // raciocínio da troca de provedor, onde o cancelamento também vem antes.
    //
    // Falhando o cancelamento no banco, a fatura FICA EM ABERTO. Entre "não
    // cancelou" e "cancelou aqui e continua cobrando lá", o primeiro é o erro
    // que se percebe e se repete.
    if ($acao === 'cancelar') {
      $derrubada = $this->derrubarCobranca($id, (int) $this->getCurrentCompanyId(), $idUser);

      if (empty($derrubada['success'])) {
        $this->session->set_flashdata('warning', $derrubada['message']);
        redirect(base_url('faturas'));
      }

      $complemento = $derrubada['message'];
    }

    $this->global_model->edit('crm_invoices', [
      'status' => $transicoes[$acao]['para'],
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => $idUser,
    ], 'id', $id);

    $this->session->set_flashdata('success', $transicoes[$acao]['ok'] . $complemento);
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
