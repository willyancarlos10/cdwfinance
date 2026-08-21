<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Regras dos servidores: teste de conexão e sincronização dos domínios. O
 * controller e o cron só chamam este model.
 *
 * As integrações (Server_whm, Server_directadmin, Server_cloudpanel e
 * Server_carbonio) têm a mesma interface — test($config) e listDomains($config)
 * — e são escolhidas por `type`. Toda credencial trafega cifrada no banco e só
 * é decifrada aqui, na hora de montar a conexão.
 *
 * O Carbonio é servidor de E-MAIL, não de site: entra só para trazer os
 * domínios e para parar/retomar o serviço deles junto com o contrato.
 */
class Server_model extends CI_Model
{
    /** Tipos aceitos no cadastro e na sincronização. */
    const TYPES = ['whm', 'directadmin', 'cloudpanel', 'carbonio'];

    /**
     * Teto de tempo, em segundos, do laço que suspende as contas de um
     * contrato. Ver suspendContractAccounts().
     *
     * Subiu de 60 para 240 quando a falha passou a ABORTAR a mudança de status
     * do contrato (Contratos::suspensaoImpede()): antes, o que não coubesse no
     * orçamento ficava para a próxima tentativa e o contrato mudava de estado
     * assim mesmo; agora a operação só vale se completar, e a repetição refaz
     * as contas já aplicadas — então um orçamento curto num contrato grande (há
     * um com 65 contas) nunca convergiria. Quem chama já faz set_time_limit(0);
     * o teto existe para a requisição HTTP não ficar presa indefinidamente numa
     * cadeia de painéis lentos.
     */
    const ORCAMENTO_SUSPENSAO_SEGUNDOS = 240;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('secret_crypto');
    }

    /**
     * @param  string $type
     * @return bool
     */
    public function isValidType($type)
    {
        return in_array((string) $type, self::TYPES, TRUE);
    }

    /**
     * Rótulo do tipo para exibição.
     *
     * @param  string $type
     * @return string
     */
    public function typeLabel($type)
    {
        $rotulos = [
            'whm' => 'WHM / cPanel',
            'directadmin' => 'DirectAdmin',
            'cloudpanel' => 'CloudPanel',
            'carbonio' => 'Carbonio (e-mail)',
        ];
        return isset($rotulos[$type]) ? $rotulos[$type] : (string) $type;
    }

    /**
     * Carrega o servidor com a credencial (tabela base, não a view — a view não
     * expõe `secret` de propósito).
     *
     * @param  int      $idServer
     * @param  int|null $idCompany quando informado, restringe ao escopo
     * @return object|null
     */
    public function getServer($idServer, $idCompany = NULL)
    {
        $where = ['id' => (int) $idServer];
        if ($idCompany !== NULL) {
            $where['id_company'] = (int) $idCompany;
        }

        $servidor = $this->global_model->getWhere_off('crm_servers', $where, TRUE);
        return empty($servidor) ? NULL : $servidor;
    }

    /**
     * Monta o array de conexão a partir do registro, decifrando o segredo.
     *
     * @param  object $servidor
     * @return array|bool FALSE quando a credencial não pôde ser decifrada
     */
    public function getConnectionConfig($servidor)
    {
        $segredo = $this->secret_crypto->decrypt(isset($servidor->secret) ? $servidor->secret : '');
        if ($segredo === FALSE) {
            return FALSE;
        }

        return [
            'host' => $servidor->host,
            'port' => isset($servidor->port) ? (int) $servidor->port : NULL,
            'username' => $servidor->username,
            'secret' => $segredo,
            'auth_type' => isset($servidor->auth_type) ? $servidor->auth_type : NULL,
            'verify_ssl' => !empty($servidor->verify_ssl),
            'timeout_seconds' => (int) $servidor->timeout_seconds,
        ];
    }

    /**
     * Instancia a library do tipo informado.
     *
     * @param  string $type
     * @return object|bool
     */
    private function getClient($type)
    {
        switch ($type) {
            case 'whm':
                $this->load->library('server_whm');
                return $this->server_whm;
            case 'directadmin':
                $this->load->library('server_directadmin');
                return $this->server_directadmin;
            case 'cloudpanel':
                $this->load->library('server_cloudpanel');
                return $this->server_cloudpanel;
            case 'carbonio':
                $this->load->library('server_carbonio');
                return $this->server_carbonio;
        }
        return FALSE;
    }

    /**
     * Testa a conexão e grava o resultado no cadastro.
     *
     * @param  int      $idServer
     * @param  int|null $idCompany escopo
     * @param  int      $idUser    autor da ação
     * @return array    success, message, data
     */
    public function testConnection($idServer, $idCompany, $idUser)
    {
        $servidor = $this->getServer($idServer, $idCompany);
        if ($servidor === NULL) {
            return ['success' => FALSE, 'message' => 'Servidor não encontrado.', 'data' => NULL];
        }

        $cliente = $this->getClient($servidor->type);
        if ($cliente === FALSE) {
            return ['success' => FALSE, 'message' => 'Tipo de servidor não suportado: ' . $servidor->type, 'data' => NULL];
        }

        $config = $this->getConnectionConfig($servidor);
        if ($config === FALSE) {
            return [
                'success' => FALSE,
                'message' => 'Não foi possível ler a credencial deste servidor. Recadastre a senha/token — a chave de criptografia pode ter sido trocada.',
                'data' => NULL,
            ];
        }

        // Chamada de rede longa: solta o lock de sessão do MySQL antes, senão a
        // conexão fica presa durante a espera. Nada de escrever na sessão aqui.
        $sessao = sessao_suspender();
        try {
            $resultado = $cliente->test($config);
        } catch (Throwable $e) {
            $resultado = ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage(), 'response_ms' => NULL];
        } finally {
            sessao_retomar($sessao);
        }

        $this->global_model->edit('crm_servers', [
            'last_test' => date('Y-m-d H:i:s'),
            'last_test_status' => !empty($resultado['success']) ? 'conectado' : 'erro',
            'last_test_message' => mb_substr((string) $resultado['message'], 0, 500),
            'last_test_ms' => isset($resultado['response_ms']) ? (int) $resultado['response_ms'] : NULL,
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ], 'id', (int) $idServer);

        return [
            'success' => !empty($resultado['success']),
            'message' => $resultado['message'],
            'data' => [
                'id' => (int) $idServer,
                'last_test_status' => !empty($resultado['success']) ? 'conectado' : 'erro',
                'last_test' => date('d/m/Y H:i'),
                'last_test_ms' => isset($resultado['response_ms']) ? (int) $resultado['response_ms'] : NULL,
            ],
        ];
    }

    /**
     * Busca a lista de domínios no painel, sem gravar nada.
     *
     * @param  object $servidor
     * @return array  domains, complete, message, error
     */
    public function fetchDomains($servidor)
    {
        $cliente = $this->getClient($servidor->type);
        if ($cliente === FALSE) {
            return ['domains' => [], 'complete' => FALSE, 'message' => 'Tipo de servidor não suportado: ' . $servidor->type, 'error' => TRUE];
        }

        $config = $this->getConnectionConfig($servidor);
        if ($config === FALSE) {
            return ['domains' => [], 'complete' => FALSE, 'message' => 'Credencial ilegível — recadastre a senha/token do servidor.', 'error' => TRUE];
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $cliente->listDomains($config);
        } catch (Throwable $e) {
            $resultado = ['domains' => [], 'complete' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage()];
        } finally {
            sessao_retomar($sessao);
        }

        $resultado['error'] = empty($resultado['domains']) && !empty($resultado['message']) && empty($resultado['complete']);
        return $resultado;
    }

    /**
     * Sincroniza os domínios de um servidor.
     *
     * @param  int      $idServer
     * @param  int|null $idCompany escopo
     * @param  int      $idUser
     * @return array    success, message, data
     */
    public function syncDomains($idServer, $idCompany, $idUser)
    {
        $servidor = $this->getServer($idServer, $idCompany);
        if ($servidor === NULL) {
            return ['success' => FALSE, 'message' => 'Servidor não encontrado.', 'data' => NULL];
        }

        $agora = date('Y-m-d H:i:s');
        $coleta = $this->fetchDomains($servidor);

        // Falha total na coleta: registra o erro e não encosta nos domínios já
        // gravados — eles continuam sendo a última informação boa que temos.
        if (!empty($coleta['error'])) {
            $this->registrarSync($idServer, $idUser, 'erro', $coleta['message'], $agora);
            return ['success' => FALSE, 'message' => $coleta['message'], 'data' => NULL];
        }

        $novos = 0;
        $atualizados = 0;
        $erros = 0;
        $vistos = [];

        foreach ($coleta['domains'] as $item) {
            $dominio = isset($item['domain']) ? mb_strtolower(trim((string) $item['domain'])) : '';
            if ($dominio === '') continue;

            $vistos[] = $dominio;
            $existente = $this->global_model->getFieldsWhereSingle_off(
                'crm_servers_domains',
                ['id'],
                ['id_server' => (int) $idServer, 'domain' => $dominio],
                TRUE
            );

            if ($this->upsertDomain($idServer, $servidor->id_company, $dominio, $item, $agora, $idUser)) {
                if (empty($existente)) $novos++;
                else $atualizados++;
            } else {
                $erros++;
            }
        }

        // Poda: só quando a listagem é autoritativa e voltou com conteúdo. Uma
        // resposta vazia por falha transitória apagaria a base inteira.
        $removidos = 0;
        if (!empty($coleta['complete']) && !empty($vistos)) {
            $removidos = $this->pruneDomains($idServer, $vistos);
        }

        $status = ($erros === 0) ? 'sucesso' : (($novos + $atualizados > 0) ? 'parcial' : 'erro');
        $mensagem = $novos . ' novo(s), ' . $atualizados . ' atualizado(s), ' . $removidos . ' removido(s), ' . $erros . ' erro(s)';

        if (empty($coleta['complete'])) {
            $mensagem .= ' — remoção ignorada: listagem incompleta';
            if ($status === 'sucesso') $status = 'parcial';
        }
        if (!empty($coleta['message'])) {
            $mensagem .= '. ' . $coleta['message'];
        }

        $this->registrarSync($idServer, $idUser, $status, $mensagem, $agora);

        return [
            'success' => ($status !== 'erro'),
            'message' => $mensagem,
            'data' => [
                'id' => (int) $idServer,
                'total' => count($vistos),
                'novos' => $novos,
                'atualizados' => $atualizados,
                'removidos' => $removidos,
                'erros' => $erros,
                'status' => $status,
                'last_sync' => date('d/m/Y H:i'),
            ],
        ];
    }

    /**
     * Insere ou atualiza um domínio, apoiado na UNIQUE (id_server, domain).
     *
     * Campos que o painel não informou NÃO entram no UPDATE: o CloudPanel não
     * expõe plano/IP/cota, e sobrescrevê-los com NULL apagaria dado bom que
     * veio de outra origem ou de um preenchimento manual.
     *
     * @param  int    $idServer
     * @param  int    $idCompany
     * @param  string $dominio
     * @param  array  $item
     * @param  string $agora
     * @param  int    $idUser
     * @return bool
     */
    private function upsertDomain($idServer, $idCompany, $dominio, $item, $agora, $idUser)
    {
        // Sempre presentes na coleta de todos os painéis.
        $colunas = [
            'owner_username' => isset($item['owner_username']) ? $item['owner_username'] : NULL,
            'status' => isset($item['status']) ? $item['status'] : 'ativo',
            'source' => isset($item['source']) ? $item['source'] : 'manual',
        ];

        // Opcionais: só entram quando a origem realmente informou. O
        // `disk_used_mb` está aqui — e não no bloco acima — porque no Carbonio
        // ele vem de uma chamada à parte da que lista os domínios: se só ela
        // falhar, gravar NULL zeraria o disco de todos os domínios do servidor
        // e derrubaria a barra de uso dos contratos sem nada ter mudado.
        foreach (['disk_used_mb', 'plan', 'disk_limit_mb', 'ip', 'contact_email', 'suspension_reason'] as $opcional) {
            if (array_key_exists($opcional, $item)) {
                $colunas[$opcional] = $item[$opcional];
            }
        }

        $colunas['last_sync'] = $agora;
        $colunas['sync_status'] = 'sucesso';

        $insert = array_merge($colunas, [
            'id_server' => (int) $idServer,
            'id_company' => (int) $idCompany,
            'domain' => $dominio,
            'created' => $agora,
            'created_by' => (int) $idUser,
            'modified' => $agora,
            'modified_by' => (int) $idUser,
        ]);

        $update = array_merge($colunas, [
            'modified' => $agora,
            'modified_by' => (int) $idUser,
        ]);

        $camposInsert = array_keys($insert);
        $sql = 'INSERT INTO `crm_servers_domains` (`' . implode('`, `', $camposInsert) . '`) VALUES ('
            . implode(', ', array_fill(0, count($camposInsert), '?')) . ') ON DUPLICATE KEY UPDATE ';

        $partes = [];
        foreach (array_keys($update) as $campo) {
            $partes[] = '`' . $campo . '` = ?';
        }
        $sql .= implode(', ', $partes);

        $binds = array_merge(array_values($insert), array_values($update));

        return $this->db->query($sql, $binds) !== FALSE;
    }

    /**
     * Remove os domínios do servidor que não vieram na listagem.
     *
     * @param  int      $idServer
     * @param  string[] $presentes
     * @return int quantidade removida
     */
    private function pruneDomains($idServer, $presentes)
    {
        $presentes = array_values(array_unique($presentes));
        if (empty($presentes)) return 0;

        $placeholders = implode(', ', array_fill(0, count($presentes), '?'));
        $sql = 'DELETE FROM `crm_servers_domains` WHERE `id_server` = ? AND `domain` NOT IN (' . $placeholders . ')';

        $this->db->query($sql, array_merge([(int) $idServer], $presentes));
        return (int) $this->db->affected_rows();
    }

    /**
     * Grava o resultado da sincronização no cadastro do servidor.
     *
     * @param int    $idServer
     * @param int    $idUser
     * @param string $status
     * @param string $mensagem
     * @param string $agora
     */
    private function registrarSync($idServer, $idUser, $status, $mensagem, $agora)
    {
        $this->global_model->edit('crm_servers', [
            'last_sync' => $agora,
            'last_sync_status' => $status,
            'last_sync_message' => mb_substr((string) $mensagem, 0, 500),
            'modified' => $agora,
            'modified_by' => (int) $idUser,
        ], 'id', (int) $idServer);
    }

    // ------------------------------------------------------------------
    // Suspensão das contas de um contrato
    // ------------------------------------------------------------------

    /**
     * Suspende (ou reativa) nos painéis as contas dos domínios de um contrato.
     *
     * Chamado quando o contrato é suspenso, reativado ou encerrado — a parada
     * do serviço acompanha a parada do contrato, em vez de depender de alguém
     * lembrar de abrir o WHM depois.
     *
     * O que é uma "unidade" muda por painel, e isso não é detalhe: WHM e
     * DirectAdmin suspendem a CONTA inteira (todos os domínios daquele usuário
     * caem juntos), então os domínios de um mesmo owner viram UMA chamada; o
     * CloudPanel age por vhost, então cada domínio é uma unidade própria.
     *
     * Nunca lança exceção e nunca decide o destino do contrato: devolve o que
     * conseguiu e o que faltou, e quem chama resolve o que fazer com isso.
     *
     * @param  int         $idContract
     * @param  int         $idCompany  escopo do tenant
     * @param  string      $acao       'suspender' | 'reativar'
     * @param  int         $idUser
     * @param  string|null $motivo     texto gravado no painel (só na suspensão)
     * @return array
     */
    public function suspendContractAccounts($idContract, $idCompany, $acao, $idUser, $motivo = NULL)
    {
        $suspender = ($acao === 'suspender');
        $resultado = [
            'acao' => $suspender ? 'suspender' : 'reativar',
            'contas_ok' => 0,
            'dominios_ok' => 0,
            'ids_contract_domains' => [],
            'falhas' => [],
            'bloqueados' => [],
            'sem_vinculo' => [],
            'nao_processadas' => 0,
        ];

        $linhas = $this->db->query(
            'SELECT cd.id, cd.domain, cd.id_server_domain,
                    sd.domain AS server_domain, sd.owner_username, sd.id_server,
                    s.name AS server_name, s.type AS server_type
               FROM crm_contracts_domains cd
          LEFT JOIN crm_servers_domains sd ON sd.id = cd.id_server_domain
          LEFT JOIN crm_servers s ON s.id = sd.id_server
              WHERE cd.id_contract = ? AND cd.id_company = ?
           ORDER BY cd.domain',
            [(int) $idContract, (int) $idCompany]
        )->result();

        if (empty($linhas)) {
            return $resultado;
        }

        // Agrupamento em unidades de suspensão. Domínio sem vínculo não tem
        // conta para suspender — é informação para a tela, não erro: cadastrar
        // domínio sem correspondência no servidor é estado normal aqui.
        $unidades = [];
        foreach ($linhas as $linha) {
            if (empty($linha->id_server_domain) || empty($linha->id_server)) {
                $resultado['sem_vinculo'][] = $linha->domain;
                continue;
            }

            $tipo = mb_strtolower((string) $linha->server_type);
            // CloudPanel age por vhost e o Carbonio, por domínio de e-mail
            // (zimbraDomainStatus). WHM e DirectAdmin derrubam a conta inteira.
            $porDominio = in_array($tipo, ['cloudpanel', 'carbonio'], TRUE);
            $conta = trim((string) $linha->owner_username);

            if (!in_array($tipo, self::TYPES, TRUE)) {
                $resultado['falhas'][] = [
                    'servidor' => (string) $linha->server_name,
                    'conta' => $linha->domain,
                    'erro' => 'O servidor não suporta suspensão automática (tipo ' . $tipo . ').',
                ];
                continue;
            }

            if (!$porDominio && $conta === '') {
                $resultado['falhas'][] = [
                    'servidor' => (string) $linha->server_name,
                    'conta' => $linha->domain,
                    'erro' => 'A sincronização não trouxe o usuário dono da conta — suspenda pelo painel.',
                ];
                continue;
            }

            $chave = $porDominio
                ? (int) $linha->id_server . '|d|' . mb_strtolower((string) $linha->server_domain)
                : (int) $linha->id_server . '|c|' . mb_strtolower($conta);

            if (!isset($unidades[$chave])) {
                $unidades[$chave] = [
                    'id_server' => (int) $linha->id_server,
                    'server_name' => (string) $linha->server_name,
                    'tipo' => $tipo,
                    'por_dominio' => $porDominio,
                    'alvo' => $porDominio ? (string) $linha->server_domain : $conta,
                    'ids_cd' => [],
                    'ids_sd' => [],
                ];
            }

            $unidades[$chave]['ids_cd'][] = (int) $linha->id;
            $unidades[$chave]['ids_sd'][] = (int) $linha->id_server_domain;
        }

        if (empty($unidades)) {
            return $resultado;
        }

        // Conta compartilhada com outro contrato VIGENTE não é suspensa: no
        // WHM/DirectAdmin a suspensão derruba o usuário inteiro, e o site de um
        // cliente adimplente cairia junto com o do inadimplente. Só vale para a
        // suspensão — reativar conta compartilhada não tira ninguém do ar.
        $compartilhadas = [];
        if ($suspender) {
            $outros = $this->db->query(
                "SELECT sd.id_server, LOWER(sd.owner_username) AS owner,
                        GROUP_CONCAT(DISTINCT c.id ORDER BY c.id SEPARATOR ', ') AS contratos
                   FROM crm_contracts_domains cd
                   JOIN crm_servers_domains sd ON sd.id = cd.id_server_domain
                   JOIN crm_servers s ON s.id = sd.id_server
                   JOIN crm_contracts c ON c.id = cd.id_contract
                  WHERE cd.id_company = ? AND cd.id_contract <> ? AND c.status = 'vigente'
                    AND s.type IN ('whm', 'directadmin') AND sd.owner_username <> ''
               GROUP BY sd.id_server, LOWER(sd.owner_username)",
                [(int) $idCompany, (int) $idContract]
            )->result();

            foreach ($outros as $outro) {
                $compartilhadas[(int) $outro->id_server . '|' . (string) $outro->owner] = (string) $outro->contratos;
            }
        }

        // Servidores e credenciais resolvidos ANTES do laço de rede: decifrar
        // dentro dele repetiria o trabalho por unidade (64 contas do mesmo
        // servidor, na base atual) e misturaria erro de cadastro com erro de
        // painel.
        $configs = [];
        foreach ($unidades as $chave => $unidade) {
            $idServer = $unidade['id_server'];
            if (array_key_exists($idServer, $configs)) continue;

            $servidor = $this->getServer($idServer, $idCompany);
            if ($servidor === NULL) {
                $configs[$idServer] = ['erro' => 'Servidor não encontrado neste tenant.'];
                continue;
            }

            $config = $this->getConnectionConfig($servidor);
            if ($config === FALSE) {
                $configs[$idServer] = ['erro' => 'Não foi possível decifrar a credencial do servidor.'];
                continue;
            }

            $configs[$idServer] = ['config' => $config];
        }

        $texto = ($motivo !== NULL && trim((string) $motivo) !== '')
            ? trim((string) $motivo)
            : 'Contrato #' . (int) $idContract . ' suspenso no CDW Finance';

        $aplicadas = [];
        $inicio = microtime(TRUE);

        // Uma requisição não pode ficar presa à quantidade de contas do
        // contrato: 98% deles têm até 5, mas há um com 65. Passado o orçamento,
        // o que sobrou é reportado como não processado em vez de estourar o
        // tempo do PHP no meio de uma chamada ao painel.
        $suspendeu = sessao_suspender();
        try {
            foreach ($unidades as $unidade) {
                if ((microtime(TRUE) - $inicio) > self::ORCAMENTO_SUSPENSAO_SEGUNDOS) {
                    $resultado['nao_processadas']++;
                    continue;
                }

                $rotulo = $unidade['por_dominio'] ? $unidade['alvo'] : 'conta ' . $unidade['alvo'];
                $idServer = $unidade['id_server'];

                if (isset($configs[$idServer]['erro'])) {
                    $resultado['falhas'][] = [
                        'servidor' => $unidade['server_name'],
                        'conta' => $rotulo,
                        'erro' => $configs[$idServer]['erro'],
                    ];
                    continue;
                }

                if (!$unidade['por_dominio']) {
                    $chaveConta = $idServer . '|' . mb_strtolower($unidade['alvo']);
                    if (isset($compartilhadas[$chaveConta])) {
                        $resultado['bloqueados'][] = [
                            'servidor' => $unidade['server_name'],
                            'conta' => $rotulo,
                            'motivo' => 'a mesma conta atende o(s) contrato(s) vigente(s) ' . $compartilhadas[$chaveConta]
                                . ' — suspender derrubaria os sites deles também.',
                        ];
                        continue;
                    }
                }

                $retorno = $this->aplicarSuspensao($unidade, $configs[$idServer]['config'], $suspender, $texto);

                if (empty($retorno['success'])) {
                    $resultado['falhas'][] = [
                        'servidor' => $unidade['server_name'],
                        'conta' => $rotulo,
                        'erro' => (string) $retorno['message'],
                    ];

                    log_message('error', '[SUSPENSAO] Falha ao ' . $resultado['acao'] . ' — tenant ' . (int) $idCompany
                        . ', contrato ' . (int) $idContract . ', servidor ' . $unidade['server_name']
                        . ' (' . $unidade['tipo'] . '), ' . $rotulo . ': ' . $retorno['message']);
                    continue;
                }

                $resultado['contas_ok']++;
                $aplicadas[] = $unidade;
            }
        } catch (Throwable $e) {
            log_message('error', '[SUSPENSAO] Exceção ao ' . $resultado['acao'] . ' — tenant ' . (int) $idCompany
                . ', contrato ' . (int) $idContract . ': ' . $e->getMessage());
            $resultado['falhas'][] = [
                'servidor' => '—',
                'conta' => '—',
                'erro' => 'Erro inesperado ao falar com o painel: ' . $e->getMessage(),
            ];
        }
        sessao_retomar($suspendeu);

        // O estado local só é gravado depois que o painel confirmou. As linhas
        // atualizadas são as do contrato: as outras contas do mesmo usuário
        // caíram junto, mas quem as corrige é a sincronização — inventar aqui o
        // status de domínio que este contrato não citou seria escrever sobre o
        // que não foi verificado.
        $idsSd = [];
        foreach ($aplicadas as $unidade) {
            $resultado['ids_contract_domains'] = array_merge($resultado['ids_contract_domains'], $unidade['ids_cd']);
            $idsSd = array_merge($idsSd, $unidade['ids_sd']);
        }

        $resultado['dominios_ok'] = count($resultado['ids_contract_domains']);
        $idsSd = array_values(array_unique($idsSd));

        if (!empty($idsSd)) {
            $agora = date('Y-m-d H:i:s');
            $placeholders = implode(', ', array_fill(0, count($idsSd), '?'));
            $binds = array_merge(
                [$suspender ? 'suspenso' : 'ativo', $suspender ? mb_substr($texto, 0, 500) : NULL, $agora, (int) $idUser],
                $idsSd
            );

            $this->db->query(
                'UPDATE `crm_servers_domains`
                    SET `status` = ?, `suspension_reason` = ?, `modified` = ?, `modified_by` = ?
                  WHERE `id` IN (' . $placeholders . ')',
                $binds
            );
        }

        return $resultado;
    }

    /**
     * Despacha a unidade para a library do painel.
     *
     * @param  array  $unidade
     * @param  array  $config
     * @param  bool   $suspender
     * @param  string $motivo
     * @return array  success, message
     */
    private function aplicarSuspensao($unidade, $config, $suspender, $motivo)
    {
        $cliente = $this->getClient($unidade['tipo']);
        if ($cliente === FALSE) {
            return ['success' => FALSE, 'message' => 'Tipo de servidor sem integração de suspensão.'];
        }

        if ($unidade['tipo'] === 'whm') {
            return $cliente->setSuspension($config, $unidade['alvo'], $suspender, $suspender ? $motivo : NULL);
        }

        return $cliente->setSuspension($config, $unidade['alvo'], $suspender);
    }

    /**
     * Servidores ativos elegíveis à sincronização — usado pelo cron.
     *
     * @return array
     */
    public function getSyncableServers()
    {
        $tipos = "'" . implode("','", self::TYPES) . "'";
        return $this->global_model->getWhereOrderBy_off(
            'crm_servers',
            'id_status = 1 AND type IN (' . $tipos . ')',
            'name',
            'asc',
            FALSE
        );
    }
}
