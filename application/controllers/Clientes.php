<?php
defined('BASEPATH') or exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Clientes finais do tenant (crm_customers) — o "cliente do cliente".
 *
 * O escopo é sempre a empresa selecionada no filtro do topo
 * (getCurrentCompanyId), como em Servidores. Cliente não acessa o sistema:
 * este módulo é só o cadastro; contratos, contatos, anexos, atividades e
 * extrato financeiro entram depois como módulos próprios (a tela de visão
 * geral já reserva os cards).
 *
 * Os campos físicos de crm_customers cobrem identificação e endereço
 * comercial; o restante do cadastro (representante, financeiro, pagamento,
 * domínios, contrato) vive no JSON `attributes` — o schema está no docblock
 * da migration 003. As seções `consent` e `source` são gravadas só pelo
 * sistema e NUNCA entram no merge da edição (whitelist em attrWhitelist).
 */
class Clientes extends MY_Controller
{
  /** Registros por página nas listagens. */
  const PER_PAGE = 30;

  /**
   * Chave de sessão dos filtros de contrato da listagem (tipo de serviço e
   * situação contratual) — e o nome do array no POST do formulário.
   *
   * Eles NÃO moram em `f_customers` de propósito: o Global_model aplica cada
   * chave daquele array como `WHERE <chave> = <valor>`, e estes dois precisam
   * de `> 0`/`= 0` e de `FIND_IN_SET` sobre colunas derivadas da view. Gravados
   * lá, virariam comparação com coluna inexistente e derrubariam a listagem
   * inteira — o mesmo acidente que a migration 015 evitou descartando o
   * `id_status`. Aqui eles são traduzidos em SQL por montarWhereListagem().
   */
  const FILTRO_AVANCADO = 'f_customers_contrato';

  /**
   * Ordenação da listagem: cadastro mais recente primeiro.
   *
   * Ancora em `created`, e não no `id`: a importação do gestor-interno grava o
   * `created` da ORIGEM e, com `--id-origem`, também o id da origem — ordenar
   * por id colocaria a base importada em ordem arbitrária e um cadastro antigo
   * poderia aparecer como o mais novo da tela.
   *
   * O id entra só como desempate: `created` tem precisão de segundo e a
   * importação grava vários clientes com o mesmo horário — sem critério
   * estável, dois clientes empatados trocariam de lugar entre uma página e
   * outra, sumindo de ambas ou aparecendo nas duas.
   *
   * A exportação usa a MESMA constante: o arquivo tem de sair na ordem da tela.
   */
  const ORDENACAO_LISTAGEM = 'created desc, id desc';

  /**
   * Teto de linhas da exportação para Excel.
   *
   * A planilha é montada INTEIRA em memória pelo PhpSpreadsheet antes do
   * primeiro byte chegar ao navegador, e nesta largura (28 colunas) o custo
   * medido é de ~27 MB e ~0,8 s por mil linhas: 3.000 clientes ficam em ~80 MB
   * e ~2,5 s, que cabem folgados num `memory_limit` de 128 MB junto com o
   * framework. Acima disso o limite estoura NO MEIO da geração e o navegador
   * recebe um arquivo truncado, que o Excel abre como "corrompido" sem
   * nenhuma pista do motivo.
   *
   * Passando do teto o download é RECUSADO com mensagem pedindo para filtrar.
   * Cortar as linhas em silêncio seria pior: um relatório incompleto se
   * parece em tudo com um relatório completo.
   */
  const LIMITE_EXPORTACAO = 3000;

  public function __construct()
  {
    parent::__construct();
    $this->data['menu'] = 'clientes';
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
    $this->setDefaultCustomerFilter();

    $where = $this->montarWhereListagem();

    $config = $this->paginationConfig(
      base_url('clientes/listagem'),
      $this->global_model->getCountW($where, 'crm_customers_v', 'f_customers')
    );
    $this->pagination->initialize($config);

    // Ordenação composta no campo, com direção vazia: o order_by do CI3 quebra
    // a string na vírgula e lê o ASC/DESC de cada pedaço, escapando os nomes
    // de coluna — é assim que se ordena por dois campos pelo Global_model.
    $this->data['results'] = $this->global_model->getListW(
      $where,
      'crm_customers_v',
      'f_customers',
      self::ORDENACAO_LISTAGEM,
      '',
      $config['per_page'],
      $this->uri->segment(3)
    );

    $this->data['total_results'] = $config['total_rows'];
    $this->data['est_count'] = $config['total_rows'];

    // Os indicadores que ficavam nos cards do topo (clientes por status e
    // contratos vigentes por tipo de serviço) foram para o dashboard
    // (Painel::index). Esta tela é só a base de clientes.
    $this->data['public_link'] = $this->linkCadastroPublico();

    // Catálogo do filtro "tipo de contrato" — só os tipos ativos, como no
    // modal de novo contrato da visão geral do cliente.
    $this->data['service_types'] = $this->global_model->getWhereOrderBy_off('crm_service_types', ['id_status' => 1], 'name', 'asc', FALSE);
    $this->data['contract_situations'] = $this->contractSituations();
    $this->data['filtro_avancado'] = (array) $this->session->userdata(self::FILTRO_AVANCADO);
    $this->data['filtro_avancado_campo'] = self::FILTRO_AVANCADO;

    $this->load->view('header', $this->data);
    $this->load->view('customers/list', $this->data);
    $this->load->view('footer', $this->data);
  }

  public function post_filtrar()
  {
    // O botão LIMPAR do offcanvas zera os dois conjuntos de filtro de uma vez:
    // com o formulário escondido, filtro esquecido lá dentro vira listagem
    // inexplicavelmente vazia.
    if ($this->input->post('acao') === 'limpar') {
      $this->session->unset_userdata('f_customers');
      $this->session->unset_userdata(self::FILTRO_AVANCADO);
      redirect(base_url('clientes'));
    }

    if (empty($this->input->post('f_customers'))) redirect(base_url('clientes'));
    $filtro = $this->input->post('f_customers');

    // A coluna document guarda só dígitos: busca digitada com máscara
    // (00.000.000/0000-00) nunca casaria. Quando a keyword é um documento,
    // normaliza para dígitos antes de gravar o filtro.
    if (!empty($filtro['keyword']) && preg_match('/^[0-9.\/\- ]+$/', trim($filtro['keyword']))) {
      $documento = sonumero($filtro['keyword']);
      if (strlen($documento) >= 4) $filtro['keyword'] = $documento;
    }

    $array = array_merge((array) $this->session->userdata('f_customers'), $filtro);
    $this->session->set_userdata('f_customers', $array);

    // O formulário do offcanvas SEMPRE envia os dois selects (o "mostrar
    // todos" é uma opção de valor vazio, não a ausência do campo), então a
    // chave só falta no POST da busca rápida do topo — e ali o filtro avançado
    // tem de ser preservado. Zerá-lo desfaria em silêncio, a cada busca, um
    // filtro que continua marcado no offcanvas e anunciado nos chips.
    if ($this->input->post(self::FILTRO_AVANCADO) !== NULL) {
      $avancado = (array) $this->input->post(self::FILTRO_AVANCADO);
      $situacao = isset($avancado['situation']) ? trim((string) $avancado['situation']) : '';
      $this->session->set_userdata(self::FILTRO_AVANCADO, [
        'id_service_type' => isset($avancado['id_service_type']) ? (int) $avancado['id_service_type'] : 0,
        // Allowlist: a situação entra na cláusula SQL, então valor desconhecido
        // é descartado em vez de virar filtro nenhum por acidente mais adiante.
        'situation' => array_key_exists($situacao, $this->contractSituations()) ? $situacao : '',
      ]);
    }

    redirect(base_url('clientes'));
  }

