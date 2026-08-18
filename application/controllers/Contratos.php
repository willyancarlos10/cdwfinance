<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contratos do cliente (crm_contracts).
 *
 * Não há listagem própria: contrato nasce pelo modal da visão geral do
 * cliente (Clientes::info) e vive na própria página de visão geral
 * (contratos/info), no layout do mockup da gestão interna — KPIs, uso de
 * espaço, dados gerais editáveis, domínios (placeholder até existir o vínculo
 * cliente↔domínio em crm_servers_domains) e documentos.
 *
 * O escopo é sempre a empresa selecionada no filtro do topo
 * (getCurrentCompanyId), como nos demais módulos.
 */
class Contratos extends MY_Controller
{
  /** Quantas contas pendentes o alerta da tela lista antes de resumir. */
  const LIMITE_AVISO_SUSPENSAO = 8;

  /** Cache do catálogo de motivos de encerramento (ver endReasons()). */
  private $cacheEndReasons = NULL;

  public function __construct()
  {
    parent::__construct();
    $this->data['menu'] = 'clientes';
  }

  // ------------------------------------------------------------------
  // Criação (modal da visão geral do cliente)
  // ------------------------------------------------------------------

  public function post_novo()
  {
    $idCustomer = (int) $this->input->post('id');
    $cliente = $this->global_model->getWhere_off('crm_customers', [
      'id' => $idCustomer,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($cliente)) {
      $this->session->set_flashdata('warning', 'Cliente não encontrado.');
      redirect(base_url('clientes'));
    }

    $dados = $this->montarDadosDoPost();
    if ($dados === FALSE) {
      redirect(base_url('clientes/info?id=' . $idCustomer));
    }

    $servicos = $this->montarServicosDoPost();
    if (empty($servicos)) {
      $this->session->set_flashdata('error', 'Selecione ao menos um tipo de serviço para o contrato.');
      redirect(base_url('clientes/info?id=' . $idCustomer));
    }

    $idUser = (int) $this->session->userdata('user')->id;

    $this->db->trans_begin();

    $dados['id_customer'] = $idCustomer;
    $dados['id_company'] = (int) $cliente->id_company;
    $dados['status'] = 'vigente';
    // `created` já vem do montarDadosDoPost (o modal permite retroagir a data);
    // em branco, ele devolve o carimbo de agora.
    $dados['created_by'] = $idUser;

    $idContract = $this->global_model->add('crm_contracts', $dados);
    if (!empty($idContract)) {
      foreach ($servicos as $idServico) {
        $this->global_model->add('crm_contracts_services', [
          'id_contract' => (int) $idContract,
          'id_service_type' => $idServico,
        ]);
      }
    }

    if (empty($idContract) || $this->db->trans_status() === FALSE) {
      $this->db->trans_rollback();
      $this->session->set_flashdata('error', 'Não foi possível criar o contrato.');
      redirect(base_url('clientes/info?id=' . $idCustomer));
    }
    $this->db->trans_commit();

    $this->session->set_flashdata('success', 'Contrato criado com sucesso.');
    redirect(base_url('contratos/info?id=' . (int) $idContract));
  }

  // ------------------------------------------------------------------
  // Visão geral do contrato
  // ------------------------------------------------------------------

  public function info()
  {
    $id = (int) $this->input->get('id');
    $this->data['result'] = $this->carregarContrato($id);

    $this->data['cycles'] = $this->cycles();
    // Todos para traduzir o rótulo do contrato já encerrado; só os ativos
    // para o select do modal (ver endReasons()).
    $this->data['end_reasons'] = $this->endReasons();
    $this->data['end_reasons_ativos'] = $this->endReasons(TRUE);

    $servicos = $this->global_model->getWhere_off('crm_contracts_services_v', ['id_contract' => $id], FALSE);
    $this->data['selected_services'] = array_map(function ($s) {
      return (int) $s->id_service_type;
    }, $servicos);

    // Tipos ativos + os já vinculados (mesmo que inativados depois), para o
    // multiselect não "perder" um vínculo existente ao salvar.
    $todos = $this->global_model->getWhereOrderBy_off('crm_service_types', 'id > 0', 'name', 'asc', FALSE);
    $this->data['service_types'] = $this->filterFormCategories($todos, $this->data['selected_services']);

    $this->data['files'] = $this->global_model->getWhereOrderBy_off('crm_contracts_files_v', ['id_contract' => $id], 'id', 'desc', FALSE);

    $this->data['domains'] = $this->global_model->getWhereOrderBy_off('crm_contracts_domains_v', ['id_contract' => $id], 'domain', 'asc', FALSE);

    // Uso do contrato: soma o disco SÓ dos domínios com vínculo (domínio sem
    // correspondência no servidor não entra na soma).
    //
    // Cada domínio de servidor conta UMA vez: a busca do cadastro procura o nome
    // exato e a variante com/sem "www.", então `foo.com` e `www.foo.com` no mesmo
    // contrato caem na MESMA linha de crm_servers_domains e, somados os dois,
    // dobrariam o uso. O card de espaço do Dashboard deduplica pelo mesmo par.
    //
    // A dedup é por `id_server_domain`, e não por nome, justamente porque o
    // MESMO nome pode entrar duas vezes apontando para servidores diferentes
    // (site num painel, e-mail em outro): são duas contas, dois discos, e as
    // duas entram na soma.
    $usadoMb = 0.0;
    $comVinculo = 0;
    $vistos = [];
    foreach ($this->data['domains'] as $d) {
      if (!empty($d->id_server_domain)) {
        $comVinculo++;
        if (isset($vistos[(int) $d->id_server_domain])) continue;
        $vistos[(int) $d->id_server_domain] = TRUE;
        $usadoMb += (float) $d->server_disk_used_mb;
      }
    }
    $this->data['uso_gb'] = $usadoMb / 1024;
    $this->data['dominios_com_vinculo'] = $comVinculo;

    // Aba Extrato Bom Controle: só uma leitura de banco (sem rede) — a aba
    // mostra o aviso de integração desativada sem esperar o AJAX do extrato.
    $this->load->model('bomcontrole_model');
    $this->data['bomcontrole_ativo'] = $this->bomcontrole_model->isActive((int) $this->getCurrentCompanyId());

    // Faturamento próprio
    $this->load->model('invoice_model');
    $this->load->model('adjustment_model');

    $this->data['invoice_policies'] = $this->invoicePolicies();
    $this->data['adjustment_indexes'] = $this->adjustmentIndexes();
    // As faturas do contrato vivem na aba Faturas, carregadas por AJAX e
    // paginadas (Contratos::json_postfaturas). Traziam-se todas aqui, sem
    // limite: um contrato mensal antigo carregava dezenas de linhas em toda
    // abertura da tela, para uma tabela que estava fora da dobra.
    //
    // As cobranças avulsas vêm direto: são poucas por contrato (uma venda
    // pontual de cada vez), e o bloco fica ao lado do histórico de reajustes,
    // que segue a mesma regra.
    $this->load->model('charge_model');
    $this->data['charges'] = $this->charge_model->listarPorContrato($id, (int) $this->getCurrentCompanyId());

    // Teto de parcelas: no ciclo, o número de meses (parcela que passa disso
    // invade a competência seguinte); na avulsa, o limite comercial do model.
    $this->data['max_parcelas_ciclo'] = max(1, (int) $this->invoice_model->mesesDoCiclo((string) $this->data['result']->cycle));
    $this->data['max_parcelas_avulsa'] = Charge_model::MAX_PARCELAS;

    // Notificações do contrato. O repeater precisa de ao menos uma linha em
    // branco para ter o que clonar, e o array vazio é o caso da maioria.
    $this->data['notification_types'] = $this->notificationTypes();
    $config = json_decode((string) $this->data['result']->notification_config, TRUE);
    $this->data['notification_emails'] = (is_array($config) && !empty($config['emails'])) ? $config['emails'] : [];
    $this->data['notification_whatsapps'] = (is_array($config) && !empty($config['whatsapps'])) ? $config['whatsapps'] : [];

    $this->data['adjustments'] = $this->global_model->getWhereOrderBy_off(
      'crm_contracts_adjustments_v',
      ['id_contract' => $id],
      'applied_at',
      'desc',
      FALSE
    );

    // Sugestões para quem ainda não configurou: dia de vencimento do parâmetro
    // global e o próximo aniversário do contrato que ainda não passou.
    $this->data['billing_day_sugerido'] = $this->invoice_model->diaPadrao();
    $this->data['proximo_aniversario'] = $this->adjustment_model->proximoAniversario(
      (string) $this->data['result']->created
    );

    $this->load->view('header', $this->data);
    $this->load->view('contracts/info', $this->data);
    $this->load->view('footer', $this->data);
  }

  // ------------------------------------------------------------------
  // Faturamento próprio
  // ------------------------------------------------------------------

  /**
   * Política de emissão da nota fiscal.
   *
   * Slug e não booleano, como endReasons(): são três estados de negócio, e a
   * pergunta seguinte ("quantos clientes emitem NF só depois de pagar") pede
   * GROUP BY. `pos_compensacao` fica cadastrável desde já, mas só passa a ter
   * efeito quando existir baixa de pagamento — sem saber que a fatura foi paga
   * não há gatilho para emitir.
   *
   * @return array slug => rótulo
   */
  private function invoicePolicies()
  {
    return [
      'nao_emitir' => 'Não emitir',
      'com_boleto' => 'Emitir junto com o boleto',
      'pos_compensacao' => 'Emitir após compensação',
    ];
  }

  /**
   * Tipos de destinatário das notificações do contrato.
   *
   * Mesmos slugs do `Form_model` do painel-v3, de onde veio o desenho do
   * repeater: o código que um dia fizer o envio separa `to`/`cc`/`cco` por
   * aqui, e usar o mesmo vocabulário evita traduzir de um lado para o outro.
   *
   * @return array slug => rótulo
   */
  private function notificationTypes()
  {
    return [
      'destinatario' => 'Destinatário',
      'copia' => 'Cópia',
      'copia_oculta' => 'Cópia Oculta',
    ];
  }

  /**
   * Destinatários de notificação vindos do repeater, normalizados para o JSON
   * de `crm_contracts.notification_config`.
   *
   * Nada aqui é obrigatório: os campos ainda não são lidos por ninguém, e
   * exigir preenchimento travaria o SALVAR FATURAMENTO dos 403 contratos por
   * uma configuração que não faz nada ainda.
   *
   * @param  array $post o array `notification` do POST
   * @return array|bool FALSE quando algo é inválido (flashdata já definido)
   */
  private function montarNotificacoesDoPost(array $post)
  {
    $tipos = $this->notificationTypes();

    $emails = [];
    $vistos = [];
    $temDestinatario = FALSE;

    $linhas = isset($post['emails']) && is_array($post['emails']) ? $post['emails'] : [];

    foreach ($linhas as $linha) {
      $email = mb_strtolower(trim((string) (isset($linha['email']) ? $linha['email'] : '')));

      // Linha em branco é o estado natural do repeater (o usuário clicou em
      // "adicionar" e desistiu): some em silêncio, não vira erro.
      if ($email === '') continue;

      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->session->set_flashdata('warning', 'E-mail de notificação inválido: ' . $email);
        return FALSE;
      }

      // O mesmo e-mail duas vezes é a mesma pessoa recebendo duas cópias.
      if (isset($vistos[$email])) continue;
      $vistos[$email] = TRUE;

      $tipo = (string) (isset($linha['type']) ? $linha['type'] : '');
      if (!array_key_exists($tipo, $tipos)) $tipo = 'destinatario';
      if ($tipo === 'destinatario') $temDestinatario = TRUE;

      $emails[] = ['email' => mb_substr($email, 0, 190), 'type' => $tipo];
    }

    // Lista só de cópia não tem para quem mandar: o "para" de um e-mail não
    // pode ficar vazio, e o servidor de e-mail recusaria o envio.
    if (!empty($emails) && !$temDestinatario) {
      $this->session->set_flashdata('warning', 'Marque ao menos um e-mail como "Destinatário" — uma lista só de cópias não tem para quem enviar.');
      return FALSE;
    }

    $whatsapps = [];
    $vistosFone = [];

    $linhas = isset($post['whatsapps']) && is_array($post['whatsapps']) ? $post['whatsapps'] : [];

    foreach ($linhas as $linha) {
      $fone = preg_replace('/\D/', '', (string) (isset($linha['phone']) ? $linha['phone'] : ''));
      if ($fone === '') continue;

      // 10 = fixo com DDD; 13 = 55 + DDD + 9 dígitos. Fora disso não há número
      // de WhatsApp possível, e guardar lixo aqui só adia a descoberta.
      if (strlen($fone) < 10 || strlen($fone) > 13) {
        $this->session->set_flashdata('warning', 'Telefone de WhatsApp inválido: ' . $fone);
        return FALSE;
      }

      if (isset($vistosFone[$fone])) continue;
      $vistosFone[$fone] = TRUE;

      $whatsapps[] = ['phone' => $fone];
    }

    // Sem nada configurado a coluna fica NULL, e não com um JSON de listas
    // vazias: assim "não configurado" se distingue de "configurado e limpo"
    // numa consulta simples.
    if (empty($emails) && empty($whatsapps)) return NULL;

    return json_encode(
      ['emails' => $emails, 'whatsapps' => $whatsapps],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
  }
  /**
   * Índices de reajuste. `nenhum` é a ausência de reajuste, e por isso encabeça
   * a lista e é o default da coluna.
   *
   * @return array slug => rótulo
   */
  private function adjustmentIndexes()
  {
    return ['nenhum' => 'Sem reajuste'] + $this->adjustmentIndexesCatalogo();
  }

  /**
   * Só os índices de verdade — o catálogo que o Adjustment_model conhece.
   *
   * @return array slug => rótulo
   */
  private function adjustmentIndexesCatalogo()
  {
    $this->load->model('adjustment_model');
    return $this->adjustment_model->indexes();
  }

  /**
   * Grava a configuração de faturamento do contrato.
   *
   * É o ponto onde um contrato deixa de ser cobrado pelo Bom Controle e passa
   * a ser faturado aqui — daí as guardas serem tão explícitas.
   */
  public function post_faturamento()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);

