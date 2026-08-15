<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Base comum das consultas de registro de domínio.
 *
 * Duas origens hoje, com regras de negócio idênticas depois da resposta:
 * Ninjas_whois (domínios internacionais, API paga com quota) e Rdap_registrobr
 * (domínios .br, RDAP público do Registro.br). O que muda é o endpoint e o
 * formato do JSON; o que não muda — normalizar data, normalizar nameserver,
 * fazer o GET e traduzir erro de rede — mora aqui.
 *
 * Contrato que as filhas cumprem:
 *
 *   lookup($domain, $apiKey, $timeout) => [
 *     'success'   => bool,
 *     'message'   => string,
 *     'data'      => array|null,   // normalizado (as chaves que o banco guarda)
 *     'raw'       => array,        // resposta crua, para a tela mostrar o resto
 *     'http_code' => int,
 *     'abort'     => bool,         // problema global: interrompe a rodada
 *     'premium'   => bool,         // o plano não cobre este domínio
 *     'transient' => bool,         // falha de infra: não conta como tentativa
 *     'livre'     => bool,         // a origem respondeu que o domínio NÃO existe
 *   ]
 *
 * `livre` é a única flag que não descreve um problema da consulta: a origem
 * respondeu, e a resposta foi "não conheço este domínio". Vira o estado
 * `whois_status = 'livre'` (migration 016), que o Dashboard conta como domínio
 * disponível. Só marque a flag onde a origem for EXPLÍCITA — resposta vazia é
 * ambígua (pode ser TLD sem WHOIS público) e continua sendo `sem_dados`.
 *
 * Em `data`, chave AUSENTE significa "a origem não informou" — o model não
 * grava essas colunas, para não apagar dado bom com NULL.
 */
abstract class Whois_provider
{
    /** Faixa aceitável de ano — descarta data corrompida vinda do registrador. */
    const ANO_MINIMO = 1990;
    const ANO_MAXIMO = 2100;

    const TIMEOUT_PADRAO = 20;

    /**
     * @param  string $domain domínio registrável
     * @param  string $apiKey ignorado pelas origens públicas
     * @param  int    $timeout
     * @return array
     */
    abstract public function lookup($domain, $apiKey = '', $timeout = self::TIMEOUT_PADRAO);

    /** Nome da origem, usado nas mensagens de erro. */
    abstract protected function servico();

    /**
     * Converte em 'Y-m-d' o que a origem mandou como data.
     *
     * Aceita timestamp Unix (int, float ou string numérica), lista de valores —
     * alguns TLDs devolvem lista quando o registro bruto repete a linha, e o
     * primeiro item válido serve — e string de data (ISO 8601 do RDAP, por
     * exemplo).
     *
     * @param  mixed $valor
     * @return string|null 'Y-m-d' ou NULL quando não dá para aproveitar
     */
    public function normalizarData($valor)
    {
        if (is_array($valor)) {
            foreach ($valor as $item) {
                $convertido = $this->normalizarData($item);
                if ($convertido !== NULL) {
                    return $convertido;
                }
            }
            return NULL;
        }

        if ($valor === NULL || $valor === '' || is_bool($valor)) {
            return NULL;
        }

        if (is_int($valor) || is_float($valor) || (is_string($valor) && ctype_digit(trim($valor)))) {
            $timestamp = (int) $valor;
        } elseif (is_string($valor)) {
            $timestamp = strtotime($valor);
            if ($timestamp === FALSE) {
                return NULL;
            }
        } else {
            return NULL;
        }

        if ($timestamp <= 0) {
            return NULL;
        }

        $ano = (int) date('Y', $timestamp);
        if ($ano < self::ANO_MINIMO || $ano > self::ANO_MAXIMO) {
            return NULL;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Lista de nameservers minúscula, sem duplicatas e ORDENADA.
     *
     * A ordenação é o que torna a detecção de troca confiável: vários
     * registradores devolvem o mesmo conjunto em ordem diferente a cada
     * consulta, e sem ordenar isso viraria "NS trocado" toda semana. O preço é
     * que "NS1" passa a ser o primeiro em ordem alfabética, não necessariamente
     * o literal NS1 do registrador.
     *
     * @param  mixed $valor array ou string
     * @return array lista de hostnames
     */
    public function normalizarNameServers($valor)
    {
        if (is_string($valor)) {
            $valor = preg_split('/[\s,;]+/', $valor);
        }

        if (!is_array($valor)) {
            return [];
        }

        $servidores = [];
        foreach ($valor as $item) {
            if (!is_string($item)) continue;

            $host = rtrim(mb_strtolower(trim($item)), '.');
            if ($host === '') continue;

            $servidores[] = mb_substr($host, 0, 255);
        }

        $servidores = array_values(array_unique($servidores));
        sort($servidores);

        return $servidores;
    }

    /**
     * GET simples com os mesmos opts de cURL usados nas integrações de servidor.
     *
     * @param  string $url
     * @param  array  $headers
     * @param  int    $timeout
     * @return array  corpo, status, erro_numero, erro_texto
     */
    protected function requisitar($url, array $headers, $timeout)
    {
        $timeout = (int) $timeout;
        if ($timeout <= 0) $timeout = self::TIMEOUT_PADRAO;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPGET => TRUE,
            CURLOPT_HTTPHEADER => $headers,
            // Diferente dos painéis de hospedagem, aqui são APIs públicas com
            // certificado válido: verificação de SSL sempre ligada.
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 15),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => FALSE,
        ]);

