<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Whois_provider.php';

/**
 * Consulta RDAP do Registro.br (https://registro.br/rdap/), usada nos domínios
 * .br. Domínios internacionais são atendidos pela Ninjas_whois.
 *
 * GET https://rdap.registro.br/domain/exemplo.com.br
 * Header: Accept: application/rdap+json
 *
 * Serviço público: não tem chave nem quota mensal. O `$apiKey` existe só para
 * a assinatura bater com a das outras origens e é ignorado.
 *
 * O formato é o RDAP padrão (RFC 9083), bem diferente do JSON achatado da API
 * Ninjas:
 *
 *   - as datas vivem em `events[]`, cada uma com `eventAction`
 *     (registration | expiration | last changed) e `eventDate` em ISO 8601;
 *   - os nameservers são objetos em `nameservers[]`, no campo `ldhName`;
 *   - o titular está em `entities[]` com `roles: ["registrant"]`, num vcard.
 *
 * Cuidado ao mexer: `nameservers[]` traz um `events` PRÓPRIO por servidor (com
 * `delegation check`), que não tem nada a ver com o vencimento do domínio. Só
 * o `events` da raiz vale.
 *
 * Não existe papel "registrar" na resposta porque o .br tem registro único —
 * daí o registrador ser constante.
 */
class Rdap_registrobr extends Whois_provider
{
    const ENDPOINT = 'https://rdap.registro.br/domain/';

    /** O .br tem registro único; não há registrador variável por domínio. */
    const REGISTRADOR = 'Registro.br';

    protected function servico()
    {
        return 'o RDAP do Registro.br';
    }

    /**
     * @param  string $domain domínio registrável (exemplo.com.br)
     * @param  string $apiKey ignorado — o serviço é público
     * @param  int    $timeout
     * @return array  ver contrato em Whois_provider
     */
    public function lookup($domain, $apiKey = '', $timeout = self::TIMEOUT_PADRAO)
    {
        $domain = rtrim(mb_strtolower(trim((string) $domain)), '.');

        if ($domain === '') {
            return $this->erro('Domínio não informado.');
        }

        $resposta = $this->requisitar(
            self::ENDPOINT . rawurlencode($domain),
            ['Accept: application/rdap+json'],
            $timeout
        );

        if ($resposta['erro_numero'] !== 0) {
            return $this->erro($this->mensagemErroCurl($resposta['erro_numero'], $resposta['erro_texto']), 0, ['transient' => TRUE]);
        }

        if ($resposta['status'] < 200 || $resposta['status'] >= 300) {
            $traduzido = $this->mensagemErroHttp($resposta['status'], $resposta['corpo']);
            return $this->erro($traduzido['message'], $resposta['status'], $traduzido);
        }

        $dados = json_decode((string) $resposta['corpo'], TRUE);
        if (!is_array($dados)) {
            return $this->erro('Resposta inválida do RDAP do Registro.br (não é JSON).', $resposta['status']);
        }

        return $this->sucesso($this->normalizarResposta($domain, $dados), $dados, $resposta['status']);
    }

    /**
     * Confere se o serviço está no ar, consultando um domínio que sempre existe.
     *
     * @return array success, message
     */
    public function test()
    {
        $resultado = $this->lookup('registro.br');

        if (empty($resultado['success'])) {
            return ['success' => FALSE, 'message' => $resultado['message']];
        }

        return [
            'success' => TRUE,
            'message' => 'Conexão com o RDAP do Registro.br confirmada (consulta de teste: registro.br).',
        ];
    }

    /**
     * Achata a resposta RDAP no mesmo formato que a Ninjas_whois devolve, para
     * o model gravar sem saber de qual origem veio.
     *
     * @param  string $domain
     * @param  array  $dados
     * @return array
     */
    private function normalizarResposta($domain, array $dados)
    {
        $retorno = [
            'domain_name' => !empty($dados['ldhName']) && is_string($dados['ldhName'])
                ? mb_strtolower(trim($dados['ldhName']))
                : $domain,
            // Constante de propósito: o .br tem registro único.
            'registrar' => self::REGISTRADOR,
        ];

        $eventos = $this->indexarEventos(isset($dados['events']) ? $dados['events'] : []);

        $mapa = [
            'expiration' => 'expiration_date',
            'registration' => 'creation_date',
            'last changed' => 'updated_date',
        ];

        foreach ($mapa as $acao => $campo) {
            // array_key_exists e não isset: a ausência do evento é o que sinaliza
            // "a origem não informou", e o model usa isso para não gravar NULL
            // por cima de dado bom.
            if (array_key_exists($acao, $eventos)) {
                $retorno[$campo] = $this->normalizarData($eventos[$acao]);
            }
        }

        if (array_key_exists('nameservers', $dados)) {
            $nomes = [];
            foreach ((array) $dados['nameservers'] as $ns) {
                if (is_array($ns) && !empty($ns['ldhName']) && is_string($ns['ldhName'])) {
                    $nomes[] = $ns['ldhName'];
                }
            }

            $servidores = $this->normalizarNameServers($nomes);
            $retorno['name_servers'] = $servidores;
            $retorno['ns1'] = isset($servidores[0]) ? $servidores[0] : NULL;
            $retorno['ns2'] = isset($servidores[1]) ? $servidores[1] : NULL;
        }

        return $retorno;
    }