  // ------------------------------------------------------------------
  // Relatórios
  // ------------------------------------------------------------------

  /**
   * Exporta a base de clientes para .xlsx (menu RELATÓRIOS da listagem).
   *
   * Sai EXATAMENTE o que a listagem está mostrando — mesmo escopo de tenant,
   * mesmos filtros da sessão e mesma ordenação —, só que sem paginação: o
   * arquivo precisa bater com os chips da tela, senão vira uma segunda
   * resposta para a mesma pergunta (foi por isso que os indicadores saíram
   * daqui para o Dashboard).
   *
   * Por consultar a MESMA `crm_customers_v` da listagem, as contagens de
   * contrato vêm de graça (`contracts_count`/`active_contracts_count`); só a
   * de domínios precisa de query própria.
   */
  public function exportar_excel()
  {
    $this->setDefaultCustomerFilter();

    $where = $this->montarWhereListagem();
    $total = $this->global_model->getCountW($where, 'crm_customers_v', 'f_customers');

    if ($total <= 0) {
      $this->session->set_flashdata('warning', 'Nenhum cliente para exportar com os filtros aplicados.');
      redirect(base_url('clientes'));
    }

    if ($total > self::LIMITE_EXPORTACAO) {
      $this->session->set_flashdata('error', 'A exportação é limitada a ' . numero(self::LIMITE_EXPORTACAO) . ' clientes por arquivo, e o filtro atual tem ' . numero($total) . '. Use os filtros para reduzir a seleção.');
      redirect(base_url('clientes'));
    }

    // `$total` como limite: o objetivo é justamente NÃO paginar, e o teto
    // acima já garante que o número é seguro.
    $clientes = $this->global_model->getListW($where, 'crm_customers_v', 'f_customers', self::ORDENACAO_LISTAGEM, '', $total, 0);

    $this->baixarPlanilhaClientes($clientes, $this->contagemDominiosPorCliente());
  }

  /**
   * Quantidade de domínios de contrato por cliente, em uma query só.
   *
   * Consulta agregada em vez de coluna derivada na `crm_customers_v`: a
   * pergunta só existe no relatório, e um subselect na view seria pago por
   * toda listagem e por toda contagem de paginação da tela — as colunas que a
   * migration 018 acrescentou existem porque o WHERE precisava delas, o que
   * não é o caso aqui.
   *
   * Conta LINHAS do cadastro de domínio do contrato, de contratos de qualquer
   * situação — mesmo recorte da coluna "Contratos" da listagem. `foo.com` e
   * `www.foo.com` contam como dois: são dois cadastros, ainda que apontem
   * para a mesma linha de `crm_servers_domains`. A deduplicação por vínculo
   * vale para SOMA de disco (onde o mesmo espaço entraria duas vezes), nunca
   * para contagem de domínio.
   *
   * @return array id_customer => quantidade
   */
  private function contagemDominiosPorCliente()
  {
    $sql = "SELECT crm_contracts.id_customer AS id_customer, COUNT(*) AS total
              FROM crm_contracts_domains
              JOIN crm_contracts ON(crm_contracts.id = crm_contracts_domains.id_contract)
             WHERE crm_contracts_domains.id_company = ?
             GROUP BY crm_contracts.id_customer";

    $linhas = $this->db->query($sql, [(int) $this->getCurrentCompanyId()])->result();

    $porCliente = [];
    foreach ($linhas as $linha) {
      $porCliente[(int) $linha->id_customer] = (int) $linha->total;
    }

    return $porCliente;
  }

  /**
   * Colunas da planilha: rótulo, tipo de célula e largura.
   *
   * A chave é o que amarra o cabeçalho ao valor devolvido por
   * linhaExportacao() — com duas listas posicionais, acrescentar uma coluna no
   * meio de uma delas desalinharia a planilha inteira em silêncio.
   *
   * O tipo existe porque o Excel adivinha: documento, CEP e telefone gravados
   * como número perderiam o zero à esquerda e virariam notação científica.
   *
   * @return array chave => [titulo, tipo (texto|inteiro|data), largura]
   */
  private function colunasExportacao()
  {
    return [
      'id' => ['titulo' => 'ID', 'tipo' => 'inteiro', 'largura' => 8],
      'tipo' => ['titulo' => 'Tipo', 'tipo' => 'texto', 'largura' => 16],
      'documento' => ['titulo' => 'CNPJ / CPF', 'tipo' => 'texto', 'largura' => 20],
      'nome' => ['titulo' => 'Razão social / Nome', 'tipo' => 'texto', 'largura' => 40],
      'fantasia' => ['titulo' => 'Nome fantasia', 'tipo' => 'texto', 'largura' => 30],
      // Texto, nunca número: inscrição estadual tem zero à esquerda.
      'inscricao_estadual' => ['titulo' => 'Inscrição estadual', 'tipo' => 'texto', 'largura' => 18],
      'email' => ['titulo' => 'E-mail do contrato', 'tipo' => 'texto', 'largura' => 30],
      'cep' => ['titulo' => 'CEP', 'tipo' => 'texto', 'largura' => 12],
      'endereco' => ['titulo' => 'Endereço', 'tipo' => 'texto', 'largura' => 35],
      'numero' => ['titulo' => 'Número', 'tipo' => 'texto', 'largura' => 10],
      'complemento' => ['titulo' => 'Complemento', 'tipo' => 'texto', 'largura' => 20],
      'bairro' => ['titulo' => 'Bairro', 'tipo' => 'texto', 'largura' => 22],
      'cidade' => ['titulo' => 'Cidade', 'tipo' => 'texto', 'largura' => 22],
      'uf' => ['titulo' => 'UF', 'tipo' => 'texto', 'largura' => 6],
      'rep_nome' => ['titulo' => 'Representante', 'tipo' => 'texto', 'largura' => 30],
      'rep_cpf' => ['titulo' => 'CPF do representante', 'tipo' => 'texto', 'largura' => 20],
      'rep_whatsapp' => ['titulo' => 'WhatsApp do representante', 'tipo' => 'texto', 'largura' => 22],
      'fin_nome' => ['titulo' => 'Contato financeiro', 'tipo' => 'texto', 'largura' => 30],
      'fin_email' => ['titulo' => 'E-mail financeiro', 'tipo' => 'texto', 'largura' => 30],
      'fin_whatsapp' => ['titulo' => 'WhatsApp financeiro', 'tipo' => 'texto', 'largura' => 22],
      'nota_fiscal' => ['titulo' => 'Emite NF', 'tipo' => 'texto', 'largura' => 10],
      'dominio_primario' => ['titulo' => 'Domínio principal (cadastro)', 'tipo' => 'texto', 'largura' => 28],
      'dominio_secundario' => ['titulo' => 'Domínio secundário (cadastro)', 'tipo' => 'texto', 'largura' => 28],
      'contratos' => ['titulo' => 'Contratos', 'tipo' => 'inteiro', 'largura' => 11],
      'contratos_vigentes' => ['titulo' => 'Contratos vigentes', 'tipo' => 'inteiro', 'largura' => 17],
      'dominios' => ['titulo' => 'Domínios nos contratos', 'tipo' => 'inteiro', 'largura' => 20],
      'criado' => ['titulo' => 'Cadastrado em', 'tipo' => 'data', 'largura' => 18],
      'criado_por' => ['titulo' => 'Cadastrado por', 'tipo' => 'texto', 'largura' => 24],
      'modificado' => ['titulo' => 'Atualizado em', 'tipo' => 'data', 'largura' => 18],
    ];
  }

