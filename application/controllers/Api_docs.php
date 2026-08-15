<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Documentação da API pública (Swagger UI sobre docs/openapi.yaml).
 *
 * Não estende MY_Controller de propósito: a página é o contrato público da API
 * e precisa abrir sem sessão, do mesmo jeito que /api/v1 responde sem cookie.
 * Estender MY_Controller carregaria a sessão e redirecionaria para o login.
 */
class Api_docs extends CI_Controller
{
  /** Caminho da spec, relativo à raiz do projeto. */
  const SPEC_PATH = 'docs/openapi.yaml';

  public function index()
  {
    $this->load->view('api/docs');
  }

  /**
   * Serve o YAML para o Swagger UI.
   *
   * O arquivo fica em docs/ na raiz e o .htaccess deixaria passar direto, mas
   * servir por aqui mantém a URL sob /api/docs e não depende do layout de
   * pastas do servidor.
   */
  public function spec()
  {
    $arquivo = FCPATH . self::SPEC_PATH;

    if (!is_file($arquivo) || !is_readable($arquivo)) {
      $this->output->set_status_header(404);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'success' => FALSE,
        'message' => 'Especificação da API não encontrada.',
        'data' => NULL,
        'errors' => ['spec' => 'O arquivo ' . self::SPEC_PATH . ' não está disponível no servidor.'],
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }

    header('Content-Type: application/yaml; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile($arquivo);
    exit;
  }
}