    /**
     * Reduz `events[]` a um mapa eventAction => eventDate.
     *
     * Só os eventos da raiz: os de `nameservers[]` e `secureDNS` descrevem
     * checagem de delegação, não o ciclo de vida do domínio.
     *
     * @param  mixed $eventos
     * @return array
     */
    public function indexarEventos($eventos)
    {
        $mapa = [];

        if (!is_array($eventos)) {
            return $mapa;
        }

        foreach ($eventos as $evento) {
            if (!is_array($evento) || empty($evento['eventAction']) || !is_string($evento['eventAction'])) {
                continue;
            }

            $acao = mb_strtolower(trim($evento['eventAction']));
            if (!isset($evento['eventDate'])) {
                continue;
            }

            // Primeiro vence: se o mesmo evento aparecer repetido, o de cima é
            // o que o registro considera corrente.
            if (!array_key_exists($acao, $mapa)) {
                $mapa[$acao] = $evento['eventDate'];
            }
        }

        return $mapa;
    }

    /**
     * Nome do titular a partir do vcard da entidade com papel `registrant`.
     * Usado só para exibição — não tem coluna no banco.
     *
     * @param  array $dados resposta crua
     * @return array nome, documento (ambos podem ser '')
     */
    public function extrairTitular(array $dados)
    {
        $vazio = ['nome' => '', 'documento' => ''];

        if (empty($dados['entities']) || !is_array($dados['entities'])) {
            return $vazio;
        }

        foreach ($dados['entities'] as $entidade) {
            if (!is_array($entidade) || empty($entidade['roles']) || !is_array($entidade['roles'])) {
                continue;
            }

            if (!in_array('registrant', $entidade['roles'], TRUE)) {
                continue;
            }

            $nome = $this->valorVcard(isset($entidade['vcardArray']) ? $entidade['vcardArray'] : NULL, 'fn');

            $documento = '';
            if (!empty($entidade['publicIds']) && is_array($entidade['publicIds'])) {
                foreach ($entidade['publicIds'] as $id) {
                    if (is_array($id) && !empty($id['identifier']) && is_string($id['identifier'])) {
                        $tipo = !empty($id['type']) && is_string($id['type']) ? mb_strtoupper($id['type']) . ': ' : '';
                        $documento = $tipo . $id['identifier'];
                        break;
                    }
                }
            }

            return ['nome' => $nome, 'documento' => $documento];
        }

        return $vazio;
    }

    /**
     * Extrai uma propriedade do jCard (RFC 7095), cuja forma é
     * ["vcard", [["fn", {}, "text", "Valor"], ...]].
     *
     * @param  mixed  $vcardArray
     * @param  string $propriedade
     * @return string
     */
    public function valorVcard($vcardArray, $propriedade)
    {
        if (!is_array($vcardArray) || !isset($vcardArray[1]) || !is_array($vcardArray[1])) {
            return '';
        }

        foreach ($vcardArray[1] as $campo) {
            if (!is_array($campo) || !isset($campo[0]) || $campo[0] !== $propriedade) {
                continue;
            }

            // O valor é o 4º item; pode ser string ou lista (endereço).
            $valor = isset($campo[3]) ? $campo[3] : NULL;

            if (is_string($valor)) {
                return trim($valor);
            }

            if (is_array($valor)) {
                $partes = array_filter(array_map('trim', array_filter($valor, 'is_string')));
                return implode(', ', $partes);
            }
        }

        return '';
    }

    /**
     * Traduz o HTTP do RDAP em causa acionável.
     *
     * @param  int    $status
     * @param  string $corpo
     * @return array  message, abort, premium, transient
     */
    private function mensagemErroHttp($status, $corpo)
    {
        $detalhe = $this->extrairDetalhe($corpo);
        $sufixo = $detalhe !== '' ? ' (' . $detalhe . ')' : '';

        switch ($status) {
            case 400:
                return ['message' => 'Domínio inválido para o RDAP do Registro.br' . $sufixo . '.'];
            case 404:
                // O 404 do Registro.br vem com corpo vazio. É a resposta mais
                // confiável de "livre" que existe na base: o RDAP é do próprio
                // registro do .br, então não conhecer o domínio significa que
                // ninguém o registrou (ou que ele já foi liberado).
                return ['message' => 'Domínio não encontrado no Registro.br — está livre ou já foi liberado' . $sufixo . '.', 'livre' => TRUE];
            case 429:
                // Serviço público e sem quota mensal, mas com limite por janela:
                // abortar a rodada e voltar depois é melhor que insistir e ser
                // bloqueado por mais tempo.
                return ['message' => 'Limite de consultas do Registro.br atingido. A rodada será retomada na próxima execução' . $sufixo . '.', 'abort' => TRUE, 'transient' => TRUE];
        }

        if ($status >= 500) {
            return ['message' => 'Falha temporária no RDAP do Registro.br (HTTP ' . $status . ')' . $sufixo . '.', 'transient' => TRUE];
        }

        return ['message' => 'O RDAP do Registro.br respondeu com HTTP ' . $status . $sufixo . '.'];
    }
}