  /**
   * Uma linha da planilha, com as chaves de colunasExportacao().
   *
   * Os campos que não são físicos vêm do JSON `attributes` (schema no docblock
   * da migration 003); `consent` e `source` ficam de fora — são gravados só
   * pelo sistema e não fazem parte do cadastro que o relatório descreve.
   *
   * @param  object $cliente            linha da crm_customers_v
   * @param  array  $dominiosPorCliente id_customer => quantidade
   * @return array
   */
  private function linhaExportacao($cliente, $dominiosPorCliente)
  {
    $attrs = json_decode((string) $cliente->attributes, TRUE);
    if (!is_array($attrs)) $attrs = [];

    $attr = function ($secao, $chave) use ($attrs) {
      return isset($attrs[$secao][$chave]) ? trim((string) $attrs[$secao][$chave]) : '';
    };

    $tipos = ['J' => 'Pessoa jurídica', 'F' => 'Pessoa física'];
    $repCpf = $attr('representative', 'cpf');
    $emiteNf = $attr('billing', 'needs_invoice');
    $id = (int) $cliente->id;

    return [
      'id' => $id,
      'tipo' => isset($tipos[$cliente->type]) ? $tipos[$cliente->type] : (string) $cliente->type,
      // cnpj() aplica a máscara de CNPJ ou de CPF conforme o tamanho — a
      // coluna guarda só dígitos.
      'documento' => cnpj($cliente->document),
      'nome' => (string) $cliente->name,
      'fantasia' => (string) $cliente->byname,
      // Só faz sentido em PJ — em PF a coluna carrega o default e diria
      // "ISENTO" para quem nunca teve inscrição.
      'inscricao_estadual' => ((string) $cliente->type === 'J') ? (string) $cliente->state_registration : '',
      'email' => (string) $cliente->email,
      'cep' => (string) $cliente->address_zip,
      'endereco' => (string) $cliente->address,
      'numero' => (string) $cliente->address_number,
      'complemento' => (string) $cliente->address_complement,
      'bairro' => (string) $cliente->address_district,
      // city_name/state_uf são NULL quando a cidade não foi resolvida (a view
      // usa LEFT JOIN de propósito) — o cliente continua na planilha.
      'cidade' => (string) $cliente->city_name,
      'uf' => (string) $cliente->state_uf,
      'rep_nome' => $attr('representative', 'name'),
      'rep_cpf' => $repCpf !== '' ? cnpj($repCpf) : '',
      'rep_whatsapp' => $attr('representative', 'whatsapp'),
      'fin_nome' => $attr('billing', 'name'),
      'fin_email' => $attr('billing', 'email'),
      'fin_whatsapp' => $attr('billing', 'whatsapp'),
      'nota_fiscal' => $emiteNf === 'S' ? 'Sim' : ($emiteNf === 'N' ? 'Não' : ''),
      'dominio_primario' => $attr('domains', 'primary'),
      'dominio_secundario' => $attr('domains', 'secondary'),
      'contratos' => (int) $cliente->contracts_count,
      'contratos_vigentes' => (int) $cliente->active_contracts_count,
      'dominios' => isset($dominiosPorCliente[$id]) ? (int) $dominiosPorCliente[$id] : 0,
      'criado' => (string) $cliente->created,
      'criado_por' => (string) $cliente->created_user,
      'modificado' => (string) $cliente->modified,
    ];
  }

