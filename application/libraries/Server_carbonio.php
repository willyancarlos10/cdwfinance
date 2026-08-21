<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Integração com a API administrativa do Carbonio (Zextras), servidor de e-mail.
 *
 * Diferente dos outros três painéis, aqui não há hospedagem de site: o Carbonio
 * só entra no CDW Finance para (a) trazer os domínios que hospeda, de modo que
 * possam ser vinculados a contratos, e (b) parar/retomar o serviço desses
 * domínios junto com o contrato. Gestão de caixa de e-mail (criar, excluir,
 * senha, quota) é assunto do GestorCMS v3 e não passa por aqui.
 *
 * Transporte: SOAP no dialeto JSON, nunca XML, num POST único para
 *
 *   {esquema}://{host}:{porta}/service/admin/soap
 *
 * O envelope é {"Header":{"context":{"_jsns":"urn:zimbra"}},
 *               "Body":{"<Cmd>Request":{...,"_jsns":"urn:zimbraAdmin"}}}
 *
 * A porta padrão é 6071 — a do Carbonio. A 7071 é do Zimbra e não responde
 * aqui. O campo `port` do cadastro é usado quando informado.
 *
 * Autenticação em duas etapas: o AuthRequest é a única chamada sem token e
 * devolve um authToken que passa a viajar no contexto do header. O token é
 * cacheado POR CONEXÃO na instância — o laço de suspensão pode rodar até 240s
 * e autenticar a cada domínio seria uma chamada a mais por domínio.
 *
 * A UNIDADE de suspensão é o DOMÍNIO (zimbraDomainStatus), e não a conta como
 * no WHM/DirectAdmin nem o vhost como no CloudPanel. É uma chamada só por
 * domínio, atômica, e a caixa criada depois da suspensão continua bloqueada —
 * o que não aconteceria suspendendo conta a conta.
 *
 * O status aplicado é 'suspended': bloqueia o login e RETÉM as mensagens que
 * chegarem, entregues na reativação. 'closed' devolveria as mensagens ao
 * remetente (perda) e 'locked' continuaria entregando (sem efeito de cobrança).
 *
 * Como os outros painéis desta pasta, NUNCA lança exceção: toda falha volta em
 * array. E, também como eles, resposta em formato não reconhecido é FALHA,
 * nunca sucesso — o Carbonio responde erro de negócio com HTTP 500 e um proxy
 * na porta do painel responde HTTP 200 com HTML; tratar qualquer um dos dois
 * como sucesso deixaria o serviço no ar com o contrato encerrado.
 */
class Server_carbonio
{
    /** Porta da API administrativa do Carbonio. */
    const PORT = 6071;

    /** Caminho do endpoint SOAP administrativo. */
    const CAMINHO = '/service/admin/soap';

    /** Sem login e mensagens retidas até a reativação. */
    const STATUS_SUSPENSO = 'suspended';

    /** Estado normal do domínio. */
    const STATUS_ATIVO = 'active';

    /** Trecho da resposta registrado no log em caso de falha. */
    const LOG_BODY_CHARS = 240;

    /**
     * Contas de serviço do próprio Carbonio: moram nos domínios mas não são
     * caixas de usuário e não devem entrar na soma de disco do cliente.
     *
     * O GetQuotaUsage devolve só nome/id/uso/limite — não traz o
     * zimbraIsSystemAccount que o GestorCMS v3 usa na listagem de caixas. Ler a
     * flag exigiria um SearchDirectory sobre TODOS os domínios, que esbarra no
     * zimbraDirectoryMaxSearchResults do servidor. Estes prefixos são
     * reservados pelo produto e estáveis, então o recorte pelo nome resolve sem
     * a chamada extra nem o teto de resultados.
     */
    const CONTAS_DE_SISTEMA = ['galsync', 'ham', 'spam', 'virus-quarantine'];

    /** authToken por conexão (host|porta|usuário). A instância vive uma requisição. */
    private $tokens = [];

