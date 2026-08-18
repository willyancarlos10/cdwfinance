<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Psp_provider.php';

/**
 * Cliente da API de Cobrança (Boleto com PIX) do Banco Inter.
 *
 * Referência: https://developers.inter.co/references/cobranca-bolepix — o
 * portal é renderizado em JS e a referência completa exige login no internet
 * banking, então NÃO dá para consultar programaticamente (mesmo problema da
 * doc do Bom Controle).
 *
 * ⚠️ ORIGEM DOS DETALHES, E O QUE ISSO EXIGE
 *
 * O que veio do portal público: existe a operação `emitirCobrancaAsync`, a
 * autenticação é OAuth 2.0 sobre mTLS, e os certificados saem do internet
 * banking com escopos escolhidos na criação da integração.
 *
 * O RESTO — caminhos, nomes de campo, valores de `situacao` — veio de SDKs da
 * comunidade e de bases de conhecimento de terceiros, e NÃO foi exercitado
 * contra conta real. É por isso que o mapeamento de payload e a leitura da
 * resposta estão concentrados em métodos próprios e curtos
 * (`montarPayloadCobranca`, `normalizarCobranca`, `situacaoNormalizada`):
 * quando o sandbox contrariar a doc, o conserto é local.
 *
 * Ver docs/PSP-BANCO-INTER-VIABILIDADE.md, seção "O que testar no sandbox".
 *
 * REGRAS DO SERVIÇO QUE MOLDAM ESTA CLASSE
 *
 *  - mTLS obrigatório: certificado e chave são passados por CAMINHO DE ARQUIVO
 *    porque o `CURLOPT_SSLCERT_BLOB` não existe no PHP 7.4 (foi exposto no
 *    8.1). Não há como manter o PEM só em memória.
 *  - OAuth `client_credentials` com token de 1 hora. SEM CACHE, uma rodada de
 *    400 faturas faria 400 chamadas de token além das 400 de cobrança — e o
 *    rate limit é o primeiro a reclamar. O cache é por conta, em arquivo.
 *  - A emissão é ASSÍNCRONA: o POST devolve `codigoSolicitacao`, não a linha
 *    digitável. Quem preenche boleto e PIX é a consulta, depois.
 *  - Não há URL pública do boleto: o PDF vem por endpoint autenticado
 *    (`obterPdf`), e por isso `link_boleto` fica AUSENTE no retorno — a chave
 *    ausente significa "o PSP não informou", e o model não grava a coluna.
 *
 * Como as demais libraries de integração, NUNCA lança exceção.
 */
class Psp_inter extends Psp_provider
{
    const HOST_PRODUCAO = 'https://cdpj.partners.bancointer.com.br';
    const HOST_SANDBOX = 'https://cdpj-sandbox.partners.uatinter.co';

    const CAMINHO_TOKEN = '/oauth/v2/token';
    const CAMINHO_COBRANCA = '/cobranca/v3/cobrancas';
    // CONFERIDO no sandbox: o webhook pendura em /cobrancas/webhook. O
    // /cobranca/v3/webhook responde 404 de gateway (texto puro "404 page not
    // found"), enquanto este devolve 404 em JSON dizendo "Webhook não existe"
    // — a diferença entre caminho inexistente e recurso ainda não criado.
    const CAMINHO_WEBHOOK = '/cobranca/v3/cobrancas/webhook';

    /** Escopos mínimos para emitir, consultar e manter o webhook. */
    const ESCOPOS = 'boleto-cobranca.read boleto-cobranca.write webhook.read webhook.write';

    /**
     * Margem de segurança na validade do token: renova antes de expirar para
     * não perder uma chamada por corrida de relógio.
     */
    const MARGEM_TOKEN_SEGUNDOS = 120;

    /** Dias após o vencimento em que o boleto continua pagável. */
    const DIAS_AGENDA_PADRAO = 30;

    public function slug()
    {
        return 'inter';
    }

    public function nome()
    {
        return 'Banco Inter';
    }

    // ------------------------------------------------------------------
    // Operações
    // ------------------------------------------------------------------