        $corpo = curl_exec($ch);
        $retorno = [
            'corpo' => $corpo,
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'erro_numero' => curl_errno($ch),
            'erro_texto' => curl_error($ch),
        ];
        curl_close($ch);

        return $retorno;
    }

    /**
     * Traduz o código do cURL para uma causa acionável.
     *
     * @param  int    $numero
     * @param  string $texto
     * @return string
     */
    protected function mensagemErroCurl($numero, $texto)
    {
        $servico = $this->servico();

        switch ($numero) {
            case CURLE_OPERATION_TIMEOUTED:
                return 'Tempo limite excedido ao consultar ' . $servico . '.';
            case CURLE_COULDNT_CONNECT:
                return 'Não foi possível conectar em ' . $servico . '.';
            case CURLE_COULDNT_RESOLVE_HOST:
                return 'Host de ' . $servico . ' não encontrado (verifique a saída de internet do servidor).';
            case CURLE_SSL_CACERT:
            case CURLE_SSL_PEER_CERTIFICATE:
            case CURLE_SSL_CONNECT_ERROR:
                return 'Erro de SSL ao consultar ' . $servico . ' (verifique os certificados raiz do servidor).';
            default:
                return 'Falha ao consultar ' . $servico . ': ' . $texto;
        }
    }

    /**
     * Mensagem de erro que a origem devolve no corpo, quando devolve.
     *
     * @param  string $corpo
     * @return string
     */
    protected function extrairDetalhe($corpo)
    {
        $dados = json_decode((string) $corpo, TRUE);
        if (!is_array($dados)) {
            return '';
        }

        // O RDAP (RFC 9083) usa title/description; as APIs REST usam error/message.
        foreach (['error', 'message', 'detail', 'title'] as $chave) {
            if (!empty($dados[$chave]) && is_string($dados[$chave])) {
                return mb_substr(trim($dados[$chave]), 0, 200);
            }
        }

        if (!empty($dados['description']) && is_array($dados['description'])) {
            $texto = trim(implode(' ', array_filter($dados['description'], 'is_string')));
            if ($texto !== '') {
                return mb_substr($texto, 0, 200);
            }
        }

        return '';
    }

    /**
     * @param  string $mensagem
     * @param  int    $status
     * @param  array  $flags abort, premium, transient, livre (ausente = FALSE)
     * @return array
     */
    protected function erro($mensagem, $status = 0, array $flags = [])
    {
        return [
            'success' => FALSE,
            'message' => $mensagem,
            'data' => NULL,
            'raw' => [],
            'http_code' => (int) $status,
            'abort' => !empty($flags['abort']),
            'premium' => !empty($flags['premium']),
            'transient' => !empty($flags['transient']),
            'livre' => !empty($flags['livre']),
        ];
    }

    /**
     * @param  array $data normalizado
     * @param  array $raw  resposta crua
     * @param  int   $status
     * @return array
     */
    protected function sucesso(array $data, array $raw, $status)
    {
        return [
            'success' => TRUE,
            'message' => '',
            'data' => $data,
            'raw' => $raw,
            'http_code' => (int) $status,
            'abort' => FALSE,
            'premium' => FALSE,
            'transient' => FALSE,
            'livre' => FALSE,
        ];
    }
}
