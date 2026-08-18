<?php

defined('BASEPATH') or exit('No direct script access allowed');

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Math\BigInteger;
use phpseclib3\Net\SSH2;

/**
 * Integração com o CloudPanel.
 *
 * O CloudPanel não expõe API HTTP: tudo é feito por SSH. Como o PHP deste
 * ambiente não tem a extensão `ssh2`, usamos phpseclib3 (já no vendor, e o
 * autoload do Composer é carregado em index.php).
 *
 * A listagem de domínios não usa `clpctl site:list` — ela varre
 * /home/<usuario>/htdocs/<dominio>/, que é onde o CloudPanel coloca os sites, e
 * mede o disco com `du -sm`. O status vem da presença do vhost em
 * /etc/nginx/sites-suspended: sem isso um site suspenso apareceria como ativo.
 *
 * O usuário SSH precisa conseguir ler /home de todos os sites (na prática,
 * root).
 */
class Server_cloudpanel
{
    /** Porta SSH padrão quando o cadastro não informa outra. */
    const PORT = 22;

    /** Onde o CloudPanel guarda os vhosts de sites suspensos. */
    const NGINX_SUSPENDED_DIR = '/etc/nginx/sites-suspended';

    /** Onde ficam os vhosts dos sites no ar. */
    const NGINX_ENABLED_DIR = '/etc/nginx/sites-enabled';

    /** Piso e teto do timeout da varredura, em segundos. */
    const SCAN_TIMEOUT_MIN = 120;
    const SCAN_TIMEOUT_MAX = 900;

    /**
     * Testa a conexão e confirma que é mesmo um CloudPanel.
     *
     * @param  array $config host, port, username, secret, auth_type, timeout_seconds
     * @return array success, message, version, response_ms
     */
    public function test($config)
    {
        $inicio = microtime(TRUE);

        $script = 'CV=$(command -v clpctl >/dev/null 2>&1 && clpctl --version 2>/dev/null || echo "")' . "\n"
            . 'HN=$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "")' . "\n"
            . 'OS=$(grep PRETTY_NAME /etc/os-release 2>/dev/null | head -1 | cut -d= -f2- || echo "")' . "\n"
            . 'echo "__CPV__:$CV"' . "\n"
            . 'echo "__HOST__:$HN"' . "\n"
            . 'echo "__OS__:$OS"';

        $resultado = $this->exec($config, $script, $this->timeout($config));
        $ms = (int) round((microtime(TRUE) - $inicio) * 1000);

        if (empty($resultado['success'])) {
            return ['success' => FALSE, 'message' => $resultado['message'], 'version' => NULL, 'response_ms' => $ms];
        }

        $versao = '';
        $hostname = '';
        $so = '';
        foreach (explode("\n", $resultado['output']) as $linha) {
            $linha = trim($linha);
            if (strpos($linha, '__CPV__:') === 0) $versao = trim(substr($linha, 8));
            if (strpos($linha, '__HOST__:') === 0) $hostname = trim(substr($linha, 9));
            if (strpos($linha, '__OS__:') === 0) $so = trim(substr($linha, 7));
        }

        if ($versao === '') {
            return [
                'success' => FALSE,
                'message' => 'Conectou por SSH, mas o clpctl não foi encontrado — este host não parece ser um CloudPanel.',
                'version' => NULL,
                'response_ms' => $ms,
            ];
        }

        $detalhe = [];
        if ($hostname !== '') $detalhe[] = $hostname;
        if ($so !== '') $detalhe[] = trim($so, '"');

        return [
            'success' => TRUE,
            'message' => 'CloudPanel respondeu: ' . $versao . (!empty($detalhe) ? ' — ' . implode(' · ', $detalhe) : ''),
            'version' => $versao,
            'response_ms' => $ms,
        ];
    }