  /**
   * Monta o .xlsx e o envia como download — não retorna.
   *
   * @param array $clientes           linhas da crm_customers_v
   * @param array $dominiosPorCliente id_customer => quantidade
   */
  private function baixarPlanilhaClientes($clientes, $dominiosPorCliente)
  {
    $colunas = $this->colunasExportacao();

    $planilha = new Spreadsheet();
    $aba = $planilha->getActiveSheet();
    $aba->setTitle('Clientes');

    $indice = 1;
    foreach ($colunas as $chave => $definicao) {
      $letra = Coordinate::stringFromColumnIndex($indice);
      $aba->setCellValue($letra . '1', $definicao['titulo']);
      $aba->getColumnDimension($letra)->setWidth($definicao['largura']);
      $indice++;
    }

    $linha = 2;
    foreach ($clientes as $cliente) {
      $valores = $this->linhaExportacao($cliente, $dominiosPorCliente);

      $indice = 1;
      foreach ($colunas as $chave => $definicao) {
        $this->escreverCelula($aba, Coordinate::stringFromColumnIndex($indice) . $linha, $valores[$chave], $definicao['tipo']);
        $indice++;
      }

      $linha++;
    }

    $ultimaColuna = Coordinate::stringFromColumnIndex(count($colunas));
    $ultimaLinha = $linha - 1;

    $cabecalho = $aba->getStyle('A1:' . $ultimaColuna . '1');
    $cabecalho->getFont()->setBold(TRUE);
    $cabecalho->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
    $cabecalho->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(TRUE);
    $aba->getRowDimension(1)->setRowHeight(28);

    // Congelar e filtrar a partir da segunda linha: a planilha é larga (28
    // colunas) e sem isso o cabeçalho some ao rolar para a direita.
    $aba->freezePane('A2');
    $aba->setAutoFilter('A1:' . $ultimaColuna . $ultimaLinha);
    $aba->setSelectedCell('A1');

    $nomeArquivo = 'clientes_' . date('Y-m-d_His') . '.xlsx';

    // O arquivo vai direto para o navegador: qualquer saída pendente (aviso do
    // PHP, BOM de um include) sairia colada no zip do .xlsx e o Excel
    // recusaria o arquivo.
    while (ob_get_level() > 0) {
      ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
    header('Pragma: public');

    $writer = new Xlsx($planilha);
    $writer->save('php://output');

    $planilha->disconnectWorksheets();
    unset($planilha);
    exit;
  }

  /**
   * Grava uma célula respeitando o tipo declarado na coluna.
   *
   * Texto vai SEMPRE explícito: deixar o Excel adivinhar transforma CEP e
   * telefone em número (zero à esquerda perdido) e datas em serial.
   *
   * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $aba
   * @param string $celula
   * @param mixed  $valor
   * @param string $tipo  texto | inteiro | data
   */
  private function escreverCelula($aba, $celula, $valor, $tipo)
  {
    if ($tipo === 'inteiro') {
      $aba->setCellValueExplicit($celula, (int) $valor, DataType::TYPE_NUMERIC);
      return;
    }

    if ($tipo === 'data') {
      // DateTime local (e não o timestamp): Date::PHPToExcel converte
      // timestamp assumindo UTC e a hora sairia deslocada do que a tela mostra.
      $dataHora = DateTime::createFromFormat('Y-m-d H:i:s', (string) $valor);
      if ($dataHora === FALSE) return;

      $aba->setCellValue($celula, ExcelDate::PHPToExcel($dataHora));
      $aba->getStyle($celula)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
      return;
    }

    $aba->setCellValueExplicit($celula, (string) $valor, DataType::TYPE_STRING);
  }

  // ------------------------------------------------------------------
  // Cadastro
  //
  // Não há criação de cliente pelo painel de propósito: cliente novo entra
  // SEMPRE pelo cadastro público (/cadastro-cliente), preenchido pelo próprio
  // cliente — o botão "+ NOVO CLIENTE" da listagem abre esse link. Aqui só
  // edição.
  //
  // O cadastro não tem status (migration 015): não existe "ativar cliente".
  // O que importa é ter contrato vigente, e isso vive em crm_contracts.
  // ------------------------------------------------------------------

  public function editar()
  {
    $this->setFormRules();

    if ($this->form_validation->run() == FALSE) {
      $id = (int) $this->input->get('id');
      $this->data['result'] = $this->carregarClienteDaView($id);
      $this->renderForm();
      return;
    }

    $id = (int) $this->input->post('id');
    $atual = $this->global_model->getWhere_off('crm_customers', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);
    if (empty($atual)) {
      $this->session->set_flashdata('warning', 'Cliente não encontrado.');
      redirect(base_url('clientes'));
    }

    $dados = $this->montarDadosDoPost();
    if ($dados === FALSE) {
      $this->data['result'] = $this->carregarClienteDaView($id);
      $this->renderForm();
      return;
    }

    if ($this->documentoEmUso($dados['document'], (int) $atual->id_company, $id)) {
      $this->session->set_flashdata('warning', 'Já existe outro cliente com este documento nesta empresa.');
      $this->data['result'] = $this->carregarClienteDaView($id);
      $this->renderForm();
      return;
    }

    // Merge com whitelist: o POST só toca nas seções editáveis; consent e
    // source permanecem exatamente como estão gravados.
    $atuais = json_decode((string) $atual->attributes, TRUE);
    if (!is_array($atuais)) $atuais = [];
    $attributes = $this->montarAttributesDoPost($atuais);
    $dados['attributes'] = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $dados['modified'] = date('Y-m-d H:i:s');
    $dados['modified_by'] = (int) $this->session->userdata('user')->id;

    $this->global_model->edit('crm_customers', $dados, 'id', $id);

    $this->session->set_flashdata('success', 'Cliente atualizado com sucesso.');

    // A sincronização vem DEPOIS do flashdata de sucesso: a edição já está
    // gravada e vale por si. Se o ERP recusar, o aviso se soma ao sucesso em
    // vez de substituí-lo — o usuário precisa saber as duas coisas.
    $this->sincronizarBomControleAposEdicao($id, $atual, $dados);

    redirect(base_url('clientes/info?id=' . $id));
  }

  /**
   * Reflete a edição no Bom Controle, quando há o que refletir.
   *
   * Só vai à rede se algum campo que o ERP recebe mudou: salvar apenas os
   * comentários do contrato ou o WhatsApp do representante não tem por que
   * gastar duas chamadas de API e mexer no cadastro de lá.
   *
   * Falha aqui é aviso, nunca erro fatal — quem está na tela é usuário interno,
   * então diferente do wizard a falha é visível e acionável (o botão
   * SINCRONIZAR CADASTRO da visão geral reprocessa).
   *
   * @param int    $id
   * @param object $atual estado anterior do cliente
   * @param array  $dados campos físicos gravados agora
   */
  private function sincronizarBomControleAposEdicao($id, $atual, array $dados)
  {
    $relevantes = [
      'type', 'document', 'name', 'byname', 'state_registration',
      'address', 'address_number', 'address_complement', 'address_district',
      'address_zip', 'id_state', 'id_city',
    ];

    $mudou = FALSE;
    foreach ($relevantes as $campo) {
      if (!array_key_exists($campo, $dados)) continue;
      if ((string) $dados[$campo] !== (string) $atual->$campo) {
        $mudou = TRUE;
        break;
      }
    }

    // Cliente ainda não sincronizado precisa subir mesmo sem mudança: é o caso
    // de quem foi cadastrado antes da integração existir.
    if (!$mudou && (int) $atual->bomcontrole_customer_id > 0) {
      return;
    }

    try {
      $this->load->model('bomcontrole_model');
      if (!$this->bomcontrole_model->isActive((int) $this->getCurrentCompanyId())) {
        return;
      }

      $resultado = $this->bomcontrole_model->sincronizarCliente(
        (int) $id,
        (int) $this->getCurrentCompanyId(),
        Bomcontrole_model::TIMEOUT_SINCRONIZACAO
      );

      if (!$resultado['success']) {
        $this->session->set_flashdata('warning', 'Cliente salvo, mas não foi possível atualizar no Bom Controle: ' . $resultado['message']);
      }
    } catch (Throwable $e) {
      log_message('error', sprintf(
        '[BOMCONTROLE] Sincronizacao apos edicao falhou — empresa=%d cliente=%d: %s',
        (int) $this->getCurrentCompanyId(),
        (int) $id,
        $e->getMessage()
      ));
      $this->session->set_flashdata('warning', 'Cliente salvo, mas houve uma falha inesperada ao atualizar no Bom Controle.');
    }
  }

  // ------------------------------------------------------------------
  // Visão geral
  // ------------------------------------------------------------------

  public function info()
  {
    $id = (int) $this->input->get('id');
    $this->data['result'] = $this->carregarClienteDaView($id);

    $attrs = json_decode((string) $this->data['result']->attributes, TRUE);
    $this->data['attrs'] = is_array($attrs) ? $attrs : [];

    $this->data['files'] = $this->global_model->getWhereOrderBy_off('crm_customers_files_v', ['id_customer' => $id], 'id', 'desc', FALSE);
    $this->data['contacts'] = $this->global_model->getWhereOrderBy_off('crm_customers_contacts_v', ['id_customer' => $id], 'id', 'desc', FALSE);
    $this->data['contact_types'] = $this->contactTypes();
    // Mais recente primeiro: é a ordem da timeline da aba Atividades.
    $this->data['notes'] = $this->global_model->getWhereOrderBy_off('crm_customers_notes_v', ['id_customer' => $id], 'id', 'desc', FALSE);

    // Contratos do cliente + tipos de serviço de cada um (uma query só, via
    // view do N:N), agrupados por contrato para os badges da tabela.
    $this->data['contracts'] = $this->global_model->getWhereOrderBy_off('crm_contracts_v', ['id_customer' => $id], 'id', 'desc', FALSE);
    $servicos = $this->global_model->getWhere_off('crm_contracts_services_v', ['id_customer' => $id], FALSE);
    $porContrato = [];
    foreach ($servicos as $s) {
      $porContrato[(int) $s->id_contract][] = $s->service_type_name;
    }
    $this->data['contract_services'] = $porContrato;

    // Domínios de TODOS os contratos do cliente em UMA query (a view carrega
    // id_customer/contract_status desde a migration 011). Dela saem três
    // recortes: a aba Domínios (só contratos vigentes), a coluna Domínios da
    // tabela de contratos (todos, inclusive suspensos) e o uso por contrato.
    $dominios = $this->global_model->getWhereOrderBy_off('crm_contracts_domains_v', ['id_customer' => $id], 'domain', 'asc', FALSE);

    $vigentes = [];
    $porContratoDominios = [];
    $usoPorContratoMb = [];
    $vistosPorContrato = [];
    foreach ($dominios as $d) {
      $idContrato = (int) $d->id_contract;
      $porContratoDominios[$idContrato][] = $d;
      if (!isset($usoPorContratoMb[$idContrato])) $usoPorContratoMb[$idContrato] = 0.0;
      // Uso = só os domínios COM vínculo, mesma regra da tela do contrato:
      // domínio sem correspondência no servidor não entra na soma. E cada
      // domínio de servidor conta uma vez por contrato — `foo.com` e
      // `www.foo.com` casam com a MESMA linha na busca do cadastro, e somar os
      // dois dobraria o uso. A dedup é pelo VÍNCULO e não pelo nome: o mesmo
      // nome em servidores diferentes são duas contas, com dois discos, e as
      // duas somam.
      if (!empty($d->id_server_domain) && !isset($vistosPorContrato[$idContrato][(int) $d->id_server_domain])) {
        $vistosPorContrato[$idContrato][(int) $d->id_server_domain] = TRUE;
        $usoPorContratoMb[$idContrato] += (float) $d->server_disk_used_mb;
      }
      if ($d->contract_status === 'vigente') $vigentes[] = $d;
    }
    $this->data['contract_domains'] = $vigentes;
    $this->data['domains_by_contract'] = $porContratoDominios;
    $this->data['usage_by_contract_mb'] = $usoPorContratoMb;

    $this->data['cycles'] = $this->cycles();
    $this->data['service_types'] = $this->global_model->getWhereOrderBy_off('crm_service_types', ['id_status' => 1], 'name', 'asc', FALSE);

    // Aba Extrato financeiro: só uma leitura de banco (sem rede) — a aba
    // mostra o aviso de integração desativada sem esperar o AJAX do extrato.
    $this->load->model('bomcontrole_model');
    $this->data['bomcontrole_ativo'] = $this->bomcontrole_model->isActive((int) $this->getCurrentCompanyId());

    $this->load->view('header', $this->data);
    $this->load->view('customers/info', $this->data);
    $this->load->view('footer', $this->data);
  }

  /**
   * Exclusão definitiva, bloqueada enquanto houver vínculo (a legenda da tela
   * avisa: "remova antes os contratos, anexos e contatos"). Hoje o único
   * vínculo que existe é o de anexos; contratos e contatos entram na checagem
   * quando os módulos existirem.
   */
  public function post_excluir()
  {
    $id = (int) $this->input->post('id');
    $cliente = $this->global_model->getWhere_off('crm_customers', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($cliente)) {
      $this->session->set_flashdata('warning', 'Cliente não encontrado.');
      redirect(base_url('clientes'));
    }

    $contratos = $this->global_model->countWhere_off('crm_contracts', ['id_customer' => $id]);
    if ($contratos > 0) {
      $this->session->set_flashdata('error', 'Este cliente possui ' . $contratos . ' contrato(s). Exclua os contratos antes de excluir o cadastro.');
      redirect(base_url('clientes/info?id=' . $id));
    }

    $anexos = $this->global_model->countWhere_off('crm_customers_files', ['id_customer' => $id]);
    if ($anexos > 0) {
      $this->session->set_flashdata('error', 'Este cliente possui ' . $anexos . ' anexo(s). Exclua os anexos antes de excluir o cadastro.');
      redirect(base_url('clientes/info?id=' . $id));
    }

    $contatos = $this->global_model->countWhere_off('crm_customers_contacts', ['id_customer' => $id]);
    if ($contatos > 0) {
      $this->session->set_flashdata('error', 'Este cliente possui ' . $contatos . ' contato(s). Exclua os contatos antes de excluir o cadastro.');
      redirect(base_url('clientes/info?id=' . $id));
    }

    $this->global_model->delete('crm_customers', 'id', $id);

    $this->session->set_flashdata('success', 'Cliente excluído com sucesso.');
    redirect(base_url('clientes'));
  }

  // ------------------------------------------------------------------
  // Anexos
  // ------------------------------------------------------------------

  public function post_sendfile()
  {
    $id = (int) $this->input->post('id');
    $cliente = $this->global_model->getWhere_off('crm_customers', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($cliente)) {
      $this->session->set_flashdata('warning', 'Cliente não encontrado.');
      redirect(base_url('clientes'));
    }

    $nome = trim((string) $this->input->post('name'));
    if ($nome === '') {
      $this->session->set_flashdata('error', 'Informe um nome para o anexo.');
      redirect(base_url('clientes/info?id=' . $id));
    }

    // uploadFileFtp valida o teto por arquivo NO SERVIDOR
    // (UPLOAD_MAX_SIZE_PADRAO, 10 MB) e grava em images/customers/<ano>/<mês>/.
    $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'xls', 'xlsx', 'doc', 'docx', 'JPG', 'JPEG', 'PNG', 'PDF', 'XLS', 'XLSX', 'DOC', 'DOCX'];
    $file = $this->uploadFileFtp('file', 'customers', $allowed, FALSE);

    if (empty($file)) {
      $this->session->set_flashdata('error', 'Nenhum anexo enviado. Confira o formato (JPG, PNG, PDF, XLS ou DOC) e o tamanho (até 10 MB).');
      redirect(base_url('clientes/info?id=' . $id));
    }

    $this->global_model->add('crm_customers_files', [
      'id_customer' => $id,
      'id_company' => (int) $cliente->id_company,
      'name' => mb_substr($nome, 0, 150),
      'file' => $file,
      'created' => date('Y-m-d H:i:s'),
      'created_by' => (int) $this->session->userdata('user')->id,
    ]);

    $this->session->set_flashdata('success', 'Anexo enviado com sucesso.');
    redirect(base_url('clientes/info?id=' . $id));
  }