    /**
     * Exercita a credencial SALVA: pede o token e faz uma consulta mínima.
     *
     * O token sozinho não bastaria — ele é emitido mesmo quando os escopos de
     * cobrança não foram marcados na criação da integração, e o erro só
     * apareceria na primeira emissão de verdade. A listagem de um item é a
     * chamada mais barata que exercita o escopo `boleto-cobranca.read`.
     *
     * @param  array $config
     * @return array success, message, response_ms
     */
    public function test(array $config)
    {
        $inicio = microtime(TRUE);

        $token = $this->token($config, TRUE);
        if (!$token['success']) {
            return [
                'success' => FALSE,
                'message' => $token['message'],
                'response_ms' => (int) round((microtime(TRUE) - $inicio) * 1000),
            ];
        }

        // A sonda REUSA listarCobrancas() em vez de montar a query à mão: uma
        // segunda versão dos mesmos parâmetros já nasceu divergente (sem o
        // `filtrarDataPor`) e o teste passou a exercitar uma chamada que a
        // produção nunca faria — justamente o que um teste de conexão não pode
        // fazer.
        $resultado = $this->listarCobrancas($config, [
            'data_inicial' => date('Y-m-d', strtotime('-7 days')),
            'data_final' => date('Y-m-d'),
            'por_pagina' => 10,
            'pagina' => 1,
        ]);

        $ms = (int) round((microtime(TRUE) - $inicio) * 1000);

        if ($resultado['success']) {
            return [
                'success' => TRUE,
                'message' => 'Conexão com o Banco Inter OK (' . $ms . ' ms).',
                'response_ms' => $ms,
            ];
        }

        // O token JÁ foi emitido neste ponto, então mTLS, Client ID e Client
        // Secret estão corretos — dizer só "falhou" mandaria o usuário conferir
        // de novo o que já está certo.
        return [
            'success' => FALSE,
            'message' => 'Credenciais aceitas (o token foi emitido), mas a consulta de cobranças falhou: '
                . $resultado['message']
                . ' Confira se o escopo boleto-cobranca.read foi marcado nesta integração.',
            'response_ms' => $ms,
        ];
    }

    /**
     * POST /cobranca/v3/cobrancas
     *
     * @param  array $config
     * @param  array $cobranca
     * @return array data => ['charge_id', 'situacao']
     */
    public function criarCobranca(array $config, array $cobranca)
    {
        $montagem = $this->montarPayloadCobranca($cobranca);

        if (!empty($montagem['faltando'])) {
            // A mensagem NOMEIA o que falta: com centenas de clientes, "dados
            // insuficientes" obriga a abrir o cadastro e comparar campo a
            // campo com a doc do banco.
            return $this->erro(
                'Não dá para emitir a cobrança — faltam dados no cadastro do cliente: '
                . implode(', ', $montagem['faltando']) . '.',
                0,
                FALSE
            );
        }

        $payload = $montagem['payload'];

        $resultado = $this->requisitar($config, 'POST', self::CAMINHO_COBRANCA, [], $payload);
        if (!$resultado['success']) {
            return $resultado;
        }

        $dados = is_array($resultado['data']) ? $resultado['data'] : [];
        $chargeId = trim((string) ($dados['codigoSolicitacao'] ?? ''));

        if ($chargeId === '') {
            // Sem o id não há como consultar nem conciliar depois. Tratar como
            // sucesso deixaria uma cobrança órfã viva no banco, cobrando o
            // cliente sem que o sistema soubesse que ela existe.
            return $this->erro(
                'O Banco Inter aceitou a cobrança mas não devolveu o código de solicitação.',
                $resultado['http_code'],
                FALSE
            );
        }

        // A emissão é assíncrona: aqui só existe o protocolo. Boleto e PIX
        // saem da consulta, feita depois.
        return $this->ok([
            'charge_id' => $chargeId,
            'situacao' => self::SIT_PENDENTE,
        ], $resultado['http_code']);
    }

    /**
     * GET /cobranca/v3/cobrancas/{codigoSolicitacao}
     *
     * @param  array  $config
     * @param  string $chargeId
     * @return array
     */
    public function consultarCobranca(array $config, $chargeId)
    {
        $chargeId = trim((string) $chargeId);
        if ($chargeId === '') {
            return $this->erro('Cobrança sem código de solicitação.', 0, FALSE);
        }

        $resultado = $this->requisitar(
            $config,
            'GET',
            self::CAMINHO_COBRANCA . '/' . rawurlencode($chargeId)
        );

        if (!$resultado['success']) {
            return $resultado;
        }

        return $this->ok(
            $this->normalizarCobranca(is_array($resultado['data']) ? $resultado['data'] : []),
            $resultado['http_code']
        );
    }