    /** Esquema fixado por conexão depois de um fallback http/https bem-sucedido. */
    private $esquemas = [];

    // -------------------------------------------------------------------------
    // Interface do módulo de Servidores
    // -------------------------------------------------------------------------

    /**
     * Testa a conexão.
     *
     * A prova de vida é o GetAllDomains — a mesma chamada de que a
     * sincronização depende. Autenticar sozinho provaria só a credencial, e uma
     * conta sem permissão administrativa passaria no teste para falhar depois,
     * na sincronização. A versão é conveniência: falha ali não reprova o teste.
     *
     * @param  array $config host, port, username, secret, verify_ssl, timeout_seconds
     * @return array success, message, version, response_ms
     */
    public function test($config)
    {
        $inicio = microtime(TRUE);
        $resposta = $this->chamar($config, 'GetAllDomains', []);
        $ms = (int) round((microtime(TRUE) - $inicio) * 1000);

        if (empty($resposta['success'])) {
            return [
                'success' => FALSE,
                'message' => $resposta['message'],
                'version' => NULL,
                'response_ms' => $ms,
            ];
        }

        $total = count($this->asList($resposta['data'], 'domain'));
        $versao = $this->versao($config);

        return [
            'success' => TRUE,
            'message' => 'Conexão com o Carbonio estabelecida'
                . ($versao !== NULL ? ' (versão ' . $versao . ')' : '')
                . ' — ' . $total . ' domínio(s) visível(is).',
            'version' => $versao,
            'response_ms' => $ms,
        ];
    }

    /**
     * Lista os domínios do servidor já no formato do sincronizador.
     *
     * São duas chamadas: o GetAllDomains traz os domínios e o GetQuotaUsage
     * traz o uso de TODAS as caixas do servidor de uma vez (sem o parâmetro
     * `domain`), somado por domínio aqui. Uma chamada por domínio seria N
     * requisições contra um painel que não tem por que pagá-las.
     *
     * @param  array $config
     * @return array domains, complete, message
     */
    public function listDomains($config)
    {
        $resposta = $this->chamar($config, 'GetAllDomains', []);
        if (empty($resposta['success'])) {
            return ['domains' => [], 'complete' => FALSE, 'message' => $resposta['message']];
        }

        $dominios = [];
        foreach ($this->asList($resposta['data'], 'domain') as $dominio) {
            $nome = isset($dominio['name']) ? mb_strtolower(trim((string) $dominio['name'])) : '';
            if ($nome === '') continue;

            $atributos = $this->attrsToMap($dominio);
            $estado = isset($atributos['zimbraDomainStatus'])
                ? mb_strtolower(trim((string) $atributos['zimbraDomainStatus']))
                : self::STATUS_ATIVO;

            $dominios[$nome] = [
                'domain' => $nome,
                // Qualquer estado que não seja 'active' (suspended, closed,
                // locked, maintenance, shutdown) significa serviço parado.
                'status' => ($estado === self::STATUS_ATIVO) ? 'ativo' : 'suspenso',
                'source' => 'carbonio',
            ];
        }

        if (empty($dominios)) {
            // Lista autoritativa e vazia: o Server_model só poda quando também
            // veio conteúdo, então isto não apaga nada.
            return [
                'domains' => [],
                'complete' => TRUE,
                'message' => 'Nenhum domínio cadastrado no Carbonio.',
            ];
        }

        $uso = $this->usoPorDominio($config);
        if (!empty($uso['success'])) {
            foreach ($dominios as $nome => $item) {
                // Domínio sem caixa não aparece no GetQuotaUsage e usa zero.
                $bytes = isset($uso['uso'][$nome]) ? $uso['uso'][$nome] : 0.0;
                $dominios[$nome]['disk_used_mb'] = round($bytes / 1048576, 2);
            }
        }

        return [
            'domains' => array_values($dominios),
            // O GetAllDomains é autoritativo, então a poda é liberada. A falha
            // do uso de disco não muda isso: a lista de domínios continua
            // completa, só o disco fica de fora desta rodada (e a chave
            // omitida faz o upsert preservar o valor anterior).
            'complete' => TRUE,
            'message' => empty($uso['success'])
                ? 'Domínios sincronizados; o uso de disco não pôde ser lido: ' . $uso['message']
                : '',
        ];
    }

