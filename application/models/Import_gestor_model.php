<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Importação da base do gestor-interno (sistema anterior) para o CDW Finance.
 *
 * Orquestrador único, no mesmo desenho do `Server_model`: o controller só
 * chama e imprime o que voltar. Toda a regra de de-para, resolução e gravação
 * mora aqui.
 *
 * IDEMPOTÊNCIA
 * ------------
 * Tudo é upsert por (`id_company`, `legacy_id`) — o id que a linha tinha na
 * origem, gravado pela migration 020. Rodar duas vezes atualiza, não duplica.
 * Isso não é luxo: contrato NÃO tem chave natural na origem (nem número, nem
 * data que o identifique), então sem o `legacy_id` a segunda execução criaria
 * 254 contratos novos.
 *
 * No UPDATE, `created`/`created_by` nunca são tocados (mesma regra do
 * `Server_model::upsertDomain()`): a data de criação do contrato é a âncora
 * das entradas do Dashboard, e reescrevê-la a cada reimportação moveria o
 * contrato de mês.
 *
 * SEM SESSÃO
 * ----------
 * Roda por CLI, fora de sessão. Por isso todas as leituras usam os helpers
 * `_off` do `Global_model` — as versões sem `_off` aplicam o filtro gravado na
 * sessão e a abrangência do usuário, e devolveriam resultado vazio aqui.
 *
 * SEM TRANSAÇÃO ÚNICA
 * -------------------
 * De propósito, como no `syncDomains()`. Cada upsert é atômico por si e a
 * rotina é reexecutável; uma transação de 1.300 linhas só transformaria uma
 * falha no meio em "nada entrou", sem ganho — reexecutar já reconcilia.
 *
 * O QUE NÃO ENTRA
 * ---------------
 * Histórico de contrato, atividades e anexos de cliente (as duas últimas estão
 * vazias na origem), servidores e domínios sincronizados (o CDW Finance
 * sincroniza os seus), usuários, chaves de API e o funil de orçamentos.
 */
class Import_gestor_model extends CI_Model
{
  /**
   * Cidade usada quando a origem não informou cidade/estado. Cascavel/PR —
   * definido pelo usuário. `crm_customers.id_city` é NULLable e a
   * `crm_customers_v` usa LEFT JOIN, então NULL também funcionaria; o padrão
   * existe para o cadastro nascer utilizável na tela de edição, que exige
   * cidade.
   */
  const ID_CIDADE_PADRAO = 3979;

  /**
   * Teto de segurança por etapa. Se o dump vier com muito mais linhas do que a
   * base real, é sinal de arquivo errado — melhor parar do que despejar.
   */
  const LIMITE_LINHAS = 20000;

  /**
   * Rótulo do contato na origem => slug de `Clientes::contactTypes()`.
   *
   * A origem grava o rótulo de exibição, com acento e espaço ("Sócio
   * proprietário"); o destino grava slug. Rótulo desconhecido cai em `outros`
   * com aviso — nunca em string livre, que quebraria o filtro da tela.
   *
   * @var array
   */
  private $tiposContato = [
    'Financeiro' => 'financeiro',
    'Sócio proprietário' => 'socio_proprietario',
    'Gestor de tráfego' => 'gestor_trafego',
    'Jurídico' => 'juridico',
    'Marketing' => 'marketing',
    'Diretor' => 'diretor',
    'Outros' => 'outros',
  ];

  /**
   * Ciclos aceitos por `Contratos::cycles()`. A origem usa o mesmo vocabulário
   * (menos `bimestral`, que só existe aqui), então é allowlist, não tradução.
   *
   * @var array
   */
  private $ciclos = ['mensal', 'bimestral', 'trimestral', 'quadrimestral', 'semestral', 'anual'];

  /**
   * Status do contrato na origem => status do destino.
   *
   * `removido` vira `encerrado`, que é o vocabulário daqui. Na base atual não
   * existe nenhum, mas a tradução fica escrita: sem ela um `removido` futuro
   * entraria com status inválido e sumiria de todas as telas, que filtram
   * pelos três valores conhecidos.
   *
   * @var array
   */
  private $statusContrato = [
    'vigente' => 'vigente',
    'suspenso' => 'suspenso',
    'removido' => 'encerrado',
  ];

  /**
   * Tipos de serviço cujo nome mudou entre os dois sistemas.
   *
   * O catálogo do destino é a fonte de verdade (é o que aparece nos selects e
   * nos filtros), e um nome que não casa faz a importação PARAR — de propósito,
   * porque criar o tipo em silêncio duplicaria o catálogo e espalharia o
   * contrato entre dois rótulos que são a mesma coisa.
   *
   * Este mapa é a exceção declarada: só entra aqui divergência puramente
   * redacional do MESMO serviço. Hoje há uma, de plural.
   *
   * Chaves e valores normalizados por `chaveNome()` (minúsculo, sem acento).
   *
   * @var array
   */
  private $aliasTiposServico = [
    // 26 contratos usam este tipo; o destino registrou o nome no plural.
    'gerenciamento de dominio' => 'gerenciamento de dominios',
  ];

  private $idCompany;
  private $idUser;
  private $simulacao = FALSE;

  /**
   * Ids gravados em `crm_contracts_history` nesta rodada, para o resumo do fim.
   *
   * A importação NÃO manda um e-mail por contrato: uma execução reescreve
   * centenas de linhas, e uma caixa de entrada com 400 avisos vira filtro de
   * lixeira — o alerta morreria junto com o caso que ele existe para pegar.
   *
   * @var array
   */
  private $eventosDaRodada = [];
  private $pastaArquivos;
  private $agora;

  /**
   * Quando ligado, `crm_customers.id` recebe o id que o cliente tinha na
   * origem, em vez do próximo AUTO_INCREMENT — assim o cadastro daqui e o do
   * gestor-interno têm o mesmo número, o que facilita conferir os dois lado a
   * lado durante a migração.
   *
   * É OPÇÃO e não padrão porque a PK é global e o `legacy_id` é único só por
   * tenant: se um segundo tenant importar a base dele, os ids de origem se
   * repetem e colidem na PK. Vale para um cutover de tenant único.
   *
   * Só tem efeito no INSERT — id de linha existente não muda.
   *
   * @var bool
   */
  private $idClienteDaOrigem = FALSE;

  /** @var array "CIDADE|UF" => ['id_city' =>, 'id_state' =>] */
  private $cacheCidades = [];

  /** @var array nome minúsculo do tipo de serviço => id em crm_service_types */
  private $mapaTipos = [];

  /** @var array domínio => id em crm_servers_domains */
  private $mapaDominiosServidor = [];

  /** @var array legacy_id do cliente => id em crm_customers */
  private $mapaClientes = [];

  /** @var array legacy_id do contrato => id em crm_contracts */
  private $mapaContratos = [];

  /** @var array legacy_id do cliente => e-mail derivado dos contatos */
  private $emailPorCliente = [];

  /**
   * Deslocamento aplicado ao `legacy_id` das linhas que nascem do inventário
   * de servidores (`dominios`) em vez do cadastro comercial
   * (`clientesContratosDominios`).
   *
   * As duas tabelas de origem numeram a partir de 1, e as duas gravam na mesma
   * `crm_contracts_domains` — sem separar, `dominios.id = 34` colidiria com
   * `clientesContratosDominios.id = 34` na UNIQUE (`id_company`, `legacy_id`) e
   * a segunda origem sobrescreveria a primeira. Com o deslocamento, o
   * `legacy_id` continua dizendo de onde a linha veio e a rotina segue
   * idempotente. A origem tem ~700 linhas em `dominios`; a folga é enorme.
   */
  const LEGACY_OFFSET_CONTA = 1000000;

  /** @var array id local do contrato => [domínio normalizado => TRUE] */
  private $dominiosDoContrato = [];

  /** @var array id local do contrato => [id_server_domain => TRUE] */
  private $contasDoContrato = [];

  /** @var array id local do cliente => [ids locais de contrato] */
  private $contratosDoCliente = [];

  /** @var array id do servidor na origem => id do servidor aqui */
  private $mapaServidores = [];

  /** @var array "id_server|domínio" => id em crm_servers_domains */
  private $contasPorServidor = [];

  /** @var array */
  private $avisos = [];