    if ((string) $contrato->status === 'encerrado') {
      $this->session->set_flashdata('warning', 'Contrato encerrado não tem faturamento a configurar.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $post = (array) $this->input->post('billing');
    $campo = function ($chave) use ($post) {
      return isset($post[$chave]) ? trim((string) $post[$chave]) : '';
    };

    $origem = $campo('billing_source') === 'cdwfinance' ? 'cdwfinance' : 'bomcontrole';

    $politica = $campo('invoice_policy');
    if (!array_key_exists($politica, $this->invoicePolicies())) {
      $this->session->set_flashdata('warning', 'Política de nota fiscal inválida.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $indice = $campo('adjustment_index');
    if (!array_key_exists($indice, $this->adjustmentIndexes())) {
      $this->session->set_flashdata('warning', 'Índice de reajuste inválido.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    // Destinatários de notificação: gravados sempre, inclusive quando o
    // faturamento é do Bom Controle. Quem avisa o cliente sobre este
    // contrato é pergunta independente de quem emite a cobrança, e
    // apagar a lista ao virar a chave perderia cadastro sem ninguém pedir.
    $notificacoes = $this->montarNotificacoesDoPost((array) $this->input->post('notification'));
    if ($notificacoes === FALSE) {
      redirect(base_url('contratos/info?id=' . $id));
    }

    $dados = [
      'billing_source' => $origem,
      'invoice_policy' => $politica,
      'adjustment_index' => $indice,
      'notification_config' => $notificacoes,
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => (int) $this->session->userdata('user')->id,
    ];

    if ($origem === 'cdwfinance') {
      $resultado = $this->montarFaturamentoAtivo($contrato, $post, $indice);
      if ($resultado === FALSE) {
        redirect(base_url('contratos/info?id=' . $id));
      }
      $dados = array_merge($dados, $resultado);
    } else {
      // Voltando para o ERP: as âncoras são zeradas para o motor daqui parar de
      // enxergar o contrato. Sem isso, desligar e religar retomaria de uma
      // competência antiga e geraria faturas retroativas sem que ninguém pedisse.
      $dados['next_competence'] = NULL;
      $dados['next_adjustment'] = NULL;
      $dados['adjustment_notified_for'] = NULL;
    }

    if ($indice === 'nenhum') {
      $dados['next_adjustment'] = NULL;
      $dados['adjustment_notified_for'] = NULL;
    }

    $this->global_model->edit('crm_contracts', $dados, 'id', $id);

    $this->session->set_flashdata('success', 'Configuração de faturamento salva.');
    redirect(base_url('contratos/info?id=' . $id));
  }

  /**
   * Valida e monta os campos exigidos quando o faturamento passa a ser daqui.
   *
   * @param  object $contrato
   * @param  array  $post
   * @param  string $indice
   * @return array|bool FALSE quando algo é inválido (flashdata já definido)
   */
  private function montarFaturamentoAtivo($contrato, array $post, $indice)
  {
    $campo = function ($chave) use ($post) {
      return isset($post[$chave]) ? trim((string) $post[$chave]) : '';
    };

    // O contrato ainda cobrado no ERP é o caso perigoso: ligar aqui sem
    // encerrar lá cobra o cliente duas vezes. Enquanto o encerramento
    // automático não existe (depende da API), a confirmação é o degrau.
    $jaEraDaqui = ((string) $contrato->billing_source === 'cdwfinance');
    if (!$jaEraDaqui && !empty($contrato->bomcontrole_contract_id) && $campo('confirma_erp') !== '1') {
      $this->session->set_flashdata('warning', 'Este contrato ainda está vinculado ao contrato #' . (int) $contrato->bomcontrole_contract_id . ' do Bom Controle. Encerre-o por lá antes de faturar pelo CDW Finance e marque a confirmação — sem isso o cliente seria cobrado duas vezes.');
      return FALSE;
    }

    $dia = (int) $campo('billing_day');
    if ($dia < 1 || $dia > 31) {
      $this->session->set_flashdata('warning', 'Informe o dia de vencimento (de 1 a 31).');
      return FALSE;
    }

    $this->load->model('invoice_model');

    // Conferência estrita, no mesmo padrão da data de criação do contrato e do
    // vencimento de domínio: `databanco()` não serve aqui porque devolve
    // 1970-01-01 para qualquer lixo, e um campo em branco viraria uma
    // competência de 1970 — que o motor tentaria faturar mês a mês até hoje.
    $competencia = $this->dataDoPost($campo('next_competence'), 'a competência inicial');
    if ($competencia === FALSE) return FALSE;

    // Parcela que passa do ciclo invadiria a competência seguinte: um mensal
    // em 2× teria a parcela 2 vencendo no mês da competência seguinte, que por
    // sua vez traria a sua parcela 1 — sobreposição que só cresce. Mensal só
    // aceita 1.
    $mesesDoCiclo = $this->invoice_model->mesesDoCiclo((string) $contrato->cycle);
    $parcelas = (int) $campo('installments');
    if ($parcelas < 1) $parcelas = 1;

    if ($mesesDoCiclo > 0 && $parcelas > $mesesDoCiclo) {
      $rotulos = $this->cycles();
      $rotulo = isset($rotulos[(string) $contrato->cycle]) ? mb_strtolower($rotulos[(string) $contrato->cycle]) : (string) $contrato->cycle;
      $this->session->set_flashdata('warning', 'Um contrato ' . $rotulo . ' aceita no máximo ' . $mesesDoCiclo . ' parcela(s) por competência.');
      return FALSE;
    }

    $dados = [
      'billing_day' => $dia,
      'next_competence' => substr($competencia, 0, 8) . '01',
      'installments' => $parcelas,
    ];

    if ($indice !== 'nenhum') {
      $reajuste = $this->dataDoPost($campo('next_adjustment'), 'a data do próximo reajuste');
      if ($reajuste === FALSE) return FALSE;
      $dados['next_adjustment'] = $reajuste;
    }

    return $dados;
  }

  /**
   * Data dd/mm/aaaa do formulário → Y-m-d, com conferência estrita.
   *
   * @param  string $texto
   * @param  string $rotulo usado na mensagem de erro
   * @return string|bool FALSE quando inválida (flashdata já definido)
   */
  private function dataDoPost($texto, $rotulo)
  {
    $texto = trim((string) $texto);

    if ($texto === '') {
      $this->session->set_flashdata('warning', 'Informe ' . $rotulo . ' no formato dd/mm/aaaa.');
      return FALSE;
    }

    $data = DateTime::createFromFormat('d/m/Y', $texto);
    if (!$data || $data->format('d/m/Y') !== $texto) {
      $this->session->set_flashdata('warning', 'Informe ' . $rotulo . ' no formato dd/mm/aaaa.');
      return FALSE;
    }

    if ((int) $data->format('Y') < 2000) {
      $this->session->set_flashdata('warning', 'Informe ' . $rotulo . ' a partir do ano 2000.');
      return FALSE;
    }

    return $data->format('Y-m-d');
  }

  /**
   * Gera a fatura da competência corrente sob demanda.
   */
  /**
   * Lança uma cobrança avulsa parcelada no contrato.
   *
   * Ao contrário da recorrência, as parcelas nascem AQUI, no ato: a obrigação
   * inteira já existe e não há competência futura a acompanhar. Quem valida e
   * grava é o Charge_model — este método só resolve o escopo e traduz o
   * formulário.
   */
  public function post_lancarcobranca()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);

    $post = (array) $this->input->post('charge');
    $campo = function ($chave) use ($post) {
      return isset($post[$chave]) ? trim((string) $post[$chave]) : '';
    };

    // Data pela conferência estrita de sempre — `databanco()` devolveria
    // 1970-01-01 para lixo, e a cobrança nasceria vencida há 56 anos.
    $vencimento = $this->dataDoPost($campo('due_date'), 'a data do primeiro vencimento');
    if ($vencimento === FALSE) {
      redirect(base_url('contratos/info?id=' . $id));
    }

    $politica = $campo('invoice_policy');
    if (!array_key_exists($politica, $this->invoicePolicies())) {
      $politica = (string) $contrato->invoice_policy;
    }

    $this->load->model('charge_model');

    $resultado = $this->charge_model->lancar($id, (int) $this->getCurrentCompanyId(), (int) $this->session->userdata('user')->id, [
      'description' => $campo('description'),
      'value' => (float) removerFormatacaoNumero($campo('value')),
      'installments' => (int) $campo('installments'),
      'due_date' => $vencimento,
      'invoice_policy' => $politica,
      'comments' => $campo('comments'),
    ]);

    $this->session->set_flashdata(
      $resultado['success'] ? 'success' : 'warning',
      $resultado['success'] ? 'Cobrança lançada — ' . $resultado['message'] : $resultado['message']
    );

    redirect(base_url('contratos/info?id=' . $id));
  }

  /**
   * Cancela a cobrança e as parcelas ainda abertas.
   *
   * Não há exclusão: cobrança é registro financeiro, como a fatura. É isso que
   * permite a sentinela `id_charge = 0` viver sem FK — a linha apontada nunca
   * desaparece.
   */
  public function post_cancelarcobranca()
  {
    $id = (int) $this->input->post('id');
    $this->carregarContratoDaTabela($id);

    $this->load->model('charge_model');

    $resultado = $this->charge_model->cancelar(
      (int) $this->input->post('id_charge'),
      (int) $this->getCurrentCompanyId(),
      (int) $this->session->userdata('user')->id
    );

    $this->session->set_flashdata($resultado['success'] ? 'success' : 'warning', $resultado['message']);
    redirect(base_url('contratos/info?id=' . $id));
  }

  public function json_postgerarfatura()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    $this->load->model('invoice_model');
    $resultado = $this->invoice_model->generateNow(
      $id,
      (int) $this->getCurrentCompanyId(),
      (int) $this->session->userdata('user')->id
    );

    $geradas = (int) $resultado['data']['geradas'];
    $mensagem = $resultado['success']
      ? ($geradas > 0 ? $geradas . ' fatura(s) gerada(s).' : 'Nenhuma competência pendente — as faturas deste contrato já foram geradas.')
      : $resultado['message'];

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $mensagem,
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['faturamento' => $resultado['message']],
    ]);
  }

