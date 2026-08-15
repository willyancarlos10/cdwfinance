<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Driver de sessão em banco, endurecido.
 *
 * O CI3 original chama ->row() direto no resultado do GET_LOCK/RELEASE_LOCK,
 * sem guarda de nulo (system/libraries/Session/drivers/Session_database_driver.php,
 * linhas 392 e 434). Com db_debug = FALSE (produção) uma query que falha devolve
 * FALSE, e FALSE->row() vira "Call to a member function row() on bool" — dentro
 * do shutdown do PHP, onde não existe tratamento possível.
 *
 * É o que acontece quando a conexão fica dessincronizada ("Commands out of sync"):
 * a request morreu no meio de um comando (estouro de max_execution_time, término
 * do worker, client abort durante FTP/SFTP/cURL) e o RELEASE_LOCK do shutdown é
 * só o primeiro comando depois do estrago.
 *
 * Este override não tenta consertar a causa — ele impede o fatal e registra o
 * contexto necessário para identificá-la (ver diagLock()).
 *
 * Resolução: com $config['subclass_prefix'] = 'MY_', o CI3 procura exatamente
 * este caminho e esta classe em system/libraries/Session/Session.php:237. Não
 * renomear o arquivo tirando o prefixo: a linha 226 é
 * file_exists(APPPATH...) OR file_exists(BASEPATH...), então um arquivo sem
 * prefixo aqui faria o do system/ nunca ser carregado e CI_Session_database_driver
 * deixaria de existir.
 */
class MY_Session_database_driver extends CI_Session_database_driver
{
    /** Quantas queries anteriores entram no diagnóstico. */
    const DIAG_MAX_QUERIES = 5;

    /** Tamanho máximo de cada query no diagnóstico. */
    const DIAG_MAX_SQL = 200;

    /**
     * Identificador curto da request.
     *
     * É o campo que responde à dúvida central do log de produção: várias linhas
     * no mesmo segundo são N requests distintas ou uma request repetindo?
     *
     * @var string
     */
    protected $rid;

    public function __construct(&$params)
    {
        parent::__construct($params);

        // Não precisa ser criptográfico, só único dentro do segundo.
        $this->rid = substr(md5(uniqid('', TRUE)), 0, 8);
    }

    // ------------------------------------------------------------------------

    /**
     * Adquire o lock. Espelha o pai, mas sem ->row() em resultado possivelmente FALSE.
     *
     * @param  string $session_id
     * @return bool
     */
    #[ReturnTypeWillChange]
    protected function _get_lock($session_id)
    {
        if ($this->_platform !== 'mysql') {
            return parent::_get_lock($session_id);
        }

        $arg = md5($session_id . ($this->_config['match_ip'] ? '_' . $_SERVER['REMOTE_ADDR'] : ''));
        $res = $this->_db->query("SELECT GET_LOCK('" . $arg . "', 300) AS ci_session_lock");

        if ($res === FALSE || ($row = $res->row()) === NULL) {
            $this->diagLock('get_lock_falhou');
            return FALSE;
        }

        if ($row->ci_session_lock) {
            $this->_lock = $arg;
            return TRUE;
        }

        return FALSE;
    }

    // ------------------------------------------------------------------------

    /**
     * Libera o lock. Espelha o pai, mas sem ->row() em resultado possivelmente FALSE.
     *
     * @return bool
     */
    #[ReturnTypeWillChange]
    protected function _release_lock()
    {
        if (!$this->_lock) {
            return TRUE;
        }

        if ($this->_platform !== 'mysql') {
            return parent::_release_lock();
        }

        $res = $this->_db->query("SELECT RELEASE_LOCK('" . $this->_lock . "') AS ci_session_lock");

        if ($res === FALSE || ($row = $res->row()) === NULL) {
            // Devolve TRUE de propósito: com pconnect = FALSE o MySQL libera
            // qualquer GET_LOCK sozinho ao encerrar a conexão, o que acontece
            // logo em seguida. Devolver FALSE só faria close() responder
            // _failure e gerar um segundo warning no shutdown, sem ganho.
            $this->diagLock('release_lock_falhou');
            $this->_lock = FALSE;
            return TRUE;
        }

        if ($row->ci_session_lock) {
            $this->_lock = FALSE;
            return TRUE;
        }

        return FALSE;
    }

    // ------------------------------------------------------------------------

