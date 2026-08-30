<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Integração com a API do WHM/cPanel.
 *
 * A porta é sempre 2087 (a de API sobre TLS). O campo `port` do cadastro é do
 * CloudPanel; aqui é ignorado de propósito.
 *
 * Autenticação por API token do WHM, no formato de header próprio do cPanel:
 *
 *   Authorization: whm <usuario>:<token>
 *
 * Não é Basic — o token é gerado em WHM › Development › Manage API Tokens.
 */
class Server_whm
{
    /** Porta da API do WHM sobre TLS. */
    const PORT = 2087;

    /**
     * Testa a conexão.
     *
     * @param  array $config host, username, secret, verify_ssl, timeout_seconds
     * @return array success, message, version, response_ms
     */
    public function test($config)
    {
        $inicio = microtime(TRUE);
        $resposta = $this->request($config, '/json-api/version');
        $ms = (int) round((microtime(TRUE) - $inicio) * 1000);

        if (empty($resposta['success'])) {
            return [
                'success' => FALSE,
                'message' => $resposta['message'],
                'version' => NULL,
                'response_ms' => $ms,
            ];
        }

        $dados = $resposta['data'];
        $version = NULL;
        if (isset($dados['version'])) $version = (string) $dados['version'];
        elseif (isset($dados['data']['version'])) $version = (string) $dados['data']['version'];

        return [
            'success' => TRUE,
            'message' => 'Conexão com o WHM estabelecida' . ($version !== NULL ? ' (versão ' . $version . ')' : '') . '.',
            'version' => $version,
            'response_ms' => $ms,
        ];
    }

    /**
     * Lista as contas do WHM já no formato do sincronizador.
     *
     * @param  array $config
     * @return array domains, complete, message
     */
    public function listDomains($config)
    {
        $resposta = $this->request($config, '/json-api/listaccts');

        if (empty($resposta['success'])) {
            return [
                'domains' => [],
                'complete' => FALSE,
                'message' => $resposta['message'],
            ];
        }

        $dados = $resposta['data'];
        $contas = [];
        if (isset($dados['acct']) && is_array($dados['acct'])) $contas = $dados['acct'];
        elseif (isset($dados['data']['acct']) && is_array($dados['data']['acct'])) $contas = $dados['data']['acct'];

        $dominios = [];
        foreach ($contas as $conta) {
            $conta = (array) $conta;
            $dominio = isset($conta['domain']) ? trim((string) $conta['domain']) : '';
            if ($dominio === '') continue;

            $suspensa = !empty($conta['suspended']);
            $limite = isset($conta['disklimit']) ? (string) $conta['disklimit'] : '';

            $dominios[] = [
                'domain' => mb_strtolower($dominio),
                'owner_username' => isset($conta['user']) ? (string) $conta['user'] : NULL,
                'plan' => (isset($conta['plan']) && $conta['plan'] !== '') ? (string) $conta['plan'] : 'Não definido',
                'disk_used_mb' => $this->toMb(isset($conta['diskused']) ? $conta['diskused'] : ''),
                // "unlimited" vira NULL (sem limite), não zero.
                'disk_limit_mb' => (mb_strtolower(trim($limite)) === 'unlimited') ? NULL : $this->toMb($limite),
                'ip' => isset($conta['ip']) ? (string) $conta['ip'] : NULL,
                'status' => $suspensa ? 'suspenso' : 'ativo',
                'contact_email' => isset($conta['email']) ? (string) $conta['email'] : NULL,
                'suspension_reason' => $suspensa
                    ? ((isset($conta['suspendreason']) && $conta['suspendreason'] !== '') ? (string) $conta['suspendreason'] : 'Não informado')
                    : NULL,
                'source' => 'whm',
            ];
        }

        return [
            'domains' => $dominios,
            'complete' => TRUE,
            'message' => '',
        ];
    }

