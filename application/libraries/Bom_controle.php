<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cliente da API de integração do ERP Bom Controle.
 *
 * Referência: docs/bomcontrole.postman_collection.json (collection oficial;
 * a doc web https://documenter.getpostman.com/view/1797561/SWT7BKWo é
 * renderizada em JS e não serve para consulta programática).
 *
 * Regras do serviço que moldam esta classe:
 *  - Auth por um único header `Authorization: ApiKey <chave>`. O prefixo é
 *    acrescentado só se a chave gravada já não vier com ele.
 *  - A leitura é toda GET com parâmetros na query string, inclusive a
 *    paginação, cujos nomes têm ponto (`paginacao.itensPorPagina`) — por isso o
 *    http_build_query com RFC 3986: o ponto passa intacto e o espaço dos
 *    parâmetros de data vira %20 (o padrão viraria `+`, que a API não aceita).
 *  - A escrita é POST/PUT com corpo JSON e `Content-Type: application/json`.
 *  - Máximo de 100 itens por página (documentado); acima disso a API ignora.
 *  - `VendaContrato/Pesquisar` responde ora envelope {Itens, TotalItens}
 *    (observado em produção), ora array puro (como documentado) —
 *    normalizarLista() aceita os dois.
 *  - Rate limit real: HTTP 429 é retentado com backoff, e SÓ ele — outros
 *    status são resposta definitiva. Falha de rede retenta APENAS em GET (ver
 *    requisitar()).
 *  - As respostas de escrita não são objetos JSON: `Cliente/Criar` devolve 200
 *    com o Id como inteiro puro no corpo, e os `Alterar*` devolvem 204 sem
 *    corpo. É por isso que `data` pode ser array, escalar ou NULL.
 *
 * Como as demais libraries de integração (Server_whm, Whois_provider), nunca
 * lança exceção: todo método público devolve
 * ['success' => bool, 'message' => string, 'data' => mixed, 'http_code' => int].
 * `data` é o JSON decodificado com as chaves PascalCase da API — quem traduz
 * para o vocabulário do sistema é o Bomcontrole_model.
 */
class Bom_controle
{
    const BASE_URL_PADRAO = 'https://apinewintegracao.bomcontrole.com.br';
    const TIMEOUT_PADRAO = 30;
    const MAX_TENTATIVAS = 4;
    const ITENS_POR_PAGINA_MAX = 100;

    /**
     * Valida a chave: uma pesquisa de 1 item, sem termo (o `pesquisa` é
     * opcional na API). 401/403 é a única resposta que incrimina a chave.
     *
     * @param  array $config ['base_url', 'api_key', 'timeout'?]
     * @return array success, message, response_ms
     */
    public function test(array $config)
    {
        $inicio = microtime(TRUE);
        $resultado = $this->requisitar($config, '/integracao/VendaContrato/Pesquisar', [
            'paginacao.itensPorPagina' => 1,
            'paginacao.numeroDaPagina' => 1,
        ]);
        $ms = (int) round((microtime(TRUE) - $inicio) * 1000);

        if ($resultado['success']) {
            return [
                'success' => TRUE,
                'message' => 'Conexão com o Bom Controle OK (' . $ms . ' ms).',
                'response_ms' => $ms,
            ];
        }

        return [
            'success' => FALSE,
            'message' => $resultado['message'],
            'response_ms' => $ms,
        ];
    }

