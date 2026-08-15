<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Integração com a API do DirectAdmin.
 *
 * Autenticação Basic com uma Login Key (DirectAdmin › Account Manager › Login
 * Keys) no lugar da senha — a senha do admin também funciona, mas a chave pode
 * ser revogada sem trocar a senha.
 *
 * Porta: a que vier no endereço cadastrado, senão 2222. Aceita http:// para
 * instalações sem TLS.
 *
 * Duas particularidades desta API que ditam o desenho abaixo:
 *
 *  1. A resposta pode vir em três formatos — JSON, querystring
 *     (`list[]=a&list[]=b`) ou uma lista de linhas. O parser tenta os três.
 *  2. Não existe um endpoint que devolva tudo de uma vez: é preciso listar os
 *     usuários e, para cada um, buscar config, domínios e uso. Com muitos
 *     usuários isso é lento e sujeito a falha parcial — daí o `complete`, que
 *     impede o sincronizador de apagar domínios quando parte da coleta falhou.
 */
class Server_directadmin
{
    /** Porta padrão do DirectAdmin quando o cadastro não informa outra. */
    const PORT = 2222;

    /**
     * Testa a conexão.
     *
     * @param  array $config host, username, secret, verify_ssl, timeout_seconds
     * @return array success, message, response_ms
     */
    public function test($config)
    {
        $inicio = microtime(TRUE);
        $resposta = $this->request($config, '/CMD_API_SHOW_ADMINS?json=yes');
        $ms = (int) round((microtime(TRUE) - $inicio) * 1000);

        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'message' => $resposta['message'], 'response_ms' => $ms];
        }

        // HTML no lugar de dados = o DirectAdmin devolveu a tela de login, ou
        // seja, a conta não tem permissão de API (ou a URL está errada).
        if (stripos($resposta['raw'], '<html') !== FALSE) {
            return [
                'success' => FALSE,
                'message' => 'O DirectAdmin devolveu HTML: a conta informada não tem permissão de API.',
                'response_ms' => $ms,
            ];
        }

        $lista = $this->paraLista($this->parse($resposta['raw']), ['admins', 'list', 'users']);

        return [
            'success' => TRUE,
            'message' => 'Conexão com o DirectAdmin estabelecida (' . count($lista) . ' admin(s) visível(is)).',
            'response_ms' => $ms,
        ];
    }

    /**
     * Percorre os usuários e monta a lista de domínios.
     *
     * @param  array $config
     * @return array domains, complete, message
     */
    public function listDomains($config)
    {
        $usuarios = $this->listarUsuarios($config);
        $dominios = [];
        $falharam = [];

        foreach ($usuarios as $usuario) {
            $cfg = $this->configDoUsuario($config, $usuario);
            if ($cfg === FALSE) {
                $falharam[] = $usuario;
                continue;
            }

            $dominiosDoUsuario = $this->dominiosDoUsuario($config, $usuario);
            if (empty($dominiosDoUsuario) && $cfg['domain'] !== '') {
                $dominiosDoUsuario = [$cfg['domain']];
            }

            $uso = $this->usoDoUsuario($config, $usuario);

            foreach ($dominiosDoUsuario as $dominio) {
                $dominio = mb_strtolower(trim((string) $dominio));
                if ($dominio === '') continue;

                $dominios[] = [
                    'domain' => $dominio,
                    'owner_username' => $usuario,
                    'plan' => ($cfg['package'] !== '') ? $cfg['package'] : 'Não definido',
                    'disk_used_mb' => $uso,
                    'disk_limit_mb' => ($cfg['quota'] > 0) ? $cfg['quota'] : NULL,
                    'ip' => ($cfg['ip'] !== '') ? $cfg['ip'] : NULL,
                    'status' => $cfg['suspended'] ? 'suspenso' : 'ativo',
                    'contact_email' => NULL,
                    'suspension_reason' => NULL,
                    'source' => 'directadmin',
                ];
            }
        }

        if (!empty($dominios)) {
            $mensagem = empty($falharam)
                ? ''
                : count($falharam) . ' usuário(s) não responderam: ' . implode(', ', array_slice($falharam, 0, 10));

            return [
                'domains' => $dominios,
                // Com qualquer usuário falhando a lista deixa de ser autoritativa
                // e o sincronizador não pode remover ausentes.
                'complete' => empty($falharam),
                'message' => $mensagem,
            ];
        }

        // Nenhum domínio pela varredura por usuário: tenta o endpoint global.
        // Ele não traz plano/ip/uso, mas ao menos identifica os domínios.
        $resposta = $this->request($config, '/CMD_API_SHOW_ALL_DOMAINS?json=yes');
        if (empty($resposta['success'])) {
            return ['domains' => [], 'complete' => FALSE, 'message' => $resposta['message']];
        }

        $lista = $this->paraLista($this->parse($resposta['raw']), ['list', 'domains']);
        $dominios = [];
        foreach ($lista as $dominio) {
            $dominio = mb_strtolower(trim((string) $dominio));
            if ($dominio === '') continue;
            $dominios[] = [
                'domain' => $dominio,
                'owner_username' => NULL,
                'plan' => NULL,
                'disk_used_mb' => NULL,
                'disk_limit_mb' => NULL,
                'ip' => NULL,
                'status' => 'ativo',
                'contact_email' => NULL,
                'suspension_reason' => NULL,
                'source' => 'directadmin',
            ];
        }

        return [
            'domains' => $dominios,
            // Veio da lista global, sem dono nem uso: não serve para podar.
            'complete' => FALSE,
            'message' => empty($dominios) ? 'Nenhum domínio retornado pelo DirectAdmin.' : 'Lista obtida sem detalhes por usuário.',
        ];
    }

    /**
     * @param  array $config
     * @return string[]
     */
    private function listarUsuarios($config)
    {
        foreach (['/CMD_API_SHOW_ALL_USERS', '/CMD_API_SHOW_USERS'] as $endpoint) {
            $resposta = $this->request($config, $endpoint);
            if (empty($resposta['success'])) continue;

            $lista = $this->paraLista($this->parse($resposta['raw']), ['list', 'users']);
            if (!empty($lista)) return $lista;
        }
        return [];
    }

    /**
     * @param  array  $config
     * @param  string $usuario
     * @return array|bool domain, package, ip, suspended, quota — ou FALSE
     */
    private function configDoUsuario($config, $usuario)
    {
        $resposta = $this->request($config, '/CMD_API_SHOW_USER_CONFIG?user=' . rawurlencode($usuario));
        if (empty($resposta['success'])) return FALSE;

        $dados = $this->parse($resposta['raw']);
        if (!is_array($dados)) return FALSE;

        return [
            'domain' => isset($dados['domain']) ? (string) $dados['domain'] : '',
            'package' => isset($dados['package']) ? (string) $dados['package'] : '',
            'ip' => isset($dados['ip']) ? (string) $dados['ip'] : '',
            'suspended' => isset($dados['suspended']) && mb_strtolower((string) $dados['suspended']) === 'yes',
            'quota' => isset($dados['quota']) ? (float) $dados['quota'] : 0.0,
        ];
    }

    /**
     * @param  array  $config
     * @param  string $usuario
     * @return string[]
     */
    private function dominiosDoUsuario($config, $usuario)
    {
        $endpoints = [
            '/CMD_API_SHOW_USER_DOMAINS?user=' . rawurlencode($usuario) . '&json=yes',
            '/CMD_API_SHOW_DOMAINS?user=' . rawurlencode($usuario),
        ];

        foreach ($endpoints as $endpoint) {
            $resposta = $this->request($config, $endpoint);
            if (empty($resposta['success'])) continue;

            $lista = $this->paraLista($this->parse($resposta['raw']), ['list', 'domains']);
            if (!empty($lista)) return $lista;
        }
        return [];
    }

    /**
     * @param  array  $config
     * @param  string $usuario
     * @return float MB usados
     */
    private function usoDoUsuario($config, $usuario)
    {
        $resposta = $this->request($config, '/CMD_API_SHOW_USER_USAGE?user=' . rawurlencode($usuario));
        if (empty($resposta['success'])) return 0.0;

        $dados = $this->parse($resposta['raw']);
        return (is_array($dados) && isset($dados['quota'])) ? (float) $dados['quota'] : 0.0;
    }

    /**
     * Interpreta a resposta em qualquer um dos três formatos que o DirectAdmin
     * usa: JSON, querystring ou linhas soltas.
     *
     * @param  string $raw
     * @return array
     */
    public function parse($raw)
    {
        $texto = trim((string) $raw);
        if ($texto === '') return [];

        $json = json_decode($texto, TRUE);
        if (is_array($json)) return $json;

        // parse_str resolve tanto `list[]=a&list[]=b` quanto `domain=x&ip=y`.
        if (strpos($texto, '=') !== FALSE) {
            $saida = [];
            parse_str($texto, $saida);
            if (!empty($saida)) return $saida;
        }

        $linhas = array_values(array_filter(array_map('trim', explode("\n", $texto)), 'strlen'));
        return $linhas;
    }

    /**
     * Extrai uma lista de strings de uma estrutura heterogênea — o DirectAdmin
     * devolve ora array simples, ora objeto com a lista em uma das chaves, ora
     * array de objetos.
     *
     * @param  mixed    $dados
     * @param  string[] $chaves chaves candidatas a conter a lista
     * @return string[]
     */
    public function paraLista($dados, $chaves = [])
    {
        if (!is_array($dados)) return [];

        // Objeto com a lista em uma chave conhecida (inclui a variante "list[]").
        if (!$this->ehListaSimples($dados)) {
            foreach (array_merge($chaves, ['list', 'list[]']) as $chave) {
                if (isset($dados[$chave]) && is_array($dados[$chave])) {
                    return $this->extrairValores($dados[$chave]);
                }
            }
            return $this->extrairValores(array_values($dados));
        }

        return $this->extrairValores($dados);
    }

    /**
     * @param  array $itens
     * @return string[]
     */
    private function extrairValores($itens)
    {
        $saida = [];
        foreach ($itens as $item) {
            if (is_array($item)) {
                foreach (['domain', 'name', 'value', 'username', 'user'] as $chave) {
                    if (isset($item[$chave]) && is_scalar($item[$chave]) && trim((string) $item[$chave]) !== '') {
                        $saida[] = trim((string) $item[$chave]);
                        break;
                    }
                }
                continue;
            }
            if (is_scalar($item) && trim((string) $item) !== '') {
                $saida[] = trim((string) $item);
            }
        }
        return array_values(array_unique($saida));
    }

    /**
     * @param  array $dados
     * @return bool TRUE quando é lista indexada (0,1,2...)
     */
    private function ehListaSimples($dados)
    {
        return array_keys($dados) === range(0, count($dados) - 1);
    }

    /**
     * Monta a base respeitando esquema e porta informados no cadastro.
     *
     * @param  string $host
     * @return string|bool
     */
    private function baseUrl($host)
    {
        $valor = trim((string) $host);
        if ($valor === '') return FALSE;

        if (strpos($valor, '://') === FALSE) {
            $valor = 'https://' . $valor;
        }

        $partes = parse_url($valor);
        if (empty($partes['host'])) return FALSE;

        $esquema = (isset($partes['scheme']) && $partes['scheme'] === 'http') ? 'http' : 'https';
        $porta = !empty($partes['port']) ? (int) $partes['port'] : self::PORT;

        return $esquema . '://' . $partes['host'] . ':' . $porta;
    }

    /**
     * GET autenticado. Devolve o corpo cru — o parsing é do chamador, porque o
     * formato varia por endpoint.
     *
     * @param  array  $config
     * @param  string $endpoint
     * @return array  success, raw, message
     */
    private function request($config, $endpoint)
    {
        $base = $this->baseUrl(isset($config['host']) ? $config['host'] : '');
        if ($base === FALSE) {
            return ['success' => FALSE, 'raw' => '', 'message' => 'Endereço do servidor DirectAdmin inválido.'];
        }

        $timeout = isset($config['timeout_seconds']) ? (int) $config['timeout_seconds'] : 30;
        if ($timeout <= 0) $timeout = 30;
        $verificaSsl = !empty($config['verify_ssl']);

        $ch = curl_init($base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTPGET => TRUE,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($config['username'] . ':' . $config['secret']),
                'Accept: application/json, text/plain, */*',
            ],
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
            return ['success' => FALSE, 'raw' => '', 'message' => $this->mensagemErroCurl($erroNumero, $erroTexto)];
        }

        if ($status === 401) {
            return ['success' => FALSE, 'raw' => '', 'message' => 'Credenciais inválidas no DirectAdmin (401). Confira o usuário e a Login Key.'];
        }
        if ($status === 403) {
            return ['success' => FALSE, 'raw' => '', 'message' => 'Sem permissão na API do DirectAdmin (403).'];
        }
        if ($status < 200 || $status >= 300) {
            return ['success' => FALSE, 'raw' => '', 'message' => 'O DirectAdmin respondeu com HTTP ' . $status . '.'];
        }

        return ['success' => TRUE, 'raw' => (string) $corpo, 'message' => ''];
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
                return 'Tempo limite excedido ao conectar no DirectAdmin.';
            case CURLE_COULDNT_CONNECT:
                return 'Conexão recusada pelo DirectAdmin (verifique o endereço e a porta).';
            case CURLE_COULDNT_RESOLVE_HOST:
                return 'Host do DirectAdmin não encontrado.';
            case CURLE_SSL_CACERT:
            case CURLE_SSL_PEER_CERTIFICATE:
            case CURLE_SSL_CONNECT_ERROR:
                return 'Erro de SSL ao conectar no DirectAdmin. Se o certificado é auto-assinado, desmarque "Verificar SSL" no cadastro.';
            default:
                return 'Falha ao conectar no DirectAdmin: ' . $texto;
        }
    }
}