  /**
   * Busca no catálogo de serviços do Bom Controle.
   *
   * Sob demanda, a partir do termo digitado: o rate limit do ERP não tolera
   * varrer o catálogo (119 serviços) a cada abertura do modal.
   */
  public function json_postbuscarservicobc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->buscarServicos(
      (int) $this->getCurrentCompanyId(),
      (string) $this->input->post('termo')
    );

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Vincula o serviço do ERP ao contrato — é o `Servicos[].IdServico` que a
   * emissão da cobrança vai usar.
   *
   * O id do POST é revalidado no servidor (`Servico/Obter`) antes de gravar.
   */
  public function json_postvincularservicobc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->vincularServico(
      $id,
      (int) $this->getCurrentCompanyId(),
      (int) $this->input->post('id_servico'),
      (int) $this->session->userdata('user')->id
    );

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Remove o vínculo do serviço. Não altera nada no ERP.
   */
  public function json_postdesvincularservicobc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->desvincularServico(
      $id,
      (int) $this->getCurrentCompanyId(),
      (int) $this->session->userdata('user')->id
    );

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Envia (ou reenvia) o aviso de reajuste ao cliente.
   */
  public function json_postavisarreajuste()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    $contrato = $this->global_model->getWhere_off('crm_contracts', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($contrato)) {
      echo json_encode($this->jsonErro('Contrato não encontrado.'));
      return;
    }

    if ((string) $contrato->adjustment_index === 'nenhum' || empty($contrato->next_adjustment)) {
      echo json_encode($this->jsonErro('Este contrato não tem reajuste configurado.'));
      return;
    }