    /**
     * GET /integracao/VendaContrato/Pesquisar
     *
     * `$documento` é o que a API chama de `pesquisa`: "documento ou nome do
     * cliente". A busca é AMPLA (fuzzy) — quem garante que o contrato é mesmo
     * do documento pedido é o refiltro exato no model, nunca esta resposta.
     *
     * @param  array       $config
     * @param  string|null $documento NULL/vazio = todos os contratos da conta
     * @param  int         $itensPorPagina
     * @param  int         $pagina 1-based
     * @return array data = ['Itens' => [...], 'TotalItens' => int]
     */
    public function pesquisarContratos(array $config, $documento = NULL, $itensPorPagina = 50, $pagina = 1)
    {
        $params = [
            'paginacao.itensPorPagina' => $this->limitarItensPorPagina($itensPorPagina),
            'paginacao.numeroDaPagina' => max(1, (int) $pagina),
        ];
        if ($documento !== NULL && $documento !== '') {
            $params['pesquisa'] = (string) $documento;
        }

        $resultado = $this->requisitar($config, '/integracao/VendaContrato/Pesquisar', $params);
        if (!$resultado['success']) {
            return $resultado;
        }

        $resultado['data'] = $this->normalizarLista($resultado['data']);
        return $resultado;
    }

    /**
     * GET /integracao/VendaContrato/Obter/{id}
     *
     * Os filtros valem só para o array `Faturas` da resposta (o resto do
     * contrato vem sempre inteiro):
     *  - 'quitadas'       bool  — TRUE só quitadas, FALSE só em aberto;
     *  - 'inicioPeriodo'  'Y-m-d H:i:s' — vencimento a partir de;
     *  - 'terminoPeriodo' 'Y-m-d H:i:s' — vencimento até.
     *
     * É esse trio que permite montar o extrato inteiro por CONTRATO — o
     * Financeiro/Pesquisar só filtra por cliente.
     *
     * @param  array $config
     * @param  int   $idContratoBc
     * @param  array $filtros
     * @return array data = o objeto do contrato
     */
    public function obterContrato(array $config, $idContratoBc, array $filtros = [])
    {
        $params = [];
        if (array_key_exists('quitadas', $filtros)) {
            $params['quitadas'] = $filtros['quitadas'] ? 'true' : 'false';
        }
        foreach (['inicioPeriodo', 'terminoPeriodo'] as $chave) {
            if (!empty($filtros[$chave])) {
                $params[$chave] = (string) $filtros[$chave];
            }
        }

        return $this->requisitar(
            $config,
            '/integracao/VendaContrato/Obter/' . (int) $idContratoBc,
            $params
        );
    }

    /**
     * GET /integracao/Financeiro/Pesquisar — movimentações financeiras.
     *
     * FALLBACK do extrato: só entra em cena se o filtro `quitadas` do Obter
     * não se comportar como a doc promete (ver Bomcontrole_model). Filtra por
     * CLIENTE (não há filtro por venda/contrato), então mistura as parcelas de
     * todos os contratos do cliente.
     *
     * `tipoData=DataPadrao`, e não DataVencimento: DataVencimento não está
     * entre as opções documentadas (DataPadrao | DataPrevista | DataPagamento |
     * DataCompetencia | DataConciliacao | Criacao | UltimaAlteracao) — o
     * DataPadrao é o vencimento. Datas são OBRIGATÓRIAS na API.
     *
     * @param  array  $config
     * @param  int    $idClienteBc  Cliente.Id interno do Bom Controle
     * @param  string $dataInicio   'Y-m-d H:i:s'
     * @param  string $dataTermino  'Y-m-d H:i:s'
     * @param  int    $itensPorPagina
     * @param  int    $pagina 1-based
     * @return array data = ['Itens' => [...], 'TotalItens' => int]
     */
    public function pesquisarFinanceiro(array $config, $idClienteBc, $dataInicio, $dataTermino, $itensPorPagina = 100, $pagina = 1)
    {
        $resultado = $this->requisitar($config, '/integracao/Financeiro/Pesquisar', [
            'idsCliente' => (int) $idClienteBc,
            'dataInicio' => (string) $dataInicio,
            'dataTermino' => (string) $dataTermino,
            'tipoData' => 'DataPadrao',
            'paginacao.itensPorPagina' => $this->limitarItensPorPagina($itensPorPagina),
            'paginacao.numeroDaPagina' => max(1, (int) $pagina),
        ]);
        if (!$resultado['success']) {
            return $resultado;
        }

        $resultado['data'] = $this->normalizarLista($resultado['data']);
        return $resultado;
    }

