<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Orquestrador único das integrações de cobrança (PSP).
 *
 * Mesmo papel do Bomcontrole_model e do Server_model: decifra a credencial,
 * escolhe o provedor, envolve TODA rede em sessao_suspender() e devolve o
 * vocabulário do sistema. Nenhum controller instancia uma library de PSP
 * diretamente.
 *
 * O PSP é escolha do CONTRATO (`crm_contracts.psp`), então mais de um pode
 * estar ativo no mesmo tenant ao mesmo tempo — é por isso que a credencial
 * mora na tabela `crm_psp_accounts`, com UNIQUE (id_company, psp), e não em
 * colunas de `crm_companies` como a do Bom Controle.
 *
 * PARA ACRESCENTAR UM PSP:
 *   1. escrever a library estendendo Psp_provider;
 *   2. somar a linha em providers();
 *   3. cadastrar a credencial na aba PSP da empresa.
 * Nada no motor de faturas muda.
 *
 * @see docs/PLANO-PSP-COBRANCA.md
 */
class Psp_model extends CI_Model
{
    /** Diretório dos certificados, sob application/ (negado pelo .htaccess). */
    const DIR_CERTIFICADOS = 'certs';

    /** Teto do arquivo de certificado — PEM legítimo não passa disso. */
    const MAX_BYTES_CERTIFICADO = 16384;

    /** Avisa na tela quando o certificado vence dentro deste prazo. */
    const DIAS_AVISO_CERTIFICADO = 30;

    /** Teto de faturas por rodada, por fase. */
    const MAX_COBRANCAS_POR_RODADA = 60;

    /**
     * Pausa entre chamadas ao PSP. O sandbox do Inter devolveu 429 já na ~6ª
     * chamada seguida (medido em 18/08/2026), contra ~12 do Bom Controle.
     */
    const ESPACAMENTO_COBRANCAS_MICROSSEGUNDOS = 1200000;

    /**
     * Marca em `psp_status` que um POST de emissão terminou SEM RESPOSTA
     * conclusiva (5xx, timeout, rede). É o rastro que faz a tentativa seguinte
     * procurar antes de criar — ver adotarCobrancaExistente().
     */
    const MARCA_ENVIO_AMBIGUO = 'FALHA_ENVIO';

    /** Janela da conciliação, em dias de VENCIMENTO (boleto vencido segue pagável). */
    const DIAS_CONCILIACAO_PASSADO = 90;
    const DIAS_CONCILIACAO_FUTURO = 30;

    /** Teto de páginas por conta/rodada — 100 itens cada. */
    const MAX_PAGINAS_CONCILIACAO = 10;

    /** Orçamento de tempo da rodada do cron, no idioma do Server_model. */
    const ORCAMENTO_COBRANCAS_SEGUNDOS = 240;

    /**
     * Teto e orçamento da via INTERATIVA (botões da tela).
     *
     * Bem menores que os do cron porque quem espera é uma pessoa diante de uma
     * requisição HTTP: 240s morreriam no `max_execution_time` e o navegador
     * desistiria antes. O que não couber não se perde — continua pendente, e a
     * rodada seguinte do cron termina.
     */
    const MAX_COBRANCAS_NA_TELA = 4;
    const ORCAMENTO_COBRANCAS_TELA_SEGUNDOS = 25;