  // ------------------------------------------------------------------
  // Atividades
  // ------------------------------------------------------------------

  public function post_novaatividade()
  {
    $id = (int) $this->input->post('id');
    $cliente = $this->global_model->getWhere_off('crm_customers', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($cliente)) {
      $this->session->set_flashdata('warning', 'Cliente não encontrado.');
      redirect(base_url('clientes'));
    }

    $descricao = trim((string) $this->input->post('description'));
    if ($descricao === '') {
      $this->session->set_flashdata('error', 'Escreva as observações da atividade.');
      redirect(base_url('clientes/info?id=' . $id));
    }

    $this->global_model->add('crm_customers_notes', [
      'id_customer' => $id,
      'id_company' => (int) $cliente->id_company,
      'description' => $descricao,
      'created' => date('Y-m-d H:i:s'),
      'created_by' => (int) $this->session->userdata('user')->id,
    ]);

    $this->session->set_flashdata('success', 'Atividade registrada.');
    redirect(base_url('clientes/info?id=' . $id));
  }

  // ------------------------------------------------------------------
  // Contatos
  // ------------------------------------------------------------------

  /**
   * Cria ou atualiza um contato — o modal é o mesmo nos dois casos; com
   * `id_contact` no POST é edição.
   */
  public function post_salvarcontato()
  {
    $id = (int) $this->input->post('id');
    $cliente = $this->global_model->getWhere_off('crm_customers', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($cliente)) {
      $this->session->set_flashdata('warning', 'Cliente não encontrado.');
      redirect(base_url('clientes'));
    }

    $contato = (array) $this->input->post('contact');
    $tipo = isset($contato['type']) ? trim((string) $contato['type']) : '';
    $nome = isset($contato['name']) ? trim((string) $contato['name']) : '';
    $email = isset($contato['email']) ? mb_strtolower(trim((string) $contato['email'])) : '';
    $telefone = isset($contato['phone']) ? trim((string) $contato['phone']) : '';

    if (!array_key_exists($tipo, $this->contactTypes())) {
      $this->session->set_flashdata('error', 'Selecione um tipo de contato válido.');
      redirect(base_url('clientes/info?id=' . $id));
    }
    if ($nome === '') {
      $this->session->set_flashdata('error', 'Informe o nome do contato.');
      redirect(base_url('clientes/info?id=' . $id));
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->session->set_flashdata('error', 'Informe um e-mail válido para o contato.');
      redirect(base_url('clientes/info?id=' . $id));
    }

    $dados = [
      'type' => $tipo,
      'name' => mb_substr($nome, 0, 150),
      'email' => $email !== '' ? mb_substr($email, 0, 150) : NULL,
      'phone' => $telefone !== '' ? mb_substr($telefone, 0, 45) : NULL,
    ];

    $idContact = (int) $this->input->post('id_contact');
    if ($idContact > 0) {
      // Edição: o contato precisa pertencer a este cliente e a este tenant.
      $existente = $this->global_model->getWhere_off('crm_customers_contacts', [
        'id' => $idContact,
        'id_customer' => $id,
        'id_company' => (int) $cliente->id_company,
      ], TRUE);

      if (empty($existente)) {
        $this->session->set_flashdata('warning', 'Contato não encontrado.');
        redirect(base_url('clientes/info?id=' . $id));
      }

      $dados['modified'] = date('Y-m-d H:i:s');
      $dados['modified_by'] = (int) $this->session->userdata('user')->id;
      $this->global_model->edit('crm_customers_contacts', $dados, 'id', $idContact);

      $this->session->set_flashdata('success', 'Contato atualizado com sucesso.');
      redirect(base_url('clientes/info?id=' . $id));
    }

    $dados['id_customer'] = $id;
    $dados['id_company'] = (int) $cliente->id_company;
    $dados['created'] = date('Y-m-d H:i:s');
    $dados['created_by'] = (int) $this->session->userdata('user')->id;
    $this->global_model->add('crm_customers_contacts', $dados);

    $this->session->set_flashdata('success', 'Contato adicionado com sucesso.');
    redirect(base_url('clientes/info?id=' . $id));
  }

