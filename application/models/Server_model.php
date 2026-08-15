<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Regras dos servidores de hospedagem: teste de conexão e sincronização dos
 * domínios. O controller e o cron só chamam este model.
 *
 * As três integrações (Server_whm, Server_directadmin, Server_cloudpanel) têm a
 * mesma interface — test($config) e listDomains($config) — e são escolhidas por
 * `type`. Toda credencial trafega cifrada no banco e só é decifrada aqui, na
 * hora de montar a conexão.
 */
class Server_model extends CI_Model
{
    /** Tipos aceitos no cadastro e na sincronização. */
    const TYPES = ['whm', 'directadmin', 'cloudpanel'];

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
        // Sempre presentes na coleta dos três painéis.
        $colunas = [
            'owner_username' => isset($item['owner_username']) ? $item['owner_username'] : NULL,
            'disk_used_mb' => isset($item['disk_used_mb']) ? $item['disk_used_mb'] : NULL,
            'status' => isset($item['status']) ? $item['status'] : 'ativo',
            'source' => isset($item['source']) ? $item['source'] : 'manual',
        ];

        // Opcionais: só entram quando a origem realmente informou.
        foreach (['plan', 'disk_limit_mb', 'ip', 'contact_email', 'suspension_reason'] as $opcional) {
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