  /**
   * Executa a importação inteira.
   *
   * @param  string $caminhoDump    Dump .sql do gestor-interno.
   * @param  string $pastaArquivos  Pasta com os documentos baixados do B2 (com manifest.json).
   * @param  array  $opcoes         id_company, id_user, simulacao.
   * @return array  ['success','message','data']
   */
  public function importar($caminhoDump, $pastaArquivos, array $opcoes = [])
  {
    $this->idCompany = isset($opcoes['id_company']) ? (int) $opcoes['id_company'] : 1;
    $this->idUser = isset($opcoes['id_user']) ? (int) $opcoes['id_user'] : (int) $this->config->item('id_user_process_auto');
    $this->simulacao = !empty($opcoes['simulacao']);
    $this->idClienteDaOrigem = !empty($opcoes['id_cliente_origem']);
    $this->pastaArquivos = rtrim((string) $pastaArquivos, '/');
    $this->agora = date('Y-m-d H:i:s');

    $this->cacheCidades = [];
    $this->mapaTipos = [];
    $this->mapaDominiosServidor = [];
    $this->mapaClientes = [];
    $this->mapaContratos = [];
    $this->emailPorCliente = [];
    $this->dominiosDoContrato = [];
    $this->contasDoContrato = [];
    $this->contratosDoCliente = [];
    $this->mapaServidores = [];
    $this->contasPorServidor = [];
    $this->avisos = [];

    $erro = $this->validarAmbiente();
    if ($erro !== '') {
      return $this->falha($erro);
    }

    $this->load->library('pgdump_parser');

    if (!$this->pgdump_parser->carregar($caminhoDump)) {
      return $this->falha($this->pgdump_parser->ultimoErro());
    }

    $contagens = $this->pgdump_parser->contagens();

    foreach ($contagens as $tabela => $quantidade) {
      if ($quantidade > self::LIMITE_LINHAS) {
        return $this->falha(
          "A tabela {$tabela} veio com {$quantidade} linhas, acima do limite de " . self::LIMITE_LINHAS . '. Confira se o dump é o esperado.'
        );
      }
    }

    $this->prepararCatalogos();

    $tiposFaltando = $this->conferirTiposServico();
    if (!empty($tiposFaltando)) {
      return $this->falha(
        'Tipos de serviço da origem que não existem em crm_service_types: ' . implode(', ', $tiposFaltando)
        . '. Cadastre-os antes de importar (o importador não cria tipo em silêncio).'
      );
    }

    if ($this->idClienteDaOrigem) {
      $ocupados = $this->idsDeClienteOcupados($this->pgdump_parser->tabela('clientes'));

      if (!empty($ocupados)) {
        return $this->falha(
          'Não dá para usar o id da origem: ' . count($ocupados) . ' id(s) já pertencem a outro cliente aqui ('
          . implode(', ', array_slice($ocupados, 0, 10)) . (count($ocupados) > 10 ? ', ...' : '') . '). '
          . 'Rode sem --id-origem, ou limpe a base importada antes (a opção só vale para carga em base limpa).'
        );
      }

      // O id só é gravado no INSERT. Se o cliente já foi importado, esta
      // execução seria um UPDATE e o id ficaria como está — a opção viraria um
      // no-op silencioso, e o relatório diria "386 atualizados" como se tivesse
      // funcionado. Recusar é melhor do que entregar o contrário do pedido.
      $renumerar = $this->clientesComIdDivergente();

      if ($renumerar > 0) {
        return $this->falha(
          "--id-origem só grava o id no INSERT, e {$renumerar} cliente(s) já importado(s) estão com id diferente do de origem — "
          . 'esta execução seria um UPDATE e os ids não mudariam. Para renumerar, apague as linhas com legacy_id NOT NULL '
          . '(clientes e tudo que depende deles) e importe de novo com a opção.'
        );
      }
    }

    $this->indexarEmailsDeContato($this->pgdump_parser->tabela('clientesContatos'));

    // Fora de produção o CI3 sobe com `db_debug = TRUE`, e aí QUALQUER erro de
    // SQL vira show_error e mata o processo na hora — o tratamento de
    // `add() === FALSE` logo abaixo nunca chegaria a rodar. Numa rotina de
    // 2.700 linhas isso significa que uma única linha problemática derruba a
    // importação inteira no meio, deixando metade gravada (foi o que aconteceu
    // com um domínio que colidia na UNIQUE por causa da colação).
    //
    // Silenciando o debug, o erro volta como FALSE, vira um contador e um
    // aviso, e as outras 2.699 linhas entram.
    $debugOriginal = $this->db->db_debug;
    $this->db->db_debug = FALSE;

    try {
      $etapas = $this->executarEtapas();
    } finally {
      $this->db->db_debug = $debugOriginal;
    }

    $erros = 0;
    foreach ($etapas as $etapa) {
      $erros += $etapa['erros'];
    }

    // UM resumo no fim, com todas as mudanças de estado da rodada. Fica fora do
    // try/finally acima de propósito: com o `db_debug` já restaurado, uma falha
    // ao enfileirar o e-mail aparece em vez de sumir — e a essa altura tudo o
    // que importava já está gravado, então não há mais nada para proteger.
    if (!empty($this->eventosDaRodada)) {
      $this->load->model('contract_history_model');
      $this->contract_history_model->notificarLote($this->eventosDaRodada, $this->idCompany);
    }

    return [
      'success' => ($erros === 0),
      'message' => $erros === 0
        ? ($this->simulacao ? 'Simulação concluída sem erros.' : 'Importação concluída.')
        : "Concluído com {$erros} erro(s).",
      'data' => [
        'simulacao' => $this->simulacao,
        'id_company' => $this->idCompany,
        'id_user' => $this->idUser,
        'contagens_origem' => $contagens,
        'etapas' => $etapas,
        // Reportado no stdout como as demais contagens: mudança de estado
        // aplicada em silêncio é indistinguível de "nada aconteceu", que é o
        // problema que este número existe para acabar.
        'contratos_com_status_alterado' => count($this->eventosDaRodada),
        'avisos' => $this->avisos,
      ],
    ];
  }

  /**
   * As etapas na ordem que as FKs exigem: o pai antes do filho.
   *
   * @return array
   */
  private function executarEtapas()
  {
    $etapas = [];
    $etapas['clientes'] = $this->importarClientes($this->pgdump_parser->tabela('clientes'));
    $etapas['contatos'] = $this->importarContatos($this->pgdump_parser->tabela('clientesContatos'));
    $etapas['contratos'] = $this->importarContratos($this->pgdump_parser->tabela('clientesContratosOperacionais'));
    $etapas['servicos'] = $this->importarServicos($this->pgdump_parser->tabela('clientesContratosTiposServicos'));
    $etapas['dominios'] = $this->importarDominios($this->pgdump_parser->tabela('clientesContratosDominios'));
    // Depois dos domínios, de propósito: esta etapa só completa o que o
    // cadastro comercial não cobriu, e para saber isso precisa do que a etapa
    // anterior gravou.
    $etapas['contas'] = $this->importarContasDeServidor($this->pgdump_parser->tabela('dominios'));
    $etapas['documentos'] = $this->importarDocumentos($this->pgdump_parser->tabela('clientesContratosDocumentos'));
    $etapas['atividades'] = $this->importarAtividades($this->pgdump_parser->tabela('clientesAtividades'));

    return $etapas;
  }

  // ------------------------------------------------------------------
  // Preparação
  // ------------------------------------------------------------------

  /**
   * Confere o que a importação pressupõe: tenant ativo, usuário existente e a
   * coluna da migration 020. Falhar aqui é muito melhor do que falhar no meio
   * da gravação.
   *
   * @return string vazio quando está tudo certo
   */
  private function validarAmbiente()
  {
    if (!$this->db->field_exists('legacy_id', 'crm_customers')) {
      return 'A coluna legacy_id não existe. Aplique a migration 020 antes de importar.';
    }

    $empresa = $this->global_model->getFieldsWhereSingle_off('crm_companies', 'id', ['id' => $this->idCompany], TRUE);
    if (empty($empresa)) {
      return "Empresa (tenant) {$this->idCompany} não encontrada em crm_companies.";
    }

    $usuario = $this->global_model->getFieldsWhereSingle_off('crm_users', 'id', ['id' => $this->idUser], TRUE);
    if (empty($usuario)) {
      return "Usuário {$this->idUser} não encontrado em crm_users (é o created_by de tudo que for importado).";
    }

    if (!$this->simulacao && !is_dir($this->pastaArquivos)) {
      return 'Pasta de arquivos não encontrada: ' . $this->pastaArquivos;
    }

    return '';
  }