    /**
     * Suspende ou reativa uma CONTA do WHM.
     *
     * A unidade aqui é a conta (`user`), não o domínio: suspender derruba o
     * domínio principal e todos os addons/subdomínios daquele usuário de uma
     * vez. Quem decide se isso é aceitável é o chamador — o Server_model recusa
     * a suspensão quando a mesma conta atende outro contrato vigente.
     *
     * @param  array       $config
     * @param  string      $usuario  conta do cPanel (owner_username)
     * @param  bool        $suspender TRUE suspende, FALSE reativa
     * @param  string|null $motivo    só usado na suspensão
     * @return array       success, message
     */
    public function setSuspension($config, $usuario, $suspender, $motivo = NULL)
    {
        $conta = trim((string) $usuario);
        if ($conta === '') {
            return ['success' => FALSE, 'message' => 'Conta do WHM não informada.'];
        }

        if ($suspender) {
            $endpoint = '/json-api/suspendacct?api.version=1&user=' . rawurlencode($conta);
            if ($motivo !== NULL && trim((string) $motivo) !== '') {
                $endpoint .= '&reason=' . rawurlencode(trim((string) $motivo));
            }
        } else {
            $endpoint = '/json-api/unsuspendacct?api.version=1&user=' . rawurlencode($conta);
        }

        $resposta = $this->request($config, $endpoint);
        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'message' => $resposta['message']];
        }

        $falha = $suspender ? 'Falha ao suspender a conta no WHM' : 'Falha ao reativar a conta no WHM';

        return $this->resultadoAcao($resposta['data'], $falha);
    }

    /**
     * Altera a COTA DE DISCO de uma conta do WHM.
     *
     * A unidade é a conta (`user`), como na suspensão: a cota do cPanel vale
     * para o domínio principal e todos os addons/subdomínios daquele usuário.
     * Quem decide se isso é aceitável é o chamador.
     *
     * `QUOTA` é em MEGABYTES e **zero significa ilimitado** — é assim que o WHM
     * trata o campo "Disk Space Quota (MB)" da tela Modify an Account. Não
     * confundir com o `disk_limit_mb` gravado aqui, onde ilimitado é NULL: a
     * conversão entre as duas convenções é do Server_model.
     *
     * Só `QUOTA` viaja no `modifyacct`: a API altera apenas o que recebe, então
     * não é preciso reenviar o resto do cadastro (ao contrário do DirectAdmin,
     * que zera o que for omitido).
     *
     * @param  array  $config
     * @param  string $usuario conta do cPanel (owner_username)
     * @param  int    $quotaMb 0 = ilimitado
     * @return array  success, message
     */
    public function setQuota($config, $usuario, $quotaMb)
    {
        $conta = trim((string) $usuario);
        if ($conta === '') {
            return ['success' => FALSE, 'message' => 'Conta do WHM não informada.'];
        }

        $cota = (int) $quotaMb;
        if ($cota < 0) {
            return ['success' => FALSE, 'message' => 'Cota inválida.'];
        }

        $endpoint = '/json-api/modifyacct?api.version=1&user=' . rawurlencode($conta)
            . '&QUOTA=' . $cota;

        $resposta = $this->request($config, $endpoint);
        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'message' => $resposta['message']];
        }

        return $this->resultadoAcao($resposta['data'], 'Falha ao alterar a cota da conta no WHM');
    }

    /**
     * Lê o resultado de uma ação (suspendacct/unsuspendacct/modifyacct) da
     * resposta do WHM.
     *
     * Formato desconhecido NÃO é tratado como sucesso: a API devolve HTTP 200
     * mesmo quando a operação falha (a falha vem no corpo), então assumir "deu
     * certo" por não reconhecer o envelope esconderia justamente o caso que
     * interessa — a conta continuar no ar depois de o contrato ser encerrado,
     * ou seguir sem limite depois de a cota ter sido "alterada".
     *
     * O rótulo da falha vem de fora porque o mesmo envelope serve às três
     * operações; montá-lo aqui a partir de um booleano só funcionava enquanto
     * as ações eram duas.
     *
     * @param  array  $dados
     * @param  string $falha rótulo do erro ("Falha ao ... no WHM")
     * @return array  success, message
     */
    private function resultadoAcao($dados, $falha)
    {

        // api.version=1: { metadata: { result: 1, reason: "OK" } }
        if (isset($dados['metadata']) && is_array($dados['metadata']) && isset($dados['metadata']['result'])) {
            $razao = isset($dados['metadata']['reason']) ? trim((string) $dados['metadata']['reason']) : '';
            if ((int) $dados['metadata']['result'] === 1) {
                return ['success' => TRUE, 'message' => $razao];
            }
            return ['success' => FALSE, 'message' => $falha . ($razao !== '' ? ': ' . $razao : '.')];
        }

        // Formato antigo: { result: [ { status: 1, statusmsg: "..." } ] }
        if (isset($dados['result'])) {
            $primeiro = is_array($dados['result']) && isset($dados['result'][0]) && is_array($dados['result'][0])
                ? $dados['result'][0]
                : $dados['result'];

            if (is_array($primeiro) && isset($primeiro['status'])) {
                $mensagem = isset($primeiro['statusmsg']) ? trim((string) $primeiro['statusmsg']) : '';
                if ((int) $primeiro['status'] === 1) {
                    return ['success' => TRUE, 'message' => $mensagem];
                }
                return ['success' => FALSE, 'message' => $falha . ($mensagem !== '' ? ': ' . $mensagem : '.')];
            }
        }

        return ['success' => FALSE, 'message' => $falha . ': resposta em formato não reconhecido.'];
    }

    /**
     * Converte o formato de disco do WHM ("512M", "2G", "unlimited") para MB.
     *
     * @param  mixed $valor
     * @return float
     */
    public function toMb($valor)
    {
        $normalizado = mb_strtoupper(trim((string) $valor));
        if ($normalizado === '' || $normalizado === 'UNLIMITED') {
            return 0.0;
        }

        if (!preg_match('/^([\d.]+)\s*([KMGT])?B?$/', $normalizado, $m)) {
            return (float) $normalizado;
        }

        $quantidade = (float) $m[1];
        $unidade = isset($m[2]) && $m[2] !== '' ? $m[2] : 'M';

        switch ($unidade) {
            case 'K':
                return $quantidade / 1024;
            case 'G':
                return $quantidade * 1024;
            case 'T':
                return $quantidade * 1024 * 1024;
            case 'M':
            default:
                return $quantidade;
        }
    }

    /**
     * Monta https://host:2087 a partir do que o usuário digitou (aceita com ou
     * sem esquema, com caminho ou porta — tudo é descartado menos o host).
     *
     * @param  string $host
     * @return string|bool FALSE quando não dá para extrair um host
     */
    private function baseUrl($host)
    {
        $valor = trim((string) $host);
        if ($valor === '') return FALSE;

        if (strpos($valor, '://') === FALSE) {
            $valor = 'https://' . $valor;
        }

        $hostname = parse_url($valor, PHP_URL_HOST);
        if (empty($hostname)) return FALSE;

        return 'https://' . $hostname . ':' . self::PORT;
    }

    /**
     * GET autenticado na API, com o JSON já decodificado.
     *
     * @param  array  $config
     * @param  string $endpoint
     * @return array  success, data, message
     */
    private function request($config, $endpoint)
    {
        $base = $this->baseUrl(isset($config['host']) ? $config['host'] : '');
        if ($base === FALSE) {
            return ['success' => FALSE, 'data' => NULL, 'message' => 'Endereço do servidor WHM inválido.'];
        }

        $timeout = isset($config['timeout_seconds']) ? (int) $config['timeout_seconds'] : 30;
        if ($timeout <= 0) $timeout = 30;
        $verificaSsl = !empty($config['verify_ssl']);

        $ch = curl_init($base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPGET => TRUE,
            CURLOPT_HTTPHEADER => [
                'Authorization: whm ' . $config['username'] . ':' . $config['secret'],
                'Accept: application/json',
            ],
            // Painéis costumam usar certificado auto-assinado; quem decide é o cadastro.
            CURLOPT_SSL_VERIFYPEER => $verificaSsl,
            CURLOPT_SSL_VERIFYHOST => $verificaSsl ? 2 : 0,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 15),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => FALSE,
        ]);

        $corpo = curl_exec($ch);
        $erroNumero = curl_errno($ch);
        $erroTexto = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($erroNumero !== 0) {
            return ['success' => FALSE, 'data' => NULL, 'message' => $this->mensagemErroCurl($erroNumero, $erroTexto)];
        }

        if ($status === 401) {
            return ['success' => FALSE, 'data' => NULL, 'message' => 'Credenciais inválidas no WHM (401). Confira usuário e token de API.'];
        }
        if ($status === 403) {
            return ['success' => FALSE, 'data' => NULL, 'message' => 'Sem permissão para usar a API do WHM (403).'];
        }
        if ($status < 200 || $status >= 300) {
            return ['success' => FALSE, 'data' => NULL, 'message' => 'O WHM respondeu com HTTP ' . $status . '.'];
        }

        $dados = json_decode((string) $corpo, TRUE);
        if (!is_array($dados)) {
            return ['success' => FALSE, 'data' => NULL, 'message' => 'Resposta inválida da API do WHM (não é JSON).'];
        }

        return ['success' => TRUE, 'data' => $dados, 'message' => ''];
    }

    /**
     * Traduz o código do cURL para uma causa acionável.
     *
     * @param  int    $numero
     * @param  string $texto
     * @return string
     */
    private function mensagemErroCurl($numero, $texto)
    {
        switch ($numero) {
            case CURLE_OPERATION_TIMEOUTED:
                return 'Tempo limite excedido ao conectar no WHM.';
            case CURLE_COULDNT_CONNECT:
                return 'Conexão recusada pelo servidor WHM (verifique se a porta ' . self::PORT . ' está liberada).';
            case CURLE_COULDNT_RESOLVE_HOST:
                return 'Host do WHM não encontrado.';
            case CURLE_SSL_CACERT:
            case CURLE_SSL_PEER_CERTIFICATE:
            case CURLE_SSL_CONNECT_ERROR:
                return 'Erro de SSL ao conectar no WHM. Se o certificado é auto-assinado, desmarque "Verificar SSL" no cadastro.';
            default:
                return 'Falha ao conectar no WHM: ' . $texto;
        }
    }
}