  public function json_postdeletecontact()
  {
    header('Content-Type: application/json; charset=utf-8');

    $idContact = (int) $this->input->post('id');
    if ($idContact <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    $contato = $this->global_model->getWhere_off('crm_customers_contacts', [
      'id' => $idContact,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($contato)) {
      echo json_encode($this->jsonErro('Contato não encontrado.', ['id' => 'Contato não encontrado.']));
      return;
    }

    $this->global_model->delete('crm_customers_contacts', 'id', $idContact);

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => 'Contato excluído com sucesso.',
      'data' => ['id' => $idContact],
      'errors' => [],
    ]);
  }

  /**
   * Extrato financeiro consolidado do cliente: agrega, ao vivo, o extrato de
   * todos os contratos vinculados ao Bom Controle. O vínculo em si é feito na
   * tela do contrato — aqui é só leitura.
   */
  public function json_postextratobc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->montarExtratoCliente($id, (int) $this->getCurrentCompanyId());

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Sobe o cadastro do cliente para o Bom Controle sob demanda.
   *
   * Existe por dois motivos: reprocessar uma sincronização que falhou (o
   * cadastro nunca é bloqueado por causa do ERP, então a falha precisa de um
   * caminho de volta) e subir a base que já estava cadastrada antes de a
   * integração existir, um cliente por vez — em lote, o rate limit da API
   * derrubaria o meio da fila.
   */
  public function json_postsincronizarbc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->sincronizarCliente($id, (int) $this->getCurrentCompanyId());

    // A tela mostra a data no formato do sistema; o ISO fica para uso
    // programático. Sem isto, a legenda mudaria de formato ao clicar no botão e
    // voltaria ao antigo no F5.
    $dados = $resultado['data'];
    $dados['synced_label'] = empty($dados['synced']) ? '' : data($dados['synced']);

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $dados,
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Catálogo de ciclos de pagamento do contrato — espelho do de
   * Contratos::cycles(), para o modal de novo contrato desta tela.
   *
   * @return array alias => rótulo
   */
  private function cycles()
  {
    return [
      'mensal' => 'Mensal',
      'bimestral' => 'Bimestral',
      'trimestral' => 'Trimestral',
      'quadrimestral' => 'Quadrimestral',
      'semestral' => 'Semestral',
      'anual' => 'Anual',
    ];
  }

  /**
   * Catálogo de tipos de contato (slug gravado em crm_customers_contacts.type).
   *
   * @return array alias => rótulo
   */
  private function contactTypes()
  {
    return [
      'financeiro' => 'Financeiro',
      'socio_proprietario' => 'Sócio proprietário',
      'gestor_trafego' => 'Gestor de tráfego',
      'juridico' => 'Jurídico',
      'marketing' => 'Marketing',
      'diretor' => 'Diretor',
      'outros' => 'Outros',
    ];
  }

