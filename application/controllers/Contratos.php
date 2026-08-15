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
    $this->data['end_reasons'] = $this->endReasons();

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

    // Aba Extrato financeiro: só uma leitura de banco (sem rede) — a aba
    // mostra o aviso de integração desativada sem esperar o AJAX do extrato.
    $this->load->model('bomcontrole_model');
    $this->data['bomcontrole_ativo'] = $this->bomcontrole_model->isActive((int) $this->getCurrentCompanyId());

    $this->load->view('header', $this->data);
    $this->load->view('contracts/info', $this->data);
    $this->load->view('footer', $this->data);
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

    $this->global_model->edit('crm_contracts', [
      'status' => $transicoes[$acao]['para'],
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => (int) $this->session->userdata('user')->id,
    ], 'id', $id);

    $this->session->set_flashdata('success', $transicoes[$acao]['ok']);
    redirect(base_url('contratos/info?id=' . $id));
  }

  /**
   * Encerra o contrato — é este carimbo que alimenta a barra de saídas do
   * dashboard.
   *
   * Funciona a partir de vigente E de suspenso: suspender por inadimplência e
   * depois encerrar é o caminho mais comum, e exigir a reativação antes faria o
   * contrato aparecer como vigente no meio do caminho.
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
    if (!array_key_exists($motivo, $this->endReasons())) {
      $this->session->set_flashdata('error', 'Selecione um motivo de encerramento válido.');
      redirect(base_url('contratos/info?id=' . $id));
    }

    $observacoes = trim((string) $this->input->post('comments'));

    $this->global_model->edit('crm_contracts', [
      'status' => 'encerrado',
      'ended' => date('Y-m-d H:i:s'),
      'ended_reason' => $motivo,
      'ended_comments' => ($observacoes !== '') ? mb_substr($observacoes, 0, 500) : NULL,
      'ended_by' => (int) $this->session->userdata('user')->id,
      'modified' => date('Y-m-d H:i:s'),
      'modified_by' => (int) $this->session->userdata('user')->id,
    ], 'id', $id);

    $this->session->set_flashdata('success', 'Contrato encerrado.');
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
   * crm_contracts.ended_reason).
   *
   * Slug, e não texto livre, porque a pergunta seguinte do negócio é "por que
   * perdemos" — e isso pede GROUP BY.
   *
   * Diferente do cycles(), NÃO é espelhado em Clientes.php: o contrato nasce no
   * modal da tela do cliente, mas o encerramento só acontece na tela do
   * contrato.
   *
   * @return array slug => rótulo
   */
  private function endReasons()
  {
    return [
      'cancelamento' => 'Cancelamento pelo cliente',
      'inadimplencia' => 'Inadimplência',
      'concorrente' => 'Migração para concorrente',
      'fim_atividades' => 'Encerramento das atividades do cliente',
      'fim_prazo' => 'Fim do prazo contratual',
      'substituicao' => 'Substituído por outro contrato',
      'outros' => 'Outros',
    ];
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
   * Extrato financeiro do contrato, consultado ao vivo no Bom Controle.
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
}