  /**
   * Carrega de uma vez os catálogos que seriam consultados linha a linha:
   * tipos de serviço e domínios dos servidores do tenant. São 9 e ~550
   * registros — em memória custa nada e evita ~800 consultas.
   */
  private function prepararCatalogos()
  {
    $tipos = $this->global_model->getFieldsWhereSingle_off('crm_service_types', 'id, name', []);

    foreach ((array) $tipos as $tipo) {
      $this->mapaTipos[$this->chaveNome($tipo->name)] = (int) $tipo->id;
    }

    $dominios = $this->global_model->getFieldsWhereSingle_off(
      'crm_servers_domains',
      'id, domain, id_server',
      ['id_company' => $this->idCompany]
    );

    foreach ((array) $dominios as $dominio) {
      $chave = mb_strtolower(trim($dominio->domain));

      // O mesmo domínio pode existir em mais de um servidor (a UNIQUE da 002 é
      // por servidor). Aqui fica o primeiro — este mapa serve ao cadastro
      // comercial, que não diz o servidor. As contas nos DEMAIS servidores
      // entram por `importarContasDeServidor()`, que sabe o servidor de cada
      // uma e usa o índice `contasPorServidor`.
      if (!isset($this->mapaDominiosServidor[$chave])) {
        $this->mapaDominiosServidor[$chave] = (int) $dominio->id;
      }

      $this->contasPorServidor[$dominio->id_server . '|' . $chave] = (int) $dominio->id;
    }

    $this->mapearServidores();
    $this->indexarDominiosJaCadastrados();
  }

  /**
   * Semeia os índices de domínio/conta com o que JÁ está no banco.
   *
   * Sem isto, `importarContasDeServidor()` só enxergaria as linhas gravadas
   * pela própria execução e tentaria recriar o que foi cadastrado à mão pela
   * tela — que é justamente o caso que ela automatiza (a conta do mesmo
   * domínio em outro servidor). O resultado era erro 1062 na UNIQUE da
   * migration 022.
   */
  private function indexarDominiosJaCadastrados()
  {
    $linhas = $this->global_model->getFieldsWhereSingle_off(
      'crm_contracts_domains',
      'id_contract, domain, id_server_domain',
      ['id_company' => $this->idCompany]
    );

    foreach ((array) $linhas as $linha) {
      $idContrato = (int) $linha->id_contract;

      $this->dominiosDoContrato[$idContrato][$this->semWww($linha->domain)] = TRUE;

      if ($linha->id_server_domain !== NULL) {
        $this->contasDoContrato[$idContrato][(int) $linha->id_server_domain] = TRUE;
      }
    }
  }

  /**
   * Servidor da origem => servidor daqui, casando pelo HOST.
   *
   * O id do servidor na origem não vale nada aqui (são bases diferentes) e o
   * nome diverge ("cloud2" lá, "CLOUD 2" aqui; "Cloud" virou "CLOUD 1"). O
   * host é o que identifica a máquina de verdade — e casa nos 5 servidores.
   */
  private function mapearServidores()
  {
    $locais = [];
    $servidores = $this->global_model->getFieldsWhereSingle_off(
      'crm_servers',
      'id, host',
      ['id_company' => $this->idCompany]
    );

    foreach ((array) $servidores as $servidor) {
      $locais[$this->apenasHost($servidor->host)] = (int) $servidor->id;
    }

    foreach ($this->pgdump_parser->tabela('servidores') as $servidor) {
      $host = $this->apenasHost($servidor['url']);

      if (isset($locais[$host])) {
        $this->mapaServidores[(int) $servidor['id']] = $locais[$host];
      }
    }
  }

  /**
   * Extrai só o host de uma URL ou endereço (sem esquema, porta ou caminho).
   *
   * @param  string $valor
   * @return string
   */
  private function apenasHost($valor)
  {
    $host = mb_strtolower(trim((string) $valor));
    $host = preg_replace('#^https?://#', '', $host);
    $host = explode('/', $host)[0];
    $host = explode(':', $host)[0];

    return rtrim($host, '.');
  }

  /**
   * Tipos de serviço da origem que não têm correspondente no destino.
   *
   * @return array nomes
   */
  private function conferirTiposServico()
  {
    $faltando = [];

    foreach ($this->pgdump_parser->tabela('tiposServicos') as $tipo) {
      $nome = (string) $tipo['nome'];

      if (!isset($this->mapaTipos[$this->chaveNome($nome)])) {
        $faltando[] = $nome;
      }
    }

    return $faltando;
  }

  /**
   * `crm_customers.email` (e-mail do contrato) não tem origem: a tabela
   * `clientes` do gestor-interno não tem coluna de e-mail. O contato do tipo
   * Financeiro é o mais próximo do que o campo significa aqui; qualquer
   * contato com e-mail serve de segunda opção.
   *
   * @param array $contatos
   */
  private function indexarEmailsDeContato(array $contatos)
  {
    $reservas = [];

    foreach ($contatos as $contato) {
      $idCliente = (int) $contato['clienteId'];
      $email = mb_strtolower(trim((string) $contato['email']));

      if ($email === '') {
        continue;
      }

      if ((string) $contato['tipoContato'] === 'Financeiro' && !isset($this->emailPorCliente[$idCliente])) {
        $this->emailPorCliente[$idCliente] = $email;
        continue;
      }

      if (!isset($reservas[$idCliente])) {
        $reservas[$idCliente] = $email;
      }
    }

    foreach ($reservas as $idCliente => $email) {
      if (!isset($this->emailPorCliente[$idCliente])) {
        $this->emailPorCliente[$idCliente] = $email;
      }
    }
  }

  // ------------------------------------------------------------------
  // Etapas
  // ------------------------------------------------------------------

