<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Servidores e o INVENTÁRIO de contas de hospedagem na API pública.
 *
 * `crm_servers_domains` é uma linha por CONTA de hospedagem — com disco,
 * plano, IP e o retrato de WHOIS. Não confundir com `crm_contracts_domains`,
 * que é o cadastro comercial (vencimento, registrador) e vive em
 * Api_contract_model. O mesmo nome de domínio pode aparecer nas duas, e em
 * mais de uma linha aqui: site num painel e e-mail em outro são duas contas,
 * com dois discos.
 *
 * O que NÃO sai, e por quê:
 *  - `host`, `port`, `username`, `auth_type`: endereço e usuário do painel
 *    administrativo. Um agente de gestão não precisa disso para responder
 *    nada, e publicá-lo é superfície de ataque.
 *  - `last_test_message` e `last_sync_message`: parecem inócuas, mas
 *    Server_whm::mensagemErroCurl() cai num `default` que concatena
 *    curl_error(), e essa string traz o host ("Failed to connect to <host>
 *    port ..."). O CloudPanel monta erro de SSH do mesmo jeito. Omitir só as
 *    colunas de conexão cumpriria a letra da regra e não a intenção — o
 *    STATUS ("conectado"/"falha") responde a pergunta útil sem o endereço.
 *  - `server_host` na conta de hospedagem: mesma razão.
 *  - `secret` já não existe na view — a credencial nunca chega nem à tela.
 */
class Api_server_model extends CI_Model
{
  const CAMPOS_SERVIDOR = 'id, id_status, name, type, last_test, last_test_status, last_test_ms,
    last_sync, last_sync_status, domains_count, created, modified';

  const CAMPOS_CONTA = 'id, id_server, domain, owner_username, plan,
    disk_used_mb, disk_limit_mb, ip, status, source, contact_email, suspension_reason,
    last_sync, sync_status,
    whois_expiration_date, whois_nameservers, whois_registrar, whois_last_check,
    whois_status, whois_message, whois_bucket, whois_ns_changed,
    server_name, server_type, created, modified';

  /** Faixas derivadas de whois_bucket na view (migration 027). */
  const FAIXAS_WHOIS = ['pendente', 'livre', 'erro', 'sem_dados', 'sem_vencimento', 'vencido', 'vence_30', 'ok'];

  // -------------------------------------------------------------- SERVIDORES

  public function countServers($idCompany, array $filters = [])
  {
    $this->applyServerFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getServers($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS_SERVIDOR);
    $this->applyServerFilters($idCompany, $filters);

    return $this->db->order_by('name', 'asc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function getServer($idCompany, $idServer)
  {
    $rows = $this->getServers($idCompany, 1, 0, ['id' => (int) $idServer]);
    return !empty($rows) ? $rows[0] : NULL;
  }

  public function formatServer($server)
  {
    return [
      'id' => (int) $server->id,
      'name' => $server->name,
      'type' => $server->type,
      'active' => (int) $server->id_status === 1,
      'domains_count' => (int) $server->domains_count,
      // Só o status e o tempo — a mensagem fica de fora (ver docblock).
      'last_test' => [
        'at' => $server->last_test,
        'status' => $server->last_test_status,
        'ms' => $server->last_test_ms !== NULL ? (int) $server->last_test_ms : NULL,
      ],
      'last_sync' => [
        'at' => $server->last_sync,
        'status' => $server->last_sync_status,
      ],
      'created_at' => $server->created,
      'updated_at' => $server->modified,
    ];
  }

  // ------------------------------------------------ CONTAS DE HOSPEDAGEM

  public function countServerDomains($idCompany, array $filters = [])
  {
    $this->applyDomainFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getServerDomains($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS_CONTA);
    $this->applyDomainFilters($idCompany, $filters);

    return $this->db->order_by('domain', 'asc')
      ->order_by('id', 'asc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function formatServerDomain($conta)
  {
    $usado = $conta->disk_used_mb !== NULL ? (float) $conta->disk_used_mb : NULL;
    $limite = $conta->disk_limit_mb !== NULL ? (float) $conta->disk_limit_mb : NULL;

    return [
      'id' => (int) $conta->id,
      'domain' => $conta->domain,
      'server' => [
        'id' => (int) $conta->id_server,
        'name' => $conta->server_name,
        'type' => $conta->server_type,
      ],
      // Conta do CLIENTE no painel (ex.: usuário cPanel), não a credencial
      // administrativa — essa nunca sai do `secret`, que não está na view.
      'owner_username' => $conta->owner_username,
      'plan' => $conta->plan,
      'ip' => $conta->ip,
      'disk' => [
        'used_mb' => $usado,
        'limit_mb' => $limite,
        // Nulo quando não há limite (0 = ilimitado nos painéis): dividir por
        // zero devolveria infinito, e 0% seria mentira.
        'usage_percent' => ($usado !== NULL && $limite) ? round(($usado / $limite) * 100, 1) : NULL,
      ],
      'status' => $conta->status,
      'suspension_reason' => $conta->suspension_reason,
      'source' => $conta->source,
      'contact_email' => $conta->contact_email,
      'whois' => [
        // Vencimento OBSERVADO no registro. O cadastrado no contrato fica em
        // /contract-domains, no campo `due_date`.
        'expiration_date' => $conta->whois_expiration_date,
        'registrar' => $conta->whois_registrar,
        'nameservers' => $this->listaDeNs($conta->whois_nameservers),
        'status' => $conta->whois_status,
        'message' => $conta->whois_message,
        'last_check_at' => $conta->whois_last_check,
        // Faixa derivada na view: responde "vence em 30 dias" sem o
        // consumidor fazer aritmética de data.
        'bucket' => $conta->whois_bucket,
        'ns_changed_at' => $conta->whois_ns_changed,
      ],
      'last_sync' => [
        'at' => $conta->last_sync,
        'status' => $conta->sync_status,
      ],
      'created_at' => $conta->created,
      'updated_at' => $conta->modified,
    ];
  }

  // ------------------------------------------------------------- FILTROS

  private function applyServerFilters($idCompany, array $filters)
  {
    $this->db->from('crm_servers_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['type'])) {
      $this->db->where('type', (string) $filters['type']);
    }
    if (array_key_exists('active', $filters) && $filters['active'] !== NULL) {
      $this->db->where('id_status ' . ($filters['active'] ? '=' : '!='), 1);
    }
  }

  private function applyDomainFilters($idCompany, array $filters)
  {
    $this->db->from('crm_servers_domains_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['server_id'])) {
      $this->db->where('id_server', (int) $filters['server_id']);
    }
    if (!empty($filters['domain'])) {
      $this->db->where('domain', (string) $filters['domain']);
    }
    if (!empty($filters['status'])) {
      $this->db->where('status', (string) $filters['status']);
    }
    if (!empty($filters['source'])) {
      $this->db->where('source', (string) $filters['source']);
    }
    if (!empty($filters['whois_bucket'])) {
      $this->db->where('whois_bucket', (string) $filters['whois_bucket']);
    }
    if (!empty($filters['search'])) {
      // Um campo só, mas o grupo fica: se um segundo campo entrar na busca,
      // o OR já nasce isolado do WHERE do tenant. Ver Api_customer_model.
      $this->db->group_start();
      $this->global_model->likeInsensitive('domain', (string) $filters['search']);
      $this->db->group_end();
    }
  }

  /** A coluna guarda os NS separados por vírgula. */
  private function listaDeNs($csv)
  {
    $csv = trim((string) $csv);
    if ($csv === '') {
      return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $csv)), 'strlen'));
  }
}