    /**
     * Varre /home/*&#47;htdocs e devolve os domínios com uso de disco e status.
     *
     * @param  array $config
     * @return array domains, complete, message
     */
    public function listDomains($config)
    {
        // A varredura percorre o disco inteiro dos sites; o timeout do teste
        // (padrão 30s) não dá conta em servidor cheio.
        $timeout = $this->timeout($config) * 4;
        $timeout = max(self::SCAN_TIMEOUT_MIN, min($timeout, self::SCAN_TIMEOUT_MAX));

        $resultado = $this->exec($config, $this->buildScanScript(), $timeout);

        if (empty($resultado['success'])) {
            return ['domains' => [], 'complete' => FALSE, 'message' => $resultado['message']];
        }

        $dominios = [];
        foreach (explode("\n", $resultado['output']) as $linha) {
            $item = $this->parseLinha($linha);
            if ($item === NULL) continue;

            $dominios[] = [
                'domain' => mb_strtolower($item['domain']),
                'owner_username' => $item['user'],
                // O CloudPanel não expõe plano, IP, cota nem e-mail de contato.
                // Estes campos ficam ausentes de propósito: o sincronizador não
                // grava o que não veio, preservando o que já existe no banco.
                'disk_used_mb' => $item['mb'],
                'status' => $item['status'],
                'source' => 'cloudpanel',
            ];
        }

        $aviso = '';
        if (empty($dominios)) {
            $aviso = 'Nenhum domínio encontrado em /home/*/htdocs. Confirme que o usuário SSH é root '
                . '(ou tem leitura em /home) e que os sites estão em /home/<usuario>/htdocs/<dominio>.';
        }

        return ['domains' => $dominios, 'complete' => TRUE, 'message' => $aviso];
    }

    /**
     * Monta o script de varredura.
     *
     * Está separado do listDomains() para poder ser inspecionado/testado sem
     * abrir conexão SSH — é o trecho mais frágil da integração.
     *
     * Sai sempre com `exit 0`: um `du` que falha em um site (permissão) não
     * pode derrubar a varredura inteira.
     *
     * Usuários de sistema em /home são ignorados. `clp` é o do próprio
     * CloudPanel: o painel mora em /home/clp/htdocs/app e, sem essa exclusão,
     * entra na listagem como se fosse um domínio de cliente chamado "app" —
     * um registro que não corresponde a site nenhum e ainda carrega o disco do
     * painel. A comparação é de linha inteira (`-x`) para "mysql" não casar
     * também com um usuário de site cujo nome apenas contenha isso.
     *
     * @return string
     */
    public function buildScanScript()
    {
        $suspensos = self::NGINX_SUSPENDED_DIR;

        return implode("\n", [
            'set +e',
            'SUSPENSOS="' . $suspensos . '"',
            'for user in $(ls /home/ 2>/dev/null | grep -vxE \'lost\+found|mysql|clp\'); do',
            '  if [ -d "/home/${user}/htdocs" ]; then',
            '    cd "/home/${user}/htdocs" 2>/dev/null || continue',
            '    for domain in $(ls -d */ 2>/dev/null | sed \'s#/$##\'); do',
            '      if [ -d "${domain}" ]; then',
            '        size_mb=$(du -sm "${domain}" 2>/dev/null | cut -f1)',
            '        st="ativo"',
            '        [ -f "${SUSPENSOS}/${domain}.conf" ] && st="suspenso"',
            '        [ -n "${size_mb}" ] && echo "${user}|${domain}|${size_mb}|${st}"',
            '      fi',
            '    done',
            '  fi',
            'done',
            'exit 0',
        ]);
    }

    /**
     * Interpreta "usuario|dominio|mb|status".
     *
     * O domínio pode conter "|" (raro, mas o parser não pode quebrar por isso),
     * então o miolo entre o primeiro campo e os dois últimos é remontado.
     *
     * @param  string $linha
     * @return array|null user, domain, mb, status
     */
    public function parseLinha($linha)
    {
        $linha = trim((string) $linha);
        if ($linha === '' || strpos($linha, '|') === FALSE) return NULL;

        $partes = array_map('trim', explode('|', $linha));
        if (count($partes) < 3) return NULL;

        $ultimo = $partes[count($partes) - 1];
        $temStatus = count($partes) >= 4 && ($ultimo === 'ativo' || $ultimo === 'suspenso');
        $status = $temStatus ? $ultimo : 'ativo';
        $fim = $temStatus ? count($partes) - 1 : count($partes);

        $usuario = $partes[0];
        $tamanho = $partes[$fim - 1];
        $dominio = implode('|', array_slice($partes, 1, $fim - 2));

        if ($usuario === '' || $dominio === '' || $tamanho === '') return NULL;

        $mb = (float) str_replace(',', '.', $tamanho);
        if (!is_finite($mb) || $mb < 0) return NULL;

        return ['user' => $usuario, 'domain' => $dominio, 'mb' => $mb, 'status' => $status];
    }