  /**
   * @param  array $linhas
   * @return array contadores
   */
  private function importarClientes(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));

    foreach ($linhas as $origem) {
      $legacyId = (int) $origem['id'];
      $documento = sonumero((string) $origem['documento']);

      if ($documento === '') {
        $this->avisar('clientes', $legacyId, 'sem documento — cliente ignorado');
        $contadores['ignorados']++;
        continue;
      }

      $tipo = ((string) $origem['tipoPessoa'] === 'F') ? 'F' : 'J';

      // O tipo de pessoa e o documento têm de contar a mesma história. Quando
      // não contam, o problema NÃO é dígito verificador — é classificação: a
      // origem tem MEI marcado como pessoa física com CNPJ de 14 dígitos. O
      // registro entra como veio (o ajuste é pela tela, por decisão), mas o
      // aviso separa os dois casos: confundi-los faria um erro de cadastro
      // parecer documento inválido.
      $tamanhoEsperado = ($tipo === 'F') ? 11 : 14;

      if (strlen($documento) !== $tamanhoEsperado) {
        $outro = ($tipo === 'F') ? 'J' : 'F';
        $coerente = ($tipo === 'F') ? valida_cnpj($documento) : valida_cpf($documento);

        $this->avisar(
          'clientes',
          $legacyId,
          'tipo de pessoa (' . $tipo . ') não bate com o documento de ' . strlen($documento) . ' dígitos'
          . ($coerente ? " — é um documento válido de pessoa {$outro}; corrigir o tipo pela tela" : '')
        );
      } elseif (!(($tipo === 'F') ? valida_cpf($documento) : valida_cnpj($documento))) {
        // Entra assim mesmo, por decisão: são poucos e o ajuste é pela tela.
        // O aviso existe para que "poucos" não vire "muitos" sem ninguém ver.
        $this->avisar('clientes', $legacyId, 'documento reprova na validação de CPF/CNPJ: ' . $documento);
      }

      $razaoSocial = trim((string) $origem['nomeRazaoSocial']);
      $fantasia = trim((string) $origem['nomeFantasia']);

      if ($tipo === 'J' && $fantasia === '') {
        // `byname` é obrigatório para pessoa jurídica no destino.
        $fantasia = $razaoSocial;
        $this->avisar('clientes', $legacyId, 'sem nome fantasia — repetida a razão social');
      }

      $cidade = $this->resolverCidade((string) $origem['cidade'], (string) $origem['estado'], $legacyId);

      $dados = [
        'id_company' => $this->idCompany,
        'legacy_id' => $legacyId,
        'type' => $tipo,
        'document' => mb_substr($documento, 0, 14),
        'name' => mb_substr(mb_strtoupper($razaoSocial), 0, 150),
        'byname' => $fantasia !== '' ? mb_substr(mb_strtoupper($fantasia), 0, 150) : NULL,
        'email' => isset($this->emailPorCliente[$legacyId]) ? mb_substr($this->emailPorCliente[$legacyId], 0, 150) : NULL,
        'address' => $this->texto($origem['logradouro'], 200),
        'address_number' => $this->texto($origem['numeroEndereco'], 20),
        'address_complement' => $this->texto($origem['complementoEndereco'], 200),
        'address_district' => $this->texto($origem['bairro'], 150),
        'address_zip' => $this->formatarCep((string) $origem['cep']),
        'id_state' => $cidade['id_state'],
        'id_city' => $cidade['id_city'],
        'attributes' => $this->montarAtributos($origem),
      ];

      $id = $this->upsert(
        'crm_customers',
        $legacyId,
        $dados,
        $origem,
        $contadores,
        TRUE,
        $this->idClienteDaOrigem ? $legacyId : NULL
      );

      if ($id !== FALSE) {
        $this->mapaClientes[$legacyId] = $id;
      }
    }

    return $contadores;
  }

  /**
   * @param  array $linhas
   * @return array contadores
   */
  private function importarContatos(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));

    foreach ($linhas as $origem) {
      $legacyId = (int) $origem['id'];
      $idCliente = $this->resolverCliente((int) $origem['clienteId'], 'contatos', $legacyId, $contadores);

      if ($idCliente === FALSE) {
        continue;
      }

      $rotulo = (string) $origem['tipoContato'];

      if (isset($this->tiposContato[$rotulo])) {
        $tipo = $this->tiposContato[$rotulo];
      } else {
        $tipo = 'outros';
        $this->avisar('contatos', $legacyId, "tipo de contato desconhecido na origem ({$rotulo}) — gravado como outros");
      }

      $email = mb_strtolower(trim((string) $origem['email']));

      $dados = [
        'id_company' => $this->idCompany,
        'legacy_id' => $legacyId,
        'id_customer' => $idCliente,
        'type' => $tipo,
        'name' => mb_substr(trim((string) $origem['nome']), 0, 150),
        'email' => $email !== '' ? mb_substr($email, 0, 150) : NULL,
        'phone' => $this->mascararTelefone((string) $origem['telefoneWhatsapp']),
      ];

      $this->upsert('crm_customers_contacts', $legacyId, $dados, $origem, $contadores, TRUE);
    }

    return $contadores;
  }

  /**
   * @param  array $linhas
   * @return array contadores
   */
  private function importarContratos(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));

    // Retrato do estado ANTES da rodada, numa query só.
    //
    // Esta etapa é a razão de o histórico existir. O `upsert()` grava `status`
    // junto dos demais campos, então toda execução devolve o contrato ao estado
    // do dump — um contrato suspenso à mão aqui volta a 'vigente' se na origem
    // estiver ativo, e o inverso também. Foi isso que a equipe leu como
    // "o sistema está suspendendo e reativando contratos sozinho".
    //
    // Pior: o upsert também reescreve `modified` com o `updatedAt` da ORIGEM,
    // uma data no passado — a única trilha existente apontava para semanas
    // atrás justamente quando alguém ia investigar o que tinha acabado de
    // acontecer.
    //
    // O comportamento NÃO muda (a origem continua sendo a fonte de verdade
    // durante a migração); o que muda é ele deixar de ser invisível.
    $statusAntes = $this->statusAtuaisPorLegacy();

    foreach ($linhas as $origem) {
      $legacyId = (int) $origem['id'];
      $idCliente = $this->resolverCliente((int) $origem['clienteId'], 'contratos', $legacyId, $contadores);

      if ($idCliente === FALSE) {
        continue;
      }

      $statusOrigem = (string) $origem['status'];

      if (isset($this->statusContrato[$statusOrigem])) {
        $status = $this->statusContrato[$statusOrigem];
      } else {
        $status = 'vigente';
        $this->avisar('contratos', $legacyId, "status desconhecido na origem ({$statusOrigem}) — gravado como vigente");
      }

      $ciclo = mb_strtolower(trim((string) $origem['cicloPagamento']));

      if (!in_array($ciclo, $this->ciclos, TRUE)) {
        $this->avisar('contratos', $legacyId, "ciclo desconhecido na origem ({$ciclo}) — gravado como mensal");
        $ciclo = 'mensal';
      }

      $dados = [
        'id_company' => $this->idCompany,
        'legacy_id' => $legacyId,
        'id_customer' => $idCliente,
        'status' => $status,
        'cycle' => $ciclo,
        // A origem guarda centavos como inteiro; aqui é decimal(12,2).
        'value' => round(((int) $origem['valorCentavos']) / 100, 2),
        'space_gb' => max(0, (float) $origem['espacoContratadoGb']),
        'comments' => $this->texto($origem['observacoes'], 65535),
        'bomcontrole_contract_id' => $origem['bomControleContratoId'] !== NULL ? (int) $origem['bomControleContratoId'] : NULL,
        'bomcontrole_linked' => $this->dataHora($origem['bomControleVinculadoEm']),
      ];

      if ($status === 'encerrado') {
        // A origem não tem data de encerramento: o `updatedAt` é a melhor
        // aproximação disponível. Sem `ended` o contrato contaria como
        // encerrado no status mas não apareceria nas saídas do Dashboard.
        $dados['ended'] = $this->dataHora($origem['updatedAt']);
        $dados['ended_reason'] = 'outros';
        $dados['ended_by'] = $this->idUser;
        $this->avisar('contratos', $legacyId, 'encerrado sem data na origem — usada a data da última alteração');
      }

      $anterior = isset($statusAntes[$legacyId]) ? $statusAntes[$legacyId] : NULL;

      $id = $this->upsert('crm_contracts', $legacyId, $dados, $origem, $contadores, TRUE);

      if ($id !== FALSE) {
        $this->mapaContratos[$legacyId] = $id;
        $this->contratosDoCliente[$idCliente][] = $id;

        // Só a MUDANÇA vira evento. O caso comum é o dump repetir o estado que
        // já está aqui, e registrar isso encheria o histórico de linhas que não
        // dizem nada — a aba do contrato viraria um log de execução da
        // importação em vez da trilha do que aconteceu com o contrato.
        //
        // Contrato NOVO também não entra: ele não mudou de estado, nasceu. A
        // carga inicial produziria 400 eventos de uma vez, e o primeiro uso do
        // recurso seria um e-mail que ninguém lê.
        if ($anterior !== NULL && $anterior !== $status) {
          $this->registrarEventoImportacao((int) $id, $anterior, $status, (int) $origem['id']);
        }
      }
    }

    return $contadores;
  }

  /**
   * Status atual de cada contrato já importado, indexado por `legacy_id`.
   *
   * Uma query, e não um SELECT por linha dentro do laço: são 400 contratos e a
   * pergunta é a mesma para todos. É o mesmo critério dos índices semeados do
   * banco antes da etapa de contas de servidor.
   *
   * @return array legacy_id => status
   */
  private function statusAtuaisPorLegacy()
  {
    $linhas = $this->db->query(
      'SELECT `legacy_id`, `status` FROM `crm_contracts`
        WHERE `id_company` = ? AND `legacy_id` IS NOT NULL',
      [(int) $this->idCompany]
    )->result();

    $mapa = [];
    foreach ($linhas as $linha) {
      $mapa[(int) $linha->legacy_id] = (string) $linha->status;
    }

    return $mapa;
  }

  /**
   * Grava no histórico a transição que a importação acabou de aplicar.
   *
   * O evento é derivado do par de status, e não de uma ação: a importação não
   * "suspende", ela reescreve — e o resultado observado é o que se registra.
   * Transição para um status desconhecido cai em 'suspenso'/'reativado' pelo
   * destino, e nunca é descartada: uma mudança que não coube no vocabulário é
   * exatamente a que precisa aparecer.
   *
   * @param  int    $idContract
   * @param  string $de
   * @param  string $para
   * @param  int    $legacyId
   * @return void
   */
  private function registrarEventoImportacao($idContract, $de, $para, $legacyId)
  {
    $this->avisar('contratos', $legacyId, "status alterado pela importação ({$de} -> {$para})");

    // Na simulação o id pode ser um placeholder negativo e nada foi gravado.
    if ($this->simulacao || $idContract <= 0) {
      return;
    }

    if ($para === 'encerrado') {
      $evento = 'encerrado';
    } elseif ($de === 'encerrado') {
      $evento = 'reaberto';
    } elseif ($para === 'suspenso') {
      $evento = 'suspenso';
    } else {
      $evento = 'reativado';
    }

    $this->load->model('contract_history_model');

    $id = $this->contract_history_model->registrar($idContract, $evento, $this->idUser, [
      'status_from' => $de,
      'status_to' => $para,
      'origin' => 'importacao',
      'detail' => 'Estado reescrito pelo dump do gestor-interno (registro de origem ' . (int) $legacyId
        . '). As contas dos painéis NÃO foram alteradas — o serviço do cliente não acompanha esta mudança.',
    ]);

    if ($id !== FALSE) {
      $this->eventosDaRodada[] = (int) $id;
    }
  }

  /**
   * Vínculo N:N contrato × tipo de serviço.
   *
   * Não tem `legacy_id`: na origem a PK é composta e a linha não tem id
   * próprio. A idempotência vem da UNIQUE `uk_contracts_services`, então a
   * gravação testa a existência antes de inserir.
   *
   * @param  array $linhas
   * @return array contadores
   */
  private function importarServicos(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));
    $nomesPorId = [];

    foreach ($this->pgdump_parser->tabela('tiposServicos') as $tipo) {
      $nomesPorId[(int) $tipo['id']] = (string) $tipo['nome'];
    }

    foreach ($linhas as $origem) {
      $legacyContrato = (int) $origem['contratoId'];
      $legacyTipo = (int) $origem['tipoServicoId'];

      if (!isset($this->mapaContratos[$legacyContrato])) {
        $this->avisar('servicos', $legacyContrato, 'contrato não importado — vínculo de serviço ignorado');
        $contadores['ignorados']++;
        continue;
      }

      if (!isset($nomesPorId[$legacyTipo])) {
        $this->avisar('servicos', $legacyContrato, "tipo de serviço {$legacyTipo} não existe na origem");
        $contadores['erros']++;
        continue;
      }

      $chave = $this->chaveNome($nomesPorId[$legacyTipo]);

      if (!isset($this->mapaTipos[$chave])) {
        $this->avisar('servicos', $legacyContrato, 'tipo de serviço sem correspondente no destino: ' . $nomesPorId[$legacyTipo]);
        $contadores['erros']++;
        continue;
      }

      $filtro = [
        'id_contract' => $this->mapaContratos[$legacyContrato],
        'id_service_type' => $this->mapaTipos[$chave],
      ];

      $existente = $this->global_model->getFieldsWhereSingle_off('crm_contracts_services', 'id', $filtro, TRUE);

      if (!empty($existente)) {
        $contadores['atualizados']++;
        continue;
      }

      if ($this->simulacao) {
        $contadores['novos']++;
        continue;
      }

      if ($this->global_model->add('crm_contracts_services', $filtro) === FALSE) {
        $this->avisar('servicos', $legacyContrato, 'falha ao gravar o vínculo de serviço');
        $contadores['erros']++;
        continue;
      }

      $contadores['novos']++;
    }

    return $contadores;
  }

  /**
   * @param  array $linhas
   * @return array contadores
   */
  private function importarDominios(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));
    $vistos = [];

    foreach ($linhas as $origem) {
      $legacyId = (int) $origem['id'];
      $legacyContrato = (int) $origem['contratoId'];

      if (!isset($this->mapaContratos[$legacyContrato])) {
        $this->avisar('dominios', $legacyId, "contrato {$legacyContrato} não importado — domínio ignorado");
        $contadores['ignorados']++;
        continue;
      }

      $dominio = $this->normalizarDominio((string) $origem['dominio']);

      if ($dominio === '') {
        $this->avisar('dominios', $legacyId, 'domínio vazio — ignorado');
        $contadores['ignorados']++;
        continue;
      }

      // O destino tem UNIQUE (id_contract, domain, id_server_domain); a origem
      // não tem chave nenhuma. Duas linhas que só diferiam por maiúscula ou
      // "https://" colidiriam aqui.
      //
      // A terceira coluna da chave (a 022 admite o mesmo domínio em servidores
      // diferentes) não dá saída aqui: `resolverDominioServidor()` devolve um
      // id só por nome, então duas linhas de mesmo domínio caem sempre no MESMO
      // servidor. E quando o nome não resolve para servidor nenhum a chave nem
      // barra mais (NULL não colide com NULL numa UNIQUE) — o que torna esta
      // dedup em PHP a ÚNICA guarda do caso, e não um pré-aviso do 1062.
      //
      // A chave despreza ACENTO porque é assim que o MySQL compara: a coluna é
      // utf8_general_ci, onde "onçanews.com.br" e "oncanews.com.br" são o
      // MESMO valor. São domínios diferentes no mundo real, mas a UNIQUE do
      // destino não consegue guardar os dois — e descobrir isso como erro 1062
      // no meio da gravação é bem pior do que recusar aqui, onde o `--dry-run`
      // enxerga e avisa antes.
      $chave = $legacyContrato . '|' . mb_strtolower(remover_acentos($dominio));

      if (isset($vistos[$chave])) {
        $anterior = $vistos[$chave];
        $mensagem = ($anterior === $dominio)
          ? "domínio repetido no mesmo contrato ({$dominio}) — ignorado"
          : "domínio [{$dominio}] só difere de [{$anterior}] por acento e a colação do banco os trata como iguais — ignorado";

        $this->avisar('dominios', $legacyId, $mensagem);
        $contadores['ignorados']++;
        continue;
      }

      $vistos[$chave] = $dominio;

      $dados = [
        'id_company' => $this->idCompany,
        'legacy_id' => $legacyId,
        'id_contract' => $this->mapaContratos[$legacyContrato],
        'domain' => mb_substr($dominio, 0, 255),
        // O vínculo é resolvido AQUI, contra os domínios que o CDW Finance
        // sincronizou dos próprios servidores. O id que a origem tinha aponta
        // para outra base e nunca é fonte de verdade.
        'id_server_domain' => $this->resolverDominioServidor($dominio),
        'due_date' => $this->data($origem['dataVencimento']),
        'registrar' => $this->texto($origem['localRegistro'], 150),
        'managed_cdw' => ((string) $origem['gerenciadoCdw'] === 't') ? 1 : 0,
        'comments' => $this->texto($origem['observacao'], 65535),
      ];

      $id = $this->upsert('crm_contracts_domains', $legacyId, $dados, $origem, $contadores, TRUE);

      if ($id !== FALSE) {
        // Índices para a etapa seguinte saber o que já existe. Ficam em
        // memória (e não numa consulta ao banco) para valerem também no
        // --dry-run, onde nada foi gravado.
        $idContrato = $this->mapaContratos[$legacyContrato];
        $this->dominiosDoContrato[$idContrato][$this->semWww($dominio)] = TRUE;

        if ($dados['id_server_domain'] !== NULL) {
          $this->contasDoContrato[$idContrato][(int) $dados['id_server_domain']] = TRUE;
        }
      }
    }

    return $contadores;
  }

  /**
   * Contas de servidor que a origem liga a um cliente (`dominios.clienteId`) e
   * que o cadastro comercial do contrato não cobre.
   *
   * POR QUE ESTA ETAPA EXISTE
   * -------------------------
   * A origem tem DUAS fontes sobre domínio, e elas não contam a mesma história:
   *
   *  - `clientesContratosDominios` é o cadastro COMERCIAL — uma linha por
   *    domínio contratado, sem dizer em qual servidor ele está;
   *  - `dominios.clienteId` é o INVENTÁRIO — uma linha por CONTA de hospedagem,
   *    com servidor e disco.
   *
   * Um cliente pode ter o site num painel e o e-mail (ou um backup) noutro:
   * `certicais.com.br` no WHM, `certicais.com.br` no CloudPanel e
   * `certicais.com.br--bkp2` no mesmo CloudPanel são TRÊS contas, três discos,
   * e o cadastro comercial tem uma linha só. Importando apenas o cadastro, o
   * disco das outras contas não entra em barra de uso nenhuma — eram 131 GB.
   *
   * A `crm_contracts_domains` modela conta, não nome: a UNIQUE da migration 022
   * é (`id_contract`, `domain`, `id_server_domain`). Esta etapa preenche o que
   * falta para o cadastro daqui refletir o que existe nos servidores.
   *
   * REGRA DE DESTINO
   * ----------------
   *  - se algum contrato do cliente já tem o mesmo nome (desprezando `www.`),
   *    a conta entra NAQUELE contrato — é a segunda conta do mesmo domínio;
   *  - senão, e se o cliente tiver UM contrato só, entra nele;
   *  - se o cliente tiver vários e nenhum já citar o nome, **não grava**: pôr
   *    disco no contrato errado falsearia a barra de uso dos dois. Sai no
   *    relatório para cadastro manual.
   *
   * @param  array $linhas linhas de `dominios` do dump
   * @return array contadores
   */
  private function importarContasDeServidor(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));

    foreach ($linhas as $origem) {
      $legacyId = (int) $origem['id'];

      if ($origem['clienteId'] === NULL) {
        // Conta sem cliente na origem: não há a quem vincular.
        $contadores['ignorados']++;
        continue;
      }

      $legacyCliente = (int) $origem['clienteId'];

      if (!isset($this->mapaClientes[$legacyCliente])) {
        $this->avisar('contas', $legacyId, "cliente {$legacyCliente} não importado — conta ignorada");
        $contadores['ignorados']++;
        continue;
      }

      $idCliente = $this->mapaClientes[$legacyCliente];
      $dominio = $this->normalizarDominio((string) $origem['dominio']);

      if ($dominio === '') {
        $contadores['ignorados']++;
        continue;
      }

      $idServidor = isset($this->mapaServidores[(int) $origem['servidorId']])
        ? $this->mapaServidores[(int) $origem['servidorId']]
        : NULL;

      if ($idServidor === NULL) {
        $this->avisar('contas', $legacyId, "servidor {$origem['servidorId']} da origem não tem correspondente aqui — {$dominio} ignorado");
        $contadores['ignorados']++;
        continue;
      }

      $chaveConta = $idServidor . '|' . $dominio;

      if (!isset($this->contasPorServidor[$chaveConta])) {
        // A conta existia na origem mas não está no inventário sincronizado
        // daqui — foi removida do servidor, ou a sincronização está atrasada.
        $this->avisar('contas', $legacyId, "conta {$dominio} não está no inventário sincronizado deste servidor — ignorada");
        $contadores['ignorados']++;
        continue;
      }

      $idServerDomain = $this->contasPorServidor[$chaveConta];
      $contratos = isset($this->contratosDoCliente[$idCliente]) ? $this->contratosDoCliente[$idCliente] : [];

      if (empty($contratos)) {
        $this->avisar('contas', $legacyId, "cliente {$legacyCliente} não tem contrato — {$dominio} ignorado");
        $contadores['ignorados']++;
        continue;
      }

      // Já coberta pelo cadastro comercial? Então não há nada a fazer.
      foreach ($contratos as $idContrato) {
        if (isset($this->contasDoContrato[$idContrato][$idServerDomain])) {
          $contadores['atualizados']++;
          continue 2;
        }
      }

      $idContrato = $this->contratoParaConta($contratos, $dominio);

      if ($idContrato === NULL) {
        $this->avisar(
          'contas',
          $legacyId,
          "cliente {$legacyCliente} tem " . count($contratos) . " contratos e nenhum cita {$dominio} — cadastre a conta no contrato certo pela tela"
        );
        $contadores['ignorados']++;
        continue;
      }

      $dados = [
        'id_company' => $this->idCompany,
        'legacy_id' => self::LEGACY_OFFSET_CONTA + $legacyId,
        'id_contract' => $idContrato,
        'id_server_domain' => $idServerDomain,
        'domain' => mb_substr($dominio, 0, 255),
        'managed_cdw' => 0,
      ];

      // Vencimento e local de registro são propriedades do DOMÍNIO, não da
      // conta de hospedagem: quando a linha irmã já os tem, a nova herda, em
      // vez de nascer com vencimento em branco para o mesmo nome.
      $irma = $this->linhaIrma($idContrato, $dominio);

      if (!empty($irma)) {
        $dados['due_date'] = $irma->due_date;
        $dados['registrar'] = $irma->registrar;
        $dados['managed_cdw'] = (int) $irma->managed_cdw;
      }

      $this->upsert('crm_contracts_domains', $dados['legacy_id'], $dados, $origem, $contadores, TRUE);

      $this->contasDoContrato[$idContrato][$idServerDomain] = TRUE;
      $this->dominiosDoContrato[$idContrato][$this->semWww($dominio)] = TRUE;
    }

    return $contadores;
  }

  /**
   * Em qual contrato a conta entra. NULL = ambíguo, não gravar.
   *
   * @param  array  $contratos ids locais
   * @param  string $dominio
   * @return int|null
   */
  private function contratoParaConta(array $contratos, $dominio)
  {
    $nome = $this->semWww($dominio);

    foreach ($contratos as $idContrato) {
      if (isset($this->dominiosDoContrato[$idContrato][$nome])) {
        return $idContrato;
      }
    }

    return (count($contratos) === 1) ? $contratos[0] : NULL;
  }

  /**
   * A linha já cadastrada do mesmo domínio no contrato (outra conta), de onde
   * herdar vencimento e local de registro.
   *
   * @param  int    $idContrato
   * @param  string $dominio
   * @return object|null
   */
  private function linhaIrma($idContrato, $dominio)
  {
    $nome = $this->semWww($dominio);

    return $this->db->query(
      'SELECT due_date, registrar, managed_cdw
         FROM crm_contracts_domains
        WHERE id_contract = ?
          AND (domain = ? OR domain = ?)
          AND due_date IS NOT NULL
        LIMIT 1',
      [(int) $idContrato, $nome, 'www.' . $nome]
    )->row();
  }

  /**
   * Nome do domínio sem o `www.` — é assim que as duas fontes da origem se
   * encontram: o cadastro comercial grava `www.certicais.com.br` e o
   * inventário do servidor grava `certicais.com.br`.
   *
   * @param  string $dominio
   * @return string
   */
  private function semWww($dominio)
  {
    return preg_replace('/^www\./', '', mb_strtolower(trim((string) $dominio)));
  }

  /**
   * Documentos de contrato: metadados do dump + o arquivo baixado do B2.
   *
   * O `manifest.json` gravado pelo script de download é o contrato entre as
   * duas metades — evita adivinhar nome de arquivo a partir do nome original,
   * que tem acento, barra e espaço.
   *
   * @param  array $linhas
   * @return array contadores
   */
  private function importarDocumentos(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));
    $manifesto = $this->lerManifesto();

    if ($manifesto === FALSE) {
      $this->avisar('documentos', 0, 'manifest.json não encontrado em ' . $this->pastaArquivos . ' — nenhum documento importado');
      $contadores['ignorados'] = count($linhas);
      return $contadores;
    }

    foreach ($linhas as $origem) {
      $legacyId = (int) $origem['id'];
      $legacyContrato = (int) $origem['contratoId'];

      if (!isset($this->mapaContratos[$legacyContrato])) {
        $this->avisar('documentos', $legacyId, "contrato {$legacyContrato} não importado — documento ignorado");
        $contadores['ignorados']++;
        continue;
      }

      if (!isset($manifesto[$legacyId])) {
        // Não é falha do importador: o registro existe no banco de origem mas
        // o objeto não existe no bucket — arquivo perdido lá atrás. Conta como
        // ignorado (e não como erro) para o resultado da rodada não acusar
        // falha por um defeito que a importação não tem como corrigir; o aviso
        // nomeia cada um para a lista sair inteira no relatório.
        $this->avisar(
          'documentos',
          $legacyId,
          'sem arquivo correspondente no B2 (registro órfão na origem): ' . trim((string) $origem['fileNameOriginal'])
        );
        $contadores['ignorados']++;
        continue;
      }

      $origemArquivo = $this->pastaArquivos . '/' . $manifesto[$legacyId];

      if (!is_file($origemArquivo)) {
        $this->avisar('documentos', $legacyId, 'arquivo do manifesto não existe na pasta: ' . $manifesto[$legacyId]);
        $contadores['erros']++;
        continue;
      }

      $existente = $this->localizar('crm_contracts_files', $legacyId);

      if (!empty($existente)) {
        // Já importado: o arquivo físico já foi gravado numa execução
        // anterior. Recopiar criaria um segundo arquivo órfão a cada rodada.
        $contadores['atualizados']++;
        continue;
      }

      if ($this->simulacao) {
        $contadores['novos']++;
        continue;
      }

      $destino = $this->copiarArquivo($origemArquivo, 'contracts');

      if ($destino === FALSE) {
        $this->avisar('documentos', $legacyId, 'falha ao copiar o arquivo para images/contracts');
        $contadores['erros']++;
        continue;
      }

      $observacao = trim((string) $origem['observacao']);

      if ($observacao === '') {
        $observacao = trim((string) $origem['fileNameOriginal']);
      }

      $dados = [
        'id_company' => $this->idCompany,
        'legacy_id' => $legacyId,
        'id_contract' => $this->mapaContratos[$legacyContrato],
        'name' => mb_substr($observacao, 0, 150),
        'file' => $destino,
        'created' => $this->dataHora($origem['createdAt']),
        'created_by' => $this->idUser,
      ];

      if ($this->global_model->add('crm_contracts_files', $dados) === FALSE) {
        // A linha não entrou: apaga o arquivo recém-copiado para não deixar
        // órfão em images/contracts.
        @unlink(FCPATH . $destino);
        $this->avisar('documentos', $legacyId, 'falha ao gravar o registro do documento');
        $contadores['erros']++;
        continue;
      }

      $contadores['novos']++;
    }

    return $contadores;
  }

  /**
   * Atividades do cliente => `crm_customers_notes` (aba Atividades).
   *
   * O autor da origem (`autorNome`) é preservado NO TEXTO, e não em
   * `created_by`: aquela coluna é FK para `crm_users`, e os autores do
   * gestor-interno não existem como usuários aqui. Jogá-los fora deixaria a
   * observação órfã ("2027 chamar o cliente antes de reajustar" — dito por
   * quem?), e a timeline mostraria o robô como autor de todas.
   *
   * @param  array $linhas
   * @return array contadores
   */
  private function importarAtividades(array $linhas)
  {
    $contadores = $this->novosContadores(count($linhas));

    foreach ($linhas as $origem) {
      $legacyId = (int) $origem['id'];
      $idCliente = $this->resolverCliente((int) $origem['clienteId'], 'atividades', $legacyId, $contadores);

      if ($idCliente === FALSE) {
        continue;
      }

      $observacao = trim((string) $origem['observacao']);

      if ($observacao === '') {
        $this->avisar('atividades', $legacyId, 'observação vazia — ignorada');
        $contadores['ignorados']++;
        continue;
      }

      $autor = trim((string) $origem['autorNome']);

      if ($autor !== '') {
        $observacao .= "\n\n— registrado por " . $autor . ' no gestor-interno';
      }

      $dados = [
        'id_company' => $this->idCompany,
        'legacy_id' => $legacyId,
        'id_customer' => $idCliente,
        'description' => $observacao,
      ];

      // `crm_customers_notes` não tem modified/modified_by.
      $this->upsert('crm_customers_notes', $legacyId, $dados, $origem, $contadores, FALSE);
    }

    return $contadores;
  }

  // ------------------------------------------------------------------
  // Gravação
  // ------------------------------------------------------------------

  /**
   * Insere ou atualiza pela chave (`id_company`, `legacy_id`).
   *
   * `created`/`created_by` só entram no INSERT — no UPDATE são preservados,
   * pela mesma razão do `Server_model::upsertDomain()`: a data de criação do
   * contrato é a âncora do Dashboard e não pode andar a cada reimportação.
   *
   * @param  string $tabela
   * @param  int    $legacyId
   * @param  array  $dados
   * @param  array  $origem      linha do dump, de onde saem created/modified
   * @param  array  $contadores  por referência
   * @param  bool     $temModified a tabela tem as colunas modified/modified_by
   * @param  int|null $idForcado   PK explícita a gravar no INSERT (NULL = AUTO_INCREMENT)
   * @return int|bool id gravado, ou FALSE em erro
   */
  private function upsert($tabela, $legacyId, array $dados, array $origem, array &$contadores, $temModified, $idForcado = NULL)
  {
    $existente = $this->localizar($tabela, $legacyId);

    if ($temModified) {
      $dados['modified'] = $this->dataHora(isset($origem['updatedAt']) ? $origem['updatedAt'] : NULL);
      $dados['modified_by'] = $this->idUser;
    }

    if (!empty($existente)) {
      $contadores['atualizados']++;

      if ($this->simulacao) {
        return (int) $existente->id;
      }

      if ($this->global_model->edit($tabela, $dados, 'id', (int) $existente->id) === FALSE) {
        $this->avisar($tabela, $legacyId, 'falha ao atualizar');
        $contadores['atualizados']--;
        $contadores['erros']++;
        return FALSE;
      }

      return (int) $existente->id;
    }

    $dados['created'] = $this->dataHora(isset($origem['createdAt']) ? $origem['createdAt'] : NULL);
    $dados['created_by'] = $this->idUser;

    // PK explícita (só no INSERT — id de linha existente não muda). O MySQL
    // ajusta o AUTO_INCREMENT sozinho para o maior id gravado + 1, então os
    // cadastros feitos depois pelas telas continuam de onde a importação parou.
    if ($idForcado !== NULL) {
      $dados['id'] = (int) $idForcado;
    }

    if ($this->simulacao) {
      $contadores['novos']++;
      // Na simulação com id da origem o número já é o definitivo; sem ela, um
      // placeholder negativo, que basta para as etapas seguintes contarem.
      return ($idForcado !== NULL) ? (int) $idForcado : -$legacyId;
    }

    $id = $this->global_model->add($tabela, $dados);

    if ($id === FALSE) {
      $this->avisar($tabela, $legacyId, 'falha ao inserir: ' . $this->erroDoBanco());
      $contadores['erros']++;
      return FALSE;
    }

    $contadores['novos']++;

    // Com PK explícita o MySQL não alimenta `insert_id()` (ele só existe para
    // AUTO_INCREMENT), e o `add()` cai no retorno booleano TRUE — que virando
    // (int) seria o id 1, e todos os filhos apontariam para o cliente errado.
    // O id certo é justamente o que mandamos gravar.
    if ($idForcado !== NULL) {
      return (int) $idForcado;
    }

    return (int) $id;
  }

  /**
   * Quantos clientes já importados estão com `id` diferente do `legacy_id`.
   *
   * @return int
   */
  private function clientesComIdDivergente()
  {
    $linha = $this->db->query(
      'SELECT COUNT(*) AS q FROM crm_customers WHERE id_company = ? AND legacy_id IS NOT NULL AND id <> legacy_id',
      [$this->idCompany]
    )->row();

    return empty($linha) ? 0 : (int) $linha->q;
  }

  /**
   * Ids de origem que já pertencem a OUTRO registro no destino.
   *
   * Só id ocupado por linha que não é a mesma (`legacy_id` diferente ou nulo)
   * é problema: reimportar por cima do próprio registro é o caso normal.
   *
   * @param  array $clientes linhas de `clientes` do dump
   * @return array ids em conflito
   */
  private function idsDeClienteOcupados(array $clientes)
  {
    if (empty($clientes)) {
      return [];
    }

    $ids = [];

    foreach ($clientes as $cliente) {
      $ids[] = (int) $cliente['id'];
    }

    $ids = array_unique($ids);
    $lista = implode(',', array_map('intval', $ids));

    $consulta = $this->db->query(
      "SELECT id, legacy_id FROM crm_customers WHERE id IN ({$lista})"
    );

    if ($consulta === FALSE) {
      return [];
    }

    $conflitos = [];

    foreach ($consulta->result() as $linha) {
      if ($linha->legacy_id === NULL || (int) $linha->legacy_id !== (int) $linha->id) {
        $conflitos[] = (int) $linha->id;
      }
    }

    return $conflitos;
  }

  /**
   * @param  string $tabela
   * @param  int    $legacyId
   * @return object|null
   */
  private function localizar($tabela, $legacyId)
  {
    return $this->global_model->getFieldsWhereSingle_off(
      $tabela,
      'id',
      ['id_company' => $this->idCompany, 'legacy_id' => $legacyId],
      TRUE
    );
  }

  /**
   * Copia o arquivo baixado do B2 para dentro de images/, no mesmo formato de
   * caminho e de nome que `MY_Controller::uploadFileFtp()` produz
   * (`images/<pasta>/<ano>/<mês>/<uniqid>.<ext>`) — a tela de download monta a
   * URL a partir daí e não distingue arquivo importado de arquivo enviado.
   *
   * @param  string $origem
   * @param  string $pasta
   * @return string|bool caminho relativo, ou FALSE
   */
  private function copiarArquivo($origem, $pasta)
  {
    $relativo = 'images/' . $pasta . '/' . date('Y') . '/' . date('m');
    $absoluto = FCPATH . $relativo;

    if (!is_dir($absoluto) && !@mkdir($absoluto, 0755, TRUE) && !is_dir($absoluto)) {
      return FALSE;
    }

    $extensao = mb_strtolower(pathinfo($origem, PATHINFO_EXTENSION));
    $nome = uniqid(rand()) . ($extensao !== '' ? '.' . $extensao : '');

    if (!@copy($origem, $absoluto . '/' . $nome)) {
      return FALSE;
    }

    return $relativo . '/' . $nome;
  }

  /**
   * `manifest.json` = { "<legacy_id>": "<nome do arquivo na pasta>", ... }
   *
   * @return array|bool
   */
  private function lerManifesto()
  {
    $caminho = $this->pastaArquivos . '/manifest.json';

    if (!is_file($caminho)) {
      return FALSE;
    }

    $conteudo = json_decode((string) file_get_contents($caminho), TRUE);

    if (!is_array($conteudo)) {
      return FALSE;
    }

    $manifesto = [];

    foreach ($conteudo as $id => $arquivo) {
      $manifesto[(int) $id] = (string) $arquivo;
    }

    return $manifesto;
  }

  // ------------------------------------------------------------------
  // Resolução e normalização
  // ------------------------------------------------------------------

  /**
   * Cidade da origem (texto livre) => ids do destino.
   *
   * `id_state` é sempre derivado do `id_city`, nunca da UF da origem: é a
   * mesma regra do `callb_validador_cidade` — a cidade tem de pertencer ao
   * estado, e quem sabe disso é a tabela, não o dump.
   *
   * @param  string $cidade
   * @param  string $uf
   * @param  int    $legacyId
   * @return array  ['id_city' =>, 'id_state' =>]
   */
  private function resolverCidade($cidade, $uf, $legacyId)
  {
    $cidade = trim($cidade);
    $uf = mb_strtoupper(trim($uf));

    if ($cidade === '' || $uf === '') {
      $this->avisar('clientes', $legacyId, 'sem cidade/estado na origem — usada a cidade padrão');
      return $this->cidadePadrao();
    }

    $chave = mb_strtoupper($cidade) . '|' . $uf;

    if (isset($this->cacheCidades[$chave])) {
      return $this->cacheCidades[$chave];
    }

    $linha = $this->db->query(
      'SELECT c.id, c.id_state FROM crm_country_cities c
         INNER JOIN crm_country_states s ON s.id = c.id_state
        WHERE s.uf = ? AND UPPER(c.name) = UPPER(?) LIMIT 1',
      [$uf, $cidade]
    )->row();

    if (empty($linha)) {
      $this->avisar('clientes', $legacyId, "cidade não encontrada ({$cidade}/{$uf}) — usada a cidade padrão");
      $this->cacheCidades[$chave] = $this->cidadePadrao();
      return $this->cacheCidades[$chave];
    }

    $this->cacheCidades[$chave] = [
      'id_city' => (int) $linha->id,
      'id_state' => (int) $linha->id_state,
    ];

    return $this->cacheCidades[$chave];
  }

  /**
   * @return array
   */
  private function cidadePadrao()
  {
    $linha = $this->global_model->getFieldsWhereSingle_off(
      'crm_country_cities',
      'id, id_state',
      ['id' => self::ID_CIDADE_PADRAO],
      TRUE
    );

    if (empty($linha)) {
      return ['id_city' => NULL, 'id_state' => NULL];
    }

    return ['id_city' => (int) $linha->id, 'id_state' => (int) $linha->id_state];
  }

  /**
   * Correspondência do domínio nos servidores do tenant, tentando o nome exato
   * e a variante com/sem "www." — mesma regra de
   * `Contratos::buscarDominioNosServidores()`.
   *
   * @param  string $dominio já normalizado
   * @return int|null
   */
  private function resolverDominioServidor($dominio)
  {
    if (isset($this->mapaDominiosServidor[$dominio])) {
      return $this->mapaDominiosServidor[$dominio];
    }

    $alternativo = (strpos($dominio, 'www.') === 0) ? substr($dominio, 4) : 'www.' . $dominio;

    return isset($this->mapaDominiosServidor[$alternativo]) ? $this->mapaDominiosServidor[$alternativo] : NULL;
  }

  /**
   * @param  int    $legacyCliente
   * @param  string $etapa
   * @param  int    $legacyId
   * @param  array  $contadores por referência
   * @return int|bool
   */
  private function resolverCliente($legacyCliente, $etapa, $legacyId, array &$contadores)
  {
    if (isset($this->mapaClientes[$legacyCliente])) {
      return $this->mapaClientes[$legacyCliente];
    }

    $this->avisar($etapa, $legacyId, "cliente {$legacyCliente} não importado — linha ignorada");
    $contadores['ignorados']++;

    return FALSE;
  }

  /**
   * Minúsculo, sem esquema, porta ou caminho — o formato de
   * `crm_servers_domains.domain`. Mesma regra de
   * `Contratos::normalizarDominio()`.
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

  /**
   * O destino grava o CEP com máscara (`00.000-000`), que é o formato que a
   * tela devolve. CEP que não tem 8 dígitos entra como veio — mascarar um
   * valor incompleto inventaria um CEP que não existe.
   *
   * @param  string $cep
   * @return string|null
   */
  private function formatarCep($cep)
  {
    $digitos = sonumero($cep);

    if ($digitos === '') {
      return NULL;
    }

    if (strlen($digitos) !== 8) {
      return mb_substr($cep, 0, 20);
    }

    return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '-' . substr($digitos, 5, 3);
  }

  /**
   * A origem guarda só dígitos; o destino guarda com máscara.
   *
   * @param  string $telefone
   * @return string|null
   */
  private function mascararTelefone($telefone)
  {
    $digitos = sonumero($telefone);

    if ($digitos === '') {
      return NULL;
    }

    if (strlen($digitos) === 11) {
      return '(' . substr($digitos, 0, 2) . ') ' . substr($digitos, 2, 5) . '-' . substr($digitos, 7);
    }

    if (strlen($digitos) === 10) {
      return '(' . substr($digitos, 0, 2) . ') ' . substr($digitos, 2, 4) . '-' . substr($digitos, 6);
    }

    return mb_substr($digitos, 0, 45);
  }

  /**
   * `attributes` no mesmo formato que o wizard produz.
   *
   * `representative`, `billing` e `domains` ficam vazios porque esses campos
   * NÃO EXISTEM MAIS na origem — foram removidos da tabela `clientes` do
   * gestor-interno. `consent` fica de fora de propósito: não houve aceite de
   * LGPD nesses cadastros, e gravar um seria registrar consentimento que não
   * aconteceu.
   *
   * @param  array $origem
   * @return string JSON
   */
  private function montarAtributos(array $origem)
  {
    $atributos = [
      'representative' => [
        'name' => '', 'nationality' => '', 'marital_status' => '', 'profession' => '',
        'rg' => '', 'cpf' => '', 'whatsapp' => '',
        'address' => [
          'street' => '', 'number' => '', 'complement' => '', 'district' => '',
          'zip' => '', 'id_state' => 0, 'id_city' => 0, 'city' => '', 'uf' => '',
        ],
      ],
      'billing' => ['name' => '', 'email' => '', 'whatsapp' => '', 'needs_invoice' => ''],
      'domains' => ['primary' => '', 'secondary' => ''],
      'contract' => ['comments' => (string) $origem['observacoesCliente']],
      'source' => [
        'channel' => 'importacao_gestor_interno',
        'legacy_id' => (int) $origem['id'],
        'imported_at' => $this->agora,
      ],
    ];

    return json_encode($atributos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  // ------------------------------------------------------------------
  // Utilitários
  // ------------------------------------------------------------------

  /**
   * Timestamp do Postgres (`2026-06-29 20:06:33.041`) => DATETIME do MySQL.
   *
   * Corta em 19 caracteres em vez de passar por `strtotime`: as strings vêm
   * com milissegundos e sem timezone, e converter aplicaria um fuso que não
   * está escrito ali (mesma regra da integração Bom Controle).
   *
   * @param  string|null $valor
   * @return string|null
   */
  private function dataHora($valor)
  {
    $texto = trim((string) $valor);

    if ($texto === '') {
      return NULL;
    }

    $corte = substr($texto, 0, 19);

    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $corte)) {
      return NULL;
    }

    // "0001-01-01" é o "sem data" do .NET/Postgres; DATETIME do MySQL não o aceita.
    if (substr($corte, 0, 4) < '1900') {
      return NULL;
    }

    return $corte;
  }

  /**
   * Timestamp do Postgres => DATE do MySQL.
   *
   * Só os 10 primeiros caracteres. Os vencimentos vêm ora como
   * `2026-08-22 03:00:00` (meia-noite local gravada em UTC), ora como
   * `2026-10-18 00:00:00`; nos dois casos a data do calendário é a que está
   * escrita, e converter fuso deslocaria metade deles em um dia.
   *
   * @param  string|null $valor
   * @return string|null
   */
  private function data($valor)
  {
    $texto = trim((string) $valor);

    if ($texto === '') {
      return NULL;
    }

    $corte = substr($texto, 0, 10);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $corte) || substr($corte, 0, 4) < '1900') {
      return NULL;
    }

    return $corte;
  }

  /**
   * @param  string|null $valor
   * @param  int         $limite
   * @return string|null
   */
  private function texto($valor, $limite)
  {
    $texto = trim((string) $valor);

    return ($texto === '') ? NULL : mb_substr($texto, 0, $limite);
  }

  /**
   * Chave de comparação de nome de tipo de serviço. O catálogo do destino tem
   * nomes em caixa mista ("Site institucional"); comparar por minúsculas sem
   * acento evita depender da collation e de como o nome foi digitado.
   *
   * @param  string $nome
   * @return string
   */
  private function chaveNome($nome)
  {
    $chave = mb_strtolower(trim(remover_acentos((string) $nome)));

    return isset($this->aliasTiposServico[$chave]) ? $this->aliasTiposServico[$chave] : $chave;
  }

  /**
   * @param  int $total
   * @return array
   */
  private function novosContadores($total)
  {
    return [
      'total' => $total,
      'novos' => 0,
      'atualizados' => 0,
      'ignorados' => 0,
      'erros' => 0,
    ];
  }

  /**
   * @param string $etapa
   * @param int    $legacyId
   * @param string $mensagem
   */
  private function avisar($etapa, $legacyId, $mensagem)
  {
    $this->avisos[] = [
      'etapa' => $etapa,
      'legacy_id' => (int) $legacyId,
      'mensagem' => $mensagem,
    ];
  }

  /**
   * @return string
   */
  private function erroDoBanco()
  {
    $erro = $this->db->error();

    return isset($erro['message']) ? (string) $erro['message'] : '';
  }

  /**
   * @param  string $mensagem
   * @return array
   */
  private function falha($mensagem)
  {
    return [
      'success' => FALSE,
      'message' => $mensagem,
      'data' => ['avisos' => $this->avisos],
    ];
  }
}