  public function json_postdeletefile()
  {
    header('Content-Type: application/json; charset=utf-8');

    $idFile = (int) $this->input->post('id');
    if ($idFile <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    // Exclusão pelo id do registro, com escopo do tenant — o caminho do
    // arquivo nunca vem do POST (diferente da tela antiga de empresas, que
    // recebia o path e confiava nele).
    $anexo = $this->global_model->getWhere_off('crm_customers_files', [
      'id' => $idFile,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($anexo)) {
      echo json_encode($this->jsonErro('Anexo não encontrado.', ['id' => 'Anexo não encontrado.']));
      return;
    }

    if (!empty($anexo->file) && file_exists(FCPATH . $anexo->file)) {
      @unlink(FCPATH . $anexo->file);
    }
    $this->global_model->delete('crm_customers_files', 'id', $idFile);

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => 'Anexo excluído com sucesso.',
      'data' => ['id' => $idFile],
      'errors' => [],
    ]);
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

  // ------------------------------------------------------------------
  // Apoio
  // ------------------------------------------------------------------

  private function setDefaultCustomerFilter()
  {
    $filtros = $this->session->userdata('f_customers');
    if (!is_array($filtros)) $filtros = [];

    if (!array_key_exists('keyword', $filtros)) $filtros['keyword'] = '';
    if (!array_key_exists('type', $filtros)) $filtros['type'] = '';
    // Cliente não tem mais status (migration 015). O Global_model aplica cada
    // chave do filtro como "WHERE <chave> = <valor>": um id_status gravado na
    // sessão antes da mudança apontaria para coluna inexistente e derrubaria a
    // listagem — por isso é descartado, e não apenas deixado de preencher.
    unset($filtros['id_status']);
    $filtros['keyword_search'] = ['name', 'byname', 'document', 'email'];

    $this->session->set_userdata('f_customers', $filtros);

    $avancado = $this->session->userdata(self::FILTRO_AVANCADO);
    if (!is_array($avancado)) $avancado = [];
    if (!array_key_exists('id_service_type', $avancado)) $avancado['id_service_type'] = 0;
    if (!array_key_exists('situation', $avancado)) $avancado['situation'] = '';

    $this->session->set_userdata(self::FILTRO_AVANCADO, $avancado);
  }

  /**
   * Catálogo da situação contratual do cliente. O alias é o que fica gravado
   * na sessão e o que montarWhereListagem() traduz em SQL — "mostrar todos" é
   * a ausência de filtro (valor vazio), e não uma terceira opção com condição
   * própria.
   *
   * @return array alias => rótulo
   */
  private function contractSituations()
  {
    return [
      'com_vigente' => 'Somente clientes com contrato vigente',
      'sem_vigente' => 'Somente clientes sem contrato vigente',
    ];
  }

  /**
   * WHERE da listagem: escopo do tenant + os filtros de contrato.
   *
   * As duas condições comparam colunas DERIVADAS da `crm_customers_v`
   * (migration 018) justamente para caber aqui: o Query Builder do CI3 quebra
   * a string em AND/OR e passa cada lado do operador por `protect_identifiers`,
   * então um subselect escrito à mão neste ponto sairia escapado como se fosse
   * nome de coluna. `FIND_IN_SET(...)` vai sem operador de propósito — sem `=`
   * ou `> 0` o CI3 não reconhece a condição e a repassa intacta, que é o que
   * se quer de uma chamada de função.
   *
   * Os valores são inteiros e aliases de allowlist, nunca texto do POST.
   *
   * @return string
   */
  private function montarWhereListagem()
  {
    $where = 'id_company = ' . (int) $this->getCurrentCompanyId();

    $avancado = (array) $this->session->userdata(self::FILTRO_AVANCADO);

    $idServiceType = isset($avancado['id_service_type']) ? (int) $avancado['id_service_type'] : 0;
    if ($idServiceType > 0) {
      $where .= ' AND FIND_IN_SET(' . $idServiceType . ', service_type_ids)';
    }

    $situacao = isset($avancado['situation']) ? (string) $avancado['situation'] : '';
    if ($situacao === 'com_vigente') $where .= ' AND active_contracts_count > 0';
    if ($situacao === 'sem_vigente') $where .= ' AND active_contracts_count = 0';

    return $where;
  }

  /**
   * Link público de cadastro do tenant selecionado: o token da empresa
   * (crm_companies.token) identifica quem recebe o cliente no wizard.
   * Empresas semeadas manualmente podem não ter token — gera e grava na hora,
   * no mesmo formato do Login::cadastro.
   *
   * @return string
   */
  private function linkCadastroPublico()
  {
    $idCompany = (int) $this->getCurrentCompanyId();
    $company = $this->global_model->getFieldsWhereSingle_off('crm_companies', ['id', 'token'], ['id' => $idCompany], TRUE);

    $token = !empty($company->token) ? trim((string) $company->token) : '';
    if ($token === '') {
      $token = substr(sha1(uniqid('', TRUE)), 0, 12);
      $this->global_model->edit('crm_companies', ['token' => $token], 'id', $idCompany);
    }

    return base_url('cadastro-cliente/' . $token);
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
   * @return object
   */
  private function carregarClienteDaView($id)
  {
    if ($id <= 0) {
      $this->session->set_flashdata('warning', 'Cliente não informado.');
      redirect(base_url('clientes'));
    }

    $cliente = $this->global_model->getWhere_off('crm_customers_v', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($cliente)) {
      $this->session->set_flashdata('warning', 'Cliente não encontrado.');
      redirect(base_url('clientes'));
    }

    return $cliente;
  }

  private function renderForm()
  {
    $this->data['states'] = $this->global_model->getWhereOrderBy_off('crm_country_states', 'id > 0', 'name', 'asc', FALSE);

    // Cidades do estado do cliente em edição; o select encadeado (AJAX
    // clientes/json_get_cities_by_id) cobre a troca de estado.
    $this->data['cities'] = [];
    if (!empty($this->data['result']) && !empty($this->data['result']->id_state)) {
      $this->data['cities'] = $this->global_model->getWhereOrderBy_off('crm_country_cities', ['id_state' => (int) $this->data['result']->id_state], 'name', 'asc', FALSE);
    }

    $this->data['marital_statuses'] = $this->maritalStatuses();

    // Attributes decodificados para popular as abas do formulário.
    $attrs = [];
    if (!empty($this->data['result']) && !empty($this->data['result']->attributes)) {
      $attrs = json_decode((string) $this->data['result']->attributes, TRUE);
    }
    $this->data['attrs'] = is_array($attrs) ? $attrs : [];

    // Cidades do estado residencial do representante, para o select encadeado.
    $this->data['rep_cities'] = [];
    $repIdState = isset($this->data['attrs']['representative']['address']['id_state']) ? (int) $this->data['attrs']['representative']['address']['id_state'] : 0;
    if ($repIdState > 0) {
      $this->data['rep_cities'] = $this->global_model->getWhereOrderBy_off('crm_country_cities', ['id_state' => $repIdState], 'name', 'asc', FALSE);
    }

    $this->load->view('header', $this->data);
    $this->load->view('customers/edit', $this->data);
    $this->load->view('footer', $this->data);
  }

  private function setFormRules()
  {
    $this->form_validation->set_rules('customer[type]', 'Tipo', 'required|in_list[J,F]');
    $this->form_validation->set_rules('customer[document]', 'Documento', 'required|trim');
    $this->form_validation->set_rules('customer[name]', 'Razão social / Nome', 'required|trim|max_length[150]');
    $this->form_validation->set_rules('customer[byname]', 'Nome fantasia', 'trim|max_length[150]');
    // Opcional: em branco vira ISENTO na gravação (e PF nem exibe o campo).
    $this->form_validation->set_rules('customer[state_registration]', 'Inscrição estadual', 'trim|max_length[20]');
    $this->form_validation->set_rules('customer[email]', 'E-mail do contrato', 'required|trim|valid_email|max_length[150]');
    $this->form_validation->set_rules('customer[address_zip]', 'CEP', 'required|trim|min_length[9]');
    $this->form_validation->set_rules('customer[address]', 'Endereço', 'required|trim|max_length[200]');
    $this->form_validation->set_rules('customer[address_number]', 'Número', 'required|trim|max_length[20]');
    $this->form_validation->set_rules('customer[address_district]', 'Bairro', 'required|trim|max_length[150]');
    $this->form_validation->set_rules('customer[id_state]', 'Estado', 'required|is_natural_no_zero');
    $this->form_validation->set_rules('customer[id_city]', 'Cidade', 'required|is_natural_no_zero');
    $this->form_validation->set_message('required', 'O campo {field} deve ser preenchido.');
    $this->form_validation->set_message('min_length', 'O campo {field} deve ter pelo menos {param} caracteres.');
  }

  /**
   * Valida o que o form_validation não cobre e monta o array de gravação
   * dos campos físicos.
   *
   * @return array|bool FALSE quando algo é inválido (flashdata já definido)
   */
  private function montarDadosDoPost()
  {
    $post = (array) $this->input->post('customer');

    $tipo = isset($post['type']) ? trim((string) $post['type']) : '';
    $documento = sonumero(isset($post['document']) ? $post['document'] : '');

    if ($tipo === 'F') {
      if (strlen($documento) !== 11 || !valida_cpf($documento)) {
        $this->session->set_flashdata('warning', 'CPF inválido, verifique.');
        return FALSE;
      }
    } else {
      if (strlen($documento) !== 14 || !valida_cnpj($documento)) {
        $this->session->set_flashdata('warning', 'CNPJ inválido, verifique.');
        return FALSE;
      }
    }

    $campo = function ($chave) use ($post) {
      return isset($post[$chave]) ? trim((string) $post[$chave]) : '';
    };

    if ($tipo === 'J' && $campo('byname') === '') {
      $this->session->set_flashdata('warning', 'Informe o nome fantasia da empresa.');
      return FALSE;
    }

    return [
      'type' => $tipo,
      'document' => $documento,
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
      'id_state' => (int) $campo('id_state'),
      'id_city' => (int) $campo('id_city'),
    ];
  }

  /**
   * Aplica o POST de `attr[...]` sobre os attributes atuais, respeitando a
   * whitelist de seções/chaves. `consent` e `source` nunca entram aqui.
   *
   * @param  array $atuais
   * @return array
   */
  private function montarAttributesDoPost($atuais)
  {
    $post = (array) $this->input->post('attr');
    $whitelist = $this->attrWhitelist();

    foreach ($whitelist as $secao => $chaves) {
      if (!isset($post[$secao]) || !is_array($post[$secao])) continue;
      if (!isset($atuais[$secao]) || !is_array($atuais[$secao])) $atuais[$secao] = [];

      foreach ($chaves as $chave) {
        if ($chave === 'address') {
          if (!isset($post[$secao]['address']) || !is_array($post[$secao]['address'])) continue;
          if (!isset($atuais[$secao]['address']) || !is_array($atuais[$secao]['address'])) $atuais[$secao]['address'] = [];
          foreach (['street', 'number', 'complement', 'district', 'zip', 'id_state', 'id_city'] as $c) {
            if (array_key_exists($c, $post[$secao]['address'])) {
              $atuais[$secao]['address'][$c] = trim((string) $post[$secao]['address'][$c]);
            }
          }
          continue;
        }

        if (array_key_exists($chave, $post[$secao])) {
          $atuais[$secao][$chave] = trim((string) $post[$secao][$chave]);
        }
      }
    }

    // Normalizações pontuais.
    if (isset($atuais['representative']['cpf'])) {
      $atuais['representative']['cpf'] = sonumero($atuais['representative']['cpf']);
    }
    if (isset($atuais['representative']['address']) && is_array($atuais['representative']['address'])) {
      // city/uf são DERIVADOS do id_city no servidor (mesma regra do wizard) —
      // nunca vêm direto do POST.
      $idCityRep = isset($atuais['representative']['address']['id_city']) ? (int) $atuais['representative']['address']['id_city'] : 0;
      $cidadeRep = $idCityRep > 0 ? $this->global_model->getWhere_off('crm_country_cities_v', ['id' => $idCityRep], TRUE) : NULL;
      $atuais['representative']['address']['id_city'] = $idCityRep;
      if (!empty($cidadeRep)) $atuais['representative']['address']['id_state'] = (int) $cidadeRep->id_state;
      $atuais['representative']['address']['city'] = !empty($cidadeRep) ? $cidadeRep->name : '';
      $atuais['representative']['address']['uf'] = !empty($cidadeRep) ? $cidadeRep->UF : '';
    }
    return $atuais;
  }

  /**
   * Seções e chaves de `attributes` que o painel pode editar.
   *
   * @return array
   */
  private function attrWhitelist()
  {
    return [
      'representative' => ['name', 'nationality', 'marital_status', 'profession', 'rg', 'cpf', 'whatsapp', 'address'],
      'billing' => ['name', 'email', 'whatsapp', 'needs_invoice'],
      'domains' => ['primary', 'secondary'],
      'contract' => ['comments'],
    ];
  }

  /**
   * @return array alias => rótulo
   */
  private function maritalStatuses()
  {
    return [
      'solteiro' => 'Solteiro(a)',
      'casado' => 'Casado(a)',
      'separado' => 'Separado(a)',
      'divorciado' => 'Divorciado(a)',
      'viuvo' => 'Viúvo(a)',
    ];
  }

  /**
   * @param  string $documento
   * @param  int    $idCompany
   * @param  int    $ignorarId
   * @return bool
   */
  private function documentoEmUso($documento, $idCompany, $ignorarId)
  {
    $existente = $this->global_model->getFieldsWhereSingle_off(
      'crm_customers',
      ['id'],
      ['document' => $documento, 'id_company' => (int) $idCompany],
      TRUE
    );

    return !empty($existente) && (int) $existente->id !== (int) $ignorarId;
  }
}
