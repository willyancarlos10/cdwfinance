<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Api_Controller extends CI_Controller
{
  protected $api_key;
  protected $api_company_id;

  public function __construct()
  {
    parent::__construct();
    $this->output->set_content_type('application/json', 'utf-8');
    $this->load->model('api_key_model');
    $this->load->library('api_rate_limiter');
    $this->authenticateApiKey();
  }

  protected function authenticateApiKey()
  {
    $authorization = $this->getAuthorizationHeader();
    if (!preg_match('/^Bearer\\s+(.+)$/i', $authorization, $matches)) {
      $this->respond(401, FALSE, 'Informe uma chave Bearer válida.', NULL, ['authorization' => 'Cabeçalho Authorization ausente ou inválido.']);
    }

    $token = trim($matches[1]);
    // O prefixo tem que casar com Api_key_model::PREFIX. O regex roda ANTES de
    // tocar o banco: descarta lixo sem pagar uma consulta por requisição.
    if (!preg_match('/^(cdwf_live_[a-f0-9]{12})_[A-Za-z0-9_-]{43}$/', $token, $parts)) {
      $this->respond(401, FALSE, 'Chave de API inválida.', NULL, ['authorization' => 'Chave de API inválida.']);
    }

    $apiKey = $this->api_key_model->findActiveByPrefix($parts[1]);
    if (empty($apiKey) || !password_verify($token, $apiKey->secret_hash)) {
      $this->respond(401, FALSE, 'Chave de API inválida.', NULL, ['authorization' => 'Chave de API inválida.']);
    }

    if (!$this->api_rate_limiter->consume($apiKey->id)) {
      $retryAfter = $this->api_rate_limiter->retryAfter();
      $this->output->set_header('Retry-After: ' . $retryAfter);
      $this->respond(429, FALSE, 'Limite de requisições excedido.', NULL, ['rate_limit' => 'Tente novamente em ' . $retryAfter . ' segundos.']);
    }

    $this->api_key = $apiKey;
    $this->api_company_id = (int) $apiKey->id_company;
    $this->api_key_model->touchLastUsed($apiKey->id);
  }

  protected function requireScope($scope)
  {
    if (!$this->hasScope($scope)) {
      $this->respond(403, FALSE, 'Esta chave não possui a permissão necessária.', NULL, ['scope' => 'Escopo obrigatório: ' . $scope]);
    }
  }

  /**
   * A chave tem o escopo? Só consulta — não interrompe a requisição.
   *
   * Existe para o caso em que o escopo decide a PRESENÇA de um bloco da
   * resposta, e não o direito de fazer a chamada: o detalhe do contrato
   * inclui os títulos quando a chave tem `invoices:read` e os omite quando
   * não tem, em vez de recusar a requisição inteira e negar junto os dados
   * do contrato, que ela pode ver.
   */
  protected function hasScope($scope)
  {
    $scopes = json_decode($this->api_key->scopes, TRUE);
    return is_array($scopes) && in_array($scope, $scopes, TRUE);
  }

  protected function requireGet()
  {
    if ($this->input->method(TRUE) !== 'GET') {
      $this->output->set_header('Allow: GET');
      $this->respond(405, FALSE, 'Método não permitido.', NULL, ['method' => 'Utilize GET.']);
    }
  }

  protected function requireMethod(array $methods)
  {
    $method = $this->input->method(TRUE);
    if (!in_array($method, $methods, TRUE)) {
      $this->output->set_header('Allow: ' . implode(', ', $methods));
      $this->respond(405, FALSE, 'Método não permitido.', NULL, ['method' => 'Métodos permitidos: ' . implode(', ', $methods) . '.']);
    }
  }

  protected function jsonBody()
  {
    $body = trim((string) $this->input->raw_input_stream);
    $decoded = json_decode($body, TRUE);
    if ($body === '' || json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
      $this->respond(400, FALSE, 'JSON inválido.', NULL, ['body' => 'Envie um objeto JSON válido.']);
    }
    return $decoded;
  }

  protected function clientIp()
  {
    return $this->input->ip_address();
  }

  protected function pagination()
  {
    $page = filter_var($this->input->get('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $limit = filter_var($this->input->get('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);

    if ($this->input->get('page') !== NULL && $page === FALSE) {
      $this->respond(422, FALSE, 'Parâmetro page inválido.', NULL, ['page' => 'Informe um inteiro maior ou igual a 1.']);
    }
    if ($this->input->get('limit') !== NULL && $limit === FALSE) {
      $this->respond(422, FALSE, 'Parâmetro limit inválido.', NULL, ['limit' => 'Informe um inteiro entre 1 e 100.']);
    }

    return ['page' => $page ?: 1, 'limit' => $limit ?: 20];
  }

  protected function respond($status, $success, $message, $data = NULL, array $errors = [], array $meta = [])
  {
    $payload = ['success' => (bool) $success, 'message' => $message, 'data' => $data, 'errors' => $errors];
    if (!empty($meta)) {
      $payload['meta'] = $meta;
    }

    $this->output->set_status_header((int) $status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  private function getAuthorizationHeader()
  {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
      return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
      return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      return $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    return '';
  }
}