    /**
     * Suspende ou reativa um SITE do CloudPanel.
     *
     * Diferente do WHM e do DirectAdmin, aqui não existe conta suspensa: o
     * painel não tem `clpctl site:suspend`. A suspensão é feita movendo o vhost
     * entre sites-enabled e sites-suspended e recarregando o nginx — o mesmo
     * diretório que a varredura já lê para reportar o status. Por isso a
     * unidade é o DOMÍNIO, e não o usuário do sistema.
     *
     * A troca só é efetivada se `nginx -t` aprovar a configuração resultante;
     * em qualquer falha o vhost volta para o lugar de origem ANTES de o erro
     * subir — meio caminho aqui derruba todos os sites do servidor, não só o
     * deste contrato.
     *
     * @param  array  $config
     * @param  string $dominio
     * @param  bool   $suspender TRUE suspende, FALSE reativa
     * @return array  success, message, already (TRUE quando já estava no estado pedido)
     */
    public function setSuspension($config, $dominio, $suspender)
    {
        $alvo = mb_strtolower(trim((string) $dominio));

        // O domínio entra num script de shell: só o que é nome de domínio
        // passa. A validação é da library (e não só de quem chama) porque é
        // aqui que o texto vira comando.
        if ($alvo === '' || !preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $alvo)) {
            return ['success' => FALSE, 'message' => 'Domínio inválido para a operação no CloudPanel.', 'already' => FALSE];
        }

        $acao = $suspender ? 'suspender' : 'reativar';
        $script = $suspender ? $this->buildSuspenderScript($alvo) : $this->buildReativarScript($alvo);

        $resultado = $this->exec($config, $script, $this->timeout($config));
        if (empty($resultado['success'])) {
            return ['success' => FALSE, 'message' => $resultado['message'], 'already' => FALSE];
        }

