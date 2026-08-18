<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Base comum dos provedores de cobrança (PSP).
 *
 * O PSP é escolha do CONTRATO (`crm_contracts.psp`), e mais de um pode estar
 * ativo no mesmo tenant ao mesmo tempo. Esta classe é o que torna isso barato:
 * acrescentar um PSP é escrever uma filha e somar uma linha na allowlist
 * `Psp_model::providers()` — nada no motor de faturas muda.
 *
 * Segue o desenho do Whois_provider, que já resolve o mesmo problema (duas
 * origens, regras idênticas depois da resposta): o que muda é o endpoint e o
 * formato do JSON; o que não muda — normalizar dinheiro, documento e data,
 * traduzir erro de rede — mora aqui.
 *
 * Contrato que as filhas cumprem, em TODO método público:
 *
 *   [
 *     'success'   => bool,
 *     'message'   => string,   // texto para o usuário, nunca stack trace
 *     'data'      => array,    // normalizado (o vocabulário do sistema)
 *     'http_code' => int,
 *     'transient' => bool,     // TRUE = falha de infra, tente de novo
 *   ]
 *
 * `transient` é o que separa "tente de novo" de "não adianta". Sem ele a fila
 * de retentativa queimaria tentativa num 422 (dado inválido, que vai falhar
 * igual para sempre) e desistiria de um timeout (que costuma passar sozinho).
 *
 * Em `data`, chave AUSENTE significa "o PSP não informou", e o model NÃO grava
 * a coluna correspondente. É a regra do CloudPanel com plano/IP/cota:
 * sobrescrever com NULL apaga dado bom vindo de outra consulta.
 *
 * Chaves normalizadas que as filhas devolvem quando souberem:
 *   charge_id, psp_status (cru, para diagnóstico), situacao (normalizada),
 *   link_boleto, linha_digitavel, link_pix, paid_at, paid_amount, paid_method
 *
 * COMO AS DEMAIS LIBRARIES DE INTEGRAÇÃO, NUNCA LANÇA EXCEÇÃO. Falha de rede,
 * credencial recusada e resposta ilegível são todas retorno com success FALSE.
 */
abstract class Psp_provider
{
    const TIMEOUT_PADRAO = 30;
    const MAX_TENTATIVAS = 4;

    /** Situações normalizadas — o vocabulário que o Psp_model entende. */
    const SIT_PENDENTE = 'pendente';
    const SIT_REGISTRADA = 'registrada';
    const SIT_PAGA = 'paga';
    const SIT_CANCELADA = 'cancelada';
    const SIT_EXPIRADA = 'expirada';

    /** Slug do provedor; precisa bater com a chave em Psp_model::providers(). */
    abstract public function slug();

    /** Nome de exibição, usado nas mensagens de erro e na tela. */
    abstract public function nome();

    /**
     * Exercita a credencial salva. Não cria nada.
     *
     * @param  array $config
     * @return array success, message, response_ms
     */
    abstract public function test(array $config);

    /**
     * Registra a cobrança (boleto + PIX) no PSP.
     *
     * @param  array $config
     * @param  array $cobranca  ['referencia', 'valor', 'vencimento', 'descricao', 'pagador' => [...]]
     * @return array data => ['charge_id', 'situacao', ...]
     */
    abstract public function criarCobranca(array $config, array $cobranca);

    /**
     * Estado atual da cobrança. É a FONTE DA VERDADE do webhook: o corpo
     * recebido nunca é acreditado, esta consulta é que decide.
     *
     * @param  array  $config
     * @param  string $chargeId
     * @return array
     */
    abstract public function consultarCobranca(array $config, $chargeId);

    /**
     * @param  array  $config
     * @param  string $chargeId
     * @param  string $motivo
     * @return array
     */
    abstract public function cancelarCobranca(array $config, $chargeId, $motivo);

    /**
     * Cobranças do período, para a conciliação da etapa D.
     *
     * @param  array $config
     * @param  array $filtros ['data_inicial', 'data_final', 'pagina', 'por_pagina']
     * @return array data => ['itens' => [...], 'total' => int]
     */
    abstract public function listarCobrancas(array $config, array $filtros);

    /**
     * Aponta a URL de callback no PSP.
     *
     * @param  array  $config
     * @param  string $url
     * @return array
     */
    abstract public function registrarWebhook(array $config, $url);

    /**
     * Lê o que chegou no webhook e responde APENAS qual cobrança reconsultar.
     *
     * Devolve `charge_id` e `event_type`, e NUNCA valor, status ou data de
     * pagamento — mesmo quando o corpo os traz. A regra do projeto é não
     * confiar no corpo (a mesma que revalida o `bomcontrole_contract_id` no
     * `Obter`), e deixá-la gravada no FORMATO da interface é o que impede que
     * uma implementação futura "aproveite" o valor recebido por economia.
     *
     * @param  array  $cabecalhos
     * @param  string $corpoCru
     * @return array data => ['charge_id', 'event_type']
     */
    abstract public function interpretarWebhook(array $cabecalhos, $corpoCru);