    /**
     * @var array|null
     */
    private $providersMemo = NULL;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('global_model');
        $this->load->library('secret_crypto');
    }

    // ------------------------------------------------------------------
    // Catálogo
    // ------------------------------------------------------------------

    /**
     * Allowlist dos provedores, no idioma de Contratos::statusTransicoes() e
     * Painel::faixasDominio(): alimenta o select da tela E valida o POST.
     *
     * Slug fora daqui é ERRO, nunca "usa o padrão" — um PSP forjado no POST
     * não pode virar cobrança no lugar errado.
     *
     * @return array slug => ['classe', 'nome', 'ativo']
     */
    public function providers()
    {
        if ($this->providersMemo === NULL) {
            $this->providersMemo = [
                'inter' => [
                    'classe' => 'Psp_inter',
                    'nome' => 'Banco Inter',
                    'ativo' => TRUE,
                ],
            ];
        }

        return $this->providersMemo;
    }

    /**
     * slug => nome, para selects.
     *
     * @param  bool $somenteAtivos
     * @return array
     */
    public function rotulos($somenteAtivos = TRUE)
    {
        $rotulos = [];

        foreach ($this->providers() as $slug => $definicao) {
            if ($somenteAtivos && empty($definicao['ativo'])) continue;
            $rotulos[$slug] = $definicao['nome'];
        }

        return $rotulos;
    }

    /**
     * Nome de exibição de um slug, mesmo que ele saia do catálogo depois.
     *
     * Cai no próprio slug em vez de vazio pelo mesmo motivo do
     * `ended_reason`: fatura antiga não pode perder a identificação de onde
     * foi cobrada só porque o provedor foi aposentado.
     *
     * @param  string $slug
     * @return string
     */
    public function rotulo($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') return '';

        $providers = $this->providers();

        return isset($providers[$slug]) ? $providers[$slug]['nome'] : $slug;
    }

    /**
     * Instância da library do provedor.
     *
     * @param  string $slug
     * @return Psp_provider|null
     */
    public function provider($slug)
    {
        $slug = trim((string) $slug);
        $providers = $this->providers();

        if ($slug === '' || !isset($providers[$slug])) {
            return NULL;
        }

        $classe = $providers[$slug]['classe'];
        $propriedade = strtolower($classe);

        $this->load->library($propriedade);

        return $this->{$propriedade};
    }

    // ------------------------------------------------------------------
    // Credencial
    // ------------------------------------------------------------------

    /**
     * A conta do tenant naquele PSP, ou NULL.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @return object|null
     */
    public function getAccount($idCompany, $psp)
    {
        $linha = $this->global_model->getWhere_off('crm_psp_accounts', [
            'id_company' => (int) $idCompany,
            'psp' => (string) $psp,
        ], TRUE);

        return empty($linha) ? NULL : $linha;
    }

    /**
     * Todas as contas do tenant, indexadas pelo slug — é o que a aba PSP da
     * empresa desenha.
     *
     * @param  int $idCompany
     * @return array
     */
    public function contasDaEmpresa($idCompany)
    {
        $linhas = $this->global_model->getWhere_off('crm_psp_accounts', [
            'id_company' => (int) $idCompany,
        ], FALSE);

        $contas = [];
        foreach ((array) $linhas as $linha) {
            $contas[(string) $linha->psp] = $linha;
        }

        return $contas;
    }

    /**
     * @param  int    $idCompany
     * @param  string $psp
     * @return bool
     */
    public function isActive($idCompany, $psp)
    {
        $conta = $this->getAccount($idCompany, $psp);

        return !empty($conta) && (int) $conta->active === 1;
    }

    /**
     * Client secret decifrado.
     *
     * O decrypt mora aqui, e não no controller, pelo mesmo motivo do
     * Bomcontrole_model::getApiKey: um ponto só decifra.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @return string|bool '' = nunca cadastrado; FALSE = ilegível
     */
    public function getClientSecret($idCompany, $psp)
    {
        $conta = $this->getAccount($idCompany, $psp);

        $cifrado = empty($conta) ? '' : (string) $conta->client_secret;
        if ($cifrado === '') {
            return '';
        }

        // === FALSE, nunca empty(): '' é retorno válido da Secret_crypto.
        $plano = $this->secret_crypto->decrypt($cifrado);
        if ($plano === FALSE) {
            return FALSE;
        }

        return trim($plano);
    }

    /**
     * Config pronta para a library, com o diagnóstico certo para cada estado
     * inválido — o usuário precisa saber O QUE cadastrar, não que "falhou".
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @return array success, message, config
     */
    public function getConfig($idCompany, $psp)
    {
        $psp = trim((string) $psp);
        $providers = $this->providers();

        if ($psp === '' || !isset($providers[$psp])) {
            return ['success' => FALSE, 'message' => 'Provedor de cobrança desconhecido.', 'config' => NULL];
        }

        $conta = $this->getAccount($idCompany, $psp);
        if (empty($conta)) {
            return [
                'success' => FALSE,
                'message' => 'Nenhuma credencial do ' . $this->rotulo($psp) . ' cadastrada para esta empresa.',
                'config' => NULL,
            ];
        }

        if ((int) $conta->active !== 1) {
            return [
                'success' => FALSE,
                'message' => 'A integração com o ' . $this->rotulo($psp) . ' está desativada para esta empresa.',
                'config' => NULL,
            ];
        }

        $secret = $this->getClientSecret($idCompany, $psp);
        if ($secret === '') {
            return [
                'success' => FALSE,
                'message' => 'Nenhum Client Secret do ' . $this->rotulo($psp) . ' cadastrado para esta empresa.',
                'config' => NULL,
            ];
        }
        if ($secret === FALSE) {
            return [
                'success' => FALSE,
                'message' => 'Não foi possível ler o Client Secret do ' . $this->rotulo($psp) . '. Recadastre-o — a chave de criptografia pode ter sido trocada.',
                'config' => NULL,
            ];
        }

        $extra = json_decode((string) $conta->extra, TRUE);
        if (!is_array($extra)) $extra = [];

        return [
            'success' => TRUE,
            'message' => '',
            'config' => array_merge($extra, [
                'id_company' => (int) $idCompany,
                'environment' => (string) $conta->environment,
                'client_id' => (string) $conta->client_id,
                'client_secret' => $secret,
                'cert_path' => $this->caminhoAbsoluto((string) $conta->cert_path),
                'key_path' => $this->caminhoAbsoluto((string) $conta->key_path),
                'cache_dir' => APPPATH . 'cache',
            ]),
        ];
    }

    /**
     * Grava a credencial. Secret NULL = manter o atual (campo em branco na
     * tela), no mesmo contrato do Bomcontrole_model::salvarConfig.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @param  array  $dados  ['active','environment','client_id','client_secret'|NULL]
     * @param  int    $idUser
     * @return array success, message
     */
    public function salvarConfig($idCompany, $psp, array $dados, $idUser)
    {
        $psp = trim((string) $psp);
        $providers = $this->providers();

        if ($psp === '' || !isset($providers[$psp])) {
            return ['success' => FALSE, 'message' => 'Provedor de cobrança desconhecido.'];
        }

        $ambiente = ((string) ($dados['environment'] ?? '')) === 'producao' ? 'producao' : 'sandbox';

        // `extra` existe para configuração específica de provedor. Hoje nenhum
        // provedor usa: a conta corrente do Inter saiu (sempre a padrão), e a
        // coluna fica para o próximo PSP que precisar.
        $extra = [];

        $campos = [
            'active' => !empty($dados['active']) ? 1 : 0,
            'environment' => $ambiente,
            'client_id' => mb_substr(trim((string) ($dados['client_id'] ?? '')), 0, 255),
            'extra' => !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : NULL,
        ];

        if (array_key_exists('client_secret', $dados) && $dados['client_secret'] !== NULL) {
            $cifrado = $this->secret_crypto->encrypt((string) $dados['client_secret']);
            if ($cifrado === FALSE) {
                return ['success' => FALSE, 'message' => 'Não foi possível cifrar o Client Secret. Confira a chave de criptografia do sistema.'];
            }
            $campos['client_secret'] = $cifrado;
        }

        $existente = $this->getAccount($idCompany, $psp);

        if (empty($existente)) {
            $campos['id_company'] = (int) $idCompany;
            $campos['psp'] = $psp;
            $campos['webhook_token'] = $this->gerarWebhookToken();
            $campos['created'] = date('Y-m-d H:i:s');
            $campos['created_by'] = (int) $idUser;

            if (!$this->global_model->add('crm_psp_accounts', $campos)) {
                return ['success' => FALSE, 'message' => 'Não foi possível gravar a credencial.'];
            }

            return ['success' => TRUE, 'message' => ''];
        }

        $campos['modified'] = date('Y-m-d H:i:s');
        $campos['modified_by'] = (int) $idUser;

        $this->global_model->edit('crm_psp_accounts', $campos, 'id', (int) $existente->id);

        return ['success' => TRUE, 'message' => ''];
    }

    /**
     * Valida e grava o par certificado/chave.
     *
     * A validação é o ponto alto deste método: conferir que a chave CASA com o
     * certificado aqui evita o erro de TLS obscuro que apareceria só na
     * primeira emissão — e a data de expiração extraída é o que permite avisar
     * antes, porque certificado vencido para TODA cobrança do tenant de uma
     * vez.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @param  string $pemCertificado
     * @param  string $pemChave
     * @param  int    $idUser
     * @return array success, message, expira_em
     */
    public function salvarCertificado($idCompany, $psp, $pemCertificado, $pemChave, $idUser)
    {
        $psp = trim((string) $psp);
        $providers = $this->providers();

        if ($psp === '' || !isset($providers[$psp])) {
            return ['success' => FALSE, 'message' => 'Provedor de cobrança desconhecido.', 'expira_em' => NULL];
        }

        if (strlen($pemCertificado) > self::MAX_BYTES_CERTIFICADO || strlen($pemChave) > self::MAX_BYTES_CERTIFICADO) {
            return ['success' => FALSE, 'message' => 'Arquivo grande demais para um certificado.', 'expira_em' => NULL];
        }

        $certificado = @openssl_x509_read($pemCertificado);
        if ($certificado === FALSE) {
            return ['success' => FALSE, 'message' => 'O arquivo de certificado não é um PEM válido (.crt).', 'expira_em' => NULL];
        }

        $chave = @openssl_pkey_get_private($pemChave);
        if ($chave === FALSE) {
            return ['success' => FALSE, 'message' => 'O arquivo de chave não é um PEM válido (.key) — ou está protegido por senha, que esta tela não aceita.', 'expira_em' => NULL];
        }

        if (!@openssl_x509_check_private_key($certificado, $chave)) {
            return ['success' => FALSE, 'message' => 'A chave não corresponde ao certificado. Reenvie o par baixado da mesma integração.', 'expira_em' => NULL];
        }

        $expiraEm = NULL;
        $info = @openssl_x509_parse($certificado);
        if (is_array($info) && !empty($info['validTo_time_t'])) {
            $expiraEm = date('Y-m-d', (int) $info['validTo_time_t']);
        }

        $diretorio = $this->diretorioCertificados($idCompany, $psp);
        if ($diretorio === FALSE) {
            return ['success' => FALSE, 'message' => 'Não foi possível criar a pasta de certificados no servidor.', 'expira_em' => NULL];
        }

        $caminhoCert = $diretorio . '/client.crt';
        $caminhoChave = $diretorio . '/client.key';

        // O BANCO GUARDA O CAMINHO RELATIVO à pasta de certificados, nunca o
        // absoluto: a instalação local e a de produção ficam em diretórios
        // diferentes, e um dump restaurado de um lado no outro deixaria todas
        // as integrações apontando para arquivos inexistentes — falha em
        // silêncio, no meio de uma emissão.
        $relativoCert = $this->caminhoRelativo($caminhoCert);
        $relativoChave = $this->caminhoRelativo($caminhoChave);

        if (@file_put_contents($caminhoCert, $pemCertificado) === FALSE
            || @file_put_contents($caminhoChave, $pemChave) === FALSE) {
            return ['success' => FALSE, 'message' => 'Não foi possível gravar os arquivos de certificado.', 'expira_em' => NULL];
        }

        @chmod($caminhoCert, 0600);
        @chmod($caminhoChave, 0600);

        $existente = $this->getAccount($idCompany, $psp);
        $campos = [
            'cert_path' => $relativoCert,
            'key_path' => $relativoChave,
            'cert_expires_at' => $expiraEm,
        ];

        if (empty($existente)) {
            $campos['id_company'] = (int) $idCompany;
            $campos['psp'] = $psp;
            $campos['webhook_token'] = $this->gerarWebhookToken();
            $campos['created'] = date('Y-m-d H:i:s');
            $campos['created_by'] = (int) $idUser;
            $this->global_model->add('crm_psp_accounts', $campos);
        } else {
            $campos['modified'] = date('Y-m-d H:i:s');
            $campos['modified_by'] = (int) $idUser;
            $this->global_model->edit('crm_psp_accounts', $campos, 'id', (int) $existente->id);
        }

        return ['success' => TRUE, 'message' => '', 'expira_em' => $expiraEm];
    }

    // ------------------------------------------------------------------
    // Rede
    // ------------------------------------------------------------------

    /**
     * Testa a credencial SALVA — nunca uma digitada, que pode estar em branco
     * justamente porque o usuário quer manter a atual.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @return array success, message
     */
    public function testConnection($idCompany, $psp)
    {
        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            return ['success' => FALSE, 'message' => $config['message']];
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            return ['success' => FALSE, 'message' => 'Provedor de cobrança desconhecido.'];
        }

        // Toda rede longa solta o lock de sessão: sem isso, uma chamada de 30s
        // trava qualquer outra requisição do mesmo usuário.
        $sessao = sessao_suspender();
        try {
            $resultado = $provider->test($config['config']);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($resultado['success'])) {
            $this->logarErro($idCompany, $psp, 'test', (string) $resultado['message']);
        }

        return [
            'success' => !empty($resultado['success']),
            'message' => (string) $resultado['message'],
        ];
    }

    // ------------------------------------------------------------------
    // Cobrança
    // ------------------------------------------------------------------

    /**
     * Registra a cobrança de uma fatura no PSP dela.
     *
     * Roda SEMPRE fora da transação que criou a fatura: a fatura já está
     * protegida pela UNIQUE e não pode ser desfeita por uma falha de rede.
     * Falhar aqui deixa `psp_charge_id` vazio — que É a fila de retentativa,
     * varrida por processarPendentes() e pela conciliação da etapa D.
     *
     * @param  int $idInvoice
     * @param  int $idCompany
     * @param  int $idUser
     * @return array success, message, data
     */
    public function registrarCobranca($idInvoice, $idCompany, $idUser)
    {
        $fatura = $this->global_model->getWhere_off('crm_invoices', [
            'id' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($fatura)) {
            return $this->resposta(FALSE, 'Fatura não encontrada.');
        }

        if ((string) $fatura->status !== 'aberta') {
            return $this->resposta(FALSE, 'Só fatura em aberto vira cobrança.');
        }

        // Idempotência: a fatura já registrada não pode gerar um SEGUNDO
        // boleto. É a mesma regra da UNIQUE que impede cobrança dupla na
        // geração, aplicada ao lado de fora.
        if (trim((string) $fatura->psp_charge_id) !== '') {
            return $this->resposta(TRUE, 'A cobrança desta fatura já estava registrada.', [
                'charge_id' => (string) $fatura->psp_charge_id,
                'criada' => FALSE,
            ]);
        }

        $psp = trim((string) $fatura->psp);
        if ($psp === '') {
            return $this->resposta(FALSE, 'Esta fatura não tem provedor de cobrança definido — configure o PSP no contrato e gere-a de novo.');
        }

        $cobranca = $this->montarCobrancaDaFatura($fatura);
        if ($cobranca === FALSE) {
            return $this->resposta(FALSE, 'Cliente da fatura não encontrado.');
        }

        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            return $this->resposta(FALSE, $config['message']);
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            return $this->resposta(FALSE, 'Provedor de cobrança desconhecido.');
        }

        // ANTES DE CRIAR: se a tentativa anterior morreu sem resposta
        // conclusiva, a cobrança PODE ter sido criada assim mesmo — um 500 do
        // banco não diz se ele processou antes de falhar. Postar de novo às
        // cegas emitiria um segundo boleto para a mesma fatura, que é o
        // acidente que todo este módulo existe para evitar.
        //
        // É para isso que `seuNumero` leva o id da fatura: a busca no provedor
        // responde "já existe cobrança minha para esta fatura?" sem depender
        // do `psp_charge_id`, que é justamente o que se perdeu.
        //
        // A busca só roda quando há o rastro da falha — no caminho feliz seria
        // uma chamada extra por fatura, contra um rate limit que estoura em
        // ~6 seguidas.
        if ((string) $fatura->psp_status === self::MARCA_ENVIO_AMBIGUO) {
            $adotada = $this->adotarCobrancaExistente($psp, $config['config'], $fatura, $idUser);
            if ($adotada !== NULL) {
                return $adotada;
            }
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $provider->criarCobranca($config['config'], $cobranca);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($resultado['success'])) {
            $this->logarErro($idCompany, $psp, 'criarCobranca fatura=' . (int) $idInvoice, (string) $resultado['message']);

            // Falha AMBÍGUA (5xx, timeout, rede): o pedido pode ter chegado.
            // Deixa o rastro para a próxima tentativa procurar antes de criar.
            // Erro definitivo (422, dado inválido) não recebe marca: ali o
            // banco recusou, nada foi criado, e uma busca inútil só gastaria
            // quota.
            if (!empty($resultado['transient'])) {
                $this->global_model->edit('crm_invoices', [
                    'psp_status' => self::MARCA_ENVIO_AMBIGUO,
                    'modified' => date('Y-m-d H:i:s'),
                    'modified_by' => (int) $idUser,
                ], 'id', (int) $idInvoice);
            }

            return $this->resposta(FALSE, (string) $resultado['message'], ['transient' => !empty($resultado['transient'])]);
        }

        $dados = isset($resultado['data']) && is_array($resultado['data']) ? $resultado['data'] : [];

        $this->global_model->edit('crm_invoices', [
            'psp_charge_id' => (string) ($dados['charge_id'] ?? ''),
            'psp_status' => mb_substr((string) ($dados['psp_status'] ?? $dados['situacao'] ?? ''), 0, 30),
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ], 'id', (int) $idInvoice);

        return $this->resposta(TRUE, 'Cobrança registrada.', [
            'charge_id' => (string) ($dados['charge_id'] ?? ''),
            'criada' => TRUE,
        ]);
    }

    /**
     * Procura no provedor uma cobrança já emitida para esta fatura e a adota.
     *
     * Roda só depois de uma tentativa ambígua. A âncora é o `seuNumero`, que
     * leva o `crm_invoices.id` desde a primeira emissão — sem ele não haveria
     * como reconhecer a cobrança órfã, e a única saída seria criar outra.
     *
     * A janela de busca é o VENCIMENTO da própria fatura, com um dia de folga
     * de cada lado: é o campo por onde o Inter filtra, e a cobrança órfã tem
     * exatamente o vencimento que mandamos.
     *
     * @param  string $psp
     * @param  array  $config
     * @param  object $fatura
     * @param  int    $idUser
     * @return array|null NULL = não achou (o chamador segue e cria)
     */
    private function adotarCobrancaExistente($psp, array $config, $fatura, $idUser)
    {
        $provider = $this->provider($psp);
        if ($provider === NULL) return NULL;

        $vencimento = (string) $fatura->due_date;

        $sessao = sessao_suspender();
        try {
            $busca = $provider->listarCobrancas($config, [
                'data_inicial' => date('Y-m-d', strtotime($vencimento . ' -1 day')),
                'data_final' => date('Y-m-d', strtotime($vencimento . ' +1 day')),
                'por_pagina' => 100,
            ]);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($busca['success'])) {
            // Não deu para conferir. Devolver NULL faria o chamador CRIAR, que
            // é exatamente o risco que esta função existe para evitar — então
            // a operação para aqui e tenta de novo na próxima passagem.
            return $this->resposta(FALSE, 'Não foi possível conferir no ' . $this->rotulo($psp)
                . ' se a cobrança anterior chegou a ser criada (' . $busca['message']
                . '). A emissão foi adiada para não arriscar um segundo boleto.', ['transient' => TRUE]);
        }

        $itens = isset($busca['data']['itens']) && is_array($busca['data']['itens']) ? $busca['data']['itens'] : [];

        foreach ($itens as $item) {
            if ((string) ($item['referencia'] ?? '') !== (string) $fatura->id) continue;

            // Cobrança cancelada ou expirada não serve: adotá-la deixaria a
            // fatura presa a um boleto que ninguém pode pagar.
            $situacao = (string) ($item['situacao'] ?? '');
            if ($situacao === Psp_provider::SIT_CANCELADA || $situacao === Psp_provider::SIT_EXPIRADA) {
                continue;
            }

            $chargeId = (string) ($item['charge_id'] ?? '');
            if ($chargeId === '') continue;

            $this->global_model->edit('crm_invoices', [
                'psp_charge_id' => $chargeId,
                'psp_status' => mb_substr((string) ($item['psp_status'] ?? ''), 0, 30),
                'modified' => date('Y-m-d H:i:s'),
                'modified_by' => (int) $idUser,
            ], 'id', (int) $fatura->id);

            $this->logarErro(
                (int) $fatura->id_company,
                $psp,
                'adotarCobranca fatura=' . (int) $fatura->id,
                'cobranca ' . $chargeId . ' ja existia no provedor apos envio ambiguo; adotada em vez de criar outra'
            );

            return $this->resposta(TRUE, 'A cobrança já havia sido criada no ' . $this->rotulo($psp)
                . ' na tentativa anterior — foi reaproveitada em vez de emitir outra.', [
                'charge_id' => $chargeId,
                'criada' => FALSE,
            ]);
        }

        // Conferido e não existe: a tentativa anterior de fato não criou nada.
        // Limpa o rastro para a próxima não pagar a busca de novo.
        $this->global_model->edit('crm_invoices', [
            'psp_status' => '',
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ], 'id', (int) $fatura->id);

        return NULL;
    }

    /**
     * Relê a cobrança no PSP e grava boleto, PIX e situação.
     *
     * É passo OBRIGATÓRIO, não opcional: a emissão do Inter é assíncrona — o
     * POST devolve só o código de solicitação, e a linha digitável e o
     * copia-e-cola do PIX só existem na consulta seguinte. Sem isto, a fatura
     * fica com cobrança registrada e nada para mandar ao cliente.
     *
     * NÃO dá baixa em pagamento, mesmo que o PSP responda "recebido": mudar o
     * status para 'paga' é ato da etapa C/D, com as regras dela. Aqui o que o
     * PSP disse fica em `psp_status`, visível para diagnóstico.
     *
     * @param  int $idInvoice
     * @param  int $idCompany
     * @param  int $idUser
     * @return array success, message, data
     */
    public function sincronizarCobranca($idInvoice, $idCompany, $idUser)
    {
        $fatura = $this->global_model->getWhere_off('crm_invoices', [
            'id' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($fatura)) {
            return $this->resposta(FALSE, 'Fatura não encontrada.');
        }

        $psp = trim((string) $fatura->psp);
        $chargeId = trim((string) $fatura->psp_charge_id);

        if ($psp === '' || $chargeId === '') {
            return $this->resposta(FALSE, 'Esta fatura ainda não tem cobrança registrada.');
        }

        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            return $this->resposta(FALSE, $config['message']);
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            return $this->resposta(FALSE, 'Provedor de cobrança desconhecido.');
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $provider->consultarCobranca($config['config'], $chargeId);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($resultado['success'])) {
            $this->logarErro($idCompany, $psp, 'consultarCobranca fatura=' . (int) $idInvoice, (string) $resultado['message']);
            return $this->resposta(FALSE, (string) $resultado['message'], ['transient' => !empty($resultado['transient'])]);
        }

        $dados = isset($resultado['data']) && is_array($resultado['data']) ? $resultado['data'] : [];

        // CHAVE AUSENTE = o PSP não informou, e a coluna NÃO é tocada. Gravar
        // '' por cima apagaria uma linha digitável boa quando a consulta
        // seguinte viesse incompleta (regra do CloudPanel com plano/IP/cota).
        $campos = [];
        foreach (['psp_status' => 'psp_status', 'linha_digitavel' => 'linha_digitavel', 'link_pix' => 'link_pix', 'link_boleto' => 'link_boleto'] as $origem => $coluna) {
            if (isset($dados[$origem]) && $dados[$origem] !== '') {
                $campos[$coluna] = $dados[$origem];
            }
        }

        if (empty($campos)) {
            return $this->resposta(TRUE, 'O provedor ainda não devolveu os dados da cobrança. Tente de novo em instantes.', [
                'pronta' => FALSE,
            ]);
        }

        $campos['modified'] = date('Y-m-d H:i:s');
        $campos['modified_by'] = (int) $idUser;
        $this->global_model->edit('crm_invoices', $campos, 'id', (int) $idInvoice);

        return $this->resposta(TRUE, 'Cobrança atualizada.', [
            'pronta' => isset($campos['linha_digitavel']) || isset($campos['link_pix']),
            'situacao' => (string) ($dados['situacao'] ?? ''),
        ]);
    }

    /**
     * REGRA ÚNICA de "resolver o que falta no PSP", acionável por três vias.
     *
     * O que muda entre elas é só o ESCOPO e o orçamento; a regra é a mesma, e
     * é isso que impede as vias de divergirem — o cron registrando de um jeito
     * e o botão de outro seria a mesma pergunta com duas respostas.
     *
     *   cron            → sem escopo, teto e orçamento largos
     *   GERAR FATURA    → id_contract, teto e orçamento de tela
     *   botão da fatura → id_invoice, idem
     *
     * Duas fases, na ordem: registrar as sem cobrança e completar as sem
     * boleto/PIX. A segunda fase reconsulta a fila, então a fatura registrada
     * na fase 1 já cai na fase 2 e sai da chamada com a linha digitável — é o
     * que evita exigir dois cliques.
     *
     * Não existe tabela de fila: `psp_charge_id` vazio numa fatura aberta JÁ
     * significa "falta registrar", e a linha digitável vazia significa "falta
     * completar". Uma fila à parte seria uma segunda verdade sobre o mesmo
     * estado, capaz de discordar da própria `crm_invoices`.
     *
     * NUNCA desfaz nada: a fatura já está gravada e protegida pela UNIQUE, e
     * falha aqui é só trabalho que fica pendente para a próxima passagem.
     *
     * @param  array $opcoes id_user, id_company, id_contract, id_invoice,
     *                       limite, orcamento
     * @return array registradas, sincronizadas, falhas, mensagens, restaram
     */
    public function processarPendentes(array $opcoes = [])
    {
        $idUser = (int) ($opcoes['id_user'] ?? 0);
        $limite = (int) ($opcoes['limite'] ?? self::MAX_COBRANCAS_POR_RODADA);
        $orcamento = (int) ($opcoes['orcamento'] ?? self::ORCAMENTO_COBRANCAS_SEGUNDOS);

        $escopo = [
            'id_company' => (int) ($opcoes['id_company'] ?? 0),
            'id_contract' => (int) ($opcoes['id_contract'] ?? 0),
            'id_invoice' => (int) ($opcoes['id_invoice'] ?? 0),
        ];

        $inicio = time();
        $saida = ['registradas' => 0, 'sincronizadas' => 0, 'falhas' => 0, 'mensagens' => [], 'restaram' => FALSE];

        foreach (['registrar', 'sincronizar'] as $fase) {
            $faturas = $this->faturasPendentes($escopo, $fase, $limite);

            foreach ($faturas as $fatura) {
                // Orçamento de tempo, no idioma do Server_model: a rodada não
                // pode morrer no max_execution_time no meio de uma chamada, e
                // o que não coube fica pendente para a próxima — o estado é a
                // própria fila, então repetir continua de onde parou.
                if ((time() - $inicio) >= $orcamento) {
                    $saida['restaram'] = TRUE;
                    $saida['mensagens'][] = 'Orçamento de tempo esgotado; o restante fica para a próxima rodada.';
                    return $saida;
                }

                $r = ($fase === 'registrar')
                    ? $this->registrarCobranca((int) $fatura->id, (int) $fatura->id_company, $idUser)
                    : $this->sincronizarCobranca((int) $fatura->id, (int) $fatura->id_company, $idUser);

                if (empty($r['success'])) {
                    $saida['falhas']++;
                    $saida['mensagens'][] = 'fatura #' . (int) $fatura->id . ': ' . $r['message'];
                } elseif ($fase === 'registrar') {
                    $saida['registradas']++;
                } else {
                    $saida['sincronizadas']++;
                }

                // O rate limit do Inter estoura em ~6 chamadas seguidas
                // (medido no sandbox em 18/08/2026) — bem antes do ~12 do Bom
                // Controle. Sem espaçamento, uma competência anual em 12x
                // levaria a rodada inteira para o 429.
                usleep(self::ESPACAMENTO_COBRANCAS_MICROSSEGUNDOS);
            }
        }

        return $saida;
    }

    /**
     * As faturas que faltam registrar (fase 'registrar') ou completar (fase
     * 'sincronizar').
     *
     * @param  array|int $escopo ['id_company','id_contract','id_invoice']; 0 = sem recorte
     * @param  string    $fase
     * @param  int       $limite
     * @return array
     */
    public function faturasPendentes($escopo, $fase, $limite = self::MAX_COBRANCAS_POR_RODADA)
    {
        $limite = max(1, (int) $limite);

        // Aceita o int antigo (empresa) por compatibilidade com qualquer
        // chamada que ainda passe só o tenant.
        if (!is_array($escopo)) {
            $escopo = ['id_company' => (int) $escopo];
        }

        $condicao = ($fase === 'registrar')
            ? "(`psp_charge_id` IS NULL OR `psp_charge_id` = '')"
            : "`psp_charge_id` IS NOT NULL AND `psp_charge_id` <> ''
               AND (`linha_digitavel` IS NULL OR `linha_digitavel` = '')
               AND (`link_pix` IS NULL OR `link_pix` = '')";

        $sql = "SELECT `id`, `id_company`
                FROM `crm_invoices`
                WHERE `status` = 'aberta'
                  AND `psp` <> ''
                  AND {$condicao}";

        // O escopo é uma ALLOWLIST de colunas: nada aqui vem do POST direto, e
        // um escopo desconhecido simplesmente não filtra — nunca vira SQL.
        $colunas = ['id_company' => 'id_company', 'id_contract' => 'id_contract', 'id_invoice' => 'id'];

        $binds = [];
        foreach ($colunas as $chave => $coluna) {
            $valor = (int) ($escopo[$chave] ?? 0);
            if ($valor > 0) {
                $sql .= ' AND `' . $coluna . '` = ?';
                $binds[] = $valor;
            }
        }

        // Mais antiga primeiro: com orçamento de tempo, a ordem decide quem
        // fica para trás — e quem vence antes é quem o cliente precisa receber
        // antes. LIMIT por inteiro já convertido (bind viraria LIMIT '60').
        $sql .= ' ORDER BY `due_date` ASC, `id` ASC LIMIT ' . $limite;

        return $this->db->query($sql, $binds)->result();
    }

    /**
     * Traduz a fatura + o cadastro do cliente no vocabulário da library.
     *
     * @param  object $fatura
     * @return array|bool FALSE quando o cliente sumiu
     */
    private function montarCobrancaDaFatura($fatura)
    {
        // A view traz cidade e UF resolvidas; o boleto precisa dos nomes, não
        // dos ids.
        $cliente = $this->global_model->getWhere_off('crm_customers_v', [
            'id' => (int) $fatura->id_customer,
        ], TRUE);

        if (empty($cliente)) {
            return FALSE;
        }

        return [
            // `seuNumero` recebe o id da fatura: é o que faz a conciliação
            // sobreviver a um psp_charge_id perdido — a listagem do PSP diz de
            // qual fatura é cada cobrança.
            'referencia' => (string) $fatura->id,
            'valor' => (float) $fatura->value,
            'vencimento' => (string) $fatura->due_date,
            'descricao' => (string) $fatura->description,
            'pagador' => [
                'documento' => (string) $cliente->document,
                'nome' => (string) $cliente->name,
                'email' => (string) $cliente->email,
                'endereco' => (string) $cliente->address,
                'numero' => (string) $cliente->address_number,
                'complemento' => (string) $cliente->address_complement,
                'bairro' => (string) $cliente->address_district,
                'cidade' => (string) $cliente->city_name,
                'uf' => (string) $cliente->state_uf,
                'cep' => (string) $cliente->address_zip,
            ],
        ];
    }

    /**
     * @param  bool   $success
     * @param  string $message
     * @param  array  $data
     * @return array
     */
    private function resposta($success, $message, array $data = [])
    {
        return ['success' => (bool) $success, 'message' => (string) $message, 'data' => $data];
    }

    /**
     * Troca o PSP de UMA fatura e força o registro da cobrança.
     *
     * Existe para o caso em que o provedor recusou (documento, endereço,
     * indisponibilidade) e a cobrança precisa sair por outro banco sem esperar
     * o contrato inteiro ser reconfigurado — o contrato segue como está, e só
     * esta fatura muda de provedor. É o snapshot `crm_invoices.psp` que torna
     * isso possível sem quebrar nada depois: o webhook e a conciliação já
     * perguntam ao provedor gravado NA FATURA.
     *
     * A REGRA QUE NÃO PODE SER RELAXADA: se já existe cobrança registrada, ela
     * é CANCELADA no provedor antigo antes da troca, e **falha no cancelamento
     * aborta a operação**. Trocar com a cobrança velha viva no banco anterior
     * deixaria dois boletos da mesma fatura em pé — o cliente pagaria o que
     * chegasse primeiro e o outro seguiria cobrando. Entre "não trocar" e
     * "cobrar duas vezes", não trocar é o erro barato.
     *
     * @param  int    $idInvoice
     * @param  int    $idCompany
     * @param  string $novoPsp
     * @param  int    $idUser
     * @return array success, message, data
     */
    public function trocarPsp($idInvoice, $idCompany, $novoPsp, $idUser)
    {
        $fatura = $this->global_model->getWhere_off('crm_invoices', [
            'id' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($fatura)) {
            return $this->resposta(FALSE, 'Fatura não encontrada.');
        }

        // Fatura paga ou cancelada não muda de provedor: a cobrança dela já
        // cumpriu (ou encerrou) o papel, e mexer nisso reescreveria histórico
        // financeiro.
        if ((string) $fatura->status !== 'aberta') {
            return $this->resposta(FALSE, 'Só fatura em aberto pode trocar de provedor.');
        }

        $novoPsp = trim((string) $novoPsp);
        if ($novoPsp === '' || !isset($this->providers()[$novoPsp])) {
            return $this->resposta(FALSE, 'Provedor de cobrança desconhecido.');
        }

        if (!$this->isActive($idCompany, $novoPsp)) {
            return $this->resposta(FALSE, sprintf(
                'A integração com o %s não está ativa para esta empresa. Ative a credencial em Empresas › Cobrança (PSP).',
                $this->rotulo($novoPsp)
            ));
        }

        $pspAtual = trim((string) $fatura->psp);
        $chargeId = trim((string) $fatura->psp_charge_id);
        $trocou = ($novoPsp !== $pspAtual);

        if ($trocou && $chargeId !== '') {
            $cancelamento = $this->cancelarCobranca(
                (int) $idInvoice,
                $idCompany,
                'Cobranca reemitida em outro provedor',
                $idUser
            );

            if (empty($cancelamento['success'])) {
                return $this->resposta(FALSE, sprintf(
                    'A cobrança atual no %s não pôde ser cancelada (%s). A troca foi abortada — trocar sem cancelar deixaria dois boletos da mesma fatura em aberto.',
                    $this->rotulo($pspAtual),
                    $cancelamento['message']
                ));
            }
        }

        if ($trocou) {
            // Zera o retrato do provedor antigo JUNTO com a troca: deixar o
            // charge_id antigo com o psp novo faria a conciliação perguntar ao
            // banco errado por um id que ele não conhece. O cancelamento acima
            // já limpa quando havia cobrança; aqui a limpeza cobre o caso em
            // que não havia (nunca registrada), e é repetida de propósito —
            // depender do ramo anterior deixaria a troca correta só por sorte.
            $this->global_model->edit('crm_invoices', [
                'psp' => $novoPsp,
                'psp_charge_id' => NULL,
                'psp_status' => '',
                'link_boleto' => NULL,
                'linha_digitavel' => NULL,
                'link_pix' => NULL,
                'modified' => date('Y-m-d H:i:s'),
                'modified_by' => (int) $idUser,
            ], 'id', (int) $idInvoice);
        }

        // Daqui em diante é a REGRA ÚNICA de sempre, recortada nesta fatura —
        // registrar e completar são as mesmas duas fases que o cron roda.
        $processo = $this->processarPendentes([
            'id_user' => $idUser,
            'id_company' => $idCompany,
            'id_invoice' => (int) $idInvoice,
            'limite' => self::MAX_COBRANCAS_NA_TELA,
            'orcamento' => self::ORCAMENTO_COBRANCAS_TELA_SEGUNDOS,
        ]);

        $prefixo = $trocou
            ? 'Provedor alterado para ' . $this->rotulo($novoPsp) . '. '
            : '';

        if ((int) $processo['falhas'] > 0) {
            return $this->resposta(FALSE, $prefixo . implode('; ', $processo['mensagens']), [
                'trocou' => $trocou,
            ]);
        }

        return $this->resposta(TRUE, $prefixo . 'Cobrança processada.', ['trocou' => $trocou]);
    }

    /**
     * Cancela a cobrança de uma fatura no provedor dela e limpa o retrato.
     *
     * Separado do `Faturas::post_status` de propósito: cancelar a FATURA é ato
     * financeiro local, cancelar a COBRANÇA é ida ao banco. O primeiro deveria
     * disparar o segundo, mas o inverso não vale — reemitir em outro provedor
     * cancela a cobrança sem cancelar a fatura, que é justamente este caso.
     *
     * @param  int    $idInvoice
     * @param  int    $idCompany
     * @param  string $motivo
     * @param  int    $idUser
     * @return array success, message, data
     */
    public function cancelarCobranca($idInvoice, $idCompany, $motivo, $idUser)
    {
        $fatura = $this->global_model->getWhere_off('crm_invoices', [
            'id' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($fatura)) {
            return $this->resposta(FALSE, 'Fatura não encontrada.');
        }

        $psp = trim((string) $fatura->psp);
        $chargeId = trim((string) $fatura->psp_charge_id);

        // Sem cobrança registrada não há o que cancelar, e isso é SUCESSO: o
        // estado final desejado (nenhuma cobrança viva) já vale. Tratar como
        // erro travaria a troca de provedor de uma fatura que nunca chegou a
        // ser registrada — o caso mais comum de querer trocar.
        if ($psp === '' || $chargeId === '') {
            return $this->resposta(TRUE, 'Não havia cobrança registrada.', ['cancelou' => FALSE]);
        }

        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            return $this->resposta(FALSE, $config['message']);
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            return $this->resposta(FALSE, 'Provedor de cobrança desconhecido.');
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $provider->cancelarCobranca($config['config'], $chargeId, $motivo);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($resultado['success'])) {
            $this->logarErro($idCompany, $psp, 'cancelarCobranca fatura=' . (int) $idInvoice, (string) $resultado['message']);
            return $this->resposta(FALSE, (string) $resultado['message']);
        }

        // A trilha do que foi cancelado vai para o log ANTES de o id sair da
        // linha: é o canal de auditoria das integrações neste projeto, e sem
        // ele um cancelamento que o banco aceitou (202, assíncrono) mas não
        // concluísse deixaria uma cobrança viva sem referência nenhuma.
        $this->logarErro(
            $idCompany,
            $psp,
            'cancelarCobranca fatura=' . (int) $idInvoice,
            'cobranca ' . $chargeId . ' cancelada a pedido: ' . $motivo
        );

        // O boleto e o PIX são APAGADOS junto: eles descrevem uma cobrança que
        // não existe mais, e deixá-los na linha permitiria copiar e mandar ao
        // cliente um boleto morto. Com `psp_charge_id` vazio a fatura volta a
        // `nao_registrada` na crm_invoices_v — que é a verdade (não há
        // cobrança viva) e a recoloca na fila, exatamente onde a troca de
        // provedor precisa que ela esteja.
        $this->global_model->edit('crm_invoices', [
            'psp_charge_id' => NULL,
            'psp_status' => 'CANCELADO',
            'link_boleto' => NULL,
            'linha_digitavel' => NULL,
            'link_pix' => NULL,
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ], 'id', (int) $idInvoice);

        // O PDF guardado descreve o boleto que acabou de ser cancelado.
        $this->descartarBoleto((int) $idInvoice);

        return $this->resposta(TRUE, 'Cobrança cancelada no ' . $this->rotulo($psp) . '.', ['cancelou' => TRUE]);
    }

    /**
     * Rótulos dos estados de registro derivados na crm_invoices_v.
     *
     * @return array
     */
    public function registrationLabels()
    {
        return [
            'sem_psp' => 'Sem provedor',
            'nao_registrada' => 'Não registrada',
            'registrando' => 'Registrando',
            'registrada' => 'Registrada',
        ];
    }

    /**
     * URL pública do webhook desta conta.
     *
     * O token vai no CAMINHO, não em parâmetro: assim ele não aparece em log de
     * proxy como query string, e o handler resolve tenant + PSP antes de tocar
     * no corpo.
     *
     * @param  object $conta
     * @return string '' quando a conta não tem token
     */
    public function urlWebhook($conta)
    {
        if (empty($conta) || empty($conta->webhook_token)) return '';

        return rtrim(base_url(), '/') . '/webhook/psp/'
            . rawurlencode((string) $conta->psp) . '/'
            . rawurlencode((string) $conta->webhook_token);
    }

    /**
     * Aponta a URL do webhook no provedor.
     *
     * Sem isto a etapa C fica inerte: o endpoint existe e ninguém chama. O
     * provedor exige HTTPS com certificado válido, então **isto não funciona
     * do ambiente local** — é ação de produção.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @return array success, message, data => ['url']
     */
    public function registrarWebhook($idCompany, $psp)
    {
        $conta = $this->getAccount($idCompany, $psp);
        $url = $this->urlWebhook($conta);

        if ($url === '') {
            return $this->resposta(FALSE, 'Esta conta ainda não tem token de webhook. Salve a credencial primeiro.');
        }

        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            return $this->resposta(FALSE, $config['message']);
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            return $this->resposta(FALSE, 'Provedor de cobrança desconhecido.');
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $provider->registrarWebhook($config['config'], $url);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($resultado['success'])) {
            $this->logarErro($idCompany, $psp, 'registrarWebhook', (string) $resultado['message']);
            return $this->resposta(FALSE, (string) $resultado['message']);
        }

        return $this->resposta(TRUE, 'Webhook registrado no ' . $this->rotulo($psp) . '.', ['url' => $url]);
    }

    // ------------------------------------------------------------------
    // Conciliação e baixa (etapas C e D)
    // ------------------------------------------------------------------

    /**
     * Reconsulta UMA cobrança no provedor e aplica a baixa se ela estiver paga.
     *
     * É o caminho do WEBHOOK (etapa C): o corpo recebido diz apenas QUAL
     * cobrança olhar, e a verdade vem desta consulta. Nunca acreditar no valor
     * que chega no corpo é a mesma regra que revalida o
     * `bomcontrole_contract_id` no `Obter` — e aqui ela vale ainda mais,
     * porque o webhook do Inter pode não ser assinado.
     *
     * @param  int $idInvoice
     * @param  int $idCompany
     * @param  int $idUser
     * @return array success, message, data => ['baixou' => bool, 'situacao']
     */
    public function conciliarCobranca($idInvoice, $idCompany, $idUser)
    {
        $fatura = $this->global_model->getWhere_off('crm_invoices', [
            'id' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($fatura)) {
            return $this->resposta(FALSE, 'Fatura não encontrada.');
        }

        $psp = trim((string) $fatura->psp);
        $chargeId = trim((string) $fatura->psp_charge_id);

        if ($psp === '' || $chargeId === '') {
            return $this->resposta(FALSE, 'Fatura sem cobrança registrada — nada a conciliar.');
        }

        // IDEMPOTÊNCIA: o PSP reenvia o mesmo evento quando o handler demora ou
        // erra, e a conciliação por pull passa pela mesma cobrança todo dia.
        // `paid_at` preenchido é o carimbo que impede a segunda baixa.
        if (!empty($fatura->paid_at)) {
            return $this->resposta(TRUE, 'Fatura já estava baixada.', ['baixou' => FALSE]);
        }

        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            return $this->resposta(FALSE, $config['message']);
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            return $this->resposta(FALSE, 'Provedor de cobrança desconhecido.');
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $provider->consultarCobranca($config['config'], $chargeId);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($resultado['success'])) {
            $this->logarErro($idCompany, $psp, 'conciliar fatura=' . (int) $idInvoice, (string) $resultado['message']);
            return $this->resposta(FALSE, (string) $resultado['message'], ['transient' => !empty($resultado['transient'])]);
        }

        $dados = isset($resultado['data']) && is_array($resultado['data']) ? $resultado['data'] : [];

        return $this->aplicarBaixa($fatura, $dados, $idUser);
    }

    /**
     * Concilia um PERÍODO inteiro, por listagem — o caminho do CRON (etapa D).
     *
     * Consulta uma a uma custaria uma chamada por fatura em aberto: com ~400
     * faturas seriam ~400 requisições por rodada, contra um rate limit que
     * estoura em ~6 seguidas. A listagem traz 100 por página, então o mesmo
     * trabalho cabe em poucas chamadas — e é por isso que `seuNumero` carrega o
     * id da fatura desde a emissão: o casamento é feito aqui, sem depender de
     * ter o `psp_charge_id` gravado.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @param  int    $idUser
     * @return array baixadas, conferidas, falhas, mensagens
     */
    public function conciliarPeriodo($idCompany, $psp, $idUser)
    {
        $saida = ['baixadas' => 0, 'conferidas' => 0, 'falhas' => 0, 'mensagens' => []];

        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            $saida['falhas']++;
            $saida['mensagens'][] = $config['message'];
            return $saida;
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            $saida['falhas']++;
            $saida['mensagens'][] = 'Provedor de cobrança desconhecido.';
            return $saida;
        }

        // A janela é de VENCIMENTO, que é como o Inter filtra. Recua bastante
        // porque cobrança vencida continua pagável: um boleto de 60 dias atrás
        // pago hoje precisa ser encontrado.
        $filtros = [
            'data_inicial' => date('Y-m-d', strtotime('-' . self::DIAS_CONCILIACAO_PASSADO . ' days')),
            'data_final' => date('Y-m-d', strtotime('+' . self::DIAS_CONCILIACAO_FUTURO . ' days')),
            'por_pagina' => 100,
        ];

        for ($pagina = 1; $pagina <= self::MAX_PAGINAS_CONCILIACAO; $pagina++) {
            $filtros['pagina'] = $pagina;

            $sessao = sessao_suspender();
            try {
                $lista = $provider->listarCobrancas($config['config'], $filtros);
            } finally {
                sessao_retomar($sessao);
            }

            if (empty($lista['success'])) {
                $saida['falhas']++;
                $saida['mensagens'][] = 'página ' . $pagina . ': ' . $lista['message'];
                $this->logarErro($idCompany, $psp, 'conciliarPeriodo pagina=' . $pagina, (string) $lista['message']);
                return $saida;
            }

            $itens = isset($lista['data']['itens']) && is_array($lista['data']['itens']) ? $lista['data']['itens'] : [];

            foreach ($itens as $item) {
                $saida['conferidas']++;

                $fatura = $this->faturaDoItem($item, $idCompany, $psp);
                if (empty($fatura)) continue;

                // Só quem está em aberto e sem baixa interessa: reaplicar num
                // registro já quitado reescreveria a data do pagamento.
                if ((string) $fatura->status !== 'aberta' || !empty($fatura->paid_at)) continue;

                $r = $this->aplicarBaixa($fatura, $item, $idUser);

                if (!empty($r['data']['baixou'])) {
                    $saida['baixadas']++;
                } elseif (empty($r['success'])) {
                    $saida['falhas']++;
                    $saida['mensagens'][] = 'fatura #' . (int) $fatura->id . ': ' . $r['message'];
                }
            }

            // `ultimaPagina` vem do próprio envelope do provedor: é critério de
            // parada mais confiável que contar páginas do nosso lado.
            if (!empty($lista['data']['ultima_pagina']) || empty($itens)) break;

            usleep(self::ESPACAMENTO_COBRANCAS_MICROSSEGUNDOS);
        }

        return $saida;
    }

    /**
     * Acha a fatura de um item devolvido pelo provedor.
     *
     * Tenta primeiro pelo `charge_id` (o vínculo forte) e depois pela
     * `referencia`, que é o `seuNumero` — o id da nossa fatura. A segunda via
     * existe para a cobrança órfã: emissão que deu certo no banco mas cujo id
     * não chegou a ser gravado aqui.
     *
     * @param  array  $item
     * @param  int    $idCompany
     * @param  string $psp
     * @return object|null
     */
    private function faturaDoItem(array $item, $idCompany, $psp)
    {
        $chargeId = trim((string) ($item['charge_id'] ?? ''));

        if ($chargeId !== '') {
            $fatura = $this->global_model->getWhere_off('crm_invoices', [
                'psp_charge_id' => $chargeId,
                'id_company' => (int) $idCompany,
            ], TRUE);

            if (!empty($fatura)) return $fatura;
        }

        $referencia = trim((string) ($item['referencia'] ?? ''));
        if ($referencia === '' || !ctype_digit($referencia)) return NULL;

        $fatura = $this->global_model->getWhere_off('crm_invoices', [
            'id' => (int) $referencia,
            'id_company' => (int) $idCompany,
        ], TRUE);

        // O `seuNumero` é texto livre do lado do banco: conferir que a fatura
        // encontrada é mesmo deste provedor evita casar com um id coincidente.
        if (empty($fatura) || (string) $fatura->psp !== (string) $psp) return NULL;

        return $fatura;
    }

    /**
     * Aplica (ou não) a baixa, a partir do retrato normalizado do provedor.
     *
     * Ponto ÚNICO da baixa: o webhook e a conciliação chegam aqui pelos dois
     * caminhos. Regra duplicada em duas vias vira duas regras, e bastaria uma
     * esquecer o `paid_at` para o sistema passar a cobrar quem já pagou,
     * conforme o caminho que descobriu o pagamento.
     *
     * @param  object $fatura
     * @param  array  $dados retrato normalizado (situacao, paid_at, ...)
     * @param  int    $idUser
     * @return array
     */
    private function aplicarBaixa($fatura, array $dados, $idUser)
    {
        $situacao = (string) ($dados['situacao'] ?? '');
        $campos = [];

        if (isset($dados['psp_status']) && $dados['psp_status'] !== '') {
            $campos['psp_status'] = mb_substr((string) $dados['psp_status'], 0, 30);
        }

        if ($situacao !== Psp_provider::SIT_PAGA) {
            // Não pagou: guarda o retrato para diagnóstico e não toca no
            // status. Cobrança CANCELADA no provedor também não cancela a
            // fatura sozinha — isso é decisão de quem opera, não do cron.
            if (!empty($campos)) {
                $campos['modified'] = date('Y-m-d H:i:s');
                $campos['modified_by'] = (int) $idUser;
                $this->global_model->edit('crm_invoices', $campos, 'id', (int) $fatura->id);
            }

            return $this->resposta(TRUE, 'Ainda não paga.', ['baixou' => FALSE, 'situacao' => $situacao]);
        }

        // O valor e a data são os DO PROVEDOR, não os nossos: o cliente pode
        // ter pago com juros, desconto ou em data diferente, e é o extrato do
        // banco que vale na conciliação.
        $campos['status'] = 'paga';
        $campos['paid_at'] = isset($dados['paid_at']) && $dados['paid_at'] !== ''
            ? (string) $dados['paid_at'] . ' 00:00:00'
            : date('Y-m-d H:i:s');

        if (isset($dados['paid_amount']) && (float) $dados['paid_amount'] > 0) {
            $campos['paid_amount'] = (float) $dados['paid_amount'];
        } else {
            $campos['paid_amount'] = (float) $fatura->value;
        }

        if (isset($dados['paid_method']) && $dados['paid_method'] !== '') {
            $campos['paid_method'] = mb_substr((string) $dados['paid_method'], 0, 20);
        }

        $campos['modified'] = date('Y-m-d H:i:s');
        $campos['modified_by'] = (int) $idUser;

        $this->global_model->edit('crm_invoices', $campos, 'id', (int) $fatura->id);

        // A emissão da NF é enfileirada AQUI: este é o momento em que o
        // pagamento passa a ser fato para o sistema, e é o gatilho do
        // `pos_compensacao`. Ponto único — o webhook e a conciliação chegam
        // aos dois pelo mesmo caminho.
        $this->load->model('bomcontrole_model');
        $this->bomcontrole_model->enfileirarNota((int) $fatura->id, 'baixa');

        // E o CONTAS A RECEBER (etapa J), pelo mesmo motivo de estar aqui: é o
        // momento em que o pagamento vira fato, e as duas vias — webhook e
        // conciliação — chegam por este ponto único.
        //
        // As duas filas convivem sem se sobrepor porque a política as separa:
        // quem emite nota já ganha título pela VENDA da etapa E (que por isso
        // termina dando baixa nele), e quem não emite não ganha nada. O
        // `enfileirarRecebimento()` só aceita `nao_emitir` — a decisão mora lá,
        // e não aqui, para o webhook e o cron não terem como divergir.
        $this->bomcontrole_model->enfileirarRecebimento((int) $fatura->id);

        return $this->resposta(TRUE, 'Baixa aplicada.', [
            'baixou' => TRUE,
            'situacao' => $situacao,
            'valor' => $campos['paid_amount'],
        ]);
    }

    /**
     * Contas de PSP ativas, para o cron varrer sem saber de tenants.
     *
     * @return array linhas de crm_psp_accounts
     */
    public function contasAtivas()
    {
        $consulta = $this->db->query(
            'SELECT `id_company`, `psp` FROM `crm_psp_accounts` WHERE `active` = 1 ORDER BY `id_company` ASC'
        );

        return ($consulta === FALSE) ? [] : $consulta->result();
    }

    // ------------------------------------------------------------------
    // Boleto (PDF)
    // ------------------------------------------------------------------

    /**
     * Devolve o PDF do boleto, buscando no provedor só quando ainda não está
     * guardado.
     *
     * O Inter não publica URL do boleto — o PDF vem de endpoint autenticado,
     * em base64. Guardar é o que evita uma chamada à API por abertura de tela,
     * contra um rate limit que estoura em ~6 seguidas; e é o que a etapa B vai
     * reusar para anexar o arquivo ao e-mail, em vez de baixar de novo.
     *
     * A busca é SOB DEMANDA, e não no registro da cobrança: a maioria dos
     * boletos nunca é aberta, e baixar todos na rodada do cron gastaria quota
     * para encher o banco.
     *
     * @param  int $idInvoice
     * @param  int $idCompany
     * @param  int $idUser
     * @return array success, message, data => ['content' (base64), 'bytes', 'do_cache']
     */
    public function obterBoleto($idInvoice, $idCompany, $idUser)
    {
        $fatura = $this->global_model->getWhere_off('crm_invoices', [
            'id' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($fatura)) {
            return $this->resposta(FALSE, 'Fatura não encontrada.');
        }

        $psp = trim((string) $fatura->psp);
        $chargeId = trim((string) $fatura->psp_charge_id);

        if ($psp === '' || $chargeId === '') {
            return $this->resposta(FALSE, 'Esta fatura ainda não tem cobrança registrada — não há boleto para abrir.');
        }

        $guardado = $this->global_model->getWhere_off('crm_invoices_boletos', [
            'id_invoice' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (!empty($guardado)) {
            // Cache ENVELHECIDO: a fatura trocou de cobrança (troca de
            // provedor, reemissão) e o PDF guardado descreve um boleto que já
            // não vale. Servi-lo entregaria ao cliente um documento cancelado.
            if ((string) $guardado->psp_charge_id === $chargeId) {
                return $this->resposta(TRUE, '', [
                    'content' => (string) $guardado->content,
                    'bytes' => (int) $guardado->bytes,
                    'do_cache' => TRUE,
                ]);
            }

            $this->global_model->delete('crm_invoices_boletos', 'id', (int) $guardado->id);
        }

        $config = $this->getConfig($idCompany, $psp);
        if (!$config['success']) {
            return $this->resposta(FALSE, $config['message']);
        }

        $provider = $this->provider($psp);
        if ($provider === NULL) {
            return $this->resposta(FALSE, 'Provedor de cobrança desconhecido.');
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $provider->obterPdf($config['config'], $chargeId);
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($resultado['success'])) {
            $this->logarErro($idCompany, $psp, 'obterPdf fatura=' . (int) $idInvoice, (string) $resultado['message']);
            return $this->resposta(FALSE, (string) $resultado['message'], ['transient' => !empty($resultado['transient'])]);
        }

        $bytes = (string) ($resultado['data']['pdf'] ?? '');
        if ($bytes === '') {
            return $this->resposta(FALSE, 'O provedor não devolveu o PDF do boleto.');
        }

        // Guardado em base64: é texto, e atravessa driver, charset e dump sem
        // tratamento especial. Binário cru neste banco (utf8 de 3 bytes) é a
        // mesma classe de problema que fez o emoji ser rejeitado em silêncio
        // no monitoramento.
        $conteudo = base64_encode($bytes);

        $gravou = $this->global_model->add('crm_invoices_boletos', [
            'id_invoice' => (int) $idInvoice,
            'id_company' => (int) $idCompany,
            'psp' => $psp,
            'psp_charge_id' => $chargeId,
            'content' => $conteudo,
            'bytes' => strlen($bytes),
            'created' => date('Y-m-d H:i:s'),
            'created_by' => (int) $idUser,
        ]);

        // Falha ao gravar NÃO impede a entrega: o usuário pediu o boleto e ele
        // está na mão. O que se perde é o cache — a próxima abertura busca de
        // novo, que é o comportamento de antes de existir a tabela.
        if (empty($gravou)) {
            $this->logarErro($idCompany, $psp, 'guardarBoleto fatura=' . (int) $idInvoice,
                'PDF obtido mas nao foi possivel guardar; sera rebuscado na proxima abertura');
        }

        return $this->resposta(TRUE, '', [
            'content' => $conteudo,
            'bytes' => strlen($bytes),
            'do_cache' => FALSE,
        ]);
    }

    /**
     * Apaga o PDF guardado de uma fatura.
     *
     * Chamado quando a cobrança deixa de valer (cancelamento, troca de
     * provedor): o arquivo descreve um boleto que não existe mais, e mantê-lo
     * permitiria abrir e enviar ao cliente um documento cancelado.
     *
     * @param  int $idInvoice
     * @return void
     */
    public function descartarBoleto($idInvoice)
    {
        $this->global_model->delete('crm_invoices_boletos', 'id_invoice', (int) $idInvoice);
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    /**
     * Token opaco que identifica a conta na URL pública do webhook.
     *
     * NÃO reusa `crm_companies.token`: aquele é semipúblico (vai no link de
     * cadastro de cliente), e o do webhook precisa ser segredo — com webhook
     * possivelmente não assinado, o caminho da URL é parte da proteção.
     *
     * @return string
     */
    public function gerarWebhookToken()
    {
        try {
            return bin2hex(random_bytes(24));
        } catch (Exception $e) {
            // random_bytes só falha sem fonte de entropia; sem token não há
            // webhook, então é melhor falhar alto do que gerar algo adivinhável.
            return bin2hex(openssl_random_pseudo_bytes(24));
        }
    }

    /**
     * Dias até o certificado vencer, ou NULL quando não há data.
     *
     * @param  object|null $conta
     * @return int|null negativo = já venceu
     */
    public function diasParaVencerCertificado($conta)
    {
        if (empty($conta) || empty($conta->cert_expires_at)) {
            return NULL;
        }

        $vencimento = strtotime((string) $conta->cert_expires_at . ' 23:59:59');
        if ($vencimento === FALSE) return NULL;

        return (int) floor(($vencimento - time()) / 86400);
    }

    /**
     * Raiz dos certificados, normalizada.
     *
     * O `realpath` existe porque a APPPATH do CI3 vem como
     * `system/../application/` quando o processo roda pela CLI — gravar isso
     * no banco funcionaria hoje e confundiria qualquer diagnóstico depois.
     *
     * @return string sem barra final
     */
    private function raizCertificados()
    {
        $base = APPPATH . self::DIR_CERTIFICADOS;
        $real = realpath($base);

        return $real !== FALSE ? $real : rtrim($base, '/');
    }

    /**
     * Absoluto -> relativo à raiz dos certificados.
     *
     * @param  string $absoluto
     * @return string
     */
    private function caminhoRelativo($absoluto)
    {
        $raiz = $this->raizCertificados() . '/';

        return (strpos($absoluto, $raiz) === 0)
            ? substr($absoluto, strlen($raiz))
            : $absoluto;
    }

    /**
     * Relativo -> absoluto. Caminho já absoluto passa intacto, para o que foi
     * gravado antes desta regra continuar funcionando.
     *
     * @param  string $relativo
     * @return string
     */
    private function caminhoAbsoluto($relativo)
    {
        $relativo = (string) $relativo;
        if ($relativo === '') return '';

        if (strpos($relativo, '/') === 0) return $relativo;

        return $this->raizCertificados() . '/' . $relativo;
    }

    /**
     * Cria (se preciso) e devolve a pasta dos certificados daquele tenant/PSP.
     *
     * Fica sob application/, que o .htaccess do CI já nega por inteiro — mesma
     * proteção de application/logs e application/config, que guardam
     * credencial de banco. O .htaccess próprio é defesa em profundidade, para
     * o caso de a pasta ser copiada para outro lugar.
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @return string|bool caminho sem barra final, ou FALSE
     */
    private function diretorioCertificados($idCompany, $psp)
    {
        $base = APPPATH . self::DIR_CERTIFICADOS;
        if (!is_dir($base) && !@mkdir($base, 0700, TRUE)) {
            return FALSE;
        }
        $base = $this->raizCertificados();

        $caminho = $base . '/' . preg_replace('/[^a-z0-9_]/', '', (string) $psp) . '/' . (int) $idCompany;

        if (!is_dir($caminho) && !@mkdir($caminho, 0700, TRUE)) {
            return FALSE;
        }

        $htaccess = $base . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n"
            );
        }

        return $caminho;
    }

    /**
     * Toda falha de integração externa vai para o log com prefixo do módulo e
     * o contexto que permite reproduzir o caso (regra do CLAUDE.md).
     *
     * @param  int    $idCompany
     * @param  string $psp
     * @param  string $operacao
     * @param  string $mensagem
     * @return void
     */
    public function logarErro($idCompany, $psp, $operacao, $mensagem)
    {
        log_message('error', sprintf(
            '[PSP] empresa=%d psp=%s operacao=%s — %s',
            (int) $idCompany,
            (string) $psp,
            (string) $operacao,
            (string) $mensagem
        ));
    }
}