    /**
     * POST /cobranca/v3/cobrancas/{codigoSolicitacao}/cancelar
     *
     * @param  array  $config
     * @param  string $chargeId
     * @param  string $motivo
     * @return array
     */
    public function cancelarCobranca(array $config, $chargeId, $motivo)
    {
        $chargeId = trim((string) $chargeId);
        if ($chargeId === '') {
            return $this->erro('Cobrança sem código de solicitação.', 0, FALSE);
        }

        $motivo = $this->limitar($motivo, 100);
        if ($motivo === '') {
            $motivo = 'Cancelada no CDW Finance';
        }

        $resultado = $this->requisitar(
            $config,
            'POST',
            self::CAMINHO_COBRANCA . '/' . rawurlencode($chargeId) . '/cancelar',
            [],
            ['motivoCancelamento' => $motivo]
        );

        if (!$resultado['success']) {
            return $resultado;
        }

        // 202 Accepted: o cancelamento é ASSÍNCRONO, como a emissão. O banco
        // aceitou o pedido; a cobrança pode levar instantes para sair do ar.
        // Quem depende disso — a troca de provedor — trata o 202 como
        // suficiente, porque o alternativo (esperar em laço) prenderia a
        // requisição do usuário sem garantia de prazo.
        return $this->ok(['situacao' => self::SIT_CANCELADA], $resultado['http_code']);
    }

    /**
     * GET /cobranca/v3/cobrancas — insumo da conciliação (etapa D).
     *
     * A paginação do Inter é 0-based (`paginaAtual`), ao contrário da do Bom
     * Controle, que é 1-based. Quem chama trabalha em 1-based e a conversão
     * mora aqui, para não vazar a diferença para o model.
     *
     * @param  array $config
     * @param  array $filtros
     * @return array data => ['itens', 'total', 'total_paginas']
     */
    public function listarCobrancas(array $config, array $filtros)
    {
        $inicial = $this->dataIso($filtros['data_inicial'] ?? '');
        $final = $this->dataIso($filtros['data_final'] ?? '');

        if ($inicial === NULL || $final === NULL) {
            return $this->erro('Período inválido para listar cobranças.', 0, FALSE);
        }

        $pagina = max(1, (int) ($filtros['pagina'] ?? 1));
        $porPagina = (int) ($filtros['por_pagina'] ?? 100);
        if ($porPagina < 1 || $porPagina > 1000) $porPagina = 100;

        $params = [
            'dataInicial' => $inicial,
            'dataFinal' => $final,
            'filtrarDataPor' => (string) ($filtros['filtrar_por'] ?? 'VENCIMENTO'),
            'itensPorPagina' => $porPagina,
            'paginaAtual' => $pagina - 1,
        ];

        if (!empty($filtros['situacao'])) {
            $params['situacao'] = (string) $filtros['situacao'];
        }

        $resultado = $this->requisitar($config, 'GET', self::CAMINHO_COBRANCA, $params);
        if (!$resultado['success']) {
            return $resultado;
        }

        $dados = is_array($resultado['data']) ? $resultado['data'] : [];
        $cruas = isset($dados['cobrancas']) && is_array($dados['cobrancas']) ? $dados['cobrancas'] : [];

        $itens = [];
        foreach ($cruas as $crua) {
            if (is_array($crua)) {
                $itens[] = $this->normalizarCobranca($crua);
            }
        }

        // Envelope CONFERIDO contra o sandbox em 18/08/2026:
        // {"totalPaginas":1,"totalElementos":0,"tamanhoPagina":100,
        //  "primeiraPagina":true,"ultimaPagina":true,"numeroDeElementos":100,
        //  "cobrancas":[]}
        // A contagem é de TOPO. `ultimaPagina` é o critério de parada da
        // conciliação (etapa D) — mais confiável que comparar páginas contadas.
        return $this->ok([
            'itens' => $itens,
            'total' => (int) ($dados['totalElementos'] ?? count($itens)),
            'total_paginas' => (int) ($dados['totalPaginas'] ?? 1),
            'ultima_pagina' => !empty($dados['ultimaPagina']),
        ], $resultado['http_code']);
    }

    /**
     * GET /cobranca/v3/cobrancas/{codigoSolicitacao}/pdf
     *
     * O corpo vem com o PDF em base64 numa chave JSON, e não como binário
     * direto — decodificar aqui é o que permite ao chamador tratar o retorno
     * como arquivo sem saber disso.
     *
     * @param  array  $config
     * @param  string $chargeId
     * @return array data => ['pdf' => bytes]
     */
    public function obterPdf(array $config, $chargeId)
    {
        $chargeId = trim((string) $chargeId);
        if ($chargeId === '') {
            return $this->erro('Cobrança sem código de solicitação.', 0, FALSE);
        }

        $resultado = $this->requisitar(
            $config,
            'GET',
            self::CAMINHO_COBRANCA . '/' . rawurlencode($chargeId) . '/pdf'
        );

        if (!$resultado['success']) {
            return $resultado;
        }

        $dados = is_array($resultado['data']) ? $resultado['data'] : [];
        $base64 = (string) ($dados['pdf'] ?? '');
        $bytes = $base64 !== '' ? base64_decode($base64, TRUE) : FALSE;

        if ($bytes === FALSE || $bytes === '') {
            return $this->erro('O Banco Inter não devolveu o PDF do boleto.', $resultado['http_code'], TRUE);
        }

        return $this->ok(['pdf' => $bytes], $resultado['http_code']);
    }