    /**
     * PDF do boleto, em bytes crus.
     *
     * Existe separado de `link_boleto` porque os PSPs se dividem em dois
     * grupos: uns publicam uma URL do boleto (e aí a chave `link_boleto` vem
     * preenchida na consulta), outros só entregam o PDF por endpoint
     * autenticado — e nesse caso não HÁ link para guardar, o arquivo é que
     * viaja anexo no e-mail da etapa B. O Banco Inter é do segundo grupo.
     *
     * @param  array  $config
     * @param  string $chargeId
     * @return array data => [pdf => bytes crus]
     */
    abstract public function obterPdf(array $config, $chargeId);

    // ------------------------------------------------------------------
    // Normalização compartilhada
    // ------------------------------------------------------------------

    /**
     * Valor em reais decimais com 2 casas, como string.
     *
     * Sai como STRING de propósito: json_encode de um float 0.1+0.2 produz
     * dízima, e o PSP recebe um centavo a mais ou a menos sem avisar.
     *
     * @param  mixed $valor
     * @return string
     */
    public function valorDecimal($valor)
    {
        return number_format((float) $valor, 2, '.', '');
    }

    /**
     * Só os dígitos do CPF/CNPJ — é assim que `crm_customers.document` guarda.
     *
     * @param  string $documento
     * @return string
     */
    public function documentoDigitos($documento)
    {
        return preg_replace('/\D/', '', (string) $documento);
    }

    /**
     * FISICA (11 dígitos) ou JURIDICA (14). Documento de outro tamanho não é
     * adivinhado: devolve '' e quem chamou decide se recusa.
     *
     * @param  string $documento
     * @return string
     */
    public function tipoPessoa($documento)
    {
        $digitos = $this->documentoDigitos($documento);

        if (strlen($digitos) === 11) return 'FISICA';
        if (strlen($digitos) === 14) return 'JURIDICA';

        return '';
    }

    /**
     * 'Y-m-d' validado, ou NULL.
     *
     * Nunca `strtotime` cru: as datas chegam com hora e sem timezone, e o
     * "sem data" do .NET (0001-01-01) precisa virar NULL em vez de uma data
     * plausível. Mesma regra do `Bomcontrole_model::dataIso`.
     *
     * @param  mixed $valor
     * @return string|null
     */
    public function dataIso($valor)
    {
        $texto = trim((string) $valor);
        if ($texto === '') return NULL;

        $data = substr($texto, 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $partes)) {
            return NULL;
        }

        if ((int) $partes[1] < 1900) return NULL;
        if (!checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) return NULL;

        return $data;
    }

    /**
     * Trunca respeitando multibyte — campo estourado costuma ser recusado pelo
     * PSP com erro genérico, e cortar aqui é mais claro que descobrir depois.
     *
     * @param  string $texto
     * @param  int    $limite
     * @return string
     */
    public function limitar($texto, $limite)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto));
        return mb_substr($texto, 0, (int) $limite);
    }

    // ------------------------------------------------------------------
    // Retornos
    // ------------------------------------------------------------------

    /**
     * @param  array  $data
     * @param  int    $status
     * @param  string $mensagem
     * @return array
     */
    protected function ok(array $data = [], $status = 200, $mensagem = '')
    {
        return [
            'success' => TRUE,
            'message' => $mensagem,
            'data' => $data,
            'http_code' => (int) $status,
            'transient' => FALSE,
        ];
    }

    /**
     * @param  string $mensagem
     * @param  int    $status
     * @param  bool   $transient
     * @return array
     */
    protected function erro($mensagem, $status = 0, $transient = FALSE)
    {
        return [
            'success' => FALSE,
            'message' => $mensagem,
            'data' => [],
            'http_code' => (int) $status,
            'transient' => (bool) $transient,
        ];
    }

    /**
     * Erro de cURL em português, com o diagnóstico que o usuário consegue agir.
     *
     * @param  int    $numero
     * @param  string $texto
     * @return string
     */
    protected function mensagemErroCurl($numero, $texto)
    {
        $mapa = [
            CURLE_OPERATION_TIMEOUTED => 'Tempo esgotado ao falar com o ' . $this->nome() . '.',
            CURLE_COULDNT_RESOLVE_HOST => 'Não foi possível resolver o endereço do ' . $this->nome() . '.',
            CURLE_COULDNT_CONNECT => 'Não foi possível conectar ao ' . $this->nome() . '.',
            CURLE_SSL_CACERT => 'Certificado do ' . $this->nome() . ' não pôde ser validado.',
            CURLE_SSL_CERTPROBLEM => 'O certificado de cliente foi recusado — confira o .crt e o .key cadastrados.',
            // 35 é o que o servidor devolve quando REJEITA o certificado que
            // mandamos (alert unknown ca). Sem esta linha vira "falha de
            // comunicação", e o usuário procura problema de rede em vez de
            // reenviar o certificado da integração certa.
            CURLE_SSL_CONNECT_ERROR => 'O ' . $this->nome() . ' recusou o certificado enviado. Confira se o .crt e o .key são os da integração desta empresa e se o ambiente (sandbox/produção) está correto.',
        ];

        if (isset($mapa[$numero])) {
            return $mapa[$numero];
        }

        return 'Falha de comunicação com o ' . $this->nome()
            . ' (cURL ' . (int) $numero . ($texto !== '' ? ': ' . $texto : '') . ').';
    }
}
