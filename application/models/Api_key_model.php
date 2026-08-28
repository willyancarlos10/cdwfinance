<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Api_key_model extends CI_Model
{
  /**
   * Prefixo do token. Mudou de `gcms_live_` (herança do GestorCMS) para
   * `cdwf_live_` quando a API do financeiro foi publicada — não havia nenhuma
   * chave emitida, então a troca não quebrou integração alguma.
   *
   * Precisa casar com o regex de Api_Controller::authenticateApiKey() e com o
   * `Mcp::authenticate()`: os três descrevem o mesmo formato de token.
   */
  const PREFIX = 'cdwf_live_';

  public function getByCompany($idCompany)
  {
    return $this->global_model->getWhereOrderBy_off(
      'crm_api_keys',
      ['id_company' => (int) $idCompany],
      'id',
      'desc',
      FALSE
    );
  }

  public function create($idCompany, $name, array $scopes, $createdBy)
  {
    $tokenParts = $this->generateTokenParts();
    if (empty($tokenParts)) {
      return ['success' => FALSE, 'message' => 'Não foi possível gerar uma chave segura.'];
    }

    $now = date('Y-m-d H:i:s');
    $saved = $this->global_model->add('crm_api_keys', [
      'id_company' => (int) $idCompany,
      'name' => trim($name),
      'key_prefix' => $tokenParts['prefix'],
      'secret_hash' => password_hash($tokenParts['token'], PASSWORD_DEFAULT),
      'scopes' => json_encode(array_values($scopes)),
      'created' => $now,
      'created_by' => (int) $createdBy,
    ]);

    if (!$saved) {
      return ['success' => FALSE, 'message' => 'Não foi possível salvar a chave de API.'];
    }

    return [
      'success' => TRUE,
      'id' => (int) $this->db->insert_id(),
      'prefix' => $tokenParts['prefix'],
      'token' => $tokenParts['token'],
    ];
  }

  public function findActiveByPrefix($prefix)
  {
    return $this->global_model->getWhere_off('crm_api_keys', ['key_prefix' => $prefix, 'revoked_at' => NULL], TRUE);
  }

  public function touchLastUsed($idKey)
  {
    return $this->global_model->edit('crm_api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id', (int) $idKey);
  }

  public function revoke($idKey, $idCompany, $revokedBy)
  {
    $apiKey = $this->global_model->getWhere_off('crm_api_keys', [
      'id' => (int) $idKey,
      'id_company' => (int) $idCompany,
    ], TRUE);

    if (empty($apiKey)) {
      return ['success' => FALSE, 'message' => 'Chave de API não encontrada.'];
    }

    if (!empty($apiKey->revoked_at)) {
      return ['success' => FALSE, 'message' => 'Esta chave já foi revogada.'];
    }

    $saved = $this->global_model->edit('crm_api_keys', [
      'revoked_at' => date('Y-m-d H:i:s'),
      'revoked_by' => (int) $revokedBy,
    ], 'id', (int) $apiKey->id);

    if (!$saved) {
      return ['success' => FALSE, 'message' => 'Não foi possível revogar a chave de API.'];
    }

    return ['success' => TRUE, 'api_key' => $apiKey];
  }

  private function generateTokenParts()
  {
    try {
      for ($attempt = 0; $attempt < 5; $attempt++) {
        $prefix = self::PREFIX . bin2hex(random_bytes(6));
        $existing = $this->global_model->getWhere_off('crm_api_keys', ['key_prefix' => $prefix], TRUE);

        if (empty($existing)) {
          $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
          return [
            'prefix' => $prefix,
            'token' => $prefix . '_' . $secret,
          ];
        }
      }
    } catch (Exception $exception) {
      log_message('error', 'Falha ao gerar chave de API: ' . $exception->getMessage());
    }

    return NULL;
  }
}