    /**
     * PUT /cobranca/v3/webhook
     *
     * @param  array  $config
     * @param  string $url
     * @return array
     */
    public function registrarWebhook(array $config, $url)
    {
        $url = trim((string) $url);

        // O Inter exige HTTPS com certificado válido. Recusar aqui dá a
        // mensagem certa; deixar passar devolveria um 400 genérico do banco.
        if (stripos($url, 'https://') !== 0) {
            return $this->erro('A URL do webhook precisa ser https://.', 0, FALSE);
        }

        $resultado = $this->requisitar($config, 'PUT', self::CAMINHO_WEBHOOK, [], [
            'webhookUrl' => $url,
        ]);

        if (!$resultado['success']) {
            return $resultado;
        }

        return $this->ok(['webhook_url' => $url], $resultado['http_code']);
    }

    /**
     * Lê o corpo do webhook e devolve SÓ o que precisa ser reconsultado.
     *
     * Nem valor, nem status, nem data de pagamento saem daqui — mesmo vindo no
     * corpo. Ver a justificativa no contrato da classe base.
     *
     * @param  array  $cabecalhos
     * @param  string $corpoCru
     * @return array data => ['charge_id', 'event_type']
     */
    public function interpretarWebhook(array $cabecalhos, $corpoCru)
    {
        $dados = json_decode((string) $corpoCru, TRUE);

        if (!is_array($dados)) {
            return $this->erro('Corpo do webhook não é JSON válido.', 0, FALSE);
        }

        // O Inter entrega ora um objeto, ora uma lista de objetos. O chamador
        // trata sempre uma lista, para não ter dois caminhos.
        $eventos = isset($dados[0]) && is_array($dados[0]) ? $dados : [$dados];

        $itens = [];
        foreach ($eventos as $evento) {
            if (!is_array($evento)) continue;

            $chargeId = trim((string) ($evento['codigoSolicitacao'] ?? ''));
            if ($chargeId === '') continue;

            $itens[] = [
                'charge_id' => $chargeId,
                'event_type' => $this->limitar((string) ($evento['situacao'] ?? ''), 40),
            ];
        }

        if (empty($itens)) {
            return $this->erro('Webhook sem código de solicitação reconhecível.', 0, FALSE);
        }

        return $this->ok(['eventos' => $itens]);
    }

    // ------------------------------------------------------------------
    // Payload e leitura
    // ------------------------------------------------------------------

    /**
     * Monta o corpo do POST de emissão.
     *
     * ⚠️ Este é o método a conferir primeiro contra o sandbox: os nomes de
     * campo vêm de fonte secundária.
     *
     * @param  array $cobranca
     * @return array ['payload' => array, 'faltando' => array de rótulos]
     */
    private function montarPayloadCobranca(array $cobranca)
    {
        $pagador = isset($cobranca['pagador']) && is_array($cobranca['pagador'])
            ? $cobranca['pagador']
            : [];

        $documento = $this->documentoDigitos($pagador['documento'] ?? '');
        $tipoPessoa = $this->tipoPessoa($documento);
        $vencimento = $this->dataIso($cobranca['vencimento'] ?? '');
        $valor = (float) ($cobranca['valor'] ?? 0);
        $nome = $this->limitar($pagador['nome'] ?? '', 100);

        // Documento de tamanho inesperado não é adivinhado: emitir boleto no
        // CPF errado é problema com o cliente, não com o sistema.
        $faltando = [];
        if ($tipoPessoa === '') $faltando[] = 'CPF/CNPJ válido';
        if ($nome === '') $faltando[] = 'nome';
        if ($vencimento === NULL) $faltando[] = 'vencimento';
        if ($valor <= 0) $faltando[] = 'valor';

        // O boleto registrado exige endereço do pagador — sem ele o banco
        // recusa a emissão com um 400 genérico, e o motivo real ficaria
        // escondido. Conferir aqui transforma isso numa frase acionável.
        $obrigatoriosEndereco = [
            'endereco' => 'logradouro',
            'bairro' => 'bairro',
            'cidade' => 'cidade',
            'uf' => 'UF',
            'cep' => 'CEP',
        ];
        foreach ($obrigatoriosEndereco as $chave => $rotulo) {
            if (trim((string) ($pagador[$chave] ?? '')) === '') {
                $faltando[] = $rotulo;
            }
        }

        if (!empty($faltando)) {
            return ['payload' => [], 'faltando' => $faltando];
        }

        $payload = [
            'seuNumero' => $this->limitar($cobranca['referencia'] ?? '', 15),
            'valorNominal' => $this->valorDecimal($valor),
            'dataVencimento' => $vencimento,
            'numDiasAgenda' => self::DIAS_AGENDA_PADRAO,
            'pagador' => [
                'cpfCnpj' => $documento,
                'tipoPessoa' => $tipoPessoa,
                'nome' => $nome,
                'endereco' => $this->limitar($pagador['endereco'] ?? '', 90),
                'numero' => $this->limitar($pagador['numero'] ?? '', 10),
                'complemento' => $this->limitar($pagador['complemento'] ?? '', 30),
                'bairro' => $this->limitar($pagador['bairro'] ?? '', 60),
                'cidade' => $this->limitar($pagador['cidade'] ?? '', 60),
                'uf' => strtoupper($this->limitar($pagador['uf'] ?? '', 2)),
                'cep' => $this->documentoDigitos($pagador['cep'] ?? ''),
                'email' => $this->limitar($pagador['email'] ?? '', 60),
            ],
        ];

        $descricao = $this->limitar($cobranca['descricao'] ?? '', 78);
        if ($descricao !== '') {
            $payload['mensagem'] = ['linha1' => $descricao];
        }

        // Campo vazio é OMITIDO, nunca mandado como string vazia: o Inter
        // valida formato por campo presente, e um `uf` em branco reprova a
        // emissão inteira em vez de simplesmente não constar do boleto.
        $payload['pagador'] = array_filter(
            $payload['pagador'],
            function ($valor) { return $valor !== '' && $valor !== NULL; }
        );

        return ['payload' => $payload, 'faltando' => []];
    }