    $this->load->model('adjustment_model');
    $resultado = $this->adjustment_model->notifyContract(
      $contrato,
      (int) $this->session->userdata('user')->id
    );

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['reajuste' => $resultado['message']],
    ]);
  }

  public function post_salvar()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);

    // A query do dashboard lê o `value` AO VIVO. Editar o valor de um contrato
    // encerrado moveria a barra de saídas de um mês já fechado — que é a
    // decisão "reajuste não entra no gráfico" entrando pela porta dos fundos.
    // Domínios e documentos continuam livres de propósito: anexar o distrato e
    // remover domínios é exatamente o que se faz depois de encerrar.
    if ($contrato->status === 'encerrado') {
      $this->session->set_flashdata('error', 'Contrato encerrado não pode ser editado — reabra o contrato para alterar os dados gerais.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    // O contrato atual entra para a data de criação: só a data vem da tela, e a
    // hora gravada é preservada.
    $dados = $this->montarDadosDoPost($contrato);
    if ($dados === FALSE) {
      redirect(base_url('contratos/info?id=' . $id));
    }

    $servicos = $this->montarServicosDoPost();
    if (empty($servicos)) {
      $this->session->set_flashdata('error', 'Selecione ao menos um tipo de serviço para o contrato.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $dados['modified'] = date('Y-m-d H:i:s');
    $dados['modified_by'] = (int) $this->session->userdata('user')->id;

    $this->db->trans_begin();

    $this->global_model->edit('crm_contracts', $dados, 'id', $id);

    // Sincroniza o N:N: apaga e regrava a seleção do formulário.
    $this->db->where('id_contract', $id);
    $this->db->delete('crm_contracts_services');
    foreach ($servicos as $idServico) {
      $this->global_model->add('crm_contracts_services', [
        'id_contract' => $id,
        'id_service_type' => $idServico,
      ]);
    }

    if ($this->db->trans_status() === FALSE) {
      $this->db->trans_rollback();
      $this->session->set_flashdata('error', 'Não foi possível salvar os dados do contrato.');
      redirect(base_url('contratos/info?id=' . $id));
    }
    $this->db->trans_commit();

    $this->session->set_flashdata('success', 'Dados gerais salvos com sucesso.');
    redirect(base_url('contratos/info?id=' . $id));
  }

  /**
   * Suspende ou reativa — a AÇÃO vem do POST e é validada contra o status
   * atual.
   *
   * Era um toggle cego (`$novo = ($atual === 'vigente') ? 'suspenso' :
   * 'vigente'`), que com a chegada de 'encerrado' devolvia encerrado → vigente
   * SEM limpar o `ended`: o contrato voltava a contar como vigente e continuava
   * somando na barra de saídas do mês. Esconder o botão na tela não bastava —
   * o endpoint aceita POST direto.
   *
   * Com a tabela de transições, qualquer status futuro cai no erro em vez de
   * virar 'vigente' por descuido.
   *
   * A parada do serviço acompanha a parada do contrato: SUSPENDER suspende nos
   * painéis as contas dos domínios vinculados e REATIVAR devolve. O status
   * local muda de qualquer jeito — painel fora do ar não pode impedir o
   * financeiro de registrar a suspensão —, e o que não foi aplicado é listado
   * na tela para o operador resolver no painel.
   */
  public function post_status()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);
    $acao = (string) $this->input->post('acao');

    $transicoes = $this->statusTransicoes();

    if (!isset($transicoes[$acao])) {
      $this->session->set_flashdata('error', 'Ação de status inválida.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    if ($contrato->status !== $transicoes[$acao]['de']) {
      $this->session->set_flashdata('error', $contrato->status === 'encerrado'
        ? 'Contrato encerrado: reabra o contrato antes de suspender ou reativar.'
        : 'Esta ação não vale para um contrato ' . $contrato->status . '.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    // Rede antes do banco: o status só é gravado depois de tentar o painel, e o
    // laço pode levar segundos por conta (ver Server_model).
    @set_time_limit(0);
    $this->load->model('server_model');
    $suspensao = $this->server_model->suspendContractAccounts(
      $id,
      (int) $this->getCurrentCompanyId(),
      $acao,
      (int) $this->session->userdata('user')->id
    );

    $this->global_model->edit('crm_contracts', [
      'status' => $transicoes[$acao]['para'],
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => (int) $this->session->userdata('user')->id,
    ], 'id', $id);

    $this->session->set_flashdata('success', $transicoes[$acao]['ok'] . $this->resumoSuspensao($suspensao));

    $aviso = $this->avisoSuspensao($suspensao);
    if ($aviso !== '') {
      $this->session->set_flashdata('warning', $aviso);
    }

    redirect(base_url('contratos/info?id=' . $id));
  }

  /**
   * Encerra o contrato — é este carimbo que alimenta a barra de saídas do
   * dashboard.
   *
   * Funciona a partir de vigente E de suspenso: suspender por inadimplência e
   * depois encerrar é o caminho mais comum, e exigir a reativação antes faria o
   * contrato aparecer como vigente no meio do caminho.
   *
   * Encerrar faz o mesmo que suspender nos painéis (as contas dos domínios
   * vinculados são suspensas) e, para cada conta que o painel confirmou,
   * DESVINCULA o domínio: a linha continua no contrato, com o histórico do que
   * foi contratado, mas a conta do servidor volta a ser "órfã" — sem contrato,
   * ela aparece no card de domínios do Dashboard como pendência de destino, que
   * é o que ela de fato passou a ser.
   *
   * O desvínculo é por conta, e não do contrato inteiro: o que não pôde ser
   * suspenso continua vinculado, tanto para não sumir da tela quanto para a
   * operação poder ser retomada (reabrir → encerrar de novo processa só o que
   * sobrou). REABRIR não reativa nada, justamente porque o vínculo das contas
   * já aplicadas não existe mais — encerramento é caminho de ida para o
   * serviço, e cliente que volta gera contrato novo.
   */
  public function post_encerrar()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);

    // Idempotência: um duplo-POST (duplo clique, reenvio do formulário)
    // reescreveria o `ended` — e se o mês tivesse virado, a perda saltaria de
    // um mês para o outro.
    if ($contrato->status === 'encerrado') {
      $this->session->set_flashdata('warning', 'Este contrato já está encerrado.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $motivo = trim((string) $this->input->post('reason'));
    if (!array_key_exists($motivo, $this->endReasons(TRUE))) {
      $this->session->set_flashdata('error', 'Selecione um motivo de encerramento válido.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $observacoes = trim((string) $this->input->post('comments'));

    $idCompany = (int) $this->getCurrentCompanyId();
    $idUser = (int) $this->session->userdata('user')->id;

    @set_time_limit(0);
    $this->load->model('server_model');
    $suspensao = $this->server_model->suspendContractAccounts(
      $id,
      $idCompany,
      'suspender',
      $idUser,
      'Contrato #' . $id . ' encerrado no CDW Finance'
    );

    $agora = date('Y-m-d H:i:s');
    $desvincular = array_map('intval', $suspensao['ids_contract_domains']);

    $this->db->trans_begin();

    $this->global_model->edit('crm_contracts', [
      'status' => 'encerrado',
      'ended' => $agora,
      'ended_reason' => $motivo,
      // 300 é o teto da coluna desde a 032; cortar aqui evita o erro seco do
      // MySQL e mantém banco, POST e textarea com o mesmo limite.
      'ended_comments' => ($observacoes !== '') ? mb_substr($observacoes, 0, 300) : NULL,
      'ended_by' => $idUser,
      'modified' => $agora,
      'modified_by' => $idUser,
    ], 'id', $id);

    if (!empty($desvincular)) {
      $placeholders = implode(', ', array_fill(0, count($desvincular), '?'));
      $this->db->query(
        'UPDATE `crm_contracts_domains`
            SET `id_server_domain` = NULL, `modified` = ?, `modified_by` = ?
          WHERE `id_contract` = ? AND `id_company` = ? AND `id` IN (' . $placeholders . ')',
        array_merge([$agora, $idUser, $id, $idCompany], $desvincular)
      );
    }

    if ($this->db->trans_status() === FALSE) {
      $this->db->trans_rollback();

      // As contas já foram suspensas — isso não volta atrás com um rollback, e
      // o log é o que liga o contrato ainda vigente na tela às contas fora do
      // ar no painel.
      log_message('error', '[SUSPENSAO] Encerramento não gravado após suspender contas — tenant ' . $idCompany
        . ', contrato ' . $id . ', contas suspensas: ' . (int) $suspensao['contas_ok']);

      $this->session->set_flashdata('error', 'Não foi possível gravar o encerramento do contrato.'
        . ((int) $suspensao['contas_ok'] > 0
          ? ' Atenção: ' . (int) $suspensao['contas_ok'] . ' conta(s) já haviam sido suspensas no painel.'
          : ''));
      redirect(base_url('contratos/info?id=' . $id));
    }
    $this->db->trans_commit();

    $mensagem = 'Contrato encerrado.' . $this->resumoSuspensao($suspensao);
    if (!empty($desvincular)) {
      $mensagem .= ' ' . count($desvincular) . ' domínio(s) desvinculado(s) da conta de servidor.';
    }
    $this->session->set_flashdata('success', $mensagem);

    $aviso = $this->avisoSuspensao($suspensao);
    if ($aviso !== '') {
      $this->session->set_flashdata('warning', $aviso);
    }

    redirect(base_url('contratos/info?id=' . $id));
  }

  /**
   * Desfaz um encerramento feito por engano.
   *
   * Zerar o `ended` não é higiene, é a correção em si: se ele continuasse
   * preenchido, o contrato voltaria a contar como vigente E seguiria somando na
   * barra de saídas do mês em que foi encerrado.
   *
   * Volta sempre para 'vigente', e não para o status anterior — não guardamos o
   * status pré-encerramento, e criar uma coluna para esse caso de exceção não
   * se paga (estava suspenso? suspende de novo, dois cliques).
   *
   * Cliente que volta depois de sair gera contrato NOVO; reabrir é para
   * corrigir engano.
   */
  public function post_reabrir()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);

    if ($contrato->status !== 'encerrado') {
      $this->session->set_flashdata('warning', 'Este contrato não está encerrado.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $this->global_model->edit('crm_contracts', [
      'status' => 'vigente',
      'ended' => NULL,
      'ended_reason' => NULL,
      'ended_comments' => NULL,
      'ended_by' => NULL,
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => (int) $this->session->userdata('user')->id,
    ], 'id', $id);

    $this->session->set_flashdata('success', 'Contrato reaberto. O registro do encerramento foi apagado.');
    redirect(base_url('contratos/info?id=' . $id));
  }

  // ------------------------------------------------------------------
  // Mensagens da suspensão de contas (Server_model::suspendContractAccounts)
  // ------------------------------------------------------------------

  /**
   * Trecho somado à mensagem de sucesso: o que de fato aconteceu nos painéis.
   *
   * Domínio sem vínculo entra aqui, e não no aviso: cadastrar domínio sem
   * correspondência no servidor é estado normal, então tratá-lo como problema
   * encheria a tela de alerta a cada encerramento.
   *
   * @param  array $resultado
   * @return string
   */
  private function resumoSuspensao(array $resultado)
  {
    $partes = [];

    if ((int) $resultado['contas_ok'] > 0) {
      $partes[] = (int) $resultado['contas_ok'] . ' conta(s) '
        . ($resultado['acao'] === 'suspender' ? 'suspensa(s)' : 'reativada(s)') . ' no painel';
    }

    $semVinculo = count($resultado['sem_vinculo']);
    if ($semVinculo > 0) {
      $partes[] = $semVinculo . ' domínio(s) sem vínculo com servidor (nada a aplicar)';
    }

    return empty($partes) ? '' : ' ' . implode('; ', $partes) . '.';
  }

  /**
   * Lista o que ficou pendente nos painéis, para o alerta da tela.
   *
   * Contas bloqueadas e falhas aparecem juntas porque a pergunta do operador é
   * uma só ("o que eu ainda preciso fazer no painel?"), mas o motivo de cada
   * uma vem escrito — conta compartilhada com contrato vigente é decisão do
   * sistema, e não erro de rede.
   *
   * @param  array $resultado
   * @return string vazio quando não há nada a avisar
   */
  private function avisoSuspensao(array $resultado)
  {
    $itens = [];

    foreach ($resultado['bloqueados'] as $bloqueado) {
      $itens[] = '<strong>' . $this->textoParaFlash($bloqueado['servidor'] . ' — ' . $bloqueado['conta'])
        . '</strong>: ' . $this->textoParaFlash($bloqueado['motivo']);
    }

    foreach ($resultado['falhas'] as $falha) {
      $itens[] = '<strong>' . $this->textoParaFlash($falha['servidor'] . ' — ' . $falha['conta'])
        . '</strong>: ' . $this->textoParaFlash($falha['erro']);
    }

    $pendentes = [];
    if (!empty($itens)) {
      // A lista é truncada: um contrato com dezenas de contas transformaria o
      // alerta numa parede de texto que ninguém lê. O log tem todas.
      $total = count($itens);
      if ($total > self::LIMITE_AVISO_SUSPENSAO) {
        $itens = array_slice($itens, 0, self::LIMITE_AVISO_SUSPENSAO);
        $itens[] = 'e mais ' . ($total - self::LIMITE_AVISO_SUSPENSAO) . ' — veja o log do sistema.';
      }

      $pendentes[] = 'Estas contas <strong>não</strong> foram '
        . ($resultado['acao'] === 'suspender' ? 'suspensas' : 'reativadas')
        . ' — resolva pelo painel do servidor:<br>&bull; ' . implode('<br>&bull; ', $itens);
    }

    if ((int) $resultado['nao_processadas'] > 0) {
      $pendentes[] = (int) $resultado['nao_processadas'] . ' conta(s) não foram processadas nesta tentativa '
        . '(tempo limite da requisição). Repita a operação para continuar de onde parou.';
    }

    return empty($pendentes) ? '' : implode('<br><br>', $pendentes);
  }

  /**
   * Prepara texto vindo de fora (mensagem de painel) para o flashdata.
   *
   * O header injeta a mensagem dentro de uma string JavaScript entre aspas
   * duplas, sem escapar: aspas, barra invertida ou quebra de linha vindas da
   * resposta de um painel quebrariam o script e o alerta simplesmente não
   * apareceria — justamente no caso em que ele mais importa.
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

  public function post_excluir()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);
    $idCustomer = (int) $contrato->id_customer;

    // A guarda testa `ended`, e não o status: é o `ended` que a barra de saídas
    // do dashboard referencia, então a condição diz literalmente "esta linha
    // está sendo contada por algum mês". E como post_reabrir zera o campo,
    // existe uma saída natural para quem precisa mesmo apagar (reabrir →
    // excluir), sem caso especial aqui.
    if (!empty($contrato->ended)) {
      $this->session->set_flashdata('error', 'Este contrato foi encerrado e não pode ser excluído — o encerramento é o que alimenta o histórico de saídas do dashboard. Reabra o contrato antes, se a exclusão for mesmo o caso.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    // Os caminhos são resolvidos ANTES da transação (o query builder do CI3
    // vaza estado se uma query for executada no meio de uma cadeia), e os
    // arquivos físicos só caem DEPOIS do commit: apagá-los antes deixaria as
    // linhas do banco apontando para arquivos inexistentes em caso de rollback.
    $arquivos = $this->global_model->getWhere_off('crm_contracts_files', ['id_contract' => $id], FALSE);
    $caminhos = [];
    foreach ($arquivos as $arquivo) {
      if (!empty($arquivo->file)) $caminhos[] = FCPATH . $arquivo->file;
    }

    $this->db->trans_begin();

    // Documentos e domínios saem junto; o N:N de tipos cascateia pela FK.
    $this->db->where('id_contract', $id);
    $this->db->delete('crm_contracts_files');

    $this->db->where('id_contract', $id);
    $this->db->delete('crm_contracts_domains');

    $this->global_model->delete('crm_contracts', 'id', $id);

    if ($this->db->trans_status() === FALSE) {
      $this->db->trans_rollback();
      $this->session->set_flashdata('error', 'Não foi possível excluir o contrato.');
      redirect(base_url('contratos/info?id=' . $id));
    }
    $this->db->trans_commit();

    foreach ($caminhos as $caminho) {
      if (file_exists($caminho)) @unlink($caminho);
    }

    $this->session->set_flashdata('success', 'Contrato excluído com sucesso.');
    redirect(base_url('clientes/info?id=' . $idCustomer));
  }

  // ------------------------------------------------------------------
  // Domínios
  // ------------------------------------------------------------------

  /**
   * Busca do modal: existe este domínio sincronizado em algum servidor do
   * tenant? A tela exige a busca antes de salvar (UX); o vínculo em si é
   * revalidado de novo no post_salvardominio — o POST nunca é fonte de verdade.
   *
   * A busca já desconta o que este contrato ocupou: o mesmo domínio pode estar
   * em mais de um servidor (site num painel, e-mail em outro) e cada conta é um
   * cadastro próprio, então `matches` traz só os servidores AINDA disponíveis
   * e `ja_vinculados`, os que este contrato já usa para este domínio.
   */
  public function json_postbuscardominio()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $contrato = $this->global_model->getWhere_off('crm_contracts', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);
    if (empty($contrato)) {
      echo json_encode($this->jsonErro('Contrato não encontrado.', ['id' => 'Contrato não encontrado.']));
      return;
    }

    $dominio = $this->normalizarDominio($this->input->post('domain'));
    if ($dominio === '') {
      echo json_encode($this->jsonErro('Informe o domínio para buscar.', ['domain' => 'Informe o domínio.']));
      return;
    }

    $matches = $this->buscarDominioNosServidores($dominio);
    $situacao = $this->situacaoDominioNoContrato($id, $dominio, $matches);

    if ($situacao['bloqueado']) {
      $mensagem = $situacao['motivo'];
    } elseif (empty($situacao['disponiveis'])) {
      $mensagem = 'Nenhuma correspondência nos servidores — o domínio ficará sem vínculo.';
    } else {
      $mensagem = count($situacao['disponiveis']) . ' correspondência(s) disponível(is).';
    }

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => $mensagem,
      'data' => [
        'domain' => $dominio,
        'matches' => $situacao['disponiveis'],
        'ja_vinculados' => $situacao['ja_vinculados'],
        'bloqueado' => $situacao['bloqueado'],
        'motivo' => $situacao['motivo'],
      ],
      'errors' => [],
    ]);
  }

  /**
   * Vencimento e local de registro do domínio ANTES de ele existir, para o
   * modal de cadastro preencher os dois campos sozinho. A origem é escolhida
   * pelo TLD dentro do Whois_model: `.br` no RDAP do Registro.br, o resto na
   * API Ninjas.
   *
   * Endpoint separado da busca, e chamado logo depois dela, de propósito: a
   * busca é o que libera o SALVAR e responde só com o banco, em milissegundos.
   * Pendurar nela uma chamada externa de até 20s atrasaria o cadastro inteiro
   * — e uma consulta fora do ar passaria a travar o vínculo, que não depende
   * dela. Aqui, falha só quer dizer "preencha à mão".
   */
  public function json_postwhoiscadastro()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $contrato = $this->global_model->getWhere_off('crm_contracts', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);
    if (empty($contrato)) {
      echo json_encode($this->jsonErro('Contrato não encontrado.', ['id' => 'Contrato não encontrado.']));
      return;
    }

    $dominio = $this->normalizarDominio($this->input->post('domain'));
    if ($dominio === '') {
      echo json_encode($this->jsonErro('Informe o domínio para consultar.', ['domain' => 'Informe o domínio.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('whois_model');
    $resultado = $this->whois_model->lookupParaCadastro(
      $dominio,
      (int) $this->getCurrentCompanyId(),
      (int) $this->session->userdata('user')->id
    );

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => [
        'domain' => $resultado['domain'],
        'origem' => $resultado['origem'],
        'livre' => $resultado['livre'],
        // A tela é quem formata: o campo tem máscara dd/mm/aaaa.
        'due_date' => !empty($resultado['data']['expiration_date'])
          ? date('d/m/Y', strtotime($resultado['data']['expiration_date']))
          : NULL,
        'registrar' => $resultado['data']['registrar'],
      ],
      'errors' => $resultado['success'] ? [] : ['whois' => $resultado['message']],
    ]);
  }

  /**
   * Cria ou atualiza um domínio — o modal é o mesmo nos dois casos; com
   * `id_domain` no POST é edição. Na edição o DOMÍNIO e o VÍNCULO não mudam
   * (trocar o domínio = excluir e recadastrar, passando pela busca de novo);
   * só vencimento, local de registro, gerenciado CDW e observações.
   */
  public function post_salvardominio()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);

    $vencimento = trim((string) $this->input->post('due_date'));
    if ($vencimento !== '') {
      $d = DateTime::createFromFormat('d/m/Y', $vencimento);
      if (!$d || $d->format('d/m/Y') !== $vencimento) {
        $this->session->set_flashdata('error', 'A data de vencimento deve estar no formato dd/mm/aaaa.');
        redirect(base_url('contratos/info?id=' . $id));
      }
      $vencimento = $d->format('Y-m-d');
    } else {
      $vencimento = NULL;
    }

    $registro = trim((string) $this->input->post('registrar'));
    $dados = [
      'due_date' => $vencimento,
      'registrar' => $registro !== '' ? mb_substr($registro, 0, 150) : NULL,
      'managed_cdw' => $this->input->post('managed_cdw') === 'S' ? 1 : 0,
      'comments' => trim((string) $this->input->post('comments')),
    ];

    $idDomain = (int) $this->input->post('id_domain');
    if ($idDomain > 0) {
      // Edição: o domínio precisa pertencer a este contrato e a este tenant.
      $existente = $this->global_model->getWhere_off('crm_contracts_domains', [
        'id' => $idDomain,
        'id_contract' => $id,
        'id_company' => (int) $contrato->id_company,
      ], TRUE);

      if (empty($existente)) {
        $this->session->set_flashdata('warning', 'Domínio não encontrado.');
        redirect(base_url('contratos/info?id=' . $id));
      }

      $dados['modified'] = date('Y-m-d H:i:s');
      $dados['modified_by'] = (int) $this->session->userdata('user')->id;
      $this->global_model->edit('crm_contracts_domains', $dados, 'id', $idDomain);

      $this->session->set_flashdata('success', 'Domínio atualizado com sucesso.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $dominio = $this->normalizarDominio($this->input->post('domain'));
    if ($dominio === '') {
      $this->session->set_flashdata('error', 'Informe o domínio.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    // Revalida o vínculo no servidor: o id postado só vale se apontar para um
    // domínio do tenant com o MESMO nome E que este contrato ainda não use;
    // senão, resolve de novo pela busca. O mesmo domínio pode voltar aqui de
    // propósito, desde que seja para outro servidor (site num painel, e-mail
    // em outro) — o que não repete é o PAR domínio+servidor.
    $matches = $this->buscarDominioNosServidores($dominio);
    $situacao = $this->situacaoDominioNoContrato($id, $dominio, $matches);

    if ($situacao['bloqueado']) {
      $this->session->set_flashdata('warning', $situacao['motivo']);
      redirect(base_url('contratos/info?id=' . $id));
    }

    $idsDisponiveis = array_map(function ($m) {
      return (int) $m['id'];
    }, $situacao['disponiveis']);

    $idServerDomain = (int) $this->input->post('id_server_domain');
    if (!in_array($idServerDomain, $idsDisponiveis, TRUE)) {
      $idServerDomain = !empty($idsDisponiveis) ? $idsDisponiveis[0] : NULL;
    }
    if (empty($idServerDomain)) $idServerDomain = NULL;

    $dados['id_contract'] = $id;
    $dados['id_company'] = (int) $contrato->id_company;
    $dados['id_server_domain'] = $idServerDomain;
    $dados['domain'] = $dominio;
    $dados['created'] = date('Y-m-d H:i:s');
    $dados['created_by'] = (int) $this->session->userdata('user')->id;
    $this->global_model->add('crm_contracts_domains', $dados);

    $this->session->set_flashdata('success', $idServerDomain !== NULL
      ? 'Domínio adicionado e vinculado automaticamente ao servidor.'
      : 'Domínio adicionado sem vínculo com servidores.');
    redirect(base_url('contratos/info?id=' . $id));
  }

  public function json_postdeletedominio()
  {
    header('Content-Type: application/json; charset=utf-8');

    $idDominio = (int) $this->input->post('id');
    if ($idDominio <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    $dominio = $this->global_model->getWhere_off('crm_contracts_domains', [
      'id' => $idDominio,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($dominio)) {
      echo json_encode($this->jsonErro('Domínio não encontrado.', ['id' => 'Domínio não encontrado.']));
      return;
    }

    $this->global_model->delete('crm_contracts_domains', 'id', $idDominio);

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => 'Domínio excluído com sucesso.',
      'data' => ['id' => $idDominio],
      'errors' => [],
    ]);
  }

  /**
   * O que ainda dá para cadastrar deste domínio neste contrato.
   *
   * O mesmo domínio pode estar em mais de um servidor do tenant — é o caso
   * comum do site num painel e das contas de e-mail em outro (WHM x
   * DirectAdmin). Cada uma é uma conta própria, com disco próprio, e por isso
   * entra como um cadastro próprio no contrato: a UNIQUE da migration 022 é
   * (id_contract, domain, id_server_domain), e o que não repete é o PAR
   * domínio+servidor.
   *
   * Só há bloqueio quando não sobrou nada para cadastrar E o domínio já está
   * no contrato de alguma forma — o que cobre também o "sem vínculo"
   * duplicado, que a UNIQUE não pega (NULL nunca colide com NULL no MySQL).
   *
   * @param  int    $idContract
   * @param  string $dominio já normalizado
   * @param  array  $matches retorno de buscarDominioNosServidores()
   * @return array  ['disponiveis' => [], 'ja_vinculados' => [], 'bloqueado' => bool, 'motivo' => string]
   */
  private function situacaoDominioNoContrato($idContract, $dominio, array $matches)
  {
    $linhas = $this->global_model->getFieldsWhereSingle_off('crm_contracts_domains', 'id_server_domain', [
      'id_contract' => (int) $idContract,
      'domain' => $dominio,
    ], FALSE);

    $usados = [];
    $semVinculo = FALSE;
    foreach ($linhas as $linha) {
      if ($linha->id_server_domain === NULL) {
        $semVinculo = TRUE;
        continue;
      }
      $usados[] = (int) $linha->id_server_domain;
    }

    $disponiveis = [];
    $jaVinculados = [];
    foreach ($matches as $match) {
      if (in_array((int) $match['id'], $usados, TRUE)) {
        $jaVinculados[] = $match;
      } else {
        $disponiveis[] = $match;
      }
    }

    $jaCadastrado = !empty($usados) || $semVinculo;
    $bloqueado = empty($disponiveis) && $jaCadastrado;

    $motivo = '';
    if ($bloqueado) {
      $motivo = !empty($jaVinculados)
        ? 'Este domínio já está cadastrado neste contrato em todos os servidores onde ele existe (' . implode(', ', array_column($jaVinculados, 'server_name')) . ').'
        : 'Este domínio já está cadastrado neste contrato.';
    }

    return [
      'disponiveis' => $disponiveis,
      'ja_vinculados' => $jaVinculados,
      'bloqueado' => $bloqueado,
      'motivo' => $motivo,
    ];
  }

  /**
   * Correspondências do domínio nos servidores do tenant. Tenta o nome exato
   * e a variante com/sem "www." — o mesmo domínio pode existir em mais de um
   * servidor (a UNIQUE da 002 é por servidor).
   *
   * @param  string $dominio já normalizado
   * @return array  [['id' =>, 'domain' =>, 'server_name' =>, 'status' =>, 'disk_used_mb' =>], ...]
   */
  private function buscarDominioNosServidores($dominio)
  {
    $candidatos = [$dominio];
    if (strpos($dominio, 'www.') === 0) {
      $candidatos[] = substr($dominio, 4);
    } else {
      $candidatos[] = 'www.' . $dominio;
    }

    $rows = $this->db->query(
      'SELECT id, domain, server_name, status, disk_used_mb FROM crm_servers_domains_v WHERE id_company = ? AND domain IN (?, ?) ORDER BY domain, server_name',
      [(int) $this->getCurrentCompanyId(), $candidatos[0], $candidatos[1]]
    )->result();

    $matches = [];
    foreach ($rows as $row) {
      $matches[] = [
        'id' => (int) $row->id,
        'domain' => $row->domain,
        'server_name' => $row->server_name,
        'status' => $row->status,
        'disk_used_mb' => $row->disk_used_mb !== NULL ? (float) $row->disk_used_mb : NULL,
      ];
    }
    return $matches;
  }

  /**
   * Minúsculo, sem esquema, porta ou caminho — o formato de
   * crm_servers_domains.domain.
   *
   * @param  string $dominio
   * @return string
   */
  private function normalizarDominio($dominio)
  {
    $d = mb_strtolower(trim((string) $dominio));
    $d = preg_replace('#^https?://#', '', $d);
    $d = explode('/', $d)[0];
    $d = explode(':', $d)[0];
    return rtrim($d, '.');
  }

  // ------------------------------------------------------------------
  // Documentos
  // ------------------------------------------------------------------

  public function post_sendfile()
  {
    $id = (int) $this->input->post('id');
    $contrato = $this->carregarContratoDaTabela($id);

    $nome = trim((string) $this->input->post('name'));
    if ($nome === '') {
      $this->session->set_flashdata('error', 'Informe um nome para o documento.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    // uploadFileFtp valida o teto por arquivo NO SERVIDOR
    // (UPLOAD_MAX_SIZE_PADRAO, 10 MB) e grava em images/contracts/<ano>/<mês>/.
    $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'xls', 'xlsx', 'doc', 'docx', 'JPG', 'JPEG', 'PNG', 'PDF', 'XLS', 'XLSX', 'DOC', 'DOCX'];
    $file = $this->uploadFileFtp('file', 'contracts', $allowed, FALSE);

    if (empty($file)) {
      $this->session->set_flashdata('error', 'Nenhum documento enviado. Confira o formato (JPG, PNG, PDF, XLS ou DOC) e o tamanho (até 10 MB).');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $this->global_model->add('crm_contracts_files', [
      'id_contract' => $id,
      'id_company' => (int) $contrato->id_company,
      'name' => mb_substr($nome, 0, 150),
      'file' => $file,
      'created' => date('Y-m-d H:i:s'),
      'created_by' => (int) $this->session->userdata('user')->id,
    ]);

    $this->session->set_flashdata('success', 'Documento enviado com sucesso.');
    redirect(base_url('contratos/info?id=' . $id));
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
    // arquivo nunca vem do POST.
    $documento = $this->global_model->getWhere_off('crm_contracts_files', [
      'id' => $idFile,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($documento)) {
      echo json_encode($this->jsonErro('Documento não encontrado.', ['id' => 'Documento não encontrado.']));
      return;
    }

    if (!empty($documento->file) && file_exists(FCPATH . $documento->file)) {
      @unlink(FCPATH . $documento->file);
    }
    $this->global_model->delete('crm_contracts_files', 'id', $idFile);

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => 'Documento excluído com sucesso.',
      'data' => ['id' => $idFile],
      'errors' => [],
    ]);
  }

  // ------------------------------------------------------------------
  // Apoio
  // ------------------------------------------------------------------

  /**
   * @param  int $id
   * @return object linha de crm_contracts_v (com dados do cliente)
   */
  private function carregarContrato($id)
  {
    if ($id <= 0) {
      $this->session->set_flashdata('warning', 'Contrato não informado.');
      redirect(base_url('clientes'));
    }

    $contrato = $this->global_model->getWhere_off('crm_contracts_v', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($contrato)) {
      $this->session->set_flashdata('warning', 'Contrato não encontrado.');
      redirect(base_url('clientes'));
    }

    return $contrato;
  }

  /**
   * Versão da tabela (para os POSTs), com o mesmo escopo.
   *
   * @param  int $id
   * @return object
   */
  private function carregarContratoDaTabela($id)
  {
    $contrato = $this->global_model->getWhere_off('crm_contracts', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($contrato)) {
      $this->session->set_flashdata('warning', 'Contrato não encontrado.');
      redirect(base_url('clientes'));
    }

    return $contrato;
  }

  /**
   * Valida e monta os campos editáveis do contrato (criação e dados gerais).
   *
   * @param  object|null $contratoAtual linha de crm_contracts (só na edição)
   * @return array|bool FALSE quando algo é inválido (flashdata já definido)
   */
  private function montarDadosDoPost($contratoAtual = NULL)
  {
    $post = (array) $this->input->post('contract');

    $ciclo = isset($post['cycle']) ? trim((string) $post['cycle']) : '';
    if (!array_key_exists($ciclo, $this->cycles())) {
      $this->session->set_flashdata('error', 'Selecione um ciclo de pagamento válido.');
      return FALSE;
    }

    // A guarda "parcelas <= meses do ciclo" também vale aqui: encurtar o ciclo
    // de anual para mensal num contrato em 2× deixaria a parcela 2 vencendo
    // dentro da competência seguinte. Sem esta checagem, a regra do bloco
    // Faturamento seria burlada pela porta dos dados gerais.
    if (!empty($contratoAtual) && (int) $contratoAtual->installments > 1) {
      $this->load->model('invoice_model');
      $meses = $this->invoice_model->mesesDoCiclo($ciclo);

      if ($meses > 0 && (int) $contratoAtual->installments > $meses) {
        $rotulos = $this->cycles();
        $this->session->set_flashdata('error', 'Este contrato está dividido em ' . (int) $contratoAtual->installments . ' parcelas, e o ciclo ' . mb_strtolower($rotulos[$ciclo]) . ' comporta no máximo ' . $meses . '. Ajuste as parcelas no bloco Faturamento antes de mudar o ciclo.');
        return FALSE;
      }
    }

    $valor = (float) removerFormatacaoNumero(isset($post['value']) ? (string) $post['value'] : '');
    if ($valor < 0) $valor = 0;

    $espaco = (float) str_replace(',', '.', isset($post['space_gb']) ? (string) $post['space_gb'] : '0');
    if ($espaco < 0) $espaco = 0;

    $criado = $this->montarCriacaoDoPost($contratoAtual);
    if ($criado === FALSE) return FALSE;

    return [
      'cycle' => $ciclo,
      'value' => $valor,
      'space_gb' => $espaco,
      'created' => $criado,
      'comments' => isset($post['comments']) ? trim((string) $post['comments']) : '',
    ];
  }

  /**
   * Data de criação vinda do formulário (dd/mm/aaaa), no padrão do vencimento
   * de domínio: máscara na tela, DateTime::createFromFormat com conferência
   * estrita no servidor.
   *
   * O `created` é editável de propósito: ele é a âncora das ENTRADAS do gráfico
   * de movimento e do KPI de novos contratos no mês, então contrato antigo
   * lançado hoje (migração de base, cadastro atrasado) precisa cair no mês em
   * que de fato entrou, e não no mês da digitação.
   *
   * A HORA não vem da tela — só a data. Na criação usamos a hora corrente (para
   * a data de hoje o resultado é exatamente `now`, como antes); na edição
   * preservamos a hora já gravada, para não embaralhar a ordem dos contratos
   * criados no mesmo dia. Campo em branco mantém o que está gravado.
   *
   * Data futura é recusada: a janela do dashboard termina no mês corrente, e um
   * contrato "criado" no mês que vem sumiria das entradas sem nenhum aviso.
   *
   * @param  object|null $contratoAtual linha de crm_contracts (só na edição)
   * @return string|bool 'Y-m-d H:i:s', ou FALSE (flashdata já definido)
   */
  private function montarCriacaoDoPost($contratoAtual = NULL)
  {
    $post = (array) $this->input->post('contract');
    $texto = isset($post['created']) ? trim((string) $post['created']) : '';

    $atual = (!empty($contratoAtual) && !empty($contratoAtual->created)) ? $contratoAtual->created : '';

    if ($texto === '') {
      return ($atual !== '') ? $atual : date('Y-m-d H:i:s');
    }

    $data = DateTime::createFromFormat('d/m/Y', $texto);
    if (!$data || $data->format('d/m/Y') !== $texto) {
      $this->session->set_flashdata('error', 'A data de criação deve estar no formato dd/mm/aaaa.');
      return FALSE;
    }

    if ($data->format('Y-m-d') > date('Y-m-d')) {
      $this->session->set_flashdata('error', 'A data de criação não pode ser futura — ela é a base das entradas do dashboard, que vão até o mês corrente.');
      return FALSE;
    }

    if ((int) $data->format('Y') < 2000) {
      $this->session->set_flashdata('error', 'Informe uma data de criação a partir do ano 2000.');
      return FALSE;
    }

    $hora = ($atual !== '') ? date('H:i:s', strtotime($atual)) : date('H:i:s');

    return $data->format('Y-m-d') . ' ' . $hora;
  }

  /**
   * IDs de tipos de serviço válidos vindos do multiselect.
   *
   * @return int[]
   */
  private function montarServicosDoPost()
  {
    $post = (array) $this->input->post('contract');
    $ids = isset($post['service_types']) && is_array($post['service_types']) ? $post['service_types'] : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
      return $id > 0;
    })));

    $validos = [];
    foreach ($ids as $id) {
      $existe = $this->global_model->countWhere_off('crm_service_types', ['id' => $id]);
      if ($existe > 0) $validos[] = $id;
    }

    return $validos;
  }

  /**
   * Catálogo de ciclos de pagamento (slug gravado em crm_contracts.cycle).
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
   * Catálogo de motivos de encerramento (slug gravado em
   * crm_contracts.ended_reason), lido de `crm_end_reasons` desde a migration
   * 032 — antes era um array aqui dentro.
   *
   * Slug, e não texto livre, porque a pergunta seguinte do negócio é "por que
   * perdemos" — e isso pede GROUP BY. O slug continua sendo o que o contrato
   * grava: a tabela é o catálogo, não o dono do carimbo histórico.
   *
   * Dois recortes, e a diferença importa:
   *  - SEM `$somenteAtivos` devolve TUDO, e é o que traduz slug → rótulo na
   *    tela do contrato. Motivo inativado depois não pode apagar o rótulo de um
   *    contrato que já foi encerrado com ele.
   *  - COM `$somenteAtivos` é o que alimenta o select do modal e valida o POST
   *    do encerramento — motivo aposentado não volta pela porta dos fundos.
   *
   * A leitura é memoizada porque `info()` chama as duas versões na mesma
   * requisição.
   *
   * Diferente do cycles(), NÃO é espelhado em Clientes.php: o contrato nasce no
   * modal da tela do cliente, mas o encerramento só acontece na tela do
   * contrato.
   *
   * @param  bool $somenteAtivos
   * @return array slug => rótulo
   */
  private function endReasons($somenteAtivos = FALSE)
  {
    if ($this->cacheEndReasons === NULL) {
      $this->cacheEndReasons = $this->db->query(
        'SELECT `slug`, `name`, `id_status` FROM `crm_end_reasons` ORDER BY `sort_order` ASC, `name` ASC'
      )->result();
    }

    $saida = [];
    foreach ($this->cacheEndReasons as $motivo) {
      if ($somenteAtivos && (int) $motivo->id_status !== 1) continue;
      $saida[$motivo->slug] = $motivo->name;
    }
    return $saida;
  }

  /**
   * Transições de status que o post_status aceita — allowlist, no mesmo idioma
   * do Painel::faixasDominio().
   *
   * O encerramento NÃO está aqui de propósito: ele grava mais quatro campos
   * (data, motivo, observações, autor) e tem endpoint próprio.
   *
   * @return array ação => ['de' => status exigido, 'para' => novo, 'ok' => msg]
   */
  private function statusTransicoes()
  {
    return [
      'suspender' => ['de' => 'vigente', 'para' => 'suspenso', 'ok' => 'Contrato suspenso.'],
      'reativar' => ['de' => 'suspenso', 'para' => 'vigente', 'ok' => 'Contrato reativado.'],
    ];
  }

  /**
   * @param  string $mensagem
   * @param  array  $errors
   * @return array
   */
  /**
   * Consulta WHOIS/RDAP de um domínio de contrato, a partir das telas do
   * contrato e da visão geral do cliente. O escopo do tenant é resolvido no
   * servidor: o POST só informa o id do domínio.
   *
   * Havendo vínculo com um domínio de servidor, a consulta grava o retrato
   * completo lá; sem vínculo, atualiza só o vencimento deste contrato.
   */
  public function json_postwhoisdominio()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('whois_model');
    $resultado = $this->whois_model->syncContractDomain(
      $id,
      $this->getCurrentCompanyId(),
      (int) $this->session->userdata('user')->id
    );

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => empty($resultado['success']) ? NULL : $this->montarRegistroWhois($resultado),
      'errors' => $resultado['success'] ? [] : ['whois' => $resultado['message']],
    ]);
  }

  /**
   * Extrato Bom Controle do contrato, consultado ao vivo no ERP.
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
    $resultado = $this->bomcontrole_model->montarExtratoContrato($id, (int) $this->getCurrentCompanyId());

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Contratos do Bom Controle candidatos ao vínculo — a busca é pelo CPF/CNPJ
   * do cliente do contrato, e o model refiltra pelo documento exato.
   */
  public function json_postbuscarbc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->buscarCandidatos($id, (int) $this->getCurrentCompanyId());

    $data = $resultado['data'];
    if (!empty($resultado['success']) && !empty($data['documento'])) {
      $data['documento'] = cnpj($data['documento']);
    }

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $data,
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Grava o vínculo. O id do contrato BC vem do POST, mas o model revalida no
   * servidor que ele pertence ao documento do cliente — o POST nunca é fonte
   * de verdade.
   */
  public function json_postvincularbc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $idBc = (int) $this->input->post('id_bc');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    @set_time_limit(0);

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->vincular($id, (int) $this->getCurrentCompanyId(), $idBc);

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  public function json_postdesvincularbc()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    $this->load->model('bomcontrole_model');
    $resultado = $this->bomcontrole_model->desvincular($id, (int) $this->getCurrentCompanyId());

    echo json_encode([
      'success' => (bool) $resultado['success'],
      'return' => (bool) $resultado['success'],
      'message' => $resultado['message'],
      'data' => $resultado['data'],
      'errors' => $resultado['success'] ? [] : ['bomcontrole' => $resultado['message']],
    ]);
  }

  /**
   * Achata o registro consultado em pares rótulo/valor para o modal.
   *
   * @param  array $resultado retorno de Whois_model::syncContractDomain()
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

    $this->load->library('rdap_registrobr');
    $titular = $this->rdap_registrobr->extrairTitular($raw);
    $incluir('Titular', $titular['nome']);
    $incluir('Documento do titular', $titular['documento']);

    if (!empty($raw['status']) && is_array($raw['status'])) {
      $incluir('Situação no registro', implode(', ', array_filter($raw['status'], 'is_string')));
    }

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
      'sem_vinculo' => !empty($whois['sem_vinculo']),
    ];
  }

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

  /**
   * Uma página das faturas geradas pelo CDW Finance para este contrato.
   *
   * Só banco, sem rede — ao contrário do extrato do Bom Controle, estas
   * faturas são nossas. É por isso que a aba pagina de verdade e o extrato
   * não: lá o recorte é fixo (vencidas + 60 dias + 13 meses de pagas) e vem
   * pronto da API; aqui a série cresce um registro por competência, para
   * sempre.
   */
  public function json_postfaturas()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    if ($id <= 0) {
      echo json_encode($this->jsonErro('ID inválido.', ['id' => 'ID inválido.']));
      return;
    }

    // Escopo conferido aqui, e não no model: o id vem do POST e sozinho
    // atravessaria tenants. Sem redirect — quem chama é AJAX.
    $contrato = $this->global_model->getWhere_off('crm_contracts', [
      'id' => $id,
      'id_company' => (int) $this->getCurrentCompanyId(),
    ], TRUE);

    if (empty($contrato)) {
      echo json_encode($this->jsonErro('Contrato não encontrado.'));
      return;
    }

    $this->load->model('invoice_model');
    $pagina = $this->invoice_model->listarPorEscopo(
      'contrato',
      $id,
      (int) $this->getCurrentCompanyId(),
      (int) $this->input->post('pagina')
    );

    if ($pagina === NULL) {
      echo json_encode($this->jsonErro('Escopo inválido.'));
      return;
    }

    $pagina['situations'] = $this->invoice_model->situations();
    $pagina['fatura_aqui'] = ((string) $contrato->billing_source === 'cdwfinance');

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => '',
      'data' => $pagina,
      'errors' => [],
    ]);
  }
}
