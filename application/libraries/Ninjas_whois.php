<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Whois_provider.php';

/**
 * Consulta WHOIS pela API Ninjas (https://api-ninjas.com/api/whois), usada nos
 * domínios INTERNACIONAIS. Domínios .br são atendidos pela Rdap_registrobr.
 *
 * GET https://api.api-ninjas.com/v1/whois?domain=exemplo.com
 * Header: X-Api-Key: <chave>
 *
 * A normalização de data e de nameserver, o cURL e a tradução de erro de rede
 * vêm da Whois_provider — aqui fica só o que é específico desta API: o
 * endpoint, o formato do JSON e o significado de cada HTTP.
 *
 * A própria documentação avisa que "o conjunto de campos retornado varia
 * conforme o TLD e o registrador", por isso o parser é deliberadamente
 * tolerante: data pode vir como timestamp, como lista de timestamps ou como
 * string; nameserver pode vir como lista ou como string única.
 */
class Ninjas_whois extends Whois_provider
{
    const ENDPOINT = 'https://api.api-ninjas.com/v1/whois';

    protected function servico()
    {
        return 'a API Ninjas';
    }

    /**
     * @param  string $domain domínio registrável (exemplo.com)
     * @param  string $apiKey
     * @param  int    $timeout
     * @return array  ver contrato em Whois_provider
     */
    public function lookup($domain, $apiKey = '', $timeout = self::TIMEOUT_PADRAO)
    {
        $domain = mb_strtolower(trim((string) $domain));

        if ($domain === '') {
            return $this->erro('Domínio não informado.');
        }

        if ((string) $apiKey === '') {
            // abort: sem chave, os próximos domínios falhariam igual.
            return $this->erro('Chave da API Ninjas não cadastrada em Parâmetros gerais.', 0, ['abort' => TRUE, 'transient' => TRUE]);
        }

        $resposta = $this->requisitar(
            self::ENDPOINT . '?' . http_build_query(['domain' => $domain]),
            ['X-Api-Key: ' . $apiKey, 'Accept: application/json'],
            $timeout
        );

        if ($resposta['erro_numero'] !== 0) {
            // Falha de rede é sempre transitória: pode funcionar na próxima rodada.
            return $this->erro($this->mensagemErroCurl($resposta['erro_numero'], $resposta['erro_texto']), 0, ['transient' => TRUE]);
        }

        if ($resposta['status'] < 200 || $resposta['status'] >= 300) {
            $traduzido = $this->mensagemErroHttp($resposta['status'], $resposta['corpo']);
            return $this->erro($traduzido['message'], $resposta['status'], $traduzido);
        }

        $dados = json_decode((string) $resposta['corpo'], TRUE);
        if (!is_array($dados)) {
            return $this->erro('Resposta inválida da API Ninjas (não é JSON).', $resposta['status']);
        }

        return $this->sucesso($this->normalizarResposta($domain, $dados), $dados, $resposta['status']);
    }

    /**
     * Valida a chave cadastrada contra um domínio .com — que o plano gratuito
     * atende — para o teste não falhar por limitação de plano.
     *
     * @param  string $apiKey
     * @return array  success, message
     */
    public function test($apiKey)
    {
        $resultado = $this->lookup('api-ninjas.com', $apiKey);

        if (empty($resultado['success'])) {
            return ['success' => FALSE, 'message' => $resultado['message']];
        }

        $vencimento = isset($resultado['data']['expiration_date']) ? $resultado['data']['expiration_date'] : NULL;

        return [
            'success' => TRUE,
            'message' => 'Conexão com a API Ninjas confirmada.'
                . ($vencimento !== NULL ? ' Consulta de teste (api-ninjas.com) devolveu vencimento em ' . date('d/m/Y', strtotime($vencimento)) . '.' : ''),
        ];
    }

    /**
     * Achata a resposta da API no formato que o model grava.
     *
     * Chave AUSENTE do array de retorno significa "a origem não informou" — o
     * model não grava essas colunas, para não apagar dado bom com NULL.
     *
     * @param  string $domain
     * @param  array  $dados
     * @return array
     */
    private function normalizarResposta($domain, array $dados)
    {
        $retorno = [
            'domain_name' => isset($dados['domain_name']) && is_string($dados['domain_name'])
                ? mb_strtolower(trim($dados['domain_name']))
                : $domain,
        ];

        if (array_key_exists('registrar', $dados)) {
            $registrador = is_string($dados['registrar']) ? trim($dados['registrar']) : '';
            $retorno['registrar'] = $registrador !== '' ? mb_substr($registrador, 0, 150) : NULL;
        }

        foreach (['expiration_date', 'creation_date', 'updated_date'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $retorno[$campo] = $this->normalizarData($dados[$campo]);
            }
        }

        if (array_key_exists('name_servers', $dados)) {
            $servidores = $this->normalizarNameServers($dados['name_servers']);
            $retorno['name_servers'] = $servidores;
            $retorno['ns1'] = isset($servidores[0]) ? $servidores[0] : NULL;
            $retorno['ns2'] = isset($servidores[1]) ? $servidores[1] : NULL;
        }

        return $retorno;
    }

    /**
     * Traduz o HTTP da API Ninjas em causa acionável.
     *
     * @param  int    $status
     * @param  string $corpo
     * @return array  message, abort, premium, transient
     */
    private function mensagemErroHttp($status, $corpo)
    {
        $detalhe = $this->extrairDetalhe($corpo);
        $sufixo = $detalhe !== '' ? ' (' . $detalhe . ')' : '';

        // A API responde chave inválida com HTTP 400 e {"error":"Invalid API Key."},
        // não com 401. Sem olhar o corpo, isso passaria por "domínio inválido": a
        // rodada não abortaria e cada domínio da fila seria marcado como já
        // tentado, ficando sem nova consulta pelo intervalo inteiro.
        if ($detalhe !== '' && preg_match('/api[\s_-]*key/i', $detalhe)) {
            return [
                'message' => 'Chave da API Ninjas inválida ou ausente (' . $detalhe . ').',
                'abort' => TRUE,
                'transient' => TRUE,
            ];
        }

        switch ($status) {
            case 400:
                return ['message' => 'Domínio inválido ou não suportado pela API Ninjas' . $sufixo . '.'];
            case 401:
                return ['message' => 'Chave da API Ninjas inválida ou ausente' . $sufixo . '.', 'abort' => TRUE, 'transient' => TRUE];
            case 402:
            case 403:
                // A API cobra plano pago para TLD fora de .com. É limitação do
                // plano, não da rodada: os outros domínios seguem valendo a pena.
                return ['message' => 'Acesso negado pela API Ninjas: este TLD provavelmente exige plano pago' . $sufixo . '.', 'premium' => TRUE];
            case 404:
                // Domínio inexistente. O 400 desta API é que cobre "TLD não
                // suportado", então o 404 sobra para o caso que interessa: o
                // WHOIS do registrador respondeu que ninguém registrou isto.
                return ['message' => 'Domínio não encontrado no WHOIS — está livre ou já foi liberado' . $sufixo . '.', 'livre' => TRUE];
            case 429:
                return ['message' => 'Quota da API Ninjas excedida' . $sufixo . '.', 'abort' => TRUE, 'transient' => TRUE];
        }

        if ($status >= 500) {
            return ['message' => 'Falha temporária na API Ninjas (HTTP ' . $status . ')' . $sufixo . '.', 'transient' => TRUE];
        }

        return ['message' => 'A API Ninjas respondeu com HTTP ' . $status . $sufixo . '.'];
    }
}