    /**
     * Traduz a resposta do Inter para o vocabulário do sistema.
     *
     * Chave AUSENTE = o PSP não informou, e o model não grava a coluna. Por
     * isso nada aqui é preenchido com '' ou 0 "para garantir".
     *
     * @param  array $bruto
     * @return array
     */
    private function normalizarCobranca(array $bruto)
    {
        // A consulta individual devolve {cobranca:{}, boleto:{}, pix:{}}; a
        // listagem devolve os mesmos blocos achatados no item. Aceitar os dois
        // evita duas funções que precisariam ser corrigidas juntas.
        $cobranca = isset($bruto['cobranca']) && is_array($bruto['cobranca']) ? $bruto['cobranca'] : $bruto;
        $boleto = isset($bruto['boleto']) && is_array($bruto['boleto']) ? $bruto['boleto'] : $bruto;
        $pix = isset($bruto['pix']) && is_array($bruto['pix']) ? $bruto['pix'] : $bruto;

        $dados = [];

        $chargeId = trim((string) ($cobranca['codigoSolicitacao'] ?? ''));
        if ($chargeId !== '') $dados['charge_id'] = $chargeId;

        $situacaoCrua = strtoupper(trim((string) ($cobranca['situacao'] ?? '')));
        if ($situacaoCrua !== '') {
            $dados['psp_status'] = $situacaoCrua;
            $dados['situacao'] = $this->situacaoNormalizada($situacaoCrua);
        }

        $referencia = trim((string) ($cobranca['seuNumero'] ?? ''));
        if ($referencia !== '') $dados['referencia'] = $referencia;

        $linha = trim((string) ($boleto['linhaDigitavel'] ?? ''));
        if ($linha !== '') $dados['linha_digitavel'] = $linha;

        $copiaCola = trim((string) ($pix['pixCopiaECola'] ?? ''));
        if ($copiaCola !== '') $dados['link_pix'] = $copiaCola;

        // `link_boleto` NÃO é preenchido de propósito: no Inter o boleto sai
        // por endpoint autenticado (obterPdf), e não há URL pública a guardar.

        if (isset($dados['situacao']) && $dados['situacao'] === self::SIT_PAGA) {
            $pagoEm = $this->dataIso($cobranca['dataSituacao'] ?? '');
            if ($pagoEm !== NULL) $dados['paid_at'] = $pagoEm;

            // O valor pago pode divergir do cobrado (juros, desconto,
            // pagamento parcial): é o do PSP que vale, não o nosso.
            if (isset($cobranca['valorTotalRecebido']) && (float) $cobranca['valorTotalRecebido'] > 0) {
                $dados['paid_amount'] = $this->valorDecimal($cobranca['valorTotalRecebido']);
            }

            $origem = strtoupper(trim((string) ($cobranca['origemRecebimento'] ?? '')));
            if ($origem !== '') {
                $dados['paid_method'] = ($origem === 'PIX') ? 'pix' : 'boleto';
            }
        }

        return $dados;
    }