    /**
     * Grava a sessão. Roda no RSHUTDOWN do PHP: uma exceção aqui vira
     * "Fatal error in Unknown on line 0", sem arquivo nem linha.
     *
     * @param  string $session_id
     * @param  string $session_data
     * @return bool
     */
    #[ReturnTypeWillChange]
    public function write($session_id, $session_data)
    {
        // Sonda para dimensionar quantas sessões descartáveis são criadas por
        // request sem cookie (bot, API Bearer, cron por URL). Ligar com
        // $config['sess_diag'] = TRUE na config.php — que é gitignored, então
        // liga e desliga em produção sem deploy.
        if ($this->_row_exists === FALSE && !$this->temCookie() && config_item('sess_diag')) {
            log_message('error', sprintf(
                '[SESS-DIAG] sessao_nova_sem_cookie rid=%s uri=%s metodo=%s ua="%s"',
                $this->rid,
                $this->campoServidor('REQUEST_URI'),
                $this->campoServidor('REQUEST_METHOD'),
                $this->campoServidor('HTTP_USER_AGENT')
            ));
        }

        try {
            return parent::write($session_id, $session_data);
        } catch (\Throwable $e) {
            $this->diagLock('write_lancou_excecao erro="' . preg_replace('/\s+/', ' ', $e->getMessage()) . '"');
            return $this->_failure;
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Registra o contexto de uma falha de lock, em uma linha só.
     *
     * Passa com log_threshold = 1 e não depende de enable_hooks. Nunca lança:
     * é chamado de dentro do shutdown, onde um erro não teria como ser tratado.
     *
     * @param  string $etapa
     * @return void
     */
    protected function diagLock($etapa)
    {
        try {
            $erro = $this->erroBanco();
            $fatal = error_get_last();

            $campos = array(
                'rid=' . $this->rid,
                'pid=' . getmypid(),
                'errno=' . $erro['code'],
                'erro="' . $erro['message'] . '"',
                // 0 aqui = request sem cookie de sessão, ou seja, sessão criada à toa.
                'cookie_presente=' . ($this->temCookie() ? 1 : 0),
                'abortado=' . (int) connection_aborted(),
                'decorrido=' . sprintf('%.1f', $this->decorrido()) . 's/' . ini_get('max_execution_time') . 's',
                'memoria=' . round(memory_get_peak_usage(TRUE) / 1048576, 1) . 'MB',
                'uri=' . $this->campoServidor('REQUEST_URI'),
                'metodo=' . $this->campoServidor('REQUEST_METHOD'),
                'ua="' . $this->campoServidor('HTTP_USER_AGENT') . '"',
                // "Maximum execution time exceeded" aqui confirma o abort mid-query.
                'fatal_anterior="' . ($fatal === NULL ? '' : preg_replace('/\s+/', ' ', $fatal['message'])) . '"',
                'queries=' . $this->queriesAnteriores(),
            );

            log_message('error', '[SESS-LOCK] ' . $etapa . ' ' . implode(' ', $campos));
        } catch (\Throwable $e) {
            // Diagnóstico nunca pode derrubar a request que ele está diagnosticando.
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Últimas queries da request, sanitizadas.
     *
     * Depende de $db['...']['save_queries'] = TRUE; sem isso devolve "(vazio)".
     *
     * @return string
     */
    protected function queriesAnteriores()
    {
        if (empty($this->_db->queries) || !is_array($this->_db->queries)) {
            return '(vazio)';
        }

        $tabela  = isset($this->_config['save_path']) ? (string) $this->_config['save_path'] : '';
        $ultimas = array_values(array_slice($this->_db->queries, -self::DIAG_MAX_QUERIES));
        $tempos  = is_array($this->_db->query_times)
            ? array_values(array_slice($this->_db->query_times, -self::DIAG_MAX_QUERIES))
            : array();

        $saida = array();
        foreach ($ultimas as $i => $sql) {
            $sql = preg_replace('/\s+/', ' ', (string) $sql);

            // O INSERT/UPDATE da sessão carrega o blob `data` serializado, com
            // usuário, empresa e permissões. O corpo nunca vai para o log — só o
            // cabeçalho, o suficiente para identificar qual query era.
            $limite = ($tabela !== '' && stripos($sql, $tabela) !== FALSE)
                ? 60
                : self::DIAG_MAX_SQL;

            if (strlen($sql) > $limite) {
                $sql = substr($sql, 0, $limite) . '…[+' . (strlen($sql) - $limite) . ' bytes]';
            }

            $tempo   = isset($tempos[$i]) ? sprintf('%.3fs', $tempos[$i]) : '?';
            $saida[] = '{' . $tempo . ' ' . $sql . '}';
        }

        return implode(' ', $saida);
    }

    // ------------------------------------------------------------------------

    /**
     * Erro do mysqli, sem assumir que a conexão ainda é um objeto válido.
     *
     * @return array code, message
     */
    protected function erroBanco()
    {
        if (!is_object($this->_db) || !is_object($this->_db->conn_id)) {
            return array('code' => '?', 'message' => 'conexao_indisponivel');
        }

        $erro = $this->_db->error();

        return array(
            'code'    => isset($erro['code']) ? $erro['code'] : '?',
            'message' => isset($erro['message']) ? preg_replace('/\s+/', ' ', $erro['message']) : '',
        );
    }

    // ------------------------------------------------------------------------

    /**
     * A request chegou com cookie de sessão?
     *
     * @return bool
     */
    protected function temCookie()
    {
        $nome = isset($this->_config['cookie_name']) ? (string) $this->_config['cookie_name'] : '';

        return ($nome !== '' && isset($_COOKIE[$nome]));
    }

    // ------------------------------------------------------------------------

    /**
     * Segundos desde o início da request; -1 quando indisponível (CLI).
     *
     * @return float
     */
    protected function decorrido()
    {
        if (empty($_SERVER['REQUEST_TIME_FLOAT'])) {
            return -1;
        }

        return microtime(TRUE) - $_SERVER['REQUEST_TIME_FLOAT'];
    }

    // ------------------------------------------------------------------------

    /**
     * Campo de $_SERVER saneado para uma linha de log.
     *
     * @param  string $chave
     * @return string
     */
    protected function campoServidor($chave)
    {
        if (empty($_SERVER[$chave]) || !is_string($_SERVER[$chave])) {
            return '-';
        }

        return substr(preg_replace('/[\s"]+/', ' ', $_SERVER[$chave]), 0, 160);
    }
}
