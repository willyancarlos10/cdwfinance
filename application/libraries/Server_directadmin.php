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
     * Campos do CMD_API_SHOW_USER_CONFIG que NÃO voltam no modify.
     *
     * São identidade e estado (quem criou, quando, se está suspenso), não
     * configuração editável. `suspended` fica de fora porque a suspensão tem
     * comando próprio (`CMD_API_SELECT_USERS`) — reenviá-lo aqui misturaria
     * duas operações que o projeto mantém separadas.
     */
    const CAMPOS_NAO_REENVIADOS = [
        'name', 'username', 'usertype', 'creator', 'date_created', 'suspended',
        'suspend_time', 'password', 'clear_password', 'api_with_password',
    ];

    /**
     * Pares numérico => checkbox "ilimitado" do formulário do DirectAdmin.
     *
     * No DA, ilimitado não é um valor do campo numérico: é o checkbox irmão.
     * O SHOW_USER_CONFIG devolve a string `unlimited` no campo numérico, e
     * mandá-la de volta crua faz o modify gravar zero — que é o oposto.
     */
    const CAMPOS_ILIMITADOS = [
        'bandwidth' => 'ubandwidth',
        'quota' => 'uquota',
        'vdomains' => 'uvdomains',
        'nsubdomains' => 'unsubdomains',
        'nemails' => 'unemails',
        'nemailf' => 'unemailf',
        'nemailml' => 'unemailml',
        'nemailr' => 'unemailr',
        'mysql' => 'umysql',
        'domainptr' => 'udomainptr',
        'ftp' => 'uftp',
        'inode' => 'uinode',
    ];

    /**
     * Altera a COTA DE DISCO de um usuário do DirectAdmin.
     *
     * A unidade é a conta, como no WHM e como na suspensão daqui.
     *
     * **O CMD_API_MODIFY_USER zera o que for omitido.** Ele processa o
     * formulário CMD_MODIFY_USER inteiro, e campo ausente vira zero (nos
     * numéricos) ou OFF (nos checkboxes) — um POST só com `quota` desligaria
     * PHP, CGI, SSL e zeraria banda e caixas do cliente de uma vez. Por isso a
     * cota nunca é enviada sozinha: relê o cadastro, reenvia tudo e troca só o
     * que precisa mudar. É a mesma defesa que o GestorCMS v3 usa ao trocar
     * senha de caixa, onde omitir a quota zerava a capacidade.
     *
     * **Se a releitura falhar, aborta.** Enviar o modify com o formulário
     * incompleto é pior que não alterar a cota.
     *
     * A cota chega em MB, com **zero significando ilimitado** (convenção do
     * WHM, adotada pelo Server_model para os dois painéis); aqui isso vira
     * `uquota=ON`, que é como o DirectAdmin representa o mesmo estado.
     *
     * @param  array  $config
     * @param  string $usuario
     * @param  int    $quotaMb 0 = ilimitado
     * @return array  success, message
     */
    public function setQuota($config, $usuario, $quotaMb)
    {
        $conta = trim((string) $usuario);
        if ($conta === '') {
            return ['success' => FALSE, 'message' => 'Usuário do DirectAdmin não informado.'];
        }

        $cota = (int) $quotaMb;
        if ($cota < 0) {
            return ['success' => FALSE, 'message' => 'Cota inválida.'];
        }

        $falha = 'Falha ao alterar a cota do usuário no DirectAdmin';

        $atual = $this->configCruDoUsuario($config, $conta);
        if ($atual === FALSE) {
            return [
                'success' => FALSE,
                'message' => $falha . ': não foi possível ler o cadastro atual do usuário, e alterar sem ele'
                    . ' apagaria os demais limites da conta.',
            ];
        }

        $post = $this->montarModifyUser($atual, $conta, $cota);

        // Só os campos que decidem o resultado — o POST inteiro carrega e-mail e
        // IP do cliente, que não têm o que fazer no log.
        $enviado = [
            'action' => $post['action'],
            'quota' => isset($post['quota']) ? $post['quota'] : NULL,
            'uquota' => isset($post['uquota']) ? $post['uquota'] : NULL,
            'campos' => count($post),
        ];

        $resposta = $this->request($config, '/CMD_API_MODIFY_USER', $post);
        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'message' => $resposta['message'], 'enviado' => $enviado];
        }

        if (stripos($resposta['raw'], '<html') !== FALSE) {
            return ['success' => FALSE, 'message' => $falha . ': a conta informada não tem permissão de API.', 'enviado' => $enviado];
        }

        $dados = $this->parse($resposta['raw']);

        // Só `error=0` é sucesso — mesmo critério da suspensão. O DirectAdmin
        // responde HTTP 200 para erro de negócio, então formato não
        // reconhecido é falha, nunca "deu certo".
        if (!is_array($dados) || !isset($dados['error'])) {
            $bruto = trim((string) $resposta['raw']);
            return [
                'success' => FALSE,
                'message' => $falha . ': resposta em formato não reconhecido'
                    . ($bruto !== '' ? ' (' . mb_substr($bruto, 0, 120) . ')' : '') . '.',
                'enviado' => $enviado,
            ];
        }

        if ((string) $dados['error'] !== '0') {
            $texto = isset($dados['text']) ? trim(strip_tags((string) $dados['text'])) : '';
            $detalhe = isset($dados['details']) ? trim(strip_tags((string) $dados['details'])) : '';
            $mensagem = trim($texto . ($detalhe !== '' ? ' — ' . $detalhe : ''));

            return ['success' => FALSE, 'message' => $falha . ($mensagem !== '' ? ': ' . $mensagem : '.'), 'enviado' => $enviado];
        }

        // Confirmação positiva: relê e compara. O `error=0` diz que o formulário
        // foi aceito, não que a cota é a pedida — e como este é o painel em que
        // um campo omitido some em silêncio, confirmar é o que separa "gravou" de
        // "respondeu bonito". Falha na releitura não derruba o sucesso: a
        // alteração foi aceita, e a sincronização confere depois.
        $depois = $this->configCruDoUsuario($config, $conta);
        if (is_array($depois) && isset($depois['quota'])) {
            $gravada = mb_strtolower(trim((string) $depois['quota'])) === 'unlimited' ? 0 : (int) $depois['quota'];
            if ($gravada !== $cota) {
                return [
                    'success' => FALSE,
                    'message' => $falha . ': o painel aceitou a alteração mas manteve a cota em '
                        . ($gravada === 0 ? 'ilimitada' : $gravada . ' MB') . '.',
                    'enviado' => $enviado,
                ];
            }
        }

        return ['success' => TRUE, 'message' => isset($dados['text']) ? trim((string) $dados['text']) : ''];
    }

    /**
     * Config cru do usuário, sem o recorte de `configDoUsuario()`.
     *
     * A sincronização só quer cinco campos; o modify precisa do formulário
     * inteiro de volta, inclusive as chaves que este código não interpreta.
     *
     * @param  array  $config
     * @param  string $usuario
     * @return array|bool pares chave => valor, ou FALSE
     */
    private function configCruDoUsuario($config, $usuario)
    {
        $resposta = $this->request($config, '/CMD_API_SHOW_USER_CONFIG?user=' . rawurlencode($usuario));
        if (empty($resposta['success'])) return FALSE;

        $dados = $this->parse($resposta['raw']);
        if (!is_array($dados) || !isset($dados['domain'])) return FALSE;

        return $dados;
    }

    /**
     * Monta o corpo do CMD_API_MODIFY_USER preservando o cadastro atual.
     *
     * @param  array  $atual   config cru vindo do SHOW_USER_CONFIG
     * @param  string $usuario
     * @param  int    $cotaMb  0 = ilimitado
     * @return array
     */
    private function montarModifyUser(array $atual, $usuario, $cotaMb)
    {
        $post = [
            // `customize` é o que aplica valores individuais. Com `single` o
            // DirectAdmin responde `error=0` e NÃO grava — foi assim que a
            // primeira versão passou no teste e deixou a conta ilimitada; quem
            // pegou isso foi a releitura de confirmação, logo abaixo.
            'action' => 'customize',
            'user' => $usuario,
        ];

        foreach ($atual as $chave => $valor) {
            if (is_array($valor)) continue;
            if (in_array($chave, self::CAMPOS_NAO_REENVIADOS, TRUE)) continue;

            $post[$chave] = (string) $valor;
        }

        // `unlimited` no campo numérico vira o checkbox irmão ligado. Sem isso o
        // modify grava zero e o cliente perde de uma vez o que era ilimitado.
        foreach (self::CAMPOS_ILIMITADOS as $campo => $checkbox) {
            if (!isset($post[$campo])) continue;

            if (mb_strtolower(trim($post[$campo])) === 'unlimited') {
                $post[$campo] = '0';
                $post[$checkbox] = 'ON';
            }
        }

        // A cota pedida vence o que veio da releitura.
        unset($post['uquota']);
        if ($cotaMb === 0) {
            $post['quota'] = '0';
            $post['uquota'] = 'ON';
        } else {
            $post['quota'] = (string) $cotaMb;
        }

        return $post;
    }

    /**
     * Suspende ou reativa um USUÁRIO do DirectAdmin.
     *
     * Como no WHM, a unidade é a conta inteira (todos os domínios do usuário
     * caem juntos) — quem pondera isso é o Server_model.
     *
     * O endpoint é o mesmo da tela de listagem do painel
     * (CMD_SELECT_USERS): `select0` é o usuário e `suspend` diz a direção. Vai
     * por POST porque é ação, não consulta.
     *
     * @param  array  $config
     * @param  string $usuario
     * @param  bool   $suspender TRUE suspende, FALSE reativa
     * @return array  success, message
     */
    public function setSuspension($config, $usuario, $suspender)
    {
        $conta = trim((string) $usuario);
        if ($conta === '') {
            return ['success' => FALSE, 'message' => 'Usuário do DirectAdmin não informado.'];
        }

        $resposta = $this->request($config, '/CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => $suspender ? 'Suspend' : 'Unsuspend',
            'select0' => $conta,
        ]);

        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'message' => $resposta['message']];
        }

        $falha = $suspender ? 'Falha ao suspender o usuário no DirectAdmin' : 'Falha ao reativar o usuário no DirectAdmin';

        if (stripos($resposta['raw'], '<html') !== FALSE) {
            return ['success' => FALSE, 'message' => $falha . ': a conta informada não tem permissão de API.'];
        }

        $dados = $this->parse($resposta['raw']);

        // Só `error=0` é sucesso. O DirectAdmin responde HTTP 200 para erro de
        // negócio (usuário inexistente, sem permissão), então tratar formato
        // não reconhecido como sucesso deixaria a conta no ar sem ninguém
        // saber.
        if (is_array($dados) && isset($dados['error'])) {
            if ((string) $dados['error'] === '0') {
                return ['success' => TRUE, 'message' => isset($dados['text']) ? trim((string) $dados['text']) : ''];
            }

            $texto = isset($dados['text']) ? trim(strip_tags((string) $dados['text'])) : '';
            $detalhe = isset($dados['details']) ? trim(strip_tags((string) $dados['details'])) : '';
            $mensagem = trim($texto . ($detalhe !== '' ? ' — ' . $detalhe : ''));

            return ['success' => FALSE, 'message' => $falha . ($mensagem !== '' ? ': ' . $mensagem : '.')];
        }

        $bruto = trim((string) $resposta['raw']);
        return [
            'success' => FALSE,
            'message' => $falha . ': resposta em formato não reconhecido' . ($bruto !== '' ? ' (' . mb_substr($bruto, 0, 120) . ')' : '') . '.',
        ];
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
     * GET (ou POST, quando `$post` vem preenchido) autenticado. Devolve o corpo
     * cru — o parsing é do chamador, porque o formato varia por endpoint.
     *
     * @param  array      $config
     * @param  string     $endpoint
     * @param  array|null $post corpo do POST; NULL = GET
     * @return array      success, raw, message
     */
    private function request($config, $endpoint, $post = NULL)
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

        if (is_array($post)) {
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

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