    /**
     * Situação do Inter → vocabulário normalizado.
     *
     * ATRASADO vira `registrada`, e não uma situação própria: "vencida" já é
     * derivada de `due_date < hoje` na crm_invoices_v, e ter as duas verdades
     * faria a tela discordar de si mesma quando o PSP demorasse a atualizar.
     *
     * Situação desconhecida vira `pendente`, o estado que NÃO autoriza baixa —
     * um valor novo do banco não pode ser lido como pagamento.
     *
     * @param  string $situacao
     * @return string
     */
    private function situacaoNormalizada($situacao)
    {
        $mapa = [
            'RECEBIDO' => self::SIT_PAGA,
            'MARCADO_RECEBIDO' => self::SIT_PAGA,
            'A_RECEBER' => self::SIT_REGISTRADA,
            'ATRASADO' => self::SIT_REGISTRADA,
            'CANCELADO' => self::SIT_CANCELADA,
            'EXPIRADO' => self::SIT_EXPIRADA,
            'EM_PROCESSAMENTO' => self::SIT_PENDENTE,
        ];

        return isset($mapa[$situacao]) ? $mapa[$situacao] : self::SIT_PENDENTE;
    }

    // ------------------------------------------------------------------
    // Transporte
    // ------------------------------------------------------------------