    /**
     * Suspende ou reativa um DOMÍNIO do Carbonio.
     *
     * O GetDomain é obrigatório porque o ModifyDomain opera por zimbraId, não
     * por nome — e, de quebra, devolve o estado atual, o que permite responder
     * "já estava assim" sem gravar. Isso importa: quando a suspensão de um
     * contrato falha no meio, a repetição passa de novo por todos os domínios,
     * e os que já foram aplicados precisam convergir em vez de errar.
     *
     * @param  array  $config
     * @param  string $dominio
     * @param  bool   $suspender TRUE suspende, FALSE reativa
     * @return array  success, message, already (TRUE quando já estava no estado pedido)
     */
    public function setSuspension($config, $dominio, $suspender)
    {
        $alvo = mb_strtolower(trim((string) $dominio));
        if ($alvo === '' || !preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $alvo)) {
            return [
                'success' => FALSE,
                'message' => 'Domínio inválido para a operação no Carbonio.',
                'already' => FALSE,
            ];
        }

        $atual = $this->obterDominio($config, $alvo);
        if (empty($atual['success'])) {
            return ['success' => FALSE, 'message' => $atual['message'], 'already' => FALSE];
        }

        // Para suspender, "já estava" é QUALQUER estado diferente de ativo: o
        // domínio já não serve, e reescrever um 'closed' para 'suspended'
        // afrouxaria a parada em vez de confirmá-la.
        $jaEsta = $suspender
            ? ($atual['status'] !== self::STATUS_ATIVO)
            : ($atual['status'] === self::STATUS_ATIVO);

        if ($jaEsta) {
            return [
                'success' => TRUE,
                'message' => 'O domínio já estava ' . ($suspender ? 'suspenso' : 'ativo') . ' no Carbonio.',
                'already' => TRUE,
            ];
        }

        $desejado = $suspender ? self::STATUS_SUSPENSO : self::STATUS_ATIVO;
        $resposta = $this->chamar($config, 'ModifyDomain', [
            'id' => $atual['id'],
            'a' => [$this->attr('zimbraDomainStatus', $desejado)],
        ]);

        if (empty($resposta['success'])) {
            $falha = $suspender
                ? 'Falha ao suspender o domínio no Carbonio'
                : 'Falha ao reativar o domínio no Carbonio';
            return [
                'success' => FALSE,
                'message' => $falha . ': ' . $resposta['message'],
                'already' => FALSE,
            ];
        }

