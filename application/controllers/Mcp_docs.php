<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Instruções de conexão ao servidor MCP.
 *
 * Não estende MY_Controller pelo mesmo motivo do Api_docs: é o contrato
 * público da integração e precisa abrir sem sessão. O caminho /mcp/docs já
 * tem RewriteRule própria no .htaccess da raiz.
 */
class Mcp_docs extends CI_Controller
{
  public function index()
  {
    $this->load->view('mcp/docs');
  }
}