    /**
     * Token OAuth, do cache quando ainda válido.
     *
     * @param  array $config
     * @param  bool  $forcar ignora o cache (usado pelo TESTAR CONEXÃO)
     * @return array success, message, token
     */
    private function token(array $config, $forcar = FALSE)
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return ['success' => FALSE, 'message' => 'Client ID ou Client Secret do Banco Inter não configurados.', 'token' => ''];
        }

        $arquivo = $this->arquivoDeCache($config);

        if (!$forcar && $arquivo !== '') {
            $token = $this->lerCache($arquivo);
            if ($token !== '') {
                return ['success' => TRUE, 'message' => '', 'token' => $token];
            }
        }

        $resultado = $this->requisitarToken($config, $clientId, $clientSecret);
        if (!$resultado['success']) {
            return $resultado;
        }

        if ($arquivo !== '') {
            $this->gravarCache($arquivo, $resultado['token'], $resultado['expira_em']);
        }

        return $resultado;
    }

    /**
     * POST /oauth/v2/token — form-urlencoded, com mTLS.
     *
     * @param  array  $config
     * @param  string $clientId
     * @param  string $clientSecret
     * @return array success, message, token, expira_em
     */
    private function requisitarToken(array $config, $clientId, $clientSecret)
    {
        $mtls = $this->opcoesMtls($config);
        if ($mtls === FALSE) {
            return [
                'success' => FALSE,
                'message' => 'Certificado ou chave do Banco Inter não encontrados no servidor. Reenvie os arquivos no cadastro da empresa.',
                'token' => '',
                'expira_em' => 0,
            ];
        }

        $corpo = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => self::ESCOPOS,
            'grant_type' => 'client_credentials',
        ]);

        $ch = curl_init($this->host($config) . self::CAMINHO_TOKEN);
        curl_setopt_array($ch, $mtls + [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout($config),
        ]);

        $resposta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroNumero = curl_errno($ch);
        $erroTexto = curl_error($ch);
        curl_close($ch);

        if ($erroNumero !== 0) {
            return [
                'success' => FALSE,
                'message' => $this->mensagemErroCurl($erroNumero, $erroTexto),
                'token' => '',
                'expira_em' => 0,
            ];
        }

        if ($status === 400 || $status === 401 || $status === 403) {
            return [
                'success' => FALSE,
                'message' => 'O Banco Inter recusou as credenciais (HTTP ' . $status . '). Confira Client ID, Client Secret e os escopos marcados na integração.',
                'token' => '',
                'expira_em' => 0,
            ];
        }

        if ($status < 200 || $status > 299) {
            return [
                'success' => FALSE,
                'message' => 'O Banco Inter retornou HTTP ' . $status . ' ao emitir o token.',
                'token' => '',
                'expira_em' => 0,
            ];
        }

        $dados = json_decode((string) $resposta, TRUE);
        $token = is_array($dados) ? trim((string) ($dados['access_token'] ?? '')) : '';

        if ($token === '') {
            return [
                'success' => FALSE,
                'message' => 'Resposta inesperada do Banco Inter ao emitir o token.',
                'token' => '',
                'expira_em' => 0,
            ];
        }

        $expira = (int) ($dados['expires_in'] ?? 3600);
        if ($expira <= 0) $expira = 3600;

        return ['success' => TRUE, 'message' => '', 'token' => $token, 'expira_em' => $expira];
    }

    /**
     * Chamada autenticada, com retry no mesmo critério do Bom_controle: 429 em
     * qualquer verbo, falha de rede SÓ em GET — o timeout de uma escrita não
     * diz se o servidor aplicou, e repetir emitiria dois boletos.
     *
     * @param  array  $config
     * @param  string $verbo
     * @param  string $caminho
     * @param  array  $params
     * @param  array  $corpoJson
     * @return array
     */
    private function requisitar(array $config, $verbo, $caminho, array $params = [], array $corpoJson = NULL)
    {
        $token = $this->token($config);
        if (!$token['success']) {
            return $this->erro($token['message'], 0, FALSE);
        }

        $mtls = $this->opcoesMtls($config);
        if ($mtls === FALSE) {
            return $this->erro('Certificado ou chave do Banco Inter não encontrados no servidor.', 0, FALSE);
        }

        $verbo = strtoupper((string) $verbo);
        $idempotente = ($verbo === 'GET');

        $url = $this->host($config) . $caminho;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        // `Accept: */*`, e NÃO `application/json`. Medido no sandbox em
        // 18/08/2026: o cancelamento (`POST .../cancelar`) responde **202 sem
        // corpo**, e exigir `application/json` fez o servidor recusar com
        // **HTTP 406** — a mensagem que volta é a genérica "verifique se os
        // dados informados estão de acordo com a documentação", que aponta
        // para o payload e não para o cabeçalho, então o diagnóstico custa
        // caro. Os endpoints que devolvem JSON continuam devolvendo JSON.
        $headers = [
            'Authorization: Bearer ' . $token['token'],
            'Accept: */*',
        ];

        // Uma mesma integração pode atender mais de uma conta corrente; sem o
        // header, o Inter escolhe a padrão — que pode não ser a do tenant.
        $conta = trim((string) ($config['conta_corrente'] ?? ''));
        if ($conta !== '') {
            $headers[] = 'x-conta-corrente: ' . $conta;
        }

        $opcoes = $mtls + [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_CUSTOMREQUEST => $verbo,
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout($config),
        ];

        if ($corpoJson !== NULL) {
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
                if ($idempotente && $tentativa < self::MAX_TENTATIVAS) {
                    usleep(350000 * $tentativa);
                    continue;
                }
                return $this->erro($this->mensagemErroCurl($erroNumero, $erroTexto), 0, TRUE);
            }

            // O 500 do Inter diz literalmente "Tente novamente mais tarde", e o
            // sandbox devolve isso com frequência — mas só entra no retry em
            // GET: repetir um POST que morreu no meio emitiria o boleto duas
            // vezes, que é o acidente que nenhum retry pode causar.
            $repetivel = ($status === 429 || $status === 503 || ($idempotente && $status === 500));

            if ($repetivel) {
                if ($tentativa < self::MAX_TENTATIVAS) {
                    usleep(500000 * $tentativa);
                    continue;
                }

                if ($status === 429) {
                    return $this->erro('Limite de requisições do Banco Inter atingido. Tente novamente em instantes.', $status, TRUE);
                }

                return $this->erro(
                    'O Banco Inter respondeu HTTP ' . $status . ' em ' . $verbo . ' ' . $caminho
                    . ' mesmo após ' . self::MAX_TENTATIVAS . ' tentativas — instabilidade do lado do banco.',
                    $status,
                    TRUE
                );
            }

            break;
        }

        if ($status === 401 || $status === 403) {
            // Credencial recusada NÃO é transitória: retentar em laço só
            // queima quota e esconde que falta escopo na integração.
            return $this->erro(
                'O Banco Inter recusou a credencial (HTTP ' . $status . '). Confira os escopos da integração e a validade do certificado.',
                $status,
                FALSE
            );
        }

        if ($status < 200 || $status > 299) {
            // O caminho entra na mensagem porque ela é o que vai para o log
            // ([PSP], pelo Psp_model): "HTTP 500" sozinho não diz qual das
            // chamadas falhou, e a resposta genérica do banco não ajuda.
            return $this->erro(
                'O Banco Inter retornou HTTP ' . $status . ' em ' . $verbo . ' ' . $caminho
                . $this->extrairDetalhe($corpo) . '.',
                $status,
                $status >= 500
            );
        }

        $bruto = trim((string) $corpo);
        if ($bruto === '' || $status === 204) {
            return $this->ok([], $status);
        }

        $dados = json_decode($bruto, TRUE);
        if ($dados === NULL && json_last_error() !== JSON_ERROR_NONE) {
            return $this->erro('Resposta inesperada do Banco Inter.', $status, FALSE);
        }

        return $this->ok(is_array($dados) ? $dados : ['valor' => $dados], $status);
    }

    /**
     * Opções de cURL do mTLS.
     *
     * Os caminhos são conferidos aqui, e não no model, porque um arquivo
     * removido do servidor produz um erro de TLS obscuro — melhor dizer que o
     * certificado sumiu.
     *
     * @param  array $config
     * @return array|bool FALSE quando algum arquivo não existe
     */
    private function opcoesMtls(array $config)
    {
        $cert = (string) ($config['cert_path'] ?? '');
        $chave = (string) ($config['key_path'] ?? '');

        if ($cert === '' || $chave === '' || !is_readable($cert) || !is_readable($chave)) {
            return FALSE;
        }

        $opcoes = [
            CURLOPT_SSLCERT => $cert,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $chave,
            CURLOPT_SSLKEYTYPE => 'PEM',
        ];

        $senha = (string) ($config['key_password'] ?? '');
        if ($senha !== '') {
            $opcoes[CURLOPT_KEYPASSWD] = $senha;
        }

        return $opcoes;
    }

    /**
     * @param  array $config
     * @return string
     */
    private function host(array $config)
    {
        return ((string) ($config['environment'] ?? 'sandbox') === 'producao')
            ? self::HOST_PRODUCAO
            : self::HOST_SANDBOX;
    }

    /**
     * @param  array $config
     * @return int
     */
    private function timeout(array $config)
    {
        $timeout = (int) ($config['timeout'] ?? self::TIMEOUT_PADRAO);
        return $timeout > 0 ? $timeout : self::TIMEOUT_PADRAO;
    }

    /**
     * Detalhe do erro devolvido pelo banco, quando houver.
     *
     * @param  string $corpo
     * @return string
     */
    private function extrairDetalhe($corpo)
    {
        $dados = json_decode((string) $corpo, TRUE);
        if (!is_array($dados)) return '';

        foreach (['detail', 'message', 'title', 'error_description'] as $chave) {
            if (!empty($dados[$chave]) && is_string($dados[$chave])) {
                return ' — ' . $this->limitar($dados[$chave], 200);
            }
        }

        // O Inter detalha erro de campo numa lista `violacoes`.
        if (!empty($dados['violacoes']) && is_array($dados['violacoes'])) {
            $partes = [];
            foreach ($dados['violacoes'] as $violacao) {
                if (!is_array($violacao)) continue;
                $partes[] = trim(($violacao['propriedade'] ?? '') . ' ' . ($violacao['razao'] ?? ''));
            }
            $partes = array_filter($partes);
            if (!empty($partes)) {
                return ' — ' . $this->limitar(implode('; ', $partes), 200);
            }
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Cache do token
    // ------------------------------------------------------------------

    /**
     * O cache é POR CONTA: duas empresas com credenciais diferentes não podem
     * compartilhar token. A chave inclui o ambiente, senão trocar sandbox por
     * produção reusaria o token do host errado.
     *
     * @param  array $config
     * @return string '' quando não há diretório utilizável
     */
    private function arquivoDeCache(array $config)
    {
        $diretorio = rtrim((string) ($config['cache_dir'] ?? ''), '/');
        if ($diretorio === '' || !is_dir($diretorio) || !is_writable($diretorio)) {
            return '';
        }

        $chave = sha1(implode('|', [
            $this->slug(),
            (string) ($config['id_company'] ?? 0),
            (string) ($config['environment'] ?? ''),
            (string) ($config['client_id'] ?? ''),
        ]));

        return $diretorio . '/psp_token_' . $chave . '.json';
    }

    /**
     * @param  string $arquivo
     * @return string '' quando não há token aproveitável
     */
    private function lerCache($arquivo)
    {
        if (!is_readable($arquivo)) return '';

        $dados = json_decode((string) @file_get_contents($arquivo), TRUE);
        if (!is_array($dados)) return '';

        $token = trim((string) ($dados['token'] ?? ''));
        $expiraEm = (int) ($dados['expira_em'] ?? 0);

        // Cache corrompido ou vencido não é erro: é só pedir outro token. Esta
        // é a diferença para o cache de migration que o projeto proíbe — lá o
        // estado velho fazia PULAR trabalho em silêncio; aqui ele só custa uma
        // chamada a mais.
        if ($token === '' || $expiraEm <= time()) return '';

        return $token;
    }

    /**
     * @param  string $arquivo
     * @param  string $token
     * @param  int    $segundos
     * @return void
     */
    private function gravarCache($arquivo, $token, $segundos)
    {
        $conteudo = json_encode([
            'token' => $token,
            'expira_em' => time() + max(60, (int) $segundos - self::MARGEM_TOKEN_SEGUNDOS),
        ]);

        // Escrita atômica: sem o rename, uma leitura concorrente pegaria o
        // arquivo pela metade e descartaria um token válido.
        $temporario = $arquivo . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temporario, $conteudo, LOCK_EX) === FALSE) {
            return;
        }

        @chmod($temporario, 0600);

        if (!@rename($temporario, $arquivo)) {
            @unlink($temporario);
        }
    }}