        return [
            'success' => TRUE,
            'message' => $suspender
                ? 'Domínio suspenso no Carbonio (mensagens retidas até a reativação).'
                : 'Domínio reativado no Carbonio.',
            'already' => FALSE,
        ];
    }

    // -------------------------------------------------------------------------
    // Operações auxiliares
    // -------------------------------------------------------------------------

    /**
     * Versão do Carbonio, só para o cartão de teste. Falha aqui é silenciosa —
     * não saber a versão não invalida uma conexão que funciona.
     *
     * @param  array $config
     * @return string|null
     */
    private function versao($config)
    {
        $resposta = $this->chamar($config, 'GetVersionInfo', []);
        if (empty($resposta['success'])) {
            return NULL;
        }

        foreach ($this->asList($resposta['data'], 'info') as $info) {
            if (!empty($info['version'])) {
                return (string) $info['version'];
            }
        }

        return NULL;
    }

    /**
     * Uso em bytes de todas as caixas do servidor, somado por domínio.
     *
     * @param  array $config
     * @return array success, uso (dominio => bytes), message
     */
    private function usoPorDominio($config)
    {
        $resposta = $this->chamar($config, 'GetQuotaUsage', [
            // Sem `domain`: traz o servidor inteiro numa chamada só.
            'allServers' => 1, // instalações com mais de um mailbox server
            'limit' => 0,      // 0 = todas
            'offset' => 0,
        ]);

        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'uso' => [], 'message' => $resposta['message']];
        }

        $uso = [];
        foreach ($this->asList($resposta['data'], 'account') as $conta) {
            $email = isset($conta['name']) ? mb_strtolower(trim((string) $conta['name'])) : '';
            $arroba = strrpos($email, '@');
            if ($email === '' || $arroba === FALSE) continue;

            $local = substr($email, 0, $arroba);
            $dominio = substr($email, $arroba + 1);
            if ($dominio === '' || $this->ehContaDeSistema($local)) continue;

            if (!isset($uso[$dominio])) {
                $uso[$dominio] = 0.0;
            }
            $uso[$dominio] += isset($conta['used']) ? (float) $conta['used'] : 0.0;
        }

        return ['success' => TRUE, 'uso' => $uso, 'message' => ''];
    }

    /**
     * zimbraId e estado atual do domínio.
     *
     * Sem `attrs` de propósito: a resposta é de um domínio só, e limitar os
     * atributos arriscaria não receber o zimbraDomainStatus.
     *
     * @param  array  $config
     * @param  string $dominio
     * @return array  success, id, status, message
     */
    private function obterDominio($config, $dominio)
    {
        $resposta = $this->chamar($config, 'GetDomain', [
            'domain' => ['by' => 'name', '_content' => $dominio],
        ]);

        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'id' => '', 'status' => '', 'message' => $resposta['message']];
        }

        foreach ($this->asList($resposta['data'], 'domain') as $item) {
            if (empty($item['id'])) continue;

            $atributos = $this->attrsToMap($item);
            $estado = isset($atributos['zimbraDomainStatus'])
                ? mb_strtolower(trim((string) $atributos['zimbraDomainStatus']))
                : self::STATUS_ATIVO;

            return [
                'success' => TRUE,
                'id' => (string) $item['id'],
                'status' => $estado,
                'message' => '',
            ];
        }

        return [
            'success' => FALSE,
            'id' => '',
            'status' => '',
            'message' => 'Domínio não encontrado no Carbonio.',
        ];
    }

    /** Local part reservado pelo Carbonio (galsync, ham.xxx, spam.xxx, virus-quarantine.xxx). */
    private function ehContaDeSistema($local)
    {
        foreach (self::CONTAS_DE_SISTEMA as $reservado) {
            if ($local === $reservado || strpos($local, $reservado . '.') === 0) {
                return TRUE;
            }
        }
        return FALSE;
    }

    // -------------------------------------------------------------------------
    // Transporte
    // -------------------------------------------------------------------------

    /**
     * Chamada autenticada, com uma repetição quando o token vence no meio.
     *
     * @param  array  $config
     * @param  string $comando sem o sufixo "Request"
     * @param  array  $payload
     * @return array  success, data, message
     */
    private function chamar($config, $comando, array $payload)
    {
        $autenticacao = $this->autenticar($config);
        if (empty($autenticacao['success'])) {
            return ['success' => FALSE, 'data' => NULL, 'message' => $autenticacao['message']];
        }

        $resposta = $this->soap($config, $comando, $payload, $autenticacao['token']);

        // O laço de suspensão roda até 240s e o token tem validade própria:
        // vencendo no meio, descarta e tenta UMA vez com credencial nova.
        if (empty($resposta['success']) && !empty($resposta['auth_expired'])) {
            unset($this->tokens[$this->chaveConexao($config)]);

            $autenticacao = $this->autenticar($config);
            if (empty($autenticacao['success'])) {
                return ['success' => FALSE, 'data' => NULL, 'message' => $autenticacao['message']];
            }
            $resposta = $this->soap($config, $comando, $payload, $autenticacao['token']);
        }

        return $resposta;
    }

    /**
     * Garante um authToken para a conexão, reaproveitando o já obtido.
     *
     * @param  array $config
     * @return array success, token, message
     */
    private function autenticar($config)
    {
        $chave = $this->chaveConexao($config);
        if (isset($this->tokens[$chave])) {
            return ['success' => TRUE, 'token' => $this->tokens[$chave], 'message' => ''];
        }

        $host = $this->host($config);
        $usuario = isset($config['username']) ? trim((string) $config['username']) : '';
        $senha = isset($config['secret']) ? (string) $config['secret'] : '';

        if ($host === '' || $usuario === '' || $senha === '') {
            return [
                'success' => FALSE,
                'token' => '',
                'message' => 'Cadastro do Carbonio incompleto: endereço, usuário administrador e senha são obrigatórios.',
            ];
        }

        // O AuthRequest é a única chamada que não leva token no contexto.
        $resposta = $this->soap($config, 'Auth', [
            'name' => ['_content' => $usuario],
            'password' => ['_content' => $senha],
        ], NULL);

        if (empty($resposta['success'])) {
            return ['success' => FALSE, 'token' => '', 'message' => $resposta['message']];
        }

        $token = '';
        foreach ($this->asList($resposta['data'], 'authToken') as $item) {
            if (!empty($item['_content'])) {
                $token = (string) $item['_content'];
                break;
            }
        }
        if ($token === '' && !empty($resposta['data']['authToken']) && is_string($resposta['data']['authToken'])) {
            $token = (string) $resposta['data']['authToken'];
        }

        if ($token === '') {
            return [
                'success' => FALSE,
                'token' => '',
                'message' => 'O Carbonio não devolveu um token de autenticação.',
            ];
        }

        $this->tokens[$chave] = $token;

        return ['success' => TRUE, 'token' => $token, 'message' => ''];
    }

    /**
     * Monta o envelope, envia e devolve o corpo já validado.
     *
     * @param  array       $config
     * @param  string      $comando
     * @param  array       $payload
     * @param  string|null $token NULL só no AuthRequest
     * @return array       success, data, message, auth_expired
     */
    private function soap($config, $comando, array $payload, $token)
    {
        $contexto = ['_jsns' => 'urn:zimbra'];
        if ($token !== NULL) {
            $contexto['authToken'] = $token;
        }
        $payload['_jsns'] = 'urn:zimbraAdmin';

        $envelope = json_encode([
            'Header' => ['context' => $contexto],
            'Body' => [$comando . 'Request' => $payload],
        ]);

        if ($envelope === FALSE) {
            return [
                'success' => FALSE,
                'data' => NULL,
                'message' => 'Não foi possível montar a requisição do Carbonio (dados com codificação inválida).',
                'auth_expired' => FALSE,
            ];
        }

        $esquema = $this->esquema($config);
        $tentativa = $this->request($config, $envelope, $esquema);

        // Sem esquema explícito no cadastro, uma falha de transporte pode ser
        // só o esquema errado (painel atendendo em HTTP puro na porta da API).
        if ($tentativa['errno'] && !$this->esquemaExplicito($config) && $this->isTransportErrno($tentativa['errno'])) {
            $alternativo = ($esquema === 'https') ? 'http' : 'https';
            $retentativa = $this->request($config, $envelope, $alternativo);
            if (!$retentativa['errno']) {
                $this->esquemas[$this->chaveConexao($config)] = $alternativo; // fixa para as próximas
                $tentativa = $retentativa;
            }
        }

        if ($tentativa['errno']) {
            $this->logFalha($config, 'falha de transporte', $comando, $tentativa);
            return [
                'success' => FALSE,
                'data' => NULL,
                'message' => $this->mensagemErroCurl($tentativa['errno'], $tentativa['error']),
                'auth_expired' => FALSE,
            ];
        }

        return $this->decode($config, $comando, $tentativa);
    }

    /**
     * POST cru no endpoint SOAP. Nunca lança e nunca interpreta o corpo.
     *
     * @param  array  $config
     * @param  string $envelope
     * @param  string $esquema
     * @return array  body, errno, error, http, scheme
     */
    private function request($config, $envelope, $esquema)
    {
        $url = $esquema . '://' . $this->host($config) . ':' . $this->porta($config) . self::CAMINHO;

        $timeout = isset($config['timeout_seconds']) ? (int) $config['timeout_seconds'] : 30;
        if ($timeout <= 0) $timeout = 30;
        $verificaSsl = !empty($config['verify_ssl']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            // Painel com certificado auto-assinado é a norma; quem decide é o cadastro.
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

        return [
            'body' => ($corpo === FALSE) ? '' : (string) $corpo,
            'errno' => $erroNumero,
            'error' => $erroTexto,
            'http' => $status,
            'scheme' => $esquema,
        ];
    }

    /**
     * Valida a resposta, nesta ordem: HTML -> JSON -> Fault -> HTTP -> Response.
     *
     * O Fault vem ANTES do código HTTP porque o Carbonio devolve erro de
     * negócio com HTTP 500 — olhar o status primeiro esconderia a mensagem que
     * diz o que de fato aconteceu.
     *
     * @param  array  $config
     * @param  string $comando
     * @param  array  $tentativa
     * @return array  success, data, message, auth_expired
     */
    private function decode($config, $comando, $tentativa)
    {
        $corpo = $tentativa['body'];

        // Proxy/WAF na porta da API devolvendo página de desafio com HTTP 200.
        if ($this->looksLikeHtml($corpo)) {
            $this->logFalha($config, 'resposta HTML em vez de SOAP', $comando, $tentativa);
            return $this->falha('O Carbonio respondeu com uma página HTML em vez da API — normalmente é um '
                . 'proxy/WAF na porta administrativa. Libere o IP deste servidor no Carbonio.');
        }

        $decodificado = json_decode((string) $corpo, TRUE);
        if (!is_array($decodificado) || !isset($decodificado['Body']) || !is_array($decodificado['Body'])) {
            $this->logFalha($config, 'payload fora do formato SOAP', $comando, $tentativa);
            return $this->falha('Resposta inválida da API do Carbonio (não é o envelope SOAP esperado).');
        }

        if (isset($decodificado['Body']['Fault'])) {
            $fault = is_array($decodificado['Body']['Fault']) ? $decodificado['Body']['Fault'] : [];
            $codigo = isset($fault['Detail']['Error']['Code']) ? (string) $fault['Detail']['Error']['Code'] : '';

            return [
                'success' => FALSE,
                'data' => NULL,
                'message' => $this->faultMessage($config, $fault, $codigo, $comando, $tentativa),
                // Só o token vencido justifica reautenticar: credencial recusada
                // repetiria a mesma falha, e mais devagar.
                'auth_expired' => in_array($codigo, ['service.AUTH_EXPIRED', 'service.AUTH_REQUIRED'], TRUE),
            ];
        }

        if ($tentativa['http'] === 401 || $tentativa['http'] === 403) {
            return $this->falha('Credenciais inválidas no Carbonio (HTTP ' . $tentativa['http']
                . '). Confira o usuário administrador e a senha.');
        }
        if ($tentativa['http'] < 200 || $tentativa['http'] >= 300) {
            $this->logFalha($config, 'HTTP fora de 2xx', $comando, $tentativa);
            return $this->falha('O Carbonio respondeu com HTTP ' . $tentativa['http'] . '.');
        }

        // Sem o Response esperado a operação NÃO foi confirmada. Tratar formato
        // desconhecido como sucesso deixaria o domínio no ar com o contrato
        // encerrado — que é exatamente o caso que a suspensão existe para evitar.
        $chave = $comando . 'Response';
        if (!isset($decodificado['Body'][$chave]) || !is_array($decodificado['Body'][$chave])) {
            $this->logFalha($config, 'resposta sem ' . $chave, $comando, $tentativa);
            return $this->falha('O Carbonio não confirmou a operação. Verifique no painel antes de repetir.');
        }

        return [
            'success' => TRUE,
            'data' => $decodificado['Body'][$chave],
            'message' => '',
            'auth_expired' => FALSE,
        ];
    }

    /**
     * Traduz o Fault. O código do Carbonio é estável (o texto não), então a
     * mensagem amigável é escolhida por ele.
     */
    private function faultMessage($config, array $fault, $codigo, $comando, $tentativa)
    {
        $razao = '';
        if (isset($fault['Reason']['Text'])) {
            $razao = trim(strip_tags((string) $fault['Reason']['Text']));
        }

        switch ($codigo) {
            case 'account.AUTH_FAILED':
            case 'service.AUTH_FAILED':
            case 'service.AUTH_REQUIRED':
            case 'service.AUTH_EXPIRED':
                return 'Credenciais inválidas no Carbonio. Confira o usuário administrador e a senha.';
            case 'service.PERM_DENIED':
                return 'O usuário informado não tem permissão administrativa no Carbonio para esta operação.';
            case 'account.NO_SUCH_DOMAIN':
                return 'Domínio não encontrado no Carbonio.';
            case 'account.NO_SUCH_ACCOUNT':
                return 'Conta não encontrada no Carbonio.';
            case 'account.INVALID_ATTR_VALUE':
            case 'service.INVALID_REQUEST':
                return 'O Carbonio recusou os dados enviados' . ($razao !== '' ? ': ' . $razao : '.');
        }

        $this->logFalha($config, 'fault ' . ($codigo !== '' ? $codigo : 'sem codigo'), $comando, $tentativa);

        return $razao !== ''
            ? 'O Carbonio recusou a operação: ' . $razao
            : 'O Carbonio recusou a operação.';
    }

    // -------------------------------------------------------------------------
    // Endereço, normalização e log
    // -------------------------------------------------------------------------

    /** Identidade da conexão, para cachear token e esquema sem misturar servidores. */
    private function chaveConexao($config)
    {
        return $this->host($config) . '|' . $this->porta($config) . '|'
            . (isset($config['username']) ? trim((string) $config['username']) : '');
    }

    /** Só o host: descarta esquema, caminho e porta digitados no endereço. */
    private function host($config)
    {
        $valor = isset($config['host']) ? trim((string) $config['host']) : '';
        $valor = preg_replace('#^https?://#i', '', $valor);
        $valor = preg_replace('#/.*$#', '', $valor);
        $valor = preg_replace('#:\d+$#', '', $valor);
        return $valor;
    }

    /** Porta do cadastro; senão a digitada no endereço (como no DirectAdmin); senão 6071. */
    private function porta($config)
    {
        $porta = isset($config['port']) ? (int) $config['port'] : 0;

        if ($porta <= 0) {
            $bruto = isset($config['host']) ? trim((string) $config['host']) : '';
            $bruto = preg_replace('#^https?://#i', '', $bruto);
            $bruto = preg_replace('#/.*$#', '', $bruto);
            if (preg_match('#:(\d+)$#', $bruto, $m)) {
                $porta = (int) $m[1];
            }
        }

        return ($porta > 0 && $porta <= 65535) ? $porta : self::PORT;
    }

    /** TRUE quando o cadastro trouxe http:// ou https:// — aí não há fallback. */
    private function esquemaExplicito($config)
    {
        $valor = isset($config['host']) ? trim((string) $config['host']) : '';
        return (bool) preg_match('#^https?://#i', $valor);
    }

    /** Esquema a usar: o já fixado por um fallback, o explícito do cadastro, ou https. */
    private function esquema($config)
    {
        $chave = $this->chaveConexao($config);
        if (isset($this->esquemas[$chave])) {
            return $this->esquemas[$chave];
        }

        $valor = isset($config['host']) ? trim((string) $config['host']) : '';
        if (preg_match('#^(https?)://#i', $valor, $m)) {
            return mb_strtolower($m[1]);
        }

        return 'https';
    }

    /** Atalho para o array de falha do decode(). */
    private function falha($mensagem)
    {
        return ['success' => FALSE, 'data' => NULL, 'message' => $mensagem, 'auth_expired' => FALSE];
    }

    /** Monta o par {n, _content} que o Carbonio usa para atributos. */
    private function attr($nome, $valor)
    {
        return ['n' => $nome, '_content' => (string) $valor];
    }

    /**
     * O array "a" vem como lista de {n, _content}; atributo multivalorado
     * repete o mesmo "n" (fica o último, que basta aqui).
     */
    private function attrsToMap($item)
    {
        $mapa = [];
        if (empty($item['a']) || !is_array($item['a'])) {
            return $mapa;
        }
        foreach ($item['a'] as $atributo) {
            if (is_array($atributo) && isset($atributo['n'])) {
                $mapa[$atributo['n']] = isset($atributo['_content']) ? $atributo['_content'] : '';
            }
        }
        return $mapa;
    }

    /**
     * O SOAP JSON devolve elementos repetidos como array, mas alguns servidores
     * colapsam um único elemento em objeto — daí a normalização.
     */
    private function asList($resposta, $chave)
    {
        if (empty($resposta[$chave]) || !is_array($resposta[$chave])) {
            return [];
        }

        $valor = $resposta[$chave];
        // Chaves associativas = objeto único; vira lista de um item.
        if (array_keys($valor) !== range(0, count($valor) - 1)) {
            return [$valor];
        }
        return $valor;
    }

    private function looksLikeHtml($corpo)
    {
        $texto = ltrim((string) $corpo);
        if ($texto === '') {
            return FALSE;
        }
        return stripos($texto, '<!doctype') === 0
            || stripos($texto, '<html') === 0
            || (stripos($texto, '<?xml') === 0 && stripos($texto, '<html') !== FALSE);
    }

    /** Erros de cURL anteriores à resposta — seguros para tentar o outro esquema. */
    private function isTransportErrno($errno)
    {
        return in_array((int) $errno, [
            CURLE_UNSUPPORTED_PROTOCOL, // 1
            CURLE_COULDNT_CONNECT,      // 7
            CURLE_SSL_CONNECT_ERROR,    // 35
            CURLE_GOT_NOTHING,          // 52
            CURLE_RECV_ERROR,           // 56
            CURLE_SSL_CIPHER,           // 59
        ], TRUE);
    }

    /** Nunca registra o payload: ele carrega a senha do administrador e o authToken. */
    private function logFalha($config, $contexto, $comando, $tentativa)
    {
        $corpo = preg_replace('/\s+/', ' ', mb_substr((string) $tentativa['body'], 0, self::LOG_BODY_CHARS));

        log_message('error', '[CARBONIO] ' . $contexto
            . ' cmd=' . $comando
            . ' url=' . $tentativa['scheme'] . '://' . $this->host($config) . ':' . $this->porta($config) . self::CAMINHO
            . ' login=' . (isset($config['username']) ? $config['username'] : '')
            . ' http=' . $tentativa['http']
            . ' errno=' . $tentativa['errno']
            . ' resp="' . trim((string) $corpo) . '"');
    }

    private function mensagemErroCurl($numero, $texto)
    {
        switch ((int) $numero) {
            case CURLE_OPERATION_TIMEOUTED:
                return 'Tempo limite excedido ao conectar no Carbonio.';
            case CURLE_COULDNT_CONNECT:
                return 'Conexão recusada pelo Carbonio (verifique se a porta administrativa, '
                    . self::PORT . ' por padrão, está liberada).';
            case CURLE_COULDNT_RESOLVE_HOST:
                return 'Host do Carbonio não encontrado.';
            case CURLE_SSL_CACERT:
            case CURLE_SSL_PEER_CERTIFICATE:
            case CURLE_SSL_CONNECT_ERROR:
            case CURLE_SSL_CIPHER:
                return 'Erro de SSL ao conectar no Carbonio. Se o certificado é auto-assinado, desmarque '
                    . '"Verificar SSL" no cadastro; se a API responde em HTTP puro, cadastre o endereço '
                    . 'como http://servidor.';
            default:
                return 'Falha ao conectar no Carbonio: ' . $texto;
        }
    }
}
