<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Caminho não reconhecido sob /api/v1.
 *
 * Sem isto, um endpoint inexistente cai no `404_override` do painel
 * (painel/erro404), que estende MY_Controller e responde com um redirect 307
 * para a tela de login — HTML, e enganoso para quem está consumindo a API.
 *
 * Não estende Api_Controller de propósito: exigir a chave aqui devolveria 401
 * em vez de 404 e obrigaria o cliente a distinguir "chave errada" de "URL
 * errada".
 *
 * Hoje a API v1 não expõe nenhuma operação — os endpoints de conteúdo do CMS
 * foram removidos. A infraestrutura de autenticação continua de pé
 * (Api_Controller, crm_api_keys, rate limiter), então basta criar o controller
 * do recurso e registrar a rota ACIMA do catch-all em config/routes.php.
 */
class Api_404 extends CI_Controller
{
  public function index()
  {
    $this->output->set_status_header(404);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
      'success' => FALSE,
      'message' => 'Endpoint não encontrado.',
      'data' => NULL,
      'errors' => ['path' => 'Nenhum endpoint da API v1 corresponde a este caminho.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}