    // ------------------------------------------------------------------
    // Cadastro de cliente
    //
    // A API separa em três endpoints o que aqui é um formulário só:
    // identificação (Alterar), endereço (AlterarEndereco) e contatos
    // (AlterarContatos). Só o Criar aceita os três de uma vez.
    //
    // AlterarContatos NÃO tem método aqui de propósito: ele ADICIONA contatos e
    // não existe endpoint para substituir nem remover. Uma sincronização que o
    // chamasse acumularia contatos duplicados no ERP a cada execução, sem
    // caminho de correção pela API. Os contatos vão uma vez só, no Criar.
    // ------------------------------------------------------------------

    /**
     * GET /integracao/Cliente/Pesquisar
     *
     * `pesquisa` é "parte do nome ou documento" — busca AMPLA, como a de
     * contratos: quem garante que o cliente é o do documento pedido é o
     * refiltro exato no model. Este endpoint NÃO aceita paginação (diferente do
     * VendaContrato/Pesquisar) e responde array puro.
     *
     * @param  array  $config
     * @param  string $pesquisa documento ou nome
     * @return array data = lista de clientes
     */
    public function pesquisarClientes(array $config, $pesquisa)
    {
        $resultado = $this->requisitar($config, '/integracao/Cliente/Pesquisar', [
            'pesquisa' => (string) $pesquisa,
        ]);
        if (!$resultado['success']) {
            return $resultado;
        }

        // Array puro é o documentado, mas normalizarLista() protege contra a
        // mesma surpresa de envelope que o VendaContrato/Pesquisar deu.
        $lista = $this->normalizarLista($resultado['data']);
        $resultado['data'] = $lista['Itens'];
        return $resultado;
    }

    /**
     * GET /integracao/Cliente/Obter/{id}
     *
     * @param  array $config
     * @param  int   $idClienteBc
     * @return array data = o objeto do cliente
     */
    public function obterCliente(array $config, $idClienteBc)
    {
        return $this->requisitar(
            $config,
            '/integracao/Cliente/Obter/' . (int) $idClienteBc
        );
    }

    /**
     * POST /integracao/Cliente/Criar
     *
     * Responde 200 com o Id do cliente criado como INTEIRO PURO no corpo (não
     * é objeto JSON) — daí `data` vir escalar aqui.
     *
     * @param  array $config
     * @param  array $payload Endereco, Contatos, PessoaFisica|PessoaJuridica
     * @return array data = int (Id no Bom Controle)
     */
    public function criarCliente(array $config, array $payload)
    {
        return $this->requisitar(
            $config,
            '/integracao/Cliente/Criar',
            [],
            'POST',
            $payload
        );
    }

    /**
     * PUT /integracao/Cliente/Alterar/{id}
     *
     * Só identificação: este endpoint NÃO aceita Endereco nem Contatos.
     * Responde 204 sem corpo.
     *
     * @param  array $config
     * @param  int   $idClienteBc
     * @param  array $payload PessoaFisica|PessoaJuridica
     * @return array data = NULL
     */
    public function alterarCliente(array $config, $idClienteBc, array $payload)
    {
        return $this->requisitar(
            $config,
            '/integracao/Cliente/Alterar/' . (int) $idClienteBc,
            [],
            'PUT',
            $payload
        );
    }

    /**
     * PUT /integracao/Cliente/AlterarEndereco/{id}
     *
     * O corpo é PLANO (TipoLogradouro, Logradouro, ...), sem o wrapper
     * `Endereco` que o Criar exige. Responde 204 sem corpo.
     *
     * @param  array $config
     * @param  int   $idClienteBc
     * @param  array $endereco
     * @return array data = NULL
     */
    public function alterarEnderecoCliente(array $config, $idClienteBc, array $endereco)
    {
        return $this->requisitar(
            $config,
            '/integracao/Cliente/AlterarEndereco/' . (int) $idClienteBc,
            [],
            'PUT',
            $endereco
        );
    }

