<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Receitaws_model extends MY_Model
{
  public function __construct()
  {
    parent::__construct();
  }

  public function getSefaz($cnpj, $ociosidade = 180)
  {
    $cnpj = sonumero($cnpj);
    if (strlen($cnpj) !== 14) {
      return ['return' => FALSE, 'message' => 'CNPJ inválido.'];
    }
    if (!valida_cnpj($cnpj)) {
      return ['return' => FALSE, 'message' => 'CNPJ inválido.'];
    }

    $cached = $this->getCachedSefaz($cnpj);
    $shouldConsult = empty($cached) || (int) $ociosidade < 180;

    if ($shouldConsult) {
      $fetched = $this->fetchCnpjData($cnpj, (int) $ociosidade);
      if (empty($fetched['success'])) {
        return ['return' => FALSE, 'message' => $fetched['message']];
      }

      $saved = $this->persistSefazQuery($fetched['data']);
      if (!$saved) {
        return ['return' => FALSE, 'message' => 'Não foi possível salvar os dados consultados do CNPJ.'];
      }
    }

    $sefaz = $this->getCachedSefaz($cnpj);
    if (empty($sefaz)) {
      return ['return' => FALSE, 'message' => 'Não foi possível obter os dados do CNPJ informado.'];
    }

    return ['return' => TRUE, 'object' => $sefaz];
  }

  private function getCachedSefaz($cnpj)
  {
    $cnpjEsc = $this->db->escape($cnpj);

    return $this->global_model->getWhere_off(
      'crm_sefaz_queries',
      'cnpj = ' . $cnpjEsc . ' and created BETWEEN now() - INTERVAL 30 DAY AND now()',
      TRUE
    );
  }

  private function fetchCnpjData($cnpj, $ociosidade)
  {
    $receitaws = ['success' => FALSE, 'message' => ''];
    $token = trim((string) $this->config->item('receitaws_token'));
    if ($token !== '') {
      $receitaws = $this->fetchReceitaws($cnpj, $ociosidade, $token);
      if (!empty($receitaws['success'])) {
        return $receitaws;
      }
    }

    $brasilApi = $this->fetchBrasilApi($cnpj);
    if (!empty($brasilApi['success'])) {
      return $brasilApi;
    }

    $message = !empty($receitaws['message'])
      ? $receitaws['message']
      : (!empty($brasilApi['message']) ? $brasilApi['message'] : 'Não foi possível consultar o CNPJ informado.');

    return ['success' => FALSE, 'message' => $message];
  }

  private function fetchReceitaws($cnpj, $ociosidade, $token)
  {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => 'https://www.receitaws.com.br/v1/cnpj/' . $cnpj . '/days/' . max(1, (int) $ociosidade) . '/',
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
      ],
    ]);

    $responseRaw = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
      return ['success' => FALSE, 'message' => 'Não foi possível buscar informações do CNPJ informado: ' . $err];
    }

    $response = json_decode($responseRaw);
    if (empty($response) || empty($response->status)) {
      return ['success' => FALSE, 'message' => 'Não foi possível obter os dados do CNPJ informado.'];
    }
    if ($response->status !== 'OK') {
      return ['success' => FALSE, 'message' => !empty($response->message) ? $response->message : 'Consulta de CNPJ indisponível.'];
    }
    if (!empty($response->situacao) && $response->situacao !== 'ATIVA') {
      return ['success' => FALSE, 'message' => 'CNPJ inativo na Receita Federal.'];
    }

    return [
      'success' => TRUE,
      'data' => [
        'cnpj' => $cnpj,
        'name' => (string) ($response->nome ?? ''),
        'byname' => (string) ($response->fantasia ?? ''),
        'founded' => $this->normalizeDate($response->abertura ?? NULL),
        'capital' => isset($response->capital_social) ? (float) $response->capital_social : 0,
        'email' => (string) ($response->email ?? ''),
        'phone' => $this->formatPhone($response->telefone ?? ''),
        'status' => (string) ($response->situacao ?? ''),
        'status_date' => !empty($response->data_situacao) ? $this->normalizeDate($response->data_situacao) : NULL,
        'status_reason' => (string) ($response->motivo_situacao ?? ''),
        'address' => (string) ($response->logradouro ?? ''),
        'address_number' => (string) ($response->numero ?? ''),
        'address_complement' => (string) ($response->complemento ?? ''),
        'address_zip' => $this->formatCep($response->cep ?? ''),
        'address_district' => (string) ($response->bairro ?? ''),
        'address_city' => (string) ($response->municipio ?? ''),
        'address_uf' => strtoupper((string) ($response->uf ?? '')),
        'primary_activity' => json_encode($response->atividade_principal ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'secondary_activity' => json_encode($response->atividades_secundarias ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'membership' => json_encode($this->normalizeMembership($response->qsa ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'situation' => !empty($response->data_situacao) ? $this->normalizeDate($response->data_situacao) : NULL,
      ],
    ];
  }

  private function fetchBrasilApi($cnpj)
  {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $responseRaw = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
      return ['success' => FALSE, 'message' => 'Não foi possível consultar o CNPJ: ' . $err];
    }

    $response = json_decode($responseRaw);
    if ($httpCode === 404) {
      return ['success' => FALSE, 'message' => 'CNPJ não encontrado na Receita Federal.'];
    }
    if ($httpCode !== 200 || empty($response)) {
      $message = !empty($response->message) ? $response->message : 'Serviço de consulta de CNPJ indisponível no momento.';
      return ['success' => FALSE, 'message' => $message];
    }

    $status = strtoupper((string) ($response->descricao_situacao_cadastral ?? ''));
    if ($status !== '' && $status !== 'ATIVA') {
      return ['success' => FALSE, 'message' => 'CNPJ inativo na Receita Federal.'];
    }

    $phone = $this->formatPhone($response->ddd_telefone_1 ?? '');
    if ($phone === '') {
      $phone = $this->formatPhone($response->ddd_telefone_2 ?? '');
    }

    $primaryActivity = [];
    if (!empty($response->cnae_fiscal)) {
      $primaryActivity[] = [
        'code' => (string) $response->cnae_fiscal,
        'text' => (string) ($response->cnae_fiscal_descricao ?? ''),
      ];
    }

    $secondaryActivity = [];
    if (!empty($response->cnaes_secundarios) && is_array($response->cnaes_secundarios)) {
      foreach ($response->cnaes_secundarios as $cnae) {
        $secondaryActivity[] = [
          'code' => (string) ($cnae->codigo ?? ''),
          'text' => (string) ($cnae->descricao ?? ''),
        ];
      }
    }

    return [
      'success' => TRUE,
      'data' => [
        'cnpj' => $cnpj,
        'name' => (string) ($response->razao_social ?? ''),
        'byname' => (string) ($response->nome_fantasia ?? ''),
        'founded' => $this->normalizeDate($response->data_inicio_atividade ?? NULL),
        'capital' => isset($response->capital_social) ? (float) $response->capital_social : 0,
        'email' => (string) ($response->email ?? ''),
        'phone' => $phone,
        'status' => $status !== '' ? $status : 'ATIVA',
        'status_date' => $this->normalizeDate($response->data_situacao_cadastral ?? NULL),
        'status_reason' => (string) ($response->descricao_motivo_situacao_cadastral ?? ''),
        'address' => (string) ($response->logradouro ?? ''),
        'address_number' => (string) ($response->numero ?? ''),
        'address_complement' => (string) ($response->complemento ?? ''),
        'address_zip' => $this->formatCep($response->cep ?? ''),
        'address_district' => (string) ($response->bairro ?? ''),
        'address_city' => (string) ($response->municipio ?? ''),
        'address_uf' => strtoupper((string) ($response->uf ?? '')),
        'primary_activity' => json_encode($primaryActivity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'secondary_activity' => json_encode($secondaryActivity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'membership' => json_encode($this->normalizeMembership($response->qsa ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'situation' => $this->normalizeDate($response->data_situacao_cadastral ?? NULL),
      ],
    ];
  }

  private function persistSefazQuery(array $data)
  {
    $state = $this->global_model->getWhere_off('crm_country_states', ['uf' => $data['address_uf']], TRUE);
    if (empty($state)) {
      return FALSE;
    }

    $cityName = mb_strtoupper(remover_acentos($data['address_city']));
    $city = $this->global_model->getWhere_off('crm_country_cities_v', ['UF' => $data['address_uf'], 'name' => $cityName], TRUE);
    if (empty($city)) {
      $this->global_model->add('crm_country_cities', [
        'id_state' => (int) $state->id,
        'name' => $cityName,
        'ddd' => 0,
      ]);
      $city = $this->global_model->getWhere_off('crm_country_cities_v', ['UF' => $data['address_uf'], 'name' => $cityName], TRUE);
    }

    if (empty($city)) {
      return FALSE;
    }

    $data['id_city'] = (int) $city->id;
    $data['id_state'] = (int) $city->id_state;

    return (bool) $this->global_model->add('crm_sefaz_queries', $data);
  }

  private function normalizeMembership($members)
  {
    $members = is_array($members) ? $members : [];
    $normalized = [];

    foreach ($members as $member) {
      if (is_object($member)) {
        $member = (array) $member;
      }
      if (!is_array($member)) {
        continue;
      }

      $nome = trim((string) ($member['nome'] ?? $member['nome_socio'] ?? ''));
      if ($nome === '') {
        continue;
      }

      $normalized[] = [
        'nome' => $nome,
        'qual' => (string) ($member['qual'] ?? $member['qualificacao_socio'] ?? ''),
      ];
    }

    return $normalized;
  }

  private function normalizeDate($date)
  {
    if (empty($date)) {
      return NULL;
    }

    $date = trim((string) $date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
      return substr($date, 0, 10);
    }

    $parsed = databanco($date);
    return !empty($parsed) ? substr($parsed, 0, 10) : NULL;
  }

  private function formatCep($cep)
  {
    $cep = sonumero($cep);
    if (strlen($cep) !== 8) {
      return (string) $cep;
    }

    return substr($cep, 0, 2) . '.' . substr($cep, 2, 3) . '-' . substr($cep, 5, 3);
  }

  private function formatPhone($phone)
  {
    $phone = sonumero($phone);
    if ($phone === '') {
      return '';
    }

    if (strlen($phone) === 10) {
      return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 4) . '-' . substr($phone, 6, 4);
    }

    if (strlen($phone) === 11) {
      return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 5) . '-' . substr($phone, 7, 4);
    }

    return (string) $phone;
  }
}