        return $this->parseMarcadorSuspensao($resultado['output'], $alvo, $acao);
    }

    /**
     * Lê o marcador que o script remoto imprime.
     *
     * Os scripts saem sempre com código 0 e comunicam o resultado por um
     * marcador na saída: assim uma falha de negócio (vhost ausente) não se
     * mistura com falha de SSH, que o `exec()` já reporta por conta própria.
     *
     * @param  string $saida
     * @param  string $dominio
     * @param  string $acao
     * @return array  success, message, already
     */
    private function parseMarcadorSuspensao($saida, $dominio, $acao)
    {
        $linhas = array_values(array_filter(array_map('trim', explode("\n", (string) $saida)), 'strlen'));
        $marcador = '';
        foreach (array_reverse($linhas) as $linha) {
            if (strpos($linha, '__') === 0) {
                $marcador = $linha;
                break;
            }
        }

        if ($marcador === '__OK__') {
            return ['success' => TRUE, 'message' => '', 'already' => FALSE];
        }
        if ($marcador === '__JA_APLICADO__') {
            return ['success' => TRUE, 'message' => 'O site ' . $dominio . ' já estava nesse estado.', 'already' => TRUE];
        }
        if ($marcador === '__SEM_VHOST__') {
            return [
                'success' => FALSE,
                'message' => 'vhost de ' . $dominio . ' não encontrado em ' . self::NGINX_ENABLED_DIR . ' nem em ' . self::NGINX_SUSPENDED_DIR . '.',
                'already' => FALSE,
            ];
        }
        if ($marcador === '__NGINX_INVALIDO__') {
            return [
                'success' => FALSE,
                'message' => 'O nginx recusou a configuração ao ' . $acao . ' ' . $dominio . '. O vhost foi restaurado e nada foi alterado.',
                'already' => FALSE,
            ];
        }
        if (strpos($marcador, '__ERRO__:') === 0) {
            $detalhe = trim(substr($marcador, strlen('__ERRO__:')));
            return [
                'success' => FALSE,
                'message' => $detalhe !== '' ? $detalhe : ('Falha ao ' . $acao . ' ' . $dominio . '.'),
                'already' => FALSE,
            ];
        }

        $bruto = trim((string) $saida);
        return [
            'success' => FALSE,
            'message' => 'Resposta inesperada do servidor ao ' . $acao . ' ' . $dominio . ': ' . ($bruto !== '' ? mb_substr($bruto, 0, 200) : '(vazia)'),
            'already' => FALSE,
        ];
    }

    /**
     * Move o vhost para sites-suspended.
     *
     * Quando não há `<dominio>.conf` no sites-enabled, procura o arquivo pelo
     * `server_name` — no CloudPanel um site com domínio adicional pode estar
     * num vhost cujo nome de arquivo é o do domínio principal.
     *
     * O caminho de origem é guardado num `.origin` ao lado do vhost para a
     * reativação devolver o arquivo exatamente de onde ele saiu.
     *
     * @param  string $dominio já validado por setSuspension()
     * @return string
     */
    private function buildSuspenderScript($dominio)
    {
        $dominioRe = str_replace('.', '\\.', $dominio);

        return implode("\n", [
            'set +e',
            'DOMINIO="' . $dominio . '"',
            'DOMINIO_RE="' . $dominioRe . '"',
            'ENABLED="' . self::NGINX_ENABLED_DIR . '"',
            'SUSPENSOS="' . self::NGINX_SUSPENDED_DIR . '"',
            '',
            'mkdir -p "${SUSPENSOS}" 2>/dev/null',
            '',
            'if [ -f "${SUSPENSOS}/${DOMINIO}.conf" ]; then',
            '  echo "__JA_APLICADO__"',
            '  exit 0',
            'fi',
            '',
            'SRC="${ENABLED}/${DOMINIO}.conf"',
            'if [ ! -f "${SRC}" ]; then',
            '  SRC=$(grep -rlE "^[[:space:]]*server_name[^;]*[[:space:]]${DOMINIO_RE}([[:space:]]|;)" "${ENABLED}" 2>/dev/null | head -1)',
            'fi',
            '',
            'if [ -z "${SRC}" ] || [ ! -f "${SRC}" ]; then',
            '  echo "__SEM_VHOST__"',
            '  exit 0',
            'fi',
            '',
            'mv "${SRC}" "${SUSPENSOS}/${DOMINIO}.conf" 2>/dev/null',
            'if [ ! -f "${SUSPENSOS}/${DOMINIO}.conf" ]; then',
            '  echo "__ERRO__:nao foi possivel mover o vhost ${SRC}"',
            '  exit 0',
            'fi',
            'printf \'%s\\n\' "${SRC}" > "${SUSPENSOS}/${DOMINIO}.origin"',
            '',
            'if ! nginx -t >/dev/null 2>&1; then',
            '  mv "${SUSPENSOS}/${DOMINIO}.conf" "${SRC}" 2>/dev/null',
            '  rm -f "${SUSPENSOS}/${DOMINIO}.origin"',
            '  echo "__NGINX_INVALIDO__"',
            '  exit 0',
            'fi',
            '',
            'if ! (systemctl reload nginx >/dev/null 2>&1 || nginx -s reload >/dev/null 2>&1); then',
            '  mv "${SUSPENSOS}/${DOMINIO}.conf" "${SRC}" 2>/dev/null',
            '  rm -f "${SUSPENSOS}/${DOMINIO}.origin"',
            '  echo "__ERRO__:falha ao recarregar o nginx"',
            '  exit 0',
            'fi',
            '',
            'echo "__OK__"',
            'exit 0',
        ]);
    }

    /**
     * Devolve o vhost ao sites-enabled, no caminho registrado no `.origin`
     * (quando existe e aponta para dentro do próprio sites-enabled).
     *
     * @param  string $dominio já validado por setSuspension()
     * @return string
     */
    private function buildReativarScript($dominio)
    {
        return implode("\n", [
            'set +e',
            'DOMINIO="' . $dominio . '"',
            'ENABLED="' . self::NGINX_ENABLED_DIR . '"',
            'SUSPENSOS="' . self::NGINX_SUSPENDED_DIR . '"',
            'SRC="${SUSPENSOS}/${DOMINIO}.conf"',
            '',
            'if [ ! -f "${SRC}" ]; then',
            '  if [ -f "${ENABLED}/${DOMINIO}.conf" ]; then',
            '    echo "__JA_APLICADO__"',
            '  else',
            '    echo "__SEM_VHOST__"',
            '  fi',
            '  exit 0',
            'fi',
            '',
            'DEST="${ENABLED}/${DOMINIO}.conf"',
            'if [ -f "${SUSPENSOS}/${DOMINIO}.origin" ]; then',
            '  ORIGEM=$(head -1 "${SUSPENSOS}/${DOMINIO}.origin")',
            '  case "${ORIGEM}" in',
            '    "${ENABLED}"/*) DEST="${ORIGEM}" ;;',
            '  esac',
            'fi',
            '',
            'mv "${SRC}" "${DEST}" 2>/dev/null',
            'if [ ! -f "${DEST}" ]; then',
            '  echo "__ERRO__:nao foi possivel restaurar o vhost em ${DEST}"',
            '  exit 0',
            'fi',
            '',
            'if ! nginx -t >/dev/null 2>&1; then',
            '  mv "${DEST}" "${SRC}" 2>/dev/null',
            '  echo "__NGINX_INVALIDO__"',
            '  exit 0',
            'fi',
            '',
            'rm -f "${SUSPENSOS}/${DOMINIO}.origin"',
            '',
            'if ! (systemctl reload nginx >/dev/null 2>&1 || nginx -s reload >/dev/null 2>&1); then',
            '  echo "__ERRO__:falha ao recarregar o nginx"',
            '  exit 0',
            'fi',
            '',
            'echo "__OK__"',
            'exit 0',
        ]);
    }

    /**
     * Escolhe o motor de matemática do phpseclib ANTES de abrir a conexão.
     *
     * O phpseclib usa GMP quando a extensão está carregada, e em build saudável
     * essa é a escolha certa. O problema é que a troca de chaves do SSH moderno
     * passa por `modInverse()`: o OpenSSH 9+ só oferece curve25519 e ecdh-*, e
     * as duas famílias caem lá. Em build de PHP com a `gmp` mal ligada à libgmp
     * do sistema, `gmp_invert()` derruba o processo com SIGSEGV — o MAMP
     * 7.4.33 deste projeto é um deles, onde até `gmp_invert(3, 11)` mata o
     * processo.
     *
     * E segfault não é exceção: não há `catch`, não há log, a requisição
     * inteira morre calada. O sintoma na tela é o genérico "Falha ao testar a
     * conexão" (o `error` do AJAX, sem resposta nenhuma do servidor), com o
     * cadastro guardando o resultado do teste ANTERIOR — o que faz parecer
     * problema de senha quando a senha está certa.
     *
     * Não dá para sondar o `gmp_invert` antes de usar: a sondagem é exatamente
     * o que derruba o processo. Então preferimos o BCMath, que é correto em
     * qualquer build. O custo é ~250ms a mais por handshake (só na troca de
     * chaves — o tráfego em si continua no OpenSSL), contra um timeout de 30s.
     *
     * A escolha é global do phpseclib para a requisição; hoje esta library é a
     * única consumidora dele no projeto.
     */
    private function prepararMathEngine()
    {
        if (!extension_loaded('bcmath')) {
            return;
        }

        $atual = (array) BigInteger::getEngine();
        if (isset($atual[0]) && $atual[0] === 'BCMath') {
            return;
        }

        try {
            BigInteger::setEngine('BCMath', ['DefaultEngine']);
        } catch (Throwable $e) {
            // phpseclib sem BCMath disponível: segue com o motor padrão.
            log_message('error', 'Server_cloudpanel: não foi possível usar o BCMath no phpseclib — ' . $e->getMessage());
        }
    }

    /**
     * @param  array $config
     * @return int segundos
     */
    private function timeout($config)
    {
        $timeout = isset($config['timeout_seconds']) ? (int) $config['timeout_seconds'] : 30;
        return $timeout > 0 ? $timeout : 30;
    }

    /**
     * Abre a conexão SSH, roda o script e devolve a saída.
     *
     * @param  array  $config
     * @param  string $script conteúdo bash
     * @param  int    $timeout segundos
     * @return array  success, output, message
     */
    private function exec($config, $script, $timeout)
    {
        $host = trim((string) (isset($config['host']) ? $config['host'] : ''));
        if ($host === '') {
            return ['success' => FALSE, 'output' => '', 'message' => 'Endereço do servidor CloudPanel não informado.'];
        }

        // O host pode ter sido cadastrado com esquema; o SSH quer só o hostname.
        if (strpos($host, '://') !== FALSE) {
            $extraido = parse_url($host, PHP_URL_HOST);
            if (!empty($extraido)) $host = $extraido;
        }

        $porta = !empty($config['port']) ? (int) $config['port'] : self::PORT;
        $usuario = trim((string) (isset($config['username']) ? $config['username'] : ''));
        $segredo = (string) (isset($config['secret']) ? $config['secret'] : '');
        $tipoAuth = (isset($config['auth_type']) && $config['auth_type'] === 'key') ? 'key' : 'password';

        if ($usuario === '' || $segredo === '') {
            return ['success' => FALSE, 'output' => '', 'message' => 'Usuário e credencial SSH são obrigatórios.'];
        }

        $this->prepararMathEngine();

        try {
            $ssh = new SSH2($host, $porta, $timeout);
            $ssh->setTimeout($timeout);

            if ($tipoAuth === 'key') {
                try {
                    $credencial = PublicKeyLoader::load(trim($segredo));
                } catch (Throwable $e) {
                    return ['success' => FALSE, 'output' => '', 'message' => 'Chave privada SSH inválida ou protegida por passphrase.'];
                }
            } else {
                $credencial = $segredo;
            }

            if (!$ssh->login($usuario, $credencial)) {
                return [
                    'success' => FALSE,
                    'output' => '',
                    'message' => 'Autenticação SSH falhou. Verifique o usuário e a ' . ($tipoAuth === 'key' ? 'chave privada.' : 'senha.'),
                ];
            }

            // O script vai em base64 para o shell remoto. Interpolar bash
            // multilinha direto no comando esbarra em aspas, cifrões e
            // parênteses (a saída do clpctl tem todos); em base64 o payload é
            // só [A-Za-z0-9+/=] e chega intacto qualquer que seja o shell.
            $saida = $ssh->exec('echo ' . base64_encode($script) . ' | base64 -d | bash');
            $ssh->disconnect();

            return ['success' => TRUE, 'output' => (string) $saida, 'message' => ''];
        } catch (Throwable $e) {
            return ['success' => FALSE, 'output' => '', 'message' => $this->mensagemErroSsh($e->getMessage())];
        }
    }

    /**
     * @param  string $mensagem
     * @return string
     */
    private function mensagemErroSsh($mensagem)
    {
        $texto = mb_strtolower((string) $mensagem);

        if (strpos($texto, 'connection refused') !== FALSE || strpos($texto, 'econnrefused') !== FALSE) {
            return 'Conexão recusada. Verifique o endereço e se a porta SSH está aberta.';
        }
        if (strpos($texto, 'timed out') !== FALSE || strpos($texto, 'timeout') !== FALSE) {
            return 'Tempo limite excedido ao conectar por SSH.';
        }
        if (strpos($texto, 'no route to host') !== FALSE || strpos($texto, 'unreachable') !== FALSE) {
            return 'Servidor inacessível. Verifique o endereço/IP.';
        }
        if (strpos($texto, 'permission denied') !== FALSE || strpos($texto, 'authentication') !== FALSE) {
            return 'Autenticação SSH falhou. Verifique usuário e senha/chave.';
        }
        if (strpos($texto, 'could not resolve') !== FALSE || strpos($texto, 'getaddrinfo') !== FALSE) {
            return 'Host não encontrado.';
        }

        return 'Falha na conexão SSH: ' . $mensagem;
    }
}