    /**
     * Requisição com retry.
     *
     * O retry de FALHA DE REDE vale só para GET: um POST/PUT que estourou o
     * timeout pode ter sido aplicado no servidor mesmo assim, e repetir criaria
     * um segundo cliente no ERP. Já o HTTP 429 é retentado em qualquer verbo —
     * ele é a recusa explícita de processar, então repetir não duplica nada.
     *
     * @param  array      $config
     * @param  string     $caminho já com / inicial
     * @param  array      $params query string
     * @param  string     $verbo GET | POST | PUT
     * @param  array|null $corpoJson corpo da requisição; NULL = sem corpo
     * @return array success, message, data, http_code
     */
    private function requisitar(array $config, $caminho, array $params = [], $verbo = 'GET', array $corpoJson = NULL)
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            return $this->erro('Chave da API do Bom Controle não configurada.', 0);
        }

        $timeout = (int) ($config['timeout'] ?? self::TIMEOUT_PADRAO);
        if ($timeout <= 0) $timeout = self::TIMEOUT_PADRAO;

        $verbo = strtoupper((string) $verbo);
        $idempotente = ($verbo === 'GET');

        $url = $this->baseUrl($config) . $caminho;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization: ' . $this->headerAutorizacao($apiKey),
            'Accept: application/json',
        ];

        $opcoes = [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_CUSTOMREQUEST => $verbo,
            // API pública com certificado válido: verificação sempre ligada.
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 15),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => FALSE,
        ];

        if ($corpoJson !== NULL) {
            // Mesmos flags de json_encode do resto do projeto: acento e barra
            // passam literais (o ERP guarda o que recebe, e "São Paulo"
            // apareceria assim na tela dele).
            $opcoes[CURLOPT_POSTFIELDS] = json_encode(
                $corpoJson,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $headers[] = 'Content-Type: application/json';
        }

        $opcoes[CURLOPT_HTTPHEADER] = $headers;

        $corpo = '';
        $status = 0;

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS; $tentativa++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $opcoes);

            $corpo = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $erroNumero = curl_errno($ch);
            $erroTexto = curl_error($ch);
            curl_close($ch);

            if ($erroNumero !== 0) {
                // Só GET retenta: o timeout de uma escrita não diz se o
                // servidor aplicou ou não, e repetir duplicaria o cadastro.
                if ($idempotente && $tentativa < self::MAX_TENTATIVAS) {
                    usleep(350000 * $tentativa);
                    continue;
                }
                return $this->erro($this->mensagemErroCurl($erroNumero, $erroTexto), 0);
            }

            if ($status === 429) {
                if ($tentativa < self::MAX_TENTATIVAS) {
                    usleep(500000 * $tentativa);
                    continue;
                }
                return $this->erro('Limite de requisições do Bom Controle atingido. Tente novamente em instantes.', 429);
            }

            break;
        }

        if ($status === 401 || $status === 403) {
            return $this->erro('Chave recusada pelo Bom Controle — confira a chave cadastrada na empresa.', $status);
        }

        if ($status < 200 || $status > 299) {
            $detalhe = $this->extrairDetalhe($corpo);
            return $this->erro(
                'Bom Controle retornou HTTP ' . $status . ($detalhe !== '' ? ' — ' . $detalhe : '') . '.',
                $status
            );
        }

        // Três formatos de resposta bem-sucedida convivem nesta API, e só o
        // primeiro é objeto JSON:
        //  - leitura: array/objeto;
        //  - Cliente/Criar: 200 com o Id como INTEIRO PURO no corpo;
        //  - Alterar*: 204 sem corpo nenhum.
        // Exigir array aqui reprovaria uma escrita que deu certo.
        $bruto = trim((string) $corpo);

        if ($bruto === '' || $status === 204) {
            return [
                'success' => TRUE,
                'message' => '',
                'data' => NULL,
                'http_code' => $status,
            ];
        }

        $dados = json_decode($bruto, TRUE);
        if ($dados === NULL && json_last_error() !== JSON_ERROR_NONE) {
            return $this->erro('Resposta inesperada do Bom Controle.', $status);
        }

        return [
            'success' => TRUE,
            'message' => '',
            'data' => $dados,
            'http_code' => $status,
        ];
    }

    /**
     * @param  array $config
     * @return string sem barra final; vazio no cadastro = URL padrão
     */
    private function baseUrl(array $config)
    {
        $url = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        return $url !== '' ? $url : self::BASE_URL_PADRAO;
    }

    /**
     * @param  string $apiKey
     * @return string valor completo do header Authorization
     */
    private function headerAutorizacao($apiKey)
    {
        return stripos($apiKey, 'ApiKey ') === 0 ? $apiKey : 'ApiKey ' . $apiKey;
    }

    /**
     * Aceita o envelope {Itens, TotalItens} e o array puro documentado,
     * devolvendo sempre o envelope.
     *
     * O parâmetro não tem type hint de array porque `data` deixou de ser
     * sempre array quando a library passou a aceitar as respostas de escrita
     * (corpo vazio vira NULL). Listagem que voltasse vazia daria TypeError.
     *
     * @param  mixed $dados
     * @return array ['Itens' => array, 'TotalItens' => int]
     */
    private function normalizarLista($dados)
    {
        if (!is_array($dados)) {
            return ['Itens' => [], 'TotalItens' => 0];
        }

        if (array_key_exists('Itens', $dados)) {
            $itens = is_array($dados['Itens']) ? array_values($dados['Itens']) : [];
            $total = isset($dados['TotalItens']) ? (int) $dados['TotalItens'] : count($itens);
            return ['Itens' => $itens, 'TotalItens' => $total];
        }

        $itens = array_values(array_filter($dados, 'is_array'));
        return ['Itens' => $itens, 'TotalItens' => count($itens)];
    }

    /**
     * @param  int $valor
     * @return int entre 1 e o máximo documentado (100)
     */
    private function limitarItensPorPagina($valor)
    {
        return max(1, min(self::ITENS_POR_PAGINA_MAX, (int) $valor));
    }

    /**
     * Mensagem que a API devolve no corpo do erro, quando devolve.
     *
     * @param  string $corpo
     * @return string
     */
    private function extrairDetalhe($corpo)
    {
        $dados = json_decode((string) $corpo, TRUE);
        if (is_array($dados)) {
            foreach (['Message', 'message', 'error', 'detail', 'title'] as $chave) {
                if (!empty($dados[$chave]) && is_string($dados[$chave])) {
                    return mb_substr(trim($dados[$chave]), 0, 200);
                }
            }
            return '';
        }

        $texto = trim(strip_tags((string) $corpo));
        return $texto !== '' ? mb_substr($texto, 0, 200) : '';
    }

    /**
     * @param  int    $numero
     * @param  string $texto
     * @return string
     */
    private function mensagemErroCurl($numero, $texto)
    {
        switch ($numero) {
            case CURLE_OPERATION_TIMEOUTED:
                return 'Tempo limite excedido ao consultar o Bom Controle.';
            case CURLE_COULDNT_CONNECT:
                return 'Não foi possível conectar no Bom Controle.';
            case CURLE_COULDNT_RESOLVE_HOST:
                return 'Host do Bom Controle não encontrado (verifique a base URL cadastrada e a saída de internet do servidor).';
            case CURLE_SSL_CACERT:
            case CURLE_SSL_PEER_CERTIFICATE:
            case CURLE_SSL_CONNECT_ERROR:
                return 'Erro de SSL ao consultar o Bom Controle (verifique os certificados raiz do servidor).';
            default:
                return 'Falha ao consultar o Bom Controle: ' . $texto;
        }
    }

    /**
     * @param  string $mensagem
     * @param  int    $status
     * @return array
     */
    private function erro($mensagem, $status)
    {
        return [
            'success' => FALSE,
            'message' => $mensagem,
            'data' => NULL,
            'http_code' => (int) $status,
        ];
    }
}
